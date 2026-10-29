<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Crontab;

use BetoCampoy\Champs\CronMonitor\Enum\JobStatus;
use BetoCampoy\Champs\CronMonitor\Enum\JobType;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Linhas do crontab a partir da agenda. Cada linha é autossuficiente (caminho completo do wrapper
 * e o PHP na própria linha), sem variáveis no topo do crontab: o crontab costuma ser compartilhado
 * com outros projetos, e um "CRON=" ou "PHP=" de um bloco vazaria para as linhas dos outros.
 */
final class CrontabGenerator
{
    public function __construct(
        private readonly CronSchedule $schedule,
        #[Autowire(param: 'champs_cron_monitor.crontab.wrapper')] private readonly string $wrapper,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
        #[Autowire(param: 'champs_cron_monitor.crontab.project_dir')] private readonly ?string $serverProjectDir = null,
        #[Autowire(param: 'champs_cron_monitor.crontab.php_binary')] private readonly ?string $phpBinary = null,
    ) {
    }

    /**
     * @param bool $all inclui, comentados, os planejados e os bloqueados
     */
    public function generate(bool $all = false, ?string $serverProjectDir = null, ?string $phpBinary = null): string
    {
        $wrapper = $this->wrapperPath($serverProjectDir ?? $this->serverProjectDir);
        $php = $phpBinary ?? $this->phpBinary;
        $prefix = null !== $php && '' !== $php ? 'CHAMPS_CRON_PHP=' . self::quote($php) . ' ' : '';

        $lines = [
            '# champs-cron-monitor: gerado pelo champs:cron:crontab. Mude a agenda no YAML e gere de novo.',
        ];
        foreach ($this->schedule->all() as $job) {
            if (JobStatus::Active !== $job->status && !$all) {
                continue;
            }

            $command = JobType::Script === $job->type ? 'php ' . $job->command : $job->command;
            // "%" no crontab vira quebra de linha: precisa de barra antes.
            $line = str_replace('%', '\%', \sprintf('%s %sbash %s %s %s', $job->schedule, $prefix, self::quote($wrapper), $job->name, $command));

            $lines[] = match ($job->status) {
                JobStatus::Active => $line,
                JobStatus::Planned => '# planejado: ' . $line,
                JobStatus::Blocked => '# bloqueado' . (null !== $job->blockedReason ? ' (' . $job->blockedReason . ')' : '') . ': ' . $line,
                null => $line,
            };
        }

        return implode("\n", $lines) . "\n";
    }

    /** Troca a pasta do projeto local pela do servidor no caminho do wrapper. */
    private function wrapperPath(?string $serverProjectDir): string
    {
        $wrapper = str_replace('\\', '/', $this->wrapper);
        if (null === $serverProjectDir || '' === $serverProjectDir) {
            return $wrapper;
        }

        $local = rtrim(str_replace('\\', '/', $this->projectDir), '/') . '/';
        if (str_starts_with($wrapper, $local)) {
            return rtrim($serverProjectDir, '/') . '/' . substr($wrapper, \strlen($local));
        }

        return $wrapper;
    }

    /** Só põe aspas quando precisa: o crontab legível é mais fácil de conferir. */
    private static function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_\/.\-]+$/', $value) ? $value : escapeshellarg($value);
    }
}
