# 3Food POS — repository instructions

## Deployment architecture
- This repository's deployment target is shared cPanel hosting.
- The browser application is a static React/Vite SPA built from `src/main.tsx`.
- The backend is the same-origin PHP API under `php-backend/public/api`, served at `/api/v1`.
- The production data store is MySQL. Do not reintroduce Neon, PostgreSQL, PGLite, Better Auth, TanStack Start server functions, or a hosted authentication broker into the cPanel runtime.
- Authentication is handled by the PHP API with secure server-side sessions. Keep session cookies HttpOnly; keep CSRF state in memory; never persist passwords, session IDs, setup tokens, CSRF tokens, or API secrets in browser storage.

## Platform independence
- Do not add Grok, App Builder, PreviewHostBridge, preview-host identity, injected scripts, platform OAuth, or platform-specific runtime dependencies.
- Do not load scripts, stylesheets, fonts, or application code from third-party CDNs. Bundle required assets locally.
- Optional integrations (such as the configured SMS provider) must remain opt-in, server-side, and disabled unless explicitly configured by the operator.
- Keep legacy migration/reference code out of the active browser import graph. The `build:cpanel` command audits that graph and the generated output.

## Build and verification
- Use `npm ci` for reproducible dependency installation.
- Use `npm run typecheck`, `npm test`, and `npm run lint` before declaring source changes verified.
- Use `npm run build:cpanel` to create and audit the standalone cPanel deployment package.
- PHP changes must pass PHP syntax checks and the MySQL/API integration checks in `.github/workflows/php-migration-checks.yml`.
- Never claim tests, browser checks, or a production deployment succeeded unless the corresponding tool output confirms it.

## Data and deployment safety
- Never request or commit production credentials, `private/config.php`, setup tokens, app keys, or database passwords.
- Do not change the live cPanel files or production database as part of repository cleanup.
- Preserve the PHP API contract, database migrations, role-based authorization, backup/restore safeguards, and order/stock transaction invariants.
- Do not delete migrations or historical data paths merely because they are not part of the browser bundle.
- Keep generated route metadata aligned with the actual files under `src/routes`; the cPanel app is a client-rendered SPA, not a TanStack Start server application.

## Change discipline
- Prefer small, reviewable commits on a dedicated branch.
- Trace imports and callers before deleting modules or dependencies.
- When a dependency is only used by isolated legacy tooling, prove that with the source graph and test/build results before removing it.
- Keep Persian UI copy and right-to-left layout intact.
