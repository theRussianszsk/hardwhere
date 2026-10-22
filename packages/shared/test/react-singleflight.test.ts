import { describe, expect, it } from "vitest";
import { singleFlight } from "../src/react/singleFlight";

describe("singleFlight (outbox kick)", () => {
  it("does not get stuck after an early-returning run (the offline / signed-out case)", async () => {
    let calls = 0;
    const kick = singleFlight(async () => { calls++; /* early return, no await */ });
    await kick();
    await kick();
    await kick();
    expect(calls).toBe(3); // the old implementation ran once and then returned a stale resolved promise forever
  });

  it("coalesces concurrent calls into one run", async () => {
    let calls = 0;
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const kick = singleFlight(async () => { calls++; await gate; });
    const a = kick();
    const b = kick();
    expect(b).toBe(a);
    release();
    await a;
    expect(calls).toBe(1);
    await kick();
    expect(calls).toBe(2); // a new run is allowed once the first settled
  });

  it("recovers after a failing run", async () => {
    let n = 0;
    const kick = singleFlight(async () => { if (n++ === 0) throw new Error("boom"); });
    await expect(kick()).rejects.toThrow("boom");
    await expect(kick()).resolves.toBeUndefined();
  });
});
