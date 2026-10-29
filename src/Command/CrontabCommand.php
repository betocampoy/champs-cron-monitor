<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Command;

use BetoCampoy\Champs\CronMonitor\Crontab\CrontabGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imprime as linhas do crontab a partir da agenda (champs_cron_monitor.jobs). Não mexe no crontab
 * do servidor: quem cola é quem tem acesso a ele.
 */
#[AsCommand(name: 'champs:cron:crontab', description: 'Gera as linhas do crontab a partir da agenda do champs_cron_monitor')]
final class CrontabCommand extends Command
{
    public function __construct(private readonly CrontabGenerator $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Inclui, comentados, os jobs planejados e bloqueados')
            ->addOption('project-dir', null, InputOption::VALUE_REQUIRED, 'Pasta do projeto no servidor (sobrepõe crontab.project_dir)')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'PHP do cron no servidor (sobrepõe crontab.php_binary)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->write($this->generator->generate(
            (bool) $input->getOption('all'),
            $input->getOption('project-dir'),
            $input->getOption('php'),
        ));

        return Command::SUCCESS;
    }
}
