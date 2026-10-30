{{-- Preview modal body (Notion Settings): the dry-run summary, then each
     finding on its own line — the list scrolls when it gets long. --}}
<div class="space-y-3 text-sm">
    <p class="font-medium text-gray-950 dark:text-white">{{ $message }}</p>

    @if (filled($lines))
        <div class="max-h-96 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
            <ul class="space-y-1">
                @foreach ($lines as $line)
                    <li class="{{ str_starts_with($line, 'FAILED:') ? 'font-semibold text-danger-600 dark:text-danger-400' : 'text-gray-700 dark:text-gray-200' }}">
                        {{ $line }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
