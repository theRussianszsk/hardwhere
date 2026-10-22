import type { CacheRow, SqlExec } from "../contracts";

/** Canonical JSON for filters: sorted keys so equality is stable. */
const canon = (f: Record<string, string | number> | undefined): string | null =>
  f && Object.keys(f).length ? JSON.stringify(Object.fromEntries(Object.entries(f).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)))) : null;

/** Insert/replace one cached DTO and refresh its exact-lookup keys. Must run inside the caller's transaction. */
export async function upsertCacheRow(tx: SqlExec, scopeKey: string, entity: string, row: CacheRow, snapshotId: string | null, syncedAt: string): Promise<void> {
  await tx.run(
    `INSERT INTO cache_entities (scope_key, entity, id, payload, search, row_version, filters, snapshot_id, synced_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON CONFLICT (scope_key, entity, id) DO UPDATE SET payload = excluded.payload, search = excluded.search, row_version = excluded.row_version,
       filters = excluded.filters, snapshot_id = excluded.snapshot_id, synced_at = excluded.synced_at`,
    [scopeKey, entity, row.id, JSON.stringify(row.payload), row.search.toLowerCase(), row.rowVersion ?? null, canon(row.filters), snapshotId, syncedAt],
  );
  await tx.run(`DELETE FROM cache_keys WHERE scope_key = ? AND entity = ? AND id = ?`, [scopeKey, entity, row.id]);
  for (const key of new Set(row.keys.map((k) => String(k).trim().toLowerCase()).filter(Boolean))) {
    await tx.run(`INSERT OR IGNORE INTO cache_keys (scope_key, entity, key, id) VALUES (?, ?, ?, ?)`, [scopeKey, entity, key, row.id]);
  }
}

/** Tombstone: remove a cached row and its keys. */
export async function deleteCacheRow(tx: SqlExec, scopeKey: string, entity: string, id: number): Promise<void> {
  await tx.run(`DELETE FROM cache_entities WHERE scope_key = ? AND entity = ? AND id = ?`, [scopeKey, entity, id]);
  await tx.run(`DELETE FROM cache_keys WHERE scope_key = ? AND entity = ? AND id = ?`, [scopeKey, entity, id]);
}

/** Remove cache rows of the scope not stamped with `keepSnapshotId`, plus keys left without a row. Returns the entities touched. */
export async function deleteStaleRows(tx: SqlExec, scopeKey: string, keepSnapshotId: string): Promise<string[]> {
  const entities = (await tx.all<{ entity: string }>(
    `SELECT DISTINCT entity FROM cache_entities WHERE scope_key = ? AND snapshot_id IS NOT ?`, [scopeKey, keepSnapshotId])).map((r) => r.entity);
  await tx.run(`DELETE FROM cache_entities WHERE scope_key = ? AND snapshot_id IS NOT ?`, [scopeKey, keepSnapshotId]);
  await tx.run(
    `DELETE FROM cache_keys WHERE scope_key = ? AND NOT EXISTS
       (SELECT 1 FROM cache_entities e WHERE e.scope_key = cache_keys.scope_key AND e.entity = cache_keys.entity AND e.id = cache_keys.id)`,
    [scopeKey]);
  return entities;
}
