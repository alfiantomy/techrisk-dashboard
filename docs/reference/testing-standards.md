# Testing Standards (Reference)

Detailed testing patterns, examples, and checklists. Read this when writing tests.

---

## Test Organization

```
tests/
├── Unit/              # Isolated component tests
│   ├── Models/
│   ├── Services/
│   └── Helpers/
├── Feature/           # Integration tests
│   ├── Api/
│   ├── Web/
│   └── Console/
└── Pest/              # Pest tests (if using Pest)
```

## Unit Testing

Test all public methods in Services. Mock external dependencies. Use data providers for multiple scenarios. Aim for 80%+ code coverage.

```php
class IncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_incident_with_valid_data(): void
    {
        $service = new IncidentService(
            $this->mock(NotificationService::class)
        );

        $incident = $service->createIncident([
            'summary' => 'Test Incident',
            'severity' => Severity::P1,
        ]);

        $this->assertDatabaseHas('incidents', [
            'summary' => 'Test Incident',
        ]);
    }
}
```

## Feature Testing

Test API endpoints comprehensively. Cover validation errors, authorization/permissions, happy path and error paths.

```php
class IncidentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_incidents(): void
    {
        $response = $this->getJson('/api/v1/incidents');
        $response->assertUnauthorized();
    }

    public function test_can_create_incident(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/incidents', [
            'summary' => 'New Incident',
            'severity' => 'P1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.summary', 'New Incident');
    }
}
```

## PHPUnit Configuration

Use SQLite in-memory for fast tests. Configure in `phpunit.xml`:

