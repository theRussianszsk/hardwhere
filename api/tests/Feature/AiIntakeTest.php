<?php

use App\Ai\AiProviderException;
use App\Ai\GemmaVisionProvider;
use App\Ai\MockVisionProvider;
use App\Ai\OpenAiCompatibleVisionProvider;
use App\Ai\ProductExtraction;
use App\Ai\VisionProvider;
use App\Enums\TenantRole;
use App\Models\AiAnalysis;
use App\Models\AiDecision;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\StockLedgerEntry;
use App\Models\TenantListing;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\World;

beforeEach(function () {
    $this->refs = World::catalog();
    $this->w = World::tenant('alpha');
    World::member($this->w['tenant'], TenantRole::Staff, 'staff@alpha.test');
    $this->h = World::login($this, 'staff@alpha.test', 'vendor', 'alpha');
    $this->photo = fn () => UploadedFile::fake()->image('p.jpg', 640, 480);
    $this->analyze = fn (?string $hint = 'makita-hp1630', ?UploadedFile $f = null, string $key = '') => $this->post('/api/v1/vendor/ai/analyses', ['image' => $f ?? ($this->photo)(), 'hint' => $hint], $this->h + idem($key) + ['Accept' => 'application/json']);
    $this->inventory = fn (array $x = []) => ['tenant_sku' => 'ALP-1', 'cost_cents' => 360000, 'price_cents' => 449900, 'quantity' => 3, 'reorder_threshold' => 1, 'store_id' => $this->w['store']->id] + $x;
});

it('returns labelled, explainable demo results: confidence, matched fields, candidates and warnings', function () {
    $r = ($this->analyze)('ambiguous-cordless-drill')->assertCreated();
    expect($r->json('data.is_mock'))->toBeTrue()->and($r->json('data.provider_label'))->toContain('not live inference')
        ->and($r->json('data.overall_confidence'))->toBe(0.62)->and($r->json('data.warnings'))->toContain('Low-confidence brand', 'Low-confidence model')
        ->and($r->json('data.extracted.confidence.brand'))->toBe(0.45)->and($r->json('data.requires_confirmation'))->toBeTrue();
    $c = collect($r->json('data.candidates'));
    expect($c->pluck('classification')->all())->toContain('likely_same_product', 'likely_variant')
        ->and($c->firstWhere('classification', 'likely_variant')['warnings'])->toContain('Similar product with different voltage')
        ->and($c[0])->toHaveKeys(['score', 'matched_fields', 'mismatched_fields', 'warnings', 'product', 'variant']);
});

it('does not create inventory from analysis alone', function () {
    ($this->analyze)('makita-hp1630')->assertCreated();
    expect(InventoryItem::query()->forTenant($this->w['ctx'])->count())->toBe(0)->and(StockLedgerEntry::query()->forTenant($this->w['ctx'])->count())->toBe(0);
});

it('reports a clear message when the image is insufficient and offers manual entry', function () {
    $r = ($this->analyze)('blurry')->assertCreated();
    expect($r->json('data.classification'))->toBe('insufficient_confidence')->and($r->json('data.warnings'))->toContain('Image insufficient for reliable identification');
});

it('persists the human decision next to the AI suggestion and original tenant input, and creates stock', function () {
    $a = ($this->analyze)('makita-hp1630')->json('data');
    $variant = $a['candidates'][0]['variant']['id'];
    $r = $this->postJson("/api/v1/vendor/ai/analyses/{$a['id']}/confirm", ['decision' => 'confirmed_existing', 'variant_id' => $variant, 'inventory' => ($this->inventory)()], $this->h + idem('c1'))->assertCreated();

    $d = AiDecision::query()->sole();
    expect($d->tenant_input['inventory']['tenant_sku'])->toBe('ALP-1')->and($d->ai_suggestion['extracted']['model'])->toBe('HP1630')->and($d->ai_suggestion['candidates'])->not->toBeEmpty()
        ->and($d->decision)->toBe('confirmed_existing')->and($d->user_id)->toBe(User::where('email', 'staff@alpha.test')->value('id'));
    $item = InventoryItem::query()->forTenant($this->w['ctx'])->sole();
    expect($item->variant_id)->toBe($variant)->and($item->mapping->source)->toBe('ai_intake')->and($item->mapping->ai_analysis_id)->toBe($a['id'])->and((float) $item->mapping->confidence)->toBeGreaterThan(0.8);
    $ledger = StockLedgerEntry::query()->forTenant($this->w['ctx'])->sole();
    expect([$ledger->movement_type, $ledger->quantity_delta])->toBe(['opening_balance', 3]);
    // The listing starts as an unpublished draft: nothing goes public by itself.
    expect(TenantListing::query()->forTenant($this->w['ctx'])->sole()->publication_status)->toBe('draft');
});

