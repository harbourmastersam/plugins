<?php

use App\Models\User;
use Boy132\UserAttributeMapper\Enums\AttributeType;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
use Boy132\UserCreatableServers\Models\UserResourceLimits;
use HarbourmasterSam\UserAttributeMapperUcs\Attributes\UserCreatableServersAttributeProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (!Schema::hasTable('user_resource_limits')) {
        Schema::create('user_resource_limits', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->unsignedInteger('cpu');
            $table->unsignedInteger('memory');
            $table->unsignedInteger('disk');
            $table->unsignedInteger('server_limit')->nullable();
            $table->timestamps();
        });
    }

    UserResourceLimits::query()->delete();
});

function ucsBridgeDefinitions(): UserAttributeRegistry
{
    $registry = new UserAttributeRegistry();
    (new UserCreatableServersAttributeProvider())->register($registry);

    return $registry;
}

function bridgeUser(int $id = 7654321): User
{
    $user = new User();
    $user->forceFill(['id' => $id]);
    $user->exists = true;

    return $user;
}

it('registers exactly the four reviewed UCS attributes with safe metadata', function (): void {
    $definitions = ucsBridgeDefinitions()->all();

    expect($definitions->keys()->all())->toBe([
        'user-creatable-servers.cpu',
        'user-creatable-servers.memory',
        'user-creatable-servers.disk',
        'user-creatable-servers.server_limit',
    ]);

    foreach ($definitions as $definition) {
        expect($definition->owner)->toBe('user-creatable-servers')
            ->and($definition->group)->toBe('User Creatable Servers')
            ->and($definition->type)->toBe(AttributeType::Integer)
            ->and($definition->writableFromIdentity)->toBeTrue()
            ->and(validator(['value' => -1], ['value' => $definition->rules])->fails())->toBeTrue();
    }

    foreach (['cpu', 'memory', 'disk'] as $field) {
        $definition = $definitions->get("user-creatable-servers.$field");
        expect($definition->nullable)->toBeFalse()
            ->and($definition->clearer)->toBeNull();
    }

    $serverLimit = $definitions->get('user-creatable-servers.server_limit');
    expect($serverLimit->nullable)->toBeTrue()
        ->and($serverLimit->clearer)->not->toBeNull();
});

it('creates authoritative UCS storage with safe defaults and reads it back', function (): void {
    $definition = ucsBridgeDefinitions()->get('user-creatable-servers.cpu');
    $user = bridgeUser();

    $definition->write($user, 800);

    $stored = UserResourceLimits::where('user_id', $user->id)->firstOrFail();
    expect($stored->cpu)->toBe(800)
        ->and($stored->memory)->toBe(0)
        ->and($stored->disk)->toBe(0)
        ->and($stored->server_limit)->toBeNull()
        ->and($definition->read($user))->toBe(800);
});

it('updates one field without overwriting unrelated UCS limits', function (): void {
    $user = bridgeUser();
    UserResourceLimits::create([
        'user_id' => $user->id,
        'cpu' => 100,
        'memory' => 16384,
        'disk' => 100000,
        'server_limit' => 5,
    ]);

    ucsBridgeDefinitions()->get('user-creatable-servers.cpu')->write($user, 800);

    $stored = UserResourceLimits::where('user_id', $user->id)->firstOrFail();
    expect($stored->cpu)->toBe(800)
        ->and($stored->memory)->toBe(16384)
        ->and($stored->disk)->toBe(100000)
        ->and($stored->server_limit)->toBe(5);
});

it('clears only nullable server_limit in authoritative storage', function (): void {
    $user = bridgeUser();
    UserResourceLimits::create([
        'user_id' => $user->id,
        'cpu' => 800,
        'memory' => 16384,
        'disk' => 100000,
        'server_limit' => 5,
    ]);

    expect(ucsBridgeDefinitions()->get('user-creatable-servers.server_limit')->clear($user))->toBeTrue()
        ->and(UserResourceLimits::where('user_id', $user->id)->value('server_limit'))->toBeNull();
});
