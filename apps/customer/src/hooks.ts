import { useConnectivity, useNow, formatAge } from "@hardwhere/shared";

/** True when the device has a connection AND the last probe did not say the server is down. */
export function useOnline(): boolean {
  const c = useConnectivity();
  return c.deviceOnline && c.serverReachable !== false;
}

/** "Updated 2 min ago" label that keeps aging while the screen stays open. */
export function useAgeLabel(iso: string | number | null | undefined, prefix: string): string {
  const now = useNow(15_000);
  return iso ? `${prefix} ${formatAge(iso, now)}` : "";
}
