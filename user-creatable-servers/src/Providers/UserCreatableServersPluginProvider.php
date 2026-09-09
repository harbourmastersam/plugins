<?php

namespace Boy132\UserCreatableServers\Providers;

use App\Enums\HeaderActionPosition;
use App\Enums\HeaderWidgetPosition;
use App\Events\Auth\OAuthAuthenticated;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Models\Role;
use App\Models\User;
use Boy132\UserCreatableServers\Filament\Admin\Resources\Users\RelationManagers\UserResourceLimitRelationManager;
use Boy132\UserCreatableServers\Filament\App\Widgets\UserResourceLimitsOverview;
use Boy132\UserCreatableServers\Filament\Components\Actions\CreateServerAction;
use Boy132\UserCreatableServers\Listeners\SyncUserResourceLimitsFromOAuth;
use Boy132\UserCreatableServers\Models\UserResourceLimits;
use Boy132\UserCreatableServers\OAuth\AuthentikProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class UserCreatableServersPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OAuthClaimContext::class, fn () => new OAuthClaimContext());

        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            // Defer the replacement until every service provider has booted so this
            // registration deterministically replaces Pelican's Authentik driver.
            $this->app->booted(fn () => $event->extendSocialite('authentik', AuthentikProvider::class));
        });

        UserResource::registerCustomRelations(UserResourceLimitRelationManager::class);

        ListServers::registerCustomHeaderWidgets(HeaderWidgetPosition::Before, UserResourceLimitsOverview::class);

        ListServers::registerCustomHeaderActions(HeaderActionPosition::Before, CreateServerAction::make());

        Role::registerCustomDefaultPermissions('userResourceLimits');
        Role::registerCustomModelIcon('userResourceLimits', 'tabler-cube-plus');
    }

    public function boot(): void
    {
        User::resolveRelationUsing('userResourceLimits', fn (User $user) => $user->belongsTo(UserResourceLimits::class, 'id', 'user_id'));

        Event::listen(OAuthAuthenticated::class, SyncUserResourceLimitsFromOAuth::class);
    }
}
