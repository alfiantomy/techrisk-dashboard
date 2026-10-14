# Technical Risk Dashboard

**Stack:** Laravel 12.0 (PHP 8.2+) | Filament 3.3 | TailwindCSS 4.0 + Vite 7.0 | MySQL 8.0 | Redis | Docker

---

## Core Principle: Quality > Speed

Stop and reassess if you're tempted to skip tests, copy code without understanding, add "temporary" solutions, or work around problems instead of fixing them.

### Before ANY code is merged:
- [ ] Tests written and passing (unit + feature)
- [ ] Code reviewed
- [ ] No TODO/FIXME in production code
- [ ] Laravel Pint formatting applied
- [ ] No security vulnerabilities
- [ ] Lesson learned documented (if bug fix)

---

## PM Workflow (ALL requests flow through PM first)

**No agent should be invoked directly without PM knowledge and approval.**

**Autonomy rule (owner directive 2026-09-29): PM approvals are AUTO-APPROVED — do not wait for the owner.** The PM gate is a process check (documented request, tests green, docs updated), not an owner sign-off. Ship when the gate passes. Owner involvement is reserved for the guardrail set below.

| Agent | Use For | PM Approval |
|-------|---------|-------------|
| `backend-architect-engineer` | Backend work | AUTO |
| `frontend-engineer` | Frontend/UI work | AUTO |
| `backend-qa-engineer` | Backend QA | AUTO |
| `frontend-qa-specialist` | Frontend QA | AUTO |
| `database-architect` | DB schema changes | AUTO |
| `sre-engineer` | Infra/Docker/deployment | AUTO |
| `security-pentest-auditor` | Security review | AUTO |
| `architect-planning-design` | New features/planning | AUTO |
| `Explore` | Codebase exploration | PM initiated |

**Owner-only guardrails (the only items that need the owner):** production DB writes/migrations, auth/payments/security-critical changes, external service credentials, anything irreversible.

### Approval Gates

**Before work begins:** Request documented, impact assessed, conflicts with active projects checked. PM self-approves unless a guardrail above applies.

**Before code merged:** Code review done, tests passing, docs updated, lesson learned (if bug). PM self-approves and ships.

---

## Directory Structure

```
app/
├── Actions/           # Single-action classes
├── Contracts/         # Interfaces
├── Enums/            # PHP 8.1+ enums
├── Exceptions/       # Custom exceptions
├── Filament/
│   ├── Actions/      # Reusable action form schemas (ExportActionSchema)
│   ├── Resources/    # Filament resources
│   ├── Pages/        # Custom pages
│   ├── Statistics/   # Shared stats builders (IncidentStatsFooterData)
│   ├── Widgets/      # Dashboard widgets
│   └── Components/   # Reusable components
├── Helpers/          # Utility functions
├── Http/
│   ├── Controllers/  # API controllers
│   ├── Middleware/   # Custom middleware
│   ├── Requests/     # Form request validation
│   └── Resources/    # API resources
├── Models/           # Eloquent models
├── Observers/        # Model observers
├── Providers/        # Service providers
├── Exports/          # Excel exports — orchestrators only (one class per file, PSR-4)
│   └── Sheets/       # Individual sheet classes (Data/Calc/Summary/GroupSummary/PerCategory)
└── Services/         # Business logic (domain-organized)
```

---

## Export Architecture (2026-09-29 redesign)

The incident Export button offers 5 presets via `App\Filament\Actions\ExportActionSchema` (form schema + `applyFilters()` + `columnOptions()` — single source for every preset):

