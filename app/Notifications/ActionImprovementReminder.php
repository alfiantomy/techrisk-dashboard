<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class ActionImprovementReminder extends ActionImprovementNotification
{
    public function via(object $notifiable): array
    {
        // Mail is sent once for the whole PIC group via Support\ReminderMail
        // (owner 2026-10-09) — not per-user through the notification channel.
        return ['database', 'broadcast'];
    }

    public function broadcastType(): string
    {
        return 'action.improvement.reminder';
    }

    /**
     * TBD rows (null due date) return null — diffInDays(null) would parse
     * null as now() and report "0 days remaining".
     */
    private function daysUntilDue(): ?int
    {
        return $this->actionImprovement->due_date === null
            ? null
            : (int) round(now()->diffInDays($this->actionImprovement->due_date, false));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $daysUntilDue = $this->daysUntilDue();
        $isOverdue = $daysUntilDue !== null && $daysUntilDue < 0;

        $extraLines = [
            '**Status:** '.ucfirst($this->actionImprovement->status),
        ];

        if ($daysUntilDue === null) {
            $extraLines[] = '**Due date not set (TBD)**';
        } elseif ($isOverdue) {
            $extraLines[] = '**'.abs($daysUntilDue).' days overdue**';
        } else {
            $extraLines[] = '**'.$daysUntilDue.' days remaining**';
        }

        return $this->buildActionMailMessage(
            $isOverdue
                ? '[OVERDUE] Action Improvement Required'
                : '[Reminder] Action Improvement Due Soon',
            $extraLines,
            $notifiable,
            'Please take action as soon as possible.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $daysUntilDue = $this->daysUntilDue();
        $isOverdue = $daysUntilDue !== null && $daysUntilDue < 0;

        return $this->baseDatabasePayload([
            'title' => $isOverdue ? 'Action Improvement Overdue' : 'Action Improvement Reminder',
            'body' => '"'.$this->actionImprovement->title.'" is '.
                ($daysUntilDue === null
                    ? 'due TBD (no date set)'
                    : ($isOverdue
                        ? abs($daysUntilDue).' days overdue'
                        : 'due in '.$daysUntilDue.' days'
                    )
                ),
            'days_until_due' => $daysUntilDue,
            'is_overdue' => $isOverdue,
            'icon' => $isOverdue ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-bell',
            'icon_color' => $isOverdue ? 'danger' : 'warning',
            'type' => $isOverdue ? 'action_improvement_overdue' : 'action_improvement_reminder',
        ]);
    }
}
