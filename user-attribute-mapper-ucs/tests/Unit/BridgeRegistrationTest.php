<?php

use HarbourmasterSam\UserAttributeMapperUcs\Providers\UserAttributeMapperUcsPluginProvider;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

it('registers its string listener without loading either optional plugin', function (): void {
    $container = new Container();
    $container->instance('events', new Dispatcher($container));

    (new UserAttributeMapperUcsPluginProvider($container))->register();

    expect($container['events']->getListeners(
        'Boy132\\UserAttributeMapper\\Events\\RegisterUserAttributes',
    ))->toHaveCount(1);
});