```xml
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

## Test Data Management

Use **Factories** for test data generation. Define realistic states in factories. Use `seed()` for reference data.

```php
User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@example.com',
]);
```

## Testing Checklists

### For New Features
- [ ] Unit tests for service methods
- [ ] Feature tests for API endpoints
- [ ] Validation tests for form requests
- [ ] Permission/authorization tests
- [ ] Observer event tests
- [ ] Edge case coverage

### For Bug Fixes
- [ ] Reproducible test case for bug
- [ ] Verify fix with test
- [ ] No regression in existing tests

### Filament Resource Testing
- [ ] Test all form validations
- [ ] Verify relationship managers work
- [ ] Test filters and sorting
- [ ] Check create/edit/delete operations
- [ ] Verify export functionality

### Browser Testing
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile responsive (iOS/Android)

## Continuous Integration

- Run tests on every PR
- Enforce code coverage thresholds
- Run security audits
- Verify code style compliance

## Test Baseline History

**Current baseline: 906 tests / 3,132 assertions (2026-10-27, banner-footer run; the known env failures below are unchanged and excluded from "green" judgement).** A change that drops this count or its assertions is a regression, not a refactor — unless the delta is a deliberate feature deletion recorded here.

- 811 / 2,705 (2026-10-13; delta from 791/2,670 = Wave 2 +16: `IncidentClosureGateTest` (8 — transition-into-Completed blocked without RCA/stop-bleed incl. create-as-Completed, category/'N/A' pass, legacy rows still editable) + `DetectionFieldsTest` (8 — enum casts, startTimeKnown/effectiveDetectionLagHours semantics, export columns, cache bump). Wave 3 +4: `RootCauseFundParetoChartTest` (Pareto split/order/widget-aligned scope + overdue relation-manager flag). The gate forced fixture updates across ~14 test files (completed rows now carry root_cause + stop_bleeding_at; deliberately-incomplete rows use `createQuietly` as legacy-row simulation). Wave 1 (+11 governance scorecard) is in the 791/2,670 line.
- 813 / 2,710 — fund-loss MTTR elapsed-days rule (owner 2026-10-13) +2 tests/+5 assertions.
- 817 / 2,736 — action-improvement reminder tracking + reminder_frequency throttle (owner 2026-10-13) +4 tests/+26 assertions.
- 823 / 2,767 — users-table PIC directory + org-tree escalation (owner 2026-10-13) +6 tests/+31 assertions (forced `ActionImprovementEndpointTest` to add broadcast-to-log — directory PICs now get the assigned notification).
- 824 / 2,777 — PIC-picker dropdown + "+" inline contact (+1 test/+10 assertions, iteration 2).
- 835 / 2,825 — PROJ-014 (2026-10-14) +11 tests/+48 assertions (`ActionImprovementLifecycleTest`: closeout stamp/clear/saveQuietly, priority default + cancelled-out-of-reminders, API request rules — write routes stay disabled by security decision so rules are validated directly, global page year-access + overdue filter + escalation badge, relation-manager render, widget overdue/escalated/avg-days, AI dedupe + re-run-0 + requester notification).
- 839 / 2,838 — PROJ-015 (2026-10-14) +4 tests/+13 assertions (grouped-by-incident default view, relationship search, PIC-combo group + Unassigned, comprehensive toggleable columns).
- 840 / 2,839 — PROJ-015 iteration 2 (owner 2026-10-14) +1/+1 (incident groups default latest-first by incident_date, not the "no" string).
- 847 / 2,875 — PROJ-016 (2026-10-14) +7/+36 (`DocumentActionItemsTest`: mangled-RCA parser, section boundaries/prose, empty variants, row-action drafts + idempotence + visibility gates; `ActionSuggestionService` refactor onto the shared loop is behavior-preserving — its PROJ-014 tests unchanged).
- 851 / 2,917 — PROJ-016 iteration 2 (owner 2026-10-14) +4/+42 (AI extractor on `add_action_items`: model picker over all available models, `ai.prompts.document_action_items` mapping title/detail/priority/due_date/owners, per-item strict due-date validation, owner name→user auto-assign, parser path preserved).
- 856 / 2,946 — PROJ-016 iteration 3 (owner 2026-10-14) +5/+29 (`ActionRewordTest`: draft reword via the shared row action (title+detail only), hidden for pending rows / without permission, failure keeps original + warns, available on the global page; `document_action_items` prompt now rewords at extraction).
- 859 / 2,961 — TBD due dates (owner 2026-10-14) +3/+15 (`ActionImprovementTbdTest`: edit clears date → TBD persists, TBD placeholder renders on the global page, reminder command skips TBD rows entirely; +30d fallback tests rewritten to assert TBD — DocumentActionItems ×2, lifecycle AI-suggest assertNull).
- 861 / 2,966 — TBD tracking iteration (owner 2026-10-14) +2/+5 (tbd filter isolates null-due rows + not-overdue keeps TBD visible; widget counts pending TBD only — drafts/dated excluded).
- 864 / 2,978 — export MTBF filter scoping (owner 2026-10-23) +3/+12 (fund-status-filtered export telescopes gaps in all three sequence implementations: Custom `IncidentTableExport` mtbfScope ctor arg, Quarterly Report `QuarterlyCaseSheet` full-filtered-set scope, All-Tabs `SingleIncidentSheetExport` per-tab base off its own query; footer = scoped mean; case tabs share the full-set sequence, not per-tab; unfiltered legacy behavior pinned by the no-scope asserts).
- 869 / 2,991 — widget-aligned export baseline (owner 2026-10-23) +5/+13 (`ExportWidgetBaselineTest`: baselineQuery defaults to Confirmed loss + Non fundLoss (widget MTBF scope), explicit f_fund_status wins, other f_* filters stack; All-Tabs All Cases/Completed scoped while Recovered/Fund-status/On Going sheets keep every fund status; Quarterly All Cases + quarter tabs scoped, Recovered tab keeps Fully-recovered rows, sequence telescopes).
- 891 / 3,094 — action-improvement evidence + cancellation reason + `tbd` status (owner 2026-10-26) +22 tests/+112 assertions measured per file over 869/2,991: `ActionImprovementEvidenceTest` (+8/56 — encrypted upload round-trip, ciphertext ≠ plaintext on disk, key row + incident `file_uploaded` audit, delete removes file + key + `file_deleted` audit, download 200 + Content-Disposition + decrypted body / 403 / 404 / 500), `ActionImprovementTbdStatusTest` (+8/47 — tbd with past due + reminder on sends nothing and stays escalation-free, widget ignores tbd, cancel-without-reason blocked / with reason persists / reason not required for pending-done, tbd in both status filters + gray badge, `send_reminder` hidden for tbd), `WebRouteTest` (+6/+8 — evidence download route block mirroring the documents block, `createEncryptedEvidence()` helper), `ActionImprovementLifecycleTest` API-rules test extended in place (+0/+1: `status: 'tbd'` passes, `'delayed'` still fails). A `view dashboard widgets` permission feature (+6/+6, `DashboardWidgetPermissionTest`) briefly rode this branch, was reverted during review as out-of-plan, then **restored with owner sign-off** — the owner requested it directly in the same session (2026-10-26) and the review agent lacked that context; final tree carries BOTH features, measured full-suite 897/3,100 (+6/+6 over the 891/3,094 evidence-only figure, same 7 env failures). Full-suite run on this tree: 6 reverb-broadcast errors + 1 `RunAiAgentJobTest` failure — the documented pre-existing env failures, unchanged; 891/3,094 is the measured count with those present (test count = 869+22 exactly; the assertion total sits 9 under the per-file sum 2,991+112=3,103 because the 7 env-failure tests error mid-test and their partial assertion counts drift between runs — compare against the measured figure, not the arithmetic one).
- 900 / 3,107 — export baseline null-fund amendment (owner 2026-10-27) +3/+7 (`ExportWidgetBaselineTest`: a null-fund_status metric-eligible row joins the default export baseline; an explicit `f_fund_status` pick still excludes null; the Quarterly Report's All Cases sheet carries the null row at sheet level). `widgetFundScope()` now **delegates to `Incident::excludedFromCounts()`** — the tile's exact predicate (null OR not-excluded) instead of a re-encoded allow-list, so the export can never drift from the tile again (prod 2026: widget 39 vs export 38, widget ruled correct). Same 7 env failures unchanged.
- 905 / 3,124 — severity footer on every incident-listing export tab (owner 2026-10-27) +5/+17 (`SeverityFooterTest`: Quarterly All Cases + Fund Loss tab footers below the pinned Total Cases row, All-Tabs below the summary block, Group-By category sheet — `PerCategorySheet`'s first footer ever, Custom from the Collection path; Avg MTTR/fund counts of each tab's OWN rows, null-fund row in severity but no fund bucket). `WritesSeverityFooter` trait + 6 call sites; the two grouped count clones carry `reorder()` — adversarial review + local MySQL repro showed tab builders' inherited `ORDER BY` fails `ONLY_FULL_GROUP_BY` (error 1055) on prod MySQL while SQLite tests stay green. `ExportMtbfReconciliationTest` ×5 and `ExportCurrencyFormatTest` ×1 summary anchors shifted `getHighestDataRow()` → `−3` — the footer now extends the sheet below the summary values row (assertion intent unchanged). Same 7 env failures unchanged; assertion count drifts ±1 between runs per the note above.
- 906 / 3,132 — footer v3: merged single-line banners (owner 2026-10-27, iteration 3 of the entry below) +1 test/+1 assertion measured (adversarial review's second round added `test_day_based_only_tab_banner_shows_dash_for_minutes_bucket` — a day-based-only tab pinned directly via `SingleIncidentSheetExport` must render `-` for the empty minutes bucket, closing the untested ternary branch) (`SeverityFooterTest` rewritten in place: the 5-row per-column grid became two MERGED banner rows spanning max(table width, H) — a grid inheriting the incident table's column widths read badly per the owner; banner strings asserted in full (`P1: 3 | P2: 1 | … X4: 0`, `Avg MTTR (mins): 52.5 | Avg MTTR (days): 3 | Fund Loss: 3 | Non Fund Loss: 1`) plus `getMergeCells()` pins `A9:L9` quarterly / `A12:H12` narrow Custom — fewer cells to pin than the grid, hence the assertion drop). Merged cells CLIP (no overflow) so the H floor keeps narrow Custom sheets readable. Summary anchors reverted `−6` → `−3` ×7 (`ExportMtbfReconciliationTest` ×6 + `ExportCurrencyFormatTest` ×1 — banners are 2 rows again). Stats math unchanged from the previous entry. Same single `RunAiAgentJobTest` env failure (reverb ones didn't fire, Docker up).
- 905 / 3,148 — footer restyle + Avg-MTTR sign split (owner 2026-10-27, same-day iteration of the entry above) +0 tests/+24 assertions measured (`SeverityFooterTest` rewritten in place for the 5-row block: severity header/counts rows + stats header/values rows with `Avg MTTR Non-Fund Loss (mins)` / `Avg MTTR Fund Loss (days)` / `Fund Loss` / `Non Fund Loss`; a `mttr => -3` Confirmed-loss fixture pins the days bucket and proves it never pollutes the minutes average; fund-loss tab asserts `-` in its empty minutes bucket). Split follows the storage convention (owner 2026-10-13): mttr ≥ 0 averaged as minutes, mttr < 0 averaged abs() as days — Builder and Collection paths identical. `fromArray(..., strict=true)` is load-bearing: the default loose compare treats `0 == null` and silently drops zero-count cells (caught by the suite on first run). Summary anchors `−3` → `−6` in `ExportMtbfReconciliationTest` ×6 + `ExportCurrencyFormatTest` ×1 (the block is 5 rows tall). This run reproduced only the 1 `RunAiAgentJobTest` env failure (the 6 reverb-broadcast errors didn't fire with Docker up), so the assertion total counts their completed tests — compare against 3,148 on a Docker-up run, 3,124-ish otherwise.

Environmental notes: 6 reverb-broadcast errors reproduce only outside the test env, and 1 pre-existing `RunAiAgentJobTest` failure reproduces on clean main — both re-verified 2026-10-12 on stashed clean main, and again 2026-10-23 (`Could not resolve host: reverb`, Docker down).
