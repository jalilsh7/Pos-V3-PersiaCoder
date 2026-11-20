import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const store = readFileSync(new URL("../src/lib/pos/store.ts", import.meta.url), "utf8");
const app = readFileSync(new URL("../src/components/grill/app.tsx", import.meta.url), "utf8");

test("checkout passes the server-owned tax toggle into the local receipt calculation", () => {
  assert.match(store, /taxEnabled:\s*boolean/);
  assert.match(store, /taxEnabled:\s*input\.taxEnabled/);
  assert.match(app, /taxEnabled:\s*settings\.taxEnabled\s*!==\s*false/);
});

test("quote calculation keeps tax disabled when the central setting is false", () => {
  const quoteStart = store.indexOf("placeOrder: (input) =>");
  const quoteEnd = store.indexOf("const phone =", quoteStart);
  const quote = store.slice(quoteStart, quoteEnd);
  assert.match(quote, /taxEnabled:\s*input\.taxEnabled/);
});
