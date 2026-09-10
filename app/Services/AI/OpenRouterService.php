<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

use App\Exceptions\OpenRouterAuthenticationException;
use App\Services\AI\OpenRouterCircuitBreaker;

class OpenRouterService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('openrouter.api_key', '');
        $this->baseUrl = config('openrouter.base_url', 'https://openrouter.ai/api/v1');
    }

    /**
     * Send a completion request to OpenRouter.
     *
     * @param array $messages
     * @param string $model
     * @param array $options
     * @return string|null
     */
    public function complete(array $messages, string $model, array $options = []): ?string
    {
        try {
            Log::info("OpenRouter Request: Model={$model}");

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'X-Title' => 'Noticias Platform', // Hardcoded local name for safety
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'), // Recommended by OpenRouter
            ])
            ->timeout(420)
            ->post($this->baseUrl . '/chat/completions', array_merge([
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.7,
                'provider' => [
                    'allow_fallbacks' => false,
                ],
            ], $options));

            if ($response->failed()) {
                Log::error("OpenRouter Error: " . $response->status() . " - " . $response->body());

                // 401 = permanent auth failure — throw to prevent useless retries
                if ($response->status() === 401) {
                    throw new OpenRouterAuthenticationException(
                        "OpenRouter authentication failed: " . $response->body(),
                        401,
                        $response->body()
                    );
                }

                return null;
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                Log::error("OpenRouter Error: Respuesta vacía o mal formada", ['data' => $data]);
                return null;
            }

            Log::info("OpenRouter Response: Length=" . strlen($content));
            OpenRouterCircuitBreaker::recordSuccess();

            return $content;
        } catch (OpenRouterAuthenticationException $e) {
            // Re-throw auth errors permanently — callers handle 401 with no retry
            throw $e;
        } catch (\Exception $e) {
            $isTimeout = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'timeout');
            Log::error("OpenRouter Error [" . ($isTimeout ? 'TIMEOUT' : 'EXCEPTION') . "]: " . $e->getMessage(), [
                'model' => $model,
                'timeout_seconds' => 300,
            ]);
            return null;
        }
    }

    /**
     * Get embeddings from OpenRouter.
     *
     * @param string $input
     * @param string $model
     * @return array|null The embedding vector (e.g. 1536 floats)
     */
    public function embeddings(string $input, string $model = 'openai/text-embedding-3-small'): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'X-Title' => 'Noticias Platform',
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'),
            ])
            ->timeout(60)
            ->post($this->baseUrl . '/embeddings', [
                'model' => $model,
                'input' => $input,
            ]);

            if ($response->failed()) {
                Log::error("OpenRouter Embeddings Error: " . $response->status() . " - " . $response->body());
                return null;
            }

            $data = $response->json();
            return $data['data'][0]['embedding'] ?? null;
        } catch (\Exception $e) {
            Log::error("OpenRouter Embeddings Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  MODELOS DISPONIBLES PARA EL PIPELINE DE REDACCIÓN
     * ═══════════════════════════════════════════════════════════════════
     *
     *  Para cambiar de modelo, edita MODEL_ACTIVE abajo.
     *  Copia el string del modelo que quieras usar.
     *
     * ═══════════════════════════════════════════════════════════════════
     */

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  MODELOS DISPONIBLES PARA EL PIPELINE DE REDACCIÓN
     * ═══════════════════════════════════════════════════════════════════
     *
     *  Para cambiar de modelo: edita MODEL_ACTIVE abajo.
     *  Copia el string del modelo que quieras usar.
     *
     *  Modelos:
     *  ─────────────────────────────────────────────────────────────────
     *  deepseek/deepseek-v4-flash-0731        Rápido, barato (default)
     *  qwen/qwen3.7-flash          Más inteligente
     *  deepseek/deepseek-v4-flash     DeepSeek V4 Flash (barato)
     *  deepseek/deepseek-v4-pro       DeepSeek V4 Pro (más potente)
     *  qwen/qwen3.6-plus              Qwen 3.6 Plus (Alibaba)
     *  minimax/minimax-m2.7           MiniMax M2.7
     *  ─────────────────────────────────────────────────────────────────
     *
     *  URLs de referencia:
     *  https://openrouter.ai/deepseek/deepseek-v4-flash-0731
     *  https://openrouter.ai/qwen/qwen3.7-flash
     *  https://openrouter.ai/deepseek/deepseek-v4-flash
     *  https://openrouter.ai/deepseek/deepseek-v4-pro
     *  https://openrouter.ai/qwen/qwen3.6-plus
     *  https://openrouter.ai/minimax/minimax-m2.7
     * ═══════════════════════════════════════════════════════════════════
     */
    // MODEL_ACTIVE removed in favor of config('ai_models.default')
}
