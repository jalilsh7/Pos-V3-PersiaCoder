# PersiaCoder POS V3 — Repository Instructions

## Canonical project boundary
- This repository is the independent source of truth for the commercial PersiaCoder POS V3.
- Active application code lives under `v3/backend/`, `v3/frontend/`, and `database/v3/`.
- V3 validation scripts live under `scripts/check-v3-*` and `scripts/run-v3-local-ci.ps1`.
- Root product and architecture documents ending in `_V3` define the V3 domain and commercial scope.
- `legacy/v2-reference/` is an archival reference copied from the previous 3Food POS repository. It is not part of the V3 runtime, build graph, migration chain, or active CI. Do not import it into V3 without an explicit compatibility review.

## Architecture and runtime
- Backend: PHP 8.2+ with PDO/MySQL, namespaced under `PersiaCoder\\PosV3`.
- Database baseline and migrations: `database/v3/`; use the V3 CLI tools in `database/v3/bin/`.
- Frontend: independent React + TypeScript + Vite workspace in `v3/frontend/`.
- Never couple V3 to root-level V2 code, `legacy/v2-reference/`, or the former V2 PHP/MySQL runtime.
- V3 is designed for an independent customer database. Central licensing/control-plane data must not contain customers' operational sales data.
- Keep the product brand fixed as PersiaCoder.ir and preserve the footer text “Powered by PersiaCoder.ir”. Do not add white-label or brand-removal features.

## Security and data integrity
- Authentication and authorization must be enforced server-side. Never trust UI role checks as authorization.
- Keep tenant/business/branch scoping explicit in every data access path.
- Use prepared statements, strict input validation, CSRF and same-origin protections, secure session cookies, audit logs, and idempotency for retryable financial/order operations.
- Do not commit production credentials, real config files, API tokens, database passwords, private keys, or customer data.
- Do not store secrets in browser storage or expose them through API responses/logs.
- Financial, inventory, payment, and order effects must be transactional and auditable. Never infer payment success from timeouts or ambiguous terminal responses.
- Backup restore must retain integrity checks and guarded destination rules.

## Development and verification
- Frontend workspace: run commands from `v3/frontend/`. Current scripts are `npm run typecheck` and `npm run build`.
- A committed V3 frontend lockfile is still required before treating dependency installation as reproducible or switching CI to `npm ci`.
- Backend/database validation workflows are under `.github/workflows/v3-*.yml` and target this repository's `main` branch.
- Run the matching PHP checks and MySQL/MariaDB validation before claiming a change verified.
- A source file existing, a workflow being defined, or a commit being pushed does not prove that CI ran or passed.
- Report separately: code change, static checks, database integration, browser/E2E, artifact creation, staging acceptance, and production deployment.
- Do not claim a build/test/deploy succeeded unless the actual output confirms it.

## Change discipline
- Work in small, reviewable batches and preserve a clear commit history.
- Inspect callers/imports and V3 contracts before changing or removing code.
- Keep legacy files archived unless they are explicitly adapted into V3.
- Do not modify a live customer installation or production database during repository preparation.
- Keep Persian UI copy and RTL behavior intact; preserve the five primary right-to-left navigation entries and place secondary entries behind “نمایش بیشتر”.
