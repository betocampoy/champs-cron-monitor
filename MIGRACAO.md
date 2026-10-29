# Migrar do painel Rotinas próprio (MEMOD / ME) para o champs-cron-monitor

O `minhaencomenda_modules` (MEMOD) e o `minhaencomenda_symfony` (ME) têm o painel **Admin →
Rotinas** implementado no próprio projeto. O bundle é a junção dos dois: estado "parcial" e
download do log (MEMOD), mais puladas no banco, script solto, retenção por job e detecção de worker
pela pasta (ME).

O que **não muda**: URL do painel (`/admin/rotinas`), log (`var/log/cron/<nome>.log`), lock
(`var/lock/cron-<nome>.lock`), nomes dos jobs e, com o shim do passo 6, o crontab.

## De → para

| No projeto | No bundle |
|---|---|
| `bin/memod-cron.sh` / `bin/me-cron.sh` | `vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh` (o antigo vira shim, passo 6) |
| `MEMOD_CRON_NOME` / `ME_CRON_NOME` | `CHAMPS_CRON_NAME` |
| `MEMOD_PHP` / `ME_PHP` | `CHAMPS_CRON_PHP` |
| `App\Service\Infra\RotinaAgenda` | `champs_cron_monitor.jobs` (YAML) |
| `App\Entity\Infra\RotinaExecucao` (`memod_rotina_execucao` / `me_rotina_execucao`) | `CronExecution` (`champs_cron_execution`) |
| `RotinaExecucao::EXIT_PARCIAL` | `BetoCampoy\Champs\CronMonitor\CronExitCode::PARTIAL` (mesmo valor, 3) |
| `RotinaExecucaoConsoleListener`, `RotinaExecucaoRecorder`, `RotinaMonitorService`, `RotinaController`, `RegistrarExecucaoRotinaCommand` (`app:rotina:registrar`) | do bundle |
| `templates/admin/rotina/*` / `templates/pages/rotina/*` | `@ChampsCronMonitor/admin/*` |
| `translations/rotinas.*.yaml`: `agenda.descricao.<nome>` / `agenda.explicacao.<nome>` | `translations/cron_jobs.*.yaml`: `<nome>.description` / `<nome>.explanation` |
| rota `admin_rotina_index` (e `_show`, `_status`, `_show_status`, `_log_download`) | `champs_cron_monitor_index` (e `_show`, `_status`, `_show_status`, `_log`) |
| `RotinaAgendaTest` | teste que roda `champs:cron:check` (README) |

Situação: `ativo` → `active`, `planejado` → `planned`, `bloqueado` → `blocked`. Tipo do ME:
`console` / `script` iguais.

## Passo a passo

### 1. Instalar

`composer require`, `bundles.php` e `config/routes/champs_cron_monitor.yaml` com
`prefix: /admin/rotinas` e `trailing_slash_on_root: false` (README).

### 2. Configurar

```yaml
champs_cron_monitor:
    admin:
        layout: admin/layout.html.twig       # MEMOD
        access: 'PERMISSION:Rotinas Monitorar'
    crontab:
        php_binary: /usr/local/php82/bin/php82
        project_dir: /home/jadlogib/minhaencomenda_modules
    messenger:
        enabled: true
        queues: { default: async, whatsapp: whatsapp }   # ME: { default: async }
        # MEMOD: acrescentar |SyncMonitored[A-Za-z]+ (champs-onboarding)
        message_type_pattern: 'App.{1,2}Message.{1,2}[A-Za-z]+|SendEmailMessage|ChatMessage|SmsMessage|SyncMonitored[A-Za-z]+'
    jobs:
        clientes-sync: { command: 'app:clientes:sincronizar-todos', schedule: '5 * * * *', status: active }
        # ... um por linha da RotinaAgenda
```

**Permissão.** O `#[IsGrantedPermission]` do MEMOD é um listener de controller, não um voter, e o
bundle usa `is_granted(admin.access)`. Um voter pequeno resolve, e serve para qualquer permissão:

```php
final class PermissionAttributeVoter extends Voter
{
    private const PREFIX = 'PERMISSION:';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, self::PREFIX);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return null !== $user && method_exists($user, 'hasPermission')
            && $user->hasPermission(substr($attribute, \strlen(self::PREFIX)));
    }
}
```

