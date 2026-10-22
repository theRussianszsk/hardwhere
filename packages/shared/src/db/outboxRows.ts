import type { OutboxItem, OutboxState } from "../contracts";

export interface OutboxRow {
  id: string; scope_key: string; kind: string; payload: string; payload_version: number; label: string; state: OutboxState; attempts: number;
  next_attempt_at: string | null; last_error: string | null; response: string | null; created_at: string; updated_at: string;
}
export const OUTBOX_COLS = `id, scope_key, kind, payload, payload_version, label, state, attempts, next_attempt_at, last_error, response, created_at, updated_at`;

/** JSON.parse that never throws: corrupt text becomes `fallback` instead of crashing a list screen. */
export function safeParse<T>(text: string | null | undefined, fallback: T): T {
  if (text === null || text === undefined) return fallback;
  try { return JSON.parse(text) as T; } catch { return fallback; }
}

export const rowToOutboxItem = (r: OutboxRow): OutboxItem => ({
  id: r.id, kind: r.kind, payload: safeParse<unknown>(r.payload, null), payloadVersion: Number(r.payload_version), state: r.state,
  attempts: Number(r.attempts), nextAttemptAt: r.next_attempt_at, lastError: safeParse(r.last_error, null),
  response: safeParse<unknown>(r.response, null), label: r.label, createdAt: r.created_at, updatedAt: r.updated_at,
});

export const UNSENT_STATES: OutboxState[] = ["draft", "queued", "sending", "needs_review", "rejected"];
export const ALL_STATES: OutboxState[] = ["draft", "queued", "sending", "confirmed", "rejected", "needs_review"];
