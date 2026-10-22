import { customerHandlers, type AppConfig } from "@hardwhere/shared";

// Default: the API on the same host the page was loaded from, so http://localhost, http://127.0.0.1 and a LAN address (phone on Wi-Fi) all work.
export const API_BASE: string = (import.meta.env.VITE_API_BASE_URL || `http://${typeof location !== "undefined" ? location.hostname : "localhost"}:8787`).replace(/\/+$/, "");

export const appConfig: AppConfig = {
  appKind: "customer",
  apiBaseUrl: API_BASE,
  dbName: "hardwhere_customer",
  keychainPrefix: "ph.hardwhere.market",
  anonymousBrowse: true,
  commandHandlers: (deps) => customerHandlers(deps),
};

/** Product images are API-relative paths (/demo-images/x.svg). */
export const imgUrl = (u: string | null | undefined): string | null => (u ? (/^https?:/.test(u) ? u : API_BASE + u) : null);
