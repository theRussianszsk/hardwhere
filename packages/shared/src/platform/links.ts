/** Opening things outside the app, plus deep-link and Android back-button hooks. All are safe no-ops on web where there is no native event. */
import { App } from "@capacitor/app";
import { capListener, isNative } from "./lifecycle";

/** Only http(s) may be opened: URLs such as directions links come from API data, so `javascript:` etc. must never reach window.open. */
export function assertWebUrl(url: string): string {
  let u: URL;
  try { u = new URL(url); } catch { throw new Error("That link is not valid."); }
  if (u.protocol !== "https:" && u.protocol !== "http:") throw new Error("That link is not valid.");
  return u.toString();
}

/** "+63 917-123 4567" / "(02) 8123 4567" -> "tel:+639171234567" / "tel:0281234567". */
export function toTelUri(number: string): string {
  const n = number.trim().replace(/(?!^\+)[^\d]/g, "");
  if (n.replace("+", "").length < 3) throw new Error("That phone number is not valid.");
  return `tel:${n}`;
}

/** New tab in browsers; in the Capacitor WebView the system browser takes over. Call from a tap handler. */
export function openExternal(url: string): void {
  const w = window.open(assertWebUrl(url), "_blank");
  if (w) w.opener = null;
}
export const openDirections = openExternal;

export function callPhone(number: string): void {
  window.location.href = toTelUri(number);
}

let launchUrlConsumed = false;
/** Fires for links opened while the app runs, and once for the link that cold-started it. Returns unsubscribe. */
export function onDeepLink(handler: (url: string) => void): () => void {
  if (!isNative()) return () => undefined;
  const off = capListener(App.addListener("appUrlOpen", (e) => handler(e.url)));
  if (!launchUrlConsumed) {
    launchUrlConsumed = true;
    App.getLaunchUrl().then((l) => { if (l?.url) handler(l.url); }, () => undefined);
  }
  return off;
}

/**
 * Android hardware/gesture back. While a listener is registered Capacitor no longer navigates back by itself: the handler must
 * decide (history.back()/router navigate when canGoBack, otherwise exitApp()).
 */
export function onAndroidBack(handler: (e: { canGoBack: boolean }) => void): () => void {
  return isNative() ? capListener(App.addListener("backButton", handler)) : () => undefined;
}
export const exitApp = (): Promise<void> => (isNative() ? App.exitApp() : Promise.resolve());
