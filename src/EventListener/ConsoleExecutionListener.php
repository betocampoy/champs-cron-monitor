<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\EventListener;

use BetoCampoy\Champs\CronMonitor\Command\RecordExecutionCommand;
use BetoCampoy\Champs\CronMonitor\Enum\ExecutionStatus;
use BetoCampoy\Champs\CronMonitor\Recorder\ExecutionRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Grava início e fim de todo comando do console rodado pelo wrapper (bin/champs-cron.sh). O wrapper
 * exporta CHAMPS_CRON_NAME; sem essa variável (comando rodado à mão, worker do Messenger) não grava
 * nada.
 *
 * Nenhum comando precisa saber disto: comando novo agendado pelo wrapper já aparece no painel.
 * Uma falha aqui nunca derruba o comando, só vai pro log.
 */
final class ConsoleExecutionListener
{
    public const ENV_NAME = 'CHAMPS_CRON_NAME';
    /** Números POSIX (Linux); escritos à mão porque as constantes SIG* só existem com pcntl. */
    private const SIGNALS = [1 => 'SIGHUP', 2 => 'SIGINT (Ctrl+C)', 3 => 'SIGQUIT', 9 => 'SIGKILL', 15 => 'SIGTERM'];

    private ?int $executionId = null;
    private ?string $name = null;
    private float $start = 0.0;
    private ?string $error = null;

    public function __construct(
        private readonly ExecutionRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = trim((string) getenv(self::ENV_NAME));
        if ('' === $name || null !== $this->executionId) {
            return;
        }
        // o próprio gravador do wrapper nunca vira uma execução
        if (RecordExecutionCommand::NAME === $event->getCommand()?->getName()) {
            return;
        }

        $this->name = $name;
        $this->start = microtime(true);
        $this->error = null;

        try {
            $this->executionId = $this->recorder->start($name, (string) $event->getInput(), getmypid() ?: null, gethostname() ?: null);
        } catch (\Throwable $e) {
            $this->logger->error('champs-cron-monitor: não foi possível registrar o início da execução.', ['name' => $name, 'error' => $e->getMessage()]);
        }
    }

    #[AsEventListener(event: ConsoleEvents::ERROR)]
    public function onError(ConsoleErrorEvent $event): void
    {
        if (null === $this->executionId) {
            return;
        }

        $e = $event->getError();
        $this->error = get_class($e) . ': ' . $e->getMessage();
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if (null === $this->executionId) {
            return;
        }

        $exitCode = $event->getExitCode();
        // Ctrl+C / kill: com a extensão pcntl o Symfony captura o sinal e dispara este evento antes
        // de sair. Sem pcntl o processo morre direto, a linha fica "running" e quem resolve é o
        // painel (CronMonitor::isProcessAlive()).
        $signal = $event->getInterruptingSignal();
        $status = null !== $signal ? ExecutionStatus::Interrupted : ExecutionStatus::fromExitCode($exitCode);
        if (null !== $signal && null === $this->error) {
            $this->error = 'Interrompida pelo sinal ' . (self::SIGNALS[$signal] ?? $signal) . '.';
        }

        try {
            $this->recorder->finish(
                $this->executionId,
                (string) $this->name,
                $status,
                $exitCode,
                (int) round((microtime(true) - $this->start) * 1000),
                (int) ceil(memory_get_peak_usage(true) / 1048576),
                $this->error,
            );
        } catch (\Throwable $e) {
            $this->logger->error('champs-cron-monitor: não foi possível registrar o fim da execução.', ['name' => $this->name, 'error' => $e->getMessage()]);
        } finally {
            $this->executionId = null;
        }
    }
}
