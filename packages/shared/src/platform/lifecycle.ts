/** Small helpers over @capacitor/app shared by connectivity.ts and links.ts. Everything degrades to a browser equivalent or a no-op on web. */
import { App, type AppState } from "@capacitor/app";
import { Capacitor, type PluginListenerHandle } from "@capacitor/core";

export const isNative = (): boolean => Capacitor.getPlatform() !== "web";

/** Capacitor's addListener() is async; this returns a synchronous unsubscribe that is safe to call before the handle arrives. */
export function capListener(pending: Promise<PluginListenerHandle>): () => void {
  let handle: PluginListenerHandle | null = null;
  let off = false;
  pending.then((h) => { if (off) void h.remove(); else handle = h; }, () => undefined);
  return () => { off = true; void handle?.remove(); handle = null; };
}

/** cb(true) when the app comes to the foreground / the tab becomes visible, cb(false) when it leaves. */
export function watchAppActive(cb: (active: boolean) => void): () => void {
  if (isNative()) return capListener(App.addListener("appStateChange", (s: AppState) => cb(s.isActive)));
  if (typeof document === "undefined") return () => undefined;
  const on = () => cb(document.visibilityState === "visible");
  document.addEventListener("visibilitychange", on);
  return () => document.removeEventListener("visibilitychange", on);
}

/**
 * Android may kill the app process while the camera / scanner is open; the plugin result is then delivered here on the next start
 * (see Capacitor "appRestoredResult"). Screens that start an intake photo can use it to resume instead of losing the capture.
 */
export function onAppRestoredResult(handler: (e: { pluginId: string; methodName: string; success: boolean; data?: unknown; error?: { message: string } }) => void): () => void {
  return isNative() ? capListener(App.addListener("appRestoredResult", handler)) : () => undefined;
}
