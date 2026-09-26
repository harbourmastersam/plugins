<?php

namespace HarbourmasterSam\UserAttributeMapper;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Panel;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingLoggingMode;

class UserAttributeMapperPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'user-attribute-mapper';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            $panel->discoverResources(plugin_path($this->getId(), 'src/Filament/Admin/Resources'), 'HarbourmasterSam\\UserAttributeMapper\\Filament\\Admin\\Resources');
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return ['logging_mode' => MappingLoggingMode::resolve(config('user-attribute-mapper.logging_mode'))->value];
    }

    public function getSettingsForm(): array
    {
        return [
            Radio::make('logging_mode')
                ->label('Mapping logging')
                ->options([
                    MappingLoggingMode::Errors->value => 'Errors only',
                    MappingLoggingMode::Normal->value => 'Normal',
                    MappingLoggingMode::Verbose->value => 'Verbose',
                ])
                ->descriptions([
                    MappingLoggingMode::Errors->value => 'Only log mapping failures and unavailable targets.',
                    MappingLoggingMode::Normal->value => 'Log one summary for each mapping run plus failures.',
                    MappingLoggingMode::Verbose->value => 'Log individual mapping results, a summary, and failures.',
                ])
                ->default(MappingLoggingMode::Normal->value)
                ->required(),
        ];
    }

    public function saveSettings(array $data): void
    {
        $mode = MappingLoggingMode::resolve($data['logging_mode'] ?? null);
        $this->writeToEnvironment(['USER_ATTRIBUTE_MAPPER_LOGGING_MODE' => $mode->value]);

        Notification::make()->title('User Attribute Mapper settings saved')->success()->send();
    }
}
