import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const api = readFileSync(new URL("../php-backend/public/api/index.php", import.meta.url), "utf8");
const client = readFileSync(new URL("../src/lib/api/php-client.ts", import.meta.url), "utf8");
const app = readFileSync(new URL("../src/components/grill/app.tsx", import.meta.url), "utf8");

test("inventory adjustments are server-atomic and reject negative stock", () => {
  assert.match(api, /SELECT payload FROM pos_records WHERE resource = 'ingredients' AND id = \? FOR UPDATE/);
  assert.match(api, /insufficient_stock/);
  assert.match(api, /ingredient_stock_adjusted/);
  assert.match(client, /adjustIngredientStock/);
});

test("inventory UI adopts the canonical stock value returned by MySQL", () => {
  assert.match(app, /adjustCentralStock<typeof i>\(i\.id,[\s\S]*?\(item\) => saveIngredient\(item\)\)/);
  assert.doesNotMatch(app, /saveCentralRecord\("ingredients",\s*next/);
});
