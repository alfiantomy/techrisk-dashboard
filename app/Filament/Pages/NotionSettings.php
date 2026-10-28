<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\NotionSyncAction;
use App\Models\Setting;
use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Notion connection settings: the workspace-wide MCP connection (OAuth via
 * laravel/mcp account linking) and the scheduled sub-page sync watch target.
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

    public function connectionStatus(): string
    {
        if (! app(NotionMcpClient::class)->connected()) {
            return 'Not connected — AI chat Notion tools and the sub-page sync are inactive.';
        }

        $expiresAt = app(NotionMcpClient::class)->tokenExpiresAt();
        $expiry = $expiresAt
            ? ' Access token expires '.now()->setTimestamp($expiresAt)->diffForHumans().' (auto-renews).'
            : '';

        return 'Connected — AI chat can search, read, and write Notion.'.$expiry;
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
                            ->content(fn (NotionSettings $livewire): string => $livewire->connectionStatus())
                            ->columnSpanFull(),

                        Placeholder::make('last_sync')
                            ->label('Last sub-page sync')
                            ->content(fn (NotionSettings $livewire): string => $livewire->lastSyncStatus())
                            ->columnSpanFull(),

                        Actions::make([
                            FormAction::make('connect')
                                ->label(app(NotionMcpClient::class)->connected() ? 'Reconnect Notion' : 'Connect Notion')
                                ->icon('heroicon-m-arrow-top-right-on-square')
                                ->color('primary')
                                ->url(route('mcp.oauth.notion.connect')),

                            FormAction::make('disconnect')
                                ->label('Disconnect')
                                ->icon('heroicon-m-x-mark')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => app(NotionMcpClient::class)->connected())
                                ->action(function (): void {
                                    app(NotionMcpClient::class)->disconnect();

                                    Notification::make()->success()->title('Notion disconnected')->send();
                                }),

                            FormAction::make('syncNow')
                                ->label('Sync now')
                                ->icon('heroicon-m-arrow-path')
                                ->action(fn () => NotionSyncAction::run()),
                        ])->columnSpanFull(),
                    ]),

                Section::make('Sync issue from Notion')
                    ->description('The scheduler reads this Notion page/database at 09:00, 12:00, 15:00, and 18:00 (WIB); new sub-pages are imported as Issues (dedupe on Notion page ID).')
                    ->schema([
                        TextInput::make('watch_page_id')
                            ->label('Notion page or database ID')
                            ->placeholder('e.g. 1f2e3d4c5b6a... or the page URL')
                            ->helperText('Copy the ID or URL from the Notion page/database to watch. Accepts a full URL or a raw ID.')
                            ->columnSpanFull(),
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
