<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Tests\Schedule;

use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use BetoCampoy\Champs\CronMonitor\Schedule\ScheduleChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class ScheduleCheckerTest extends TestCase
{
    public function testAgendaValidaNaoTemProblemas(): void
    {
        $checker = new ScheduleChecker(
            $this->schedule(['sync' => ['command' => 'app:sync --x=1', 'schedule' => '5 * * * *']]),
            $this->translator(['sync']),
            'cron_jobs',
            ['pt_BR', 'en'],
        );

        self::assertSame([], $checker->check(static fn (string $c) => 'app:sync' === $c));
    }

    public function testApontaNomeExpressaoComandoETraducao(): void
    {
        $checker = new ScheduleChecker(
            $this->schedule([
                'Nome_Ruim' => ['command' => 'app:sync', 'schedule' => '5 * * * *'],
                'expressao' => ['command' => 'app:sync', 'schedule' => '99 * * * *'],
                'sem-comando' => ['command' => 'app:nao-existe', 'schedule' => '5 * * * *'],
                'script' => ['command' => 'services/x.php', 'schedule' => '5 * * * *', 'type' => 'script'],
            ]),
            $this->translator(['Nome_Ruim', 'expressao', 'sem-comando']),
            'cron_jobs',
            ['pt_BR', 'en'],
        );

        $errors = $checker->check(static fn (string $c) => 'app:sync' === $c);

        self::assertCount(4 + 1 + 1 + 1, $errors, implode("\n", $errors));
        self::assertStringContainsString('Nome_Ruim: nome inválido', $errors[0]);
        self::assertContains('expressao: expressão cron inválida "99 * * * *".', $errors);
        self::assertContains('sem-comando: o comando "app:nao-existe" não existe.', $errors);
        // script não exige comando do console, mas exige as traduções (2 chaves × 2 línguas)
        self::assertCount(4, array_filter($errors, static fn ($e) => str_starts_with($e, 'script: falta a tradução')));
    }

    /**
     * @param array<string, array<string, string>> $jobs
     */
    private function schedule(array $jobs): CronSchedule
    {
        return new CronSchedule(array_map(static fn (array $j) => $j + ['status' => 'active', 'type' => 'console', 'retention_days' => null, 'blocked_reason' => null], $jobs));
    }

    /**
     * @param list<string> $names jobs com descrição e explicação nas duas línguas
     */
    private function translator(array $names): Translator
    {
        $translator = new Translator('pt_BR');
        $translator->addLoader('array', new ArrayLoader());
        foreach (['pt_BR', 'en'] as $locale) {
            $messages = [];
            foreach ($names as $name) {
                $messages[$name . '.description'] = 'd';
                $messages[$name . '.explanation'] = 'e';
            }
            $translator->addResource('array', $messages, $locale, 'cron_jobs');
        }

        return $translator;
    }
}
