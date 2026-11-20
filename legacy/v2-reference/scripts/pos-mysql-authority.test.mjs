import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const store = readFileSync(new URL("../src/lib/pos/store.ts", import.meta.url), "utf8");
const app = readFileSync(new URL("../src/components/grill/app.tsx", import.meta.url), "utf8");

test("POS business state is not persisted to browser storage", () => {
  assert.doesNotMatch(store, /zustand\/middleware|\bpersist\s*\(/);
  assert.match(store, /categories:\s*\[\]/);
  assert.match(store, /products:\s*\[\]/);
  assert.match(store, /orders:\s*\[\]/);
  assert.match(store, /nextOrder:\s*1/);
  assert.doesNotMatch(store, /resetDemo/);
  assert.doesNotMatch(app, /بازگشت به نمونه/);
});

test("each role bootstrap replaces collections, including empty collections", () => {
  for (const collection of ["categories", "products", "ingredients", "customers", "discounts", "expenses", "templates"]) {
    assert.match(app, new RegExp(collection + ": data\\." + collection + " \\?\\? \\[\\]"));
  }
  assert.match(app, /orders,\s*settings:/);
  assert.doesNotMatch(app, /if \(!hasRecords/);
});

test("legacy browser data remains available for an explicit one-time MySQL import", () => {
  assert.match(app, /getItem\("grillcash-v1"\)/);
  assert.match(app, /removeItem\("grillcash-v1"\)/);
  assert.match(app, /phpApi\.importLegacyData/);
  assert.match(app, /smsUser: "",\s*smsKey: "",\s*smsFrom: ""/);
});
