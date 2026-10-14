<?php

namespace Tests\Feature;

use App\Filament\Resources\ActionImprovementResource\Pages\ListActionImprovements;
use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use App\Services\Ai\AiTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-016 iteration 3: "Reword (AI)" on DRAFT action improvements — rewrites
 * title + detail into professional English; every other column stays put.
 */
class ActionRewordTest extends TestCase
{
    use RefreshDatabase;

    private function manager(array $extra = []): User
    {
        foreach (['manage incidents', ...$extra] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        return User::factory()->create()->givePermissionTo('manage incidents', ...$extra);
    }

    private function incident(): Incident
    {
        return Incident::factory()->create(['incident_status' => 'In progress']);
    }

    private function draft(Incident $incident, array $attrs = []): ActionImprovement
    {
        return ActionImprovement::create(array_merge([
            'incident_id' => $incident->id,
            'title' => 'Load Test ( tambahin hasilnya nanti )',
            'detail' => "Load test.\n\n_Drafted from RCA document: rca-dana-cicil.pdf — review and promote._",
            'due_date' => now()->addDays(30)->toDateString(),
            'pic_email' => [],
            'reminder' => false,
            'priority' => 'low',
            'status' => ActionImprovement::STATUS_DRAFT,
        ], $attrs));
    }

    /**
     * visible() and form() resolve on the same mocked instance — stub them
     * or the action hides itself (DocumentActionItemsTest precedent).
     */
    private function aiMock(?string $title, ?string $detail, string $model = 'test-model'): void
    {
        $this->mock(AiTextService::class, function (MockInterface $mock) use ($title, $detail, $model) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('getModelsForPicker')->andReturn([$model => 'Test Model']);
            $mock->shouldReceive('callAiForJson')
                ->withArgs(fn (string $fieldType, string $m): bool => $fieldType === 'action_reword' && $m === $model)
                ->andReturn(['title' => $title, 'detail' => $detail]);
        });
    }

    public function test_reword_action_rewrites_draft_title_and_detail(): void
    {
        $this->actingAs($this->manager());
        $incident = $this->incident();
        $draft = $this->draft($incident);
        $this->aiMock(
            'Execute load test and publish results before the upgrade',
            "Run a full load test against the upgraded environment and publish the results to stakeholders.\n\n_Drafted from RCA document: rca-dana-cicil.pdf — review and promote._",
        );

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->callTableAction('reword', $draft, ['model' => 'test-model'])
            ->assertNotified('Reworded');

        $draft->refresh();
        $this->assertSame('Execute load test and publish results before the upgrade', $draft->title);
        $this->assertStringContainsString('Run a full load test against the upgraded environment', $draft->detail);
        // Only title + detail change — the draft and its register columns stay.
        $this->assertSame(ActionImprovement::STATUS_DRAFT, $draft->status);
        $this->assertSame(now()->addDays(30)->toDateString(), $draft->due_date->toDateString());
        $this->assertSame([], $draft->pic_email);
        $this->assertSame('low', $draft->priority);
    }

    public function test_reword_action_hidden_for_non_draft_rows(): void
    {
        $this->actingAs($this->manager());
        $incident = $this->incident();
        $pending = $this->draft($incident, ['status' => ActionImprovement::STATUS_PENDING]);
        $this->aiMock('Should never run', 'Should never run');

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])->assertTableActionHidden('reword', $pending);
    }

    public function test_reword_action_hidden_without_manage_incidents_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $incident = $this->incident();
        $draft = $this->draft($incident);
        $this->aiMock('Should never run', 'Should never run');

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])->assertTableActionHidden('reword', $draft);
    }

    public function test_reword_failure_keeps_original_and_warns(): void
    {
        $this->actingAs($this->manager());
        $incident = $this->incident();
        $draft = $this->draft($incident);
        $originalTitle = $draft->title;
        $this->aiMock(null, null);

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->callTableAction('reword', $draft, ['model' => 'test-model'])
            ->assertNotified('Reword failed');

        $draft->refresh();
        $this->assertSame($originalTitle, $draft->title);
    }

    public function test_reword_action_available_on_global_page(): void
    {
        $this->actingAs($this->manager(['view incidents', 'access dashboard']));
        $incident = $this->incident();
        $draft = $this->draft($incident);
        $this->aiMock('Reworded title', 'Reworded detail.');

        Livewire::test(ListActionImprovements::class)
            ->assertTableActionVisible('reword', $draft);
    }
}
