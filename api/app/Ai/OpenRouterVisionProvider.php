<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gemma 4 (or any vision model) through OpenRouter's OpenAI-compatible /chat/completions endpoint.
 * Reuses the Gemma prompt and strict output validation; only the transport differs from GemmaVisionProvider.
 */
class OpenRouterVisionProvider implements VisionProvider
{
    public function analyze(string $imageBytes, string $mime, array $context = []): AnalysisResult
    {
        $cfg = config('hardwhere.ai.openrouter');
        $cfg['api_key'] ?: throw new AiProviderException('OPENROUTER_API_KEY is not configured.');

        try {
            $response = Http::withToken($cfg['api_key'])->timeout($cfg['timeout'])->acceptJson()
                ->withHeaders(['X-Title' => 'Hardwhere'])
                ->post(rtrim($cfg['base_url'], '/').'/chat/completions', [
                    'model' => $cfg['model'],
                    'temperature' => 0.1,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => GemmaVisionProvider::PROMPT],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($imageBytes)]],
                        ],
                    ]],
                ]);
        } catch (ConnectionException $e) {
            throw new AiProviderException('The AI service could not be reached.', previous: $e);
        }

        $response->successful() || throw new AiProviderException("The AI service returned HTTP {$response->status()}.");

        $text = (string) $response->json('choices.0.message.content', '');

        return new AnalysisResult(ProductExtraction::fromModelOutput($this->decodeJson($text)), 'openrouter', $cfg['model'], false, ['text' => mb_substr($text, 0, 4000)]);
    }

    private function decodeJson(string $text): mixed
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        if (($start = strpos($text, '{')) === false || ($end = strrpos($text, '}')) === false) {
            throw new AiProviderException('The model did not return JSON.');
        }

        return json_decode(substr($text, $start, $end - $start + 1), true) ?? throw new AiProviderException('The model returned malformed JSON.');
    }
}
