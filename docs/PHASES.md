# GoldenHR — Implementation Phase Plan

Status: **PLAN ONLY — no implementation started.**
Authored against: frontend `b008040`, backend `b244f4e`, VPS `169.58.130.142`.
Companion doc: `docs/ERD.md`.

---

## 0. Verified server facts

Checked live before planning — these constrain the design:

| Fact | Value |
|---|---|
| Container | `hr-api`, php:8.3-fpm-alpine + nginx + supervisord |
| Bind mounts | **Work** (tested with alpine) → real file storage is possible |
| Writable by `victor` | `/home/victor`, `/srv/victor`, `/tmp` |
| Current DB | **SQLite** in Docker volume `backendhr_api-storage` |
| `pdo_sqlite` | installed |
| `gd` | **NOT installed** — blocks image resize/thumbnails |
| `zip` | **NOT installed** — blocks PDF assembly |
| `intl`, `fileinfo`, `mbstring` | installed |
| Free disk | 36 GB |
| Live API | `https://hr-api.goldencreations.online` → `172.18.0.1:3001` |
| Live frontend | `https://hr.goldencreations.online` → static dir `/srv/victor/hr.goldencreations.online` |

**Frontend audit** (`app/page.tsx`, 2105 lines):
- **17 `window.print()` sites** — payslips (642, 646, 669, 704), contracts (875, 880, 896), documents (738, 943), registration (1263), receipts (1077, 1088, 1157), reports (1615, 1702, 1727, 1888).
- **1 fake download** at `page.tsx:974` — builds a `text/plain` Blob named `*.txt`. Not a real file.
- **4 file inputs, all uncontrolled** — `246` (profile image), `247` (CV), `248` (employee document), `1199` (reg photo). No `onChange`, no `name`, no handler → **uploads currently do nothing**.
- 2 `@media print` rules exist (`globals.css:69, 108`) — enough to hide chrome, not enough for branded output.
- Zero `fetch`/`axios`/`process.env`.

---

## Phase 0 — Foundations *(blocking everything)*

1. **Commit `docs/ERD.md`** to `backendHR` and push.
2. **Commit deploy scaffolding** — `Dockerfile`, `compose.yml`, `docker/*`, `.dockerignore` currently exist only on the VPS. A clean clone cannot rebuild without them.
3. **Commit `output: 'export'`** to `goldenHR/next.config.mjs` — still uncommitted server-side.
4. **Decide the database engine.** *This gates Phase 2.*

### The database decision

`ERD.md` uses Postgres-only features:

| Feature | Where | SQLite |
|---|---|---|
| Generated columns (`gross_minor`, `net_minor`) | `payslips` | ✗ |
| Partial unique index (`WHERE is_current`) | `employee_assignments` | ✗ |
| `jsonb` settings | `settings` | ✗ |
| `FILTER (WHERE …)` in reports | `reportData` aggregates | ✗ |
| `DEFERRABLE` FK (users↔employees cycle) | §3 of ERD | ✗ |

**Option A (recommended):** run Postgres in a second container on the same compose network. No sudo needed, same pattern as the existing stack. Adds ~400 MB RAM (server has 7.8 GB).
**Option B:** stay on SQLite; compute generated values in a service layer, drop partial indexes, use TEXT for JSON. Faster to ship, weaker guarantees on payroll integrity.

> Payroll is financial data. I lean **A** — but this is your call, and it changes Phase 2 substantially.

**Decision needed before Phase 2 proceeds.**

---

## Phase 1 — Database schema

Create all 24 tables from `ERD.md`. Migrations in dependency order (ERD §4.1).

**Core** — `users`, `employees`, `departments`, `department_leads`, `job_roles`, `employee_assignments`
**Documents** — `contracts`, `contract_types`, `employee_documents`, `document_categories`
**Leave** — `leave_requests`, `leave_types`, `leave_balances`
**Payroll** — `payroll_runs`, `payslips`
**Money** — `money_requests`, `money_request_types`
**Other** — `bank_accounts`, `attendance_records`, `goals`, `notifications`, `notification_recipients`, `settings`

**Exit criteria:** `php artisan migrate` clean on the VPS; `migrate:fresh` reproducible; ERD table list matches `Schema::getTables()` output exactly.

---

## Phase 2 — Authentication

`users` + Sanctum token auth (already installed via Laravel 12).

- `POST /api/auth/login` → token, user, linked `employee`
- `POST /api/auth/logout`, `GET /api/auth/me`, `PUT /api/auth/password`
- Roles: `hr_admin`, `hr_officer`, `employee` — middleware guards per ERD §5
- Seed the first `hr_admin` (Jordan Davis) from an env var, not a hardcoded password

**Exit criteria:** real login works end-to-end from a deployed frontend build; a wrong password returns 422 with no user-enumeration leak.

---

## Phase 3 — File storage & media pipeline

**Image analysis of your requirement:** currently 4 uncontrolled inputs, so nothing is stored. This phase makes upload → disk → URL → DB → render actually work.