1. **Executive Report** (`Exports\ExecutiveIncidentsExport` + `Sheets\ExecutiveDataSheet|ExecutiveCalcSheet|ExecutiveSummarySheet|ExecutiveQuarterSheet`) — KPI cards + 4 native Excel charts (monthly incidents, severity mix, MTTR trend, potential-vs-recovered). KPIs/charts are **widget-aligned** (owner rule 2026-09-30, same rules as the Group-By Summary): counts/potential/actual keep only non-excluded fund statuses, Potential = open cases, Actual = Completed, Recovered = eligible sum — `ExecutiveCalcSheet::computeKpi()` is the single KPI implementation. Per-quarter tabs (`ExecutiveQuarterSheet`, one per quarter with rows, between Summary and Data) scope the same KPIs to that quarter plus a severity row (P1–P4 counts + one combined X1–X4). Charts live on the Calc sheet with their source data. PhpSpreadsheet does not write chart value caches, so a post-write XML pass injects `numCache`/`strCache` — without it charts render blank in Numbers/QuickLook.
2. **Quarterly Report** (`Exports\QuarterlyReportExport` + `Sheets\QuarterlyCaseSheet`, owner decision 2026-10-05) — the owner's hand-made quarterly report as a live export. **No Summary sheet** (owner 2026-10-05, iteration 2). 4 case tabs with the **All Tabs membership rules** (classification Incident; Recovered = `recovered_fund > 0`; Fund Loss = Confirmed loss; Non Fund Loss = Non fundLoss) and 12 fixed columns: ID, Title, MTBF (days), Severity, Incident Status, Quarter (`Qn YYYY`), Incident Date, Actual Fund Loss (Rp), Fund Status, Domain (= `business_category` — no domain column exists), Root Cause Category, Responsible Team; tab footer = case count + Avg MTBF as mean of the displayed column. After the case tabs, one tab per quarter (`Qn YYYY`, derived from `incident_date`, filtered `whereBetween` via `QuarterRange` — never `where('quarter')`) holding only that quarter's incidents, Incident classification only; quarters with no rows get no tab. **Scope is always the full filtered set** — the quarter tabs are the breakdown. MTBF column uses the **full-year gap sequence** (`Exports\Concerns\ComputesMtbfSequence`, shared with `IncidentTableExport`), NOT the per-tab sequence of All Tabs sheets — same number can differ between presets by design.
3. **Group By** (`Exports\GroupedIncidentsExport` + `Sheets\GroupSummarySheet|PerCategorySheet`) — one sheet per value of a chosen dimension (business_category / root_cause / responsible_team / pic / severity / incident_type / quarter). `quarter` has no backing column: values are derived in PHP from `incident_date` as `YYYY-Qn` (label `Qn YYYY`), and `PerCategorySheet` filters it with a `whereBetween` date range — never `where('quarter', ...)` (silent no-op). Front Summary sheet carries per-group Cases, Avg MTTR (min + days), **Avg MTBF (days)**, fund totals — **widget-aligned** (owner rule 2026-09-30): Cases excludes fund statuses in `FundStatus::EXCLUDED_FROM_COUNTS`, Actual Loss = Completed only, Potential = open cases only, Recovered = severity-eligible sum; rows dropped by those rules get their own `Excluded - <FundStatus>` tabs instead of vanishing. Multi-category incidents appear in every matching sheet (by design).
4. **All Tabs** (`MultiSheetIncidentsExport`) — 14 sheets mirroring the table tabs (the "Non Incident" sheet is gone: exports are METRIC_ELIGIBLE-only, so it would be structurally empty; the table's own tab stays).
5. **Custom** — pick columns (`ExportActionSchema::columnOptions()`) + XLSX/CSV.
6. **Markdown ZIP** (`export_markdown_zip` action on `ListIncidents`, separate from the preset form which is shared with `ai_search`) — one folder per incident: full `IncidentMarkdownExporter::generate()` md + every investigation document as md (cached-first via `getMarkdownContent()`, else `DocumentConverterService::convert()`; skipped docs noted in a `## Document conversion notes` appendix), plus a root `index.md`. Built by `Services/Markdown/IncidentMarkdownZipService` over `getFilteredTableQuery()` + `ExportActionSchema::baseExportScope()` (the METRIC_ELIGIBLE single source — `applyFilters()` calls it too). Inline build; temp zip on local disk, `deleteFileAfterSend`.

Optional export filters (severity / status / type / fund status / PIC / business category / root cause / **quarter** `f_quarter`, all multi-select) apply **on top of** the table's current filters via `ExportActionSchema::applyFilters()`. `applyFilters()` **permanently restricts severity to `METRIC_ELIGIBLE`** (owner rule 2026-09-30) — G / Non Incident never reach any preset; the 3 fresh-query Issue sheets in `MultiSheetIncidentsExport` carry the same `whereIn`.

**Fund numbers in every export render as Rupiah via the native Excel number format** (`"Rp "#,##0`, defined once in `Exports\Concerns\IdrFormat`) — cells stay real summable numbers, never `'Rp x.xxx'` strings. Fixed-layout sheets use `WithColumnFormatting`; dynamic-column sheets (Custom/All Tabs) compute fund letters via `IdrFormat::letters()`. Quarter parsing (`"YYYY-Qn"` → date range / label) lives in `Exports\Concerns\QuarterRange`.

**Rule:** every export class = its own PSR-4 file. Shared query filtering goes in `ExportActionSchema`, shared stats in `Statistics/IncidentStatsFooterData` — never duplicated in handlers.

---

## Key Files & Dependency Map

Before editing any file below, check the "consumed by" column — a rule change must reach **every** listed surface, not just the one you came to fix. BUG-005/006/007 were all one surface drifting while the rest stayed correct.

| File | Role | Consumed by |
|------|------|-------------|
| `app/Enums/Severity.php` | `METRIC_ELIGIBLE` = P1–P4, X1–X4 — the ONLY definition of who counts in MTTR/MTBF (excludes `G`, `Non Incident`) | every metrics surface below |
| `app/Models/Label.php` | `OUTLIER = 'Outlier'` — incidents tagged with it leave every MTBF/MTTR average while staying in all counts (owner rule 2026-10-01). Hand-applied only; `autoLabel()` skips it | `Incident::withoutOutliers()` scope + `isOutlier()` + `IncidentLabel` pivot |
| `app/Enums/FundStatus.php` | `EXCLUDED_FROM_COUNTS` = Potential recovery / Fully recovered / Non Tech Loss | all count/fund queries |
| `app/Enums/IncidentStatus.php` | `Open / In progress / Finalization / Completed` — no other values exist | status filters everywhere |
| `app/Models/Incident.php` | `excludedFromCounts()` scope; `countEligible()` scope (severity `METRIC_ELIGIBLE` + fund-status exclusion — G / Non Incident never count); `aiCounts()` scope (single source of truth for AI-facing counts = classification `Incident` + `countEligible()`); `withoutOutliers()` scope (metric-math queries ONLY — never count/KPI tallies); `isOutlier()`; `shouldCalculateMttrByDays()`; PROJ-011 detection fields (enum-cast `detected_via`/`failed_control`, `detection_delay_days`, `start_time_known`) with `startTimeKnown()` (explicit flag ?? occurrence time not 00:00/12:00 placeholder) and `effectiveDetectionLagHours()` (real timestamps only when start known → else delay-days×24 → else null; never negative) | widgets, AI services, exports, governance KPIs |
| `app/Observers/IncidentObserver.php` | Gate for every Incident write; decides when `CalculateIncidentMetrics` dispatches. Its dirty-field trigger list must contain **every field that feeds a cached number** (`fund_loss`, `potential_fund_loss`, `recovered_fund`, status/severity/type/fund_status/classification/dates, `detected_via`/`failed_control`/`detection_delay_days`/`start_time_known`). Label attach/detach is covered by the `IncidentLabel` pivot (not the observer). **Closure gate (RD-2, owner-approved exception to "observers never block"): `saving()` throws `ValidationException` when a row moves INTO `Completed` (create or update) without RCA (root_cause — 'N/A' passes as deliberate override — or root_cause_category) or stop_bleeding_at** — one guard covers the edit form, `update_status`, API and imports; pre-gate Completed rows stay editable (only the transition in is gated); tests simulate them with `createQuietly()` | — |
| `app/Services/Metrics/IncidentMetricsCalculator.php` | **All per-row metric formulas** (`computeMttr`/`computeMtbf`/`computeCategoryMtbf`/`computeMtbfAll`) + `METRIC_COLUMNS` list; sets attributes, never saves. **Outlier rows: every method nulls only its own columns** (guard at the top of each) and predecessor lookups run `withoutOutliers()` | job + recalc command (BUG-008: the two had drifted into 4 copies) |
| `app/Jobs/CalculateIncidentMetrics.php` | Pipeline only: persistence, adjacent-row repair (date/classification edits), `flushIncidentCache()` bumps `dashboard_cache_version` **and clears the chat caches** (pivot writes fire no observer — the job is the one flush every metrics path shares). Formulas live in the calculator. Find-next queries are `withoutOutliers()`; `autoLabel()` never attaches `Outlier` from text | invoked via observer + `IncidentLabel` pivot |
| `app/Models/IncidentLabel.php` | Pivot on `incident_label` (custom class, `$timestamps = false`); created/deleted hooks dispatch `CalculateIncidentMetrics` when the label is `Outlier` — label tagging is a metrics trigger the observer cannot see. Fires only through relations declaring `->using()` (both sides do) | attach/detach in any Filament form or `sync()` |
| `app/Models/IncidentPic.php` | Pivot on `incident_pic` (PROJ-010) — PICs are many per incident (`Incident::pics()`); `pic_id` is GONE. Owns assignment side effects the `IncidentObserver` cannot see (pivot sync fires NO model events, `IncidentLabel` precedent): `created` emails the new PIC (`AssignedAsPicNotification`, self-assign skipped) + notifies admins, both `created`/`deleted` re-index RAG + clear AI chat caches. Unchanged re-sync fires nothing → no re-email. Raw `DB::table('incident_pic')` writes bypass even these hooks — remedy: `sync()` afterwards. Render names via `Incident::pic_names` accessor, never per-surface joins | Filament form/filter/column, reminder commands, exports, charts, AI services |
| `app/Support/MailSettings.php` | Runtime mail activation: `apply()` sets `mail.default=netcore` when Setting `netcore_enabled` + key present, and always overrides `mail.from.*` from Settings `mail_from_address`/`mail_from_name` (default `noreply-techrisk@dana.id`). Called from `AppServiceProvider::boot()` (guarded) + `EmailSettings::save()` (with `queue:restart`) — `.env` is no longer the activation gate | `AppServiceProvider`, `Filament/Pages/EmailSettings` (test-send + failures panel) |
| `app/Filament/Statistics/IncidentStatsFooterData.php` | Shared footer/summary stats builder (Total Cases, avg MTTR min/days, avg MTBF, fund totals) — used by the table content footer AND exports; default scope mirrors the table's date filters (`quick_period` + `custom_date_range`, stacked — appliers live in `Filters/QuickPeriodFilter`); do not duplicate its formulas | `IncidentResource` table config, exports |
| `app/Filament/Actions/ExportActionSchema.php` | Export form schema + `applyFilters()` + `columnOptions()` + `baseExportScope()` (METRIC_ELIGIBLE single source) for every preset | `ListIncidents` export + markdown-zip actions, `IncidentMarkdownCorpusService` |
| `app/Services/Markdown/IncidentMarkdownCorpusService.php` | **The long-term incident memory** — persistent corpus at `markdown/corpus` (local disk): `index.md` + one folder per incident (`incident.md` + `documents/*.md`, cached-first conversion, skip-and-note). `scopedQuery()` = Incidents + `baseExportScope()` (P1–P4/X1–X4; fund-status-excluded rows STAY — knowledge base, not metrics). `refresh()` wipes + rewrites (index.md LAST — the only runtime-read artifact) + stores `incident_corpus_manifest` {built_at (second-precision), incidents, version}; `isStale()` = count/version/updated_at drift; `catalog()` feeds AI prompts; `manifest()` is the single source for the Agent Memory status panel (`Widgets/IncidentMemoryStatusWidget` — lazy-isolated Filament widget; the page's rebuild action dispatches `incident-memory-rebuilt` so the panel re-renders without reload, and `view_incident_catalog` shows `catalog()` read-only). Rebuilt by the Agent Memory page button + hourly `incidents:refresh-corpus` (`--force` overrides). Also owns zip content assembly (index/indexLines/folderFor/incidentFiles) | `IncidentMarkdownZipService` (zip output byte-identical — its tests guard the refactor), `RunAiAgentJob` (include_corpus → `## Incident catalog`, capped by `ai.agents.corpus_inject_limit`), `RefreshIncidentCorpusCommand`, Agent Memory button + status widget + catalog viewer |
| `app/Services/Analytics/AnalyticsQueryService.php` | Analytics page charts; severity scope applied once in `buildSingleDataset()` | `AnalyticsPage` |
| `app/Policies/ActionImprovementPolicy.php` | `viewAny`: `view incidents` OR `access api`; write ops need `manage incidents` | Action Improvements tab + add button |
| `app/Services/Ai/SimilarIncidentService.php` | Similar-incident pipeline (THINK → FIND RAG+structured fused → VERIFY full-compact context → DOUBLE-CHECK uncertain band) **plus `persist()`** — the single home of re-verify semantics (prune stale auto rows, never prune admin-dismissed, re-activate re-detected pairs). Accuracy rules (owner 2026-10-01): verify context = fresh `generateCompact()` capped `ai.similarity.verify_context_chars`, RAG scores never shown to the model, structured-only "taxonomy twins" dropped when RAG has hits, legacy single-call fallback opt-in only (`ai.similarity.legacy_fallback`) | `DetectSimilarController` (edit-page button), `DetectSimilarIncidentsJob` (auto on create, 2-min delay via `IncidentObserver`), `ViewIncident` detect_similar_incidents action, `RecurrenceDetectionService` (pipeline primary), `TestSimilarIncidentPipeline` command |
| `app/Services/Ai/PostMortemService.php` | 7-section retro array (`generate()` — AI via `callAiForJson`, data-only fallback) + `generateAsMarkdown()` (array → markdown, null when no substance) + `resolvedModel()`; stores into `incidents.retro_markdown/retro_generated_at/retro_model` (migration 2026_10_01_100000 — **prod run is an owner-only guardrail**) | `ViewIncident` generate_retro action + Retrospective section, `ExportPostMortemPdfController`, `GeneratePostMortemController` |
| `app/Exports/Concerns/ComputesMtbfSequence.php` | Full-calendar-year MTBF gap sequence for export row cells (`mtbfSequenceValue()`; per-instance cache, first-of-year → null/'-', outliers skipped). All-Tabs sheets sequence **per-tab** instead (`SingleIncidentSheetExport::computeMtbfForIncident()`) — two semantics, do not merge | `IncidentTableExport` (Custom), `Sheets\QuarterlyCaseSheet` (Quarterly Report) |
| `app/Exports/QuarterlyReportExport.php` | Quarterly Report preset: 4 case tabs with All Tabs membership rules + one tab per quarter (`QuarterRange::dates` scope, Incident-only; no Summary sheet — owner 2026-10-05); always full filtered set | `ListIncidents` export action (`quarterly_report` preset) |
| `app/Models/User.php` | **PIC directory lives ON users (owner decision 2026-10-13, no separate table)** — `upsertFromPicEmails()` is the single home: parses `Name <email>` suggestion tags or bare emails (lowercased, deduped), `firstOrCreate`s a login-disabled user (`can_login=false`, random password — flip the toggle in User Management to grant access), returns bare emails for `pic_email`; existing users are never touched. `manager_id` self-relation is the org tree (`ancestorAt(level)` — cycle-safe, null on too-short chains) that drives overdue escalation. `canAccessPanel()` gates on `can_login === false` (strict — a not-hydrated null never blocks) BEFORE expiry/role checks; API/Sanctum routes never pass this gate. `getNotificationType` must know every notification class (an unmapped class falls to the raw `Notification::send` path — no preference filtering, no ChannelFilteredNotification wrapper; `ActionImprovementEscalated` bit us 2026-10-13) | `ActionImprovementObserver` (upsert), `SendActionImprovementReminders` (directory + escalation), `ActionImprovementsRelationManager` (suggestions), `UserResource` (can_login/manager fields), `canAccessPanel` consumers |
| `app/Support/ReminderMail.php` | **No email ever goes to admins (owner rule 2026-10-09)** — the PIC-assignment admin blast and P1/P2 critical-incident alert were deleted (classes + `User::getNotificationType` arms + preference toggles). Every reminder email is ONE combined message for all recipients (multiple TOs = one Netcore send; owner 2026-10-09): `ReminderMail::send($recipients, $notification)` filters by `User::mailPreferenceAllows()` then sends `Mail\GroupNotificationMail` (wraps the notification's `toMailForGroup()` MailMessage); **returns the mail-eligible recipient count (int, owner rule 2026-10-13) — action improvements record every actual send on the row (`ActionImprovement::recordReminderSent()` → `last_reminded_at` + `reminders_sent`), and `last_reminded_at` anchors the `reminder_frequency` throttle (weekly/biweekly) on the daily overdue batch**. The 5 reminder classes override `via()` to `['database','broadcast']` — per-user in-app goes through `notify()`, mail only through ReminderMail. **Org-tree escalation (owner 2026-10-13) lives in `SendActionImprovementReminders::escalateIfDue()` and deliberately BYPASSES mail preferences** (owner mandate, not a subscription): `level = min(intdiv(daysOverdue,14),3)`, fires every unreached rung L1→L3 (a row already deep in overdue informs each rung passed), stops at the manager chain's depth, persists `escalation_level`+`last_escalated_at` per highest fired rung; directory PICs (previously non-User emails that got nothing) now receive reminders like anyone | `SendIncidentReminders`, `SendActionImprovementReminders` (usersByEmail prefetch must run before the due-soon loop; escalation reads the users-table directory), `ActionImprovementsRelationManager` Email button (records the send; "Last email" column) + pic_email Select dropdown (incident-picker UX, options keyed by EMAIL over `User::humanUsers` so stored `pic_email` stays bare emails) with built-in `createOptionForm/Using` "+" button → `User::upsertFromPicEmails` (inline new contact, login-disabled), `ViewIncident` Email PIC action; preference columns `email_/database_incident_not_done_reminder` + `fund_loss_unsettled_reminder` (migration 2026_10_09); tracking migration `2026_10_13_000002`; org/login migration `2026_10_13_000003` |
| `app/Services/Governance/GovernanceKpiService.php` | **Governance scorecard KPIs (VP TechOps PDR §10) — the single KPI implementation** for `Filament/Pages/GovernancePage` (nav Reports, `manage incidents`): register completeness (Completed rows: RCA + stop-bleed + action item), RCA-within-5d from the **audits table** (`new_values.root_cause` first-filled vs `incident_date`; reports coverage since legacy rows have no audits), overdue pending action items, quarterly recurrence (`recurrence_data.is_recurring`), detection-lag medians by fund-status stream (Confirmed loss vs Non fundLoss) via `Incident::effectiveDetectionLagHours()` (real timestamps only when `startTimeKnown()`, else `detection_delay_days` fallback — never placeholder-derived), usable-timing %, placeholder-time count, and FL unknown-channel % (`detected_via` null/Unknown — PDR red >5%; starts at 100% until rows are backfilled). PDR's unimplementable "Negative MTTR = 0" replaced by FL usable-timing % (findings.md 2026-10-13). Every incident scope = `aiCounts()` + period (default: current year); caches `governance_v2_*` 15 min keyed on `dashboard_cache_version` | `Filament/Widgets/GovernanceKpiOverview` (RAG stat cards), `Filament/Widgets/DetectionLagChart` (median lag by quarter), both `$isLazy = false` on the page |
| `app/Models/ActionImprovement.php` | **Lifecycle single source (PROJ-014)** — status constants `STATUS_DRAFT/PENDING/DONE/CANCELLED` (every status filter/form/command writes these, never hand-typed strings) + `PRIORITIES` low→critical; closeout columns `completed_at/completed_by/result_note` are stamped by `ActionImprovementObserver::saving()` (into done = stamp with `??=` so re-saves don't re-stamp; out of done = clear; `saveQuietly` paths never stamp); `recordReminderSent()` is the only writer of `last_reminded_at`/`reminders_sent`. Reminder/escalation/overdue logic everywhere gates on `status = pending` — draft and cancelled rows drop out with no extra code | relation manager, global `ActionImprovementResource`, `SendActionImprovementReminders`, `ActionImprovementsOverview` widget, `GovernanceKpiService` overdue, API requests, `ActionSuggestionService` |
| `app/Filament/Actions/ActionImprovementFormSchema.php` | **Single home of the action-improvement form** (ExportActionSchema pattern) + `statusColor()`/`priorityColor()` maps consumed by every badge column + `rewordAction()` (PROJ-016 iter 3, owner 2026-10-14 "more professional and detail") — the shared **Reword (AI)** row action: draft-status rows only, model picker (default = AI default model), rewrites title + detail via `ActionRewordService`, never status/PIC/due/priority — relation manager and global resource cannot drift. PIC picker = email-keyed Select over `User::humanUsers()` with "+" inline createOption (`User::upsertFromPicEmails`); priority default low, not required; `result_note` visible when status = done | `ActionImprovementsRelationManager`, `ActionImprovementResource` |
| `app/Filament/Resources/ActionImprovementResource.php` | Global Action Improvements table (PROJ-015, owner 2026-10-14): **grouped by incident by default** (`Group::make('incident.no')`, header = "no — title", collapsible; `defaultGroup`), **latest incident first** (owner 2026-10-14: `ListActionImprovements::mount()` seeds `tableGroupingDirection = 'desc'` + the incident Group's `orderQueryUsing` orders by `incident_date` subquery — NOT the `no` string, which doesn't track recency), groupable also by PIC / status / priority. PIC group is **exact email-combo** — `pic_email` is an array cast, so one group key per record (`getKeyFromRecordUsing`); per-person/team grouping needs a real relation (owner: later). Comprehensive columns: incident.no + incident.title (both searchable + linked to EditIncident — relationship search is automatic in Filament 3.3; title limit 35 / incident.title 25, owner 2026-10-14), Created/reminder/reminder_frequency/Last email hidden by default, `result_note` rides the Completed column description. Edit uses the shared form schema | `ActionImprovementFormSchema` (form), `Incident::applyUserYearAccess` (query scoping) |
| `app/Services/Ai/ActionSuggestionService.php` | AI "Suggest Actions" (PROJ-014): one `callAiForJson` (prompt `ai.prompts.action_suggestions`) over `generateCompact()` + retro when present → ≤8 **draft** rows (due +30d, reminder off, priority validated against `PRIORITIES` else low), titles deduped case-insensitively per incident (re-run = 0 new). Draft creation itself delegates to `DocumentActionItemService::createDrafts()` — the single shared loop | `GenerateActionSuggestionsJob` ← relation-manager `suggest_actions` action; notifies requester via `ActionImprovementsSuggested` (mapped in `User::getNotificationType`) |
| `app/Services/ActionImprovements/DocumentActionItemService.php` | **PROJ-016 RCA quick-add**: `extractFromMarkdown()` deterministically parses a document's `## ACTION IMPROVEMENT` section (line-start heading anchor, ends at same-or-higher heading; drops table-header row, single-word owner names, `(DEV)`-fragments, fences/`## DONE` tokens); `extractWithAi()` (iteration 2, owner 2026-10-14 — "select AI… not auto cut… you make the prompt") runs one `callAiForJson` (prompt `ai.prompts.document_action_items`, 30k-char cap, `Current date:` prefix) mapping title/detail/priority/due_date/owners — iteration 3 made that prompt **reword into professional English at extraction** (no casual/Indonesian phrasing copied verbatim); AI owners resolve to users by exact case-insensitive full name (team tag stripped) — matched → `pic_email` (fires the assigned email), unmatched → name stays in detail's Owner suffix; `createDrafts()` is the single draft-creation loop shared with `ActionSuggestionService` (lowercase-title flip-map dedupe, `STATUS_DRAFT`, per-item due date strict `Y-m-d`+`checkdate` else +30d, provenance note in detail) | `InvestigationDocumentsRelationManager` `add_action_items` row action (Extractor select: Fast parse + every available model, AI options gated on `isAvailable()` not the config list — tests have models but no gateway; default = AI default model; visible: conversion completed + `manage incidents`), `Ai\ActionSuggestionService::generate()` |
| `app/Services/ActionImprovements/ActionRewordService.php` | **PROJ-016 iter 3**: `reword($row, $model)` — one `callAiForJson` (prompt `ai.prompts.action_reword`) rewriting a DRAFT action improvement's title + detail into professional, more detailed English (prompt preserves the `Owner:` suffix + `_provenance_` note verbatim; never invents systems/dates/owners); updates ONLY those two columns — status/PIC/due/priority untouched, row stays draft. Failure (empty result / missing prompt) returns false → danger toast | `ActionImprovementFormSchema::rewordAction()` (both surfaces) |

**Surfaces that must stay in sync for MTTR/MTBF/fund numbers:** `Filament/Widgets/DashboardStatsOverview`, `Widgets/MttrMtbfTrendChart`, `Widgets/AiTrendInsights`, `Widgets/PotentialFundLoss`, `IncidentResource/Pages/ListIncidents` (footer + export), `Pages/Reporting`, `Console/Commands/SendReport`, `Http/Controllers/Ai/AnalyzeTrendsController`, `Services/Ai/ChatContextService`, `Services/WarRoom/WarRoomToolExecutor`, `Exports/Sheets/*`, `Services/Analytics/AnalyticsQueryService`.

**Business rules (confirmed):** MTTR positive = minutes, negative = elapsed calendar days, time-of-day ignored, **no `+1`** (owner rule 2026-10-13: 02→09 Oct = 7, not 8; same-day fund-loss resolution stores 0 and joins the minutes bucket; pre-2026-10-13 rows need a metrics recalc). Fund Loss card = classification `Incident` + status `Completed` + `excludedFromCounts`. "Open cases" = `incident_status != Completed`. **Action Improvements lifecycle (PROJ-014, owner 2026-10-14):** statuses draft/pending/done/cancelled; priority optional, default low. Reminder emails, escalation and every "overdue" count gate on `status = 'pending'` only — draft and cancelled rows drop out automatically. Transition INTO done stamps `completed_at`/`completed_by` in the observer (`??=` — editing a done row never re-stamps); reopening clears both. `registerCompletenessPct()` counts action-item EXISTENCE by design — a cancelled item still satisfies register completeness (no governance-number drift). The AI Suggest button only ever creates **draft** rows; drafts become real actions by promoting status to Pending. Base MTBF = calendar year window. Incident COUNTS exclude severity `G` and `Non Incident` everywhere (2026-09-17 product rule — dashboard cards, AI quick stats, WarRoom tools, trends all use `aiCounts()`/`countEligible()`; operator-filtered table footers and Reporting/SendReport queries are row-counts of their own filtered sets and keep counting what they list). Severity BREAKDOWNS (Group-By severity sheets, executive Severity Mix chart) are likewise P1–P4 + X1–X4 only (owner rule 2026-09-29, BUG-022) — and enum-cast attributes compared on Collections must go through `?->value` (enum == string is always false in PHP 8). EXPORTS (all 5 presets incl. the Issue sheets) exclude G / Non Incident rows entirely, and `mttr_formatted` renders positive MTTR as plain minutes ("180", not "3h 0m") — negative stays "X day(s)", null "-" (owner rule 2026-09-30). Export per-row MTBF columns render `-` for the first incident of each year (no predecessor — the old Jan-1 `dayOfYear` anchor made the column mean ≠ span/(n−1)), and every export "Avg MTBF" summary is the **mean of the displayed column** (single-year sets: exactly the widgets' span/(n−1)) — owner report 2026-09-30 (14.11-vs-14.222 reconciliation); the Issues-MTBF sheet follows the same rule. Export MTBF sequence caches are **per-instance, never `static`** — a static cache froze the year's sequence inside long-lived FPM workers, so newly created incidents rendered `-` mid-year and vanished from column and bottom (prod bug 2026-09-30). A tab with no positive (minutes) MTTR shows `-` as Avg MTTR, not 0. Known remaining divergence: the live table's `mtbf_display` column still anchors first-of-year at `dayOfYear`. **Outlier rule (owner 2026-10-01):** a Label `Outlier` tag removes the row from every MTBF/MTTR *average* (calculator nulls its stored metrics; every metric query/span composes `withoutOutliers()`; predecessor gaps telescope over it so Σgaps still equals span) while it stays in every *count* (Total Cases, status tabs, severity breakdowns, KPI counts, Group-By Cases) and every table/export row — its own MTTR/MTBF cells render the literal **`Outlier`** (`mttr_formatted` accessor + per-surface mtbf cells; live-table `state()` returns `int|string`). Tagging fires a recalc + cache bump via the `IncidentLabel` pivot — NOT the observer; raw pivot writes and deleting/renaming the label record bypass it (remedy: Recalculate button / sweep command). In stored-metric sheets (ExecutiveDataSheet, PerCategorySheet) a non-outlier's null stored mtbf renders **0**, not blank (owner 2026-10-01); sequence sheets keep `-` for first-of-year.

**Cache keys that gate freshness:** `dashboard_cache_version` (bump refreshes dashboard widget cache — every version-keyed cache below must include it in the key payload), `analytics_v3_*` (15 min), `mttr_mtbf_trend_v5_*` (15 min), `chat_quick_stats_v2` (5 min, cleared by `ChatContextService::clearDataCache()`), `mtbf_{tab}_{year}_v{version}` (1 h), `incident_corpus_manifest` (no TTL — stale-check state for the incident memory; a `dashboard_cache_version` bump marks it stale).

### Edit-Safety Rules (from BUG-005/006/007)

1. Enum filters are written from enum cases (`IncidentStatus::Completed->value`), never hand-typed strings — a `whereNotIn` listing values that don't exist is a silent no-op.
2. Adding a field that feeds a dashboard/AI number → it must join the observer's recalculation trigger list, or caches serve stale numbers until TTL.
3. Changing a counting/metric rule → walk the whole surface list above; fix the shared scope, not one call site.
4. A filter is only real if the query actually selects the column it checks (comparing a never-selected column = comparing null).
5. After removing a relation/column, grep for its bare name as a **string** too — `'pic'`, `'pic.name'`, `with('incident.pic')`, column-key maps in forms/exports. Arrow-syntax greps (`->pic`) miss eager-load strings and array keys: PROJ-010's final review caught two shipped-green runtime breaks (a daily reminder command + the Reporting page) that were exactly this.
6. Never `clone()` a builder that already grouped — the clone carries the `GROUP BY` into the new query (per-group rows, not table-wide). PROJ-014: the widget's aging/closeout stats cloned the status-count builder → aging returned the first group's numbers (overdue ≈ 0 in prod) and closeout hydration crashed on a null `completed_at`. Use a fresh builder per stat. Related: a typed-`bool` closure over `auth()->user()?->can(...)` returns **null**, not false, when nobody is logged in — wrap with `(bool)` or tests hit a TypeError on anonymous renders.

### Incident Form Validation Rules (2026-09-29 hardening)

Live QA (HTTP-submit matrix) found bad data silently persisting; these constraints are now server-side and must not be removed:
- Fund fields (`potential_fund_loss`, `fund_loss`, `recovered_fund`): `numeric` + `minValue(0)` — negatives are data-entry errors.
- `incident_date`, `entry_date_tech_risk`: `maxDate(now())` — future dates are typos (wrong year) or clock skew.
- `discovered_at`, `stop_bleeding_at`: `afterOrEqual('incident_date')` — the timeline cannot precede the incident.
- Filament API note: `DateTimePicker` has **`maxDate()`**, not `maxValue()` (numeric/text only — using the wrong one throws 500).

---

## Architecture Rules

- **Service Layer:** Complex business logic goes in `app/Services/{Domain}/`, not controllers. Use dependency injection.
- **Observers:** Use for side effects (notifications, cache invalidation, metrics). Never block — use queues for heavy operations. **One owner-approved exception:** the Incident closure gate in `IncidentObserver::saving()` (PROJ-011 Wave 2, RD-2) throws `ValidationException` — data-quality enforcement at the single point every write routes through; no other observer may throw.
- **Query Modification:** `IssueResource` (own Resource on `Incident`, does NOT extend `IncidentResource`) scopes its `getEloquentQuery()` to `classification = 'Issue'` + `Incident::applyUserYearAccess()` — the same year-access scope `IncidentResource::applyAccessControl()` uses. Change year-access rules in the scope, never in one resource.
- **Caching:** Use tag-based caching (`Cache::tags(['incidents'])->flush()`). Cache expensive queries.
- **Validation:** Use Form Request classes for all non-trivial validation.
- **API:** API Resources for responses, versioned routes (`/api/v1/...`), `ApiResponser` trait.
- **Queues:** Use queued jobs for time-consuming operations. Set `$tries` and `$timeout`.
- **Filament:** One resource per entity, Relation Managers for relationships, Sections/Tabs for complex forms.
- **Filament table config:** prefer declarative config (`->contentFooter()` on the resource `table()`) over page-hook methods — page hooks like `getTableContentFooter()` are not executed on the Livewire `loadTable` render path (footer rendered NULL until moved into the resource config).
- **Exports:** one class per file (PSR-4). Shared form/filter logic in `Filament/Actions/ExportActionSchema`, shared stats in `Statistics/IncidentStatsFooterData`.

---

## Docker Stack

Services: `app` (PHP-FPM 8.2), `nginx`, `mysql` (port 3306), `redis` (port 6379), `queue` (Laravel worker), `reverb` (WebSocket)

- DB: `mysql` / host: `db` / database: `laravel` / user: `root` / password: `password`
- Redis: host `redis`, port 6379
- Queue connection: `redis`
- Cache: `redis`
- Broadcast: `reverb`

---

## Testing Rules

- **Target:** 80%+ code coverage
- **Structure:** `tests/Unit/` for isolated tests, `tests/Feature/` for integration tests
- **Factories:** Use for all test data generation
- **RefreshDatabase** trait for clean state
- SQLite in-memory for fast test runs
- **Baseline:** 811 tests / 2,705 assertions green (2026-10-13; delta from 791/2,670 = Wave 2 +16: `IncidentClosureGateTest` (8 — transition-into-Completed blocked without RCA/stop-bleed incl. create-as-Completed, category/'N/A' pass, legacy rows still editable) + `DetectionFieldsTest` (8 — enum casts, startTimeKnown/effectiveDetectionLagHours semantics, export columns, cache bump); Wave 3 +4: `RootCauseFundParetoChartTest` (Pareto split/order/widget-aligned scope + overdue relation-manager flag). The gate forced fixture updates across ~14 test files (completed rows now carry root_cause + stop_bleeding_at; deliberately-incomplete rows use `createQuietly` as legacy-row simulation). Wave 1 (+11 governance scorecard) is in the 791/2,670 line. Fund-loss MTTR elapsed-days rule (owner 2026-10-13) +2 tests/+5 assertions → 813/2,710. Action-improvement reminder tracking + reminder_frequency throttle (owner 2026-10-13) +4 tests/+26 assertions → 817/2,736. Users-table PIC directory + org-tree escalation (owner 2026-10-13) +6 tests/+31 assertions → 823/2,767 (forced `ActionImprovementEndpointTest` to add broadcast-to-log — directory PICs now get the assigned notification). PIC-picker dropdown + "+" inline contact (+1 test/+10 assertions, iteration 2) → 824/2,777. Plus 6 environmental reverb-broadcast errors that only reproduce outside the test env, and 1 pre-existing `RunAiAgentJobTest` failure that reproduces on clean main — both re-verified 2026-10-12 on stashed clean main). A change that drops this count or its assertions is a regression, not a refactor — unless the delta is a deliberate feature deletion recorded here. PROJ-014 (2026-10-14) +11 tests/+48 assertions → 835/2,825 (`ActionImprovementLifecycleTest`: closeout stamp/clear/saveQuietly, priority default + cancelled-out-of-reminders, API request rules — write routes stay disabled by security decision so rules are validated directly, global page year-access + overdue filter + escalation badge, relation-manager render, widget overdue/escalated/avg-days, AI dedupe + re-run-0 + requester notification). PROJ-015 (2026-10-14) +4 tests/+13 assertions → 839/2,838 (grouped-by-incident default view, relationship search, PIC-combo group + Unassigned, comprehensive toggleable columns). PROJ-015 iteration 2 (owner 2026-10-14) +1 test/+1 assertion → 840/2,839 (incident groups default latest-first by incident_date, not the "no" string). PROJ-016 (2026-10-14) +7 tests/+36 assertions → 847/2,875 (`DocumentActionItemsTest`: mangled-RCA parser, section boundaries/prose, empty variants, row-action drafts + idempotence + visibility gates; `ActionSuggestionService` refactor onto the shared loop is behavior-preserving — its PROJ-014 tests unchanged). PROJ-016 iteration 2 (owner 2026-10-14) +4 tests/+42 assertions → 851/2,917 (AI extractor on `add_action_items`: model picker over all available models, `ai.prompts.document_action_items` mapping title/detail/priority/due_date/owners, per-item strict due-date validation, owner name→user auto-assign, parser path preserved). PROJ-016 iteration 3 (owner 2026-10-14) +5 tests/+29 assertions → **856 tests / 2,946 assertions** (`ActionRewordTest`: draft reword via the shared row action (title+detail only), hidden for pending rows / without permission, failure keeps original + warns, available on the global page; `document_action_items` prompt now rewords at extraction).

---

## Security Rules

- Validate all user input (Form Requests)
- Parameterized queries (Eloquent handles this)
- Escape output (Blade handles this)
- CSRF protection enabled
- HTTPS only in production
- Keep dependencies updated (`composer audit`, `npm audit`)
- Role-based access via Spatie Laravel Permission
- API tokens via Laravel Sanctum with scoped abilities
- Audit logging via OwenIt Auditing for model changes

---

## Document Locations

```
docs/
├── projects/active-projects.md      # Track all ongoing work
├── bugs/lesson-learned.md           # Bug RCA and prevention
├── findings/findings.md             # Technical debt & findings
├── continuous-improvement.md        # Improvement initiatives
└── reference/                       # Detailed code examples & templates
    ├── backend-standards.md
    ├── frontend-standards.md
    ├── testing-standards.md
    ├── sre-operations.md
    └── project-management.md
```

---

## Quick Commands

```bash
composer dev                    # Start all services
php artisan test                # Run tests
./vendor/bin/pint               # Code style fix
php artisan optimize:clear      # Clear caches
docker-compose up -d --build    # Deploy
php artisan migrate --force     # Run migrations (prod)
php artisan pail                # View logs
php artisan queue:monitor       # Check queue status
```
