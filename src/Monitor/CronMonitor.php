<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Monitor;

use BetoCampoy\Champs\CronMonitor\ChampsCronMonitorBundle;
use BetoCampoy\Champs\CronMonitor\Entity\CronExecution;
use BetoCampoy\Champs\CronMonitor\Enum\ExecutionStatus;
use BetoCampoy\Champs\CronMonitor\Repository\CronExecutionRepository;
use BetoCampoy\Champs\CronMonitor\Schedule\CronJob;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use Cron\CronExpression;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Monta o painel: agenda × última execução gravada, puladas pela trava, filas do Messenger, workers
 * vivos e o fim do log de cada job. Só leitura: o painel nunca dispara nem interrompe nada.
 *
 * Processos (execução "running" ainda viva? quais workers existem?) são lidos do /proc: o cron, os
 * workers do supervisor e o PHP do site costumam rodar com o mesmo usuário no servidor, então o
 * site enxerga esses processos. Fora do Linux (dev no Windows) não há /proc e o painel diz "não
 * verificável" em vez de chutar.
 */
class CronMonitor
{
    public const STATE_NEVER = 'never';
    public const STATE_RUNNING = 'running';
    public const STATE_INTERRUPTED = 'interrupted';
    public const STATE_SUCCESS = 'success';
    public const STATE_FAILURE = 'failure';
    public const STATE_PARTIAL = 'partial';
    public const STATE_SKIPPED = 'skipped';

    /**
     * @param array<string, string> $queues queue_name => nome mostrado
     */
    public function __construct(
        private readonly CronSchedule $schedule,
        private readonly CronExecutionRepository $executions,
        private readonly Connection $connection,
        #[Autowire(param: 'champs_cron_monitor.log_dir')] private readonly string $logDir,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
        #[Autowire(param: 'champs_cron_monitor.late_tolerance_minutes')] private readonly int $lateToleranceMinutes = 10,
        #[Autowire(param: 'champs_cron_monitor.skipped_window_hours')] private readonly int $skippedWindowHours = 24,
        #[Autowire(param: 'champs_cron_monitor.messenger.enabled')] private readonly bool $messengerEnabled = false,
        #[Autowire(param: 'champs_cron_monitor.messenger.table')] private readonly string $messengerTable = 'messenger_messages',
        #[Autowire(param: 'champs_cron_monitor.messenger.queues')] private readonly array $queues = ['default' => 'async'],
        #[Autowire(param: 'champs_cron_monitor.messenger.failed_queue')] private readonly string $failedQueue = 'failed',
        #[Autowire(param: 'champs_cron_monitor.messenger.message_type_pattern')] private readonly string $messageTypePattern = '',
        private readonly string $procDir = '/proc',
    ) {
    }

    public function isMessengerEnabled(): bool
    {
        return $this->messengerEnabled;
    }

    public function skippedWindowHours(): int
    {
        return $this->skippedWindowHours;
    }

    /**
     * Uma linha por job da agenda, mais as execuções de nomes fora dela (um job colado no crontab
     * sem passar pelo YAML aparece, para ninguém esquecer de registrar).
     *
     * @return list<JobRow>
     */
    public function rows(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $lasts = $this->executions->findLastByName();
        $skipped = $this->executions->countSkippedSince($now->modify('-' . $this->skippedWindowHours . ' hours'));

        $rows = [];
        foreach ($this->schedule->all() as $job) {
            $rows[] = $this->row($job, $lasts[$job->name] ?? null, $now, $skipped[$job->name] ?? 0);
            unset($lasts[$job->name], $skipped[$job->name]);
        }

        foreach ($lasts as $name => $execution) {
            $job = CronJob::unscheduled($name, $execution->getCommand(), $this->schedule->defaultRetentionDays());
            $rows[] = $this->row($job, $execution, $now, $skipped[$name] ?? 0);
        }

        return $rows;
    }

