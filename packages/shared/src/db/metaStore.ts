import type { CreateMetaStore } from "../contracts";

/** Non-secret, non-scoped key/value metadata (meta table). Tokens and passphrases never go here. */
export const createMetaStore: CreateMetaStore = (port) => ({
  async get(key) {
    const rows = await port.all<{ value: string }>(`SELECT value FROM meta WHERE key = ?`, [key]);
    return rows[0]?.value ?? null;
  },
  async set(key, value) {
    await port.run(`INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value`, [key, value]);
  },
  async delete(key) {
    await port.run(`DELETE FROM meta WHERE key = ?`, [key]);
  },
});
