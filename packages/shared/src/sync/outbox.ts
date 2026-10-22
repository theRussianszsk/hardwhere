import { ApiError } from "../api/errors";
import type { BackoffOptions, CommandHandler, CreateOutboxProcessor, OutboxProcessor, OutboxProcessorOptions, OutboxState } from "../contracts";
import { OUTBOX_COLS, safeParse, type OutboxRow } from "../db/outboxRows";

export const DEFAULT_BACKOFF: BackoffOptions = { baseMs: 2000, capMs: 5 * 60_000, jitter: 0.2 };
export const DEFAULT_MAX_ATTEMPTS = 8;

/**
 * Delay before retry number `attempt` (1 = the first retry): exponential from baseMs, CAPPED at capMs, THEN jittered by ±jitter.
 * random() in [0,1): 0 -> x(1-jitter), 0.5 -> x1, ~1 -> x(1+jitter). So the result stays within [0.8, 1.2] x min(cap, base*2^(attempt-1)).
 */
export function computeBackoffMs(attempt: number, opts: BackoffOptions, random: () => number): number {
  const exp = Math.min(opts.capMs, opts.baseMs * 2 ** Math.max(0, attempt - 1));
  return Math.round(exp * (1 + opts.jitter * (2 * random() - 1)));
}

/** Extras beyond the contract (optional, so a plain OutboxProcessorOptions is still valid). */
export type OutboxProcessorOptionsX = OutboxProcessorOptions & {
  /** Called with the file_refs of photo_queue rows removed after a command confirmed, so the app can delete the image files. */
  onPhotosReleased?: (fileRefs: string[]) => void | Promise<void>;
};

type Failure = { state: OutboxState; nextAttemptAt: string | null; lastError: { code: string; message: string; details?: unknown } };

