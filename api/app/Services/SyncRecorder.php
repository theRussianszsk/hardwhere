<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Appends to the change feed that drives snapshot/delta sync. Always called inside the writing transaction. */
class SyncRecorder
{
    public function record(string $audience, string $entity, int $id): void
    {
        DB::table('sync_changes')->insert(['audience' => $audience, 'entity' => $entity, 'entity_id' => $id]);
    }

    public function publicEntity(string $entity, int $id): void
    {
        $this->record('public', $entity, $id);
    }

    /** Canonical catalog changes (categories, products) — visible to every signed-in or anonymous client. */
    public function catalogEntity(string $entity, int $id): void
    {
        $this->record('catalog', $entity, $id);
    }

    public function tenantEntity(int $tenantId, string $entity, int $id): void
    {
        $this->record("tenant:$tenantId", $entity, $id);
    }

    public function userEntity(int $userId, string $entity, int $id): void
    {
        $this->record("user:$userId", $entity, $id);
    }

    /** A listing changed (price, visibility, availability): both the public view and the tenant view are stale. */
    public function listing(int $tenantId, int $listingId): void
    {
        $this->publicEntity('listing', $listingId);
        $this->tenantEntity($tenantId, 'listing', $listingId);
    }
}