it('reviews each analysis once and replays a retried confirmation', function () {
    $a = ($this->analyze)('makita-hp1630')->json('data');
    $body = ['decision' => 'selected_variant', 'variant_id' => $a['candidates'][0]['variant']['id'], 'inventory' => ($this->inventory)()];
    $first = $this->postJson("/api/v1/vendor/ai/analyses/{$a['id']}/confirm", $body, $this->h + idem('same-key'))->assertCreated();
    $this->postJson("/api/v1/vendor/ai/analyses/{$a['id']}/confirm", $body, $this->h + idem('same-key'))->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
    $this->postJson("/api/v1/vendor/ai/analyses/{$a['id']}/confirm", $body, $this->h + idem('new-key-1'))->assertStatus(409)->assertJsonPath('error.code', 'already_decided');
    expect(InventoryItem::query()->forTenant($this->w['ctx'])->count())->toBe(1)->and(StockLedgerEntry::query()->forTenant($this->w['ctx'])->count())->toBe(1);
});

it('never silently merges: an already-stocked variant or SKU is a conflict, and existing data is untouched', function () {
    $first = ($this->analyze)('makita-hp1630')->json('data');
    $v = $first['candidates'][0]['variant']['id'];
    $this->postJson("/api/v1/vendor/ai/analyses/{$first['id']}/confirm", ['decision' => 'confirmed_existing', 'variant_id' => $v, 'inventory' => ($this->inventory)()], $this->h + idem('m1'))->assertCreated();

    $second = ($this->analyze)('makita-hp1630', null, 'second')->json('data');
    $this->postJson("/api/v1/vendor/ai/analyses/{$second['id']}/confirm", ['decision' => 'confirmed_existing', 'variant_id' => $v, 'inventory' => ($this->inventory)(['tenant_sku' => 'ALP-2', 'quantity' => 99])], $this->h + idem('m2'))
        ->assertStatus(409)->assertJsonPath('error.code', 'already_stocked');
    $kit = $second['candidates'][1]['variant']['id'] ?? $this->refs['HP1630K'];
    $this->postJson("/api/v1/vendor/ai/analyses/{$second['id']}/confirm", ['decision' => 'selected_variant', 'variant_id' => $kit, 'inventory' => ($this->inventory)()], $this->h + idem('m3'))
        ->assertStatus(409)->assertJsonPath('error.code', 'sku_taken');
    $item = InventoryItem::query()->forTenant($this->w['ctx'])->sole();
    expect($item->tenant_sku)->toBe('ALP-1')->and($item->snapshots->sum('on_hand'))->toBe(3)->and(AiDecision::query()->count())->toBe(1);
});

it('creates a private catalog candidate that is invisible to customers until a platform admin approves it', function () {
    $a = ($this->analyze)('unknown-product')->json('data');
    expect($a['classification'])->toBe('new_product_candidate');
    $cat = Category::where('slug', 'building-supplies')->value('id');
    $r = $this->postJson("/api/v1/vendor/ai/analyses/{$a['id']}/confirm", ['decision' => 'created_candidate',
        'new_product' => ['category_id' => $cat, 'name' => 'Expanding foam sealant 750ml', 'brand' => 'Kingfoam', 'model_number' => 'KF-750', 'attributes' => ['capacity_ml' => 750],
            'variants' => [['name' => 'Kingfoam KF-750 750ml', 'model_number' => 'KF-750', 'attributes' => ['capacity_ml' => 750], 'barcodes' => ['4806001234567' => 'EAN13']]]],
        'inventory' => ($this->inventory)(['tenant_sku' => 'FOAM-1'])], $this->h + idem('cand-1'))->assertCreated();

    $product = Product::query()->where('model_number', 'KF-750')->sole();
    expect($product->status)->toBe('candidate')->and($product->submitted_by_tenant_id)->toBe($this->w['tenant']->id)->and($product->variants)->toHaveCount(1);
    $this->getJson("/api/v1/public/products/{$product->id}")->assertNotFound();
    expect(json_encode($this->getJson('/api/v1/public/search?q=foam')->json()))->not->toContain('Kingfoam');
    $this->getJson("/api/v1/vendor/catalog/products/{$product->id}", $this->h)->assertOk();
    $other = World::tenant('beta');
    $this->getJson("/api/v1/vendor/catalog/products/{$product->id}", World::login($this, 'owner@beta.test', 'vendor', 'beta'))->assertNotFound();

    User::query()->forceCreate(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => bcrypt('password'), 'is_platform_admin' => true]);
    $admin = World::login($this, 'admin@test.test', 'admin');
    $this->postJson("/api/v1/admin/catalog/candidates/{$product->id}/approve", [], $admin)->assertOk()->assertJsonPath('data.status', 'approved');
    $this->getJson("/api/v1/public/products/{$product->id}")->assertOk();
});

