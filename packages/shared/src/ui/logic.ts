/** Pure presentation rules (wording, thresholds, banner precedence, input validation). No React: unit-tested in Node. */
import type { AnalysisClass, AvailabilityStatus } from "../api/types";
import { formatAge, formatPeso, parsePesoToCents } from "../format";
import type { IconName } from "./icons";

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? "" : "s"}`;

// ───────── AI confidence / match ─────────
export type ConfidenceLevel = "high" | "medium" | "low";
export const HIGH_CONFIDENCE = 0.85;
export const MEDIUM_CONFIDENCE = 0.6;
const clamp01 = (n: number) => (Number.isFinite(n) ? Math.min(1, Math.max(0, n)) : 0);
export const confidenceLevel = (c: number): ConfidenceLevel => (clamp01(c) >= HIGH_CONFIDENCE ? "high" : clamp01(c) >= MEDIUM_CONFIDENCE ? "medium" : "low");
export const confidencePercent = (c: number): number => Math.round(clamp01(c) * 100);
export const CONFIDENCE_META: Record<ConfidenceLevel, { label: string; color: "success" | "warning" | "failure"; icon: IconName; advice: string }> = {
  high: { label: "High", color: "success", icon: "check", advice: "Still check the details before saving." },
  medium: { label: "Medium", color: "warning", icon: "alert", advice: "Please double-check the name, brand and model." },
  low: { label: "Low", color: "failure", icon: "alert", advice: "Take a clearer photo or enter the details yourself." },
};

export const MATCH_META: Record<AnalysisClass, { label: string; color: "success" | "info" | "indigo" | "warning" | "purple" | "failure"; icon: IconName; hint: string }> = {
  exact_match: { label: "Exact match", color: "success", icon: "checks", hint: "This is already in the catalog." },
  likely_same_product: { label: "Likely same product", color: "info", icon: "check", hint: "Looks like an existing product. Please confirm." },
  likely_variant: { label: "Likely variant", color: "indigo", icon: "list", hint: "Same product, different size, color or pack. Pick the right variant." },
  possible_duplicate: { label: "Possible duplicate", color: "warning", icon: "alert", hint: "Similar items exist. Check before adding another." },
  new_product_candidate: { label: "New product", color: "purple", icon: "plus", hint: "Nothing similar found. You can add it as new." },
  insufficient_confidence: { label: "Not enough confidence", color: "failure", icon: "info", hint: "Try another photo or search by name or barcode." },
  not_hardware: { label: "Not a hardware product", color: "failure", icon: "alert", hint: "This is not something a hardware store sells, so nothing was matched." },
};

// ───────── stock ─────────
export const STOCK_META: Record<AvailabilityStatus, { label: string; color: "success" | "warning" | "failure"; icon: IconName }> = {
  in_stock: { label: "In stock", color: "success", icon: "check" },
  low_stock: { label: "Low stock", color: "warning", icon: "alert" },
  out_of_stock: { label: "Out of stock", color: "failure", icon: "x" },
};

// ───────── status pills: order + outbox + reservation states, each with its own colour AND wording AND icon ─────────
export type StatusKind =
  | "draft" | "queued" | "sending" | "submitted" | "pending" | "reserved" | "confirmed" | "preparing" | "ready_for_pickup"
  | "fulfilled" | "cancelled" | "expired" | "rejected" | "needs_review" | "failed";
interface StatusMeta { label: string; icon: IconName | "spinner"; color: "gray" | "blue" | "cyan" | "indigo" | "purple" | "teal" | "yellow" | "lime" | "green" | "dark" | "pink" | "red"; solid?: string }
export const STATUS_META: Record<StatusKind, StatusMeta> = {
  draft: { label: "Draft", icon: "pencil", color: "gray" },
  queued: { label: "Queued", icon: "clock", color: "blue" },
  sending: { label: "Sending", icon: "spinner", color: "cyan" },
  submitted: { label: "Submitted", icon: "send", color: "indigo" },
  pending: { label: "Pending", icon: "send", color: "indigo" },
  reserved: { label: "Reserved", icon: "lock", color: "purple" },
  confirmed: { label: "Confirmed", icon: "check", color: "teal" },
  preparing: { label: "Preparing", icon: "wrench", color: "yellow" },
  ready_for_pickup: { label: "Ready for pickup", icon: "bag", color: "lime" },
  fulfilled: { label: "Fulfilled", icon: "checks", color: "green" },
  cancelled: { label: "Cancelled", icon: "xCircle", color: "dark" },
  expired: { label: "Expired", icon: "hourglass", color: "pink" },
  rejected: { label: "Rejected", icon: "ban", color: "red" },
  needs_review: { label: "Needs review", icon: "flag", color: "yellow", solid: "bg-orange-700 text-white hover:bg-orange-700 dark:bg-orange-700 dark:text-white dark:hover:bg-orange-700" },
  failed: { label: "Failed", icon: "alert", color: "red", solid: "bg-red-700 text-white hover:bg-red-700 dark:bg-red-700 dark:text-white dark:hover:bg-red-700" },
};
export const isStatusKind = (s: string): s is StatusKind => s in STATUS_META;

// ───────── connection banner: exactly one banner, most important first ─────────
export interface ConnectionState {
  deviceOnline: boolean;
  /** null = never probed */
  serverReachable: boolean | null;
  stale: boolean;
  lastSyncedAt: string | null;
  syncing: boolean;
  queuedCount: number;
  errorMessage?: string | null;
}
export type BannerKind = "offline" | "server" | "error" | "syncing" | "queued" | "stale";
export interface BannerSpec { kind: BannerKind; color: "gray" | "failure" | "warning" | "info"; icon: IconName | "spinner"; title: string; detail: string | null; retry: string | null }

export function connectionBanner(s: ConnectionState, now: number = Date.now()): BannerSpec | null {
  const updated = s.lastSyncedAt ? `Last updated ${formatAge(s.lastSyncedAt, now)}.` : "Not updated on this phone yet.";
  const waiting = s.queuedCount > 0 ? `${plural(s.queuedCount, "change")} saved on this phone will send automatically.` : "";
  if (!s.deviceOnline) {
    return { kind: "offline", color: "gray", icon: "wifiOff", title: "You're offline", detail: [s.queuedCount > 0 ? waiting : "You can keep working.", updated].join(" "), retry: null };
  }
  if (s.serverReachable === false) {
    return { kind: "server", color: "failure", icon: "serverOff", title: "Can't reach the server", detail: [`Your phone is online but our server isn't answering. We'll keep trying.`, waiting, updated].filter(Boolean).join(" "), retry: "Try again" };
  }
  if (s.errorMessage) {
    return { kind: "error", color: "failure", icon: "alert", title: "Couldn't update", detail: s.errorMessage, retry: "Try again" };
  }
  if (s.syncing) {
    return { kind: "syncing", color: "info", icon: "spinner", title: "Syncing…", detail: s.queuedCount > 0 ? `Sending ${plural(s.queuedCount, "change")}.` : null, retry: null };
  }
  if (s.queuedCount > 0) {
    return { kind: "queued", color: "info", icon: "send", title: `${plural(s.queuedCount, "change")} waiting to send`, detail: "Saved on this phone. They'll send automatically.", retry: "Send now" };
  }
  if (s.stale) {
    return { kind: "stale", color: "warning", icon: "clock", title: "This information may be out of date", detail: updated, retry: "Refresh" };
  }
  return null;
}

// ───────── inputs ─────────
export function validatePeso(text: string, o: { min?: number; max?: number; required?: boolean } = {}): { cents: number | null; error: string | null } {
  if (text.trim() === "") return { cents: null, error: o.required ? "Enter an amount." : null };
  const cents = parsePesoToCents(text);
  if (cents === null) return { cents: null, error: "Enter an amount like 1,250 or 1,250.50." };
  if (o.min !== undefined && cents < o.min) return { cents: null, error: `Must be at least ${formatPeso(o.min)}.` };
  if (o.max !== undefined && cents > o.max) return { cents: null, error: `Must be ${formatPeso(o.max)} or less.` };
  return { cents, error: null };
}

export const clampQty = (n: number, min: number, max?: number): number => Math.min(max ?? Infinity, Math.max(min, Number.isFinite(n) ? Math.trunc(n) : min));

// ───────── store hours: {"mon-sat": ["08:00","18:00"], "sun": ["09:00","15:00"]} -> ["Mon–Sat 8:00 AM – 6:00 PM", ...] ─────────
const to12h = (hhmm: string): string => {
  const m = /^(\d{1,2}):(\d{2})$/.exec(hhmm.trim());
  if (!m) return hhmm;
  const h = Number(m[1]);
  return `${h % 12 === 0 ? 12 : h % 12}:${m[2]} ${h < 12 ? "AM" : "PM"}`;
};
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
export function formatStoreHours(hours: Partial<Record<string, string[]>> | null | undefined): string[] {
  return Object.entries(hours ?? {}).flatMap(([days, span]) => {
    if (!span || span.length === 0) return [];
    const label = days.split("-").map(cap).join("–");
    return [`${label} ${span.length >= 2 ? `${to12h(span[0]!)} – ${to12h(span[1]!)}` : span.join(", ")}`];
  });
}
