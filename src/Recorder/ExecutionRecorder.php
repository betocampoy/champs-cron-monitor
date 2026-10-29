<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Recorder;

use BetoCampoy\Champs\CronMonitor\Entity\CronExecution;
use BetoCampoy\Champs\CronMonitor\Enum\ExecutionStatus;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use Doctrine\DBAL\Connection;

/**
 * Grava as execuções em champs_cron_execution. Dois caminhos usam:
 *  - EventListener\ConsoleExecutionListener: comando do console rodado pelo wrapper (grava o início,
 *    e o fim quando o comando termina);
 *  - Command\RecordExecutionCommand (champs:cron:record): o wrapper chama quando pula pela trava ou
 *    depois de um script PHP solto (execução inteira de uma vez).
 *
 * Sempre via DBAL, nunca pelo EntityManager: uma exceção dentro do comando fecha o EM e o registro
 * do fim se perderia. Quem chama decide o que fazer com uma falha aqui (nunca derrubar a rotina).
 *
 * Cada gravação de fim apaga o histórico do MESMO job mais velho que a retenção dele (índice
 * name+started_at): a tabela se limpa sozinha, sem outro cron.
 */
class ExecutionRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CronSchedule $schedule,
    ) {
    }

    /** Início de um comando do console. Devolve o id da linha, para o fim. */
    public function start(string $name, string $command, ?int $pid, ?string $host): int
    {
        $this->connection->insert(CronExecution::TABLE, [
            'name' => self::name($name),
            'command' => mb_substr($command, 0, 500),
            'status' => ExecutionStatus::Running->value,
            'started_at' => date('Y-m-d H:i:s'),
            'pid' => $pid,
            'host' => null !== $host ? mb_substr($host, 0, 100) : null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function finish(int $id, string $name, ExecutionStatus $status, int $exitCode, int $durationMs, ?int $memoryPeakMb, ?string $error): void
    {
        // Um comando que estourou dentro de uma transação pode deixá-la aberta e marcada pra
        // rollback: o UPDATE abaixo seria engolido junto.
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection->update(CronExecution::TABLE, [
            'status' => $status->value,
            'finished_at' => date('Y-m-d H:i:s'),
            'duration_ms' => $durationMs,
            'exit_code' => $exitCode,
            'memory_peak_mb' => $memoryPeakMb,
            'error' => null !== $error ? mb_substr($error, 0, 4000) : null,
        ], ['id' => $id]);

        $this->purge($name);
    }

    /** Execução inteira de uma vez (script solto): o wrapper mediu início e fim. */
    public function recordCompleted(string $name, string $command, \DateTimeInterface $start, \DateTimeInterface $end, int $exitCode, ?string $error): void
    {
        $this->connection->insert(CronExecution::TABLE, [
            'name' => self::name($name),
            'command' => mb_substr($command, 0, 500),
            'status' => ExecutionStatus::fromExitCode($exitCode)->value,
            'started_at' => $start->format('Y-m-d H:i:s'),
            'finished_at' => $end->format('Y-m-d H:i:s'),
            'duration_ms' => max(0, (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000)),
            'exit_code' => $exitCode,
            'error' => null !== $error && '' !== trim($error) ? mb_substr(trim($error), 0, 4000) : null,
        ]);

        $this->purge($name);
    }

    /** A execução anterior do mesmo job ainda segurava a trava do wrapper. */
    public function recordSkipped(string $name, string $command): void
    {
        $now = date('Y-m-d H:i:s');
        $this->connection->insert(CronExecution::TABLE, [
            'name' => self::name($name),
            'command' => mb_substr($command, 0, 500),
            'status' => ExecutionStatus::Skipped->value,
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
        ]);
    }

    private function purge(string $name): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . CronExecution::TABLE . ' WHERE name = :name AND started_at < :limit',
            [
                'name' => self::name($name),
                'limit' => date('Y-m-d H:i:s', strtotime('-' . $this->schedule->retentionDays($name) . ' days')),
            ],
        );
    }

    private static function name(string $name): string
    {
        return mb_substr($name, 0, 60);
    }
}
