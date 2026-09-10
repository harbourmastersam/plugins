<?php

namespace HarbourmasterSam\ServerLifecycle;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;

class ServerLifecyclePlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;
    public function getId(): string { return 'server-lifecycle'; }
    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "HarbourmasterSam\\ServerLifecycle\\Filament\\$id\\Pages");
    }
    public function boot(Panel $panel): void {}
    public function getSettingsFormData(): array { return config('server-lifecycle'); }
    public function getSettingsForm(): array
    {
        return [Section::make(__('server-lifecycle::strings.settings.title'))->schema([
            Toggle::make('enabled'), Toggle::make('users_may_archive'), Toggle::make('users_may_restore'), Toggle::make('users_may_download'), Toggle::make('users_may_delete'),
            TextInput::make('attachment_max_bytes')->numeric()->minValue(1)->required(),
        ])];
    }
    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment(['SERVER_LIFECYCLE_ENABLED' => $data['enabled'] ? 'true' : 'false', 'SERVER_LIFECYCLE_USERS_MAY_ARCHIVE' => $data['users_may_archive'] ? 'true' : 'false', 'SERVER_LIFECYCLE_USERS_MAY_RESTORE' => $data['users_may_restore'] ? 'true' : 'false', 'SERVER_LIFECYCLE_USERS_MAY_DOWNLOAD' => $data['users_may_download'] ? 'true' : 'false', 'SERVER_LIFECYCLE_USERS_MAY_DELETE' => $data['users_may_delete'] ? 'true' : 'false', 'SERVER_LIFECYCLE_ATTACHMENT_MAX_BYTES' => $data['attachment_max_bytes']]);
        Notification::make()->title(__('server-lifecycle::strings.settings.saved'))->success()->send();
    }
}
