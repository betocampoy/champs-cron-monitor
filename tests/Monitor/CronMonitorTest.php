<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Tests\Monitor;

use BetoCampoy\Champs\CronMonitor\Entity\CronExecution;
use BetoCampoy\Champs\CronMonitor\Enum\ExecutionStatus;
use BetoCampoy\Champs\CronMonitor\Enum\JobStatus;
use BetoCampoy\Champs\CronMonitor\Enum\JobType;
use BetoCampoy\Champs\CronMonitor\Monitor\CronMonitor;
use BetoCampoy\Champs\CronMonitor\Repository\CronExecutionRepository;
use BetoCampoy\Champs\CronMonitor\Schedule\CronJob;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class CronMonitorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/champs-cron-monitor-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/proc/self', 0777, true);
        mkdir($this->tmp . '/log', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmp);
    }

    public function testJobAtivoSemExecucaoDepoisDaToleranciaEstaAtrasado(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 10:20:00');
        $row = $this->monitor()->row($this->job('5 * * * *'), null, $now);

        self::assertSame(CronMonitor::STATE_NEVER, $row->state);
        self::assertTrue($row->late);
        self::assertEquals(new \DateTimeImmutable('2026-10-29 11:05:00'), $row->next);
    }

    public function testDentroDaToleranciaNaoEstaAtrasado(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 10:12:00');

        self::assertFalse($this->monitor()->row($this->job('5 * * * *'), null, $now)->late);
    }

    public function testRodouNoHorarioNaoEstaAtrasado(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 10:20:00');
        // cron dispara 10:05:00; o PHP sobe um pouco antes de gravar (1 min de folga cobre)
        $last = $this->execution(ExecutionStatus::Success, '2026-10-29 10:04:30', 1500);

        $row = $this->monitor()->row($this->job('5 * * * *'), $last, $now);

        self::assertFalse($row->late);
        self::assertSame(CronMonitor::STATE_SUCCESS, $row->state);
        self::assertSame('1s', $row->duration);
    }

    public function testPlanejadoEBloqueadoNuncaAtrasam(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 10:20:00');

        $planned = $this->monitor()->row($this->job('5 * * * *', JobStatus::Planned), null, $now);
        self::assertFalse($planned->late);
        self::assertNotNull($planned->next);

        $blocked = $this->monitor()->row($this->job('5 * * * *', JobStatus::Blocked), null, $now);
        self::assertFalse($blocked->late);
        self::assertNull($blocked->next);
    }

    public function testRodandoComProcessoVivoNoProc(): void
    {
        mkdir($this->tmp . '/proc/4242');
        file_put_contents($this->tmp . '/proc/4242/cmdline', "php\0bin/console\0app:sync");
        $now = new \DateTimeImmutable('2026-10-29 10:20:00');
        $last = $this->execution(ExecutionStatus::Running, '2026-10-29 10:05:00', null, 4242);

        $row = $this->monitor()->row($this->job('5 * * * *'), $last, $now);

        self::assertSame(CronMonitor::STATE_RUNNING, $row->state);
        self::assertSame('15min 00s', $row->elapsed);
        self::assertFalse($row->late);
    }

    public function testRodandoComProcessoMortoViraInterrompida(): void
    {
        $last = $this->execution(ExecutionStatus::Running, '2026-10-29 10:05:00', null, 4243);

        self::assertSame(CronMonitor::STATE_INTERRUPTED, $this->monitor()->state($last));
    }

    public function testPidReaproveitadoPorOutroProgramaViraInterrompida(): void
    {
        mkdir($this->tmp . '/proc/4244');
        file_put_contents($this->tmp . '/proc/4244/cmdline', "/usr/sbin/nginx\0");
        $last = $this->execution(ExecutionStatus::Running, '2026-10-29 10:05:00', null, 4244);

        self::assertSame(CronMonitor::STATE_INTERRUPTED, $this->monitor()->state($last));
    }

    public function testSemProcNemPosixConfiaNoStatusGravado(): void
    {
        rmdir($this->tmp . '/proc/self');
        $monitor = new class(...$this->monitorArgs()) extends CronMonitor {
            protected function posixAvailable(): bool
            {
                return false;
            }
        };
        $last = $this->execution(ExecutionStatus::Running, '2026-10-29 10:05:00', null, 4245);

        self::assertNull($monitor->isProcessAlive($last));
        self::assertSame(CronMonitor::STATE_RUNNING, $monitor->state($last));
    }

    public function testExecucaoDeOutraMaquinaNaoEVerificavel(): void
    {
        $last = CronExecution::fromArray([
            'name' => 'sync', 'status' => ExecutionStatus::Running,
            'startedAt' => new \DateTimeImmutable(), 'pid' => 1, 'host' => 'outra-maquina-' . bin2hex(random_bytes(3)),
        ]);

        self::assertNull($this->monitor()->isProcessAlive($last));
    }

    public function testEstadosGravados(): void
    {
        $m = $this->monitor();

        self::assertSame(CronMonitor::STATE_PARTIAL, $m->state($this->execution(ExecutionStatus::Partial, '2026-10-29 10:00:00', 10)));
        self::assertSame(CronMonitor::STATE_FAILURE, $m->state($this->execution(ExecutionStatus::Failure, '2026-10-29 10:00:00', 10)));
        self::assertSame(CronMonitor::STATE_SKIPPED, $m->state($this->execution(ExecutionStatus::Skipped, '2026-10-29 10:00:00', 0)));
        self::assertSame(CronMonitor::STATE_INTERRUPTED, $m->state($this->execution(ExecutionStatus::Interrupted, '2026-10-29 10:00:00', 10)));
    }

    public function testLogFileRecusaNomeQueViraCaminho(): void
    {
        file_put_contents($this->tmp . '/log/sync.log', "linha 1\nlinha 2\nlinha 3\n");
        $m = $this->monitor();

        self::assertNull($m->logFile('../../.env'));
        self::assertNull($m->logFile('Sync'));
        self::assertNull($m->logFile('nao-existe'));
        self::assertSame($this->tmp . '/log/sync.log', $m->logFile('sync'));
        self::assertSame("linha 2\nlinha 3", $m->tailLog('sync', 2));
    }

    public function testFormatDuration(): void
    {
        self::assertSame('850 ms', CronMonitor::formatDuration(850));
        self::assertSame('42s', CronMonitor::formatDuration(42_000));
        self::assertSame('3min 05s', CronMonitor::formatDuration(185_000));
        self::assertSame('2h 01min', CronMonitor::formatDuration(7_260_000));
    }

    private function job(string $schedule, JobStatus $status = JobStatus::Active): CronJob
    {
        return new CronJob('sync', 'app:sync', $schedule, $status, JobType::Console, 90);
    }

    private function execution(ExecutionStatus $status, string $startedAt, ?int $durationMs, ?int $pid = null): CronExecution
    {
        return CronExecution::fromArray([
            'name' => 'sync',
            'command' => 'app:sync',
            'status' => $status,
            'startedAt' => new \DateTimeImmutable($startedAt),
            'durationMs' => $durationMs,
            'pid' => $pid,
            'host' => null !== $pid ? gethostname() : null,
        ]);
    }

    private function monitor(): CronMonitor
    {
        return new CronMonitor(...$this->monitorArgs());
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorArgs(): array
    {
        return [
            'schedule' => new CronSchedule([]),
            'executions' => $this->createMock(CronExecutionRepository::class),
            'connection' => $this->createMock(Connection::class),
            'logDir' => $this->tmp . '/log',
            'projectDir' => $this->tmp,
            'procDir' => $this->tmp . '/proc',
        ];
    }
}
