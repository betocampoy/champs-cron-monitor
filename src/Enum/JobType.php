<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Enum;

enum JobType: string
{
    /** Comando do console do Symfony: o listener grava início e fim. */
    case Console = 'console';
    /** Script PHP solto (legado): o wrapper mede o tempo e grava a execução inteira no fim. */
    case Script = 'script';
}