**Layout do ME.** O ME monta o menu no controller (`AdminController::renderWithMenu()`), e o
controller do bundle não passa essas variáveis. Antes de migrar o ME, o menu precisa vir de uma
extensão/global do Twig (ou de um layout que o busque sozinho). Sem isso o painel abre sem menu.

### 3. Traduções

Mover `agenda.descricao.<nome>` e `agenda.explicacao.<nome>` de `rotinas.*.yaml` para
`cron_jobs.*.yaml` como `<nome>.description` e `<nome>.explanation`, nas 3 línguas. O resto do
`rotinas.*.yaml` (rótulos da tela) sai: o bundle traz os seus.

### 4. Código das rotinas

Trocar `RotinaExecucao::EXIT_PARCIAL` por `CronExitCode::PARTIAL` nos comandos que usam
(MEMOD, 2026-10-29: `turno`, `analitico`, `atend-rastreio`, `oper-rastreio`, `componentes-frete`,
`oper-observacoes`, `atend-observacoes`, `sinistro`). Ajustar o link do menu para
`champs_cron_monitor_index`.

### 5. Banco

Gerar a migration (cria `champs_cron_execution`), ler e limpar o SQL. Para não perder o histórico,
acrescentar na mesma migration (MEMOD; no ME troque a tabela de origem por `me_rotina_execucao`):

```sql
INSERT INTO champs_cron_execution
    (name, command, status, started_at, finished_at, duration_ms, exit_code, pid, host, memory_peak_mb, error)
SELECT nome, comando,
       CASE status
           WHEN 'rodando' THEN 'running'
           WHEN 'sucesso' THEN 'success'
           WHEN 'falha' THEN 'failure'
           WHEN 'parcial' THEN 'partial'
           WHEN 'interrompida' THEN 'interrupted'
           WHEN 'pulada' THEN 'skipped'
           ELSE 'failure'
       END,
       iniciado_em, finalizado_em, duracao_ms, exit_code, pid, host, memoria_pico_mb, erro
  FROM memod_rotina_execucao
 ORDER BY id;
```

A tabela antiga só sai numa migration **posterior**, depois de conferir o painel novo em produção.

### 6. Wrapper: shim para o crontab não mudar

`bin/memod-cron.sh` (ou `bin/me-cron.sh`) passa a ser só:

```bash
#!/usr/bin/env bash
# Mantido para o crontab não mudar: o wrapper agora é o do champs-cron-monitor.
DIR="$(cd "$(dirname "$0")/.." && pwd)"
export CHAMPS_CRON_PROJECT_DIR="$DIR"
export CHAMPS_CRON_PHP="${CHAMPS_CRON_PHP:-${MEMOD_PHP:-/usr/local/php82/bin/php82}}"
exec bash "$DIR/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh" "$@"
```

Lock e log ficam nos mesmos caminhos, então uma execução que esteja rodando durante o deploy
continua segurando a trava. Mais tarde, dá para trocar o crontab pelas linhas do
`champs:cron:crontab` e apagar o shim.

### 7. Remover o código antigo

As classes e templates da tabela "De → para", o `RotinaAgendaTest` (vira o teste do
`champs:cron:check`) e as rotas antigas.

### 8. Conferir

1. `php bin/console champs:cron:check` sem erros e `php bin/phpunit`.
2. `php bin/console champs:cron:crontab --all` bate com o crontab do servidor (`crontab -l`).
3. Dev: `CHAMPS_CRON_NAME=clientes-sync php bin/console app:clientes:sincronizar-todos` e abrir
   `/admin/rotinas`.
4. Produção (quem faz é o usuário): deploy normal, rodar um job à mão pelo shim
   (`bash bin/memod-cron.sh clientes-sync app:clientes:sincronizar-todos`) e conferir a execução no
   painel. No dia seguinte, conferir que nenhum job ficou "Atrasada".

### 9. Documentação do projeto

`docs/development/infra/agendamentos-e-workers.md`: §2 (wrapper e painel) passa a apontar para o
bundle; §3 continua sendo o porquê de cada horário; §4 (crontab para colar) passa a ser a saída do
`champs:cron:crontab`; §10 (rotina nova) troca "RotinaAgenda" por "YAML + `cron_jobs.*.yaml`".
