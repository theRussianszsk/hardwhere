import { describe, expect, it } from "vitest";
import {
  clampQty, confidenceLevel, confidencePercent, connectionBanner, formatStoreHours, MATCH_META, STATUS_META, STOCK_META, validatePeso, type ConnectionState,
} from "../src/ui/logic";

const NOW = Date.parse("2026-03-01T12:00:00Z");
const calm: ConnectionState = { deviceOnline: true, serverReachable: true, stale: false, lastSyncedAt: "2026-03-01T11:59:00Z", syncing: false, queuedCount: 0 };

describe("connection banner: one banner, most important first", () => {
  it("nothing when everything is fine", () => {
    expect(connectionBanner(calm, NOW)).toBeNull();
    expect(connectionBanner({ ...calm, serverReachable: null }, NOW)).toBeNull(); // never probed is not a problem by itself
  });
  it("offline beats everything and mentions unsent work + data age", () => {
    const b = connectionBanner({ ...calm, deviceOnline: false, serverReachable: false, syncing: true, queuedCount: 3, stale: true, errorMessage: "x" }, NOW)!;
    expect(b.kind).toBe("offline");
    expect(b.title).toBe("You're offline");
    expect(b.detail).toContain("3 changes");
    expect(b.detail).toContain("Last updated 1 min ago");
    expect(b.retry).toBeNull();
  });
  it("device online but server unreachable is a different banner from offline", () => {
    const b = connectionBanner({ ...calm, serverReachable: false, queuedCount: 1 }, NOW)!;
    expect(b.kind).toBe("server");
    expect(b.title).toMatch(/server/i);
    expect(b.detail).toContain("1 change saved");
    expect(b.retry).toBe("Try again");
  });
  it("precedence below that: error > syncing > queued > stale", () => {
    expect(connectionBanner({ ...calm, errorMessage: "Sync failed", syncing: true, queuedCount: 2, stale: true }, NOW)!.kind).toBe("error");
    expect(connectionBanner({ ...calm, syncing: true, queuedCount: 2, stale: true }, NOW)!.kind).toBe("syncing");
    expect(connectionBanner({ ...calm, queuedCount: 2, stale: true }, NOW)!.kind).toBe("queued");
    const stale = connectionBanner({ ...calm, stale: true, lastSyncedAt: "2026-03-01T11:30:00Z" }, NOW)!;
    expect(stale.kind).toBe("stale");
    expect(stale.detail).toBe("Last updated 30 min ago.");
    expect(connectionBanner({ ...calm, stale: true, lastSyncedAt: null }, NOW)!.detail).toBe("Not updated on this phone yet.");
  });
  it("singular wording", () => {
    expect(connectionBanner({ ...calm, queuedCount: 1 }, NOW)!.title).toBe("1 change waiting to send");
  });
});

describe("confidence + match wording", () => {
  it("thresholds and rounding, junk input is low", () => {
    expect(confidenceLevel(0.85)).toBe("high");
    expect(confidenceLevel(0.8499)).toBe("medium");
    expect(confidenceLevel(0.6)).toBe("medium");
    expect(confidenceLevel(0.59)).toBe("low");
    expect(confidenceLevel(Number.NaN)).toBe("low");
    expect(confidencePercent(0.923)).toBe(92);
    expect(confidencePercent(1.7)).toBe(100);
    expect(confidencePercent(-1)).toBe(0);
  });
  it("every match class has plain wording", () => {
    expect(Object.values(MATCH_META).map((m) => m.label)).toEqual(["Exact match", "Likely same product", "Likely variant", "Possible duplicate", "New product", "Not enough confidence", "Not a hardware product"]);
  });
  it("stock buckets", () => {
    expect(Object.values(STOCK_META).map((m) => m.label)).toEqual(["In stock", "Low stock", "Out of stock"]);
  });
});

describe("status pills", () => {
  it("every state has distinct wording, and no two states share the same look (colour + icon + solid)", () => {
    const entries = Object.entries(STATUS_META);
    const labels = entries.map(([, m]) => m.label);
    expect(new Set(labels).size).toBe(labels.length);
    for (const k of ["draft", "queued", "sending", "submitted", "reserved", "confirmed", "preparing", "ready_for_pickup", "fulfilled", "cancelled", "expired", "rejected", "needs_review", "failed"]) expect(STATUS_META).toHaveProperty(k);
    // "pending" is the order-side twin of "submitted" and intentionally shares its look; everything else must differ.
    const looks = entries.filter(([k]) => k !== "pending").map(([, m]) => `${m.color}/${m.icon}/${m.solid ?? ""}`);
    expect(new Set(looks).size).toBe(looks.length);
    expect(STATUS_META.ready_for_pickup.label).toBe("Ready for pickup");
    expect(STATUS_META.needs_review.label).toBe("Needs review");
  });
});

describe("peso + quantity input rules", () => {
  it("parses and validates", () => {
    expect(validatePeso("1,250.50")).toEqual({ cents: 125050, error: null });
    expect(validatePeso("")).toEqual({ cents: null, error: null });
    expect(validatePeso("", { required: true }).error).toBe("Enter an amount.");
    expect(validatePeso("12abc").error).toMatch(/like 1,250/);
    expect(validatePeso("0.99", { min: 100 }).error).toBe("Must be at least ₱1.00.");
    expect(validatePeso("999999", { max: 100_000 }).error).toMatch(/or less/);
    expect(validatePeso("₱ 5").cents).toBe(500);
  });
  it("clampQty", () => {
    expect(clampQty(5, 0, 3)).toBe(3);
    expect(clampQty(-2, 1)).toBe(1);
    expect(clampQty(Number.NaN, 2)).toBe(2);
    expect(clampQty(2.9, 0)).toBe(2);
  });
});

describe("store hours", () => {
  it("formats the API's hours object", () => {
    expect(formatStoreHours({ "mon-sat": ["08:00", "18:00"], sun: ["09:00", "15:00"] })).toEqual(["Mon–Sat 8:00 AM – 6:00 PM", "Sun 9:00 AM – 3:00 PM"]);
    expect(formatStoreHours({ mon: ["00:00", "12:30"] })).toEqual(["Mon 12:00 AM – 12:30 PM"]);
    expect(formatStoreHours({})).toEqual([]);
    expect(formatStoreHours(null)).toEqual([]);
    expect(formatStoreHours({ sun: [] })).toEqual([]);
  });
});
