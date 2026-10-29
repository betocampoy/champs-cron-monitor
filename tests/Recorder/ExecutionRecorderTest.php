<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Tests\Recorder;

use BetoCampoy\Champs\CronMonitor\CronExitCode;
use BetoCampoy\Champs\CronMonitor\Entity\CronExecution;
use BetoCampoy\Champs\CronMonitor\Enum\ExecutionStatus;
use BetoCampoy\Champs\CronMonitor\Recorder\ExecutionRecorder;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ExecutionRecorderTest extends TestCase
{
    public function testScriptComCodigoParcialGravaParcialEExpurgaPelaRetencaoDoJob(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            CronExecution::TABLE,
            self::callback(static fn (array $row) => 'partial' === $row['status'] && 2500 === $row['duration_ms'] && 'falhou em 1 conta' === $row['error']),
        );
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('DELETE FROM ' . CronExecution::TABLE),
            self::callback(static fn (array $p) => 'legado' === $p['name'] && $p['limit'] === date('Y-m-d', strtotime('-14 days')) . substr($p['limit'], 10)),
        );

        (new ExecutionRecorder($connection, $this->schedule()))->recordCompleted(
            'legado',
            'php services/x.php',
            new \DateTimeImmutable('2026-10-29 10:00:00.000'),
            new \DateTimeImmutable('2026-10-29 10:00:02.500'),
            CronExitCode::PARTIAL,
            "  falhou em 1 conta \n",
        );
    }

    public function testFimDesfazTransacaoAbertaAntesDeGravar(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturnOnConsecutiveCalls(true, true, false);
        $connection->expects(self::exactly(2))->method('rollBack');
        $connection->expects(self::once())->method('update')->with(
            CronExecution::TABLE,
            self::callback(static fn (array $row) => 'failure' === $row['status'] && 1 === $row['exit_code']),
            ['id' => 7],
        );

        (new ExecutionRecorder($connection, $this->schedule()))->finish(7, 'sync', ExecutionStatus::Failure, 1, 100, 30, 'boom');
    }

    public function testPuladaNaoExpurga(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(CronExecution::TABLE, self::callback(static fn (array $row) => 'skipped' === $row['status']));
        $connection->expects(self::never())->method('executeStatement');

        (new ExecutionRecorder($connection, $this->schedule()))->recordSkipped('sync', 'app:sync');
    }

    private function schedule(): CronSchedule
    {
        return new CronSchedule([
            'legado' => ['command' => 'services/x.php', 'schedule' => '* * * * *', 'status' => 'active', 'type' => 'script', 'retention_days' => 14, 'blocked_reason' => null],
        ], 90);
    }
}
