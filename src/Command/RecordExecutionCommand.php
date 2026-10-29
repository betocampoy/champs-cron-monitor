<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Command;

use BetoCampoy\Champs\CronMonitor\ChampsCronMonitorBundle;
use BetoCampoy\Champs\CronMonitor\Recorder\ExecutionRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Uso interno do wrapper bin/champs-cron.sh, nunca agendado nem rodado à mão: grava no painel o que
 * o listener do console não enxerga.
 *  - --skipped: o cron disparou, mas a execução anterior ainda segurava a trava;
 *  - --start-ms/--end-ms/--exit-code: um script PHP solto terminou (o wrapper mediu o tempo).
 */
#[AsCommand(name: self::NAME, description: 'Uso interno do bin/champs-cron.sh: grava uma execução pulada ou de script solto')]
final class RecordExecutionCommand extends Command
{
    public const NAME = 'champs:cron:record';

    public function __construct(private readonly ExecutionRecorder $recorder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Nome do job (o mesmo do wrapper)')
            ->addOption('command', null, InputOption::VALUE_REQUIRED, 'O que o wrapper rodou', '')
            ->addOption('skipped', null, InputOption::VALUE_NONE, 'Execução pulada pela trava')
            ->addOption('start-ms', null, InputOption::VALUE_REQUIRED, 'Início, em milissegundos desde 1970')
            ->addOption('end-ms', null, InputOption::VALUE_REQUIRED, 'Fim, em milissegundos desde 1970')
            ->addOption('exit-code', null, InputOption::VALUE_REQUIRED, 'Código de saída do script')
            ->addOption('error', null, InputOption::VALUE_REQUIRED, 'Fim da saída do script, quando falhou');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        if (!preg_match(ChampsCronMonitorBundle::JOB_NAME_PATTERN, $name)) {
            $output->writeln("<error>Nome inválido: {$name}</error>");

            return Command::INVALID;
        }
        $command = (string) $input->getOption('command');

        if ($input->getOption('skipped')) {
            $this->recorder->recordSkipped($name, $command);

            return Command::SUCCESS;
        }

        $start = self::instant($input->getOption('start-ms'));
        $end = self::instant($input->getOption('end-ms'));
        $exitCode = $input->getOption('exit-code');
        if (null === $start || null === $end || !is_numeric($exitCode)) {
            $output->writeln('<error>Informe --skipped, ou --start-ms, --end-ms e --exit-code.</error>');

            return Command::INVALID;
        }

        $this->recorder->recordCompleted($name, $command, $start, $end, (int) $exitCode, $input->getOption('error'));

        return Command::SUCCESS;
    }

    private static function instant(mixed $ms): ?\DateTimeImmutable
    {
        if (!is_numeric($ms)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.3F', ((int) $ms) / 1000));

        return false === $date ? null : $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
