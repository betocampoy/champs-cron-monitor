# betocampoy/champs-cron-monitor

Monitoramento das rotinas do cron para projetos Symfony 7.4: wrapper com trava e log, histórico de
cada execução no banco, agenda declarada em YAML (que também gera o crontab) e um painel
administrativo que mostra, sem SSH, se cada rotina está rodando, atrasada ou falhando.

Extraído do painel **Admin → Rotinas** do `minhaencomenda_modules` (MEMOD) e do
`minhaencomenda_symfony` (ME), que tinham duas cópias quase iguais. Padrão cross-project:
`_documentation/symfony-projects-patterns/rotinas-cron.md`.

## Como funciona

```
crontab ──> bin/champs-cron.sh <nome> <comando>
              │  flock por nome (anterior rodando → "pulada", registrada no painel)
              │  log em var/log/cron/<nome>.log (gira em 5 MB)
              │  exporta CHAMPS_CRON_NAME=<nome>
              ▼
            bin/console <comando>
              │  ConsoleExecutionListener: grava início/fim/duração/rc/memória/erro
              ▼
            champs_cron_execution  ──>  painel /admin/rotinas (CronMonitor)
                                          agenda YAML × última execução, atraso,
                                          puladas, filas e workers do Messenger, log
```

- **Nenhum comando precisa saber do bundle.** Agendou pelo wrapper, aparece no painel.
- **Execução manual no terminal não é gravada** (não tem `CHAMPS_CRON_NAME`).
- **Script PHP solto** (legado): `champs-cron.sh <nome> php <script.php>`. O wrapper mede o tempo
  e grava a execução inteira no fim (`champs:cron:record`).
- **Histórico se limpa sozinho:** cada fim de execução apaga as linhas do mesmo job mais velhas
  que a retenção dele (90 dias por padrão; job de minuto, use 14).
- **O painel é só leitura:** não dispara nem interrompe nada.

## Estrutura

```
bin/champs-cron.sh                       # wrapper do crontab (Linux: flock, stat, date +%3N)
src/
├── ChampsCronMonitorBundle.php          # config, mapeamento Doctrine
├── CronExitCode.php                     # PARTIAL = 3 (concluída com pendências)
├── Entity/CronExecution.php             # champs_cron_execution (só leitura no ORM)
├── Enum/                                # ExecutionStatus, JobStatus, JobType
├── Schedule/
│   ├── CronSchedule.php                 # agenda lida do YAML
│   ├── CronJob.php
│   └── ScheduleChecker.php              # regras do champs:cron:check
├── Recorder/ExecutionRecorder.php       # grava via DBAL (nunca pelo EntityManager)
├── EventListener/ConsoleExecutionListener.php
├── Monitor/CronMonitor.php              # estado, atraso, processo vivo, filas, workers, log
├── Crontab/CrontabGenerator.php
├── Command/
│   ├── CrontabCommand.php               # champs:cron:crontab
│   ├── CheckCommand.php                 # champs:cron:check
│   └── RecordExecutionCommand.php       # champs:cron:record (uso interno do wrapper)
└── Controller/CronMonitorController.php # painel, detalhe, recarregar, baixar log
templates/admin/                         # Bootstrap 5 + Bootstrap Icons + champs-frontend
translations/champs_cron_monitor.{pt_BR,en,es}.yaml
```

## Instalação

Durante o desenvolvimento, por repositório `path` (mesmo esquema dos outros `champs-*`):

```json
"repositories": [
    { "type": "path", "url": "../champs-cron-monitor", "options": { "symlink": true } }
]
```

```bash
composer require betocampoy/champs-cron-monitor:@dev
```

Quando estabilizar, troque o `path` por `vcs` (GitHub) e use tags de versão. Em produção o
wrapper precisa estar dentro do `vendor/` do projeto: com `path` + symlink no servidor, confira
que o link aponta para algo que existe lá.

`config/bundles.php`:

```php
BetoCampoy\Champs\CronMonitor\ChampsCronMonitorBundle::class => ['all' => true],
```

`config/routes/champs_cron_monitor.yaml`:

```yaml
champs_cron_monitor:
    resource: '@ChampsCronMonitorBundle/config/routes.php'
    prefix: /admin/rotinas
    trailing_slash_on_root: false
```

Exige `betocampoy/champs-frontend` `^1.11` (AjaxPoll e `ui.renderButton`) com o namespace Twig
`@ChampsFrontend` configurado no projeto, Bootstrap 5 e Bootstrap Icons no layout.

### Banco de dados

O mapeamento Doctrine é registrado pelo bundle. Gere a migration, **leia e limpe o SQL** (só a
tabela `champs_cron_execution` e os dois índices dela) e rode:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Configuração

