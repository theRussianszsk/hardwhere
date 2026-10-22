// jeep-sqlite (browser SQLite for dev/tests) loads sql.js's wasm from /assets. Native builds do not use it.
import { copyFileSync, mkdirSync, existsSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const src = [resolve(here, "../../../node_modules/sql.js/dist/sql-wasm.wasm"), resolve(here, "../node_modules/sql.js/dist/sql-wasm.wasm")].find(existsSync);
if (!src) throw new Error("sql.js wasm not found; run `npm install` at the repo root");
const dest = resolve(here, "../public/assets/sql-wasm.wasm");
mkdirSync(dirname(dest), { recursive: true });
copyFileSync(src, dest);
console.log("copied sql-wasm.wasm -> public/assets");
