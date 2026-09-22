<?php

namespace Boy132\UserCreatableServers\Providers;

use App\Enums\HeaderActionPosition;
use App\Enums\HeaderWidgetPosition;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Models\Role;
use App\Models\User;
use Boy132\UserCreatableServers\Filament\Admin\Resources\Users\RelationManagers\UserResourceLimitRelationManager;
use Boy132\UserCreatableServers\Filament\App\Widgets\UserResourceLimitsOverview;
use Boy132\UserCreatableServers\Filament\Components\Actions\CreateServerAction;
use Boy132\UserCreatableServers\Models\UserResourceLimits;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class UserCreatableServersPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        UserResource::registerCustomRelations(UserResourceLimitRelationManager::class);

        ListServers::registerCustomHeaderWidgets(HeaderWidgetPosition::Before, UserResourceLimitsOverview::class);

        ListServers::registerCustomHeaderActions(HeaderActionPosition::Before, CreateServerAction::make());

        Role::registerCustomDefaultPermissions('userResourceLimits');
        Role::registerCustomModelIcon('userResourceLimits', 'tabler-cube-plus');
    }

    public function boot(): void
    {
        User::resolveRelationUsing('userResourceLimits', fn (User $user) => $user->belongsTo(UserResourceLimits::class, 'id', 'user_id'));

        // Keep the mapper optional: none of its classes are resolved unless its
        // registration event is available in Pelican's enabled plugin set.
        $eventClass = 'Boy132\\UserAttributeMapper\\Events\\RegisterUserAttributes';
        if (!class_exists($eventClass)) {
            Log::debug('User Creatable Servers attribute integration unavailable: User Attribute Mapper is not enabled.');

            return;
        }

        /** @param object{registry: mixed} $event */
        Event::listen($eventClass, function (object $event): void {
            $providerClass = 'Boy132\\UserCreatableServers\\Integrations\\UserAttributeMapper\\UserCreatableServersAttributeProvider';
            $before = $event->registry->all()->count();
            (new $providerClass())->register($event->registry);

            Log::debug('User Creatable Servers contributed identity-writable user attributes.', [
                'registered_attributes' => $event->registry->all()->count() - $before,
                'owner' => 'user-creatable-servers',
            ]);
        });
    }
}
