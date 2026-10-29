<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Command;

use BetoCampoy\Champs\CronMonitor\Schedule\ScheduleChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Valida a agenda. Feito para rodar no teste do projeto (KernelTestCase + CommandTester) e antes de
 * gerar o crontab.
 */
#[AsCommand(name: 'champs:cron:check', description: 'Valida a agenda do champs_cron_monitor (nomes, expressões, comandos e traduções)')]
final class CheckCommand extends Command
{
    public function __construct(private readonly ScheduleChecker $checker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        $errors = $this->checker->check(static fn (string $name): bool => null !== $application && $application->has($name));

        if (!$errors) {
            $output->writeln('<info>Agenda ok.</info>');

            return Command::SUCCESS;
        }

        foreach ($errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        return Command::FAILURE;
    }
}
