import { ApiError } from "../../src/api/errors";
import type { ChangesResponse, SnapshotPage, SyncKind } from "../../src/api/types";
import { ENTITIES_BY_KIND } from "../../src/sync/engine";

export type FakeCall =
  | { type: "snapshot"; n: number; kind: SyncKind; entity: string; after: number; limit: number }
  | { type: "changes"; n: number; kind: SyncKind; cursor: string; limit: number };

export const netError = () => new ApiError({ kind: "network", message: "Can't reach the server." });

/**
 * A tiny in-memory stand-in for the Laravel sync endpoints. Snapshot pages are real keyset pages (ordered by id, `after`, `limit`);
 * each snapshot call returns a DIFFERENT cursor (`snap-<callNo>`) so tests can prove which page's cursor the engine kept.
 * Changes are scripted PER REQUESTED CURSOR, like a position-based feed; unknown cursors return "nothing new".
 */
export function createFakeSyncApi(initial: Record<string, any[]>, o: { entities?: string[]; cursorPrefix?: string } = {}) {
  const data: Record<string, any[]> = structuredClone(initial);
  const calls: FakeCall[] = [];
  const script = new Map<string, ChangesResponse[]>();
  let failWhen: ((c: FakeCall) => Error | null | undefined) | null = null;
  let beforeCall: ((c: FakeCall) => Promise<void> | void) | null = null;
  let seq = 0;
  const server_time = "2026-01-01T00:00:00+00:00";

  async function enter(c: FakeCall) {
    calls.push(c);
    await beforeCall?.(c);
    const err = failWhen?.(c);
    if (err) throw err;
  }

  const api = {
    async syncSnapshot(kind: SyncKind, entity: string, after: number, limit = 200): Promise<SnapshotPage> {
      const n = ++seq;
      await enter({ type: "snapshot", n, kind, entity, after, limit });
      const all = (data[entity] ?? []).filter((i) => i.id > after).sort((a, b) => a.id - b.id);
      const page = all.slice(0, limit);
      return {
        entity, items: structuredClone(page), next_after: all.length > limit ? page[page.length - 1].id : null,
        cursor: `${o.cursorPrefix ?? "snap"}-${n}`, entities: o.entities ?? [...ENTITIES_BY_KIND[kind]], server_time,
      };
    },
    async syncChanges(kind: SyncKind, cursor: string, limit = 200): Promise<ChangesResponse> {
      const n = ++seq;
      await enter({ type: "changes", n, kind, cursor, limit });
      const next = script.get(cursor)?.shift();
      return structuredClone(next ?? { changes: [], cursor, has_more: false, server_time });
    },
  };

  return {
    api, calls, data,
    snapshotCalls: () => calls.filter((c): c is Extract<FakeCall, { type: "snapshot" }> => c.type === "snapshot"),
    changeCalls: () => calls.filter((c): c is Extract<FakeCall, { type: "changes" }> => c.type === "changes"),
    /** When the engine asks for changes after `fromCursor`, answer with this page (queued FIFO). */
    onChanges(fromCursor: string, res: Omit<ChangesResponse, "server_time">) {
      const q = script.get(fromCursor) ?? [];
      q.push({ ...res, server_time });
      script.set(fromCursor, q);
    },
    failWhen(fn: typeof failWhen) { failWhen = fn; },
    beforeCall(fn: typeof beforeCall) { beforeCall = fn; },
    reset() { calls.length = 0; failWhen = null; beforeCall = null; },
  };
}
export type FakeSyncApi = ReturnType<typeof createFakeSyncApi>;
