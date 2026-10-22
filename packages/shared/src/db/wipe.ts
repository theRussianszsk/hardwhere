import type { SqlPort, WipeScope } from "../contracts";

/**
 * Remove a scope's private data in ONE transaction. Cache + sync state always go; outbox/drafts/photo rows go only when
 * keepUnsent is false. Photo FILES are not SQLite: call listPhotoRefs() BEFORE wiping and delete those files afterwards.
 * (The contract keeps this a Promise<void>, so the refs cannot be returned from here.)
 */
export const wipeScope: WipeScope = async (port, scopeKey, o) => {
  await port.transaction(async (tx) => {
    for (const t of ["cache_entities", "cache_keys", "sync_state"]) await tx.run(`DELETE FROM ${t} WHERE scope_key = ?`, [scopeKey]);
    if (!o.keepUnsent) for (const t of ["outbox", "drafts", "photo_queue"]) await tx.run(`DELETE FROM ${t} WHERE scope_key = ?`, [scopeKey]);
  });
};

/** file_refs of every queued photo in the scope (so the caller can delete the files after wipeScope). */
export async function listPhotoRefs(port: SqlPort, scopeKey: string): Promise<string[]> {
  return (await port.all<{ file_ref: string }>(`SELECT file_ref FROM photo_queue WHERE scope_key = ? ORDER BY created_at, rowid`, [scopeKey])).map((r) => r.file_ref);
}
