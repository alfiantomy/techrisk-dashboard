# Active Projects

*This document tracks all ongoing and planned projects for the Technical Risk Dashboard.*

---

## Project Template

```markdown
### [PROJ-XXX] Project Name

**Status:** [Backlog / In Progress / In Review / Done]
**Priority:** [P1 / P2 / P3 / P4]
**PM:** [Name]
**Assigned Agents:** [List of agents/team members]
**Start Date:** YYYY-MM-DD
**Target Completion:** YYYY-MM-DD

#### Description
[Brief description of project goals and business value]

#### Technical Approach
- [Architecture decisions]
- [Key technologies]
- [Dependencies]

#### Tasks
- [ ] Task 1 - [Agent] - [Status]
- [ ] Task 2 - [Agent] - [Status]
- [ ] Task 3 - [Agent] - [Status]

#### Dependencies
- Dependency 1
- Dependency 2

#### Blockers
- [Blocker if any, otherwise write "None"]

#### Progress Updates
- YYYY-MM-DD: Update description
- YYYY-MM-DD: Update description
```

---

## Active Projects

### [PROJ-016] Add Action Items button on RCA documents

**Status:** Done
**Priority:** P2
**Start Date:** 2026-10-14

#### Description
Owner request 2026-10-14: a button on each uploaded RCA document (Supporting Documents tab) that quick-adds the document's action items to the incident's Action Improvements, "can later be edited one by one". Owner explicitly rejected AI extraction ("do we need AI? if already have MD?") — the RCA template always carries an `## ACTION IMPROVEMENT` section, so the parse is **deterministic over the converted markdown**: synchronous row action, no job, no notification, zero tokens. `DocumentActionItemService::extractFromMarkdown()` survives the heavily mangled PDF→MD output (inline ``` fences, mid-line `## DONE`, owner names on their own lines — the owner's Dana Cicil RCA is the test fixture); items become **draft** rows (due +30d, PIC empty, priority low, provenance note naming the file), deduped case-insensitively incident-wide (re-click = 0 new). Draft creation lives in `DocumentActionItemService::createDrafts()`, extracted from `Ai\ActionSuggestionService` — one loop, two consumers (AI + document paths cannot drift). Deliberate simplification: owners/due dates in the section are NOT parsed (too mangled to trust); everything editable per row. Button visible only when conversion completed + `manage incidents`; toasts: N added / Nothing new / No section found.

#### Tasks
- [x] `DocumentActionItemService` (parser + shared `createDrafts`) — 2026-10-14
- [x] `ActionSuggestionService::generate()` refactored onto the shared loop (behavior-preserving; PROJ-014 tests unchanged) — 2026-10-14
- [x] `add_action_items` row action on `InvestigationDocumentsRelationManager` — 2026-10-14
- [x] 7 tests (`DocumentActionItemsTest`); pint; full suite 847/2,875 (1 known pre-existing RunAiAgentJobTest failure) — 2026-10-14
- [x] Docs (CLAUDE.md dependency-map rows + baseline; this entry) — 2026-10-14
- [ ] Owner live check: incident → Supporting Documents → completed RCA → "Add Action Items" → drafts appear in the Action Improvements tab with the provenance note; re-click → "Nothing new to add"

### [PROJ-001] Initial SOP and Documentation Setup

**Status:** Done
**Priority:** P1
**PM:** Claude (PM Agent)
**Assigned Agents:** PM
**Start Date:** 2026-02-02
**Target Completion:** 2026-02-02

#### Description
Establish Standard Operating Procedures (SOP) and documentation structure for the Technical Risk Dashboard project to ensure consistent development practices and continuous improvement.

#### Technical Approach
- Created CLAUDE.md with comprehensive SOPs for all roles
- Established docs/ folder structure for projects, bugs, and findings
- Implemented PM-centralized workflow for all requests
- Created templates for lesson learned and project tracking

#### Tasks
- [x] Create CLAUDE.md with role-based SOPs - PM - Done
- [x] Create docs/ folder structure - PM - Done
- [x] Create active-projects.md template - PM - Done
- [x] Create lesson-learned.md template - PM - Done
- [x] Create findings.md template - PM - Done

#### Dependencies
- None

#### Blockers
- None

#### Progress Updates
- 2026-02-02: Project completed. Documentation structure established.

---

## Backlog

### [PROJ-005] AI Agents — user-defined scheduled agents (trigger.dev-style)

**Status:** In Progress
**Priority:** P2
**Start Date:** 2026-09-18

#### Description
New 'AI' nav group with an Agents CRUD resource: admins create text-only AI agents (name, instructions = system prompt, optional model override — null falls through to the AI Settings default model), scheduled via 5-field cron (`ai:dispatch-due-agents`, every minute) or run manually. Every run is observable in a read-only Agent Runs feed (status, model, tokens, latency, error, input/output snapshots) and is logged to `ai_usage_logs` with `field_type = 'agent'`. Reuses `AiTextService` (new `generate()`), `CircuitBreaker`, and the existing default-model setting.

#### Tasks
- [x] Migrations, enums, models, `generate()`, job, dispatch command, schedule — 2026-09-18
- [x] Filament AiAgentResource + AiAgentRunResource; AI Settings/AI Usage Logs moved to 'AI' group — 2026-09-18
- [x] Phase 2: dashboard-context injection, output delivery (email), run retention pruning, easier creation (templates, schedule presets, health-badged model picker) — 2026-09-21
- [x] Phase 3: agent collaboration — chain runs (depends_on), shared memory (source of truth), org chart (reports_to) — 2026-09-21
- [ ] Phase 4: tool use (allow-list over the existing tool registry), human-approval gates, self-evolving loops

#### Blockers
None

#### Progress Updates
- 2026-09-18: Project created; Phase 1 (text-only agents) implemented.
- 2026-09-21: Phase 2 shipped. `include_context` appends `ChatContextService::getQuickStats()` to the run prompt; `notify_email` receives output/error via `Mail::raw` (best-effort, never fails the run); runs pruned after 90 days (`AI_AGENT_RUN_RETENTION_DAYS`, `model:prune` daily 02:45). Create form: template presets (`AiAgentTemplates`), friendly schedule (Manual/Daily/Hourly/Weekly/Custom-cron — translated by `AiAgentResource::resolveScheduleData()` + `AiAgent::cronFromPreset()`/`schedulePresetFromCron()`, DB still stores only frequency+cron), model picker now `getModelsForPicker()` (hides unhealthy models). 8 new tests; suite 559 with unchanged pre-existing baseline. Tool use intentionally still text-only ceiling.
- 2026-09-21: Phase 2b shipped — "Draft with AI" creation. New create-only form section: describe the agent in one sentence → `AiAgentDraftService` (`callAiForJson('agent_draft')`, config `ai.prompts.agent_draft.system`) returns whitelisted/normalized name/description/instructions/include_context/schedule-preset, and the hintAction `$set`s all fields for review before save. 6 new tests (suite 565, baseline unchanged). Decision: Retrospective Agents (War Room personas — manual, multi-round, tool-using) stay a separate runtime from scheduled AI Agents; nav converged only — `WarRoomAgentConfigResource` moved to the 'AI' group (sort 92) so all agent management lives together.
- 2026-09-21: Phase 3 shipped — agent collaboration, three mechanisms. **Chaining:** `depends_on_agent_id` ("Runs after") — on success the job dispatches every enabled dependent via `dispatchRun(triggeredByRunId:)`; runs store `triggered_by_run_id` provenance; `AiAgent::createsDependencyCycle()` guards both columns at form level (self-select excludes self; walk-up visited-set; X-after-Y when Y-after-X is correctly a cycle). **Shared memory (one source of truth):** `include_memory` agents append `AiAgentMemoryService::PROTOCOL` to the system prompt; on success `extractFromOutput()` parses the trailing `## Memory` section (`- LESSON:/- OUTCOME:/- NOTE:`, tolerant of case/bold/CRLF, ends at next heading) into `ai_agent_memories` (kind enum, `run_id` **nullOnDelete** — lessons survive the 90-day run prune); `recentForPrompt()` injects newest 25 (`memory_inject_limit`) back into prompts. New read/delete **Agent Memory** resource (light-bulb icon, AI group). **Org chart:** `reports_to_agent_id` tree rendered as indented text into every run prompt when any hierarchy exists (orphan roots render, corrupt-DB cycles guarded); works for company org and SDLC pipelines (a pipeline = a depends_on chain). Teamwork section in agent form (cycle-validated selects + memory toggle). Text-only ceiling kept: no tool calls, memory writes are parsed output. 21 new tests (suite 586, baseline 18F+17E unchanged — auth/notification/export only). Live-verified: A→B chain ran, B's output quoted A's injected lessons and wrote its own OUTCOME back. Demo agents "Chain A — Digest"/"Chain B — Follow-up" left in place.
- 2026-09-21: Phase 3 addendum — teamwork fields are now draftable. "Draft with AI" passes the enabled-agent list (`id => name`) into the draft prompt; the model returns `runs_after`/`reports_to` ids which `AiAgentDraftService::resolveAgentId()` keeps only when they exist (hallucinated ids/names drop to null — no cycle check needed since a create-form agent can't close a loop); `include_memory` defaults on when either link resolves. The action `$set`s all three Teamwork fields, so one sentence fills the whole form including the chain. 4 new tests (suite 590, baseline unchanged). Live-verified: "a follow-up risk reviewer that runs after Chain A — Digest…" resolved both links and memory=true.
- 2026-09-21: Phase 3 addendum 2 — **human feedback loop** (Hermes-style learning). New "Give feedback" action on completed runs in Agent Runs (warning-colored, modal with required 1000-char textarea) → `AiAgentMemoryService::recordFeedback()` stores a `Feedback` memory row tied to the run (`run_id` nullOnDelete survives pruning; Auditable records who). Feedback flows into future prompts through the existing `recentForPrompt()` injection — zero new injection code, no migration (kind is a string column; `AiAgentMemoryKind` gained `Feedback` → warning). Integrity for free: `extractFromOutput()` only parses lesson/outcome/note bullets, so agents can never forge Feedback rows — Feedback ⟺ human-written. 3 new tests (suite 593, baseline unchanged), incl. first Livewire table-action tests in the repo. Live-verified: feedback "keep the digest under 5 bullets and name the top severity incident" → next run produced exactly 5 bullets with "Four P1 incidents (highest severity)" as bullet one.
- 2026-09-21: Phase 3 addendum 3 — **flow-failure audit fixes** (full audit in `docs/findings/findings.md` AG-1..8). (1) Chain handoff: dependent runs now inject `## Upstream result from "{agent}"` — the triggering run's actual output (`upstream_inject_limit` 4000 chars). (2) Safe retry: one retry (3s backoff) around the generation call only; side effects stay exactly-once. (3) Output contract: new `expected_output` + `require_json` columns, "Output contract" form section, `## Expected output` prompt block, JSON validation with one repair retry feeding the parse error back (twice-invalid → Failed, chain correctly dies); both fields draftable. 9 new tests (suite 602, at/below baseline). Live-verified: B's output reviewed A's exact digest figures; require_json agent returned `{"verdict":"high","open_count":7,...}`.
- 2026-09-21: Phase 3 addendum 4 — **guided workflows + agent file reading** (Dify-style builder, scoped per user choice to guided steps + visual map — NOT a canvas editor). A workflow is an ordered agent list (`ai_workflows`/`ai_workflow_steps`, uuid, `unique(agent_id)` = one workflow per agent); `AiWorkflowService::saveSteps()` renders it into the existing `depends_on` chain — no new executor, chain propagation + provenance come free. Filament "Workflows" resource (AI group, gated `manage api tokens`): Repeater step builder (native drag-reorder), View page with Mermaid flow diagram (existing page-scoped `resources/js/mermaid.js`, sanitized labels, escaped fallback) + Run-workflow header action (dispatches step 1; `healChain` bridges gaps when an agent is deleted). Agent "Files & documents" section: uploads (pdf/docx/xlsx/txt/md/csv/json ≤15MB, finfo content-sniff — same whitelist as chat attachments) extracted at attach time via `DocumentConverterService::convertRaw` into `ai_agent_files.extracted_text`; runs inject `## Attached files` (`file_inject_limit` 8000) and, when `include_documents` is on, `## Investigation documents` from the 5 most recent investigation docs (best-effort skip). 12 new tests (suite 613). Two uuid-keyed-collection bugs caught before ship (mermaid node ids, step i−1 lookup) + Filament 3 has no `view-record` layout — custom pages wrap `<x-filament-panels::page>`. Roadmap: canvas/node-graph editor, path/SFTP/remote file sources, per-run file selection. Live: "Nightly Risk Pipeline" (Chain A→B) wired, run dispatched through the real queue (dev gateway env is intentionally unset → failed cleanly at the HTTP call, B correctly not dispatched), .txt/.csv attached + extracted on disk.
- 2026-09-21: Phase 3 addendum 5 — **post-ship fix (BUG-015)**: `/admin/ai-workflows` 500'd live because `AiWorkflow`/`AiWorkflowStep`/`AiAgentFile` used the auditing trait without `implements Auditable` — invisible to phpunit because `audit.console=false` means the observer never attaches in console processes. Fixed all 3 (AiAgent pattern); regression test attaches the observer explicitly and hits the index route over HTTP (RED-verified against the exact production TypeError). Suite 614, known band unchanged.

### [PROJ-003] Export Incident Reports to Markdown

**Status:** Backlog
**Priority:** P2
**PM:** TBD
**Assigned Agents:** TBD (backend-architect-engineer, frontend-engineer)
**Start Date:** TBD
**Target Completion:** TBD

#### Description
Implement a Markdown export feature for individual incident reports. The export will be AI-consumable with well-structured, clean markdown that includes all relevant incident data (summary, timeline, root cause, financial impact, action items, etc.). This will enable AI analysis of incident patterns and facilitate automated reporting.

#### Technical Approach
- Create a dedicated Markdown export service in `app/Services/Markdown/`
- Add Filament action on Incident view page
- Use Laravel Blade templates for markdown generation
- Implement proper formatting for AI consumption (structured headers, consistent data formats)
- Include all relationships: StatusUpdate, InvestigationDocument, ActionImprovement, Label, incidentType
- **See detailed technical plan:** `docs/projects/markdown-features-technical-plan.md`

#### Tasks
- [ ] Phase 1.1: Create MarkdownExportService base class - backend-architect-engineer - Backlog
- [ ] Phase 1.2: Create IncidentMarkdownExporter service - backend-architect-engineer - Backlog
- [ ] Phase 1.3: Create resources/views/markdown/incident.blade.php template - frontend-engineer - Backlog
- [ ] Phase 1.4: Add export_markdown action to ViewIncident page - backend-architect-engineer - Backlog
- [ ] Phase 1.5: Create tests for markdown export format - backend-qa-engineer - Backlog
- [ ] Phase 1.6: Manual testing with various incident data - frontend-qa-specialist - Backlog
- [ ] Phase 1.7: Documentation update - backend-architect-engineer - Backlog

#### Dependencies
- None

#### Blockers
- None

#### Progress Updates
- 2026-02-02: Project added to backlog. Technical requirements defined. Detailed plan created in markdown-features-technical-plan.md.

---

### [PROJ-004] Convert Uploaded Documents to Markdown

**Status:** Backlog
**Priority:** P2
**PM:** TBD
**Assigned Agents:** TBD (backend-architect-engineer, backend-qa-engineer)
**Start Date:** TBD
**Target Completion:** TBD

#### Description
Automatically convert uploaded PDF/DOC files to Markdown format when they're attached to incidents. This makes documents searchable and AI-consumable, enabling advanced analysis and search capabilities across incident documentation.

#### Technical Approach
- **Recommended packages:**
  - PDF: `iamgerwin/php-pdf-to-markdown-parser` (lightweight, dedicated PDF to Markdown)
  - DOCX: `phpoffice/phpword` (well-established, robust DOCX parsing)
- **Alternative:** Doxswap (LibreOffice-based) - NOT recommended due to server dependency
- Create DocumentConverterService in `app/Services/Markdown/`
- Implement queue-based async conversion (ConvertDocumentToMarkdown job)
- Add markdown_path, markdown_converted_at, markdown_conversion_status to investigation_documents table
- Handle encrypted files (decrypt → convert → store markdown → re-encrypt if needed)
- Add Filament actions for viewing/downloading converted markdown
- **See detailed technical plan:** `docs/projects/markdown-features-technical-plan.md`

#### Database Schema Changes
```php
// Add to investigation_documents table
$table->string('markdown_path')->nullable();
$table->timestamp('markdown_converted_at')->nullable();
$table->string('markdown_conversion_status')->default('pending');
// Values: pending, processing, completed, failed
```

#### Storage Strategy
- Converted markdown: `storage/app/markdown/documents/{document_id}.md`
- Temp files during conversion: `storage/app/temp/` (auto-cleanup)

#### Tasks
- [ ] Phase 2.1: Create database migration for markdown fields - backend-architect-engineer - Backlog
- [ ] Phase 2.2: Install composer packages (iamgerwin/php-pdf-to-markdown-parser, phpoffice/phpword) - backend-architect-engineer - Backlog
- [ ] Phase 2.3: Create DocumentConverterService - backend-architect-engineer - Backlog
- [ ] Phase 2.4: Create ConvertDocumentToMarkdown queue job - backend-architect-engineer - Backlog
- [ ] Phase 2.5: Update InvestigationDocumentsRelationManager to dispatch job - backend-architect-engineer - Backlog
- [ ] Phase 2.6: Create Filament actions for viewing/downloading markdown - frontend-engineer - Backlog
- [ ] Phase 2.7: Create temp storage directory and configure cleanup - backend-architect-engineer - Backlog
- [ ] Phase 2.8: Create tests for conversion service - backend-qa-engineer - Backlog
- [ ] Phase 2.9: Manual testing with PDF and DOCX files - backend-qa-engineer - Backlog
- [ ] Phase 2.10: Error handling and retry logic testing - backend-qa-engineer - Backlog

#### Dependencies
- None (can be developed in parallel with PROJ-003)

#### Blockers
- None

#### Risks & Considerations
- **Medium Risk:** PDF/DOCX conversion quality may vary
  - Mitigation: Provide manual review process and reconvert option
- **Medium Risk:** Performance issues with large files
  - Mitigation: Queue processing, file size limits (15MB already enforced)
- **Low Risk:** Package compatibility issues
  - Mitigation: Thorough testing, both packages are actively maintained

#### Progress Updates
- 2026-02-02: Project added to backlog. Technical requirements defined. Detailed plan created in markdown-features-technical-plan.md.

---


### [PROJ-005] Export Redesign — Executive Report, Group By, Form Validation

**Status:** Done
**Priority:** P1
**PM:** Claude (PM Agent)
**Assigned Agents:** PM, backend-architect-engineer, backend-qa-engineer
**Start Date:** 2026-09-29
**Target Completion:** 2026-09-29

#### Description
Rework the incident Export button from a single all-tabs dump into a 4-preset workflow (Executive Report with native Excel charts + KPI cards, Group By dimension sheets with per-group MTTR/MTBF, All Tabs, Custom), add optional export filters, and harden the incident form against bad data (negative funds, reversed timelines, future dates).

#### Technical Approach
- `App\Filament\Actions\ExportActionSchema` — single source for form schema, `applyFilters()`, `columnOptions()`
- `App\Exports\ExecutiveIncidentsExport` + `Sheets/Executive{Data,Calc,Summary}Sheet` — KPI + 4 native charts; post-write XML pass injects chart `numCache`/`strCache` (PhpSpreadsheet omits them; blank charts in Numbers/QuickLook otherwise)
- `App\Exports\GroupedIncidentsExport` + `Sheets/GroupSummarySheet` + `Sheets/PerCategorySheet` — per-dimension sheets, multi-category incidents appear in each
- Shared stats via `App\Filament\Statistics\IncidentStatsFooterData` (also powers the table footer)
- Form validation: `minValue(0)` on fund fields, `maxDate(now())` on incident/entry dates, `afterOrEqual('incident_date')` on discovered/stop-bleeding (found via live HTTP-submit QA matrix)

#### Tasks
- [x] Preset form + handler — backend — Done
- [x] Executive report with charts + cache injection — backend — Done
- [x] Group By export (6 dimensions) — backend — Done
- [x] Group By: + Quarter dimension (Q1–Q4 by incident_date, "2026-Q1" value / "Q1 2026" label, year-aware) — backend — Done (2026-09-30)
- [x] Exports: MTTR plain minutes everywhere + G / Non Incident rows excluded from all presets (Non Incident tab sheet removed, All Tabs now 14 sheets) — backend — Done (2026-09-30)
- [x] Group-By export: Summary widget-aligned (Cases/Actual/Potential follow dashboard card rules) + "Excluded - <FundStatus>" tabs for the difference rows — backend — Done (2026-09-30)
- [x] Quarter multi-select filter (f_quarter) for every preset + QuarterRange concern — backend — Done (2026-09-30)
- [x] Rupiah as native Excel format on every fund cell (IdrFormat concern, all 4 presets) — backend — Done (2026-09-30)
- [x] Executive report widget-aligned (computeKpi single source) + per-quarter Q1–Q4 KPI tabs with severity row (P1–P4 + combined X1–X4) — backend — Done (2026-09-30)
- [x] MTBF reconciliation in All-Tabs/Custom exports: first-of-year cell `-` (no Jan-1 anchor), bottom Avg MTBF = mean of displayed column (equals widgets' span/(n−1)) — backend — Done (2026-09-30)
- [x] Workbook audit round 2 (owner's downloaded file): MTBF sequence caches static→instance (stale FPM workers dashed out new incidents mid-year), Issues-MTBF sheet reconciled like the others, day-only-MTTR tabs show `-` Avg MTTR — backend — Done (2026-09-30)
- [x] Optional export filters — backend — Done
- [x] Form validation hardening (4 gaps found live) — backend — Done
- [x] PSR-4 refactor: one class per file — backend — Done
- [x] Full suite regression — QA — Done (529/1765 green)

#### Dependencies
- maatwebsite/excel 3.1 (WithCharts), phpoffice/phpspreadsheet chart writers

#### Blockers
None

#### Progress Updates
- 2026-09-29: Shipped in commits 6d783c4, 45abd9b, bf40c08. Charts verified in Excel/Numbers via cached values; QA matrix re-run post-fix (all invalid inputs blocked).

---

### [PROJ-006] Outlier Label — exclude from MTBF/MTTR math, keep in counts

**Status:** In Progress (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner rule 2026-10-01: an Incident/Issue tagged Label `Outlier` drops out of every MTBF/MTTR average — calculator nulls its stored metrics, every metric query/span composes `Incident::withoutOutliers()`, predecessor gaps telescope over it — while staying in every count (Total Cases, status tabs, severity breakdowns, KPI counts, Group-By Cases) and every table/export row. Its own MTTR/MTBF cells render the literal `Outlier`. Tagging is a metrics trigger via the new `IncidentLabel` pivot (dispatches recalc + bumps `dashboard_cache_version`); `autoLabel()` never infers it from text. Bonus fixes shipped in the same commit (BUG-024): `app:send-report` fatalled on a private method, its metrics matched 0 rows (enum trap), Reporting page 500'd on enum echo, two unversioned 15-min caches (`mttr_mtbf_trend` → v5, `analytics` → v3).

#### Tasks
- [x] Model primitives (`Label::OUTLIER`, `withoutOutliers()`, `isOutlier()`) — 2026-10-01
- [x] Calculator + job: outlier guards in all 4 methods, predecessor/find-next queries scoped — 2026-10-01
- [x] Freshness: `IncidentLabel` pivot dispatch + autoLabel guard — 2026-10-01
- [x] Query surfaces: footer, dashboard/incident widgets, trend chart, AI insights/controller, Reporting, SendReport, Analytics choke point — 2026-10-01
- [x] Presentation: `mttr_formatted` accessor, sequence sheets ×3, stored sheets ×2 (null→0), live tables ×2, Issues-sheet row retention, markdown truthy fix — 2026-10-01
- [x] Docs: CLAUDE.md dependency map + business rules, BUG-024 — 2026-10-01
- [ ] Owner live check after deploy: tag an incident Outlier → cells show `Outlier`, averages shift, counts don't; detach restores

---

### [PROJ-007] Export Markdown ZIP — AI-consumption corpus

**Status:** Done (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner request 2026-10-01: every incident in the current table view becomes a folder holding its full markdown report plus every attached investigation document converted to markdown; all zipped for feeding AI tools. Markdown-only zip, Incidents only (no Issues), P1–P4/X1–X4 via `ExportActionSchema::baseExportScope()` (extracted as the single source; `applyFilters()` now calls it). Unconvertible/failed documents are skipped with an in-band note in the incident's md — the corpus is honest about gaps. Builds on PROJ-003's exporter + PROJ-004's converter (cached-first, per the `ai_summarize` precedent). Completes PROJ-003's vision. Content assembly now lives in `IncidentMarkdownCorpusService` (PROJ-008); the zip service is a thin orchestrator over it.

#### Tasks
- [x] `baseExportScope()` extraction + regression guard — 2026-10-01
- [x] `IncidentMarkdownZipService` (index.md, sanitized/deduped names, cached-first conversion, skip-and-note appendix) — 2026-10-01
- [x] `export_markdown_zip` header action on ListIncidents — 2026-10-01
- [x] Docs (CLAUDE.md export architecture item 5) — 2026-10-01
- [ ] Owner live check after deploy: Incidents → Export Markdown ZIP → zip with one folder per incident + index.md

---

### [PROJ-008] Incident Memory — persistent corpus for all Agents/AI

**Status:** Done (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner request 2026-10-01: one button holding "memory of all incidents" every Agent/AI can read, auto-updating with data changes. Implemented as a persistent corpus at `storage/app/markdown/corpus/` (`index.md` catalog + one sanitized folder per incident: full report + converted documents) built by `IncidentMarkdownCorpusService` — content assembly moved there from the zip service (byte-identical output; zip tests guard the refactor). Scope: Incidents + `baseExportScope()` (P1–P4/X1–X4; fund-status-excluded rows stay — knowledge base, not metrics). Rebuild = "Rebuild Incident Memory" button on the Agent Memory page + hourly `incidents:refresh-corpus` (stale-check: scoped count / `dashboard_cache_version` / incident+doc `updated_at`, second-precision, `>=`). AI consumption v1: `include_corpus` agent toggle injects the catalog (`## Incident catalog`, capped `ai.agents.corpus_inject_limit` 6000) into every run; `catalog()` is the long-term-memory API chat/WarRoom can adopt later. Rendered deterministically — zero AI calls, zero tokens to build; models only matter when an agent reads it.

#### Tasks
- [x] `IncidentMarkdownCorpusService` (refresh/catalog/isStale/index/folderFor/incidentFiles) + zip refactor — 2026-10-01
- [x] `incidents:refresh-corpus {--force}` command + hourlyAt(17) schedule — 2026-10-01
- [x] `include_corpus` migration + model + config + RunAiAgentJob catalog block — 2026-10-01
- [x] Agent form toggle + Corpus icon column + Agent Memory rebuild button — 2026-10-01
- [x] Docs (CLAUDE.md dependency map + cache keys) — 2026-10-01
- [x] Visibility fix (owner live-check feedback 2026-10-01: rebuild succeeded — 115 incidents — but nothing persisted on the page): `manifest()` accessor, `IncidentMemoryStatusWidget` (count · built · Fresh/Stale, live-updates via `incident-memory-rebuilt` dispatch from the rebuild action — Filament widgets are lazy-isolated children), `view_incident_catalog` read-only modal (index.md content) — 2026-10-01
- [ ] Owner live check after deploy: Agent Memory page shows status panel (115 incidents · Fresh) + View catalog lists incidents; rebuild press updates the panel without reload; agent with toggle → output references catalog incidents

---

### [PROJ-011] Prevention/Detection Program — governance scorecard, lag analytics, closure gate

**Status:** All 3 waves done (code complete; Wave 2 prod migrate pending owner)
**Priority:** P1
**Start Date:** 2026-10-13

#### Description
Owner request 2026-10-13 (grounded in the VP TechOps PDFs: `detection-response-gap-2026-ytd-v2.pdf`, `techops-pdr-action-plan-2026-v1.pdf`): make the Portal measure prevention/detection patterns, not just record incidents. Scope boundary: SLO burn-rate alerting / real-time anomaly detection are SRE-platform items, NOT this app. Approved as 3 independently-shippable waves. KPI substitution per the 2026-10-13 findings audit: PDR's "Negative MTTR = 0" is unimplementable (negative = day-unit encoding for fund-status rows) → replaced by "FL usable timing %".

**Wave 1 (done 2026-10-13, no migrations):** `Services/Governance/GovernanceKpiService` (single KPI implementation — register completeness, RCA-within-5d from the audits table with coverage, overdue pending action items, quarterly recurrence from `recurrence_data.is_recurring`, detection-lag medians by stream, usable-timing %, placeholder-time count; `aiCounts()` scope + current-year period; 15-min cache keyed on `dashboard_cache_version`) + GovernancePage (nav Reports, `manage incidents`) + `GovernanceKpiOverview` (RAG stat cards per PDR §10) + `DetectionLagChart` (median lag by quarter, FL vs NFL). Both widgets `$isLazy = false` — Filament lazy skeletons hide labels from first paint AND from `Livewire::test` page renders (that was the last Wave-1 bug).

**Wave 2 (done 2026-10-13, code complete — prod migrate pending owner):** `DetectedVia` + `FailedControl` enums; migration `2026_10_13_000001` (`detected_via`, `failed_control`, `detection_delay_days`, `start_time_known`); Incident casts + `startTimeKnown()`/`effectiveDetectionLagHours()`; Timeline-tab form fields + table badge columns + 2 filters; **closure gate in `IncidentObserver::saving()`** (ValidationException on any transition INTO Completed without RCA or stop-bleed — covers form, `update_status`, API, imports; 'N/A' root_cause passes as deliberate override; legacy Completed rows stay editable; documented exception to the "observers never block" rule); observer trigger list + 4 fields; export columnOptions (the existing BackedEnum coercion in both export mappers renders the new enums); GovernanceKpiService v2: timing KPIs via `effectiveDetectionLagHours()` + new FL unknown-channel % stat (PDR red >5%). The gate's test fallout: ~14 test files updated (completed fixtures now carry root_cause + stop_bleeding_at; deliberately-incomplete rows use `createQuietly` as legacy-row simulation).

**Wave 3 (done 2026-10-13):** overdue indicator on `ActionImprovementsRelationManager` (due_date renders danger/bold with an `overdue` description when pending + past due — RD-3; `isOverdue()` helper, display-only); quarterly recurrence KPI panel (shipped with Wave 1 — `ragZero(1)` RAG on the overview); `RootCauseFundParetoChart` on the Governance page — fund_loss per root_cause_category value, widget-aligned scope (`aiCounts()` + Completed), multi-category rows split evenly so bars sum to the total, uncategorised loss kept visible as `(uncategorized)`, top 8 + cumulative-% line (dual axis), cached `rca_fund_pareto_v1_*` on `dashboard_cache_version`. AI failed-control suggestion = follow-up, not built (hook point: `callAiForJson` + ViewIncident action, pattern `PostMortemService`).

#### Tasks
- [x] Wave 1: service + page + 2 widgets + blade + 11 tests (9 unit service / 2 feature page) — 2026-10-13
- [x] Wave 1: docs (CLAUDE.md dependency map + baseline 791/2670) — 2026-10-13
- [x] Wave 2: enums + migration + model + form/table + closure gate + exports + 16 tests (IncidentClosureGateTest 8 / DetectionFieldsTest 8) — 2026-10-13
- [x] Wave 2: docs (CLAUDE.md observer/model/governance rows + architecture-rule exception; findings.md RD-2/RD-4 closed) — 2026-10-13
- [x] Wave 3: overdue badge + Pareto chart + 4 tests (recurrence KPI was Wave 1) — 2026-10-13; baseline now 811/2705
- [x] Follow-up (owner rule 2026-10-13): fund-loss MTTR = elapsed calendar days, no `+1` (02→09 Oct = 7, was 8) — one-line `computeMttr()` change, +2 tests, baseline 813/2710; **owner runs `incidents:recalculate-metrics` on prod after deploy** (local dev already recalced)
- [ ] Wave 2: **owner runs `php artisan migrate --force` on prod** (owner-only guardrail), then backfill `detected_via` on Confirmed-loss rows (FL Unknown Channel % starts at 100%)
- [ ] Owner live check: Governance page RAG cards + Pareto match expectations on prod data (`docker compose restart app` for OPcache after deploy)

---

### [PROJ-015] Action Improvements page: incident visibility + grouping + comprehensive table

**Status:** Done
**Priority:** P2
**Start Date:** 2026-10-14

#### Description
Owner follow-up (2026-10-14, right after PROJ-014): "action improvement pages need which incident its on? can be group by incident? comprehensive table like incident pages? can edit from that page like now its already good. and know which people or group by people / team later." All in `ActionImprovementResource::table()` — one production file, no schema change:
- **Incident visibility**: `incident.no` badge (searchable, linked) + `incident.title` (searchable, linked, severity · status description) — relationship search is automatic in Filament 3.3 (`whereRelation` LIKE).
- **Grouping**: default view opens **grouped by incident** (owner choice via question — header "no — title", collapsible); group dropdown also offers PIC / Status / Priority. PIC group = exact email-combo (array cast → `getKeyFromRecordUsing`/`getTitleFromRecordUsing`, 'Unassigned' for empty); per-person/team grouping deferred (needs a real relation) per owner "later".
- **Comprehensive columns** (Incident-table style): Created, reminder + frequency, Last email (+ sent count), `result_note` under Completed — all toggleable, extras hidden by default. Edit stays the shared `ActionImprovementFormSchema` form.
- +4 tests/+13 assertions → baseline **839/2,838** (grouped default render, relationship search, PIC-combo group, toggleable columns via empty `toggledTableColumns` map).

#### Tasks
- [x] Table upgrade (groups + defaultGroup + incident columns + comprehensive toggleables) — 2026-10-14
- [x] 4 tests; pint; full suite 839/2,838 (1 known pre-existing RunAiAgentJobTest failure) — 2026-10-14
- [x] Docs (CLAUDE.md resource row + baseline; this entry) — 2026-10-14
- [x] Iteration 2 (owner 2026-10-14): incident groups open **latest-first** — `ListActionImprovements::mount()` seeds `tableGroupingDirection = 'desc'` (dropdown still flips it) and the incident Group orders by an `incident_date` subquery, not the `no` string (a higher no ≠ newer). Title columns shrunk: action title limit 35, incident.title 25. +1 test/+1 assertion → 840/2,839 — 2026-10-14
- [ ] Later (owner): per-person / team grouping — needs a real relation (e.g. PIC pivot to users or a team column), not the pic_email array cast

---

### [PROJ-014] Action Improvements: global page, closeout, priority, AI drafts

**Status:** Done (code complete; prod migrate pending owner)
**Priority:** P2
**Start Date:** 2026-10-14

#### Description
Owner picked the full menu (2026-10-14) after a gap review. Four big features + four quick wins, all shipped:
1. **Global Action Improvements page** (`ActionImprovementResource`, nav sort 3, `clipboard-document-check`) — every action across incidents, scoped `whereHas('incident', applyUserYearAccess())` (IssueResource precedent; policy as-is: viewAny = `view incidents` OR `access api`). Columns: title+detail, incident link, priority/escalation/status badges, overdue-styled due date, pic_email, completed_at + closer name. Same status/priority/overdue filters as the relation manager; no bulk actions.
2. **Closeout tracking** (migration `2026_10_14_000001`) — `completed_at`/`completed_by`/`result_note`; `ActionImprovementObserver::saving()` stamps on transition INTO done (`??=` — re-saves don't re-stamp) and clears both on reopen; `saveQuietly` paths (reminder recording) never stamp.
3. **Priority field** — low/medium/high/critical, **optional, DB default 'low'** (owner-specified). Constants `ActionImprovement::PRIORITIES`; form default low, badge colors via `ActionImprovementFormSchema::priorityColor()`.
4. **AI "Suggest Actions" button** — relation-manager header action (`manage incidents` gate) dispatches `GenerateActionSuggestionsJob` → `ActionSuggestionService::generate()` (one `callAiForJson` over `generateCompact()` + retro when present; prompt `ai.prompts.action_suggestions`) → ≤8 draft rows (status **draft**, due +30d, reminder off), titles deduped case-insensitively against existing rows (re-run = 0 new); requester gets an in-app `ActionImprovementsSuggested` notice (success/0-created/failed variants). Drafts stay out of reminders/overdue (existing `status = 'pending'` gates).
Quick wins: relation-manager + global filters (status/priority/Ternary overdue), dashboard widget Overdue stat ("N escalated to a manager") + Avg Days to Close, escalation badge columns (`L1–L3` + last-escalated date, visible only when > 0), **'Cancelled' status** (values-only — reminder/escalation/governance-overdue all `pending`-gated so cancelled drops out with zero extra code; `registerCompletenessPct()` deliberately unchanged — it counts action-item EXISTENCE, so a cancelled item still satisfies register completeness).

Shared form single-source: `ActionImprovementFormSchema` (schema + status/priority color maps) consumed by both the relation manager and the global resource — the two surfaces cannot drift.

Bug caught by the new tests before merge: the widget's aging/closeout queries cloned a builder that had already `groupBy('status')` — the clone dragged the GROUP BY into them (aging returned the FIRST GROUP's numbers → overdue would read ~0 in prod; closeout hydration picked an arbitrary group row → null completed_at crash). Fixed with a fresh `$scoped()` builder per stat; recorded as edit-safety rule 6. API write routes stay disabled (read-only by security decision) — the Store/Update request rules (`status: pending,done,cancelled`; `priority in:low,medium,high,critical`) are validated directly so they're covered whenever writes re-enable.

#### Tasks
- [x] Wave 1: migration + model constants/casts/relations + observer closeout stamping + API request rules — 2026-10-14
- [x] Wave 2: ActionImprovementFormSchema + relation-manager rewrite (filters, priority/escalation columns, Suggest Actions) + global ActionImprovementResource/List/Edit — 2026-10-14
- [x] Wave 3: widget 4-stat layout (Overdue + escalated description, Avg Days to Close; groupBy-clone bug fixed) — 2026-10-14
- [x] Wave 4: config prompt + ActionSuggestionService + job + notification + getNotificationType arm — 2026-10-14
- [x] Tests: `ActionImprovementLifecycleTest` (11) — closeout stamp/clear/quietly, priority default + cancelled-reminders, request rules, global page year-access/overdue filter/escalation badge, relation manager render, widget aging/closeout, AI dedupe + re-run 0 + requester notify — 2026-10-14; baseline 835/2825
- [x] Docs: CLAUDE.md dependency-map rows + business rules + edit-safety rule 6 + baseline — 2026-10-14
- [ ] Owner runs `php artisan migrate --force` on prod (same run now covers `2026_10_13_000001`–`2026_10_14_000001`); local dev already migrated
- [ ] Owner live check: global page nav + filters, done → completed stamp, AI Suggest Actions on a real incident

---

### [PROJ-013] PIC directory on users + org-tree escalation

**Status:** Done (code complete; prod migrate pending owner)
**Priority:** P2
**Start Date:** 2026-10-13

#### Description
Owner request 2026-10-13 ("cari nama" + "escalate ke atasnya seperti org tree"), redirected mid-build from a separate `pic_contacts` table to **reusing the users table** (owner: "Reuse user login table... make them cannot login... give access"). Every action-improvement PIC email is auto-saved as a login-disabled directory user by `User::upsertFromPicEmails()` (single home; observer `saving()` normalizes `Name <email>` suggestion tags to bare emails, existing users never touched); the relation-manager TagsInput now suggests users by name ("Name <email>"). Login gate: `users.can_login` defaults TRUE in migration `2026_10_13_000003` so every existing non-expired user keeps logging in; directory rows are `can_login=false` (random password) — flip the toggle in User Management to grant access; API/Sanctum routes never pass the panel gate. `manager_id` self-relation is the org tree, curated in UserResource (Select + Login column). Overdue escalation in `SendActionImprovementReminders`: level = `min(daysOverdue/14, 3)` — ≥2wk → manager, ≥4wk → one higher, capped at 3; fires every unreached rung on first run (deep-overdue rows inform each rung passed), stops at chain depth, one `ActionImprovementEscalated` mail per rung that deliberately bypasses mail preferences (owner mandate) + in-app rows; `escalation_level`/`last_escalated_at` persisted. Intended behavior change: directory PICs (previously non-User emails that received NOTHING) now get reminder + assigned emails like everyone.

#### Tasks
- [x] Migration 000003 (can_login default true, manager_id self-FK, escalation columns) — 2026-10-13
- [x] User: upsertFromPicEmails + parsePicEmailTag + manager()/ancestorAt + canAccessPanel gate (strict `=== false`) — 2026-10-13
- [x] Observer + command swap to users directory; escalation ladder cumulative — 2026-10-13
- [x] Form suggestions + validation; UserResource can_login/manager fields + columns — 2026-10-13
- [x] 6 tests (UserDirectoryEscalationTest) — 2026-10-13; baseline 823/2767
- [x] Iteration 2 (owner 2026-10-13): pic_email TagsInput → incident-picker-style Select dropdown (options keyed by email over human users, stored `pic_email` unchanged) + built-in "+" createOption button → inline new contact via `upsertFromPicEmails` (login-disabled); +1 test → 824/2777
- [ ] Owner runs `php artisan migrate --force` on prod (same run covers 000001–000003), then curates manager chains in User Management (empty chain = no escalation)

---

### [PROJ-012] Action Improvement reminder tracking + frequency throttle

**Status:** Done (code complete; prod migrate pending owner)
**Priority:** P2
**Start Date:** 2026-10-13

#### Description
Owner request 2026-10-13 ("auto save email that have been sended"). Every reminder email sent for an action improvement is now recorded on the row: `last_reminded_at` + `reminders_sent` (migration `2026_10_13_000002`), written by `ActionImprovement::recordReminderSent()` from both send paths (08:00 scheduled command + relation-manager Email button) whenever `ReminderMail::send()` actually mailed someone (returns int now — 0 means everyone's mail preference was off, and then nothing is recorded). The relation manager shows a "Last email" column with the sent count. `reminder_frequency` (weekly/biweekly) — a form field nothing ever read — now throttles the daily overdue batch via `last_reminded_at`; null frequency stays daily. Bonus bug fix found by the new tests: the command's `usersByEmail` prefetch ran AFTER the due-soon loop, so due-soon emails only ever reached incident PICs, never `pic_email`-matched users (existing tests masked it by attaching pics to the incident).

#### Tasks
- [x] Migration + model (recordReminderSent) + ReminderMail int return — 2026-10-13
- [x] Command: throttle + record both batches + prefetch-order fix — 2026-10-13
- [x] Relation manager: record + honest toasts (mailed vs preference-filtered) + Last email column — 2026-10-13
- [x] 4 tests (ActionImprovementReminderTrackingTest; first-ever coverage of the Email button) — 2026-10-13; baseline 817/2736
- [ ] Owner runs `php artisan migrate --force` on prod (one run now covers `2026_10_13_000001`–`000003`)

---

### [PROJ-010] Multi-PIC incidents + working Netcore email

**Status:** Done (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner request 2026-10-01: enable Netcore email (was stuck on `MAIL_MAILER=log` — everything written to laravel.log, nothing sent); auto-email each PIC on assignment ("you have been assigned"), PIC becomes multiple people, From address (e.g. `noreply-techrisk@dana.id`) + Netcore settings editable from the app, API key visible, send errors surfaced. Delivered: T1 `MailSettings::apply()` runtime activation from Settings (no .env editing) called at boot + on save (with `queue:restart`); T2 EmailSettings rework (editable From, status panel, real API key in the field); T3 Send Test Email action (failure notification carries the exact exception) + Recent Email Failures panel (last 5 `failed_jobs` matching Netcore); T4 multi-PIC cutover — `incident_pic` pivot replaces the `pic_id` column in ONE migration (copy + drop, no dual-write), `IncidentPic` pivot hooks own assignment email to each newly attached PIC + RAG re-index + AI cache clear (pivot sync fires no model events), all consumers swept (Filament form/filter/column, reminder commands, exports, charts, AI services, API `pic_ids[]` with legacy `pic_id` mapped one release). **Prod notes: the T4 migration DROPS `pic_id` — owner-only guardrail (`php artisan migrate --force`); after deploy `docker compose restart app queue`; Netcore console must have noreply-techrisk@dana.id as an approved sender.**

#### Tasks
- [x] T1 runtime mail activation + From override — 2026-10-01
- [x] T2 EmailSettings editable From + status panel + real key — 2026-10-01
- [x] T3 send-test-email action + recent failures panel — 2026-10-01
- [x] T4 multi-PIC pivot cutover + full consumer sweep — 2026-10-01
- [x] T5 CLAUDE.md dependency map (IncidentPic + MailSettings rows) + this entry — 2026-10-01
- [ ] Owner live check after deploy: Email Settings → toggle ON, key visible, From set, Send Test Email arrives from noreply-techrisk@dana.id; assign 2 PICs → both emailed; re-save unchanged PICs → no new email

---

### [PROJ-009] Similar Incidents (accurate + automatic) + Retro generation

**Status:** Done (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner request 2026-10-01: similar-incident detection "is not accurate" (irrelevant matches on the incident pages), must appear automatically with a score on every new incident, plus on-demand detection and AI retro drafting ("Restro") using the incident memory. Root causes found and fixed (all in `SimilarIncidentService`): RC1 verify saw only 800 chars/candidate → now the full `generateCompact()` report capped `ai.similarity.verify_context_chars` (2500); RC2 verify message anchored on junk RAG scores ("Rank #N · retrieval X") → removed; RC3 structured-only taxonomy twins entered verify with zero textual evidence → dropped when RAG has hits; RC4 any pipeline hiccup silently fell back to the loose legacy single-call prompt → now opt-in (`ai.similarity.legacy_fallback`, default false; legacy-path enum crash fixed too); RC5 `match_type` stored but never displayed → Deep/Thematic badge on edit card + view section. Auto-detection: `IncidentObserver::created` dispatches `DetectSimilarIncidentsJob` (2-min delay, isAvailable-gated) → `analyze()` + `persist()` (persist extracted from the controller as the single home of re-verify semantics: prune stale auto rows, keep admin-dismissed, re-activate re-detected). View page: "Similar Incidents" section after Root Cause Analysis (link/severity/status/similarity %/badge/reason, hint when empty) + Detect Similar header action. Retro: `generate_retro` action stores `retro_markdown`/`retro_generated_at`/`retro_model` (`PostMortemService::generateAsMarkdown()` over the untouched `generate()` array; null → error notification) + Retrospective section rendering markdown. **Prod note: the retro_* migration is an owner-only guardrail.**

#### Tasks
- [x] Part A accuracy fixes A1–A5 (compact verify context, anchor removal, taxonomy-twin filter, opt-in legacy fallback, match-type badge) — 2026-10-01
- [x] Part B persist() extraction + auto-detect job off the observer — 2026-10-01
- [x] Part C ViewIncident Similar Incidents section + Detect Similar action — 2026-10-01
- [x] Part D retro columns + generateAsMarkdown + action + section — 2026-10-01
- [x] Part E CLAUDE.md dependency map (SimilarIncidentService + PostMortemService rows) + this entry — 2026-10-01
- [ ] Owner live check after deploy: known repeat incident → Detect Similar → reasons cite root-cause substance + Deep/Thematic badges; new incident → section auto-populates ~2 min later; Generate Retro → section + PDF

---

---

## Completed Projects

### [PROJ-005] Export Redesign — Executive Report, Group By, Form Validation
- **Completed:** 2026-09-29
- **Outcome:** 4-preset export (Executive/GroupBy/AllTabs/Custom) + filters + validation hardening; 529/1765 green

### [PROJ-001] Initial SOP and Documentation Setup
- **Completed:** 2026-02-02
- **Outcome:** Established documentation framework for continuous improvement

---

*Last Updated: 2026-10-13*
