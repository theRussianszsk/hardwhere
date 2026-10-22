/**
 * App-private image files (never SQLite BLOBs).
 *   native: @capacitor/filesystem, Directory.Data, `photos/<uuid>.<ext>` written as base64.
 *   web (dev): IndexedDB.
 * `ref` is opaque to callers, but it is a RELATIVE path on purpose: absolute iOS container paths change between app updates.
 * Note for the lead: on iOS Directory.Data is the Documents folder, which is included in iCloud/iTunes backups.
 */
import { Directory, Filesystem, type FilesystemPlugin } from "@capacitor/filesystem";
import type { PhotoStore } from "../contracts";
import { newId } from "../format";
import { isNative } from "./lifecycle";

const EXT_BY_MIME: Record<string, string> = { "image/jpeg": "jpg", "image/png": "png", "image/webp": "webp", "image/heic": "heic", "image/heif": "heif" };
const MIME_BY_EXT: Record<string, string> = { jpg: "image/jpeg", png: "image/png", webp: "image/webp", heic: "image/heic", heif: "image/heif" };
const REF_RE = /^photos\/[A-Za-z0-9-]+\.(jpg|png|webp|heic|heif)$/;

export const extForMime = (mime: string): string => EXT_BY_MIME[mime.toLowerCase()] ?? "jpg";
export const mimeForRef = (ref: string): string => MIME_BY_EXT[ref.slice(ref.lastIndexOf(".") + 1)] ?? "image/jpeg";
/** Refs come from our own database; still refuse anything that could escape the photos folder. */
function assertRef(ref: string): void {
  if (!REF_RE.test(ref)) throw new Error("photo missing");
}

export async function blobToBase64(blob: Blob): Promise<string> {
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let bin = "";
  for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return btoa(bin);
}
export function base64ToBlob(b64: string, mime: string): Blob {
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new Blob([bytes], { type: mime });
}

type Fs = Pick<FilesystemPlugin, "writeFile" | "readFile" | "deleteFile">;

function nativeStore(fs: Fs): PhotoStore {
  return {
    async save(blob) {
      const mime = blob.type || "image/jpeg";
      const ref = `photos/${newId()}.${extForMime(mime)}`;
      await fs.writeFile({ path: ref, directory: Directory.Data, data: await blobToBase64(blob), recursive: true }); // no encoding => binary from base64
      return { ref, mime, size: blob.size };
    },
    async load(ref) {
      assertRef(ref);
      let data: string | Blob | undefined;
      try { data = (await fs.readFile({ path: ref, directory: Directory.Data })).data; } catch (cause) { throw new Error("photo missing", { cause }); }
      if (typeof data !== "string" || !data) throw new Error("photo missing");
      return base64ToBlob(data, mimeForRef(ref));
    },
    async remove(ref) {
      assertRef(ref);
      try { await fs.deleteFile({ path: ref, directory: Directory.Data }); } catch (e) {
        if (!/not exist|not found|no such|enoent/i.test((e as Error)?.message ?? "")) throw e; // already gone is fine
      }
    },
  };
}

// ───────── web (IndexedDB) ─────────
const DB_NAME = "hardwhere-photos";
const STORE = "photos";
function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const r = indexedDB.open(DB_NAME, 1);
    r.onupgradeneeded = () => r.result.createObjectStore(STORE);
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
}
async function withStore<T>(mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await openDb();
  try {
    return await new Promise<T>((resolve, reject) => {
      const req = fn(db.transaction(STORE, mode).objectStore(STORE));
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  } finally { db.close(); }
}
const webStore: PhotoStore = {
  async save(blob) {
    const mime = blob.type || "image/jpeg";
    const ref = `photos/${newId()}.${extForMime(mime)}`;
    await withStore("readwrite", (s) => s.put(blob, ref));
    return { ref, mime, size: blob.size };
  },
  async load(ref) {
    assertRef(ref);
    const b = await withStore<Blob | undefined>("readonly", (s) => s.get(ref));
    if (!b) throw new Error("photo missing");
    return b;
  },
  async remove(ref) {
    assertRef(ref);
    await withStore("readwrite", (s) => s.delete(ref));
  },
};

/** `deps` exists for tests; production code calls createPhotoStore(). */
export function createPhotoStore(deps: { native?: boolean; fs?: Fs } = {}): PhotoStore {
  return (deps.native ?? isNative()) ? nativeStore(deps.fs ?? Filesystem) : webStore;
}
