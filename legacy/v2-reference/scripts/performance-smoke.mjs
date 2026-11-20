#!/usr/bin/env node
import { chromium } from "playwright";

const url = process.argv[2] || process.env.POS_PERF_URL || "http://127.0.0.1:8099/";
const timeoutMs = Number(process.env.POS_PERF_TIMEOUT_MS || 30000);
const budgets = {
  javascriptBytes: 850 * 1024,
  cssBytes: 260 * 1024,
  totalAssetBytes: 1400 * 1024,
  firstContentfulPaintMs: 4000,
  largestContentfulPaintMs: 6000,
  domContentLoadedMs: 3500,
  longestTaskMs: 250,
};

const browser = await chromium.launch({
  headless: true,
  args: ["--no-sandbox", "--disable-dev-shm-usage"],
});

const results = [];
let failed = false;
try {
  for (const viewport of [
    { name: "desktop", width: 1280, height: 800 },
    { name: "mobile", width: 390, height: 844 },
  ]) {
    const page = await browser.newPage({ viewport: { width: viewport.width, height: viewport.height } });
    const pageErrors = [];
    page.on("pageerror", error => pageErrors.push(error.message));
    await page.addInitScript(() => {
      window.__posPerf = { lcp: 0, longTasks: [] };
      try {
        new PerformanceObserver(list => {
          for (const entry of list.getEntries()) window.__posPerf.lcp = entry.startTime;
        }).observe({ type: "largest-contentful-paint", buffered: true });
        new PerformanceObserver(list => {
          for (const entry of list.getEntries()) window.__posPerf.longTasks.push(entry.duration);
        }).observe({ type: "longtask", buffered: true });
      } catch { /* Performance observers are optional in unsupported browsers. */ }
    });

    const response = await page.goto(url, { waitUntil: "load", timeout: timeoutMs });
    await page.waitForTimeout(1200);
    const metrics = await page.evaluate(() => {
      const nav = performance.getEntriesByType("navigation")[0];
      const resources = performance.getEntriesByType("resource");
      const byType = type => resources
        .filter(item => item.initiatorType === type || (type === "css" && item.initiatorType === "stylesheet") || (type === "script" && /\.m?js(?:$|\?)/i.test(item.name)) || (type === "css" && /\.css(?:$|\?)/i.test(item.name)))
        .reduce((sum, item) => sum + (item.transferSize || item.encodedBodySize || 0), 0);
      const paint = name => performance.getEntriesByName(name)[0]?.startTime ?? null;
      return {
        domContentLoadedMs: Math.round(nav?.domContentLoadedEventEnd ?? 0),
        loadEventMs: Math.round(nav?.loadEventEnd ?? 0),
        firstContentfulPaintMs: Math.round(paint("first-contentful-paint") ?? 0),
        largestContentfulPaintMs: Math.round(window.__posPerf?.lcp ?? 0),
        javascriptBytes: byType("script"),
        cssBytes: byType("css"),
        totalAssetBytes: resources.reduce((sum, item) => sum + (item.transferSize || item.encodedBodySize || 0), 0),
        resourceCount: resources.length,
        longestTaskMs: Math.round(Math.max(0, ...(window.__posPerf?.longTasks ?? []))),
        horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        title: document.title,
      };
    });

    const checks = {
      httpOk: Boolean(response && response.ok()),
      ...Object.fromEntries(Object.entries(budgets).map(([key, limit]) => [key, metrics[key] <= limit])),
      noHorizontalOverflow: !metrics.horizontalOverflow,
      noPageErrors: pageErrors.length === 0,
    };
    const failedChecks = Object.entries(checks).filter(([, ok]) => !ok).map(([key]) => key);
    if (failedChecks.length) failed = true;
    results.push({ viewport: viewport.name, status: response?.status() ?? 0, metrics, budgets, checks, failedChecks, pageErrors });
    await page.close();
  }
} finally {
  await browser.close();
}

const report = {
  ok: !failed,
  testedAt: new Date().toISOString(),
  url,
  note: "Cold browser load on the CI runner; compare staging/production separately because host, cache and network affect timings.",
  results,
};
console.log(JSON.stringify(report, null, 2));
if (failed) process.exitCode = 1;
