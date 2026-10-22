import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterAll } from "vitest";
import { createChangeBus, type ChangeEvent } from "../../src/contracts";
import { migrate } from "../../src/db/migrations";
import { createNodeSqlitePort, openFilePorts, type NodeSqlitePort } from "./nodePort";

export const T0 = Date.UTC(2026, 0, 1, 8, 0, 0);

/** Deterministic injectable clock. */
export function makeClock(start = T0) {
  let t = start;
  return { now: () => t, set: (ms: number) => { t = ms; }, advance: (ms: number) => { t += ms; } };
}

/** Temp directory for file-backed databases ("forced termination" tests); removed after the file's tests. */
export function useTmpDir() {
  const dir = mkdtempSync(join(tmpdir(), "hw-shared-"));
  afterAll(() => {
    // Tests leave the reopened "second process" database open; on Windows an open file cannot be deleted (EPERM).
    for (const p of openFilePorts) if (p.path.startsWith(dir)) p.kill();
    rmSync(dir, { recursive: true, force: true });
  });
  return (name: string) => join(dir, name);
}

/** In-memory (or file) database already migrated to the latest schema. */
export async function openDb(path = ":memory:"): Promise<NodeSqlitePort> {
  const port = createNodeSqlitePort(path);
  await migrate(port);
  return port;
}

export function recordBus() {
  const bus = createChangeBus();
  const events: ChangeEvent[] = [];
  bus.subscribe((e) => events.push(e));
  return { bus, events };
}

export const rows = <T = Record<string, unknown>>(port: NodeSqlitePort, sql: string, params: (string | number | null)[] = []) => port.all<T>(sql, params);
