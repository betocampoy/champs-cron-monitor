<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * No projeto (config/routes/champs_cron_monitor.yaml):
 *
 *   champs_cron_monitor:
 *       resource: '@ChampsCronMonitorBundle/config/routes.php'
 *       prefix: /admin/rotinas
 *       trailing_slash_on_root: false
 *
 * Sem o trailing_slash_on_root o painel fica em /admin/rotinas/ (com a barra).
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../src/Controller/', 'attribute');
};