`config/packages/champs_cron_monitor.yaml` (tudo opcional menos `jobs`, que é a agenda):

```yaml
champs_cron_monitor:
    log_dir: '%kernel.project_dir%/var/log/cron'   # o mesmo do wrapper (CHAMPS_CRON_LOG_DIR)
    default_retention_days: 90
    late_tolerance_minutes: 10       # job ativo que não começou X min depois do previsto = atrasado
    skipped_window_hours: 24         # janela da contagem de puladas no painel

    admin:
        layout: admin/layout.html.twig   # precisa dos blocos "title" e "content"
        access: ROLE_ADMIN               # atributo do is_granted() (role ou atributo de voter)
        jobs_translation_domain: cron_jobs
        history_limit: 50
        log_lines: 200
        poll_ms: 15000

    crontab:
        # caminho do wrapper (padrão: <projeto>/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh)
        php_binary: /usr/local/php82/bin/php82   # PHP do cron no servidor; null = "php" do PATH
        project_dir: /home/usuario/projeto       # pasta no servidor, para gerar o crontab no dev

    messenger:
        enabled: true                    # mostra filas e workers (transporte Doctrine, MySQL/MariaDB)
        table: messenger_messages
        queues: { default: async, whatsapp: whatsapp }   # queue_name => nome no painel
        failed_queue: failed
        message_type_pattern: 'App.{1,2}Message.{1,2}[A-Za-z]+|SendEmailMessage|ChatMessage|SmsMessage'

    check:
        locales: [pt_BR, en, es]

    jobs:
        clientes-sync:
            command: 'app:clientes:sincronizar-todos'
            schedule: '5 * * * *'
            status: active               # active | planned | blocked
        rastreio:
            command: 'app:remessa:rastrear-lote --limite=3000'
            schedule: '30 5,12,19 * * *'
            status: active
        legado-email:
            type: script                 # console (padrão) | script
            command: '/home/usuario/legado/services/sendEmail.php'
            schedule: '*/2 * * * *'
            status: planned
            retention_days: 14           # job de minuto: menos dias
        analitico:
            command: 'app:remessa:atualizar-analitico'
            schedule: '0 2 * * *'
            status: blocked
            blocked_reason: 'FractionWeb trava relatórios com o turno vencido'
```

**Situação do job:** `active` = está no crontab, o painel cobra atraso; `planned` = definido, ainda
não ligado; `blocked` = não pode rodar (sem próxima execução nem atraso). Execução com um nome que
não está na agenda aparece como **Fora da agenda**.

### Textos de cada job (i18n)

Duas chaves por job no domínio `admin.jobs_translation_domain`, nas línguas de `check.locales`
(`translations/cron_jobs.pt_BR.yaml`, `.en.yaml`, `.es.yaml`):

```yaml
clientes-sync:
  description: 'De hora em hora'
  explanation: 'Traz os Clientes novos do ME. Dependem dela: Fechamento e Contas Excluídas.'
```

- `description`: a frequência legível (coluna Frequência);
- `explanation`: o que faz, por que importa e quem depende dela (3ª linha da coluna Rotina).

### Validar a agenda (teste do projeto)

`champs:cron:check` falha se um nome for inválido, a expressão cron estiver errada, o comando do
console não existir ou faltar `description`/`explanation` em alguma língua. Coloque no PHPUnit do
projeto:

```php
final class CronScheduleTest extends KernelTestCase
{
    public function testAgendaValida(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('champs:cron:check'));

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
    }
}
```

## Crontab

A agenda é a fonte; o crontab sai dela:

```bash
php bin/console champs:cron:crontab          # só os jobs active
php bin/console champs:cron:crontab --all    # + planned/blocked, comentados
php bin/console champs:cron:crontab --project-dir=/home/usuario/projeto --php=/usr/local/php82/bin/php82
```

```
# champs-cron-monitor: gerado pelo champs:cron:crontab. Mude a agenda no YAML e gere de novo.
5 * * * * CHAMPS_CRON_PHP=/usr/local/php82/bin/php82 bash /home/usuario/projeto/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh clientes-sync app:clientes:sincronizar-todos
```

Cada linha é autossuficiente (sem `CRON=`/`PHP=` no topo): o crontab costuma ser compartilhado
com outros projetos e uma variável de um bloco vazaria para os outros. `%` sai escapado (`\%`).

O comando **só imprime**: quem cola no servidor (`crontab -e`) é quem tem acesso a ele. Ao colar,
substitua só o bloco do projeto, sem mexer nas linhas dos outros.

**Mudou horário?** YAML + crontab do servidor juntos, senão o painel acusa atraso falso.

## Código de saída

