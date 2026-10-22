<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** One error envelope for the whole API: {"error": {"code", "message", "details"?, "fields"?}}. Clients switch on `code`. */
final class ApiErrors
{
    public static function register(Exceptions $exceptions): void
    {
        $api = fn (Request $r) => $r->is('api/*');
        $json = fn (int $status, string $code, string $message, array $extra = []): JsonResponse => response()->json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);

        $exceptions->render(fn (ApiException $e, Request $r) => $api($r) ? $json($e->status, $e->errorCode, $e->getMessage(), $e->details ? ['details' => $e->details] : []) : null);
        $exceptions->render(fn (ValidationException $e, Request $r) => $api($r) ? $json(422, 'validation_failed', 'Please check the highlighted fields.', ['fields' => $e->errors()]) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $api($r) ? $json(401, 'unauthenticated', 'Your session has expired. Please sign in again.') : null);
        // Laravel turns AuthorizationException (policies, can:*) and Sanctum's MissingAbilityException into this before render callbacks run.
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $r) => $api($r) ? $json(403, 'forbidden', 'This action is not allowed for your role.') : null);
        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $r) => $api($r) ? $json(404, 'not_found', 'The requested resource was not found.') : null);
        $exceptions->render(fn (ThrottleRequestsException $e, Request $r) => $api($r) ? $json(429, 'rate_limited', 'Too many requests. Try again shortly.', ['details' => ['retry_after' => $e->getHeaders()['Retry-After'] ?? null]])->withHeaders($e->getHeaders()) : null);
        $exceptions->render(fn (UniqueConstraintViolationException $e, Request $r) => $api($r) ? $json(409, 'conflict', 'That record already exists.') : null);
        $exceptions->render(function (QueryException $e, Request $r) use ($api, $json) {
            if (! $api($r)) {
                return null;
            }

            // 23514 = check_violation: the database refused an impossible stock state; surface it as a conflict, not a crash.
            if ($e->getCode() === '23503') { // foreign_key_violation: a referenced row is missing or belongs to another tenant
                return $json(422, 'invalid_reference', 'One of the referenced records does not exist.');
            }

            return $e->getCode() === '23514' ? $json(409, 'constraint_violation', 'The change was rejected because it would put data in an invalid state.') : null;
        });
        $exceptions->render(fn (HttpExceptionInterface $e, Request $r) => $api($r) ? $json($e->getStatusCode(), 'http_error', $e->getMessage() ?: 'Request failed.') : null);
        $exceptions->dontReport([ApiException::class]);
    }
}
