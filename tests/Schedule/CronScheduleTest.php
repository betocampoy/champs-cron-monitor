<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Tests\Schedule;

use BetoCampoy\Champs\CronMonitor\Enum\JobStatus;
use BetoCampoy\Champs\CronMonitor\Enum\JobType;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use PHPUnit\Framework\TestCase;

final class CronScheduleTest extends TestCase
{
    public function testMontaOsJobsDaConfigComPadroes(): void
    {
        $schedule = new CronSchedule([
            'clientes-sync' => ['command' => 'app:clientes:sync --limite=10', 'schedule' => '5 * * * *', 'status' => 'active', 'type' => 'console', 'retention_days' => null, 'blocked_reason' => null],
            'legado-email' => ['command' => 'services/sendEmail.php', 'schedule' => '*/2 * * * *', 'status' => 'planned', 'type' => 'script', 'retention_days' => 14, 'blocked_reason' => null],
        ], 90);

        $jobs = $schedule->all();
        self::assertCount(2, $jobs);

        $sync = $schedule->get('clientes-sync');
        self::assertSame(JobStatus::Active, $sync->status);
        self::assertSame(JobType::Console, $sync->type);
        self::assertSame(90, $sync->retentionDays);
        self::assertSame('app:clientes:sync', $sync->consoleCommandName());
        self::assertTrue($sync->isActive());

        $email = $schedule->get('legado-email');
        self::assertSame(JobType::Script, $email->type);
        self::assertNull($email->consoleCommandName());
        self::assertSame(14, $schedule->retentionDays('legado-email'));
    }

    public function testNomeForaDaAgendaUsaRetencaoPadrao(): void
    {
        $schedule = new CronSchedule([], 30);

        self::assertNull($schedule->get('qualquer'));
        self::assertSame(30, $schedule->retentionDays('qualquer'));
    }
}
