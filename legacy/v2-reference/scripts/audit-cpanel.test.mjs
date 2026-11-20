import test from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const audit = fileURLToPath(new URL("./audit-cpanel.mjs", import.meta.url));

function fixture(run) {
  const root = mkdtempSync(join(tmpdir(), "3food-cpanel-audit-"));
  try {
    mkdirSync(join(root, "assets"));
    run(root);
    return spawnSync(process.execPath, [audit, root], { encoding: "utf8" });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}

test("cPanel audit accepts local-only static assets", () => {
  const result = fixture((root) => {
    writeFileSync(join(root, "index.html"), '<script type="module" src="/assets/app.js"></script>');
    writeFileSync(join(root, "assets", "app.js"), 'fetch("/api/v1/health")');
    writeFileSync(join(root, "assets", "app.css"), 'body { color: #123; }');
  });
  assert.equal(result.status, 0, result.stderr);
});

test("cPanel audit rejects Grok platform markers", () => {
  const result = fixture((root) => {
    writeFileSync(join(root, "index.html"), '<script type="module" src="/assets/app.js"></script>');
    writeFileSync(join(root, "assets", "app.js"), 'const host = "https://grok.com";');
  });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /forbidden platform marker/i);
});

test("cPanel audit rejects remotely hosted HTML assets", () => {
  const result = fixture((root) => {
    writeFileSync(join(root, "index.html"), '<script src="https://example.com/app.js"></script>');
  });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /remote script\/stylesheet asset reference/i);
});

test("cPanel audit rejects remote CSS resources", () => {
  const result = fixture((root) => {
    writeFileSync(join(root, "assets", "app.css"), 'body { background-image: url("https://cdn.example.com/bg.png"); }');
  });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /remote CSS resource URL/i);
});

test("cPanel audit rejects remote browser runtime requests", () => {
  const result = fixture((root) => {
    writeFileSync(join(root, "assets", "app.js"), 'fetch("https://api.example.com/data")');
  });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /remote browser runtime request/i);
});
