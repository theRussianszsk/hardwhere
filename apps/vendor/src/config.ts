import { vendorHandlers, type AppConfig, type CommandHandler, type HandlerDeps } from "@hardwhere/shared";

// Default: the API on the same host the page was loaded from, so http://localhost, http://127.0.0.1 and a LAN address (phone on Wi-Fi) all work.
export const API_BASE_URL: string = import.meta.env.VITE_API_BASE_URL ?? `http://${typeof location !== "undefined" ? location.hostname : "localhost"}:8787`;

/** Outbox kind for adding a catalogue variant to stock without a photo (offline-capable manual entry). */
export const CMD_INVENTORY_CREATE = "inventory.create";

export const appConfig: AppConfig = {
  appKind: "vendor",
  apiBaseUrl: API_BASE_URL,
  dbName: "hardwhere-vendor",
  keychainPrefix: "ph.hardwhere.vendor",
  syncIntervalMs: 120_000,
  commandHandlers: (deps: HandlerDeps): Record<string, CommandHandler> => ({
    ...vendorHandlers(deps),
    [CMD_INVENTORY_CREATE]: { autoRetry: true, run: async (p: Record<string, unknown>, { idempotencyKey }) => (await deps.api.call<{ data: unknown }>("inventoryCreate", { body: p, idempotencyKey })).data },
  }),
};
