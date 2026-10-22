import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryTokenStore, createSecureTokenStore } from "../src/session";

describe("token stores", () => {
  it("memory store round-trips and is not protected", async () => {
    const s = createMemoryTokenStore();
    expect(s.protected).toBe(false);
    expect(await s.get()).toBeNull();
    await s.set("abc");
    expect(await s.get()).toBe("abc");
    await s.clear();
    expect(await s.get()).toBeNull();
  });

  describe("secure store on the web (dev) platform", () => {
    const mk = () => { const m = new Map<string, string>(); return { m, api: { getItem: (k: string) => m.get(k) ?? null, setItem: (k: string, v: string) => void m.set(k, v), removeItem: (k: string) => void m.delete(k) } }; };
    let session: ReturnType<typeof mk>, local: ReturnType<typeof mk>;
    beforeEach(() => { session = mk(); local = mk(); vi.stubGlobal("sessionStorage", session.api); vi.stubGlobal("localStorage", local.api); });
    afterEach(() => { vi.unstubAllGlobals(); });

    it("uses sessionStorage only, reports protected:false, never localStorage", async () => {
      const s = createSecureTokenStore({ keyPrefix: "hw_vendor" });
      expect(s.protected).toBe(false);
      await s.set("tok");
      expect(await s.get()).toBe("tok");
      expect([...session.m.keys()]).toEqual(["hw_vendor:api_token"]);
      expect(local.m.size).toBe(0);
      await s.clear();
      expect(await s.get()).toBeNull();
    });
    it("a blocked sessionStorage reads as no token instead of throwing", async () => {
      vi.stubGlobal("sessionStorage", { getItem: () => { throw new Error("blocked"); } });
      expect(await createSecureTokenStore({ keyPrefix: "x" }).get()).toBeNull();
    });
  });
});
