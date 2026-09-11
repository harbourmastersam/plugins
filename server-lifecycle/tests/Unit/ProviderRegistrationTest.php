<?php

use HarbourmasterSam\ServerLifecycle\Providers\ServerLifecyclePluginProvider;
use Illuminate\Container\Container;

it('registers without resolving the translator', function (): void {
    $previousContainer = Container::getInstance();
    $container = new Container();

    Container::setInstance($container);

    try {
        expect($container->bound('translator'))->toBeFalse();

        (new ServerLifecyclePluginProvider($container))->register();

        expect($container->bound('translator'))->toBeFalse();
    } finally {
        Container::setInstance($previousContainer);
    }
});
