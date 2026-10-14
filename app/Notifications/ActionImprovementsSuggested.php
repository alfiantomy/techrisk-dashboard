<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * PROJ-014 job-completion notice for the user who clicked "Suggest Actions
 * (AI)". In-app only — no email (this is a status ping, not a reminder).
 */
class ActionImprovementsSuggested extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Incident $incident,
        public readonly int $created,
        public readonly bool $failed,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $url = IncidentResource\Pages\EditIncident::getUrl(['record' => $this->incident]);

        return FilamentNotification::make()
            ->{$this->failed ? 'danger' : 'success'}()
            ->title($this->failed
                ? 'AI action suggestions failed'
                : ($this->created > 0
                    ? "{$this->created} draft action item(s) created"
                    : 'No new action items suggested'))
            ->body($this->failed
                ? '"'.$this->incident->title.'" — the AI request failed. Check the logs and try again.'
                : ($this->created > 0
                    ? 'Drafts for "'.$this->incident->title.'" — review and promote them to Pending.'
                    : '"'.$this->incident->title.'": every suggested action already exists on the incident.'))
            ->actions([
                \Filament\Notifications\Actions\Action::make('open')->label('Open incident')->url($url)->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
