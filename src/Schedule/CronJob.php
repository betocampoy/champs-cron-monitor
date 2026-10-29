<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Schedule;

use BetoCampoy\Champs\CronMonitor\Enum\JobStatus;
use BetoCampoy\Champs\CronMonitor\Enum\JobType;

/**
 * Um job da agenda (uma entrada de champs_cron_monitor.jobs = uma linha do crontab).
 *
 * Job "fora da agenda" (rodou pelo wrapper com um nome que não está no YAML) também vira um
 * CronJob, com schedule e status null: aparece no painel para ninguém esquecer de registrar.
 */
final class CronJob
{
    public function __construct(
        public readonly string $name,
        public readonly string $command,
        public readonly ?string $schedule,
        public readonly ?JobStatus $status,
        public readonly JobType $type,
        public readonly int $retentionDays,
        public readonly ?string $blockedReason = null,
    ) {
    }

    public static function unscheduled(string $name, string $command, int $retentionDays): self
    {
        return new self($name, $command, null, null, JobType::Console, $retentionDays);
    }

    public function isScheduled(): bool
    {
        return null !== $this->status;
    }

    public function isActive(): bool
    {
        return JobStatus::Active === $this->status;
    }

    public function isBlocked(): bool
    {
        return JobStatus::Blocked === $this->status;
    }

    /** Nome do comando do console, sem as opções (ex.: "app:foo" de "app:foo --limite=10"). */
    public function consoleCommandName(): ?string
    {
        if (JobType::Console !== $this->type) {
            return null;
        }

        return strtok($this->command, ' ') ?: null;
    }
}