    public function row(CronJob $job, ?CronExecution $last, \DateTimeImmutable $now, int $skipped = 0): JobRow
    {
        $state = $this->state($last);

        $next = null;
        $late = false;
        if (null !== $job->schedule && !$job->isBlocked()) {
            $cron = new CronExpression($job->schedule);
            $next = \DateTimeImmutable::createFromMutable($cron->getNextRunDate($now));

            if ($job->isActive() && self::STATE_RUNNING !== $state) {
                $expected = \DateTimeImmutable::createFromMutable($cron->getPreviousRunDate($now, 0, true));
                $pastTolerance = $now >= $expected->modify('+' . $this->lateToleranceMinutes . ' minutes');
                // 1 min de folga: o cron dispara no minuto cheio e o PHP leva um instante pra subir.
                $notRunSinceExpected = null === $last || $last->getStartedAt() < $expected->modify('-1 minute');
                $late = $pastTolerance && $notRunSinceExpected;
            }
        }

        return new JobRow(
            $job,
            $last,
            $state,
            self::STATE_RUNNING === $state ? self::formatDuration(($now->getTimestamp() - $last->getStartedAt()->getTimestamp()) * 1000) : null,
            null !== $last?->getDurationMs() ? self::formatDuration($last->getDurationMs()) : null,
            $next,
            $late,
            $skipped,
        );
    }

    public function state(?CronExecution $execution): string
    {
        if (null === $execution) {
            return self::STATE_NEVER;
        }

        return match ($execution->getStatus()) {
            ExecutionStatus::Running => false === $this->isProcessAlive($execution) ? self::STATE_INTERRUPTED : self::STATE_RUNNING,
            ExecutionStatus::Success => self::STATE_SUCCESS,
            ExecutionStatus::Partial => self::STATE_PARTIAL,
            ExecutionStatus::Interrupted => self::STATE_INTERRUPTED,
            ExecutionStatus::Skipped => self::STATE_SKIPPED,
            ExecutionStatus::Failure => self::STATE_FAILURE,
        };
    }

    /**
     * true/false quando dá pra verificar; null quando não dá (gravada por outra máquina, ou nem
     * /proc nem posix disponíveis): aí o painel confia no status gravado.
     *
     * 1º /proc/<pid>/cmdline (confere também que o pid ainda é um bin/console, não outro processo
     * que reaproveitou o número). 2º posix_kill(pid, 0), que não depende de ler arquivo: em
     * hospedagem com open_basedir o PHP do site não lê /proc (achado no MEMOD em 2026-10-15: uma
     * execução interrompida com Ctrl+C ficou "Rodando" no painel).
     */
    public function isProcessAlive(CronExecution $execution): ?bool
    {
        $pid = $execution->getPid();
        if (null === $pid) {
            return null;
        }
        if (null !== $execution->getHost() && $execution->getHost() !== gethostname()) {
            return null;
        }

        if (@is_dir($this->procDir . '/self')) {
            $cmdline = @file_get_contents($this->procDir . '/' . $pid . '/cmdline');

            return false !== $cmdline && str_contains($cmdline, 'bin/console');
        }

        if ($this->posixAvailable()) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            // EPERM (1): o processo existe, só é de outro usuário
            return 1 === posix_get_last_error();
        }