1. **Storage driver** — bind-mount a host dir (`/srv/victor/hr-files`) into the container. Tested working. Proposed layout:
   ```
   /srv/victor/hr-files/{employees,contracts,documents}/
     {yyyy}/{mm}/{uuid}.{ext}
   ```
2. **DB stores relative path + metadata** — `employee_documents.disk_path`, `mime_type`, `size_bytes`. Never store an absolute path or a public URL; build URLs at response time so the domain can change.
3. **Serving route** — `GET /api/files/{id}` → authenticated stream. **Not** a raw static path: documents are confidential ("Only HR administrators and {name} can access these files" — `page.tsx:1021`) and must be access-checked per request.
4. **Install `gd`** (image resize + thumbnails) and `zip` (PDF assembly) — both currently missing.
5. **Validation** — allowlist mime types, cap size, reject double extensions, randomise filenames.
6. **Serve `employee_documents`** into the Documents hub (UI expects `name`, `category`, `type`, `size`, `added`, `owner`, `status`) and into Registration Info.

**Exit criteria:** upload a real JPEG/PDF from the deployed UI; the image renders at `Registration Info`; the file downloads back byte-identical; an unrelated user is refused.

---

## Phase 4 — Employee CRUD & dashboards

- `GET/POST/PATCH/DELETE /api/employees`, search + filters (the UI filters by name/role/department — `page.tsx:271`)
- Departments, roles, assignments (bulk role-add parses newline/comma — `page.tsx:335`)
- `GET /api/dashboard` feeding `HrDashboard` (2017) and `EmployeeDashboard` (1766)
- Reports endpoint replacing `reportData` aggregates (ERD §4.3)

**Exit criteria:** add an employee with a password → that employee can log in → appears in the directory, dashboard counts and reports.

---

## Phase 5 — Domain modules

Each replaces one hardcoded array:

| Module | Replaces | Line |
|---|---|---|
| Leave (request → approve/decline, balances) | `initialLeaveRequests` | 413 |
| Payroll (runs, payslips, approve, pay) | `initialPayroll` | 517 |
| Contracts (draft, send, renew, sign) | `seededContracts` | 785 |
| Money requests (limits, approve, receipt) | `initialMoneyRequests` | 1042 |
| Notifications (replace the pub/sub at 1303) | `noticeStore` | 1303 |
| Bank accounts, attendance, goals | `workedDays`, `goalProgress` | 1862 |

---

## Phase 6 — PDF export (17 sites)

**Today every "Download PDF" button is `window.print()`** — it opens the browser print dialog against a page that may still show other panels. The `page.tsx:974` "download" produces a `.txt` file. None of it is a real PDF.

Two approaches:

**A. Server-rendered (recommended)** — Blade/PDF views + `dompdf` or `barryvdh/laravel-pdf` + `zip`. Pros: real branded PDF, official filename, auditable, downloadable without a browser. Cons: `dompdf` needs `zip` + `gd` (both missing today), and the `GoldMark` SVG logo (line 81) needs an inline-embedded asset for headers.

**B. Client-side** — `html2canvas` + `jsPDF`, or browser print with improved `@media print`. Pros: no server work, reuses existing markup. Cons: output quality varies by browser, fonts and SVG gradients often break, and nothing is stored server-side.

**Documents to produce** (8 distinct artifacts across the 17 buttons):

| Document | Sites |
|---|---|
| Payslip | 642, 646, 669, 704 |
| Contract | 875, 880, 896 |
| Document preview / export | 738, 943 |
| Money receipt | 1077, 1088, 1157 |
| Registration profile | 1263 |
| Monthly payroll report | 1615 |
| Full reports | 1702, 1727 |
| Work progress analysis | 1888 |

**Decision needed:** A or B. If A, add `zip` + `gd` and confirm the logo asset approach.

---

## Phase 7 — CORS, routing & hardening

- Allow `https://hr.goldencreations.online` on `hr-api` (**not yet configured — the first cross-origin call will fail**)
- Frontend: replace the `useState('Dashboard')` ternary (2101) with real routes so URLs are shareable and refresh-safe
- Rate-limit auth + file endpoints; lock down the CORS allowlist

---

## Recommended order

`0 → 1 → 2 → 3 → 4 → 5 → 6 → 7`

Phases 2 and 3 are independent and can run in parallel once Phase 1 lands.

---

## Open questions

1. **Postgres or SQLite?** (gates Phase 2 — ask first)
2. **PDF: server-rendered or client-side?** (gates Phase 6)
3. **Circular FK** `users.employee_id` ↔ `employees.user_id` — persist one direction only; SQLite can't defer.
4. **Leave allowance: 18 or 21 days?** Mock data disagrees (ERD §2.2).
5. **Attendance source** — real device, manual entry, or leave hardcoded?
6. **`reportData` is demo-scale** (212→272 headcount) but only 4 employees exist. Keep as fixture, or regenerate from real aggregates?
7. **Employee image visibility** — all staff, or HR-only?