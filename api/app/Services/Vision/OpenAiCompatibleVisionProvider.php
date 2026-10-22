<?php

namespace App\Services\Vision;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Calls any OpenAI-compatible /chat/completions endpoint (vLLM, OpenRouter, ...)
 * serving a vision-capable Gemma model. Request shape follows the OpenAI chat
 * spec; not yet verified against a live endpoint.
 */
class OpenAiCompatibleVisionProvider implements ProductVisionProvider
{
    private const PROMPT = <<<'TXT'
You identify hardware-store products from a photo. Respond with ONE JSON object and nothing else:
{"name":string|null,"brand":string|null,"model_number":string|null,"category":string|null,
"barcode":string|null (digits only, only if printed and legible),"packaging":string|null,
"attributes":{"voltage":..,"wattage":..,"pack_quantity":..,"color":..,"size":..,"length":..,"diameter":..},
"confidences":{"name":0..1,"brand":0..1,"model_number":0..1},
"image_sufficient":boolean}
Use null for anything you cannot read. Never guess a brand or model number.
TXT;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function analyze(string $imageBytes, string $mimeType, ?string $filename = null): ProductAnalysis
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => self::PROMPT],
                            ['type' => 'image_url', 'image_url' => [
                                'url' => 'data:'.$mimeType.';base64,'.base64_encode($imageBytes),
                            ]],
                        ],
                    ]],
                ]);
        } catch (ConnectionException $e) {
            throw new VisionProviderException('Vision provider unreachable.', previous: $e);
        }

        if ($response->failed()) {
            throw new VisionProviderException('Vision provider returned HTTP '.$response->status().'.');
        }

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($this->stripCodeFence($content), true) : null;

        if (! is_array($decoded)) {
            throw new VisionProviderException('Vision provider returned non-JSON output.');
        }

        try {
            return ProductAnalysis::fromProviderArray($decoded, 'openai-compatible:'.$this->model, false);
        } catch (InvalidArgumentException $e) {
            throw new VisionProviderException('Vision provider output failed validation: '.$e->getMessage(), previous: $e);
        }
    }

    private function stripCodeFence(string $content): string
    {
        return trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)));
    }
}
