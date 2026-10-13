<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ActionImprovement;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Org-tree escalation (owner request 2026-10-13): an action improvement
 * overdue for level×2 weeks moves up the PIC's manager chain. Goes to the
 * ancestors, not the PIC — the PIC still gets the normal overdue reminder.
 */
class ActionImprovementEscalated extends ActionImprovementNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function __construct(
        ActionImprovement $actionImprovement,
        public readonly int $level,
        public readonly int $daysOverdue,
        public readonly string $picNames,
    ) {
        parent::__construct($actionImprovement);
    }

    public function broadcastType(): string
    {
        return 'action.improvement.escalated';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildActionMailMessage(
            "[ESCALATION L{$this->level}] Action Improvement overdue {$this->daysOverdue} days",
            [
                '**Days Overdue:** '.$this->daysOverdue,
                '**Assigned to:** '.$this->picNames,
                '**Escalation level:** '.$this->level.' (next manager up)',
            ],
            $notifiable,
            'This action improvement has been open past its due date for '.$this->daysOverdue.' days. Please help chase it to closure.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => "Action Improvement ESCALATED (L{$this->level})",
            'body' => '"'.$this->actionImprovement->title.'" is '.$this->daysOverdue.' days overdue (assigned to '.$this->picNames.')',
            'days_overdue' => $this->daysOverdue,
            'escalation_level' => $this->level,
            'icon' => 'heroicon-o-arrow-trending-up',
            'icon_color' => 'danger',
            'type' => 'action_improvement_escalated',
        ]);
    }
}
