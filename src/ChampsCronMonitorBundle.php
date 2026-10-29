<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor;

use BetoCampoy\Champs\CronMonitor\Enum\JobStatus;
use BetoCampoy\Champs\CronMonitor\Enum\JobType;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Monitoramento das rotinas do cron.
 *
 * Configuração no projeto (config/packages/champs_cron_monitor.yaml):
 *
 *   champs_cron_monitor:
 *       admin:
 *           layout: admin/layout.html.twig
 *           access: ROLE_ADMIN
 *       crontab:
 *           php_binary: /usr/local/php82/bin/php82
 *       messenger:
 *           enabled: true
 *           queues: { default: async }
 *       jobs:
 *           clientes-sync:
 *               command: 'app:clientes:sincronizar-todos'
 *               schedule: '5 * * * *'
 *               status: active
 *
 * Descrição e explicação de cada job ficam nas traduções do projeto (domínio
 * admin.jobs_translation_domain, chaves "<nome>.description" e "<nome>.explanation").
 */
final class ChampsCronMonitorBundle extends AbstractBundle
{
    /** Nome de agendamento: vira nome de arquivo de log e de lock, por isso é tão restrito. */
    public const JOB_NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,59}$/';

    protected string $extensionAlias = 'champs_cron_monitor';

    /**
     * Raiz do pacote (onde ficam config/ e templates/).
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Roda antes do RoutingControllerPass (prioridade 0).
        $container->addCompilerPass(new DependencyInjection\ExcludeFromRoutingControllersPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('log_dir')
                    ->defaultValue('%kernel.project_dir%/var/log/cron')
                    ->info('Pasta dos logs do wrapper (var/log/cron/<nome>.log). Precisa ser a mesma que o bin/champs-cron.sh usa.')
                ->end()
                ->integerNode('default_retention_days')
                    ->defaultValue(90)
                    ->min(1)
                    ->info('Dias de histórico guardados por job, quando o job não define retention_days.')
                ->end()
                ->integerNode('late_tolerance_minutes')
                    ->defaultValue(10)
                    ->min(1)
                    ->info('Depois do horário previsto, quanto esperar antes de marcar um job ativo como atrasado.')
                ->end()
                ->integerNode('skipped_window_hours')
                    ->defaultValue(24)
                    ->min(1)
                    ->info('Janela da contagem de execuções puladas pela trava mostrada no painel.')
                ->end()
                ->arrayNode('admin')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout')
                            ->defaultValue('base.html.twig')
                            ->info('Template que o painel estende. Precisa ter os blocos "title" e "content".')
                        ->end()
                        ->scalarNode('access')
                            ->defaultValue('ROLE_ADMIN')
                            ->info('Atributo exigido no is_granted() do painel (role ou atributo de um voter do projeto).')
                        ->end()
                        ->scalarNode('jobs_translation_domain')
                            ->defaultValue('cron_jobs')
                            ->info('Domínio das traduções "<nome>.description" e "<nome>.explanation" de cada job.')
                        ->end()
                        ->integerNode('history_limit')
                            ->defaultValue(50)
                            ->min(1)
                            ->info('Execuções mostradas no detalhe de um job.')
                        ->end()
                        ->integerNode('log_lines')
                            ->defaultValue(200)
                            ->min(1)
                            ->info('Linhas do fim do log mostradas no detalhe de um job.')
                        ->end()
                        ->integerNode('poll_ms')
                            ->defaultValue(15000)
                            ->min(1000)
                            ->info('Intervalo da atualização automática do painel.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('crontab')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('wrapper')
                            ->defaultValue('%kernel.project_dir%/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh')
                            ->info('Caminho do wrapper no servidor, usado pelo champs:cron:crontab.')
                        ->end()
                        ->scalarNode('php_binary')
                            ->defaultNull()
                            ->info('PHP do cron (ex.: /usr/local/php82/bin/php82). Null = o "php" do PATH do cron.')
                        ->end()
                        ->scalarNode('project_dir')
                            ->defaultNull()
                            ->info('Pasta do projeto no servidor, para gerar o crontab de produção a partir do dev. Null = kernel.project_dir.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('messenger')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Mostra filas (transporte Doctrine, MySQL/MariaDB) e workers no painel.')
                        ->end()
                        ->scalarNode('table')
                            ->defaultValue('messenger_messages')
                        ->end()
                        ->arrayNode('queues')
                            ->useAttributeAsKey('queue_name')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                            ->defaultValue(['default' => 'async'])
                            ->info('queue_name do transporte Doctrine => nome mostrado no painel.')
                        ->end()
                        ->scalarNode('failed_queue')
                            ->defaultValue('failed')
                        ->end()
                        ->scalarNode('message_type_pattern')
                            ->defaultValue('App.{1,2}Message.{1,2}[A-Za-z]+|SendEmailMessage|ChatMessage|SmsMessage')
                            ->info('Regex (REGEXP_SUBSTR) que extrai o tipo da mensagem do corpo serializado: o PhpSerializer não grava o tipo no headers.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('check')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('locales')
                            ->scalarPrototype()->end()
                            ->defaultValue(['pt_BR', 'en', 'es'])
                            ->info('Línguas em que o champs:cron:check exige a descrição e a explicação de cada job.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('jobs')
                    ->useAttributeAsKey('name')
                    // sem isto o Symfony troca "-" por "_" na chave e "push-remind" vira "push_remind"
                    ->normalizeKeys(false)
                    ->info('A agenda: uma entrada por linha do crontab. A chave é o nome do job (minúsculas, números e hífen, até 60).')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('command')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->info('Comando do console com as opções (ex.: "app:foo --limite=100"), ou o caminho do script quando type = script.')
                            ->end()
                            ->scalarNode('schedule')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->info('Expressão cron de 5 campos, no fuso do servidor.')
                            ->end()
                            ->enumNode('status')
                                ->values(array_column(JobStatus::cases(), 'value'))
                                ->defaultValue(JobStatus::Planned->value)
                                ->info('active = está no crontab e o painel cobra atraso; planned = ainda não ligado; blocked = não pode rodar.')
                            ->end()
                            ->enumNode('type')
                                ->values(array_column(JobType::cases(), 'value'))
                                ->defaultValue(JobType::Console->value)
                                ->info('console = comando do Symfony; script = script PHP solto (php <caminho>).')
                            ->end()
                            ->integerNode('retention_days')
                                ->defaultNull()
                                ->min(1)
                                ->info('Dias de histórico deste job. Job de minuto: use menos (ex.: 14).')
                            ->end()
                            ->scalarNode('blocked_reason')
                                ->defaultNull()
                                ->info('Por que está bloqueado (sai comentado no crontab gerado).')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('champs_cron_monitor.log_dir', $config['log_dir'])
            ->set('champs_cron_monitor.default_retention_days', $config['default_retention_days'])
            ->set('champs_cron_monitor.late_tolerance_minutes', $config['late_tolerance_minutes'])
            ->set('champs_cron_monitor.skipped_window_hours', $config['skipped_window_hours'])
            ->set('champs_cron_monitor.admin.layout', $config['admin']['layout'])
            ->set('champs_cron_monitor.admin.access', $config['admin']['access'])
            ->set('champs_cron_monitor.admin.jobs_translation_domain', $config['admin']['jobs_translation_domain'])
            ->set('champs_cron_monitor.admin.history_limit', $config['admin']['history_limit'])
            ->set('champs_cron_monitor.admin.log_lines', $config['admin']['log_lines'])
            ->set('champs_cron_monitor.admin.poll_ms', $config['admin']['poll_ms'])
            ->set('champs_cron_monitor.crontab.wrapper', $config['crontab']['wrapper'])
            ->set('champs_cron_monitor.crontab.php_binary', $config['crontab']['php_binary'])
            ->set('champs_cron_monitor.crontab.project_dir', $config['crontab']['project_dir'])
            ->set('champs_cron_monitor.messenger.enabled', $config['messenger']['enabled'])
            ->set('champs_cron_monitor.messenger.table', $config['messenger']['table'])
            ->set('champs_cron_monitor.messenger.queues', $config['messenger']['queues'])
            ->set('champs_cron_monitor.messenger.failed_queue', $config['messenger']['failed_queue'])
            ->set('champs_cron_monitor.messenger.message_type_pattern', $config['messenger']['message_type_pattern'])
            ->set('champs_cron_monitor.check.locales', $config['check']['locales'])
            ->set('champs_cron_monitor.jobs', $config['jobs']);

        $container->import('../config/services.php');
    }

    /**
     * Registra o mapeamento Doctrine da entidade do bundle, sem exigir configuração no projeto.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'ChampsCronMonitor' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__ . '/Entity',
                        'prefix' => 'BetoCampoy\\Champs\\CronMonitor\\Entity',
                        'alias' => 'ChampsCronMonitor',
                    ],
                ],
            ],
        ]);
    }
}