it('rejects duplicate barcodes when proposing a candidate', function () {
    $cat = Category::first()->id;
    $this->postJson('/api/v1/vendor/catalog/candidates', ['category_id' => $cat, 'name' => 'Clone', 'variants' => [['name' => 'Clone', 'barcodes' => ['4800000100017' => 'EAN13']]]], $this->h)
        ->assertStatus(409)->assertJsonPath('error.code', 'barcode_conflict');
});

it('lets a reviewer reject the suggestion without stocking anything', function () {
    $a = ($this->analyze)('blurry')->json('data');
    $this->postJson("/api/v1/vendor/ai/analyses/{$a['id']}/confirm", ['decision' => 'rejected'], $this->h + idem('rej-1'))->assertCreated()->assertJsonPath('data.decision', 'rejected');
    expect(InventoryItem::query()->forTenant($this->w['ctx'])->count())->toBe(0)->and(AiDecision::query()->sole()->inventory_item_id)->toBeNull();
});

it('keeps analyses private to their tenant', function () {
    $a = ($this->analyze)('makita-hp1630')->json('data');
    World::tenant('beta');
    $b = World::login($this, 'owner@beta.test', 'vendor', 'beta');
    $this->getJson("/api/v1/vendor/ai/analyses/{$a['id']}", $b)->assertNotFound();
    $this->postJson("/api/v1/vendor/ai/analyses/{$a['id']}/confirm", ['decision' => 'rejected'], $b + idem('x-tenant'))->assertNotFound();
});

