<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor;

/**
 * Códigos de saída que o painel entende, além dos do Symfony (0 = sucesso, 1 = falha, 2 = uso
 * inválido).
 *
 * Regra para rotina que processa vários itens (Unidades, contas, tenants): item com problema que o
 * próprio cliente resolve nunca impede os outros, e no fim
 *
 *     return $pendencias > 0 ? CronExitCode::PARTIAL : Command::SUCCESS;
 *
 * Erro nosso (serviço externo fora, exceção inesperada) tem precedência e sai Command::FAILURE.
 */
final class CronExitCode
{
    /** Concluída com pendências: linha amarela no painel, não vermelha. */
    public const PARTIAL = 3;

    private function __construct()
    {
    }
}
