/**
 * Barcode scanning through @capacitor-mlkit/barcode-scanning's full-screen `scan()` UI (native only).
 * Web or an unsupported device => UnsupportedError so screens fall back to manual SKU/barcode entry.
 * Android needs the Google Barcode Scanner module (Play Services); if absent we trigger its install and wait for it.
 */
import { BarcodeFormat, BarcodeScanner, GoogleBarcodeScannerModuleInstallState } from "@capacitor-mlkit/barcode-scanning";
import { Capacitor, type PluginListenerHandle } from "@capacitor/core";
import { CancelledError, PermissionDeniedError, UnsupportedError } from "../contracts";
import { CAMERA_DENIED_HELP } from "./camera";
import { isNative } from "./lifecycle";

/** Retail + warehouse symbologies only: fewer formats = faster, fewer false reads. */
const FORMATS = [BarcodeFormat.Ean13, BarcodeFormat.Ean8, BarcodeFormat.UpcA, BarcodeFormat.UpcE, BarcodeFormat.Code128, BarcodeFormat.Code39, BarcodeFormat.Itf, BarcodeFormat.QrCode];
const MODULE_INSTALL_TIMEOUT_MS = 90_000;

export async function isBarcodeScanSupported(): Promise<boolean> {
  if (!isNative()) return false;
  try { return (await BarcodeScanner.isSupported()).supported; } catch { return false; }
}

export function classifyScanError(e: unknown): Error {
  if (e instanceof CancelledError || e instanceof PermissionDeniedError || e instanceof UnsupportedError) return e;
  const msg = (e as { message?: unknown } | null)?.message;
  const m = typeof msg === "string" ? msg : "";
  if (/cancel/i.test(m)) return new CancelledError();
  if (/denied/i.test(m)) return new PermissionDeniedError("camera", CAMERA_DENIED_HELP);
  if (/module is not available|not supported|unimplemented|unavailable/i.test(m)) return new UnsupportedError("barcode scanning");
  return e instanceof Error ? e : new Error(m || "Scanning failed.");
}

async function ensureCameraPermission(): Promise<void> {
  const st = await BarcodeScanner.checkPermissions();
  if (st.camera === "granted" || st.camera === "limited") return;
  if (st.camera === "denied" && Capacitor.getPlatform() === "ios") throw new PermissionDeniedError("camera", CAMERA_DENIED_HELP);
  const r = await BarcodeScanner.requestPermissions();
  if (r.camera !== "granted" && r.camera !== "limited") throw new PermissionDeniedError("camera", CAMERA_DENIED_HELP);
}

/** Android only: make sure the Google scanner module is installed, downloading it if needed. */
async function ensureAndroidModule(): Promise<void> {
  if (Capacitor.getPlatform() !== "android") return;
  if ((await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable()).available) return;
  let handle: PluginListenerHandle | undefined;
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    await new Promise<void>((resolve, reject) => {
      timer = setTimeout(() => reject(new UnsupportedError("barcode scanning")), MODULE_INSTALL_TIMEOUT_MS);
      BarcodeScanner.addListener("googleBarcodeScannerModuleInstallProgress", (ev) => {
        if (ev.state === GoogleBarcodeScannerModuleInstallState.COMPLETED) resolve();
        else if (ev.state === GoogleBarcodeScannerModuleInstallState.FAILED || ev.state === GoogleBarcodeScannerModuleInstallState.CANCELED) reject(new UnsupportedError("barcode scanning"));
      }).then(async (h) => { handle = h; await BarcodeScanner.installGoogleBarcodeScannerModule(); }).catch(reject);
    });
  } finally {
    clearTimeout(timer);
    await handle?.remove();
  }
}

/** Resolves with the scanned value. @throws UnsupportedError | PermissionDeniedError('camera') | CancelledError */
export async function scanBarcode(): Promise<string> {
  if (!(await isBarcodeScanSupported())) throw new UnsupportedError("barcode scanning");
  try {
    await ensureCameraPermission();
    await ensureAndroidModule();
    const { barcodes } = await BarcodeScanner.scan({ formats: FORMATS });
    const value = (barcodes[0]?.rawValue || barcodes[0]?.displayValue || "").trim();
    if (!value) throw new CancelledError();
    return value;
  } catch (e) {
    throw classifyScanError(e);
  }
}
