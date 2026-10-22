/**
 * Photo capture. Native: @capacitor/camera `takePhoto` / `chooseFromGallery` (the non-deprecated 8.1+ API; getPhoto/pickImages are
 * marked deprecated in the installed typings). Web (dev server): a hidden <input type=file>.
 *
 * Camera permission is checked/requested explicitly. The library path deliberately does NOT pre-check "photos": the system pickers
 * (PHPicker / Android photo picker) need no permission, so a stale 'denied' flag must not block a flow that would work. If the plugin
 * does report a gallery-permission error we map it to PermissionDeniedError('photos').
 */
import { Camera, CameraDirection, CameraErrorCode, EncodingType, MediaTypeSelection, type MediaResult } from "@capacitor/camera";
import { Capacitor } from "@capacitor/core";
import { CancelledError, PermissionDeniedError, UnsupportedError } from "../contracts";
import { isNative } from "./lifecycle";

export type PhotoSource = "camera" | "library";

export const CAMERA_DENIED_HELP = "Camera access is turned off. Open Settings, find this app, and allow Camera to take photos.";
export const PHOTOS_DENIED_HELP = "Photo access is turned off. Open Settings, find this app, and allow Photos to choose pictures.";

const CANCEL_CODES: string[] = [CameraErrorCode.TakePhotoCancelled, CameraErrorCode.ChooseMediaCancelled, CameraErrorCode.EditPhotoCancelled];

/** Maps whatever the plugin threw (structured `code` on 8.1+, message strings on older paths) onto our error classes. */
export function classifyCameraError(e: unknown, source: PhotoSource): Error {
  if (e instanceof CancelledError || e instanceof PermissionDeniedError || e instanceof UnsupportedError) return e;
  const err = e as { code?: unknown; message?: unknown } | null;
  const code = typeof err?.code === "string" ? err.code : "";
  const msg = typeof err?.message === "string" ? err.message : "";
  if (CANCEL_CODES.includes(code) || /cancel/i.test(msg)) return new CancelledError(); // includes the camera being interrupted/dismissed
  if (code === CameraErrorCode.CameraPermissionDenied) return new PermissionDeniedError("camera", CAMERA_DENIED_HELP);
  if (code === CameraErrorCode.GalleryPermissionDenied) return new PermissionDeniedError("photos", PHOTOS_DENIED_HELP);
  if (code === CameraErrorCode.NoCameraAvailable) return new UnsupportedError("camera");
  if (/denied|permission/i.test(msg)) return source === "camera" ? new PermissionDeniedError("camera", CAMERA_DENIED_HELP) : new PermissionDeniedError("photos", PHOTOS_DENIED_HELP);
  return e instanceof Error ? e : new Error(msg || "Could not get the photo.");
}

async function ensureCameraPermission(): Promise<void> {
  const st = await Camera.checkPermissions();
  if (st.camera === "granted" || st.camera === "limited") return;
  if (st.camera === "denied" && Capacitor.getPlatform() === "ios") throw new PermissionDeniedError("camera", CAMERA_DENIED_HELP); // iOS never asks twice
  const r = await Camera.requestPermissions({ permissions: ["camera"] });
  if (r.camera !== "granted" && r.camera !== "limited") throw new PermissionDeniedError("camera", CAMERA_DENIED_HELP);
}

async function blobFromResult(r: MediaResult | undefined): Promise<Blob> {
  const url = r?.webPath ?? (r?.uri ? Capacitor.convertFileSrc(r.uri) : undefined);
  if (!url) throw new CancelledError(); // nothing was chosen
  const res = await fetch(url);
  if (!res.ok) throw new Error("Could not read the photo from the device.");
  const b = await res.blob();
  return b.type ? b : new Blob([b], { type: "image/jpeg" });
}

function pickFileOnWeb(source: PhotoSource): Promise<Blob> {
  return new Promise((resolve, reject) => {
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/*";
    if (source === "camera") input.setAttribute("capture", "environment");
    input.style.display = "none";
    input.addEventListener("change", () => { const f = input.files?.[0]; input.remove(); if (f) resolve(f); else reject(new CancelledError()); }, { once: true });
    // 'cancel' exists on file inputs in current browsers/WebViews; on older ones the promise simply stays pending (dev-only path).
    input.addEventListener("cancel", () => { input.remove(); reject(new CancelledError()); }, { once: true });
    document.body.appendChild(input);
    input.click();
  });
}

/** @throws PermissionDeniedError | CancelledError | UnsupportedError (no camera) | Error */
export async function capturePhoto(source: PhotoSource): Promise<Blob> {
  if (!isNative()) return pickFileOnWeb(source);
  try {
    if (source === "camera") {
      await ensureCameraPermission();
      return await blobFromResult(await Camera.takePhoto({
        quality: 90, correctOrientation: true, encodingType: EncodingType.JPEG, saveToGallery: false, cameraDirection: CameraDirection.Rear, editable: "no",
      }));
    }
    const picked = await Camera.chooseFromGallery({ mediaType: MediaTypeSelection.Photo, allowMultipleSelection: false, limit: 1, correctOrientation: true, quality: 90, editable: "no" });
    return await blobFromResult(picked.results[0]);
  } catch (e) {
    throw classifyCameraError(e, source);
  }
}

// ───────────────────────── downscale ─────────────────────────
/** Pure: the size that fits `maxEdge` on the longer side, never upscaling. */
export function fitWithin(width: number, height: number, maxEdge: number): { width: number; height: number; scaled: boolean } {
  const longest = Math.max(width, height);
  if (!(longest > maxEdge)) return { width, height, scaled: false };
  const k = maxEdge / longest;
  return { width: Math.max(1, Math.round(width * k)), height: Math.max(1, Math.round(height * k)), scaled: true };
}

async function decode(blob: Blob): Promise<ImageBitmap> {
  try { return await createImageBitmap(blob, { imageOrientation: "from-image" }); } catch { return createImageBitmap(blob); } // older WebViews reject the options bag
}

/** Shrinks a photo to JPEG with its longer edge <= maxEdge to cut upload time on slow mobile data. Returns the original if it cannot be decoded. */
export async function downscaleImage(blob: Blob, o: { maxEdge?: number; quality?: number } = {}): Promise<Blob> {
  const { maxEdge = 1600, quality = 0.85 } = o;
  let bmp: ImageBitmap | undefined;
  try {
    bmp = await decode(blob);
    const { width, height, scaled } = fitWithin(bmp.width, bmp.height, maxEdge);
    if (!scaled && blob.type === "image/jpeg") return blob; // already small: re-encoding would only lose quality
    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext("2d");
    if (!ctx) return blob;
    ctx.fillStyle = "#fff"; // JPEG has no alpha: transparent PNGs would turn black
    ctx.fillRect(0, 0, width, height);
    ctx.drawImage(bmp, 0, 0, width, height);
    const out = await new Promise<Blob | null>((r) => canvas.toBlob(r, "image/jpeg", quality));
    if (!out || (blob.type === "image/jpeg" && out.size >= blob.size)) return blob;
    return blob instanceof File ? new File([out], blob.name, { type: out.type }) : out; // keep the original file name
  } catch {
    return blob;
  } finally {
    bmp?.close();
  }
}