describe('uploads', function () {
    it('rejects non-images, spoofed extensions, oversize and tiny files', function () {
        $post = fn (UploadedFile $f) => $this->post('/api/v1/vendor/ai/analyses', ['image' => $f], $this->h + idem() + ['Accept' => 'application/json']);
        // the fake test client trusts the extension, so the server-side content check (getimagesize) is what stops this one
        $post(UploadedFile::fake()->createWithContent('evil.jpg', '<?php system($_GET["c"]); ?>'))->assertStatus(422)->assertJsonPath('error.code', 'invalid_image');
        $post(UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'))->assertStatus(422);
        $post(UploadedFile::fake()->image('huge.jpg', 600, 600)->size(9000))->assertStatus(422);
        $post(UploadedFile::fake()->image('tiny.png', 10, 10))->assertStatus(422)->assertJsonPath('error.code', 'invalid_image_size');
        $this->post('/api/v1/vendor/ai/analyses', [], $this->h + idem() + ['Accept' => 'application/json'])->assertStatus(422);
    });

    it('re-encodes images (dropping appended payloads) and stores them on the private disk', function () {
        Storage::fake('local');
        $fake = UploadedFile::fake()->image('p.jpg', 400, 300); // keep a reference: the temp file dies with the object
        $path = $fake->getRealPath();
        file_put_contents($path, 'TRAILING_PAYLOAD_MARKER', FILE_APPEND); // valid JPEG + appended bytes (a script would already fail MIME sniffing)
        $r = ($this->analyze)('makita-hp1630', new UploadedFile($path, 'p.jpg', 'image/jpeg', null, true))->assertCreated();
        $a = AiAnalysis::query()->findOrFail($r->json('data.id'));
        Storage::disk('local')->assertExists($a->image_path);
        expect(Storage::disk('local')->get($a->image_path))->not->toContain('TRAILING_PAYLOAD_MARKER')->and($a->image_path)->toStartWith('ai-uploads/'.$this->w['tenant']->id.'/')->and($a->image_mime)->toBe('image/jpeg');
        expect(Storage::disk('public')->exists($a->image_path))->toBeFalse();
    });

    it('dedupes a retried upload by idempotency key (offline queue replay)', function () {
        $f = ($this->photo)();
        $one = ($this->analyze)('makita-hp1630', $f, 'upload-retry')->assertCreated();
        $two = ($this->analyze)('makita-hp1630', $f, 'upload-retry')->assertCreated();
        expect($two->json('data.id'))->toBe($one->json('data.id'))->and(AiAnalysis::query()->count())->toBe(1);
    });
});

describe('Gemma provider', function () {
    beforeEach(function () {
        config(['hardwhere.ai.provider' => 'gemma', 'hardwhere.ai.gemma.api_key' => 'test-key', 'hardwhere.ai.gemma.model' => 'gemma-4-26b-a4b-it']);
        $this->reply = fn (string $text, int $status = 200) => Http::response($status === 200 ? ['candidates' => [['content' => ['parts' => [['text' => 'thinking...', 'thought' => true], ['text' => $text]]]]]] : ['error' => 'x'], $status);
    });

    it('sends the documented generateContent request and validates the reply', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => ($this->reply)("```json\n".json_encode(['name' => 'Hammer Drill', 'brand' => 'Makita', 'model' => 'HP1630', 'category' => 'Power Tools',
            'attributes' => ['voltage_v' => 220, 'BAD KEY' => 1, 'nested' => ['x']], 'confidence' => ['brand' => 1.7, 'model' => 'high'], 'overall_confidence' => 0.91, 'barcode_text' => 'EAN 4800 0001 00017'])."\n```")]);

        expect(app(VisionProvider::class))->toBeInstanceOf(GemmaVisionProvider::class);
        $r = ($this->analyze)(null)->assertCreated();

        Http::assertSent(function ($req) {
            $part = $req['contents'][0]['parts'];

            return str_ends_with($req->url(), '/v1beta/models/gemma-4-26b-a4b-it:generateContent') && $req->hasHeader('x-goog-api-key', 'test-key')
                && $part[0]['inline_data']['mime_type'] === 'image/jpeg' && base64_decode($part[0]['inline_data']['data'], true) !== false && str_contains($part[1]['text'], 'ONE JSON object');
        });
        // provider/model are recorded for audit but never shown to clients
        expect(AiAnalysis::query()->withoutGlobalScopes()->find($r->json('data.id'))->only(['provider', 'model']))->toBe(['provider' => 'gemma', 'model' => 'gemma-4-26b-a4b-it']);
        expect($r->json('data.is_mock'))->toBeFalse()->and($r->json())->not->toHaveKey('data.provider')->and(json_encode($r->json()))->not->toContain('gemma-4')
            ->and($r->json('data.provider_label'))->toBe('AI recognition')
            ->and($r->json('data.extracted.attributes'))->toBe(['voltage_v' => 220])        // invalid keys / nested values dropped
            ->and($r->json('data.extracted.confidence.brand'))->toEqual(1.0)->and($r->json('data.extracted.confidence.model'))->toEqual(0.0) // clamped / non-numeric
            ->and($r->json('data.extracted.barcode_text'))->toBe('4800000100017')
            ->and($r->json('data.candidates.0.variant.model_number'))->toBe('HP1630');
        expect(json_encode($r->json()))->not->toContain('test-key');
    });

    it('fails safely and offers manual entry when the provider is down, misconfigured, or returns garbage', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => ($this->reply)('not json at all')]);
        ($this->analyze)(null)->assertStatus(503)->assertJsonPath('error.code', 'ai_unavailable')->assertJsonPath('error.message', fn ($m) => str_contains($m, 'manually'));
        Http::fake(['generativelanguage.googleapis.com/*' => ($this->reply)('', 500)]);
        ($this->analyze)(null)->assertStatus(503);
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('timeout')]);
        ($this->analyze)(null)->assertStatus(503);
        config(['hardwhere.ai.gemma.api_key' => null]);
        ($this->analyze)(null)->assertStatus(503)->assertJsonPath('error.details.reason', 'GEMMA_API_KEY is not configured.');
        expect(AiAnalysis::query()->count())->toBe(0);
    });
});

