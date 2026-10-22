<?php

use App\Models\Team;
use App\Models\User;
use App\Services\Vision\OpenAiCompatibleVisionProvider;
use App\Services\Vision\ProductVisionProvider;
use Database\Seeders\CatalogProductSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(CatalogProductSeeder::class);
    $this->user = User::factory()->create();
});

function analyzeImage(string $filename, ?User $user = null)
{
    return test()
        ->actingAs($user ?? test()->user)
        ->postJson(route('catalog.analyze-image'), ['image' => UploadedFile::fake()->image($filename)]);
}

test('guests cannot analyze images', function () {
    $this->postJson(route('catalog.analyze-image', ['current_team' => 'any']))->assertUnauthorized();
});

test('users outside the team cannot analyze images', function () {
    $team = Team::factory()->create();

    $this->actingAs($this->user)
        ->postJson(route('catalog.analyze-image', ['current_team' => $team->slug]), [
            'image' => UploadedFile::fake()->image('drill.jpg'),
        ])
        ->assertForbidden();
});

test('same product with matching model is a likely same product needing confirmation', function () {
    analyzeImage('drill.jpg')
        ->assertOk()
        ->assertJsonPath('analysis.is_mock', true)
        ->assertJsonPath('classification', 'likely_same_product')
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('candidates.0.product.model_number', 'DF333D')
        ->assertJsonPath('candidates.0.matched_fields', ['brand', 'model_number', 'category', 'name', 'attributes']);
});

test('different voltage is flagged as a variant and never merged', function () {
    analyzeImage('drill-220.jpg')
        ->assertOk()
        ->assertJsonPath('classification', 'likely_variant')
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('candidates.0.warnings', ['Similar product with different voltage', 'Possible product variant']);
});

test('a matching barcode is an exact match', function () {
    analyzeImage('barcode.jpg')
        ->assertOk()
        ->assertJsonPath('classification', 'exact_match')
        ->assertJsonPath('requires_confirmation', false)
        ->assertJsonPath('candidates.0.product.barcode', '3253561510130');
});

test('unbranded fasteners are only a possible duplicate', function () {
    analyzeImage('nails.jpg')
        ->assertOk()
        ->assertJsonPath('classification', 'possible_duplicate')
        ->assertJsonPath('candidates.0.product.name', 'Common Wire Nails 2 inch');
});

test('an unreadable image yields insufficient confidence', function () {
    analyzeImage('blurry.jpg')
        ->assertOk()
        ->assertJsonPath('classification', 'insufficient_confidence')
        ->assertJsonPath('warnings.0', 'Image insufficient for reliable identification');
});

test('invalid uploads are rejected', function (array $payload) {
    $this->actingAs($this->user)
        ->postJson(route('catalog.analyze-image'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
})->with([
    'missing' => [[]],
    'not an image' => fn () => [['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]],
    'too large' => fn () => [['image' => UploadedFile::fake()->image('big.jpg')->size(6000)]],
]);

test('openai-compatible provider parses a model response', function () {
    config()->set('services.vision', [
        'driver' => 'openai', 'base_url' => 'https://vision.test/v1', 'api_key' => 'k', 'model' => 'gemma-4', 'timeout' => 5,
    ]);

    Http::fake(['vision.test/*' => Http::response(['choices' => [['message' => ['content' => "```json\n".json_encode([
        'name' => 'Claw Hammer 16oz', 'brand' => 'Stanley', 'barcode' => '3253 5615 10130',
        'attributes' => ['weight' => '16oz'], 'confidences' => ['name' => 2, 'brand' => 0.9],
    ])."\n```"]]]])]);

    expect(app(ProductVisionProvider::class))->toBeInstanceOf(OpenAiCompatibleVisionProvider::class);

    analyzeImage('hammer.jpg')
        ->assertOk()
        ->assertJsonPath('analysis.is_mock', false)
        ->assertJsonPath('analysis.provider', 'openai-compatible:gemma-4')
        ->assertJsonPath('analysis.barcode', '3253561510130')
        ->assertJsonPath('analysis.confidences.name', 1)
        ->assertJsonPath('classification', 'exact_match');

    Http::assertSent(fn ($request) => $request->url() === 'https://vision.test/v1/chat/completions'
        && $request['model'] === 'gemma-4'
        && str_starts_with($request['messages'][0]['content'][1]['image_url']['url'], 'data:image/'));
});

test('provider failure returns 503 and non-json output is rejected', function (mixed $response) {
    config()->set('services.vision', [
        'driver' => 'openai', 'base_url' => 'https://vision.test/v1', 'api_key' => 'k', 'model' => 'gemma-4', 'timeout' => 5,
    ]);
    Http::fake(['vision.test/*' => $response]);

    analyzeImage('hammer.jpg')->assertStatus(503)->assertJsonStructure(['message']);
})->with([
    'http error' => fn () => Http::response('boom', 500),
    'garbage' => fn () => Http::response(['choices' => [['message' => ['content' => 'I think it is a hammer']]]]),
]);
