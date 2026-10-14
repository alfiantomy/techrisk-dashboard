<x-mail::message>
# Action Improvement Notification

An action improvement has been assigned to you.

**Title:** {{ $actionImprovement->title }}

**Details:**
{{ $actionImprovement->detail }}

**Due Date:** {{ $actionImprovement->due_date?->format('Y-m-d') ?? 'TBD' }}

<x-mail::button :url="route('filament.admin.resources.incidents.edit', $actionImprovement->incident)">
View Incident
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
