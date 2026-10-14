<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Incident;
use App\Models\User;
use App\Notifications\ActionImprovementsSuggested;
use App\Services\Ai\ActionSuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PROJ-014 AI "Suggest actions": drafts action improvements for an incident
 * and notifies the requesting user how many were created (0 = all already
 * existed or the model returned nothing useful).
 */
class GenerateActionSuggestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        private Incident $incident,
        private User $requestedBy,
    ) {}

    public function handle(ActionSuggestionService $service): void
    {
        try {
            $created = $service->generate($this->incident);
        } catch (\Throwable $e) {
            Log::warning('[GenerateActionSuggestionsJob] Failed', [
                'incident_id' => $this->incident->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->requestedBy->notify(new ActionImprovementsSuggested($this->incident, $created, failed: false));
    }

    public function failed(\Throwable $exception): void
    {
        $this->requestedBy->notify(new ActionImprovementsSuggested($this->incident, 0, failed: true));
    }
}
