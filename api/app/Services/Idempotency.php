<?php

namespace App\Services;

use App\Support\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Exactly-once business effect for retried commands (not exactly-once delivery).
 *
 * Inside the caller's transaction the first statement is INSERT ... ON CONFLICT DO NOTHING on (scope, key).
 * A concurrent duplicate blocks on that unique index until the original commits, then replays its stored result.
 * PostgreSQL aborts a transaction on a unique violation, so we never rely on catching one.
 */
class Idempotency
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $resourceClass
     * @param  callable(): TModel  $execute  creates the business effect and returns the resulting record
     * @return array{0: TModel, 1: bool} the resource and whether it was replayed from a previous request
     */
    public function run(string $scope, string $key, string $operation, string $requestHash, string $resourceClass, callable $execute): array
    {
        return DB::transaction(function () use ($scope, $key, $operation, $requestHash, $resourceClass, $execute) {
            $inserted = DB::table('idempotency_keys')->insertOrIgnore([
                'scope' => $scope, 'key' => $key, 'operation' => $operation, 'request_hash' => $requestHash, 'created_at' => now(),
            ]);

            if ($inserted === 0) {
                $row = DB::table('idempotency_keys')->where('scope', $scope)->where('key', $key)->first();

                if ($row->operation !== $operation || $row->request_hash !== $requestHash) {
                    throw ApiException::conflict('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.');
                }
                if (! $row->resource_id) {
                    throw ApiException::conflict('idempotency_in_progress', 'The original request is still being processed. Retry shortly.');
                }

                return [$resourceClass::query()->withoutGlobalScopes()->findOrFail($row->resource_id), true];
            }

            $resource = $execute();
            DB::table('idempotency_keys')->where('scope', $scope)->where('key', $key)
                ->update(['resource_type' => $resourceClass, 'resource_id' => $resource->getKey()]);

            return [$resource, false];
        });
    }

    /** @param  array<string, mixed>  $payload */
    public static function hash(array $payload): string
    {
        self::ksortRecursive($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param  array<mixed>  $a */
    private static function ksortRecursive(array &$a): void
    {
        foreach ($a as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
        if (! array_is_list($a)) {
            ksort($a);
        }
    }
}
