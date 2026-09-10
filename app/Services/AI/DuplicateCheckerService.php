<?php

namespace App\Services\AI;

use App\Models\Article;
use App\Models\ArticleUpdate;
use App\Models\RawArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DuplicateCheckerService
{
    public function __construct(
        protected OpenRouterService $ai
    ) {}

    /**
     * Comprehensive multi-tier duplicate check:
     * - Level 1: URL / Canonical matching
     * - Level 2: Canonical Event Slug matching (within 36h)
     * - Level 2.5: Title normalization and Fuzzy/Lexical similarity (> 75%)
     * - Level 3: Category-partitioned Semantic pgvector embedding (< 0.18 exact, 0.18-0.35 LLM Judge)
     *
     * Returns true if a duplicate is found (and handled), false if it is genuinely new.
     */
    public function checkAndHandleDuplicate(
        string $title,
        string $content,
        string $url,
        int $rawArticleId,
        ?int $categoryId = null,
        ?string $canonicalEventSlug = null,
        ?string $summary = null
    ): bool {
        // Sanitize string encodings to guarantee only valid UTF-8
        $title   = mb_convert_encoding(trim($title), 'UTF-8', 'UTF-8');
        $content = mb_convert_encoding(trim($content), 'UTF-8', 'UTF-8');
        $cleanTitle = $this->normalizeTitle($title);

        Log::info("Running Anti-Duplicate Check for RawArticle #{$rawArticleId}: '{$title}' (Category: " . ($categoryId ?? 'None') . ", Slug: " . ($canonicalEventSlug ?? 'None') . ")");

        // ═══════════════════════════════════════════════════════════════════════
        // LEVEL 1: Exact URL / Canonical URL Check
        // ═══════════════════════════════════════════════════════════════════════
        $normalizedUrl = $this->normalizeUrl($url);
        
        $existingUpdate = ArticleUpdate::where('source_url', $url)
            ->orWhere('source_url', $normalizedUrl)
            ->first();

        if ($existingUpdate && $existingUpdate->article) {
            Log::info("Level 1 Duplicate: URL already registered in ArticleUpdate for Article ID {$existingUpdate->article_id}");
            RawArticle::where('id', $rawArticleId)->update(['status' => 'ignored']);
            return true;
        }

        $existingRaw = RawArticle::where('id', '!=', $rawArticleId)
            ->whereIn('status', ['processed', 'ignored'])
            ->where(function ($q) use ($url, $normalizedUrl) {
                $q->where('url', $url)
                  ->orWhere('url', $normalizedUrl);
            })
            ->first();

        if ($existingRaw) {
            Log::info("Level 1 Duplicate: URL already processed in RawArticle ID {$existingRaw->id}");
            RawArticle::where('id', $rawArticleId)->update(['status' => 'ignored']);
            return true;
        }

        // ═══════════════════════════════════════════════════════════════════════
        // LEVEL 2: Canonical Event Slug Check (Last 36h)
        // ═══════════════════════════════════════════════════════════════════════
        if (!empty($canonicalEventSlug)) {
            $eventMatch = Article::where('status', 'published')
                ->where('created_at', '>=', now()->subHours(36))
                ->whereRaw("ai_metadata->>'event_slug_canonical' = ?", [$canonicalEventSlug])
                ->first();

            if ($eventMatch) {
                Log::info("Level 2 Canonical Event Match! Article ID {$eventMatch->id} shares event slug '{$canonicalEventSlug}'. Consolidating as update.");
                $this->createUpdateEntry($eventMatch, $url, $rawArticleId);
                return true;
            }
        }

        // ═══════════════════════════════════════════════════════════════════════
        // LEVEL 2.5: Fuzzy/Lexical Title Matching (Last 30 days)
        // ═══════════════════════════════════════════════════════════════════════
        $recentArticles = Article::where('status', 'published')
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['id', 'title', 'created_at']);

        foreach ($recentArticles as $existingArt) {
            $existingTitleEn = $this->normalizeTitle($existingArt->getTranslation('title', 'en') ?? '');
            $existingTitleEs = $this->normalizeTitle($existingArt->getTranslation('title', 'es') ?? '');

            if ($this->isTitleFuzzyMatch($cleanTitle, $existingTitleEn) || $this->isTitleFuzzyMatch($cleanTitle, $existingTitleEs)) {
                Log::info("Level 2.5 Fuzzy Duplicate found (>75% similarity): Article ID {$existingArt->id} ('{$existingTitleEn}')");
                $this->createUpdateEntry($existingArt, $url, $rawArticleId);
                return true;
            }
        }

        // In-Queue Deduplication against pending raw articles
        $pendingDuplicate = RawArticle::where('id', '<', $rawArticleId)
            ->where('status', 'pending')
            ->where(function ($q) use ($cleanTitle) {
                $q->whereRaw("LOWER(title) ILIKE ?", ["%{$cleanTitle}%"])
                  ->orWhere('title', 'ILIKE', '%' . mb_substr($cleanTitle, 0, 40, 'UTF-8') . '%');
            })
            ->first();

        if ($pendingDuplicate) {
            Log::info("Level 2.5 In-Queue Duplicate: RawArticle #{$rawArticleId} duplicate of pending #{$pendingDuplicate->id}");
            RawArticle::where('id', $rawArticleId)->update(['status' => 'ignored']);
            return true;
        }

        // ═══════════════════════════════════════════════════════════════════════
        // LEVEL 3: Category-Partitioned Semantic pgvector Embedding Similarity
        // ═══════════════════════════════════════════════════════════════════════
        $textToEmbed = mb_substr($title . ". " . strip_tags($summary ?? $content), 0, 1000, 'UTF-8');
        $embedding = $this->ai->embeddings($textToEmbed);

        if (!$embedding) {
            Log::warning("Could not generate embedding for duplicate checking. Passing content through.");
            return false;
        }

        $vectorString = '[' . implode(',', $embedding) . ']';

        // Query pgvector strictly partitioned within the SAME category (or global if category null) in the last 48 hours
        $query = Article::select('id', 'title', 'excerpt', 'embedding', 'created_at')
            ->where('status', 'published')
            ->whereNotNull('embedding')
            ->where('created_at', '>=', now()->subHours(48));

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $similarArticle = $query->orderByRaw("embedding <=> ?::vector", [$vectorString])->first();

        if ($similarArticle) {
            $distanceResult = DB::selectOne(
                "SELECT (embedding <=> ?::vector) as distance FROM articles WHERE id = ?",
                [$vectorString, $similarArticle->id]
            );

            $distance = (float) ($distanceResult->distance ?? 1.0);
            Log::info("Level 3 Semantic check: Closest Article ID {$similarArticle->id} in category {$categoryId} has cosine distance {$distance}");

            // 1. Exact Semantic Duplicate (< 0.18)
            if ($distance < 0.18) {
                Log::info("Level 3 Exact Duplicate: Cosine Distance {$distance} < 0.18 with Article ID {$similarArticle->id}. Consolidating.");
                $this->createUpdateEntry($similarArticle, $url, $rawArticleId);
                return true;
            }

            // 2. Grey Zone (0.18 - 0.35) -> LLM-as-a-Judge Evaluation
            if ($distance >= 0.18 && $distance <= 0.35) {
                Log::info("Level 3 Grey Zone ({$distance}): Invoking LLM Judge for disambiguation between Article #{$similarArticle->id} and RawArticle #{$rawArticleId}...");
                $verdict = $this->evaluateWithLLMJudge($similarArticle, $title, $summary ?? mb_substr(strip_tags($content), 0, 400, 'UTF-8'));
                
                Log::info("LLM Judge Verdict: {$verdict} for RawArticle #{$rawArticleId}");

                if ($verdict === 'FUSIONAR') {
                    $this->createUpdateEntry($similarArticle, $url, $rawArticleId);
                    return true;
                } elseif ($verdict === 'DESCARTAR') {
                    RawArticle::where('id', $rawArticleId)->update(['status' => 'ignored']);
                    return true;
                }
                // If 'PUBLICAR', pass through as a distinct new angle/editorial piece
            }
        }

        return false;
    }

    /**
     * Ultra-fast, lightweight LLM-as-a-Judge to disambiguate grey-zone story pairs.
     */
    protected function evaluateWithLLMJudge(Article $candidateArticle, string $newTitle, string $newSummary): string
    {
        $existingTitle = $candidateArticle->getTranslation('title', 'es') ?: $candidateArticle->getTranslation('title', 'en');
        $existingExcerpt = $candidateArticle->getTranslation('excerpt', 'es') ?: $candidateArticle->getTranslation('excerpt', 'en') ?: mb_substr(strip_tags($candidateArticle->getTranslation('content', 'es') ?? ''), 0, 300);

        $prompt = <<<JUDGE_PROMPT
Eres un juez editorial de precision para un medio tecnologico.
Determina la relacion entre un articulo ya publicado (A) y una nueva noticia entrante (B):

ARTICULO A (Ya publicado):
- Titulo: {$existingTitle}
- Resumen: {$existingExcerpt}

NOTICIA B (Entrante):
- Titulo: {$newTitle}
- Resumen: {$newSummary}

INSTRUCCIONES:
1. Responde 'FUSIONAR' si B cuenta exactamente el mismo hecho/suceso noticioso que A (misma noticia de diferente fuente).
2. Responde 'DESCARTAR' si B es un duplicado menor, irrelevante o un eco sin ningun dato nuevo.
3. Responde 'PUBLICAR' si B trata sobre un hecho distinto, un producto diferente o aporta un angulo de analisis tecnico/financiero radicalmente nuevo que amerita su propio articulo independiente.

Responde UNICAMENTE con una palabra: FUSIONAR, DESCARTAR o PUBLICAR.
JUDGE_PROMPT;

        try {
            $response = $this->ai->complete(
                [['role' => 'user', 'content' => $prompt]],
                config('ai_models.default'),
                [
                    'temperature' => 0.0,
                    'max_tokens'  => 10,
                ]
            );

            $cleanVerdict = strtoupper(trim(preg_replace('/[^A-Z]/', '', $response ?? 'PUBLICAR')));
            if (in_array($cleanVerdict, ['FUSIONAR', 'DESCARTAR', 'PUBLICAR'])) {
                return $cleanVerdict;
            }
        } catch (\Throwable $e) {
            Log::warning("LLM Judge evaluation failed: " . $e->getMessage() . ". Defaulting to PUBLICAR.");
        }

        return 'PUBLICAR';
    }

    /**
     * Clean and normalize title by stripping media outlets branding suffixes and punctuation.
     */
    protected function normalizeTitle(string $title): string
    {
        $cleaned = preg_replace('/\s*[-|–—]\s*(TechCrunch|The Verge|Wired|Hacker News|Reuters|Bloomberg|CNBC|VentureBeat|Ars Technica|Engadget|Gizmodo|ZDNet|The Information|CoinDesk|Decrypt|Cointelegraph|The Register|9to5Mac|9to5Google|Android Police|The Next Web).*$/iu', '', $title);
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $cleaned ?? $title);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);

        return trim($cleaned);
    }

    /**
     * Compare two titles using Token Overlap (Jaccard) and similar_text.
     */
    protected function isTitleFuzzyMatch(string $title1, string $title2): bool
    {
        if (empty($title1) || empty($title2)) {
            return false;
        }

        $t1 = mb_strtolower(trim($title1), 'UTF-8');
        $t2 = mb_strtolower(trim($title2), 'UTF-8');

        if ($t1 === $t2) {
            return true;
        }

        similar_text($t1, $t2, $percent);
        if ($percent >= 78.0) {
            return true;
        }

        $words1 = array_filter(explode(' ', $t1), fn($w) => mb_strlen($w, 'UTF-8') >= 4);
        $words2 = array_filter(explode(' ', $t2), fn($w) => mb_strlen($w, 'UTF-8') >= 4);

        if (count($words1) >= 3 && count($words2) >= 3) {
            $intersection = count(array_intersect($words1, $words2));
            $union = count(array_unique(array_merge($words1, $words2)));
            $jaccard = $union > 0 ? ($intersection / $union) : 0;

            if ($jaccard >= 0.70) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize URL (strip query parameters and trailing slashes).
     */
    protected function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return $url;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = strtolower($parsed['host']);
        $path = rtrim($parsed['path'] ?? '', '/');

        return "{$scheme}://{$host}{$path}";
    }

    /**
     * Attach this new raw source as an update to an existing article (Immutable Core + 6h Window).
     */
    protected function createUpdateEntry(Article $article, string $url, int $rawArticleId): void
    {
        $raw = RawArticle::find($rawArticleId);
        $title = $raw ? $raw->title : 'Update';
        $summary = $raw ? ($raw->summary ?? mb_substr(strip_tags($raw->content ?? ''), 0, 200, 'UTF-8')) : 'Update from source';

        ArticleUpdate::firstOrCreate([
            'article_id' => $article->id,
            'source_url' => $url,
        ], [
            'title' => $title,
            'content' => $summary,
            'summary' => $summary,
            'published_at' => now(),
        ]);

        if ($raw) {
            $raw->update(['status' => 'processed']);
        }

        // Consolidate secondary source into article ai_metadata for Google E-E-A-T authority
        $metadata = is_array($article->ai_metadata) ? $article->ai_metadata : (json_decode($article->ai_metadata ?? '[]', true) ?: []);
        $sources = $metadata['consolidated_sources'] ?? [];
        $parsedHost = parse_url($url, PHP_URL_HOST);
        $sourceDomain = $parsedHost ? preg_replace('/^www\./', '', $parsedHost) : 'Source';

        $sourceExists = false;
        foreach ($sources as $src) {
            if (isset($src['url']) && $this->normalizeUrl($src['url']) === $this->normalizeUrl($url)) {
                $sourceExists = true;
                break;
            }
        }

        if (!$sourceExists) {
            $sources[] = [
                'name'         => $sourceDomain,
                'url'          => $url,
                'title'        => $title,
                'connected_at' => now()->toIso8601String(),
            ];
            $metadata['consolidated_sources'] = $sources;
            $article->ai_metadata = $metadata;
            $article->save();
        }

        // Touch updated_at if the article is within the 6-hour breaking news window
        if ($article->created_at && $article->created_at->greaterThan(now()->subHours(6))) {
            $article->touch();
        }
        
        Log::info("Attached RawArticle #{$rawArticleId} as consolidated source update to Article ID {$article->id} (Total sources: " . count($sources) . ").");
    }

    /**
     * Store the embedding on the newly created Article for future comparisons.
     */
    public function generateAndStoreEmbedding(Article $article, string $content): void
    {
        $title = $article->getTranslation('title', 'en') ?? '';
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $textToEmbed = mb_substr($title . ". " . strip_tags($content), 0, 1000, 'UTF-8');
        $embedding = $this->ai->embeddings($textToEmbed);

        if ($embedding) {
            $article->update(['embedding' => $embedding]);
            Log::info("Embedding stored for Article {$article->id}");
        }
    }
}
