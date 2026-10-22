// jeep-sqlite (browser SQLite for dev/tests) loads sql.js's wasm from /assets. Native builds do not use it.
import { copyFileSync, mkdirSync, existsSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
// jeep-sqlite 2.8.x bundles sql.js 1.12-era JS: the wasm from the newer root sql.js (1.15) has 5 extra imports and fails to link
// ("Import #34 a.I requires a callable"). Prefer the pinned, compatible wasm kept in packages/shared/assets (one copy for both apps).
const src = [resolve(here, "../../../packages/shared/assets/sql-wasm-1.12.0.wasm"), resolve(here, "../../../node_modules/sql.js/dist/sql-wasm.wasm"), resolve(here, "../node_modules/sql.js/dist/sql-wasm.wasm")].find(existsSync);
if (!src) throw new Error("sql.js wasm not found; run `npm install` at the repo root");
const dest = resolve(here, "../public/assets/sql-wasm.wasm");
mkdirSync(dirname(dest), { recursive: true });
copyFileSync(src, dest);
console.log("copied sql-wasm.wasm -> public/assets");
