<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Tests\Kernel;

use BetoCampoy\Champs\CronMonitor\ChampsCronMonitorBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Kernel mínimo para conferir a ligação do bundle (serviços, rotas, comandos e templates). O banco
 * nunca é acessado: a URL aponta para um MySQL que não existe.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new ChampsCronMonitorBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/champs-cron-monitor-kernel/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/champs-cron-monitor-kernel/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'translator' => ['default_path' => '%kernel.project_dir%/tests/Kernel/translations', 'fallbacks' => ['pt_BR']],
        ]);
        $container->extension('twig', [
            'paths' => ['%kernel.project_dir%/vendor/betocampoy/champs-frontend/templates' => 'ChampsFrontend'],
        ]);
        $container->extension('security', [
            'firewalls' => ['main' => ['lazy' => true]],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'mysql://u:p@127.0.0.1:1/nao_existe?serverVersion=8.0.32'],
            'orm' => [],
        ]);
        $container->extension('champs_cron_monitor', [
            'crontab' => ['php_binary' => '/usr/local/php82/bin/php82'],
            'messenger' => ['enabled' => true, 'queues' => ['default' => 'async', 'fila-lenta' => 'lenta']],
            'jobs' => [
                'clientes-sync' => ['command' => 'champs:cron:check', 'schedule' => '5 * * * *', 'status' => 'active'],
                'legado-email' => ['command' => 'services/x.php', 'schedule' => '*/2 * * * *', 'type' => 'script', 'retention_days' => 14],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ChampsCronMonitorBundle/config/routes.php')->prefix('/admin/rotinas', false);
    }
}
