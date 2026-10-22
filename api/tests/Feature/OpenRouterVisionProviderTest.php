<?php

use App\Ai\AiProviderException;
use App\Ai\OpenRouterVisionProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('hardwhere.ai.openrouter', ['base_url' => 'https://openrouter.test/api/v1', 'api_key' => 'k', 'model' => 'google/gemma-4-26b-a4b-it', 'timeout' => 5]);
});

test('openrouter provider sends the image and validates the model output', function () {
    Http::fake(['openrouter.test/*' => Http::response(['choices' => [['message' => ['content' => "```json\n".json_encode([
        'name' => 'Claw Hammer', 'brand' => 'Stanley', 'model' => '51-188', 'category' => 'Hand Tools', 'attributes' => ['weight_oz' => 16],
        'confidence' => ['name' => 0.9, 'brand' => 0.9, 'model' => 0.8], 'overall_confidence' => 0.88, 'image_sufficient' => true,
    ])."\n```"]]]])]);

    $result = (new OpenRouterVisionProvider)->analyze('img-bytes', 'image/png');

    expect($result->isMock)->toBeFalse()
        ->and($result->provider)->toBe('openrouter')
        ->and($result->extraction->brand)->toBe('Stanley');

    Http::assertSent(fn ($r) => $r->url() === 'https://openrouter.test/api/v1/chat/completions'
        && $r['model'] === 'google/gemma-4-26b-a4b-it'
        && str_starts_with($r['messages'][0]['content'][1]['image_url']['url'], 'data:image/png;base64,'));
});

test('openrouter provider refuses to run without a key', function () {
    config()->set('hardwhere.ai.openrouter.api_key', null);

    expect(fn () => (new OpenRouterVisionProvider)->analyze('x', 'image/png'))->toThrow(AiProviderException::class, 'not configured');
});

test('openrouter provider fails safely on http errors and non-json output', function (string $expected, Closure $response) {
    Http::fake(['openrouter.test/*' => $response()]);

    expect(fn () => (new OpenRouterVisionProvider)->analyze('x', 'image/png'))->toThrow(AiProviderException::class, $expected);
})->with([
    'http error' => ['HTTP 500', fn () => Http::response('boom', 500)],
    'not json' => ['did not return JSON', fn () => Http::response(['choices' => [['message' => ['content' => 'it is a hammer']]]])],
]);
