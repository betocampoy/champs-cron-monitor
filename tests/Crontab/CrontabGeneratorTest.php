<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Tests\Crontab;

use BetoCampoy\Champs\CronMonitor\Crontab\CrontabGenerator;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use PHPUnit\Framework\TestCase;

final class CrontabGeneratorTest extends TestCase
{
    private const WRAPPER = '/dev/app/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh';

    public function testSoAtivosPorPadrao(): void
    {
        $out = $this->generator()->generate();

        self::assertStringContainsString('5 * * * * bash ' . self::WRAPPER . ' sync app:sync --limite=10', $out);
        self::assertStringNotContainsString('legado', $out);
        self::assertStringNotContainsString('analitico', $out);
    }

    public function testAllIncluiPlanejadosEBloqueadosComentados(): void
    {
        $out = $this->generator()->generate(true);

        self::assertStringContainsString('# planejado: */2 * * * * bash ' . self::WRAPPER . ' legado-email php services/sendEmail.php', $out);
        self::assertStringContainsString('# bloqueado (turno vencido): 0 2 * * * bash ' . self::WRAPPER . ' analitico app:analitico', $out);
    }

    public function testTrocaPastaDoProjetoEPhpDoServidor(): void
    {
        $out = $this->generator()->generate(false, '/home/app/projeto', '/usr/local/php82/bin/php82');

        self::assertStringContainsString(
            '5 * * * * CHAMPS_CRON_PHP=/usr/local/php82/bin/php82 bash /home/app/projeto/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh sync app:sync --limite=10',
            $out,
        );
    }

    public function testEscapaPorcentagem(): void
    {
        $generator = new CrontabGenerator(
            new CronSchedule(['data' => ['command' => 'app:x --formato=%Y', 'schedule' => '0 1 * * *', 'status' => 'active', 'type' => 'console', 'retention_days' => null, 'blocked_reason' => null]]),
            self::WRAPPER,
            '/dev/app',
        );

        self::assertStringContainsString('app:x --formato=\%Y', $generator->generate());
    }

    private function generator(): CrontabGenerator
    {
        $base = ['type' => 'console', 'retention_days' => null, 'blocked_reason' => null];

        return new CrontabGenerator(
            new CronSchedule([
                'sync' => ['command' => 'app:sync --limite=10', 'schedule' => '5 * * * *', 'status' => 'active'] + $base,
                'legado-email' => ['command' => 'services/sendEmail.php', 'schedule' => '*/2 * * * *', 'status' => 'planned', 'type' => 'script'] + $base,
                'analitico' => ['command' => 'app:analitico', 'schedule' => '0 2 * * *', 'status' => 'blocked', 'blocked_reason' => 'turno vencido'] + $base,
            ]),
            self::WRAPPER,
            '/dev/app',
        );
    }
}
