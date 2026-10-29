<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    // Os parâmetros da config chegam pelos #[Autowire(param: ...)] de cada construtor.
    $services->load('BetoCampoy\\Champs\\CronMonitor\\', '../src/')
        ->exclude([
            '../src/ChampsCronMonitorBundle.php',
            '../src/CronExitCode.php',
            '../src/DependencyInjection/',
            '../src/Entity/',
            '../src/Enum/',
            '../src/Monitor/JobRow.php',
            '../src/Schedule/CronJob.php',
        ]);

    $services->load('BetoCampoy\\Champs\\CronMonitor\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');
};
