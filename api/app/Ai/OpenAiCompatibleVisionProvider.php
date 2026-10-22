<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Any OpenAI-compatible `/chat/completions` endpoint serving a vision-capable model — e.g. Gemma 4 through OpenRouter, vLLM or a
 * local Ollama (`http://localhost:11434/v1`). Request shape follows the OpenAI chat spec (text part + `image_url` data URI).
 * NOT live-verified in this repository (no endpoint/key available). The request idea comes from the teammate prototype on branch
 * feature/image-recognition (Mark Heramis); it is ported onto this app's VisionProvider/ProductExtraction types.
 *
 * `AI_OPENAI_JSON_MODE=true` adds response_format=json_object for backends that support it; the reply is parsed and validated either way.
 */
class OpenAiCompatibleVisionProvider implements VisionProvider
{
    use DecodesModelJson;

    public function analyze(string $imageBytes, string $mime, array $context = []): AnalysisResult
    {
        $cfg = config('hardwhere.ai.openai');
        $cfg['base_url'] && $cfg['model'] ?: throw new AiProviderException('AI_OPENAI_BASE_URL and AI_OPENAI_MODEL must be configured.');

        $body = [
            'model' => $cfg['model'],
            'temperature' => 0.1,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => GemmaVisionProvider::PROMPT],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($imageBytes)]],
            ]]],
        ] + ($cfg['json_mode'] ? ['response_format' => ['type' => 'json_object']] : []);

        try {
            $request = Http::timeout($cfg['timeout'])->acceptJson();
            $response = ($cfg['api_key'] ? $request->withToken($cfg['api_key']) : $request) // local servers need no key
                ->post(rtrim($cfg['base_url'], '/').'/chat/completions', $body);
        } catch (ConnectionException $e) {
            throw new AiProviderException('The AI service could not be reached.', previous: $e);
        }

        $response->successful() || throw new AiProviderException("The AI service returned HTTP {$response->status()}.");

        $content = $response->json('choices.0.message.content');
        $text = is_array($content) ? collect($content)->pluck('text')->filter()->implode("\n") : (string) $content;

        return new AnalysisResult(ProductExtraction::fromModelOutput($this->decodeJson($text)), 'openai-compatible', $cfg['model'], false, ['text' => mb_substr($text, 0, 4000)]);
    }
}
