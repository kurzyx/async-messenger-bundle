<?php

declare(strict_types=1);

use Kurzyx\AsyncMessengerBundle\Subscriber\HandleIdleMessageWorkerSubscriber;
use React\EventLoop\Loop as EventLoop;
use React\EventLoop\LoopInterface as EventLoopInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container) {
    $container->services()
        ->set('kurzyx_async_messenger.handle_idle_message_worker_subscriber', HandleIdleMessageWorkerSubscriber::class)
            ->args([service('kurzyx_async_messenger.event_loop')])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset'])
        ->set('kurzyx_async_messenger.event_loop', EventLoopInterface::class)
            ->factory([EventLoop::class, 'get'])
    ;
};