it('validates model output strictly', function () {
    expect(fn () => ProductExtraction::fromModelOutput('text'))->toThrow(AiProviderException::class);
    $x = ProductExtraction::fromModelOutput(['name' => str_repeat('x', 500), 'overall_confidence' => 5, 'pack_quantity' => -3, 'image_sufficient' => false]);
    expect(mb_strlen($x->name))->toBe(160)->and($x->overallConfidence)->toBe(1.0)->and($x->packQuantity)->toBeNull()->and($x->imageSufficient)->toBeFalse();
});

it('is deterministic: the mock gives the same answer for the same image and every scenario is labelled', function () {
    $m = new MockVisionProvider;
    $bytes = random_bytes(64);
    expect($m->analyze($bytes, 'image/jpeg')->extraction->toArray())->toBe($m->analyze($bytes, 'image/jpeg')->extraction->toArray());
    foreach (array_keys(MockVisionProvider::scenarios()) as $key) {
        $res = $m->analyze('x', 'image/jpeg', ['hint' => $key]);
        expect($res->isMock)->toBeTrue()->and($res->provider)->toBe('mock')->and(implode(' ', $res->extraction->notes))->toContain('Demo AI');
    }
});

describe('demo mock behaviour', function () {
    it('does not guess: an unknown photo is "insufficient confidence" with an explanation, never a confident wrong match', function () {
        $r = $this->post('/api/v1/vendor/ai/analyses', ['image' => UploadedFile::fake()->image('IMG_4821.jpg', 400, 400)], $this->h + idem() + ['Accept' => 'application/json'])->assertCreated();
        expect($r->json('data.is_mock'))->toBeTrue()->and($r->json('data.classification'))->toBe('insufficient_confidence')->and($r->json('data.candidates'))->toBe([])
            ->and($r->json('data.extracted.name'))->toBeNull()->and(implode(' ', $r->json('data.extracted.notes')))->toContain('built-in demo photos');
    });

    it('picks the demo scenario from a keyword in the file name, and an explicit hint wins over the name', function () {
        $byName = $this->post('/api/v1/vendor/ai/analyses', ['image' => UploadedFile::fake()->image('my-makita-drill.jpg', 400, 400)], $this->h + idem() + ['Accept' => 'application/json'])->assertCreated();
        expect($byName->json('data.extracted.model'))->toBe('HP1630');
        $hint = $this->post('/api/v1/vendor/ai/analyses', ['image' => UploadedFile::fake()->image('makita.jpg', 400, 400), 'hint' => 'barcode-visible'], $this->h + idem() + ['Accept' => 'application/json'])->assertCreated();
        expect($hint->json('data.extracted.brand'))->toBe('Stanley');
    });
});