        return null;
    }

    /** Separado só pra teste conseguir simular servidor sem posix. */
    protected function posixAvailable(): bool
    {
        return \function_exists('posix_kill') && \function_exists('posix_get_last_error');
    }

    /**
     * Pendentes por fila (prontas pra rodar, agendadas pro futuro e em processamento agora) e as que
     * falharam de vez, por tipo. Só transporte Doctrine em MySQL/MariaDB (TIMESTAMPDIFF,
     * REGEXP_SUBSTR). null = Messenger desligado na config do bundle.
     *
     * @return array{queues: list<array{name: string, ready: int, scheduled: int, processing: int, longestWait: ?string}>, failures: list<array{type: ?string, total: int}>, totalFailures: int}|null
     */
    public function queues(): ?array
    {
        if (!$this->messengerEnabled) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT queue_name,
                    SUM(CASE WHEN delivered_at IS NULL AND available_at <= NOW() THEN 1 ELSE 0 END) AS ready,
                    SUM(CASE WHEN delivered_at IS NULL AND available_at > NOW() THEN 1 ELSE 0 END) AS scheduled,
                    SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS processing,
                    MAX(CASE WHEN delivered_at IS NULL AND available_at <= NOW() THEN TIMESTAMPDIFF(SECOND, available_at, NOW()) END) AS longest_wait_s
               FROM ' . $this->messengerTable . '
              WHERE queue_name <> :failed
              GROUP BY queue_name',
            ['failed' => $this->failedQueue],
        );
        $byQueue = array_column($rows, null, 'queue_name');

        $queues = [];
        foreach ($this->queues as $queueName => $label) {
            $q = $byQueue[$queueName] ?? [];
            $queues[] = [
                'name' => $label,
                'ready' => (int) ($q['ready'] ?? 0),
                'scheduled' => (int) ($q['scheduled'] ?? 0),
                'processing' => (int) ($q['processing'] ?? 0),
                'longestWait' => isset($q['longest_wait_s']) ? self::formatDuration((int) $q['longest_wait_s'] * 1000) : null,
            ];
        }

        $failures = array_map(
            static fn (array $f) => ['type' => $f['type'], 'total' => (int) $f['total']],
            $this->connection->fetchAllAssociative(
                'SELECT REGEXP_SUBSTR(body, :pattern) AS type, COUNT(*) AS total
                   FROM ' . $this->messengerTable . '
                  WHERE queue_name = :failed
                  GROUP BY type
                  ORDER BY total DESC',
                ['pattern' => $this->messageTypePattern, 'failed' => $this->failedQueue],
            ),
        );

        return ['queues' => $queues, 'failures' => $failures, 'totalFailures' => array_sum(array_column($failures, 'total'))];
    }

    /**
     * Workers do Messenger deste projeto rodando agora (lidos do /proc). null = não verificável
     * neste servidor.
     *
     * O supervisor costuma subir o worker com bin/console relativo, então o caminho do projeto nem
     * sempre está na linha de comando: confere também a pasta de trabalho do processo
     * (/proc/<pid>/cwd). Sem isso os workers de outro projeto no mesmo servidor apareceriam aqui.
     *
     * @return list<array{pid: int, queues: string}>|null
     */
    public function workers(): ?array
    {
        if (!@is_dir($this->procDir . '/self')) {
            return null;
        }

        $projectDir = rtrim($this->projectDir, '/');
        $workers = [];
        foreach (glob($this->procDir . '/[0-9]*/cmdline') ?: [] as $file) {
            $cmdline = str_replace("\0", ' ', (string) @file_get_contents($file));
            if (!str_contains($cmdline, 'messenger:consume')) {
                continue;
            }

            $pidDir = \dirname($file);
            $cwd = @readlink($pidDir . '/cwd');
            $ownProject = str_contains($cmdline, $projectDir . '/')
                || (false !== $cwd && rtrim($cwd, '/') === $projectDir);
            if (!$ownProject) {
                continue;
            }

            preg_match('/messenger:consume((?:\s+[a-z_]+)+)/', $cmdline, $m);
            $workers[] = ['pid' => (int) basename($pidDir), 'queues' => trim($m[1] ?? '')];
        }

        usort($workers, static fn ($a, $b) => strcmp($a['queues'], $b['queues']));

        return $workers;
    }

    /**
     * Últimas linhas do log do job (var/log/cron/<nome>.log). null = arquivo não existe.
     */
    public function tailLog(string $name, int $lines = 200): ?string
    {
        $file = $this->logFile($name);
        if (null === $file) {
            return null;
        }

        // Lê só o fim do arquivo (até 256 KB): o log pode chegar a 5 MB antes de girar.
        $size = (int) filesize($file);
        $read = min($size, 262144);
        if (0 === $read) {
            return '';
        }
        $handle = fopen($file, 'rb');
        fseek($handle, $size - $read);
        $content = (string) fread($handle, $read);
        fclose($handle);

        $all = preg_split('/\R/', rtrim($content));
        if ($read < $size) {
            array_shift($all); // primeira linha provavelmente cortada no meio
        }

        return implode("\n", \array_slice($all, -$lines));
    }

    /**
     * Caminho do log do job, ou null se o nome for inválido ou o arquivo não existir. O nome é
     * validado aqui: é o que impede um nome como "../../.env" de virar caminho de arquivo no
     * download.
     */
    public function logFile(string $name): ?string
    {
        if (!preg_match(ChampsCronMonitorBundle::JOB_NAME_PATTERN, $name)) {
            return null;
        }

        $file = $this->logDir . '/' . $name . '.log';

        return is_file($file) ? $file : null;
    }

    /** Caminho do log relativo ao projeto, para exibir ("var/log/cron/foo.log"). */
    public function logPathForDisplay(string $name): string
    {
        $path = $this->logDir . '/' . $name . '.log';
        $prefix = rtrim($this->projectDir, '/\\') . '/';

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
    }

    public static function formatDuration(int $ms): string
    {
        if ($ms < 1000) {
            return $ms . ' ms';
        }

        $s = intdiv($ms, 1000);
        if ($s < 60) {
            return $s . 's';
        }
        if ($s < 3600) {
            return \sprintf('%dmin %02ds', intdiv($s, 60), $s % 60);
        }

        return \sprintf('%dh %02dmin', intdiv($s, 3600), intdiv($s % 3600, 60));
    }
}
