<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Entity;

use BetoCampoy\Champs\CronMonitor\Enum\ExecutionStatus;
use BetoCampoy\Champs\CronMonitor\Repository\CronExecutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma execução de um job do cron. Só é gravada quando o job roda pelo wrapper bin/champs-cron.sh
 * (que exporta CHAMPS_CRON_NAME): execução manual no terminal não aparece aqui.
 *
 * Quem grava é o Recorder\ExecutionRecorder, via DBAL (não pelo EntityManager do comando: uma
 * exceção dentro do comando fecha o EM e o registro do fim se perderia). Esta entidade existe para
 * a leitura do painel e para o schema/migration.
 *
 * status = running com o processo já morto (kill, reinício do servidor) aparece no painel como
 * interrompida: ver Monitor\CronMonitor::isProcessAlive().
 */
#[ORM\Entity(repositoryClass: CronExecutionRepository::class, readOnly: true)]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_champs_cron_execution_name_started', columns: ['name', 'started_at'])]
#[ORM\Index(name: 'idx_champs_cron_execution_status', columns: ['status'])]
class CronExecution
{
    public const TABLE = 'champs_cron_execution';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nome do job (o mesmo do log var/log/cron/<nome>.log e da chave na agenda). */
    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(length: 500)]
    private string $command;

    #[ORM\Column(length: 12, enumType: ExecutionStatus::class)]
    private ExecutionStatus $status;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'duration_ms', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'exit_code', type: Types::SMALLINT, nullable: true)]
    private ?int $exitCode = null;

    #[ORM\Column(nullable: true)]
    private ?int $pid = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $host = null;

    #[ORM\Column(name: 'memory_peak_mb', nullable: true)]
    private ?int $memoryPeakMb = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getStatus(): ExecutionStatus
    {
        return $this->status;
    }

    public function isRunning(): bool
    {
        return ExecutionStatus::Running === $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getMemoryPeakMb(): ?int
    {
        return $this->memoryPeakMb;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Monta uma execução em memória (testes e leitura fora do Doctrine).
     *
     * @param array{id?: ?int, name: string, command?: string, status: ExecutionStatus, startedAt: \DateTimeImmutable, finishedAt?: ?\DateTimeImmutable, durationMs?: ?int, exitCode?: ?int, pid?: ?int, host?: ?string, memoryPeakMb?: ?int, error?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        $e = new self();
        $e->id = $data['id'] ?? null;
        $e->name = $data['name'];
        $e->command = $data['command'] ?? '';
        $e->status = $data['status'];
        $e->startedAt = $data['startedAt'];
        $e->finishedAt = $data['finishedAt'] ?? null;
        $e->durationMs = $data['durationMs'] ?? null;
        $e->exitCode = $data['exitCode'] ?? null;
        $e->pid = $data['pid'] ?? null;
        $e->host = $data['host'] ?? null;
        $e->memoryPeakMb = $data['memoryPeakMb'] ?? null;
        $e->error = $data['error'] ?? null;

        return $e;
    }
}
