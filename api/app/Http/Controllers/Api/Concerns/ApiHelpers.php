<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Support\ApiException;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

trait ApiHelpers
{
    protected function ctx(): TenantContext
    {
        return TenantContext::current() ?? throw ApiException::forbidden('A vendor session is required.');
    }

    /** Mutating commands carry a client-generated key so a retry after a lost response has exactly one business effect. */
    protected function idempotencyKey(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $key) || throw ApiException::invalid('idempotency_key_required', 'Send an Idempotency-Key header (8-100 characters).');

        return $key;
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->query('per_page', $default)));
    }
}
