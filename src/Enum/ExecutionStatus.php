<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Enum;

use BetoCampoy\Champs\CronMonitor\CronExitCode;

/**
 * Estado gravado de uma execução (coluna status de champs_cron_execution).
 */
enum ExecutionStatus: string
{
    case Running = 'running';
    case Success = 'success';
    /** Código de saída diferente de 0 e de CronExitCode::PARTIAL. */
    case Failure = 'failure';
    /** A rotina funcionou, mas algum item ficou de fora por um problema que o cliente resolve. */
    case Partial = 'partial';
    /** Encerrada por sinal (Ctrl+C, kill). Só gravado quando o PHP tem pcntl; sem ele o painel deduz pelo processo. */
    case Interrupted = 'interrupted';
    /** O cron disparou, mas a execução anterior ainda segurava a trava do wrapper. */
    case Skipped = 'skipped';

    public function isSkipped(): bool
    {
        return self::Skipped === $this;
    }

    public static function fromExitCode(int $exitCode): self
    {
        return match ($exitCode) {
            0 => self::Success,
            CronExitCode::PARTIAL => self::Partial,
            default => self::Failure,
        };
    }
}