export const createOutboxProcessor = ((o: OutboxProcessorOptionsX): OutboxProcessor => {
  const { port, scope, handlers, bus } = o;
  const sk = scope.key;
  const now = o.now ?? Date.now;
  const random = o.random ?? Math.random;
  const maxAttempts = o.maxAttempts ?? DEFAULT_MAX_ATTEMPTS;
  const backoff = o.backoff ?? DEFAULT_BACKOFF;
  const iso = (ms = now()) => new Date(ms).toISOString();
  const emit = () => bus?.emit({ area: "outbox" });

  function failureFor(e: unknown, handler: CommandHandler, attemptsAfter: number): Failure | "auth" {
    if (!(e instanceof ApiError)) return { state: "rejected", nextAttemptAt: null, lastError: { code: "client_error", message: e instanceof Error ? e.message : String(e) } };
    if (e.kind === "unauthenticated") return "auth";
    const base = { code: e.code, message: e.message };
    if (e.kind === "conflict") return { state: "needs_review", nextAttemptAt: null, lastError: { ...base, details: e.details } };
    if (e.retryable) {
      // autoRetry:false (e.g. a POS sale whose response may have been lost) is never re-sent in the background.
      if (!handler.autoRetry || attemptsAfter >= maxAttempts) return { state: "needs_review", nextAttemptAt: null, lastError: base };
      let delay = computeBackoffMs(attemptsAfter, backoff, random);
      if (e.kind === "rate_limited" && e.retryAfterSec) delay = Math.max(delay, e.retryAfterSec * 1000);
      return { state: "queued", nextAttemptAt: iso(now() + delay), lastError: base };
    }
    const fields = Object.keys(e.fields).length ? { ...e.details, fields: e.fields } : e.details;
    return { state: "rejected", nextAttemptAt: null, lastError: Object.keys(fields).length ? { ...base, details: fields } : base };
  }

  async function confirm(row: OutboxRow, response: unknown): Promise<void> {
    let refs: string[] = [];
    await port.transaction(async (tx) => {
      await tx.run(
        `UPDATE outbox SET state = 'confirmed', attempts = attempts + 1, response = ?, last_error = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ? AND scope_key = ?`,
        [JSON.stringify(response ?? null), iso(), row.id, sk]);
      refs = (await tx.all<{ file_ref: string }>(`SELECT file_ref FROM photo_queue WHERE outbox_id = ? AND scope_key = ?`, [row.id, sk])).map((r) => r.file_ref);
      if (refs.length) await tx.run(`DELETE FROM photo_queue WHERE outbox_id = ? AND scope_key = ?`, [row.id, sk]);
    });
    emit();
    if (refs.length) {
      bus?.emit({ area: "photos" });
      try { await o.onPhotosReleased?.(refs); } catch { /* file cleanup must never undo a confirmed command */ }
    }
  }

  async function pass() {
    const due = await port.all<OutboxRow>(
      `SELECT ${OUTBOX_COLS} FROM outbox WHERE scope_key = ? AND state = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY created_at, rowid`,
      [sk, iso()]);
    let processed = 0;
    let confirmed = 0;
    let authRequired = false;

    for (const row of due) {
      const handler = handlers[row.kind];
      if (!handler) {
        await port.run(`UPDATE outbox SET state = 'rejected', last_error = ?, updated_at = ? WHERE id = ? AND scope_key = ? AND state = 'queued'`,
          [JSON.stringify({ code: "no_handler", message: `This app version cannot send "${row.kind}" commands.` }), iso(), row.id, sk]);
        emit();
        continue;
      }
      // Persist "sending" BEFORE the handler runs: if the app dies mid-request, recoverInterrupted() finds the row.
      const claim = await port.run(`UPDATE outbox SET state = 'sending', updated_at = ? WHERE id = ? AND scope_key = ? AND state = 'queued'`, [iso(), row.id, sk]);
      if (!claim.changes) continue; // discarded/changed since we listed it
      emit();
      processed++;

      let response: unknown;
      try {
        response = await handler.run(safeParse<unknown>(row.payload, null), { idempotencyKey: row.id, attempt: Number(row.attempts) + 1 });
      } catch (e) {
        const f = failureFor(e, handler, Number(row.attempts) + 1);
        if (f === "auth") {
          // Credentials expired: not the command's fault. Keep it queued, don't burn an attempt, stop the pass.
          await port.run(`UPDATE outbox SET state = 'queued', updated_at = ? WHERE id = ? AND scope_key = ? AND state = 'sending'`, [iso(), row.id, sk]);
          emit();
          authRequired = true;
          break;
        }
        await port.run(
          `UPDATE outbox SET state = ?, attempts = attempts + 1, next_attempt_at = ?, last_error = ?, updated_at = ? WHERE id = ? AND scope_key = ? AND state = 'sending'`,
          [f.state, f.nextAttemptAt, JSON.stringify(f.lastError), iso(), row.id, sk]);
        emit();
        continue;
      }
      await confirm(row, response); // only reached when the handler promise resolved
      confirmed++;
    }

    let nextWakeAt: number | null = null;
    if (!authRequired) {
      const t = (await port.all<{ t: string | null }>(`SELECT MIN(next_attempt_at) AS t FROM outbox WHERE scope_key = ? AND state = 'queued' AND next_attempt_at IS NOT NULL`, [sk]))[0]?.t;
      nextWakeAt = t ? Date.parse(t) : null;
    }
    return { processed, confirmed, authRequired, nextWakeAt };
  }

  // One pass at a time: overlapping calls queue up instead of racing over the same rows.
  let tail: Promise<unknown> = Promise.resolve();
  return {
    processDue() {
      const p = tail.then(pass, pass);
      tail = p.catch(() => undefined);
      return p;
    },
    async recoverInterrupted() {
      let n = 0;
      await port.transaction(async (tx) => {
        const rows = await tx.all<{ id: string; kind: string }>(`SELECT id, kind FROM outbox WHERE scope_key = ? AND state = 'sending'`, [sk]);
        for (const r of rows) {
          if (handlers[r.kind]?.autoRetry === false) {
            await tx.run(`UPDATE outbox SET state = 'needs_review', last_error = ?, updated_at = ? WHERE id = ? AND scope_key = ?`,
              [JSON.stringify({ code: "interrupted", message: "The app closed while this was being sent. Check whether it went through before sending it again." }), iso(), r.id, sk]);
          } else {
            await tx.run(`UPDATE outbox SET state = 'queued', next_attempt_at = NULL, updated_at = ? WHERE id = ? AND scope_key = ?`, [iso(), r.id, sk]);
          }
        }
        n = rows.length;
      });
      if (n) emit();
      return n;
    },
  };
}) satisfies CreateOutboxProcessor;
