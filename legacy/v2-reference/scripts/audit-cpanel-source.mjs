#!/usr/bin/env node
/**
 * Audit the actual browser entrypoint's transitive source imports.
 * Legacy server/auth modules may remain as migration references, but they must
 * never enter the standalone cPanel browser graph.
 */
import { existsSync, readFileSync } from "node:fs";
import { dirname, extname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const entry = resolve(repoRoot, process.argv[2] ?? "src/main.tsx");
const extensions = [".ts", ".tsx", ".js", ".jsx", ".mjs", ".css", ".json"];
const forbidden = [
  /grok(?:-sandbox)?\.com/i,
  /grok\.me/i,
  /app-builder/i,
  /PreviewHostBridge/i,
  /__grok/i,
  /x-grok-identity/i,
  /grok-auth\.bearer-token/i,
];
const visited = new Set();
const failures = [];

function resolveImport(from, specifier) {
  if (!specifier.startsWith(".") && !specifier.startsWith("@/")) return null;
  const base = specifier.startsWith("@/")
    ? resolve(repoRoot, "src", specifier.slice(2))
    : resolve(dirname(from), specifier);
  const candidates = [base, ...extensions.map((ext) => base + ext), ...extensions.map((ext) => resolve(base, "index" + ext))];
  return candidates.find((candidate) => existsSync(candidate) && extname(candidate) !== "") ?? null;
}

function importsOf(source) {
  const found = [];
  const staticImport = /^\s*(?:import|export)\s+(?:[\s\S]*?\s+from\s*)?["']([^"']+)["']/gm;
  const dynamicImport = /\bimport\(\s*["']([^"']+)["']\s*\)/g;
  for (const regex of [staticImport, dynamicImport]) {
    for (const match of source.matchAll(regex)) found.push(match[1]);
  }
  return found;
}

function visit(path) {
  if (visited.has(path)) return;
  visited.add(path);
  const source = readFileSync(path, "utf8");
  const relative = path.slice(repoRoot.length + 1);
  if (/\.server\.[cm]?[jt]sx?$/.test(path)) {
    failures.push(relative + ": server-only module entered browser import graph");
  }
  for (const pattern of forbidden) {
    if (pattern.test(source)) {
      failures.push(relative + ": forbidden platform marker " + pattern);
      break;
    }
  }
  for (const specifier of importsOf(source)) {
    const target = resolveImport(path, specifier);
    if (target) visit(target);
    else if (specifier.startsWith(".") || specifier.startsWith("@/")) {
      failures.push(relative + ": unresolved local import " + specifier);
    }
  }
}

if (!existsSync(entry)) {
  console.error("[cpanel-source-audit] Entry file not found: " + entry);
  process.exit(2);
}

visit(entry);
if (failures.length) {
  console.error("[cpanel-source-audit] Browser source graph check failed:");
  for (const failure of failures) console.error(" - " + failure);
  process.exit(1);
}
console.log("[cpanel-source-audit] Passed: " + visited.size + " browser source files are free of Grok/App Builder coupling and server-only imports.");
