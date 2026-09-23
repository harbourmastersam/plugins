<?php

namespace HarbourmasterSam\UserAttributeMapperUcs;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class UserAttributeMapperUcsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'user-attribute-mapper-ucs';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
