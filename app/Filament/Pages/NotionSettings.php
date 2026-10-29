<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\NotionSyncAction;
use App\Models\Setting;
use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use Filament\Actions\Action as HeaderAction;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Notion connection settings: the workspace-wide MCP connection (OAuth via
 * laravel/mcp account linking) and the scheduled sub-page sync watch target.
 * The connection buttons live in the page header; the sync buttons live
 * inside the "Sync issue from Notion" section (owner 2026-10-29).
 */
class NotionSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Notion Settings';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 102;

    protected static string $view = 'filament.pages.notion-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('manage api tokens');
    }

    public function mount(): void
    {
        $this->form->fill([
            'watch_page_id' => (string) Setting::get(NotionIssueSyncService::WATCH_KEY, ''),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            HeaderAction::make('connect')
                ->label($this->connectButtonLabel())
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('primary')
                ->url(route('mcp.oauth.notion.connect')),

            HeaderAction::make('disconnect')
                ->label('Disconnect')
                ->icon('heroicon-m-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => app(NotionMcpClient::class)->connected())
                ->action(function (): void {
                    app(NotionMcpClient::class)->disconnect();

                    Notification::make()->success()->title('Notion disconnected')->send();
                }),
        ];
    }

    /**
     * Owner 2026-10-29: "Connect" before any connection, "Reconnect" once a
     * token exists — a failed refresh keeps the stored token, so the problem
     * states stay on "Reconnect".
     */
    public function connectButtonLabel(): string
    {
        return app(NotionMcpClient::class)->connected() ? 'Reconnect' : 'Connect';
    }

    /**
     * The four connection states, machine-readable — connectionStatus()
     * turns each into its message and connectionStatusHtml() adds the
     * color coding (green+bold healthy, red broken, amber renewal pending).
     */
    public function connectionState(): string
    {
        $client = app(NotionMcpClient::class);

        if (! $client->connected()) {
            return 'not_connected';
        }

        if ($client->needsReconnect()) {
            return 'reconnect_required';
        }

        $expiresAt = $client->tokenExpiresAt();

        if ($expiresAt !== null && $expiresAt <= time()) {
            return 'renewal_pending';
        }

        return 'connected';
    }

    public function connectionStatus(): string
    {
        $client = app(NotionMcpClient::class);

        if ($this->connectionState() === 'not_connected') {
            return 'Not connected — AI chat Notion tools and the sub-page sync are inactive.';
        }

        if ($this->connectionState() === 'reconnect_required') {
            return 'Reconnect required — the token could not be renewed (the refresh token may have been revoked in Notion). AI chat Notion tools and the sub-page sync are failing until an admin clicks Connect Notion.';
        }

        $expiresAt = $client->tokenExpiresAt();

        // Expired and not yet successfully re-newed — do not claim renewal.
        if ($this->connectionState() === 'renewal_pending') {
            return 'Connected — token expired '.now()->setTimestamp((int) $expiresAt)->diffForHumans().'; renewal pending. If calls keep failing, click Connect Notion.';
        }

        $expiry = $expiresAt
            ? ' Access token expires '.now()->setTimestamp((int) $expiresAt)->diffForHumans().' (auto-renews).'
            : '';

        return 'Connected — AI chat can search, read, and write Notion.'.$expiry;
    }

    public function connectionStatusHtml(): HtmlString
    {
        $classes = match ($this->connectionState()) {
            'not_connected', 'reconnect_required' => 'font-semibold text-danger-600',
            'renewal_pending' => 'font-semibold text-warning-600',
            default => 'font-bold text-success-600',
        };

        return new HtmlString('<span class="'.e($classes).'">'.e($this->connectionStatus()).'</span>');
    }

    public function lastSyncStatus(): string
    {
        $at = Setting::get(NotionIssueSyncService::LAST_SYNC_AT_KEY);

        if (blank($at)) {
            return 'Not synced yet — the scheduler runs at 09:00, 12:00, 15:00, and 18:00 (WIB) once connected.';
        }

        return sprintf('%s — %s', \Illuminate\Support\Carbon::parse($at)->format('Y-m-d H:i'), Setting::get(NotionIssueSyncService::LAST_SYNC_STATUS_KEY, ''));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Connection')
                    ->description("One workspace-wide connection to Notion's hosted MCP server (OAuth 2.1). The connecting admin's Notion account determines the accessible workspace.")
                    ->schema([
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (NotionSettings $livewire): HtmlString => $livewire->connectionStatusHtml())
                            ->columnSpanFull(),

                        Placeholder::make('last_sync')
                            ->label('Last sub-page sync')
                            ->content(fn (NotionSettings $livewire): string => $livewire->lastSyncStatus())
                            ->columnSpanFull(),
                    ]),

                Section::make('Sync issue from Notion')
                    ->description('Runs at 09:00, 12:00, 15:00, and 18:00 (WIB): new sub-pages import as Issues (dedupe by Notion page ID and title, against Issues and Incidents). Deleting an imported issue from the dashboard is permanent — only "Sync now" with "Full sync" restores deleted issues. Use "Preview" for a read-only dry run first.')
                    ->schema([
                        TextInput::make('watch_page_id')
                            ->label('Notion page or database ID')
                            ->placeholder('e.g. 1f2e3d4c5b6a... or the page URL')
                            ->helperText('Copy the ID or URL from the Notion page/database to watch. Accepts a full URL or a raw ID.')
                            ->columnSpanFull(),

                        Actions::make([
                            Action::make('syncNow')
                                ->label('Sync now')
                                ->icon('heroicon-m-arrow-path')
                                ->form(NotionSyncAction::form())
                                ->action(fn (array $data) => NotionSyncAction::run(restoreDeleted: (bool) ($data['restoreDeleted'] ?? false))),

                            Action::make('previewSync')
                                ->label('Preview')
                                ->icon('heroicon-o-eye')
                                ->color('gray')
                                ->action(fn () => NotionSyncAction::preview()),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::set(NotionIssueSyncService::WATCH_KEY, $this->normalizePageId((string) ($data['watch_page_id'] ?? '')) ?: null);

        Notification::make()->success()->title('Notion settings saved')->send();
    }

    /**
     * Accept a full Notion URL or a bare ID — store the bare ID either way.
     */
    private function normalizePageId(string $value): string
    {
        $value = trim($value);

        if (preg_match('/([a-f0-9]{32})/i', $value, $m)) {
            return $m[1];
        }

        return $value;
    }
}
