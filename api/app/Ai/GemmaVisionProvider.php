<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gemma 4 through the Gemini API generateContent endpoint (model ids documented by Google: gemma-4-31b-it, gemma-4-26b-a4b-it).
 * NOT live-verified in this repository: it needs GEMMA_API_KEY. Google's Gemma page documents Files-API image input and does
 * not document JSON mode, so this sends inline_data and asks for JSON in the prompt, then validates the output strictly.
 */
class GemmaVisionProvider implements VisionProvider
{
    public const PROMPT = <<<'TXT'
You identify hardware-store products (Philippines) from one photo. Reply with ONE JSON object and nothing else, using exactly these keys:
{"name": string|null, "brand": string|null, "model": string|null, "part_number": string|null, "category": string|null,
 "attributes": {snake_case_key: number|string} (e.g. voltage_v, wattage_w, diameter_mm, length_mm, size, color, material, thread_size),
 "pack_quantity": integer|null, "barcode_text": string|null (digits only, only if clearly legible),
 "confidence": {"name": 0-1, "brand": 0-1, "model": 0-1, "category": 0-1, "attributes": 0-1, "barcode": 0-1},
 "overall_confidence": 0-1, "image_sufficient": boolean, "notes": [string]}
Use null for anything you cannot read or see. Never guess a brand or model number; lower the confidence instead.
TXT;

    public function analyze(string $imageBytes, string $mime, array $context = []): AnalysisResult
    {
        $cfg = config('hardwhere.ai.gemma');
        $cfg['api_key'] ?: throw new AiProviderException('GEMMA_API_KEY is not configured.');

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $cfg['api_key']])->timeout($cfg['timeout'])->acceptJson()
                ->post(rtrim($cfg['base_url'], '/')."/models/{$cfg['model']}:generateContent", [
                    'contents' => [['role' => 'user', 'parts' => [
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($imageBytes)]],
                        ['text' => self::PROMPT],
                    ]]],
                    'generationConfig' => ['temperature' => 0.1],
                ]);
        } catch (ConnectionException $e) {
            throw new AiProviderException('The AI service could not be reached.', previous: $e);
        }

        $response->successful() || throw new AiProviderException("The AI service returned HTTP {$response->status()}.");

        $text = collect($response->json('candidates.0.content.parts', []))
            ->reject(fn ($p) => ! empty($p['thought']))->pluck('text')->filter()->implode("\n");

        return new AnalysisResult(ProductExtraction::fromModelOutput($this->decodeJson($text)), 'gemma', $cfg['model'], false, ['text' => mb_substr($text, 0, 4000)]);
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
