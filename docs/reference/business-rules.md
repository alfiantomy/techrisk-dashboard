# Business Rules & Validation (confirmed with owner)

Full detail of every counting/metric/fund rule. CLAUDE.md carries only the headline versions.

## Metrics & counts

- MTTR positive = minutes, negative = elapsed calendar days, time-of-day ignored, **no `+1`** (owner rule 2026-10-13: 02→09 Oct = 7, not 8; same-day fund-loss resolution stores 0 and joins the minutes bucket; pre-2026-10-13 rows need a metrics recalc).
- Fund Loss card = classification `Incident` + status `Completed` + `excludedFromCounts`.
- "Open cases" = `incident_status != Completed`.
- Incident COUNTS exclude severity `G` and `Non Incident` everywhere (2026-09-17 product rule — dashboard cards, AI quick stats, WarRoom tools, trends all use `aiCounts()`/`countEligible()`; operator-filtered table footers and Reporting/SendReport queries are row-counts of their own filtered sets and keep counting what they list).
- Severity BREAKDOWNS (Group-By severity sheets, executive Severity Mix chart) are likewise P1–P4 + X1–X4 only (owner rule 2026-09-29, BUG-022) — and enum-cast attributes compared on Collections must go through `?->value` (enum == string is always false in PHP 8).
- Base MTBF = calendar year window.
- Export MTBF sequences follow the export's `f_*` filters (owner report 2026-10-23) — filtering Fund Status to **Confirmed loss + Non fundLoss** reproduces the owner's hand-made report (~7,6 days for 2026 vs the unfiltered chain's ~3,8; both arithmetically correct, different scopes). Gaps telescope over filtered-out rows; the footer = mean of the scoped column. The owner's sheet also hand-skips 4 predecessor pairs (Waffo Jun 3, TORU Jul 12 — merchant non-system-failure cases); no system rule covers that yet.
- Known remaining divergence: the live table's `mtbf_display` column still anchors first-of-year at `dayOfYear`.

## Outlier rule (owner 2026-10-01)

A Label `Outlier` tag removes the row from every MTBF/MTTR *average* (calculator nulls its stored metrics; every metric query/span composes `withoutOutliers()`; predecessor gaps telescope over it so Σgaps still equals span) while it stays in every *count* (Total Cases, status tabs, severity breakdowns, KPI counts, Group-By Cases) and every table/export row — its own MTTR/MTBF cells render the literal **`Outlier`** (`mttr_formatted` accessor + per-surface mtbf cells; live-table `state()` returns `int|string`). Tagging fires a recalc + cache bump via the `IncidentLabel` pivot — NOT the observer; raw pivot writes and deleting/renaming the label record bypass it (remedy: Recalculate button / sweep command).

## Action Improvements lifecycle (PROJ-014, owner 2026-10-14)

- Statuses draft/pending/done/cancelled; priority optional, default low.
- Reminder emails, escalation and every "overdue" count gate on `status = 'pending'` only — draft and cancelled rows drop out automatically.
- Transition INTO done stamps `completed_at`/`completed_by` in the observer (`??=` — editing a done row never re-stamps); reopening clears both.
- `registerCompletenessPct()` counts action-item EXISTENCE by design — a cancelled item still satisfies register completeness (no governance-number drift).
- The AI Suggest button only ever creates **draft** rows; drafts become real actions by promoting status to Pending.
- **TBD due dates (owner 2026-10-14):** `due_date = null` means TBD — every draft-creation path (AI Suggest, RCA parser/AI extractor, WarRoom retro) stores TBD when no real date is known, never an invented +30d; tables render `TBD`, and TBD rows are never due-soon/overdue/escalated (SQL null semantics, no code added). TBD rows are **tracked, not lost**: `tbd` filter on both tables, warning color on pending TBD, and the widget Pending card shows "N TBD (no due date)" — reminders skip them by design, so these surfaces are the only chase mechanism.

## Cache keys that gate freshness

- `dashboard_cache_version` (bump refreshes dashboard widget cache — every version-keyed cache below must include it in the key payload)
- `analytics_v3_*` (15 min), `mttr_mtbf_trend_v5_*` (15 min)
- `chat_quick_stats_v2` (5 min, cleared by `ChatContextService::clearDataCache()`)
- `mtbf_{tab}_{year}_v{version}` (1 h)
- `incident_corpus_manifest` (no TTL — stale-check state for the incident memory; a `dashboard_cache_version` bump marks it stale)

## Incident Form Validation Rules (2026-09-29 hardening)

Live QA (HTTP-submit matrix) found bad data silently persisting; these constraints are now server-side and must not be removed:

- Fund fields (`potential_fund_loss`, `fund_loss`, `recovered_fund`): `numeric` + `minValue(0)` — negatives are data-entry errors.
- `incident_date`, `entry_date_tech_risk`: `maxDate(now())` — future dates are typos (wrong year) or clock skew.
- `discovered_at`, `stop_bleeding_at`: `afterOrEqual('incident_date')` — the timeline cannot precede the incident.
- Filament API note: `DateTimePicker` has **`maxDate()`**, not `maxValue()` (numeric/text only — using the wrong one throws 500).

## Export-specific rules

See `docs/reference/exports.md` — exclusions, `mttr_formatted` rendering, per-instance MTBF caches, `-`/0 conventions.
