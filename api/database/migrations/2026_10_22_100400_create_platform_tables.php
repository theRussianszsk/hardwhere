<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business-level idempotency. The row is the FIRST statement of every mutating transaction (INSERT ... ON CONFLICT DO NOTHING),
        // so a concurrent duplicate blocks on the unique index until the original commits, then replays the stored result.
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 64);
            $t->string('key', 100);
            $t->string('operation', 40);
            $t->string('request_hash', 64);
            $t->string('resource_type', 40)->nullable();
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['scope', 'key']);
        });

        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('action', 64);
            $t->string('subject_type', 40)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->jsonb('metadata')->default('{}');
            $t->string('ip', 45)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['tenant_id', 'action']);
        });
        DB::statement('CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');

        Schema::create('ai_analyses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable(); // null for customer searches
            $t->foreignId('user_id')->constrained();
            $t->string('purpose', 20);
            $t->string('provider', 24);
            $t->string('model', 80)->nullable();
            $t->boolean('is_mock')->default(false);
            $t->string('status', 12)->default('completed');
            $t->string('image_path')->nullable(); // private disk; null for customer searches (image is not retained)
            $t->string('image_sha256', 64);
            $t->string('image_mime', 24);
            $t->jsonb('extracted')->default('{}');
            $t->jsonb('warnings')->default('[]');
            $t->jsonb('raw_response')->nullable();
            $t->decimal('overall_confidence', 4, 3)->nullable();
            $t->string('classification', 28)->nullable();
            $t->boolean('requires_confirmation')->default(true);
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->unique(['id', 'tenant_id']);
            $t->index(['tenant_id', 'purpose', 'created_at']);
        });
        DB::statement("ALTER TABLE ai_analyses ADD CONSTRAINT ai_analyses_purpose_chk CHECK (purpose IN ('intake','pos_search','marketplace_search'))");

        Schema::create('ai_match_candidates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_analysis_id')->constrained('ai_analyses')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants');
            $t->string('classification', 28);
            $t->decimal('score', 4, 3);
            $t->jsonb('matched_fields')->default('[]');
            $t->jsonb('mismatched_fields')->default('[]');
            $t->jsonb('warnings')->default('[]');
            $t->unsignedSmallInteger('rank');
            $t->timestampsTz();
            $t->index(['ai_analysis_id', 'rank']);
        });

        // What the human decided, next to the original tenant input and the AI suggestion it was based on.
        Schema::create('ai_decisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('ai_analysis_id')->constrained('ai_analyses');
            $t->foreignId('user_id')->constrained();
            $t->string('decision', 24);
            $t->foreignId('variant_id')->nullable()->constrained('product_variants');
            $t->unsignedBigInteger('inventory_item_id')->nullable();
            $t->jsonb('tenant_input')->default('{}');
            $t->jsonb('ai_suggestion')->default('{}');
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->unique('ai_analysis_id');
        });
        DB::statement("ALTER TABLE ai_decisions ADD CONSTRAINT ai_decisions_chk CHECK (decision IN ('confirmed_existing','selected_variant','created_candidate','rejected'))");

        // Change feed behind snapshot/delta sync. txid lets readers skip rows from still-open transactions (see ChangeFeed).
        Schema::create('sync_changes', function (Blueprint $t) {
            $t->id();
            $t->string('audience', 32);
            $t->string('entity', 24);
            $t->unsignedBigInteger('entity_id');
            $t->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE sync_changes ADD COLUMN txid xid8 NOT NULL DEFAULT pg_current_xact_id()');
        DB::statement('CREATE INDEX sync_changes_feed_idx ON sync_changes (audience, txid, id)');
    }

    public function down(): void
    {
        foreach (['sync_changes', 'ai_decisions', 'ai_match_candidates', 'ai_analyses', 'audit_events', 'idempotency_keys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
