import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const source = await readFile(new URL("../src/lib/api/php-client.ts", import.meta.url), "utf8");

test("only an unauthorized session response clears the in-memory CSRF token", () => {
  assert.match(source, /response\.status === 401 && code === "unauthorized"\) csrfToken = null/);
  assert.doesNotMatch(source, /if \(response\.status === 401\) csrfToken = null/);
});

test("auth/me normalizes the password-change flag from boolean or MySQL numeric values", () => {
  assert.match(source, /flag === true \|\| flag === 1 \|\| flag === "1"/);
});
