#!/usr/bin/env node
/**
 * Verify the deployable cPanel frontend is not coupled to the app-builder host.
 * This intentionally audits generated output (not the legacy source archive).
 */
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, resolve, extname } from "node:path";

const root = resolve(process.argv[2] ?? "dist");
const forbidden = [
  /grok(?:-sandbox)?\.com/i,
  /grok\.me/i,
  /app-builder/i,
  /PreviewHostBridge/i,
  /__grok/i,
  /x-grok-identity/i,
  /grok-auth\.bearer-token/i,
];
const externalHtmlAsset = /<(?:script|link)\b[^>]*\b(?:src|href)\s*=\s*["']https?:\/\//i;
const externalCssImport = /@import\s+(?:url\()?\s*["']?https?:\/\//i;
const externalCssUrl = /url\(\s*["']?(?:https?:)?\/\//i;
const externalJsRequest = /\b(?:fetch|WebSocket|EventSource)\s*\(\s*["'](?:https?:)?\/\//i;
const files = [];

function walk(dir) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) walk(path);
    else files.push(path);
  }
}

if (!statSync(root, { throwIfNoEntry: false })?.isDirectory()) {
  console.error("[cpanel-audit] Build directory not found: " + root);
  process.exit(2);
}

walk(root);
const failures = [];
for (const path of files) {
  const ext = extname(path).toLowerCase();
  if (![".html", ".js", ".mjs", ".css", ".json", ".svg", ".webmanifest"].includes(ext)) continue;
  const source = readFileSync(path, "utf8");
  const relative = path.slice(root.length + 1);
  if (externalHtmlAsset.test(source)) failures.push(relative + ": remote script/stylesheet asset reference");
  if (ext === ".css" && externalCssImport.test(source)) failures.push(relative + ": remote CSS import");
  if (ext === ".css" && externalCssUrl.test(source)) failures.push(relative + ": remote CSS resource URL");
  if ([".js", ".mjs"].includes(ext) && externalJsRequest.test(source)) failures.push(relative + ": remote browser runtime request");
  for (const pattern of forbidden) {
    if (pattern.test(source)) {
      failures.push(relative + ": forbidden platform marker " + pattern);
      break;
    }
  }
}

if (failures.length) {
  console.error("[cpanel-audit] Standalone build check failed:");
  for (const failure of failures) console.error(" - " + failure);
  process.exit(1);
}
console.log("[cpanel-audit] Passed: " + files.length + " generated files contain no Grok integration, remote browser assets, or remote runtime requests.");
