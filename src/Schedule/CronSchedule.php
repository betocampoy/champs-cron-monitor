<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Schedule;

use BetoCampoy\Champs\CronMonitor\Enum\JobStatus;
use BetoCampoy\Champs\CronMonitor\Enum\JobType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A agenda do cron, lida de champs_cron_monitor.jobs. É a fonte única: o crontab do servidor é
 * gerado dela (champs:cron:crontab) e o painel cobra atraso a partir dela.
 *
 * Mudou o crontab? Mude o YAML, rode champs:cron:check e gere as linhas de novo.
 */
final class CronSchedule
{
    /** @var array<string, CronJob> */
    private array $jobs = [];

    /**
     * @param array<string, array{command: string, schedule: string, status: string, type: string, retention_days: ?int, blocked_reason: ?string}> $jobs
     */
    public function __construct(
        #[Autowire(param: 'champs_cron_monitor.jobs')] array $jobs,
        #[Autowire(param: 'champs_cron_monitor.default_retention_days')] private readonly int $defaultRetentionDays = 90,
    ) {
        foreach ($jobs as $name => $job) {
            $this->jobs[(string) $name] = new CronJob(
                (string) $name,
                $job['command'],
                $job['schedule'],
                JobStatus::from($job['status'] ?? JobStatus::Planned->value),
                JobType::from($job['type'] ?? JobType::Console->value),
                $job['retention_days'] ?? $defaultRetentionDays,
                $job['blocked_reason'] ?? null,
            );
        }
    }

    /**
     * @return list<CronJob>
     */
    public function all(): array
    {
        return array_values($this->jobs);
    }

    public function get(string $name): ?CronJob
    {
        return $this->jobs[$name] ?? null;
    }

    /** Dias de histórico do job; nome fora da agenda usa o padrão. */
    public function retentionDays(string $name): int
    {
        return $this->jobs[$name]->retentionDays ?? $this->defaultRetentionDays;
    }

    public function defaultRetentionDays(): int
    {
        return $this->defaultRetentionDays;
    }
}