describe('OpenAI-compatible provider', function () {
    beforeEach(function () {
        config(['hardwhere.ai.provider' => 'openai', 'hardwhere.ai.openai.base_url' => 'https://openrouter.ai/api/v1', 'hardwhere.ai.openai.api_key' => 'or-test-key',
            'hardwhere.ai.openai.model' => 'google/gemma-4-26b-a4b-it:free', 'hardwhere.ai.openai.json_mode' => false]);
        $this->chat = fn (string $text) => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]]);
        $this->json = json_encode(['name' => 'Hammer Drill', 'brand' => 'Makita', 'model' => 'HP1630', 'category' => 'Power Tools', 'attributes' => ['voltage_v' => 220], 'confidence' => ['brand' => 0.95, 'model' => 0.9], 'overall_confidence' => 0.92]);
    });

    it('sends an OpenAI chat-completions request with the image as a data URI and validates the reply', function () {
        Http::fake(['openrouter.ai/*' => ($this->chat)("```json\n{$this->json}\n```")]);
        expect(app(VisionProvider::class))->toBeInstanceOf(OpenAiCompatibleVisionProvider::class);
        $r = ($this->analyze)(null)->assertCreated();

        Http::assertSent(function ($req) {
            $parts = $req['messages'][0]['content'];

            return $req->url() === 'https://openrouter.ai/api/v1/chat/completions' && $req->hasHeader('Authorization', 'Bearer or-test-key') && $req['model'] === 'google/gemma-4-26b-a4b-it:free'
                && $parts[0]['type'] === 'text' && str_starts_with($parts[1]['image_url']['url'], 'data:image/jpeg;base64,') && ! isset($req['response_format']);
        });
        expect(AiAnalysis::query()->withoutGlobalScopes()->find($r->json('data.id'))->only(['provider', 'model']))->toBe(['provider' => 'openai-compatible', 'model' => 'google/gemma-4-26b-a4b-it:free']);
        expect($r->json('data.is_mock'))->toBeFalse()->and($r->json())->not->toHaveKey('data.model')->and(json_encode($r->json()))->not->toContain('gemma-4-26b')
            ->and($r->json('data.provider_label'))->toBe('AI recognition')->and($r->json('data.extracted.model'))->toBe('HP1630')->and($r->json('data.candidates.0.variant.model_number'))->toBe('HP1630');
        expect(json_encode($r->json()))->not->toContain('or-test-key');
    });

    it('can request JSON mode, works without a key for local servers, and reads array-style content', function () {
        config(['hardwhere.ai.openai.json_mode' => true, 'hardwhere.ai.openai.api_key' => null, 'hardwhere.ai.openai.base_url' => 'http://localhost:11434/v1']);
        Http::fake(['localhost:11434/*' => Http::response(['choices' => [['message' => ['content' => [['type' => 'text', 'text' => $this->json]]]]]])]);
        ($this->analyze)(null)->assertCreated();
        Http::assertSent(fn ($req) => $req['response_format'] === ['type' => 'json_object'] && ! $req->hasHeader('Authorization'));
    });

    it('fails safely (503, manual entry) on HTTP errors, garbage, timeouts and missing configuration', function () {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => 'rate limited'], 429)]);
        ($this->analyze)(null)->assertStatus(503)->assertJsonPath('error.code', 'ai_unavailable');
        Http::fake(['openrouter.ai/*' => ($this->chat)('sorry, I cannot help')]);
        ($this->analyze)(null)->assertStatus(503);
        Http::fake(['openrouter.ai/*' => fn () => throw new ConnectionException('timeout')]);
        ($this->analyze)(null)->assertStatus(503);
        config(['hardwhere.ai.openai.model' => null]);
        ($this->analyze)(null)->assertStatus(503)->assertJsonPath('error.details.reason', 'AI_OPENAI_BASE_URL and AI_OPENAI_MODEL must be configured.');
        expect(AiAnalysis::query()->count())->toBe(0);
    });
});

it('normalises unit-suffixed model attributes to numbers so numeric search and comparison work', function () {
    $x = ProductExtraction::fromModelOutput(['name' => 'Drill', 'attributes' => [
        'voltage_v' => '18V', 'wattage_w' => '710 W', 'length_mm' => '1.5 mm', 'capacity_l' => '4 L', 'amperage_a' => 20, 'color' => 'Teal and Black', 'series' => 'LXT', 'size' => '#10 x 1"', 'weight_oz' => 'about 16 oz',
    ]]);
    expect($x->attributes)->toBe(['voltage_v' => 18, 'wattage_w' => 710, 'length_mm' => 1.5, 'capacity_l' => 4, 'amperage_a' => 20, 'color' => 'Teal and Black', 'series' => 'LXT', 'size' => '#10 x 1"', 'weight_oz' => 'about 16 oz']);
});

it('finds a product by natural-language voltage even when the model said "18V" as text', function () {
    $cat = Category::where('slug', 'power-tools')->value('id');
    $x = ProductExtraction::fromModelOutput(['name' => 'Cordless drill', 'attributes' => ['voltage_v' => '18V']]);
    $p = app(CatalogService::class)->createProduct(['category_id' => $cat, 'name' => 'Zenith 18V Cordless Drill', 'brand' => 'Zenith', 'variants' => [['name' => 'Zenith 18V', 'attributes' => $x->attributes]]]);
    $w = World::tenant('zen');
    World::stocked($w, $p->variants->first()->id, 5, 500000);
    $found = collect($this->getJson('/api/v1/public/search?q='.urlencode('zenith 18v drill'))->assertOk()->json('data'))->pluck('id');
    expect($found)->toContain($p->id);
});
