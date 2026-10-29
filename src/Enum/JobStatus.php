<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Enum;

/**
 * Situação de um job na agenda (não confundir com o estado de uma execução, ExecutionStatus).
 */
enum JobStatus: string
{
    /** Está no crontab do servidor: o painel cobra atraso. */
    case Active = 'active';
    /** Definido, mas ainda não colado no crontab. */
    case Planned = 'planned';
    /** Não pode rodar agora (motivo em blocked_reason). Sem próxima execução nem atraso. */
    case Blocked = 'blocked';
}
