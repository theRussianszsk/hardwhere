/**
 * Optional "stores near me". Only coarse location is requested (Android `coarseLocation`), and coordinates are rounded to ~100 m before
 * they leave this module. Denied => PermissionDeniedError('location') so the UI can offer manual address entry. Timeout/GPS off => null.
 */
import { Geolocation } from "@capacitor/geolocation";
import { Capacitor } from "@capacitor/core";
import { PermissionDeniedError } from "../contracts";
import { isNative } from "./lifecycle";

export const LOCATION_DENIED_HELP = "Location is turned off for this app. You can allow it in Settings, or type your address instead.";

const round3 = (n: number) => Math.round(n * 1000) / 1000;

export function isLocationDenied(e: unknown): boolean {
  const err = e as { code?: unknown; message?: unknown } | null;
  return err?.code === 1 /* GeolocationPositionError.PERMISSION_DENIED */ || /denied|not authori[sz]ed|permission/i.test(typeof err?.message === "string" ? err.message : "");
}

async function ensurePermission(): Promise<void> {
  const st = await Geolocation.checkPermissions();
  if (st.location === "granted" || st.coarseLocation === "granted") return;
  if (st.location === "denied" && Capacitor.getPlatform() === "ios") throw new PermissionDeniedError("location", LOCATION_DENIED_HELP); // iOS never asks twice
  const r = await Geolocation.requestPermissions({ permissions: ["coarseLocation"] });
  if (r.location !== "granted" && r.coarseLocation !== "granted") throw new PermissionDeniedError("location", LOCATION_DENIED_HELP);
}

export async function getApproxLocation({ timeoutMs = 8000 }: { timeoutMs?: number } = {}): Promise<{ lat: number; lng: number } | null> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    if (isNative()) await ensurePermission();
    const pos = await Promise.race([
      Geolocation.getCurrentPosition({ enableHighAccuracy: false, timeout: timeoutMs, maximumAge: 5 * 60_000 }),
      new Promise<null>((r) => { timer = setTimeout(() => r(null), timeoutMs + 1000); }), // some platforms ignore the plugin's own timeout
    ]);
    return pos ? { lat: round3(pos.coords.latitude), lng: round3(pos.coords.longitude) } : null;
  } catch (e) {
    if (e instanceof PermissionDeniedError) throw e;
    if (isLocationDenied(e)) throw new PermissionDeniedError("location", LOCATION_DENIED_HELP);
    return null; // position unavailable / GPS off / timeout: location is optional
  } finally {
    clearTimeout(timer);
  }
}
