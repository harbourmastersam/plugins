<?php

namespace Boy132\UserAttributeMapper;

use Filament\Contracts\Plugin;
use Filament\Panel;

class UserAttributeMapperPlugin implements Plugin
{
    public function getId(): string
    {
        return 'user-attribute-mapper';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            $panel->discoverResources(plugin_path($this->getId(), 'src/Filament/Admin/Resources'), 'Boy132\\UserAttributeMapper\\Filament\\Admin\\Resources');
        }
    }

    public function boot(Panel $panel): void {}
}
