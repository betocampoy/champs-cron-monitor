<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Tests\Kernel;

use BetoCampoy\Champs\CronMonitor\Controller\CronMonitorController;
use BetoCampoy\Champs\CronMonitor\EventListener\ConsoleExecutionListener;
use BetoCampoy\Champs\CronMonitor\Monitor\CronMonitor;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BundleBootTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/champs-cron-monitor-kernel');
    }

    public function testServicosRotasETemplates(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // nome com hífen não pode virar "_" na normalização da config do Symfony
        self::assertSame(['clientes-sync', 'legado-email'], array_map(static fn ($j) => $j->name, $container->get(CronSchedule::class)->all()));
        self::assertSame(['default' => 'async', 'fila-lenta' => 'lenta'], $container->getParameter('champs_cron_monitor.messenger.queues'));
        self::assertInstanceOf(CronMonitor::class, $container->get(CronMonitor::class));
        self::assertTrue($container->get(CronMonitor::class)->isMessengerEnabled());
        self::assertTrue($container->has(CronMonitorController::class));
        self::assertTrue($container->has(ConsoleExecutionListener::class));

        $router = $container->get('router');
        self::assertSame('/admin/rotinas', $router->generate('champs_cron_monitor_index'));
        self::assertSame('/admin/rotinas/clientes-sync/log', $router->generate('champs_cron_monitor_log', ['name' => 'clientes-sync']));
        // só as rotas do import explícito, sem duplicata sem prefixo
        $paths = array_map(static fn ($r) => $r->getPath(), iterator_to_array($router->getRouteCollection()));
        self::assertSame([], array_values(array_filter($paths, static fn ($p) => !str_starts_with($p, '/admin/rotinas'))));

        $twig = $container->get('twig');
        foreach (['index', 'show', '_jobs', '_state', '_queues', '_workers', '_history', '_log'] as $template) {
            $twig->load('@ChampsCronMonitor/admin/' . $template . '.html.twig');
        }
    }

    public function testComandosCrontabECheck(): void
    {
        $application = new Application(self::bootKernel());

        $crontab = new CommandTester($application->find('champs:cron:crontab'));
        $crontab->execute(['--all' => true, '--project-dir' => '/home/app/projeto']);
        $out = $crontab->getDisplay();
        self::assertStringContainsString('5 * * * * CHAMPS_CRON_PHP=/usr/local/php82/bin/php82 bash /home/app/projeto/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh clientes-sync champs:cron:check', $out);
        self::assertStringContainsString('# planejado: */2 * * * *', $out);

        // falta en/es das traduções do kernel de teste: o check acusa
        $check = new CommandTester($application->find('champs:cron:check'));
        self::assertSame(1, $check->execute([]));
        self::assertStringContainsString('clientes-sync: falta a tradução "clientes-sync.description" no domínio cron_jobs (en)', $check->getDisplay());
        self::assertStringNotContainsString('(pt_BR)', $check->getDisplay());
        self::assertStringNotContainsString('não existe', $check->getDisplay());
    }
}