| rc | Painel | Quando |
|---|---|---|
| `0` | Sucesso | cumpriu o trabalho |
| `3` (`CronExitCode::PARTIAL`) | Concluída com pendências (amarelo) | funcionou, mas algum item ficou de fora por um problema que **o cliente** resolve |
| outro | Falhou (vermelho) | problema da plataforma: serviço externo fora, exceção |

Rotina que processa vários itens: um item com problema nunca impede os outros, e no fim

```php
return $pendencias > 0 ? CronExitCode::PARTIAL : Command::SUCCESS;
```

Erro nosso tem precedência e sai `Command::FAILURE`.

## Regras do painel

- **Atrasada:** só job `active`. A execução prevista mais recente já passou há mais de
  `late_tolerance_minutes` e não há execução iniciada desde ela (1 min de folga). Rodando nunca
  conta como atraso.
- **Rodando × Interrompida:** com `pcntl` no PHP do cron, Ctrl+C/kill grava `interrupted`. Sem
  `pcntl` (ou kill -9, reinício) a linha fica `running` e o painel confere o processo:
  `/proc/<pid>/cmdline` e, se o PHP do site não lê `/proc` (`open_basedir`), `posix_kill($pid, 0)`.
  Processo sumido = **Interrompida**. Gravada por outra máquina ou sem os dois recursos: confia no
  status gravado.
- **Puladas:** a última execução mostrada nunca é uma pulada; elas aparecem como contagem na janela
  (`skipped_window_hours`). Muitas puladas = a rotina demora mais que o intervalo.
- **Workers:** processos `messenger:consume` deste projeto (pela linha de comando ou pela pasta de
  trabalho, `/proc/<pid>/cwd`). Lista vazia em produção é alerta real. No Windows: "não
  verificável".
- **Filas:** por `queue_name`: aguardando, processando, agendadas e maior espera; falhas definitivas
  por tipo (o tipo sai do corpo serializado, pelo `message_type_pattern`).
- **Detalhe:** últimas `history_limit` execuções e `log_lines` linhas do log; botão **Recarregar**
  (sem polling: o log mudando sozinho não deixava ler) e **Baixar log completo** (só o arquivo atual,
  sem o `.log.1`). O nome do job é validado antes de virar caminho de arquivo.

## Wrapper

```
bash champs-cron.sh <nome> <comando do console> [opções...]
bash champs-cron.sh <nome> php <script.php> [args...]
```

| Variável | Padrão |
|---|---|
| `CHAMPS_CRON_PHP` | `php` do PATH (no cron nem sempre é o certo: defina) |
| `CHAMPS_CRON_PROJECT_DIR` | sobe a partir do script até achar `bin/console` |
| `CHAMPS_CRON_LOG_DIR` | `<projeto>/var/log/cron` (precisa bater com `log_dir`) |

Lock em `<projeto>/var/lock/cron-<nome>.lock`. Exige Linux com `flock`, `stat -c` e `date +%3N`
(GNU coreutils). Chame sempre com `bash` explícito: não depende do bit de execução no git.

## Lições (não redescobrir)

- **Grave via DBAL**, nunca pelo EntityManager: exceção no comando fecha o EM e o fim se perderia.
  Transação aberta pelo comando é desfeita antes do UPDATE do fim.
- **Avisos de depreciação no stderr** sujam o log de toda rotina: no `monolog.yaml` de prod, canal
  `deprecation` para um `rotating_file` próprio e `!deprecation` nos handlers `main`/`console`.
- **Medir antes de agendar:** rode uma vez à mão pelo wrapper, leia duração e memória no painel, só
  então fixe limite e frequência.
- **Conexão longa parada:** rotina que espera serviço externo pode passar do `wait_timeout` do
  MySQL ("MySQL server has gone away"). No MEMOD: `SET SESSION wait_timeout = 3600` ao conectar.
- **Tarefa com horário → cron (wrapper); processo contínuo (worker) → supervisor.** Nunca worker no
  cron.

## Testes

```bash
composer install
php vendor/bin/phpunit
```

**No teste do projeto, não grave execução com `start()`/`finish()`:** o `finish()` desfaz toda
transação aberta antes de gravar o fim (é o certo no processo do cron) e leva junto a transação
que o teste abriu para reverter no fim. As linhas seguintes ficam gravadas de verdade no banco
de teste. Use `recordCompleted()` / `recordSkipped()` (achado na adoção do OrganizzeMe,
2026-10-29).

`tests/Kernel` sobe um kernel mínimo (sem banco) e confere serviços, rotas, comandos e templates.

## Migrar uma implementação antiga

MEMOD e ME: ver [MIGRACAO.md](MIGRACAO.md).
