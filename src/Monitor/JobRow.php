<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Monitor;

use BetoCampoy\Champs\CronMonitor\Entity\CronExecution;
use BetoCampoy\Champs\CronMonitor\Schedule\CronJob;

/**
 * Uma linha do painel: o job da agenda com a última execução e o que se deduz dela.
 */
final class JobRow
{
    public function __construct(
        public readonly CronJob $job,
        public readonly ?CronExecution $last,
        /** Um dos CronMonitor::STATE_*. */
        public readonly string $state,
        /** Tempo desde o início, quando está rodando ("3min 05s"). */
        public readonly ?string $elapsed,
        /** Duração da última execução terminada. */
        public readonly ?string $duration,
        public readonly ?\DateTimeImmutable $next,
        public readonly bool $late,
        /** Execuções puladas pela trava na janela skipped_window_hours. */
        public readonly int $skipped = 0,
    ) {
    }

    /** Linha que pede atenção de quem cuida da plataforma (vermelho no painel). */
    public function isProblem(): bool
    {
        return $this->late || \in_array($this->state, [CronMonitor::STATE_FAILURE, CronMonitor::STATE_INTERRUPTED], true);
    }
}
