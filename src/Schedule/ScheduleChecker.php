<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Schedule;

use BetoCampoy\Champs\CronMonitor\ChampsCronMonitorBundle;
use BetoCampoy\Champs\CronMonitor\Enum\JobType;
use Cron\CronExpression;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confere a agenda: nome válido, expressão cron válida, comando do console existente e descrição e
 * explicação traduzidas em todas as línguas exigidas. É o que roda no champs:cron:check (e no teste
 * PHPUnit do projeto que chama o comando).
 */
final class ScheduleChecker
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        private readonly CronSchedule $schedule,
        private readonly TranslatorInterface $translator,
        #[Autowire(param: 'champs_cron_monitor.admin.jobs_translation_domain')] private readonly string $domain = 'cron_jobs',
        #[Autowire(param: 'champs_cron_monitor.check.locales')] private readonly array $locales = ['pt_BR', 'en', 'es'],
    ) {
    }

    /**
     * @param callable(string): bool $commandExists recebe o nome do comando (sem opções)
     *
     * @return list<string> problemas encontrados; vazio = agenda ok
     */
    public function check(callable $commandExists): array
    {
        $errors = [];
        foreach ($this->schedule->all() as $job) {
            if (!preg_match(ChampsCronMonitorBundle::JOB_NAME_PATTERN, $job->name)) {
                $errors[] = \sprintf('%s: nome inválido (minúsculas, números e hífen, até 60 caracteres).', $job->name);
            }

            if (null === $job->schedule || !CronExpression::isValidExpression($job->schedule)) {
                $errors[] = \sprintf('%s: expressão cron inválida "%s".', $job->name, $job->schedule);
            }

            if (JobType::Console === $job->type) {
                $command = $job->consoleCommandName();
                if (null === $command || !$commandExists($command)) {
                    $errors[] = \sprintf('%s: o comando "%s" não existe.', $job->name, $command);
                }
            }

            // Tradutor sem catálogo (ex.: IdentityTranslator) não tem o que conferir.
            if (!$this->translator instanceof TranslatorBagInterface) {
                continue;
            }
            foreach ($this->locales as $locale) {
                $catalogue = $this->translator->getCatalogue($locale);
                foreach (['description', 'explanation'] as $key) {
                    if (!$catalogue->defines($job->name . '.' . $key, $this->domain)) {
                        $errors[] = \sprintf('%s: falta a tradução "%s.%s" no domínio %s (%s).', $job->name, $job->name, $key, $this->domain, $locale);
                    }
                }
            }
        }

        return $errors;
    }
}
