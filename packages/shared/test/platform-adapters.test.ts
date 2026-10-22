import { describe, expect, it, vi } from "vitest";
import { CancelledError, PermissionDeniedError, UnsupportedError } from "../src/contracts";
import { classifyCameraError, fitWithin } from "../src/platform/camera";
import { assertWebUrl, toTelUri } from "../src/platform/links";
import { isLocationDenied } from "../src/platform/location";
import { base64ToBlob, blobToBase64, createPhotoStore, extForMime, mimeForRef } from "../src/platform/photoStore";
import { classifyScanError } from "../src/platform/scanner";

describe("photo store (native path with a fake Filesystem)", () => {
  function fakeFs() {
    const files = new Map<string, string>();
    const calls: { path: string; directory: string; recursive?: boolean; encoding?: unknown }[] = [];
    const fs = {
      writeFile: vi.fn(async (o: { path: string; data: string | Blob; directory?: string; recursive?: boolean; encoding?: unknown }) => { files.set(o.path, o.data as string); calls.push({ path: o.path, directory: o.directory!, recursive: o.recursive, encoding: o.encoding }); return { uri: "file://" + o.path }; }),
      readFile: vi.fn(async (o: { path: string }) => { const d = files.get(o.path); if (d === undefined) throw new Error("File does not exist."); return { data: d }; }),
      deleteFile: vi.fn(async (o: { path: string }) => { if (!files.delete(o.path)) throw new Error("File does not exist."); }),
    };
    return { fs, files, calls };
  }
  const bytes = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 0, 16, 74, 70, 73, 70, 0, 255, 128, 7]);

  it("saves under photos/<id>.jpg in Directory.Data as base64, loads it back byte for byte", async () => {
    const { fs, calls } = fakeFs();
    const store = createPhotoStore({ native: true, fs });
    const { ref, mime, size } = await store.save(new Blob([bytes], { type: "image/jpeg" }));
    expect(ref).toMatch(/^photos\/[0-9a-f-]+\.jpg$/);
    expect(mime).toBe("image/jpeg");
    expect(size).toBe(bytes.length);
    expect(calls[0]).toMatchObject({ directory: "DATA", recursive: true, encoding: undefined });
    const back = await store.load(ref);
    expect(back.type).toBe("image/jpeg");
    expect(new Uint8Array(await back.arrayBuffer())).toEqual(bytes);
  });
  it("a missing file is a clear 'photo missing' error; refs that could escape the folder are refused", async () => {
    const store = createPhotoStore({ native: true, fs: fakeFs().fs });
    await expect(store.load("photos/deadbeef.jpg")).rejects.toThrow("photo missing");
    await expect(store.load("../../etc/passwd")).rejects.toThrow("photo missing");
    await expect(store.remove("photos/../x.jpg")).rejects.toThrow("photo missing");
  });
  it("remove deletes and is idempotent", async () => {
    const { fs, files } = fakeFs();
    const store = createPhotoStore({ native: true, fs });
    const { ref } = await store.save(new Blob([bytes], { type: "image/jpeg" }));
    await store.remove(ref);
    expect(files.size).toBe(0);
    await expect(store.remove(ref)).resolves.toBeUndefined();
  });
  it("keeps the real mime of non-JPEG input in the extension", async () => {
    const store = createPhotoStore({ native: true, fs: fakeFs().fs });
    const { ref } = await store.save(new Blob([bytes], { type: "image/png" }));
    expect(ref.endsWith(".png")).toBe(true);
    expect((await store.load(ref)).type).toBe("image/png");
    expect(extForMime("image/HEIC")).toBe("heic");
    expect(extForMime("")).toBe("jpg");
    expect(mimeForRef("photos/x.webp")).toBe("image/webp");
  });
  it("base64 helpers round-trip a large blob", async () => {
    const big = new Uint8Array(200_000).map((_, i) => i % 251);
    const b64 = await blobToBase64(new Blob([big]));
    expect(new Uint8Array(await base64ToBlob(b64, "image/jpeg").arrayBuffer())).toEqual(big);
  });
});

