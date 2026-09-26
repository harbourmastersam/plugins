<?php

namespace HarbourmasterSam\UserAttributeMapperUcs\Providers;

use Filament\Facades\Filament;
use Illuminate\Support\ServiceProvider;

final class UserAttributeMapperUcsPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // A string listener does not autoload the optional mapper dependency.
        $eventClass = 'HarbourmasterSam\\UserAttributeMapper\\Events\\RegisterUserAttributes';

        $this->app['events']->listen($eventClass, function (object $event): void {
            if (!$this->ucsIsAvailableAndEnabled()) {
                return;
            }

            // Resolve this class only after both optional integrations are available.
            $providerClass = 'HarbourmasterSam\\UserAttributeMapperUcs\\Attributes\\UserCreatableServersAttributeProvider';
            (new $providerClass())->register($event->registry);
        });
    }

    private function ucsIsAvailableAndEnabled(): bool
    {
        if (!class_exists('Boy132\\UserCreatableServers\\Models\\UserResourceLimits')) {
            return false;
        }

        // An installed class may be left behind by a disabled plugin. Pelican adds
        // enabled plugins to its Filament panels, so require that runtime signal.
        foreach (Filament::getPanels() as $panel) {
            if ($panel->hasPlugin('user-creatable-servers')) {
                return true;
            }
        }

        return false;
    }
}
