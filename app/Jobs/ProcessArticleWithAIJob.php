<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\User;
use App\Models\RawArticle;
use App\Services\AI\OpenRouterService;
use App\Services\AI\ModelRouterService;
use App\Exceptions\OpenRouterAuthenticationException;
use App\Services\AI\OpenRouterCircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessArticleWithAIJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // 15 minutes — classification (60s) + redaction (300s) + 5 images (240s) + validation (30s) + buffer
    public $tries = 2;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->rawArticle->id;
    }

    public function backoff(): array
    {
        return [60, 180]; // 1min, 3min between retries
    }

    public function __construct(
        protected RawArticle $rawArticle,
        public bool $forceReprocess = false
    ) {}

    public function handle(ModelRouterService $ai, \App\Services\AI\SiliconFlowImageService $imageService, \App\Services\AI\TagGeneratorService $tagService, \App\Services\AI\DuplicateCheckerService $duplicateChecker, \App\Services\SEO\EntityAutoLinkerService $autoLinker): void
    {
        // Guard: require API key before processing
        if (empty(config('openrouter.api_key'))) {
            Log::error("ProcessArticleWithAIJob: OPENROUTER_API_KEY is not set. Releasing job for 5 minutes.");
            $this->release(300); // retry in 5 minutes
            return;
        }

        // Circuit Breaker: if API key is invalid, release all jobs with long delay
        if (OpenRouterCircuitBreaker::isOpen()) {
            $ttl = OpenRouterCircuitBreaker::remainingTtl();
            Log::warning("OpenRouter Circuit Breaker is OPEN. Releasing RawArticle {$this->rawArticle->id} for {$ttl}s. Update OPENROUTER_API_KEY in .env!");
            $this->release(max(60, $ttl));
            return;
        }

        // Fail-Fast Guard: Refresh from DB to verify if user changed status to 'ignored' or cancelled
        $this->rawArticle->refresh();
        if (!$this->rawArticle->exists || ($this->rawArticle->status !== 'pending' && !$this->forceReprocess)) {
            Log::info("🛑 ProcessArticleWithAIJob: Aborting execution for RawArticle #{$this->rawArticle->id} because current DB status is '{$this->rawArticle->status}'.");
            return;
        }

        $today = now()->format('l, F j, Y');
        Log::info("Processing RawArticle: {$this->rawArticle->id} (Bilingual EN/ES) at {$today}.");

        // --- Guard against incomplete/failed previous attempts ---
        if (!$this->forceReprocess) {
            $existing = Article::where('raw_article_id', $this->rawArticle->id)->first();
            if ($existing) {
                $contentEn = $existing->getTranslation('content', 'en');
                $contentEs = $existing->getTranslation('content', 'es');
                
                if ($existing->status === 'published' && !empty($contentEn) && !empty($contentEs)) {
                    Log::warning("RawArticle {$this->rawArticle->id} has already been fully processed and published (Article {$existing->id}). Skipping.");
                    $this->rawArticle->update(['status' => 'processed']);
                    return;
                }
                
                // If it exists but is incomplete (e.g. empty content or still draft), delete it so we can start fresh
                Log::info("Found incomplete/failed Article {$existing->id} for RawArticle {$this->rawArticle->id}. Deleting to retry fresh.");
                $existing->delete();
            }
        } else {
            Log::info("🔄 Force Reprocess active for RawArticle #{$this->rawArticle->id}: bypassing existing published check.");
        }

        try {
            $classification = $this->classifyAndExtract($ai);
        } catch (OpenRouterAuthenticationException $e) {
            // 401 = permanent auth failure — don't retry, mark as failed immediately
            Log::error("RawArticle {$this->rawArticle->id}: OpenRouter auth failed (401). Marking as failed — check API key.", [
                'response' => $e->getResponseBody(),
            ]);
            OpenRouterCircuitBreaker::recordFailure();
            $meta = $this->rawArticle->metadata ?? [];
            $meta['last_error'] = [
                'message'   => 'Error 401 en OpenRouter: clave de API inválida o expirada.',
                'exception' => 'OpenRouterAuthenticationException',
                'failed_at' => now()->toIso8601String(),
            ];
            $this->rawArticle->update(['status' => 'failed', 'metadata' => $meta]);
            return; // Don't throw — prevents Laravel from retrying a permanent error
        }

        if ($classification === null) {
            throw new \Exception("AI classification failed (empty response). Retrying...");
        }

        if (!$this->forceReprocess) {
            if (!($classification['is_relevant'] ?? false) && empty($classification['is_seed'])) {
                $this->rawArticle->update(['status' => 'ignored']);
                Log::info("RawArticle {$this->rawArticle->id} ignored by AI (not relevant).");
                return;
            }

            // --- FILTER: Sensitive / Harmful Content ---
            if (!empty($classification['is_sensitive']) && $classification['is_sensitive'] === true) {
                $this->rawArticle->update(['status' => 'ignored']);
                Log::warning("RawArticle {$this->rawArticle->id} flagged as sensitive content. Blocked.");
                return;
            }

            // --- FILTER: Potentially False / Misinformation ---
            if (!empty($classification['is_potentially_false']) && $classification['is_potentially_false'] === true) {
                $this->rawArticle->update(['status' => 'ignored']);
                Log::warning("RawArticle {$this->rawArticle->id} flagged as potentially false/misinformation. Blocked.");
                return;
            }

            // --- FILTER: Article Age (null-safe for articles without source) ---
            $source = $this->rawArticle->source;
            $maxAgeDays = $source?->max_age_days ?? 7;
            if ($this->rawArticle->published_at && $this->rawArticle->published_at->lt(now()->subDays($maxAgeDays))) {
                $this->rawArticle->update(['status' => 'ignored']);
                Log::info("RawArticle {$this->rawArticle->id} rejected: article is {$this->rawArticle->published_at->diffForHumans()}, max age is {$maxAgeDays} days.");
                return;
            }

            // --- FILTER: Source Trust (null-safe — no source = trusted by default) ---
            if ($source && !$source->trusted && $source->score < 50) {
                $this->rawArticle->update(['status' => 'ignored']);
                Log::warning("RawArticle {$this->rawArticle->id} rejected: untrusted source with low score ({$source->score}).");
                return;
            }
        }

        // Extract category for partitioned semantic check (Optimized JSONB search)
        $categoryName = trim($classification['category_name'] ?? '');
        $slugCat = \Illuminate\Support\Str::slug($categoryName);
        $matchedCategory = null;
        if ($categoryName) {
            $matchedCategory = \App\Models\Category::active()
                ->where(function ($q) use ($categoryName, $slugCat) {
                    $q->whereRaw("name->>'es' ILIKE ?", [$categoryName])
                      ->orWhereRaw("name->>'en' ILIKE ?", [$categoryName])
                      ->orWhere('slug_en', 'ILIKE', $slugCat)
                      ->orWhere('slug_es', 'ILIKE', $slugCat);
                })
                ->first();

            if (!$matchedCategory) {
                $matchedCategory = \App\Models\Category::active()
                    ->where(function ($q) use ($categoryName) {
                        $q->whereRaw("name->>'es' ILIKE ?", ["%{$categoryName}%"])
                          ->orWhereRaw("name->>'en' ILIKE ?", ["%{$categoryName}%"]);
                    })
                    ->first();
            }
        }
        $categoryId = $matchedCategory?->id ?? $source?->category_id ?? 1;
        $canonicalEventSlug = $classification['event_slug_canonical'] ?? null;
        $summaryText = !empty($classification['facts']) ? implode('. ', $classification['facts']) : ($this->rawArticle->summary ?? null);

        // Priority Bypass for Breaking News (Importance >= 9)
        if (($classification['importance'] ?? 5) >= 9) {
            Log::info("🚀 BREAKING NEWS BYPASS: RawArticle #{$this->rawArticle->id} has maximum importance ({$classification['importance']}/10). Expediting.");
        }

        // --- DUPLICATE CHECK LEVEL 1, 2, 2.5 & 3 WITH CATEGORY PARTITION & LLM JUDGE ---
        if (!$this->forceReprocess) {
            $isDuplicate = $duplicateChecker->checkAndHandleDuplicate(
                 $this->rawArticle->title ?? '',
                 $this->rawArticle->content ?? '',
                 $this->rawArticle->url ?? '',
                 $this->rawArticle->id,
                 $categoryId,
                 $canonicalEventSlug,
                 $summaryText
            );

            if ($isDuplicate) {
                 $this->rawArticle->update(['status' => 'processed']);
                 return;
            }
        } else {
            Log::info("🔄 Force Reprocess active for RawArticle #{$this->rawArticle->id}: bypassing duplicate checker.");
        }

        $author = User::role(['redactor', 'admin', 'super_admin'])->where('is_active', true)->inRandomOrder()->first();

        if (!$author) {
            $defaultAuthor = config('global.editorial.default_author');
            $author = User::firstOrCreate(
                ['email' => $defaultAuthor['email'] ?? 'luis@glodaxia.com'],
                [
                    'name'      => $defaultAuthor['name'] ?? ['en' => 'Luis Figuera', 'es' => 'Luis Figuera'],
                    'password'  => bcrypt(Str::random(16)),
                    'slug'      => $defaultAuthor['slug'] ?? 'luis-figuera',
                    'is_active' => true,
                    'bio'       => $defaultAuthor['bio'] ?? [
                        'es' => 'Especialista en redacción y análisis tecnológico en Glodaxia.',
                        'en' => 'Technology analysis and digital journalism specialist at Glodaxia.',
                    ],
                ]
            );
        }

        // --- REDACTION: Call AI to produce bilingual content ---
        try {
            $redacted = $this->redactBilingual($ai, $classification, $author);
        } catch (OpenRouterAuthenticationException $e) {
            Log::error("RawArticle {$this->rawArticle->id}: OpenRouter auth failed (401) during redaction. Marking as failed.", [
                'response' => $e->getResponseBody(),
            ]);
            OpenRouterCircuitBreaker::recordFailure();
            $meta = $this->rawArticle->metadata ?? [];
            $meta['last_error'] = [
                'message'   => 'Error 401 en OpenRouter: clave de API inválida durante la redacción.',
                'exception' => 'OpenRouterAuthenticationException',
                'failed_at' => now()->toIso8601String(),
            ];
            $this->rawArticle->update(['status' => 'failed', 'metadata' => $meta]);
            return;
        }

        if (!$redacted) {
            throw new \RuntimeException("AI could not draft bilingual content (attempt {$this->attempts()}).");
        }

        // --- AUTO-FIX: Truncate fields that exceed limits before validation ---
        $redacted = $this->autoFixRedactedOutput($redacted);

        // --- VALIDATE: Programmatic checks on AI output before creating Article ---
        $validationErrors = $this->validateRedactedOutput($redacted);
        if (!empty($validationErrors)) {
            Log::warning("redactBilingual validation failed for RawArticle {$this->rawArticle->id}", $validationErrors);
            throw new \RuntimeException(
                "AI output failed validation: " . implode('; ', $validationErrors) . " (attempt {$this->attempts()})"
            );
        }

        // --- CLEANUP: Remove AI hallucinated image attributes + inline URLs ---
        $contentEn = $this->cleanHallucinatedAttributes($redacted['content_en'] ?? '');
        $contentEs = $this->cleanHallucinatedAttributes($redacted['content_es'] ?? '');
        $contentEn = $this->cleanInlineUrls($contentEn);
        $contentEs = $this->cleanInlineUrls($contentEs);
        $contentEn = $this->ensureHtmlParagraphs($contentEn);
        $contentEs = $this->ensureHtmlParagraphs($contentEs);

        // --- CREATE ARTICLE (Bilingual) ---
        $slugEn = $redacted['slug_en'] ?? Str::slug($redacted['title_en'] ?? $this->rawArticle->title);
        $slugEs = $redacted['slug_es'] ?? Str::slug($redacted['title_es'] ?? $this->rawArticle->title);

        // Ensure unique slugs
        $slugEn = $this->ensureUniqueSlug($slugEn, 'slug_en');
        $slugEs = $this->ensureUniqueSlug($slugEs, 'slug_es');

        // Reuse existing Article if reprocessing, or create a new one
        $isReprocessing = $this->forceReprocess || ($this->rawArticle->article && $this->rawArticle->article->exists);
        $article = $this->rawArticle->article ?? new Article();
        $originalPublishedAt = $article->exists ? $article->published_at : null;
        $originalStatus = $article->exists ? $article->status : 'published';

        $article->raw_article_id = $this->rawArticle->id;
        $article->slug_en        = $slugEn;
        $article->slug_es        = $slugEs;
        $article->user_id        = $article->exists ? ($article->user_id ?? $author->id) : $author->id;
        $article->category_id    = $categoryId;
        $article->status         = $article->exists ? $article->status : 'draft';
        $article->published_at   = $originalPublishedAt ?: now();
        $article->seo_score      = 85; // Static default — self-reported AI scores are unreliable, use Filament for manual override
        $article->meta_keywords  = $redacted['keywords'] ?? [];
        $article->reading_time   = $this->calculateReadingTime($contentEn, $contentEs);
        $article->ai_metadata    = [
            'origin_url'  => $this->rawArticle->url,
            'today_date'  => $today,
            'json_ld'     => $redacted['json_ld'] ?? null,
            'style_dna'   => $redacted['__style_dna'] ?? null,
            'model_used'  => $redacted['__model_used'] ?? config('ai_models.default'),
            'temperature' => $redacted['__temperature'] ?? null,
        ];

        // Set all translatable fields via setTranslation (Spatie-aware)
        $article->setTranslation('title',            'en', $redacted['title_en']  ?? $this->rawArticle->title);
        $article->setTranslation('title',            'es', $redacted['title_es']  ?? $this->rawArticle->title);
        $article->setTranslation('excerpt',          'en', $redacted['excerpt_en'] ?? '');
        $article->setTranslation('excerpt',          'es', $redacted['excerpt_es'] ?? '');
        $metaTitleMax = (int) config('global.editorial.limits.meta_title.max', 80);
        $metaDescMax  = (int) config('global.editorial.limits.meta_description.max', 160);
        $article->setTranslation('meta_title',       'en', Str::limit($redacted['meta_title_en'] ?? $redacted['title_en'] ?? '', $metaTitleMax));
        $article->setTranslation('meta_title',       'es', Str::limit($redacted['meta_title_es'] ?? $redacted['title_es'] ?? '', $metaTitleMax));
        $article->setTranslation('meta_description', 'en', Str::limit($redacted['excerpt_en'] ?? '', $metaDescMax));
        $article->setTranslation('meta_description', 'es', Str::limit($redacted['excerpt_es'] ?? '', $metaDescMax));
        // Set full content immediately (never set to empty string)
        $article->setTranslation('content', 'en', $contentEn);
        $article->setTranslation('content', 'es', $contentEs);
        $article->save();

        // --- IMAGE GENERATION (shared across languages) ---
        $imageCount = 0;
        $imageObjectsJsonLd = [];

        if (!empty($redacted['image_prompts']) && is_array($redacted['image_prompts'])) {
            foreach ($redacted['image_prompts'] as $index => $imgData) {
                if ($index >= 3) break;

                $placeholder = $imgData['id'] ?? '';
                $promptEn    = $imgData['prompt_en'] ?? '';
                $altEn       = trim($imgData['alt_en'] ?? '');
                $altEs       = trim($imgData['alt_es'] ?? $altEn);
                $captionEn   = trim($imgData['caption_en'] ?? $altEn);
                $captionEs   = trim($imgData['caption_es'] ?? $altEs);
                $imgTitleMax = (int) config('global.editorial.limits.image_title.max', 70);
                $titleEn     = Str::limit(trim($imgData['title_en'] ?? $altEn), $imgTitleMax);
                $titleEs     = Str::limit(trim($imgData['title_es'] ?? $altEs), $imgTitleMax);

                if (empty($placeholder) || empty($promptEn)) continue;

                // ── 1 API call to SiliconFlow ─────────────────────────────
                $path = $imageService->generateAndSave($promptEn, $slugEn, $index + 1);

                if ($path && file_exists($path)) {

                    $imgNum  = $index + 1;
                    $imgId   = "img-{$imgNum}-" . Str::random(5);
                    $sizes   = "(max-width: 600px) 100vw, (max-width: 1200px) 800px, 1200px";

                    // ── Save EN copy (preservingOriginal keeps the file for ES) ──
                    $fileNameEn = "{$slugEn}-{$imgNum}.webp";
                    $mediaEn = $article->addMedia($path)
                        ->usingFileName($fileNameEn)
                        ->usingName(Str::limit($titleEn, 70))
                        ->withCustomProperties([
                            'lang'    => 'en',
                            'alt'     => $altEn,
                            'title'   => $titleEn,
                            'caption' => $captionEn,
                        ])
                        ->preservingOriginal()                  // keeps the source file for ES
                        ->toMediaCollection('images_en');

                    // ── Save ES copy (moves the file, nothing left on disk) ──
                    $fileNameEs = "{$slugEs}-{$imgNum}.webp";
                    $mediaEs = $article->addMedia($path)
                        ->usingFileName($fileNameEs)
                        ->usingName(Str::limit($titleEs, 70))
                        ->withCustomProperties([
                            'lang'    => 'es',
                            'alt'     => $altEs,
                            'title'   => $titleEs,
                            'caption' => $captionEs,
                        ])
                        ->toMediaCollection('images_es');

                    // ── Build srcset for EACH language from its own collection ──
                    $srcsetEn = $mediaEn->getUrl('thumb') . " 480w, "
                              . $mediaEn->getUrl('medium') . " 800w, "
                              . $mediaEn->getUrl('large')  . " 1200w";

                    $srcsetEs = $mediaEs->getUrl('thumb') . " 480w, "
                              . $mediaEs->getUrl('medium') . " 800w, "
                              . $mediaEs->getUrl('large')  . " 1200w";

                    // ── Build semantic, WCAG-compliant <figure> for each language ──
                    $imgTagEn = $this->buildImageTag(
                        $mediaEn->getUrl(), $srcsetEn, $sizes, $altEn, $titleEn, $captionEn, $imgId
                    );
                    $imgTagEs = $this->buildImageTag(
                        $mediaEs->getUrl(), $srcsetEs, $sizes, $altEs, $titleEs, $captionEs, $imgId
                    );

                    // --- Ensure each image token is replaced ONCE (never duplicated) ---
                    if ($placeholder !== '[IMAGE_1]') {
                        // Replace only the FIRST occurrence with the image figure
                        $posEn = strpos($contentEn, $placeholder);
                        if ($posEn !== false) {
                            $contentEn = substr_replace($contentEn, $imgTagEn, $posEn, strlen($placeholder));
                        }
                        $contentEn = str_replace($placeholder, '', $contentEn); // strip any extra duplicates

                        $posEs = strpos($contentEs, $placeholder);
                        if ($posEs !== false) {
                            $contentEs = substr_replace($contentEs, $imgTagEs, $posEs, strlen($placeholder));
                        }
                        $contentEs = str_replace($placeholder, '', $contentEs); // strip any extra duplicates
                    } else {
                        // Safety: remove [IMAGE_1] if AI placed it in content anyway
                        $contentEn = str_replace($placeholder, '', $contentEn);
                        $contentEs = str_replace($placeholder, '', $contentEs);
                    }

                    // ── JSON-LD Schema.org ImageObject (Google SEO) ──
                    $imageObjectsJsonLd[] = [
                        "@type"       => "ImageObject",
                        "url"         => $mediaEn->getUrl('large'),
                        "thumbnail"   => $mediaEn->getUrl('thumb'),
                        "caption"     => $captionEn,
                        "description" => $altEn,
                        "name"        => $titleEn,
                        "width"       => 1200,
                        "height"      => 675,
                        "encodingFormat" => "image/webp",
                        "inLanguage"  => "en",
                    ];

                    // ── Set featured image on first image only ──
                    if ($imageCount === 0) {
                        $article->image_url = $article->getBestImageUrl('images_en', 'large');
                        $article->save();
                        $article->setTranslation('image_alt', 'en', $altEn);
                        $article->setTranslation('image_alt', 'es', $altEs);
                        $article->save();
                    }

                    Log::info("Image {$imgNum} saved: EN={$fileNameEn}, ES={$fileNameEs}");
                    $imageCount++;

                } else {
                    // Image generation failed — remove placeholder from both languages
                    $contentEn = str_replace($placeholder, '', $contentEn);
                    $contentEs = str_replace($placeholder, '', $contentEs);
                    Log::warning("Image generation failed for placeholder {$placeholder}.");

                    // --- FEATURED IMAGE [IMAGE_1] FAILURE: Use placeholder instead of rollback ---
                    if ($placeholder === '[IMAGE_1]') {
                        $placeholderPath = $this->generatePlaceholderHero(
                            $redacted['title_en'] ?? $this->rawArticle->title ?? 'Article',
                            $slugEn
                        );
                        if ($placeholderPath && file_exists($placeholderPath)) {
                            $heroImgId = "img-hero-placeholder-" . Str::random(5);
                            $heroSizes = "(max-width: 600px) 100vw, (max-width: 1200px) 800px, 1200px";

                            $fileNameEn = "{$slugEn}-hero-placeholder.webp";
                            $mediaEn = $article->addMedia($placeholderPath)
                                ->usingFileName($fileNameEn)
                                ->usingName(Str::limit($redacted['title_en'] ?? 'Hero', 70))
                                ->withCustomProperties(['lang' => 'en', 'alt' => $altEn, 'title' => Str::limit($altEn, 70), 'caption' => $captionEn])
                                ->preservingOriginal()
                                ->toMediaCollection('images_en');

                            $fileNameEs = "{$slugEs}-hero-placeholder.webp";
                            $mediaEs = $article->addMedia($placeholderPath)
                                ->usingFileName($fileNameEs)
                                ->usingName(Str::limit($redacted['title_es'] ?? 'Hero', 70))
                                ->withCustomProperties(['lang' => 'es', 'alt' => $altEs, 'title' => Str::limit($altEs, 70), 'caption' => $captionEs])
                                ->toMediaCollection('images_es');

                            $article->image_url = $mediaEn->getUrl();
                            $article->save();
                            $article->setTranslation('image_alt', 'en', $altEn);
                            $article->setTranslation('image_alt', 'es', $altEs);
                            $article->save();

                            $imageCount++;
                            Log::info("Placeholder hero image generated for Article. Original SiliconFlow call failed.");
                        }
                    }
                }
            }
        }

        // --- SAFETY NET: No images at all → save as draft for admin review ---
        if ($imageCount === 0) {
            $article->status = 'draft';
            $meta = $article->ai_metadata;
            $meta['needs_images'] = true;
            $meta['image_failure_reason'] = 'All image generations failed';
            $article->ai_metadata = $meta;
            $article->save();
            Log::warning("Article {$article->id} saved as draft — no images generated. Admin review needed.");
            // Do NOT throw — article content is preserved for manual image addition
        }

        // --- CLEANUP: Remove temp files from images-tmp/ ---
        // SiliconFlowImageService saves to local temp, Spatie copies to MEDIA_DISK (R2 or local).
        // We must clean up the temp copies to avoid disk accumulation.
        $tempPath = storage_path('app/images-tmp');
        if (is_dir($tempPath)) {
            $tempFiles = glob($tempPath . '/' . $slugEn . '-*.webp');
            foreach ($tempFiles as $tempFile) {
                if (is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }
            // Clean up placeholder too
            $placeholderFile = $tempPath . '/placeholder-' . $slugEn . '.webp';
            if (is_file($placeholderFile)) {
                @unlink($placeholderFile);
            }
        }


        // --- Verified Entity Auto-Linking (Zero-Hallucination Safe Outbound Links) ---
        $contentEn = $autoLinker->autoLink($contentEn);
        $contentEs = $autoLinker->autoLink($contentEs);

        // --- Canonical Source Reference Card ---
        if (!empty($this->rawArticle->url)) {
            $sourceName = $this->rawArticle->source ? $this->rawArticle->source->name : 'Primary Source';
            $sourceUrl = htmlspecialchars($this->rawArticle->url, ENT_QUOTES, 'UTF-8');
            $sourceNameClean = htmlspecialchars($sourceName, ENT_QUOTES, 'UTF-8');

            $contentEn .= "\n<p class=\"source-reference-card mt-8 pt-4 border-t border-slate-800 text-xs text-slate-400\"><em>Originally reported and referenced from <a href=\"{$sourceUrl}\" target=\"_blank\" rel=\"noopener noreferrer nofollow\" class=\"text-brand-teal hover:underline font-medium\">{$sourceNameClean}</a>.</em></p>";
            $contentEs .= "\n<p class=\"source-reference-card mt-8 pt-4 border-t border-slate-800 text-xs text-slate-400\"><em>Referencia y fuente original: <a href=\"{$sourceUrl}\" target=\"_blank\" rel=\"noopener noreferrer nofollow\" class=\"text-brand-teal hover:underline font-medium\">{$sourceNameClean}</a>.</em></p>";
        }

        // --- HTML SANITIZATION (Defense-in-Depth via ultra-fast native C engine) ---
        $allowedHtml = '<p><h2><h3><strong><em><blockquote><ul><ol><li><figure><figcaption><img><a><span><div>';
        $contentEn = strip_tags($contentEn, $allowedHtml);
        $contentEs = strip_tags($contentEs, $allowedHtml);

        // Update JSON-LD
        if (!empty($imageObjectsJsonLd)) {
            $meta = $article->ai_metadata;
            $meta['json_ld']['image'] = $imageObjectsJsonLd;
            $article->ai_metadata = $meta;
        }

        $modelUsed = $redacted['__model_used'] ?? $redacted['_model_used'] ?? config('ai_models.default');

        // --- ATOMIC DB TRANSACTION: Guarantees full consistency between Article and RawArticle ---
        \Illuminate\Support\Facades\DB::transaction(function () use ($article, $contentEn, $contentEs, $imageCount, $modelUsed, $isReprocessing, $originalPublishedAt, $originalStatus) {
            $article->setTranslation('content', 'en', $contentEn);
            $article->setTranslation('content', 'es', $contentEs);

            // Dynamic Publishing / Reprocessing Logic
            if ($imageCount > 0) {
                if ($isReprocessing && $originalPublishedAt) {
                    // Reprocessing existing article: preserve original date and status (does not jump to position #1)
                    $article->status = $originalStatus;
                    $article->published_at = $originalPublishedAt;
                    Log::info("Article {$article->id} reprocessed. Preserving original published_at: {$originalPublishedAt->toDateTimeString()} (Status: {$originalStatus}).");
                } else {
                    // New article: apply staggered or immediate publishing
                    $isStaggered = (bool) \App\Models\Setting::get('publishing.staggered_enabled', false);
                    $minDelay = max(0, (int) \App\Models\Setting::get('publishing.delay_min_minutes', 1));
                    $maxDelay = max($minDelay, (int) \App\Models\Setting::get('publishing.delay_max_minutes', 60));

                    if ($isStaggered && $maxDelay > 0) {
                        $latestArticleDate = Article::whereIn('status', ['published', 'scheduled'])
                            ->whereNotNull('published_at')
                            ->max('published_at');

                        $baseTime = ($latestArticleDate && \Carbon\Carbon::parse($latestArticleDate)->isFuture())
                            ? \Carbon\Carbon::parse($latestArticleDate)
                            : now();

                        $delayMinutes = random_int($minDelay, $maxDelay);
                        $publishAt = $baseTime->copy()->addMinutes($delayMinutes);

                        $article->status = 'scheduled';
                        $article->published_at = $publishAt;
                        Log::info("New Article {$article->id} ('{$article->slug_es}') scheduled for publication at {$publishAt->toDateTimeString()} (+{$delayMinutes}m delay, range: {$minDelay}-{$maxDelay}m).");
                    } else {
                        $article->status = 'published';
                        $article->published_at = now();
                        Log::info("New Article {$article->id} published immediately with current timestamp.");
                    }
                }
            } else {
                $article->status = 'draft';
            }
            $article->save();

            $this->rawArticle->update([
                'status'   => 'processed',
                'ai_model' => $modelUsed,
            ]);
        });

        // --- Generate Embedding ---
        $duplicateChecker->generateAndStoreEmbedding($article, $contentEn);

        // --- SEO & IndexNow Real-Time Instant Notification ---
        if ($article->status === 'published') {
            \App\Http\Controllers\SitemapController::flushCache();
            if ($article->slug_en) {
                \App\Http\Controllers\IndexNowController::ping(url('/' . $article->slug_en));
            }
            if ($article->slug_es) {
                \App\Http\Controllers\IndexNowController::ping(url('/es/' . $article->slug_es));
            }
        }

        // --- Generate and sync Tags ---
        $extractedTags = $tagService->generateTags($contentEn);
        if (!empty($extractedTags)) {
            $tagService->syncTagsToArticle($article, $extractedTags);
            Log::info("Tags generated for Article {$article->id}: " . implode(', ', $extractedTags));
        }
        
        Log::info("Bilingual article created: {$article->id} with {$imageCount} images using model {$modelUsed}.");
        
        if (!empty($article->id)) {
            \Illuminate\Support\Facades\Cache::forget("article_full_reprocessing_{$article->id}");
        }
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function cleanHallucinatedAttributes(string $content): string
    {
        $content = preg_replace('/\s*\"\s*alt=\"\[IMAGE_\d+_ALT\]\"\s*title=\"\[IMAGE_\d+_TITLE\]\">\s*/i', '', $content);
        $content = preg_replace('/\[IMAGE_\d+_(ALT|TITLE|CAPTION|PROMPT)\]/i', '', $content);
        $content = preg_replace('/\s*\"\s*alt=\"[^\"]*\"\s*title=\"[^\"]*\">\s*/i', '', $content);
        return $content;
    }

    /**
     * Strip any inline external URLs from the HTML content.
     * The AI is instructed not to place URLs, but safety net catches any
     * that slip through. References are stored in ai_metadata['origin_url'].
     */
    private function cleanInlineUrls(string $content): string
    {
        // Remove <a href="...">...</a> links — keep the visible text
        $content = preg_replace('/<a\s+[^>]*href=["\']https?:\/\/[^"\']*["\'][^>]*>(.*?)<\/a>/is', '$1', $content);
        // Remove bare https://... URLs (not inside tags)
        $content = preg_replace('/(?<!["\'=])https?:\/\/[^\s<>"\')\]]+/', 'una fuente verificada', $content);
        return $content;
    }

    private function buildImageTag(
        string $src, string $srcset, string $sizes,
        string $alt, string $title, string $caption, string $imgId
    ): string {
        return "<figure role=\"group\" aria-labelledby=\"caption-{$imgId}\" class=\"article-image my-10 overflow-hidden rounded-xl border border-gray-100 shadow-2xl transition-all duration-500 hover:shadow-cyan-500/20\">
            <img src=\"{$src}\"
                 srcset=\"{$srcset}\"
                 sizes=\"{$sizes}\"
                 alt=\"{$alt}\"
                 title=\"{$title}\"
                 loading=\"lazy\"
                 decoding=\"async\"
                 width=\"1280\"
                 height=\"720\"
                 role=\"img\"
                 class=\"w-full h-auto object-cover aspect-video\">
            <figcaption id=\"caption-{$imgId}\" class=\"text-sm text-gray-500 mt-4 text-center italic leading-relaxed px-4 bg-gray-50/50 py-3 border-t border-gray-100\">
                {$caption}
            </figcaption>
        </figure>";
    }

    private function ensureUniqueSlug(string $slug, string $column, int $attempt = 0): string
    {
        // Safety limit to prevent infinite recursion
        if ($attempt > 50) {
            Log::warning("ensureUniqueSlug: exceeded 50 attempts for '{$slug}', using random suffix");
            return "{$slug}-" . Str::random(6);
        }

        $candidate = $attempt === 0 ? $slug : "{$slug}-" . ($attempt + 1);
        
        // We must check BOTH columns to ensure a slug is truly unique across the whole site
        $exists = Article::where('slug_en', $candidate)
            ->orWhere('slug_es', $candidate)
            ->exists();

        if ($exists) {
            return $this->ensureUniqueSlug($slug, $column, $attempt + 1);
        }

        return $candidate;
    }

    protected function classifyAndExtract(ModelRouterService $ai): ?array
    {
        $content = trim(strip_tags($this->rawArticle->content ?? ''));
        $today   = now()->format('l, F j, Y');

        if (empty($content)) {
            Log::info("RawArticle {$this->rawArticle->id} has no content. Treating as a Seed Idea.");
            // Try to get category in both languages
            $categories = \App\Models\Category::active()->get()->map(function ($c) {
                $en = $c->getTranslation('name', 'en');
                $es = $c->getTranslation('name', 'es');
                return $en ?: $es;
            })->filter()->toArray();

            return [
                'is_relevant'     => true,
                'importance'      => 8,
                'is_seed'         => true,
                'source_language' => 'unknown', // will be inferred by redactBilingual from topic
                'category_name'   => $categories[0] ?? 'General',
                'facts'           => [$this->rawArticle->title],
            ];
        }

        // Get categories showing both EN and ES names for better matching
        $categories     = \App\Models\Category::active()->get()->map(function ($c) {
            $en = $c->getTranslation('name', 'en');
            $es = $c->getTranslation('name', 'es');
            return $en ? "{$en} / {$es}" : $es;
        })->filter()->toArray();
        $categoriesList = implode(', ', $categories) ?: 'General / General';

        $source = $this->rawArticle->source;
        $sourceTrusted = ($source && $source->trusted) ? 'YES' : 'NO';
        $sourceScore   = (int) ($source?->score ?? 0);
        $sourceDate    = $this->rawArticle->published_at ? $this->rawArticle->published_at->format('l, F j, Y') : $today;
        $articleAge    = $this->rawArticle->published_at ? $this->rawArticle->published_at->diffForHumans() : 'today';
        $currentYear   = now()->year;

        $prompt = <<<PROMPT
You are a senior multilingual editorial AI. Analyze the following news article and respond in STRICT JSON only.

STRICT TEMPORAL ANCHORS:
- CURRENT DATE: {$today} (Year: {$currentYear})
- SOURCE PUBLISHED: {$sourceDate} ({$articleAge})

VALID CATEGORIES: [{$categoriesList}]
ARTICLE TITLE: {$this->rawArticle->title}
ARTICLE CONTENT: {$content}
SOURCE TRUSTED: {$sourceTrusted} (score: {$sourceScore})
ARTICLE AGE: {$articleAge}

Detect the SOURCE LANGUAGE of the article automatically (it may be English, Spanish, French, Portuguese, or any other language).

Respond in STRICT JSON (no markdown):
{
    "is_relevant": true,
    "is_sensitive": false,
    "is_potentially_false": false,
    "source_language": "en",
    "category_name": "AI / IA",
    "content_type": "news",
    "importance": 8,
    "primary_entity": "Apple",
    "event_slug_canonical": "apple-m5-ultra-mac-studio-launch-2026",
    "facts": ["key fact 1", "key fact 2", "key fact 3"]
}

Rules:
- source_language: ISO 639-1 code of the article's source language (e.g., "en", "es", "pt", "fr")
- category_name: MUST exactly match one of the VALID CATEGORIES listed above. Use the English name part before the slash. If NONE of the valid categories fit, set is_relevant to false.
- content_type: one of: news, blog, guide, review, pillar
- importance: 1-10 based on editorial relevance
- primary_entity: Main company, project, technology, or person at the center of the story (e.g., "Apple", "OpenAI", "Nvidia", "Oracle", "Linux Kernel")
- event_slug_canonical: Lowercase hyphenated canonical event identifier in English, capturing entity, specific action, and year (e.g., "apple-m5-ultra-mac-studio-launch-2026", "oracle-weblogic-zero-day-cisa-deadline-2026", "nvidia-rtx-5090-pricing-announcement-2026")
- facts: 3-7 concise key facts extracted from the article IN ENGLISH (always translate facts to English)
- is_sensitive: set to TRUE if the content involves: graphic violence, hate speech, explicit sexual content, illegal activities, self-harm, terrorism, or content that could cause legal liability
- is_potentially_false: set to TRUE if the article contains obvious misinformation, fabricated statistics, conspiracy theories, unverified claims presented as fact, or reads like propaganda/sponsored content disguised as news
PROMPT;

        $resultObj = $ai->classifyWithFailover([['role' => 'user', 'content' => $prompt]]);
        $response = $resultObj['content'] ?? null;
        $result   = $this->parseJson($response);

        if ($result) {
            $importance = (int) ($result['importance'] ?? 5);
            $isTrusted = $this->rawArticle->source && $this->rawArticle->source->trusted;
            $isRelevant = (bool) ($result['is_relevant'] ?? true);
            $isSensitive = (bool) ($result['is_sensitive'] ?? false);
            $isFalse = (bool) ($result['is_potentially_false'] ?? false);

            Log::info("RawArticle {$this->rawArticle->id} classified. Importance: {$importance}, Relevant: " . ($isRelevant ? 'Y' : 'N') . ", Source language: " . ($result['source_language'] ?? 'unknown'));

            // High-Impact Editorial Gatekeeper: Drop low-importance or sensitive/false news
            if (!$isRelevant || $isSensitive || $isFalse || (!$isTrusted && $importance < 7)) {
                Log::info("Editorial Gatekeeper: RawArticle #{$this->rawArticle->id} filtered out (Importance: {$importance}/10, Trusted: " . ($isTrusted ? 'YES' : 'NO') . ")");
                $result['is_relevant'] = false;
            }
        }

        return $result;
    }

    protected function redactBilingual(ModelRouterService $ai, array $classification, User $author): ?array
    {
        $today          = now()->format('l, F j, Y');
        $currentYear    = now()->year;
        $sourceDate     = $this->rawArticle->published_at ? $this->rawArticle->published_at->format('l, F j, Y') : $today;
        $articleAge     = $this->rawArticle->published_at ? $this->rawArticle->published_at->diffForHumans() : 'today';
        $isSeed         = $classification['is_seed'] ?? false;
        $contentType    = $classification['content_type'] ?? 'blog';
        $rawFacts       = (array) ($classification['facts'] ?? [$this->rawArticle->title ?? 'Tech News']);
        $facts          = array_slice($rawFacts, 0, 10);
        $topic          = $isSeed ? ($this->rawArticle->title ?? 'Tech News') : implode('; ', $facts);
        // Pass first 2000 chars of raw content for richer source context
        $rawBody            = strip_tags($this->rawArticle->content ?? '');
        $rawSourceExcerpt   = mb_strlen($rawBody) > 60
            ? mb_substr($rawBody, 0, 2000) . (mb_strlen($rawBody) > 2000 ? '...' : '')
            : '';
        $sourceLang     = $classification['source_language'] ?? 'unknown';
        $sourceLangName = match($sourceLang) {
            'en'    => 'English',
            'es'    => 'Spanish',
            'pt'    => 'Portuguese',
            'fr'    => 'French',
            'de'    => 'German',
            'it'    => 'Italian',
            default => 'an automatically detected language',
        };

        $wordTargets = (array) config('global.editorial.word_targets', []);
        $wordTarget = $wordTargets[$contentType] ?? ($wordTargets['blog'] ?? '850-1300 words EN | 850-1300 palabras ES (Mínimo estricto: 850 palabras)');

        $authorNameEn = $author->getTranslation('name', 'en') ?: $author->getTranslation('name', 'es') ?: $author->name;
        $authorBioEn  = $author->getTranslation('bio', 'en') ?: $author->getTranslation('bio', 'es') ?: $author->bio;

        $persona = config('global.editorial.persona');
        $rules   = config('global.editorial.focus_rules');

        // Generate clean editorial style DNA
        $styleDna    = $this->generateStyleDNA();
        $temperature = $styleDna['temperature'];

        $authorBioEs = $author->getTranslation('bio', 'es') ?: $author->bio;

        $minArticleWords = (int) config('global.editorial.limits.min_words.news', 500);
        $titleMinChars    = (int) config('global.editorial.limits.title.min', 50);
        $titleMaxChars    = (int) config('global.editorial.limits.title.max', 130);
        $titleMinWords    = (int) config('global.editorial.limits.title.min_words', 7);
        $excerptMinChars  = (int) config('global.editorial.limits.excerpt.min', 160);
        $excerptMaxChars  = (int) config('global.editorial.limits.excerpt.max', 250);
        $imgAltMaxChars   = (int) config('global.editorial.limits.image_alt.max', 125);
        $imgTitleMaxChars = (int) config('global.editorial.limits.image_title.max', 70);

        $prompt = <<<PROMPT
You are a senior investigative tech columnist and essayist writing for Glodaxia (a premium digital journalism publication).
Your task is to write an ORIGINAL, RIGOROUS, HIGH-IMPACT journalism column based on the verified facts provided below.

═════════════════════════════════════════════════════════════════════
═══ 0. ABSOLUTE LANGUAGE SEPARATION RULE (ZERO TOLERANCE) ═══
═════════════════════════════════════════════════════════════════════
1. "title_en", "excerpt_en", and "content_en" MUST BE 100% WRITTEN IN THE ENGLISH LANGUAGE.
   - It is a catastrophic failure to put Spanish (or any non-English language) inside "content_en", "title_en", or "excerpt_en".
   - Even if the raw source material is in Spanish, you MUST translate and synthesize the entire narrative into native, fluent, professional English.

2. "title_es", "excerpt_es", and "content_es" MUST BE 100% WRITTEN IN THE SPANISH LANGUAGE.
   - It is a catastrophic failure to put English inside "content_es", "title_es", or "excerpt_es".
   - Even if the raw source material is in English, you MUST translate and synthesize the entire narrative into native, fluent, professional Spanish.

═════════════════════════════════════════════════════════════════════
═══ 1. CONTEXT & VERIFIED SOURCE FACTS ═══
═════════════════════════════════════════════════════════════════════
- CURRENT DATE: {$today} (Current Year: {$currentYear})
- SOURCE PUBLISHED: {$sourceDate} ({$articleAge})
- SOURCE LANGUAGE DETECTED: {$sourceLangName} (MANDATORY: Regardless of source language, you MUST write native English in 'content_en'/'title_en'/'excerpt_en', and native Spanish in 'content_es'/'title_es'/'excerpt_es')
- VERIFIED SOURCE FACTS: {$topic}
- RAW SOURCE EXCERPT (first 2000 chars of original content, for tone & context reference only — do NOT copy verbatim): {$rawSourceExcerpt}
- ARTICLE ARCHETYPE: {$styleDna['archetypeName']}
- STRUCTURAL GUIDANCE: {$styleDna['archetypeStructure']}
- NARRATIVE VOICE & PERSPECTIVE: {$styleDna['perspectiveVoice']}
- PERSPECTIVE RULE: {$styleDna['perspectiveRule']}
- OPENING HOOK STRATEGY: {$styleDna['openingHook']}
- CLOSING STYLE: {$styleDna['closingStyle']}

═════════════════════════════════════════════════════════════════════
═══ 2. AUTHOR VOICE & REALISTIC PROFESSIONAL PERSONA ═══
═════════════════════════════════════════════════════════════════════
- Assigned Author: {$authorNameEn}
- Author Background: {$authorBioEn} (ES: {$authorBioEs})

STRICT PERSONA GROUNDING:
1. Write with the natural voice, authentic perspective, and expertise of {$authorNameEn}.
2. CRITICAL ANTI-PROMPT-LEAK RULE: NEVER invent fake personal memories in unrelated industries. (For example: if the author is an educator or physician, DO NOT say 'I recall analyzing fintech platform pivots years ago...'). If you draw a real-world reflection, ground it strictly in the subject matter and the author's actual field of passion.
3. Adopt an authoritative yet natural, conversational tone. Write like a real staff writer for Wired, The Verge, MIT Technology Review, or The Atlantic.

═════════════════════════════════════════════════════════════════════
═══ 3. SEARCH INTENT & GENERATIVE ENGINE OPTIMIZATION (GEO & AI SEARCH 2026) ═══
═════════════════════════════════════════════════════════════════════
1. DIRECT ANSWER & SNIPPET OPTIMIZATION (THE FIRST 50-80 WORDS):
   - Immediately in the opening paragraph, answer the core search query: What happened, which companies/technologies are involved, and what is the primary breakthrough, risk, or strategic impact?
   - Make the first 3 sentences crystal-clear so AI search engines (SearchGPT, Google AI Overviews, Perplexity) and featured snippets can directly extract and cite Glodaxia as the authoritative primary answer.

2. SEARCH-INTENT DRIVEN HEADINGS (<h2> and <h3>):
   - Subheadings must clearly reflect how users and search algorithms query technical information (e.g., 'How the Architecture Works under the Hood', 'Security Implications & Key Vulnerabilities', 'Market Impact & What Developers Should Do Next').
   - NEVER use vague headers like 'Introduction', 'Overview', or 'The Situation'.

3. HIGH INFORMATION GAIN & ENTITY DENSITY:
   - Include specific technical details: concrete version numbers, benchmark metrics, hardware/software specs, dates, and cause-and-effect reasoning.
   - Generative AI engines rank content higher when it provides rich information gain rather than generic rewrites.

4. OPINION, CRITICAL PERSPECTIVE & E-E-A-T EVALUATION:
   - Glodaxia is a distinguished publication of opinion and analysis. Do not merely state facts: provide the author's critical viewpoint on whether this announcement solves real problems, what trade-offs exist, and how it challenges competing technologies.

5. SCANNABLE STRUCTURE (BULLETS & TAKEAWAYS):
   - Incorporate at least one structured <ul>...</ul> or <ol>...<li> list for technical specifications, key takeaways, or comparative points to facilitate instant reading and AI knowledge graph extraction.

═════════════════════════════════════════════════════════════════════
═══ 4. STRICT LENGTH & DEPTH REQUIREMENTS (MANDATORY >= {$minArticleWords} WORDS) ═══
═════════════════════════════════════════════════════════════════════
- ABSOLUTE MINIMUM: Each language version (content_en and content_es) MUST contain a STRICT MINIMUM OF {$minArticleWords} WORDS (actual narrative words, excluding HTML tags). This is a HARD LIMIT — the article will be automatically REJECTED and retried if it falls short.
- OPTIMAL RANGE: Aim for {$wordTarget}. Rich, in-depth technical journalism is celebrated. Write as much as the story demands.
- FLEXIBLE UPPER CEILING: There is NO penalty for exceeding the target. 1500-2000 word articles are preferred when the story warrants it.
- FORBIDDEN THIN CONTENT: NEVER write shallow 200-400 word summaries. Expand deeply on technical architecture, market ripple effects, historical context, benchmarks, expert opinions, and practitioner takeaways. Every section must add NEW information.
- Articles with fewer than {$minArticleWords} words will be automatically REJECTED by programmatic validation and the job will be retried.

═════════════════════════════════════════════════════════════════════
═══ 5. STRICT LEGAL, DEFAMATION PREVENTION & JOURNALISTIC ETHICS (CRITICAL) ═══
═════════════════════════════════════════════════════════════════════

1. ZERO DEFAMATION / ZERO LIBEL (STRICT LIABILITY PROTECTION):
   - STRICTLY FORBIDDEN to accuse any individual, executive, founder, public figure, or corporation of fraud, crime, corruption, malice, illegal acts, or unethical conduct without citing verified official public records or court documents.
   - NEVER make derogatory assumptions or speculate maliciously about motives, competence, or character.

2. MANDATORY JOURNALISTIC ATTRIBUTION (NEVER ASSUME FACTS):
   - For all controversies, investigations, security vulnerabilities, disputes, regulatory inquiries, or layoffs: you MUST explicitly attribute the source in the text.
   - Use verified attribution phrases:
     • EN: "According to a report by...", "As stated in official disclosures...", "Court documents allege that...", "Company representatives stated...", "As documented by security researchers..."
     • ES: "De acuerdo con un informe de...", "Según un comunicado oficial de...", "Documentos judiciales alegan que...", "Portavoces de la compañía señalaron...", "Según documentaron investigadores de seguridad..."

3. PRESUMPTION OF INNOCENCE & CONDITIONAL PHRASING:
   - In any active legal dispute, antitrust inquiry, lawsuit, or unconfirmed report: ALWAYS use objective conditional language ("alleged", "reported", "under investigation", "according to plaintiffs" / "presunto", "supuesto", "según la parte demandante"). Never state unproven claims as indisputable facts.

4. COMPLETE PROHIBITION OF DEROGATORY, OFFENSIVE, OR HUMILIATING LANGUAGE:
   - STRICTLY FORBIDDEN: Any form of mockery, insult, hostility, sarcasm, character assassination, or derogatory commentary against any person, gender, race, nationality, or brand.
   - The tone must be strictly professional, balanced, sober, rigorous, respectful, and authoritative (AP Stylebook / Reuters Trust Principles).

5. ACCURATE QUOTES & CONTEXT INTEGRITY:
   - Only use quotation marks ('...') for literal, verified statements from speakers or official documents. NEVER invent quotes or take statements out of context.

═════════════════════════════════════════════════════════════════════
═══ 6. ZERO-TOLERANCE ANTI-AI RULES (HUMAN AUTHENTICITY) ═══
═════════════════════════════════════════════════════════════════════

1. ZERO SENTENCE REPETITION OR ECHO (CRITICAL):
   - NEVER repeat the same phrase, opening hook, thesis sentence, or grammatical formula twice in the article.
   - Every single paragraph must advance the story with fresh analysis, concrete data, or distinct perspectives.

2. BAN ON FORMULAIC DEBATE CATCHPHRASES & CLICHES:
   - FORBIDDEN EN: "Look,", "Here's the thing:", "Okay, I hear the objection already", "Fair enough", "Impossible?", "metabolic enzyme", "pathogen", "plate tectonics", "the digital landscape", "beacon of hope", "double-edged sword", "tapestry", "delve", "testament to", "orchestrate", "at the end of the day", "it remains to be seen", "only time will tell", "in today's fast-paced world", "in conclusion", "a game-changer", "paradigm shift".
   - FORBIDDEN ES: "Mira,", "Aqui esta el detalle:", "Ya escucho las objeciones", "Justo.", "¿Imposible?", "enzima metabolica", "patogeno", "placas tectonicas", "el panorama digital", "faro de esperanza", "espada de doble filo", "tapiz", "adentrarse", "testimonio de", "orquestar", "al final del dia", "solo el tiempo dira", "en el mundo actual", "en conclusion", "cambio de paradigma".

3. NATURAL, SHARP VOCABULARY:
   - Use clear, rigorous, concrete journalistic vocabulary. Avoid forced pseudo-intellectual metaphors or piled-up buzzwords.

4. SEAMLESS SOURCE INTEGRATION:
   - Integrate the source attribution naturally in the prose (e.g. "According to a detailed report from...", "As documented in recent findings by...").

═════════════════════════════════════════════════════════════════════
═══ 7. DYNAMIC EDITORIAL FREEDOM & CONTENT ARCHITECTURE ═══
═════════════════════════════════════════════════════════════════════
- OPENING HOOK EXECUTION: Use the OPENING HOOK STRATEGY from Section 1 as your entry point and creative impulse for the first paragraph. It is a direction, not a rigid template — interpret it with full professional freedom. The archetype gives you structural guidance, but the hook determines your ANGLE OF ATTACK for this specific story.
- EDITORIAL FREEDOM: Do NOT follow a rigid formula. You have complete freedom to structure the narrative to best tell this specific story. The archetype is a base — use it as scaffolding, not a cage. Vary paragraph lengths, use lists when comparing features/data, or use pure flowing prose when delivering deep analytical narrative.
- ALLOWED HTML TAGS: <p>, <h2>, <h3>, <strong>, <blockquote>, <ul>, <ol>, <li>.
- NEVER use <h1>, <h4>, <div>, <span>, or raw markdown bold (**) inside HTML content.
- PARAGRAPHS: Every narrative block must be wrapped in <p>...</p>. Write with natural rhythm (some paragraphs of 2 sentences, others of 3-4 sentences).
- HEADINGS (<h2> / <h3>): Use descriptive, incisive subheadings to structure your argument. NEVER use generic headers like "The Context" or "The Impact".
- CLOSING SECTION: Conclude with an analytical final section that looks toward the future.
- CLOSING EXECUTION: Implement the CLOSING STYLE from Section 1 precisely for the final paragraph/sentence of the article. You have 5 possible closing styles: reader question, quantified projection, aphoristic close, practitioner action, or open verdict. Use the one assigned. Make it specific to THIS article's content. NEVER say 'In conclusion' or 'En conclusion'.

═════════════════════════════════════════════════════════════════════
═══ 8. IMAGE PLACEMENT RULES ═══
═════════════════════════════════════════════════════════════════════
- Total images: {$styleDna['imageCount']}
- [IMAGE_1] = Hero/featured image ONLY (do NOT insert inside content_en or content_es).
- Interior images: Insert [IMAGE_2] (and [IMAGE_3] if count=3) on its OWN STANDALONE LINE inside the HTML content between major sections.
  Example:
  <p>First section analysis...</p>

  [IMAGE_2]

  <h2>Next Analytical Section</h2>
  <p>Second section analysis...</p>

- FLUX.1 Prompts: Photorealistic, 35mm DSLR Nikon D850 style, cinematic natural lighting, 8k, hyper-realistic, no text overlay, no watermarks.

═════════════════════════════════════════════════════════════════════
═══ 9. STRICT BILINGUAL INDEPENDENCE ═══
═════════════════════════════════════════════════════════════════════
The Spanish version MUST read as if originally penned by a native Spanish tech journalist — with natural flow, rich vocabulary, and independent rhetorical strength.

CRITICAL JSON FORMATTING RULES:
1. Inside HTML content strings, use single quotes (') for speech or attribute quotes. NEVER use unescaped double quotes (") inside text values.
2. Use literal "\n" for newlines inside content strings.
3. Every interior image token must be isolated on its own line: `\n\n[IMAGE_2]\n\n`.

{
    "title_en": "Complete, highly descriptive, journalistic headline in English ({$titleMinChars}-{$titleMaxChars} chars). MUST be a full standalone headline describing who, what happened, and why it matters — NEVER truncate, NEVER leave sentences incomplete. Minimum {$titleMinWords} words.",
    "title_es": "Titular periodístico completo, altamente descriptivo e impactante en Español ({$titleMinChars}-{$titleMaxChars} caracteres). DEBE ser una oración completa con sujeto, verbo e impacto — NUNCA truncar ni dejar a medias. Mínimo {$titleMinWords} palabras.",
    "slug_en": "short-english-slug-max-6-words",
    "slug_es": "slug-espanol-corto-max-6-palabras",
    "excerpt_en": "Sharply written teaser in English ({$excerptMinChars}-{$excerptMaxChars} chars). Must summarize WHO, WHAT, WHY it matters — enough context for a reader to decide to click. NEVER write fewer than {$excerptMinChars} chars.",
    "excerpt_es": "Extracto detallado en Espanol ({$excerptMinChars}-{$excerptMaxChars} caracteres). Debe resumir QUIÉN, QUÉ, POR QUÉ importa — suficiente contexto para que el lector decida hacer clic. NUNCA escribir menos de {$excerptMinChars} caracteres.",
    "meta_title_en": "Concise SEO title in English (50-70 chars max)",
    "meta_title_es": "Título SEO conciso en Español (50-70 caracteres max)",
    "meta_description_en": "Concise SEO description in English (120-160 chars max)",
    "meta_description_es": "Descripción SEO concisa en Español (120-160 caracteres max)",
    "content_en": "Full article in English with <p>, <h2>, [IMAGE_2], etc.",
    "content_es": "Articulo completo en Espanol con <p>, <h2>, [IMAGE_2], etc.",
    "keywords": ["keyword1", "keyword2", "keyword3", "keyword4", "keyword5"],
    "image_prompts": [
        {
            "id": "[IMAGE_1]",
            "prompt_en": "Photojournalistic style, [scene description relevant to headline], 35mm lens, natural cinematic lighting, 8k, photorealistic, no text",
            "alt_en": "Alt text in English (max {$imgAltMaxChars} chars)",
            "alt_es": "Texto alternativo en Espanol (max {$imgAltMaxChars} chars)",
            "caption_en": "Contextual editorial caption",
            "caption_es": "Leyenda editorial contextual",
            "title_en": "Image title (max {$imgTitleMaxChars} chars)",
            "title_es": "Titulo imagen (max {$imgTitleMaxChars} chars)"
        },
        {
            "id": "[IMAGE_2]",
            "prompt_en": "Photojournalistic style, [second visual perspective on the topic], 35mm lens, natural lighting, 8k, no text",
            "alt_en": "Alt text in English",
            "alt_es": "Texto alternativo en Espanol",
            "caption_en": "Contextual caption",
            "caption_es": "Leyenda contextual",
            "title_en": "Image title",
            "title_es": "Titulo imagen"
        }
    ],
    "json_ld": {
        "@context": "https://schema.org",
        "@type": "NewsArticle",
        "headline": "Same as title_en",
        "datePublished": "{$today}",
        "author": {"@type": "Person", "name": "{$author->name}"},
        "description": "Same as excerpt_en"
    }
}
PROMPT;
        $resultObj = $ai->completeWithFailover([['role' => 'user', 'content' => $prompt]], ['temperature' => $temperature]);
        if (!$resultObj || empty($resultObj['content'])) {
            Log::warning("redactBilingual: AI returned null response for RawArticle {$this->rawArticle->id}");
            return null;
        }
        $response = $resultObj['content'];
        if (!$response) {
            Log::warning("redactBilingual: AI returned null response for RawArticle {$this->rawArticle->id} (likely timeout)");
            return null;
        }
        $data     = $this->parseJson($response);

        if (!$data) {
            Log::warning("redactBilingual: AI returned invalid JSON for RawArticle {$this->rawArticle->id}", [
                'response_preview' => substr($response, 0, 500),
            ]);
            return null;
        }

        if (isset($data['keywords']) && is_string($data['keywords'])) {
            $data['keywords'] = array_map('trim', explode(',', $data['keywords']));
        }

        // Attach style DNA metadata for downstream logging
        // Prefix with double-underscore to avoid collision with any AI-returned keys
        $data['__style_dna'] = $styleDna;
        $data['__temperature'] = $temperature;
        $data['__model_used'] = $resultObj['model_used'] ?? 'auto';

        return $data;
    }

    protected function parseJson(?string $json): ?array
    {
        if (!$json) return null;

        // Remove <think> blocks (reasoning models like DeepSeek R1, Qwen3)
        $clean = preg_replace('~<think>.*?</think>~s', '', $json);

        // Remove markdown code fences (multiline-safe)
        $clean = preg_replace('~^```json\s*|\s*```$~m', '', $clean);
        $clean = preg_replace('~^```\s*|\s*```$~m', '', $clean);

        // Extract only the JSON portion between first { and last }
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start === false || $end === false || $end <= $start) {
            Log::warning("parseJson: no JSON object found in response", ['raw' => substr($json, 0, 300)]);
            return null;
        }
        $clean = substr($clean, $start, $end - $start + 1);

        $result = json_decode(trim($clean), true);

        // If still failing, attempt repair of common AI JSON mistakes
        if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("parseJson: initial decode failed, attempting repair", [
                'error' => json_last_error_msg(),
                'preview' => substr($clean, 0, 500),
            ]);

            $repaired = $clean;

            // 1. Escape unescaped control characters (newlines, tabs) inside string literals
            $repaired = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/s', function ($matches) {
                $str = $matches[0];
                $inner = substr($str, 1, -1);
                // Replace actual raw carriage returns and newlines with literal "\n"
                $inner = str_replace(["\r\n", "\r", "\n"], '\n', $inner);
                // Replace raw tabs with literal "\t"
                $inner = str_replace("\t", '\t', $inner);
                return '"' . $inner . '"';
            }, $repaired);

            // 2. Fix trailing commas before closing braces/brackets
            $repaired = preg_replace('/,\s*}/', '}', $repaired);
            $repaired = preg_replace('/,\s*]/', ']', $repaired);

            $result = json_decode(trim($repaired), true);

            if ($result) {
                Log::info("parseJson: repair succeeded");
            } else {
                Log::warning("parseJson: JSON decode failed after repair", [
                    'error' => json_last_error_msg(),
                    'raw' => substr($repaired, 0, 500),
                ]);
            }
        }

        return $result ?: null;
    }

    /**
     * Programmatic validation of AI-generated content.
     * Returns empty array if valid, or array of error messages.
     */
    protected function validateRedactedOutput(array $data): array
    {        $errors = [];

        // 0. STRICT BILINGUAL LANGUAGE INTEGRITY CHECK (ZERO TOLERANCE)
        $langErrors = $this->validateLanguageIntegrity($data);
        if (!empty($langErrors)) {
            return $langErrors; // Hard fail immediately so job retries with next model
        }

        $contentEn = $data['content_en'] ?? '';
        $contentEs = $data['content_es'] ?? '';

        // 1. [IMAGE_1] must NOT appear inside content
        if (str_contains($contentEn, '[IMAGE_1]')) {
            $errors[] = '[IMAGE_1] found inside content_en (should only be in image_prompts)';
        }
        if (str_contains($contentEs, '[IMAGE_1]')) {
            $errors[] = '[IMAGE_1] found inside content_es (should only be in image_prompts)';
        }

        // 2. Every [IMAGE_N] in content must have a matching image_prompts entry
        $promptIds = collect($data['image_prompts'] ?? [])->pluck('id')->toArray();
        preg_match_all('/\[IMAGE_(\d+)\]/', $contentEn, $matchesEn);
        foreach ($matchesEn[0] ?? [] as $token) {
            if (!in_array($token, $promptIds)) {
                $errors[] = "Token {$token} in content_en has no matching image_prompts entry";
            }
        }

        // 3. Image tokens in EN must also exist in ES (synchronized placement)
        foreach ($matchesEn[0] ?? [] as $token) {
            if (!str_contains($contentEs, $token)) {
                $errors[] = "Token {$token} exists in content_en but missing from content_es";
            }
        }

        // 4. Must have image_prompts with at least [IMAGE_1]
        if (!in_array('[IMAGE_1]', $promptIds)) {
            $errors[] = 'Missing [IMAGE_1] in image_prompts array (hero image required)';
        }

        // 5. Title length and depth validation (Strict limits from config/global.php)
        $titleMinChars = (int) config('global.editorial.limits.title.min', 60);
        $titleMinWords = (int) config('global.editorial.limits.title.min_words', 8);
        foreach (['title_en' => 'English', 'title_es' => 'Español'] as $field => $langLabel) {
            $titleStr = trim($data[$field] ?? '');
            $titleLen = mb_strlen($titleStr);
            $wordCount = count(preg_split('/\s+/u', $titleStr, -1, PREG_SPLIT_NO_EMPTY));
            if ($titleLen < $titleMinChars || $wordCount < $titleMinWords) {
                $errors[] = "{$field} is too short ({$titleLen} chars, {$wordCount} words). A full journalistic headline with subject, action and context is required (minimum {$titleMinChars} chars, minimum {$titleMinWords} words).";
            }
        }

        // 6. Word Count Validation (Unicode-aware word count, minimum 500 words)
        $minArticleWords = (int) config('global.editorial.limits.min_words.news', 500);
        $wordsEn = count(preg_split('/\s+/u', trim(strip_tags($contentEn)), -1, PREG_SPLIT_NO_EMPTY));
        $wordsEs = count(preg_split('/\s+/u', trim(strip_tags($contentEs)), -1, PREG_SPLIT_NO_EMPTY));

        if ($wordsEn < $minArticleWords) {
            $errors[] = "content_en has only {$wordsEn} words (minimum is {$minArticleWords} words). The article is too thin — expand depth, analysis, and context.";
        }
        if ($wordsEs < $minArticleWords) {
            $errors[] = "content_es has only {$wordsEs} words (minimum is {$minArticleWords} words). El artículo es demasiado corto — expandir análisis, contexto y profundidad.";
        }

        // 7. Check for blocked AI-fingerprint phrases (WARNING ONLY — auto-fixed in autoFixRedactedOutput)
        // Hard-failing on blocked phrases wastes API credits on retries. The auto-fix
        // silently strips them in PHP, and we log warnings here for monitoring.
        $blockedPhrases = config('global.editorial.blocked_phrases.en', []);
        $contentEnLower = strtolower($contentEn);
        foreach ($blockedPhrases as $phrase) {
            if (str_contains($contentEnLower, $phrase)) {
                Log::warning("AI-fingerprint phrase found in content_en (auto-fixed): '{$phrase}' — RawArticle {$this->rawArticle->id}");
            }
        }

        // 8. Check for blocked AI-fingerprint phrases in SPANISH (WARNING ONLY)
        $blockedPhrasesEs = config('global.editorial.blocked_phrases.es', []);
        $contentEsLower = strtolower($contentEs);
        foreach ($blockedPhrasesEs as $phrase) {
            if (str_contains($contentEsLower, $phrase)) {
                Log::warning("AI-fingerprint phrase found in content_es (auto-fixed): '{$phrase}' — RawArticle {$this->rawArticle->id}");
            }
        }

        // 9. Paragraph asymmetry (warnings only — not hard fails)
        foreach ($this->validateParagraphAsymmetry($contentEn, 'en') as $w) { Log::warning("Asymmetry: {$w}"); }
        foreach ($this->validateParagraphAsymmetry($contentEs, 'es') as $w) { Log::warning("Asymmetry: {$w}"); }

        // 10. Heading variety (warnings only)
        foreach ($this->validateHeadingVariety($contentEn) as $w) { Log::warning("Headings: {$w}"); }

        // 11. IMAGE token placement (warnings only)
        foreach ($this->validateImageTokenPlacement($contentEn, 'en') as $w) { Log::warning("Tokens: {$w}"); }
        foreach ($this->validateImageTokenPlacement($contentEs, 'es') as $w) { Log::warning("Tokens: {$w}"); }

        // 12. SEO technical validation (warnings only — too aggressive for hard fail)
        foreach ($this->validateSeoTechnical($data) as $w) { Log::warning("SEO: {$w}"); }

        return $errors;
    }

    /**
     * Auto-fix fields that exceed limits. Runs BEFORE validation.
     * Truncates titles, excerpts, meta fields. Logs what was fixed.
     */
    protected function autoFixRedactedOutput(array $data): array
    {
        $fixes = [];

        // ── In-Flight Self-Healing: Auto-translate if AI inverted or mixed languages ──
        if (!empty($data['content_en']) && $this->isSpanishContent($data['content_en'])) {
            Log::warning("Self-Healing: content_en for RawArticle #{$this->rawArticle->id} was generated in Spanish. Translating to English in-flight...");
            $data['content_en'] = $this->autoTranslateToLanguage($data['content_en'], 'en');
            $fixes[] = "content_en: auto-translated from Spanish to English";
        }

        if (!empty($data['content_es']) && $this->isEnglishContent($data['content_es'])) {
            Log::warning("Self-Healing: content_es for RawArticle #{$this->rawArticle->id} was generated in English. Translating to Spanish in-flight...");
            $data['content_es'] = $this->autoTranslateToLanguage($data['content_es'], 'es');
            $fixes[] = "content_es: auto-translated from English to Spanish";
        }

        if (!empty($data['title_en']) && $this->isSpanishShortText($data['title_en'])) {
            $data['title_en'] = $this->autoTranslateToLanguage($data['title_en'], 'en', isShortText: true);
            $fixes[] = "title_en: auto-translated from Spanish to English";
        }

        if (!empty($data['title_es']) && $this->isEnglishShortText($data['title_es'])) {
            $data['title_es'] = $this->autoTranslateToLanguage($data['title_es'], 'es', isShortText: true);
            $fixes[] = "title_es: auto-translated from English to Spanish";
        }

        $titleMin     = (int) config('global.editorial.limits.title.min', 50);
        $titleMax     = (int) config('global.editorial.limits.title.max', 130);
        $excerptMax   = (int) config('global.editorial.limits.excerpt.max', 250);
        $metaTitleMax = (int) config('global.editorial.limits.meta_title.max', 70);
        $metaDescMax  = (int) config('global.editorial.limits.meta_description.max', 160);

        // Clean and safely clamp excessively long titles using global config limit
        foreach (['title_en', 'title_es'] as $field) {
            if (!empty($data[$field])) {
                $t = trim(preg_replace('/\s+/u', ' ', strip_tags($data[$field])));
                $t = trim($t, " \t\n\r\0\x0B\"'«»“”");
                $t = trim($t);
                if (mb_strlen($t) > $titleMax) {
                    $original = $t;
                    $sub = mb_substr($t, 0, $titleMax);
                    $lastSpace = mb_strrpos($sub, ' ');
                    if ($lastSpace !== false && $lastSpace >= $titleMin) {
                        $sub = mb_substr($sub, 0, $lastSpace);
                    }
                    $sub = preg_replace('/\s+(?:de|del|con|para|por|en|a|y|e|o|u|que|sobre|tras|the|and|or|for|with|in|on|at|by|of|to|from|as|an|a)$/iu', '', $sub);
                    $t = rtrim($sub, " ,;:-–—/|\\");
                    $fixes[] = "{$field}: safely clamped from " . mb_strlen($original) . " to " . mb_strlen($t) . " chars";
                }
                $data[$field] = $t;
            }
        }

        // Truncate excerpts
        foreach (['excerpt_en', 'excerpt_es'] as $field) {
            if (mb_strlen($data[$field] ?? '') > $excerptMax) {
                $data[$field] = Str::limit($data[$field], $excerptMax, '');
                $fixes[] = "{$field}: truncated to {$excerptMax} chars";
            }
        }

        // Truncate meta titles
        foreach (['meta_title_en', 'meta_title_es'] as $field) {
            if (mb_strlen($data[$field] ?? '') > $metaTitleMax) {
                $data[$field] = Str::limit($data[$field], $metaTitleMax, '');
                $fixes[] = "{$field}: truncated to {$metaTitleMax} chars";
            }
        }

        // Truncate meta descriptions
        foreach (['meta_description_en', 'meta_description_es'] as $field) {
            if (mb_strlen($data[$field] ?? '') > $metaDescMax) {
                $data[$field] = Str::limit($data[$field], $metaDescMax, '');
                $fixes[] = "{$field}: truncated to {$metaDescMax} chars";
            }
        }

                // --- AUTO-FIX: Reconcile Image Tokens ---
        // 1. Remove [IMAGE_1] from content body (it is only for hero image)
        $data['content_en'] = str_replace('[IMAGE_1]', '', $data['content_en'] ?? '');
        $data['content_es'] = str_replace('[IMAGE_1]', '', $data['content_es'] ?? '');

        // 2. Reconcile inline tokens: if [IMAGE_N] has no matching prompt, strip it
        $promptIds = collect($data['image_prompts'] ?? [])->pluck('id')->toArray();
        if (!in_array('[IMAGE_1]', $promptIds)) {
            $data['image_prompts'][] = [
                'id' => '[IMAGE_1]',
                'prompt' => 'High quality editorial tech photography representing ' . ($data['title_en'] ?? 'technology news'),
                'alt' => $data['title_en'] ?? 'Technology News',
            ];
        }

        preg_match_all('/\[IMAGE_(\d+)\]/', $data['content_en'] ?? '', $matchesEn);
        foreach ($matchesEn[0] ?? [] as $token) {
            if (!in_array($token, $promptIds)) {
                $data['content_en'] = str_replace($token, '', $data['content_en']);
                $data['content_es'] = str_replace($token, '', $data['content_es']);
                $fixes[] = "Removed orphan token {$token} from content";
            }
        }

        // --- AUTO-FIX: Strip blocked AI-fingerprint phrases silently ---
        $blockedReplacementsEn = [
            'paradigm shift' => 'fundamental change', 'game-changer' => 'significant development',
            'revolutionary' => 'substantial', 'democratization of' => 'wider access to',
            'inflection point' => 'turning point', 'trajectory points toward' => 'trend suggests',
            'unprecedented scale' => 'massive scale', 'seamlessly integrate' => 'integrate',
            'robust ecosystem' => 'mature ecosystem', 'the digital landscape' => 'the industry',
            'it remains to be seen' => 'it is unclear', 'only time will tell' => 'the outcome is uncertain',
            'it\'s worth noting' => 'notably,', 'in today\'s rapidly evolving' => 'in a shifting',
            'at the end of the day' => 'ultimately,', 'raises important questions' => 'raises questions',
            'a bold step forward' => 'a deliberate move', 'double-edged sword' => 'trade-off',
            'the implications are profound' => 'the consequences matter',
            'a testament to' => 'evidence of',
            'let\'s dive in' => '', 'let me break this down' => '',
            'in my experience' => 'from what I\'ve observed,', 'low-hanging fruit' => 'obvious target',
            'home run' => 'success', 'slam dunk' => 'certainty', 'picture this' => '',
        ];
        $blockedReplacementsEs = [
            'cambio de paradigma' => 'cambio fundamental', 'en conclusión' => 'para cerrar,',
            'sin lugar a dudas' => 'con certeza,', 'cabe destacar' => 'es notable que',
            'queda por ver' => 'es incierto', 'un arma de doble filo' => 'una disyuntiva',
            'marca un antes y un después' => 'cambia las reglas',
            'las implicaciones son profundas' => 'las consecuencias importan',
            'en el mundo de' => 'en', 'sin ir más lejos' => '',
            'como ya hemos mencionado' => 'como se indicó antes,', 'en última instancia' => 'al final,',
            'es importante destacar' => 'destaca que', 'sin duda alguna' => 'con certeza,',
            'no cabe duda' => 'es evidente', 'vale la pena mencionar' => 'cabe señalar que',
        ];

        foreach (['content_en', 'title_en', 'excerpt_en'] as $field) {
            if (!empty($data[$field])) {
                $original = $data[$field];
                $data[$field] = str_ireplace(array_keys($blockedReplacementsEn), array_values($blockedReplacementsEn), $data[$field]);
                // Clean up double spaces left by empty replacements
                $data[$field] = preg_replace('/\s{2,}/', ' ', $data[$field]);
                if ($data[$field] !== $original) {
                    $fixes[] = "{$field}: AI-fingerprint phrases stripped";
                }
            }
        }
        foreach (['content_es', 'title_es', 'excerpt_es'] as $field) {
            if (!empty($data[$field])) {
                $original = $data[$field];
                $data[$field] = str_ireplace(array_keys($blockedReplacementsEs), array_values($blockedReplacementsEs), $data[$field]);
                $data[$field] = preg_replace('/\s{2,}/', ' ', $data[$field]);
                if ($data[$field] !== $original) {
                    $fixes[] = "{$field}: AI-fingerprint phrases stripped (ES)";
                }
            }
        }

        if (!empty($fixes)) {
            Log::info('autoFixRedactedOutput: ' . implode(', ', $fixes));
        }

        return $data;
    }

    /**
     * Calculate reading time for both languages and return the maximum.
     * English: ~225 WPM, Spanish: ~165 WPM (longer words).
     * Using the max ensures the displayed time is accurate for both audiences.
     */
    protected function calculateReadingTime(string $contentEn, string $contentEs = ''): int
    {
        $wordsEn = str_word_count(strip_tags($contentEn));
        $timeEn  = (int) ceil($wordsEn / 225);

        if (empty($contentEs)) {
            return max(1, $timeEn);
        }

        $wordsEs = count(preg_split('/\s+/', trim(strip_tags($contentEs))));
        $timeEs  = (int) ceil($wordsEs / 165);

        return max(1, $timeEn, $timeEs);
    }

    public function failed(\Throwable $exception): void
    {
        $isAuth = $exception instanceof OpenRouterAuthenticationException;
        $isPermanent = $isAuth || str_contains($exception->getMessage(), '401');

        $metadata = $this->rawArticle->metadata ?? [];
        $metadata['last_error'] = [
            'message'   => \Illuminate\Support\Str::limit($exception->getMessage(), 350),
            'exception' => class_basename($exception),
            'failed_at' => now()->toIso8601String(),
        ];

        $this->rawArticle->update([
            'status'   => 'failed',
            'metadata' => $metadata,
        ]);

        Log::error("Job failed for RawArticle {$this->rawArticle->id}: {$exception->getMessage()}", [
            'permanent'    => $isPermanent,
            'exception'    => get_class($exception),
            'attempts'     => $this->attempts(),
            'will_retry'   => !$isPermanent,
        ]);
    }

    // -------------------------------------------------------------------------
    // NEW VALIDATION & HELPER METHODS
    // -------------------------------------------------------------------------

    /**
     * Extract meaningful paragraph-level blocks from HTML content.
     * Handles <p>, <h2>, <blockquote>, <li>, and fragment lines.
     */
    private function extractParagraphs(string $html): array
    {
        // Split by common block-level HTML tags
        $blocks = preg_split('/\n\n+/', strip_tags($html));
        return array_values(array_filter(array_map('trim', $blocks), fn($b) => mb_strlen($b) > 3));
    }

    /**
     * Count sentences in a text block with multilingual support.
     * Handles abbreviations, URLs, decimal numbers, and ES punctuation.
     */
    private function countSentences(string $text): int
    {
        $clean = strip_tags($text);

        // Single regex to PROTECT abbreviations (replaces "Dr." with "Dr " — strips the period so it doesn't count as sentence end)
        $abbrevPattern = '(\\b(?:Dr|Mr|Mrs|Ms|Prof|Sr|Sra|Ing|Lic|EE\\\\.UU|U\\\\.S|U\\\\.K|etc|vs|approx|aprox|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec|Lun|Mar|Mie|Jue|Vie|Sab|Dom))\\.';
        $clean = preg_replace("/{$abbrevPattern}/i", '$1 ', $clean);

        // Protect URLs and decimal numbers
        $clean = preg_replace('/https?:\/\/[^\s]+/', '', $clean);
        $clean = preg_replace('/\d+\.\d+/', '', $clean);

        // Split by sentence terminators followed by space + uppercase (EN + ES)
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-ZÁÉÍÓÚÑ¿¡"])/u', $clean, -1, PREG_SPLIT_NO_EMPTY);

        // Filter out fragments too short to be real sentences
        return count(array_filter($sentences, fn($s) => mb_strlen(trim($s)) > 5));
    }

    /**
     * Validate paragraph asymmetry (T1).
     * Detects AI-fingerprint patterns: uniform paragraph lengths, no single-sentence paragraphs, etc.
     * Returns array of warning strings (not hard errors).
     */
    /**
     * In-flight self-healing auto-translation to ensure strict bilingual purity.
     */
    protected function autoTranslateToLanguage(string $text, string $targetLang, bool $isShortText = false): string
    {
        try {
            $ai = app(\App\Services\AI\OpenRouterService::class);
            $langName = $targetLang === 'es' ? 'Spanish' : 'English';

            if ($isShortText) {
                $prompt = "You are a professional tech journalism headline editor. Translate the following text into 100% natural, fluent {$langName}. Return ONLY the translation without quotes, markdown or extra words.\n\nTEXT:\n" . trim(strip_tags($text));
                $res = $ai->complete([['role' => 'user', 'content' => $prompt]], config('ai_models.default'));
                return trim(preg_replace('/^["\'«“]+|["\'»”]+$/u', '', trim($res)));
            }

            $prompt = <<<PROMPT
You are a senior tech journalism editor for Glodaxia.
Translate and rewrite the following HTML article content into 100% fluent, native, and in-depth {$langName} journalism.

CRITICAL RULES:
1. The output MUST be 100% in {$langName}. Absolutely zero foreign language words.
2. Preserve all HTML tags (<p>, <h2>, <ul>, <li>, <strong>, <em>, etc.) and [IMAGE_N] placeholders exactly intact on their own lines.
3. Keep the full journalistic depth, tone, and facts.
4. Output ONLY the resulting HTML content (no markdown wrappers, no code blocks, no preamble).

CONTENT TO TRANSLATE:
{$text}
PROMPT;

            $res = $ai->complete([['role' => 'user', 'content' => $prompt]], config('ai_models.default'));
            return trim(preg_replace('/^```(?:html)?\s*|\s*```$/i', '', trim($res)));
        } catch (\Throwable $e) {
            Log::error("autoTranslateToLanguage failed for RawArticle #{$this->rawArticle->id}: " . $e->getMessage());
            return $text;
        }
    }

    protected function isSpanishContent(string $html): bool
    {
        if (empty(trim($html))) return false;
        $esCount = $this->countLanguageStopwords($html, 'es');
        $enCount = $this->countLanguageStopwords($html, 'en');
        return ($esCount > $enCount && $esCount >= 15) || ($esCount >= 20 && $enCount < 10);
    }

    protected function isEnglishContent(string $html): bool
    {
        if (empty(trim($html))) return false;
        $enCount = $this->countLanguageStopwords($html, 'en');
        $esCount = $this->countLanguageStopwords($html, 'es');
        return ($enCount > $esCount && $enCount >= 15) || ($enCount >= 20 && $esCount < 10);
    }

    protected function isSpanishShortText(string $text): bool
    {
        $esCount = preg_match_all('/\b(?:de|la|los|las|del|en|para|con|sobre|según|este|esta)\b/iu', $text);
        $enCount = preg_match_all('/\b(?:the|and|with|from|which|about|between|for|after)\b/iu', $text);
        return $esCount >= 2 && $enCount === 0;
    }

    protected function isEnglishShortText(string $text): bool
    {
        $enCount = preg_match_all('/\b(?:the|and|with|from|which|about|between|for|after)\b/iu', $text);
        $esCount = preg_match_all('/\b(?:de|la|los|las|del|en|para|con|sobre|según|este|esta)\b/iu', $text);
        return $enCount >= 2 && $esCount === 0;
    }

    protected function countLanguageStopwords(string $text, string $lang): int
    {
        $esStopwords = [
            'de', 'la', 'que', 'el', 'en', 'y', 'a', 'los', 'del', 'se', 'las', 'por', 'un', 'para', 'con', 'no',
            'una', 'su', 'al', 'lo', 'como', 'más', 'mas', 'pero', 'sus', 'le', 'ya', 'o', 'este', 'sí', 'si',
            'porque', 'esta', 'entre', 'cuando', 'muy', 'sin', 'sobre', 'también', 'tambien', 'me', 'hasta', 'hay',
            'donde', 'quien', 'desde', 'todo', 'nos', 'durante', 'todos', 'uno', 'les', 'ni', 'contra', 'otros',
            'ese', 'eso', 'ante', 'ellos', 'e', 'esto', 'mí', 'mi', 'antes', 'algunos', 'qué', 'unos', 'otro',
            'otras', 'otra', 'él', 'tanto', 'esa', 'estos', 'mucho', 'quienes', 'nada', 'muchos', 'cual', 'sea',
            'poco', 'ella', 'estar', 'haber', 'estas', 'estaba', 'estamos', 'algunas', 'algo', 'nosotros', 'según',
            'además', 'después', 'través', 'año', 'años', 'cuenta', 'forma', 'parte', 'llegó', 'crear', 'nueva'
        ];

        $enStopwords = [
            'the', 'and', 'of', 'to', 'a', 'in', 'that', 'is', 'was', 'for', 'on', 'are', 'as', 'with', 'his',
            'they', 'at', 'be', 'this', 'have', 'from', 'or', 'one', 'had', 'by', 'word', 'but', 'not', 'what',
            'all', 'were', 'we', 'when', 'your', 'can', 'said', 'there', 'use', 'an', 'each', 'which', 'she',
            'do', 'how', 'their', 'if', 'will', 'up', 'other', 'about', 'out', 'many', 'then', 'them', 'these',
            'so', 'some', 'her', 'would', 'make', 'like', 'him', 'into', 'time', 'has', 'look', 'two', 'more',
            'write', 'go', 'see', 'number', 'way', 'could', 'people', 'than', 'first', 'water', 'been', 'call',
            'who', 'its', 'now', 'find', 'also', 'new', 'after', 'state', 'only', 'year', 'years', 'over', 'most'
        ];

        $stopwords = $lang === 'es' ? $esStopwords : $enStopwords;
        $cleaned = mb_strtolower(strip_tags($text));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $cleaned, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) return 0;
        $swMap = array_flip($stopwords);
        $count = 0;
        foreach ($words as $w) {
            if (isset($swMap[$w])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Strictly validate that content, titles, and excerpts are written in their assigned language.
     */
    protected function validateLanguageIntegrity(array $data): array
    {
        $errors = [];

        $esStopwords = [
            'de', 'la', 'que', 'el', 'en', 'y', 'a', 'los', 'del', 'se', 'las', 'por', 'un', 'para', 'con', 'no',
            'una', 'su', 'al', 'lo', 'como', 'más', 'mas', 'pero', 'sus', 'le', 'ya', 'o', 'este', 'sí', 'si',
            'porque', 'esta', 'entre', 'cuando', 'muy', 'sin', 'sobre', 'también', 'tambien', 'me', 'hasta', 'hay',
            'donde', 'quien', 'desde', 'todo', 'nos', 'durante', 'todos', 'uno', 'les', 'ni', 'contra', 'otros',
            'ese', 'eso', 'ante', 'ellos', 'e', 'esto', 'mí', 'mi', 'antes', 'algunos', 'qué', 'unos', 'otro',
            'otras', 'otra', 'él', 'tanto', 'esa', 'estos', 'mucho', 'quienes', 'nada', 'muchos', 'cual', 'sea',
            'poco', 'ella', 'estar', 'haber', 'estas', 'estaba', 'estamos', 'algunas', 'algo', 'nosotros', 'según',
            'además', 'después', 'través', 'año', 'años', 'cuenta', 'forma', 'parte', 'llegó', 'crear', 'nueva',
            'nuevo', 'nuevos', 'nuevas', 'sistema', 'último', 'últimos', 'primer', 'primera', 'momento', 'mayor'
        ];

        $enStopwords = [
            'the', 'and', 'of', 'to', 'a', 'in', 'that', 'is', 'was', 'for', 'on', 'are', 'as', 'with', 'his',
            'they', 'at', 'be', 'this', 'have', 'from', 'or', 'one', 'had', 'by', 'word', 'but', 'not', 'what',
            'all', 'were', 'we', 'when', 'your', 'can', 'said', 'there', 'use', 'an', 'each', 'which', 'she',
            'do', 'how', 'their', 'if', 'will', 'up', 'other', 'about', 'out', 'many', 'then', 'them', 'these',
            'so', 'some', 'her', 'would', 'make', 'like', 'him', 'into', 'time', 'has', 'look', 'two', 'more',
            'write', 'go', 'see', 'number', 'way', 'could', 'people', 'than', 'first', 'water', 'been', 'call',
            'who', 'its', 'now', 'find', 'also', 'new', 'after', 'state', 'only', 'year', 'years', 'over', 'most',
            'such', 'where', 'both', 'between', 'during', 'through', 'under', 'while', 'because', 'should', 'well'
        ];

        $countStopwords = function (string $text, array $stopwords): int {
            $cleaned = mb_strtolower(strip_tags($text));
            $words = preg_split('/[^\p{L}\p{N}]+/u', $cleaned, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($words)) return 0;
            $swMap = array_flip($stopwords);
            $count = 0;
            foreach ($words as $w) {
                if (isset($swMap[$w])) {
                    $count++;
                }
            }
            return $count;
        };

        // 1. Validate content_en (MUST BE ENGLISH)
        $contentEn = $data['content_en'] ?? '';
        $enScoreInEn = $countStopwords($contentEn, $enStopwords);
        $esScoreInEn = $countStopwords($contentEn, $esStopwords);
        if ($esScoreInEn > $enScoreInEn || ($esScoreInEn >= 15 && $enScoreInEn < 20)) {
            $errors[] = "FATAL LANGUAGE MISMATCH: content_en appears to be written in Spanish! (Found {$esScoreInEn} Spanish stopwords vs {$enScoreInEn} English stopwords). content_en MUST be 100% English.";
        }

        // 2. Validate content_es (MUST BE SPANISH)
        $contentEs = $data['content_es'] ?? '';
        $enScoreInEs = $countStopwords($contentEs, $enStopwords);
        $esScoreInEs = $countStopwords($contentEs, $esStopwords);
        if ($enScoreInEs > $esScoreInEs || ($enScoreInEs >= 15 && $esScoreInEs < 20)) {
            $errors[] = "FATAL LANGUAGE MISMATCH: content_es appears to be written in English! (Found {$enScoreInEs} English stopwords vs {$esScoreInEs} Spanish stopwords). content_es MUST be 100% Spanish.";
        }

        // 3. Validate title_en and title_es
        $titleEn = $data['title_en'] ?? '';
        $titleEs = $data['title_es'] ?? '';
        
        $esMarkersInTitleEn = $countStopwords($titleEn, ['de', 'la', 'los', 'las', 'del', 'en', 'para', 'con', 'sobre', 'según', 'después', 'través', 'este', 'esta', 'como']);
        $enMarkersInTitleEn = $countStopwords($titleEn, ['the', 'and', 'with', 'from', 'which', 'about', 'between', 'for', 'in', 'on', 'at', 'by', 'after', 'under']);
        if ($esMarkersInTitleEn >= 2 && $enMarkersInTitleEn === 0) {
            $errors[] = "FATAL LANGUAGE MISMATCH: title_en contains Spanish words ('{$titleEn}'). title_en MUST be 100% English.";
        }

        $enMarkersInTitleEs = $countStopwords($titleEs, ['the', 'and', 'with', 'from', 'which', 'about', 'between', 'for', 'after', 'under', 'through']);
        $esMarkersInTitleEs = $countStopwords($titleEs, ['de', 'la', 'los', 'las', 'del', 'en', 'para', 'con', 'sobre', 'según', 'como', 'este', 'esta']);
        if ($enMarkersInTitleEs >= 2 && $esMarkersInTitleEs === 0) {
            $errors[] = "FATAL LANGUAGE MISMATCH: title_es contains English words ('{$titleEs}'). title_es MUST be 100% Spanish.";
        }

        // 4. Validate excerpt_en and excerpt_es
        $excerptEn = $data['excerpt_en'] ?? '';
        $excerptEs = $data['excerpt_es'] ?? '';
        if (!empty($excerptEn)) {
            $enScoreInExcerptEn = $countStopwords($excerptEn, $enStopwords);
            $esScoreInExcerptEn = $countStopwords($excerptEn, $esStopwords);
            if ($esScoreInExcerptEn > $enScoreInExcerptEn && $esScoreInExcerptEn >= 4) {
                $errors[] = "FATAL LANGUAGE MISMATCH: excerpt_en appears to be written in Spanish ('{$excerptEn}'). excerpt_en MUST be 100% English.";
            }
        }
        if (!empty($excerptEs)) {
            $enScoreInExcerptEs = $countStopwords($excerptEs, $enStopwords);
            $esScoreInExcerptEs = $countStopwords($excerptEs, $esStopwords);
            if ($enScoreInExcerptEs > $esScoreInExcerptEs && $enScoreInExcerptEs >= 4) {
                $errors[] = "FATAL LANGUAGE MISMATCH: excerpt_es appears to be written in English ('{$excerptEs}'). excerpt_es MUST be 100% Spanish.";
            }
        }

        return $errors;
    }

    private function validateParagraphAsymmetry(string $html, string $lang): array
    {
        $warnings = [];
        $paragraphs = $this->extractParagraphs($html);

        if (count($paragraphs) < 4) {
            return $warnings;
        }

        $sentenceCounts = array_map(fn($p) => $this->countSentences($p), $paragraphs);

        // Check for 3+ consecutive paragraphs with same sentence count
        $consecutive = 1;
        for ($i = 1; $i < count($sentenceCounts); $i++) {
            if ($sentenceCounts[$i] === $sentenceCounts[$i - 1] && $sentenceCounts[$i] > 0) {
                $consecutive++;
                if ($consecutive >= 3) {
                    $warnings[] = "{$lang}: {$consecutive} consecutive paragraphs with {$sentenceCounts[$i]} sentence(s) — AI fingerprint";
                }
            } else {
                $consecutive = 1;
            }
        }

        // Check minimum single-sentence paragraphs exist
        $singleSentence = count(array_filter($sentenceCounts, fn($c) => $c === 1));
        if ($singleSentence === 0 && count($paragraphs) >= 5) {
            $warnings[] = "{$lang}: No single-sentence paragraphs found — lacks rhetorical punch";
        }

        // Check for too many consecutive single-sentence paragraphs (artificial drama)
        $consecutiveShort = 0;
        for ($i = 0; $i < count($sentenceCounts); $i++) {
            if ($sentenceCounts[$i] === 1) {
                $consecutiveShort++;
                if ($consecutiveShort >= 3) {
                    $warnings[] = "{$lang}: {$consecutiveShort} consecutive single-sentence paragraphs — artificial drama";
                }
            } else {
                $consecutiveShort = 0;
            }
        }

        // Check long paragraphs exist
        $longParagraphs = count(array_filter($sentenceCounts, fn($c) => $c >= 6));
        if ($longParagraphs === 0 && count($paragraphs) >= 6) {
            $warnings[] = "{$lang}: No long paragraphs (6+ sentences) found — lacks depth";
        }

        return $warnings;
    }

    /**
     * Validate H2 heading variety.
     * AI-generated content tends to have uniform heading lengths.
     */
    private function validateHeadingVariety(string $html): array
    {
        $warnings = [];
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $matches);
        $headings = $matches[1] ?? [];

        if (count($headings) >= 3) {
            $wordCounts = array_map(fn($h) => str_word_count(strip_tags($h)), $headings);
            $uniqueCounts = array_unique($wordCounts);

            // If all headings have same word count (±1) → flag
            if (count($uniqueCounts) <= 2 && count($headings) >= 3) {
                $warnings[] = 'H2 headings have suspiciously uniform word counts: ' . implode(', ', $wordCounts);
            }

            // If all headings are exactly 4-6 words → classic AI tell
            $allShort = !empty(array_filter($wordCounts, fn($c) => $c >= 4 && $c <= 6));
            if ($allShort && count(array_unique(array_map(fn($c) => $c >= 4 && $c <= 6 ? 'mid' : 'other', $wordCounts))) === 1) {
                $warnings[] = 'All H2 headings are 4-6 words — classic AI fingerprint';
            }
        }

        return $warnings;
    }

    /**
     * Validate IMAGE token placement in HTML content.
     * Tokens must be on their own standalone line, not inside <p> tags.
     */
    private function validateImageTokenPlacement(string $html, string $lang): array
    {
        $warnings = [];
        $lines = explode("\n", $html);

        foreach ($lines as $i => $line) {
            if (preg_match('/\[IMAGE_(\d+)\]/', $line, $m)) {
                $trimmed = trim($line);
                // Token should be alone on its line (the token itself and nothing else meaningful)
                if ($trimmed !== $m[0] && mb_strlen(str_replace($m[0], '', $trimmed)) > 10) {
                    $warnings[] = "{$lang} line " . ($i + 1) . ": IMAGE token not standalone — extra content on same line";
                }
                // Token must NOT be inside a <p> tag on the same line
                if (str_contains($line, '<p') && str_contains($line, '</p>')) {
                    $warnings[] = "{$lang} line " . ($i + 1) . ": IMAGE token found inside <p> tag";
                }
            }
        }

        return $warnings;
    }

    /**
     * Validate SEO technical requirements (CTO recommendation).
     * Returns array of warning strings.
     */
    private function validateSeoTechnical(array $data): array
    {
        $warnings = [];

        $contentEn = strip_tags($data['content_en'] ?? '');
        $keywords = $data['keywords'] ?? [];
        $primaryKw = strtolower($keywords[0] ?? '');

        if (empty($primaryKw)) {
            $warnings[] = 'No keywords provided — cannot validate SEO';
            return $warnings;
        }

        // 1. Primary keyword in first 100 words
        $words = str_word_count($contentEn, 1);
        $first100 = strtolower(implode(' ', array_slice($words, 0, 100)));
        if (!str_contains($first100, $primaryKw)) {
            $warnings[] = "Primary keyword '{$primaryKw}' not found in first 100 words";
        }

        // 2. Keyword density (aligned with prompt: 0.5% - 2.5%)
        $totalWords = count($words);
        if ($totalWords > 0) {
            $keywordCount = mb_substr_count(strtolower($contentEn), $primaryKw);
            $density = ($keywordCount / $totalWords) * 100;
            if ($density < 0.5) {
                $warnings[] = "Keyword density too low: " . round($density, 2) . "% (minimum 0.5%)";
            } elseif ($density > 2.5) {
                $warnings[] = "Keyword density too high: " . round($density, 2) . "% (maximum 2.5%) — possible keyword stuffing";
            }
        }

        // 3. Primary keyword in at least 1 H2
        $contentHtml = $data['content_en'] ?? '';
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $contentHtml, $h2Matches);
        $h2Text = strtolower(strip_tags(implode(' ', $h2Matches[1] ?? [])));
        if (!empty($h2Matches[1]) && !str_contains($h2Text, $primaryKw)) {
            $warnings[] = "Primary keyword '{$primaryKw}' not found in any H2 heading";
        }

        // 4. JSON-LD basic validation
        $jsonLd = $data['json_ld'] ?? null;
        if ($jsonLd) {
            if (empty($jsonLd['@type'])) {
                $warnings[] = 'JSON-LD missing @type field';
            }
            if (empty($jsonLd['headline'])) {
                $warnings[] = 'JSON-LD missing headline field';
            }
            if (empty($jsonLd['author']['name'])) {
                $warnings[] = 'JSON-LD missing author name';
            }
        }

        return $warnings;
    }

    /**
     * Generate a placeholder hero image when SiliconFlow fails.
     * Creates a dark gradient image with the article title as text overlay.
     */
    private function generatePlaceholderHero(string $title, string $slug): ?string
    {
        if (!extension_loaded('gd')) {
            Log::error("generatePlaceholderHero: GD extension not loaded. Cannot generate placeholder image.", [
                'title' => $title,
                'slug' => $slug,
            ]);
            return null;
        }

        try {
            $width  = 1280;
            $height = 720;

            $img = imagecreatetruecolor($width, $height);
            if (!$img) return null;

            // Dark gradient background (slate-900 to slate-800)
            for ($y = 0; $y < $height; $y++) {
                $ratio = $y / $height;
                $r = (int)(15 + (30 - 15) * $ratio);
                $g = (int)(23 + (41 - 23) * $ratio);
                $b = (int)(42 + (59 - 42) * $ratio);
                $color = imagecolorallocate($img, $r, $g, $b);
                imageline($img, 0, $y, $width, $y, $color);
            }

            // Brand accent line (cyan-500)
            $accent = imagecolorallocate($img, 6, 182, 212);
            imagefilledrectangle($img, 0, $height - 4, $width, $height, $accent);

            // Title text (white, centered)
            $white = imagecolorallocate($img, 255, 255, 255);
            $fontSize = 5;
            $titleShort = Str::limit($title, 120, '');
            $textWidth = imagefontwidth($fontSize) * strlen($titleShort);
            $x = max(20, (int)(($width - $textWidth) / 2));
            $y = (int)(($height / 2) - (imagefontheight($fontSize) / 2));
            imagestring($img, $fontSize, $x, $y, $titleShort, $white);

            // "Glodaxia" watermark (bottom-right, gray)
            $gray = imagecolorallocate($img, 100, 116, 139);
            imagestring($img, 3, $width - 120, $height - 30, 'Glodaxia.com', $gray);

            // Save as webp
            $path = storage_path("app/public/placeholder-{$slug}.webp");
            $saved = imagewebp($img, $path, 85);
            imagedestroy($img);

            return $saved ? $path : null;
        } catch (\Throwable $e) {
            Log::error("generatePlaceholderHero failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate randomized style DNA for each article.
     * 6 x 5 x 5 x 4 x 10 x 12 x 8 x 8 = 9,216,000 unique macro-combinations.
     * Each call shuffles arrays and picks one option per dimension.
     */
    /**
     * Generates a rich, randomized editorial "Style DNA" for each article.
     *
     * Combines:
     *  - 12 narrative archetypes (structural blueprints with calibrated temperature)
     *  - 6 narrative voice perspectives
     *  - 50 dynamic opening hooks (rotated randomly per article)
     *  - 5 closing styles (varied endings)
     *
     * Produces virtually infinite variety across 10,000+ articles with zero structural monotony.
     */
    private function generateStyleDNA(): array
    {
        // ─────────────────────────────────────────────────────────────────
        // 12 NARRATIVE ARCHETYPES — distinct structure + calibrated temperature
        // ─────────────────────────────────────────────────────────────────
        $archetypes = [
            'concise_punchy_column' => [
                'name'        => 'Columna Agil y Directa (Minimo estricto: 700 palabras, extensible a 1100+)',
                'structure'   => 'Abre con dato o afirmacion impactante. 2-3 secciones <h2> cortas y contundentes. Parrafos de 2-3 oraciones maximas. Cada seccion tiene un micro-argumento propio con conclusion parcial. Ritmo rapido estilo The Register.',
                'image_count' => random_int(1, 2),
                'temp_range'  => [0.72, 0.82],
            ],
            'deep_investigative_breakdown' => [
                'name'        => 'Reportaje de Investigacion Profundo (Minimo estricto: 1200 palabras, extensible a 2000+)',
                'structure'   => '4 secciones <h2>: (1) Hallazgo central y contexto, (2) Analisis tecnico con datos concretos, (3) Impacto en la industria, (4) Proyeccion y consecuencias. Incluye 1 cita en <blockquote>. Tono MIT Technology Review.',
                'image_count' => random_int(2, 3),
                'temp_range'  => [0.55, 0.65],
            ],
            'inverted_pyramid_breaking' => [
                'name'        => 'Piramide Invertida / Breaking News (Minimo estricto: 700 palabras, extensible a 1300+)',
                'structure'   => 'EMPIEZA con la conclusion o dato mas impactante sin preambulo. Luego desciende: Que paso => Por que importa => Que dicen los implicados => Contexto historico => Proyeccion. Cierra con vision de futuro.',
                'image_count' => random_int(1, 2),
                'temp_range'  => [0.60, 0.70],
            ],
            'faq_driven_explainer' => [
                'name'        => 'Explicador con Preguntas Clave (Minimo estricto: 800 palabras, extensible a 1600+)',
                'structure'   => '4-5 preguntas concretas como secciones <h2>: "Que cambio exactamente?", "A quien afecta y como?", "Por que ahora?", "Que alternativas existen?", "Que debo esperar?". Estructura de Explainer WIRED / The Verge.',
                'image_count' => random_int(1, 2),
                'temp_range'  => [0.65, 0.75],
            ],
            'verdict_first_review' => [
                'name'        => 'Veredicto-Primero / Review Ejecutivo (Minimo estricto: 750 palabras, extensible a 1400+)',
                'structure'   => 'Primer parrafo: VEREDICTO EDITORIAL en 2-3 oraciones. Cuerpo: justificacion con datos, comparaciones y analisis. Cierre: resumen ejecutivo de una sola frase sintetica. Tono Ars Technica.',
                'image_count' => random_int(1, 2),
                'temp_range'  => [0.68, 0.78],
            ],
            'timeline_sequential' => [
                'name'        => 'Cronologia Secuencial / Historia de un Incidente (Minimo estricto: 900 palabras, extensible a 1800+)',
                'structure'   => 'Estructura de linea de tiempo. <h2> con fechas o fases. Ideal para postmortems, vulnerabilidades, lanzamientos por etapas. Estilo WIRED longform.',
                'image_count' => random_int(2, 3),
                'temp_range'  => [0.58, 0.68],
            ],
            'data_driven_analysis' => [
                'name'        => 'Analisis Centrado en Datos y Metricas (Minimo estricto: 850 palabras, extensible a 1700+)',
                'structure'   => 'Cada seccion <h2> ancla su argumento en un dato cuantitativo especifico. Usa <ul>/<ol> para cifras comparativas. Al menos 2 contrastes numericos directos. Cierre cuantificando impacto proyectado. Bloomberg Technology.',
                'image_count' => random_int(2, 3),
                'temp_range'  => [0.55, 0.65],
            ],
            'narrative_scene_opening' => [
                'name'        => 'Narrativa Cinematografica con Apertura de Escena (Minimo estricto: 950 palabras, extensible a 1800+)',
                'structure'   => 'Abre con escena vivida y concreta. 2-3 lineas cinematograficas luego transicion al analisis. 3 secciones <h2> con narrativa rica. Cierra volviendo a la escena inicial. Estilo The Atlantic Tech.',
                'image_count' => random_int(2, 3),
                'temp_range'  => [0.78, 0.90],
            ],
            'debate_two_sides' => [
                'name'        => 'Articulo de Debate: Dos Perspectivas Validas (Minimo estricto: 850 palabras, extensible a 1600+)',
                'structure'   => 'Tension genuina entre dos posiciones. Seccion 1: argumento a favor con evidencia. Seccion 2: argumento en contra con evidencia. Seccion 3: balance editorial razonado. Evita falsa neutralidad.',
                'image_count' => random_int(1, 2),
                'temp_range'  => [0.70, 0.82],
            ],
            'comparison_shootout' => [
                'name'        => 'Comparativa Tecnica Cara a Cara (Minimo estricto: 850 palabras, extensible a 1600+)',
                'structure'   => 'Comparacion directa 2-3 opciones/tecnologias. Cada dimension de comparacion es un <h2>. Veredicto final claro. Usa <ul> para datos paralelos. Estilo Ars Technica shootout.',
                'image_count' => random_int(2, 3),
                'temp_range'  => [0.62, 0.72],
            ],
            'trend_implications_analysis' => [
                'name'        => 'Analisis de Tendencia e Implicaciones Futuras (Minimo estricto: 800 palabras, extensible a 1500+)',
                'structure'   => '<h2> 1: Que esta pasando mas alla del titular. <h2> 2: Por que era inevitable (contexto 12-24 meses). <h2> 3: Tres implicaciones concretas para la audiencia. Cierre: pronostico de plazo medio. The Information.',
                'image_count' => random_int(1, 2),
                'temp_range'  => [0.67, 0.77],
            ],
            'myth_busting_column' => [
                'name'        => 'Columna Desmontando un Mito o Exageracion (Minimo estricto: 700 palabras, extensible a 1400+)',
                'structure'   => '(1) El mito que circula, expuesto claramente. (2) Lo que los datos muestran, desmontaje con evidencia. (3) Lo que si es verdad y lo que no, conclusion matizada. Tono critico constructivo. NY Times Tech Opinion.',
                'image_count' => random_int(1, 2),
                'temp_range'  => [0.72, 0.85],
            ],
        ];

        // ─────────────────────────────────────────────────────────────────
        // 6 NARRATIVE VOICE PERSPECTIVES
        // ─────────────────────────────────────────────────────────────────
        $perspectives = [
            'editorial_collective' => [
                'voice' => 'Redaccion Editorial Colegiada, Primera Persona Plural',
                'rule'  => 'Escribe desde la perspectiva del equipo de Glodaxia ("En nuestro laboratorio de pruebas...", "Al contrastar estas cifras en la redaccion...", "Lo que observamos tras semanas de seguimiento..."). Rigor de redaccion tecnica especializada.',
            ],
            'technical_observer' => [
                'voice' => 'Observador Tecnico en Tercera Persona, Periodismo Analitico Inmersivo',
                'rule'  => 'Tono periodistico objetivo y riguroso. Conecta desde experiencias del ecosistema ("Los equipos de SRE que gestionan miles de instancias se enfrentan a...", "Para cualquier arquitecto que haya disenado sistemas distribuidos, este cambio resuena inmediatamente"). Evita anecdotas personales inventadas.',
            ],
            'individual_columnist' => [
                'voice' => 'Columnista de Firma, Primera Persona Singular Sobria',
                'rule'  => 'Columnista experto con opinion editorial propia ("Al analizar la documentacion tecnica...", "Mi valoracion tras revisar los benchmarks es clara...", "Lo que me sorprende no es el anuncio sino su momento estrategico"). JAMAS cliches de espacio fisico ("en mi laptop", "en mi telefono").',
            ],
            'practitioner_community' => [
                'voice' => 'Perspectiva Comunitaria, Voz de la Comunidad de Practicantes',
                'rule'  => 'Conecta con la experiencia compartida ("Cualquiera que haya debuggeado una race condition en produccion entiende...", "Quienes llevamos anos migrando arquitecturas monoliticas sabemos que la promesa suena mejor en el slide de ventas que en el backlog real"). Nosotros implicito sin ser condescendiente.',
            ],
            'investigative_journalist' => [
                'voice' => 'Periodista de Investigacion, Narrativa Profunda con Fuentes',
                'rule'  => 'Escribe como periodista con semanas de investigacion ("Tras revisar los commits publicos...", "Las conversaciones con ingenieros que prefieren el anonimato sugieren que...", "La documentacion filtrada el mes pasado adelantaba exactamente esto"). Cita fuentes genericas pero creibles.',
            ],
            'opinionated_expert' => [
                'voice' => 'Experto con Opinion Editorial Firme, Autoridad Critica sin Ambiguedad',
                'rule'  => 'Autoridad critica sin neutralidad falsa ("Esto no es una mejora incremental, es un error de diseno disfrazado de feature", "La industria lleva dos anos hablando de esto y por fin alguien lo hizo bien", "Hay tres razones por las que este anuncio no es lo que parece"). El lector siente que lee a alguien con mas contexto.',
            ],
        ];

        // ─────────────────────────────────────────────────────────────────
        // 50 DYNAMIC OPENING HOOKS (rotated randomly per article)
        // The archetype provides STRUCTURAL guidance. The hook provides the ENTRY POINT.
        // The AI has full creative freedom to use these as starting impulses,
        // not rigid templates. They are creative provocations, not formulas.
        // ─────────────────────────────────────────────────────────────────
        $openingHooks = [
            // DATOS E IMPACTO ESTADISTICO
            'stat_scale'        => 'Start with one precise, surprising number from the facts, isolated. Then build the paragraph around why that single figure changes the conversation.',
            'stat_compression'  => 'Open with a time or effort compression: what used to take X now takes Y. Make the reader feel the magnitude of that delta before explaining how.',
            'stat_ratio'        => 'Open with a ratio or proportion that reveals a hidden truth. Let the number do the heavy lifting in the first sentence.',
            'stat_cost'         => 'Open with the real economic or opportunity cost of the old approach vs. the new, in concrete terms, not percentages.',
            'stat_adoption'     => 'Open with an adoption gap: the distance between what is possible and what is actually deployed at scale in the industry right now.',

            // ESCENAS CINEMATOGRAFICAS
            'scene_3am'         => 'Open with a specific visceral scene: an engineer at 3 AM, a Slack alert firing, a pipeline silently failing. 2-3 crisp sentences of immersive detail, then pivot to the broader significance.',
            'scene_midaction'   => 'Drop the reader mid-story, already in motion. No preamble. No "today, company X announced..." Start where it matters.',
            'scene_before'      => 'Describe the "before" state in precise operational detail: what a team did every week before this change. Let the contrast with the new reality emerge naturally.',
            'scene_discovery'   => 'Open with the exact moment the discovery, failure, or announcement became real for the people closest to it. Specific, human, grounded.',
            'scene_decision'    => 'Open with the decision moment: a meeting, a commit, a late-night call, where the direction that led to this announcement was set. Consequential and specific.',

            // PREGUNTAS RETOHRICAS
            'rq_sharp'          => 'Open with one sharp, specific rhetorical question the article will answer, but not immediately. Let it hang for a beat.',
            'rq_assumption'     => 'Open with a question that exposes an assumption the reader likely holds, then spend the article rigorously testing it against the evidence.',
            'rq_tension'        => 'Open with two competing questions framing a genuine tension. "Is this X or Y? The industry is still negotiating the answer."',
            'rq_impossible'     => 'Open with a question that, a year ago, had an obvious answer. Show how this development just changed it.',

            // DECLARACIONES AUDACES
            'bold_counter'      => 'Open with a bold, counter-intuitive declarative statement that challenges a prevailing assumption. Defensible. Unexpected enough to stop the reader.',
            'bold_verdict'      => 'Lead with a direct, unambiguous editorial verdict: "This is not an incremental update. It is a structural shift that most coverage has missed."',
            'bold_silence'      => 'Open by naming what everyone is NOT saying about this story, then spend the article filling that gap with precision.',
            'bold_reframe'      => '"The real story is not X. It is Y." One sentence. Then prove it through the rest of the article.',
            'bold_admission'    => 'Open with the honest admission that the obvious interpretation of this news is wrong, then explain what is actually happening beneath the surface.',

            // CONTEXTO HISTORICO
            'hist_parallel'     => 'Connect this news to a pivotal historical moment in tech, not as loose metaphor but as genuine structural parallel. Then show what is different this time.',
            'hist_possible'     => '"Ten years ago, this would have been technically impossible. Here is precisely what changed in the technical and market landscape."',
            'hist_trajectory'   => 'Open with a compressed trajectory: 3-4 key milestones in 3-4 sentences that made this moment inevitable.',
            'hist_cycle'        => 'Frame this as part of a recurring industry cycle, show the pattern, then show what is different about this iteration of it.',

            // PARADOJAS Y CONTRAPOSICIONES
            'paradox_core'      => 'Open with an apparent paradox: two facts that seem to contradict each other, both of which are true. The article resolves the tension.',
            'paradox_hidden'    => '"While X was happening publicly, Y was quietly occurring beneath it." Surface the hidden dynamic.',
            'paradox_label'     => '"It is marketed as X. Under the hood, it is doing Y. That distinction matters more than the branding."',
            'paradox_timing'    => 'Open by noting the paradox of the timing: why this arrives exactly now, and what that timing reveals about the industry.',

            // IMPLICACIONES Y CONSECUENCIAS
            'conseq_second'     => 'Open with the second-order effect: not the announcement itself but what it enables three steps downstream.',
            'conseq_ripple'     => '"When X changes, Y must adapt. When Y adapts, Z becomes obsolete." Map the chain of consequence this announcement sets in motion.',
            'conseq_winners'    => 'Open with a "who benefits and who loses" framing: specific groups, specific positions, concrete not generic.',
            'conseq_enabled'    => 'Open by stating what is now possible that was categorically impossible before, and why that capability matters for the next generation.',

            // TENSION NARRATIVA
            'tension_buildup'   => 'Open with the pressure that had been building: the technical debt, market friction, or accumulated frustration that made this development inevitable.',
            'tension_problem'   => 'Name the specific problem this solves so viscerally that the reader feels the pain of the old situation before learning about the solution.',
            'tension_old_new'   => 'Contrast the old approach and the new one without using "before" and "after". Let the structural difference emerge from the comparison itself.',

            // DESMITIFICACION
            'myth_headline'     => '"The headline is technically accurate. What it implies is misleading." Define the gap between the announcement and the reality with precision.',
            'myth_circulating'  => 'Name the misconception circulating in the ecosystem about this topic, then systematically separate signal from noise with evidence.',
            'myth_hype'         => 'Acknowledge the hype honestly in one sentence, then immediately pivot to what the data actually shows.',
            'myth_definition'   => 'Open by defining what this technology actually is vs. what the marketing language suggests. The gap between those two is the story.',

            // CASOS REALES Y ESCENARIOS
            'case_team'         => 'Open by describing a specific, realistic team scenario that this news directly affects. Ground the abstraction in operational reality without inventing company names.',
            'case_decision'     => '"The next time a team sits down to architect X, this announcement changes which path they choose, and why." Explain the decision tree.',
            'case_hypothetical' => '"Imagine a team of N engineers managing X infrastructure. Before this week, their options were A and B. Now they have C." Make it feel real and consequential.',

            // URGENCIA Y VENTANAS
            'urgency_speed'     => 'Open with the specific speed of this change. "This happened faster than most predicted. The window from announcement to adoption pressure is weeks, not quarters."',
            'urgency_window'    => '"Organizations that move quickly have a concrete advantage. Those that wait six months will find the landscape has already shifted beneath them."',
            'urgency_irreversi' => 'Open by naming what becomes irreversible after a certain point: the technical debt that accumulates, the market position that solidifies.',

            // MERCADO Y COMPETENCIA
            'market_dynamics'   => 'Open with the competitive dynamics this news reshapes: specific players, specific positions, why the equilibrium has shifted.',
            'market_signal'     => '"This is not just a technical announcement. It is a pricing signal, a competitive repositioning, and a market direction all in one release note."',
            'market_calc'       => 'Open with the business calculation behind the technical decision. Understanding the economics explains both the timing and the design choices.',

            // SINTESIS DE ALTA DENSIDAD
            'synthesis_dense'   => 'Pack WHO + WHAT + WHY IT MATTERS into a single crisp opening sentence. No throat-clearing. Pure journalistic efficiency.',
            'synthesis_three'   => 'Three precise insights. Three punchy sentences. No cliche preamble. Let the density of the opening earn the reader attention.',
            'synthesis_buried'  => 'Open with the single most important insight buried in this announcement, the one most coverage will miss, then build the article around proving why it is the key signal.',

            // PROYECCION DE FUTURO
            'future_projection' => 'Open with a projection 18-24 months out if this plays as announced, then walk backwards to show how we get there from today.',
            'future_enables'    => 'Open by naming what this makes possible that was impossible before, and why that capability matters for the next generation of products or services.',
            'future_question'   => 'Open with the question the industry will be actively debating 12 months from now as a direct consequence of what is announced today.',
        ];

        // ─────────────────────────────────────────────────────────────────
        // 5 CLOSING STYLES — varied endings to prevent pattern recognition
        // ─────────────────────────────────────────────────────────────────
        $closingStyles = [
            'reader_question'       => 'End with one incisive, specific question directly to the reader that invites genuine reflection or debate. Particular to this topic, not generic. Never "In conclusion" or "En conclusion".',
            'quantified_projection' => 'End with a specific, quantified projection or threshold: name the concrete metric, date, or event that will determine success or failure, and why that number matters.',
            'aphoristic_close'      => 'End with a single dense, precise, memorable sentence that synthesizes the core argument. Original and specific. Not a cliche. Designed to be quoted.',
            'practitioner_action'   => 'End with a concrete, actionable next step for the practitioner reader: what to evaluate, test, monitor, or decide in the next 30-90 days.',
            'open_verdict'          => 'End with an honest "the jury is still out" close that names exactly what evidence or event would shift the verdict in either direction. No false neutrality.',
        ];

        // ─────────────────────────────────────────────────────────────────
        // RANDOM SELECTION (true variety across 10,000+ articles)
        // ─────────────────────────────────────────────────────────────────
        $archetypeKeys = array_keys($archetypes);
        shuffle($archetypeKeys);
        $selectedArchetypeKey = $archetypeKeys[0];
        $selectedArchetype    = $archetypes[$selectedArchetypeKey];

        $perspectiveKeys = array_keys($perspectives);
        shuffle($perspectiveKeys);
        $selectedPerspective = $perspectives[$perspectiveKeys[0]];

        $hookKeys = array_keys($openingHooks);
        shuffle($hookKeys);
        $selectedHook = $openingHooks[$hookKeys[0]];

        $closingKeys = array_keys($closingStyles);
        shuffle($closingKeys);
        $selectedClosing = $closingStyles[$closingKeys[0]];

        // Archetype-calibrated temperature: each archetype has its own tonal range
        [$tempMin, $tempMax] = $selectedArchetype['temp_range'];
        $tempRange   = (int)(($tempMax - $tempMin) * 100);
        $temperature = round($tempMin + (mt_rand(0, $tempRange) / 100), 2);

        return [
            'archetypeKey'       => $selectedArchetypeKey,
            'archetypeName'      => $selectedArchetype['name'],
            'archetypeStructure' => $selectedArchetype['structure'],
            'perspectiveVoice'   => $selectedPerspective['voice'],
            'perspectiveRule'    => $selectedPerspective['rule'],
            'openingHook'        => $selectedHook,
            'closingStyle'       => $selectedClosing,
            'imageCount'         => $selectedArchetype['image_count'],
            'temperature'        => $temperature,
        ];
    }

    private function rollImageCount(): int
    {
        $roll = mt_rand(1, 100);
        if ($roll <= 60) return 1;       // 60% — hero only
        return mt_rand(2, 3);            // 40% — 2-3 images
    }

    /**
     * Ensure raw narrative text blocks without HTML wrapper tags are properly formatted into <p>...</p> paragraphs.
     */
    protected function ensureHtmlParagraphs(string $content): string
    {
        if (empty(trim($content))) {
            return $content;
        }

        // If already well formed with <p> tags and headings
        if (substr_count($content, '<p>') >= 3) {
            return $content;
        }

        $lines = preg_split('/\n{2,}|\r\n\r\n/', $content);
        $formatted = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            // Preserve existing HTML block tags and image tokens
            if (preg_match('/^<(?:p|h[1-6]|blockquote|ul|ol|li|div|figure)[\s>]/i', $trimmed) ||
                preg_match('/^\[IMAGE_\d+\]$/i', $trimmed)) {
                $formatted[] = $trimmed;
            } else {
                // Split large blocks into natural 2-3 sentence paragraphs
                $sentences = preg_split('/(?<=[.?!])\s+(?=[A-Z¿¡"\'\d])/u', $trimmed);
                if (count($sentences) > 4) {
                    $chunks = array_chunk($sentences, 3);
                    foreach ($chunks as $chunk) {
                        $formatted[] = '<p>' . implode(' ', $chunk) . '</p>';
                    }
                } else {
                    $formatted[] = '<p>' . $trimmed . '</p>';
                }
            }
        }

        return implode("\n\n", $formatted);
    }
}