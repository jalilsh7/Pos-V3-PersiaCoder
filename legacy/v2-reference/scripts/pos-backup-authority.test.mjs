import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const store = readFileSync(new URL("../src/lib/pos/store.ts", import.meta.url), "utf8");
const app = readFileSync(new URL("../src/components/grill/app.tsx", import.meta.url), "utf8");

test("backup export and restore use the authoritative MySQL API", () => {
  assert.doesNotMatch(store, /importBackup/);
  assert.doesNotMatch(app, /importBackup|بازیابی\s*<input/);
  assert.match(app, /phpApi\.exportBackup\(\)/);
  assert.match(app, /phpApi\.restoreBackup\(backup\)/);
  assert.match(app, /بازیابی پشتیبان MySQL/);
  assert.match(app, /بازیابی فقط روی دیتابیس POS خالی/);
  assert.doesNotMatch(app, /usePos\(\(s\) => s\.exportBackup\)/);
});
