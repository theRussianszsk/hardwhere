<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Services\ChangeFeed;
use App\Services\SyncProjector;
use App\Support\ApiException;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versioned snapshot + delta sync for the mobile caches. The same controller serves three audiences ("kinds"):
 * vendor (tenant-scoped, role-aware), customer (public + own orders), public (anonymous browsing).
 * Visibility is recomputed on every call; a cursor only says where to resume, it never grants access.
 */
class SyncController extends Controller
{
    use ApiHelpers;

    public function __construct(private ChangeFeed $feed, private SyncProjector $projector) {}

    public function snapshot(Request $request, string $kind): JsonResponse
    {
        [$ctx, $userId] = $this->actor($kind, $request);
        $entities = SyncProjector::ENTITIES[$kind];
        $entity = $request->query('entity');
        in_array($entity, $entities, true) || throw ApiException::invalid('unknown_entity', 'Unknown entity.', ['entities' => $entities]);
        $limit = max(1, min(500, (int) $request->query('limit', config('hardwhere.sync.page_size'))));

        $audiences = SyncProjector::audiences($kind, $ctx?->tenantId, $userId);
        // Head is read BEFORE the data so anything written meanwhile is replayed (idempotently) by the first delta call.
        $cursor = $this->feed->head($audiences);
        $page = $this->projector->snapshotPage($kind, $entity, (int) $request->query('after', 0), $limit, $ctx, $userId);

        return response()->json(['entity' => $entity, 'items' => $page['items'], 'next_after' => $page['next_after'], 'cursor' => $cursor, 'entities' => $entities, 'server_time' => now()->toIso8601String()]);
    }

    public function changes(Request $request, string $kind): JsonResponse
    {
        [$ctx, $userId] = $this->actor($kind, $request);
        $limit = max(1, min(500, (int) $request->query('limit', config('hardwhere.sync.page_size'))));
        $result = $this->feed->since(SyncProjector::audiences($kind, $ctx?->tenantId, $userId), (string) $request->query('cursor', ChangeFeed::START), $limit);

        $allowed = SyncProjector::ENTITIES[$kind];
        $ops = [];
        foreach ($result['changes'] as $c) {
            if (! in_array($c['entity'], $allowed, true)) {
                continue;
            }
            $data = $this->projector->project($kind, $c['entity'], $c['entity_id'], $ctx, $userId);
            $ops[] = ['entity' => $c['entity'], 'id' => $c['entity_id'], 'op' => $data ? 'upsert' : 'delete', 'data' => $data];
        }

        return response()->json(['changes' => $ops, 'cursor' => $result['cursor'], 'has_more' => $result['has_more'], 'server_time' => now()->toIso8601String()]);
    }

    /** @return array{0: ?TenantContext, 1: ?int} */
    private function actor(string $kind, Request $request): array
    {
        return match ($kind) {
            'vendor' => [$this->ctx(), $request->user()->id],
            'customer' => [null, $request->user()->id],
            default => [null, null],
        };
    }
}
