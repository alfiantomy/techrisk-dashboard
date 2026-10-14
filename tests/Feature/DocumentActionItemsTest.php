<?php

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\InvestigationDocumentsRelationManager;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\User;
use App\Services\ActionImprovements\DocumentActionItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-016: deterministic quick-add of action improvements from an RCA
 * document's converted markdown — parser over the mangled PDF→MD output
 * plus the row action on Supporting Documents (no AI anywhere).
 */
class DocumentActionItemsTest extends TestCase
{
    use RefreshDatabase;

    /** The owner's actual converted RCA section (fences, mid-line ## DONE, owner-name lines). */
    private const OWNER_SECTION = <<<'MD'
## GRC Review
Question Answer
Is there any related governance regarding existing internal control? N/A

## ACTION IMPROVEMENT
Action Item Due date Verification Standard Owner
Separate DB into 3 Instances ( Repayment , Payment , Member ) ## DONE
Steven
```
(DEV),
```
Esra
```
(SRE)
Load Test ( tambahin hasilnya nanti )

## APPROVAL
Reviewed By Zikry Zakiyulfuadi VP of Tech Ops
MD;

    private function manager(): User
    {
        Permission::firstOrCreate(['name' => 'manage incidents']);

        return User::factory()->create()->givePermissionTo('manage incidents');
    }

    private function incident(): Incident
    {
        return Incident::factory()->create(['incident_status' => 'In progress']);
    }

    private function completedDoc(Incident $incident, string $markdown, array $attrs = []): InvestigationDocument
    {
        Storage::fake('local');
        $path = 'markdown/documents/'.\Illuminate\Support\Str::uuid().'.md';
        Storage::disk('local')->put($path, $markdown);

        return InvestigationDocument::factory()
            ->for($incident)
            ->create(array_merge([
                'markdown_path' => $path,
                'markdown_conversion_status' => 'completed',
                'original_filename' => 'rca-dana-cicil.pdf',
            ], $attrs));
    }

    // ---- parser ----

    public function test_extracts_items_from_mangled_rca_markdown(): void
    {
        $titles = (new DocumentActionItemService)->extractFromMarkdown(self::OWNER_SECTION);

        $this->assertSame([
            'Separate DB into 3 Instances ( Repayment , Payment , Member )',
            'Load Test ( tambahin hasilnya nanti )',
        ], $titles);
    }

    public function test_section_boundaries_and_prose_mentions(): void
    {
        $markdown = <<<'MD'
We discussed the action improvement plan in the meeting — prose never anchors.
# Root Cause
Database performance dropped.

## ACTION IMPROVEMENT
- Add capacity alerts on connection saturation
### Sub Note
Steven
- Review DB upgrade runbook

## APPROVAL
Signed off by VP
MD;

        $titles = (new DocumentActionItemService)->extractFromMarkdown($markdown);

        // Prose mention ignored; "### Sub Note" neither terminates the
        // section nor becomes an item (its owner-name line filters out by
        // the normal rules); "## APPROVAL" terminates.
        $this->assertSame([
            'Add capacity alerts on connection saturation',
            'Review DB upgrade runbook',
        ], $titles);
    }

    public function test_empty_section_variants_return_no_titles(): void
    {
        $service = new DocumentActionItemService;

        $this->assertSame([], $service->extractFromMarkdown("## Root Cause\nNo section at all."));
        $this->assertSame([], $service->extractFromMarkdown("## ACTION IMPROVEMENT\nN/A\nNone\n—"));
        $this->assertSame([], $service->extractFromMarkdown('## ACTION IMPROVEMENT'.PHP_EOL.'Action Item Due date Verification Standard Owner'));
    }

    // ---- row action ----

    public function test_row_action_creates_draft_action_improvements(): void
    {
        $this->actingAs($this->manager());
        $incident = $this->incident();
        $doc = $this->completedDoc($incident, self::OWNER_SECTION);

        Livewire::test(InvestigationDocumentsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->callTableAction('add_action_items', $doc)
            ->assertNotified('2 draft action item(s) added');

        $drafts = ActionImprovement::where('incident_id', $incident->id)
            ->where('status', ActionImprovement::STATUS_DRAFT)
            ->orderBy('id')
            ->get();

        $this->assertSame(['Separate DB into 3 Instances ( Repayment , Payment , Member )', 'Load Test ( tambahin hasilnya nanti )'], $drafts->pluck('title')->all());
        $this->assertSame('low', $drafts[0]->priority);
        $this->assertSame([], $drafts[0]->pic_email);
        $this->assertSame(now()->addDays(30)->toDateString(), $drafts[0]->due_date->toDateString());
        $this->assertStringContainsString('rca-dana-cicil.pdf', (string) $drafts[0]->detail);
    }

    public function test_row_action_is_idempotent(): void
    {
        $this->actingAs($this->manager());
        $incident = $this->incident();
        $doc = $this->completedDoc($incident, self::OWNER_SECTION);

        $component = Livewire::test(InvestigationDocumentsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ]);

        $component->callTableAction('add_action_items', $doc);
        $component->callTableAction('add_action_items', $doc)
            ->assertNotified('Nothing new to add');

        $this->assertSame(2, ActionImprovement::where('incident_id', $incident->id)->count());
    }

    public function test_action_hidden_when_markdown_not_completed(): void
    {
        $this->actingAs($this->manager());
        $incident = $this->incident();
        $doc = $this->completedDoc($incident, self::OWNER_SECTION, [
            'markdown_conversion_status' => 'pending',
            'markdown_path' => null,
        ]);

        Livewire::test(InvestigationDocumentsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])->assertTableActionHidden('add_action_items', $doc);
    }

    public function test_action_hidden_without_manage_incidents_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $incident = $this->incident();
        $doc = $this->completedDoc($incident, self::OWNER_SECTION);

        Livewire::test(InvestigationDocumentsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])->assertTableActionHidden('add_action_items', $doc);
    }
}
