import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const rules = readFileSync(
  new URL("../php-backend/public/.htaccess", import.meta.url),
  "utf8",
);

test("PHP API rewrite is evaluated before the React SPA fallback", () => {
  const apiRule = rules.indexOf("RewriteRule ^api(?:/.*)?$ api/index.php");
  const spaRule = rules.indexOf("RewriteRule ^ index.html");
  assert.notEqual(apiRule, -1, "API front-controller rewrite must exist");
  assert.notEqual(spaRule, -1, "SPA fallback must exist");
  assert.match(rules, /DirectoryIndex index\.html/);
  assert.ok(apiRule < spaRule, "API rewrite must precede SPA fallback");
});

test("SPA fallback is guarded and only activates when index.html exists", () => {
  const spaRule = rules.indexOf("RewriteRule ^ index.html");
  const beforeRule = rules.slice(0, spaRule);
  assert.match(beforeRule, /RewriteCond %\{REQUEST_FILENAME\} !-f/);
  assert.match(beforeRule, /RewriteCond %\{REQUEST_FILENAME\} !-d/);
  assert.match(beforeRule, /RewriteCond %\{DOCUMENT_ROOT\}\/index\.html -f/);
});

test("API rewrite is also guarded against existing files and directories", () => {
  const apiRule = rules.indexOf("RewriteRule ^api(?:/.*)?$ api/index.php");
  const beforeRule = rules.slice(0, apiRule);
  assert.match(beforeRule, /RewriteCond %\{REQUEST_FILENAME\} !-f/);
  assert.match(beforeRule, /RewriteCond %\{REQUEST_FILENAME\} !-d/);
});
