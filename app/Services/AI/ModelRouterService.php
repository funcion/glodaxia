<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

class ModelRouterService
{
    protected OpenRouterService $openRouter;

    public function __construct(OpenRouterService $openRouter)
    {
        $this->openRouter = $openRouter;
    }

    /**
     * Get the list of active models configured in the pool in exact priority order.
     *
     * @return array<string>
     */
    public function getPool(): array
    {
        $pool = config('ai_models.pool', []);
        $default = config('ai_models.default');

        if (empty($pool) && empty($default)) {
            throw new \RuntimeException("No AI models pool configured. Please define AI_MODELS_POOL or AI_DEFAULT_MODEL in config/ai_models.php.");
        }

        if (empty($pool)) {
            return [$default];
        }

        if ($default && in_array($default, $pool)) {
            $pool = array_values(array_unique(array_merge([$default], $pool)));
        }

        return array_values(array_unique($pool));
    }

    /**
     * Select the next highest-priority model from the pool, excluding tried/failed ones.
     *
     * @param array<string> $exclude
     * @return string|null
     */
    public function selectModel(array $exclude = []): ?string
    {
        $available = array_values(array_diff($this->getPool(), $exclude));

        if (empty($available)) {
            return null;
        }

        return $available[array_rand($available)];
    }

    /**
     * Execute a chat completion with strict top-down priority failover chain.
     *
     * @param array $messages
     * @param array $options
     * @param string|null $preferredModel
     * @return array|null ['content' => string, 'model_used' => string, 'attempts' => array]
     */
    public function completeWithFailover(array $messages, array $options = [], ?string $preferredModel = null): ?array
    {
        $pool = $this->getPool();
        $tried = [];
        $attempts = [];

        // Determine default safe token limit (10,000 for full bilingual articles)
        $defaultMaxTokens = config('ai_models.max_tokens', 10000);
        $mergedOptions = array_merge([
            'max_tokens'  => $defaultMaxTokens,
            'temperature' => config('ai_models.temperature', 0.7),
        ], $options);

        // If a preferred model is explicitly requested, try it first, otherwise start with #1 priority model
        $nextModel = ($preferredModel && in_array($preferredModel, $pool)) 
            ? $preferredModel 
            : $this->selectModel();

        while ($nextModel !== null) {
            $tried[] = $nextModel;
            Log::info("ModelRouter: Attempting completion with priority model [{$nextModel}] (max_tokens: {$mergedOptions['max_tokens']})");

            $startTime = microtime(true);
            $content = $this->openRouter->complete($messages, $nextModel, $mergedOptions);
            $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);

            $attempts[] = [
                'model'      => $nextModel,
                'duration'   => $elapsedMs,
                'successful' => !empty($content),
            ];

            if (!empty($content)) {
                Log::info("ModelRouter: Completed successfully with priority model [{$nextModel}] in {$elapsedMs}ms.");
                return [
                    'content'    => $content,
                    'model_used' => $nextModel,
                    'attempts'   => $attempts,
                ];
            }

            // Fall over to the next priority model in the chain
            $fallbackModel = $this->selectModel($tried);
            if ($fallbackModel) {
                Log::warning("ModelRouter: Model [{$nextModel}] failed/timed out. Failing over to next priority model [{$fallbackModel}]...");
            } else {
                Log::error("ModelRouter: All models in OpenRouter pool exhausted without success. Tried: " . implode(', ', $tried));
            }

            $nextModel = $fallbackModel;
        }

        return null;
    }

    /**
     * Fast classification helper with reduced token limits.
     *
     * @param array $messages
     * @param array $options
     * @return array|null
     */
    public function classifyWithFailover(array $messages, array $options = []): ?array
    {
        $classMaxTokens = config('ai_models.classification_max_tokens', 1500);
        $options['max_tokens'] = $classMaxTokens;

        return $this->completeWithFailover($messages, $options);
    }
}
