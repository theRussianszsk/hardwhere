/** Every failure the apps must handle explicitly (spec: timeout, 401, 403, 409, 429, 5xx). */
export type ApiErrorKind =
  | "network" // no response at all: offline, DNS, TLS, server down
  | "timeout"
  | "unauthenticated" // 401: token expired/revoked -> re-authenticate
  | "forbidden" // 403: role/tenant does not allow it, or membership revoked
  | "not_found"
  | "conflict" // 409: server state differs (stock, price, version, idempotency)
  | "validation" // 422
  | "rate_limited" // 429
  | "server" // 5xx
  | "client"; // other 4xx

export class ApiError extends Error {
  readonly kind: ApiErrorKind;
  readonly status: number;
  readonly code: string;
  readonly details: Record<string, unknown>;
  readonly fields: Record<string, string[]>;
  readonly retryAfterSec: number | null;

  constructor(init: { kind: ApiErrorKind; status?: number; code?: string; message: string; details?: Record<string, unknown>; fields?: Record<string, string[]>; retryAfterSec?: number | null }) {
    super(init.message);
    this.name = "ApiError";
    this.kind = init.kind;
    this.status = init.status ?? 0;
    this.code = init.code ?? init.kind;
    this.details = init.details ?? {};
    this.fields = init.fields ?? {};
    this.retryAfterSec = init.retryAfterSec ?? null;
  }

  /** Worth retrying later with the SAME idempotency key (the request may or may not have reached the server). */
  get retryable(): boolean {
    return this.kind === "network" || this.kind === "timeout" || this.kind === "server" || this.kind === "rate_limited";
  }

  /** True when the server is authoritative and said no: the user must review/refresh, retrying will not help. */
  get needsReview(): boolean {
    return this.kind === "conflict";
  }

  /** First validation message for a field, e.g. firstError("payment.reference"). */
  firstError(field: string): string | undefined {
    return this.fields[field]?.[0];
  }

  static fromStatus(status: number, body: unknown, retryAfter: string | null): ApiError {
    const e = (body as { error?: { code?: string; message?: string; details?: Record<string, unknown>; fields?: Record<string, string[]> } } | null)?.error;
    const kind: ApiErrorKind =
      status === 401 ? "unauthenticated" : status === 403 ? "forbidden" : status === 404 ? "not_found" : status === 409 ? "conflict"
      : status === 422 ? "validation" : status === 429 ? "rate_limited" : status >= 500 ? "server" : "client";
    const retry = retryAfter ? Number.parseInt(retryAfter, 10) : Number(e?.details?.retry_after ?? NaN);

    return new ApiError({
      kind, status, code: e?.code, details: e?.details, fields: e?.fields, retryAfterSec: Number.isFinite(retry) ? retry : null,
      message: e?.message ?? (status >= 500 ? "The server had a problem. Please try again." : `Request failed (${status}).`),
    });
  }
}

/** Plain-language text for the UI, never a raw status code. */
export function humanizeError(err: unknown): string {
  if (!(err instanceof ApiError)) return err instanceof Error ? err.message : "Something went wrong.";
  switch (err.kind) {
    case "network": return "You appear to be offline or the server can't be reached.";
    case "timeout": return "The server took too long to answer. Please try again.";
    case "unauthenticated": return "Your session has expired. Please sign in again.";
    case "forbidden": return err.message || "You don't have permission to do that.";
    case "rate_limited": return `Too many requests. Try again${err.retryAfterSec ? ` in ${err.retryAfterSec}s` : " shortly"}.`;
    case "server": return "The server is having trouble right now. Your work is saved; try again shortly.";
    default: return err.message;
  }
}
