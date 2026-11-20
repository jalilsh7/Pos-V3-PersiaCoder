import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const app = readFileSync(new URL("../src/components/grill/app.tsx", import.meta.url), "utf8");

test("order transitions refresh authoritative inventory and loyalty snapshot", () => {
  const jumpStart = app.indexOf("async function jump(order: Order");
  const jumpEnd = app.indexOf("\n  return (", jumpStart);
  assert.ok(jumpStart >= 0 && jumpEnd > jumpStart, "order transition handler exists");
  const jump = app.slice(jumpStart, jumpEnd);
  assert.match(jump, /await phpApi\.setOrderStatus/);
  assert.match(jump, /await phpApi\.bootstrap<ServerBootstrap>\(\)/);
  assert.match(jump, /hydratePosFromServer\(snapshot\.data, snapshot\.next_order, role\)/);
});