describe("downscale math", () => {
  it("never upscales and keeps aspect ratio", () => {
    expect(fitWithin(800, 600, 1600)).toEqual({ width: 800, height: 600, scaled: false });
    expect(fitWithin(1600, 1200, 1600).scaled).toBe(false);
    expect(fitWithin(4000, 3000, 1600)).toEqual({ width: 1600, height: 1200, scaled: true });
    expect(fitWithin(3000, 4000, 1600)).toEqual({ width: 1200, height: 1600, scaled: true });
    expect(fitWithin(10000, 10, 1600)).toEqual({ width: 1600, height: 2, scaled: true });
    expect(fitWithin(100000, 1, 1600).height).toBe(1);
  });
});

describe("error mapping", () => {
  it("camera: structured codes, legacy messages, and passthrough", () => {
    expect(classifyCameraError({ code: "OS-PLUG-CAMR-0006", message: "x" }, "camera")).toBeInstanceOf(CancelledError);
    expect(classifyCameraError({ code: "OS-PLUG-CAMR-0020" }, "library")).toBeInstanceOf(CancelledError);
    expect(classifyCameraError(new Error("User cancelled photos app"), "camera")).toBeInstanceOf(CancelledError);
    const cam = classifyCameraError({ code: "OS-PLUG-CAMR-0003" }, "camera") as PermissionDeniedError;
    expect(cam).toBeInstanceOf(PermissionDeniedError);
    expect(cam.what).toBe("camera");
    expect(cam.message).toMatch(/Settings/);
    const photos = classifyCameraError({ code: "OS-PLUG-CAMR-0005" }, "library") as PermissionDeniedError;
    expect(photos.what).toBe("photos");
    expect((classifyCameraError(new Error("User denied access to camera"), "camera") as PermissionDeniedError).what).toBe("camera");
    expect(classifyCameraError({ code: "OS-PLUG-CAMR-0007" }, "camera")).toBeInstanceOf(UnsupportedError);
    const other = new Error("disk full");
    expect(classifyCameraError(other, "camera")).toBe(other);
    const already = new CancelledError();
    expect(classifyCameraError(already, "camera")).toBe(already);
  });
  it("scanner: cancel / denied / module missing", () => {
    expect(classifyScanError(new Error("scan canceled."))).toBeInstanceOf(CancelledError);
    expect((classifyScanError(new Error("User denied access to camera.")) as PermissionDeniedError).what).toBe("camera");
    expect(classifyScanError(new Error("The Google Barcode Scanner Module is not available. You must install it first"))).toBeInstanceOf(UnsupportedError);
    const other = new Error("camera busy");
    expect(classifyScanError(other)).toBe(other);
  });
  it("location: permission-denied detection", () => {
    expect(isLocationDenied({ code: 1, message: "User denied Geolocation" })).toBe(true);
    expect(isLocationDenied(new Error("Location permission was denied"))).toBe(true);
    expect(isLocationDenied({ code: 3, message: "Timeout expired" })).toBe(false);
    expect(isLocationDenied(new Error("Location services are not enabled"))).toBe(false);
  });
});

describe("links", () => {
  it("only http(s) links may be opened", () => {
    expect(assertWebUrl("https://maps.google.com/?q=1,2")).toBe("https://maps.google.com/?q=1,2");
    expect(() => assertWebUrl("javascript:alert(1)")).toThrow();
    expect(() => assertWebUrl("tel:123")).toThrow();
    expect(() => assertWebUrl("not a url")).toThrow();
  });
  it("tel: uri keeps a leading + and digits only", () => {
    expect(toTelUri("+63 917-123 4567")).toBe("tel:+639171234567");
    expect(toTelUri("(02) 8123 4567")).toBe("tel:0281234567");
    expect(() => toTelUri("call me")).toThrow();
  });
});
