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

**Current baseline: 869 tests / 2,991 assertions green (2026-10-23).** A change that drops this count or its assertions is a regression, not a refactor — unless the delta is a deliberate feature deletion recorded here.

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

Environmental notes: 6 reverb-broadcast errors reproduce only outside the test env, and 1 pre-existing `RunAiAgentJobTest` failure reproduces on clean main — both re-verified 2026-10-12 on stashed clean main, and again 2026-10-23 (`Could not resolve host: reverb`, Docker down).
