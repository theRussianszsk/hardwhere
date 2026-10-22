/** Philippine peso, centavos in. 449900 -> "₱4,499.00" */
const peso = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", minimumFractionDigits: 2 });
export const formatPeso = (cents: number | null | undefined): string => (cents === null || cents === undefined ? "—" : peso.format(cents / 100));

/** Pesos typed by a person -> centavos, rejecting garbage instead of guessing. */
export function parsePesoToCents(input: string): number | null {
  const clean = input.replace(/[₱,\s]/g, "");
  if (!/^\d+(\.\d{1,2})?$/.test(clean)) return null;
  return Math.round(Number.parseFloat(clean) * 100);
}

const TZ = "Asia/Manila";
export const formatManilaDateTime = (iso: string | Date | null | undefined): string =>
  iso ? new Intl.DateTimeFormat("en-PH", { timeZone: TZ, dateStyle: "medium", timeStyle: "short" }).format(new Date(iso)) : "—";
export const formatManilaTime = (iso: string | Date | null | undefined): string =>
  iso ? new Intl.DateTimeFormat("en-PH", { timeZone: TZ, hour: "numeric", minute: "2-digit" }).format(new Date(iso)) : "—";
export const formatManilaDate = (iso: string | Date | null | undefined): string =>
  iso ? new Intl.DateTimeFormat("en-PH", { timeZone: TZ, dateStyle: "medium" }).format(new Date(iso)) : "—";

/** "3 min ago" style age used on every cached screen so stale data is never presented as live. */
export function formatAge(iso: string | number | Date | null | undefined, now: number = Date.now()): string {
  if (!iso) return "never";
  const s = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));
  if (s < 45) return "just now";
  if (s < 3600) return `${Math.max(1, Math.round(s / 60))} min ago`;
  if (s < 86400) return `${Math.round(s / 3600)} h ago`;
  return `${Math.round(s / 86400)} d ago`;
}

export const STALE_AFTER_MS = 15 * 60 * 1000;
export const isStale = (iso: string | null | undefined, now: number = Date.now(), maxAgeMs = STALE_AFTER_MS): boolean => !iso || now - new Date(iso).getTime() > maxAgeMs;

/** 0917 123 4567 / +63 917... -> +639171234567, or null when it is not a PH mobile number. */
export function normalizePhMobile(input: string): string | null {
  const d = input.replace(/\D+/g, "");
  const m = /^(?:63|0)?(9\d{9})$/.exec(d);
  return m ? `+63${m[1]}` : null;
}

export const formatKm = (km: number | null | undefined): string => (km === null || km === undefined ? "" : km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(1)} km`);

export function newId(): string {
  const c = globalThis.crypto;
  if (c?.randomUUID) return c.randomUUID();
  const b = c.getRandomValues(new Uint8Array(16));
  return Array.from(b, (x) => x.toString(16).padStart(2, "0")).join("");
}
