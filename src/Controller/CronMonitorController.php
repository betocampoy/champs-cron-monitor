<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Controller;

use BetoCampoy\Champs\CronMonitor\ChampsCronMonitorBundle;
use BetoCampoy\Champs\CronMonitor\Entity\CronExecution;
use BetoCampoy\Champs\CronMonitor\Monitor\CronMonitor;
use BetoCampoy\Champs\CronMonitor\Repository\CronExecutionRepository;
use BetoCampoy\Champs\CronMonitor\Schedule\CronJob;
use BetoCampoy\Champs\CronMonitor\Schedule\CronSchedule;
use BetoCampoy\Champs\Frontend\Response\AjaxFormRespose;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Painel das rotinas do cron: jobs da agenda com a última execução, o que está rodando agora,
 * puladas pela trava, filas do Messenger e workers vivos, e o log de cada job, sem precisar de SSH.
 * Só leitura.
 *
 * Painel com poucas linhas fixas: sem filtro, sem paginação e sem toggle grid/tabela, de propósito.
 * O painel se atualiza sozinho (AjaxPoll); o detalhe não (o log mudando a cada poucos segundos não
 * deixava ler), tem o botão Recarregar.
 */
final class CronMonitorController extends AbstractController
{
    private const NAME_REQUIREMENT = '[a-z0-9][a-z0-9-]{0,59}';

    public function __construct(
        private readonly CronMonitor $monitor,
        private readonly CronSchedule $schedule,
        private readonly CronExecutionRepository $executions,
        #[Autowire(param: 'champs_cron_monitor.admin.access')] private readonly string $access,
        #[Autowire(param: 'champs_cron_monitor.admin.layout')] private readonly string $layout,
        #[Autowire(param: 'champs_cron_monitor.admin.jobs_translation_domain')] private readonly string $jobsDomain,
        #[Autowire(param: 'champs_cron_monitor.admin.history_limit')] private readonly int $historyLimit,
        #[Autowire(param: 'champs_cron_monitor.admin.log_lines')] private readonly int $logLines,
        #[Autowire(param: 'champs_cron_monitor.admin.poll_ms')] private readonly int $pollMs,
    ) {
    }

    #[Route('', name: 'champs_cron_monitor_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted($this->access);

        return $this->render('@ChampsCronMonitor/admin/index.html.twig', $this->dashboardData());
    }

    /** Polling do painel (AjaxPoll): reescreve as seções de dentro, nunca o elemento do polling. */
    #[Route('/status', name: 'champs_cron_monitor_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $this->denyAccessUnlessGranted($this->access);
        $data = $this->dashboardData();

        $response = AjaxFormRespose::make()
            ->domPatchReplace(['id' => 'champs-cron-jobs'], $this->renderView('@ChampsCronMonitor/admin/_jobs.html.twig', $data))
            ->domPatchText(['id' => 'champs-cron-updated-at'], $data['updatedAt']->format('H:i:s'));
        if ($data['messengerEnabled']) {
            $response
                ->domPatchReplace(['id' => 'champs-cron-queues'], $this->renderView('@ChampsCronMonitor/admin/_queues.html.twig', $data))
                ->domPatchReplace(['id' => 'champs-cron-workers'], $this->renderView('@ChampsCronMonitor/admin/_workers.html.twig', $data));
        }

        return new JsonResponse($response->toArray());
    }

    #[Route('/{name}', name: 'champs_cron_monitor_show', requirements: ['name' => self::NAME_REQUIREMENT], methods: ['GET'])]
    public function show(string $name): Response
    {
        $this->denyAccessUnlessGranted($this->access);

        return $this->render('@ChampsCronMonitor/admin/show.html.twig', $this->detailData($name));
    }

    /** Botão "Recarregar" do detalhe. */
    #[Route('/{name}/status', name: 'champs_cron_monitor_show_status', requirements: ['name' => self::NAME_REQUIREMENT], methods: ['GET'])]
    public function showStatus(string $name): JsonResponse
    {
        $this->denyAccessUnlessGranted($this->access);
        $data = $this->detailData($name);

        $response = AjaxFormRespose::make()
            ->domPatchReplace(['id' => 'champs-cron-history'], $this->renderView('@ChampsCronMonitor/admin/_history.html.twig', $data))
            ->domPatchReplace(['id' => 'champs-cron-log'], $this->renderView('@ChampsCronMonitor/admin/_log.html.twig', $data))
            ->domPatchText(['id' => 'champs-cron-updated-at'], $data['updatedAt']->format('H:i:s'));

        return new JsonResponse($response->toArray());
    }

    /**
     * Baixa o log inteiro do job, sem precisar entrar na hospedagem. Só o arquivo atual: o .log.1
     * (rotação de 5 MB do wrapper) fica no servidor.
     */
    #[Route('/{name}/log', name: 'champs_cron_monitor_log', requirements: ['name' => self::NAME_REQUIREMENT], methods: ['GET'])]
    public function downloadLog(string $name): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted($this->access);

        $file = $this->monitor->logFile($name);
        if (null === $file) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($file);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, \sprintf('%s-%s.log', $name, date('Ymd-His')));
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardData(): array
    {
        $now = new \DateTimeImmutable();

        return [
            'layout' => $this->layout,
            'jobsDomain' => $this->jobsDomain,
            'pollMs' => $this->pollMs,
            'rows' => $this->monitor->rows($now),
            'skippedWindowHours' => $this->monitor->skippedWindowHours(),
            'messengerEnabled' => $this->monitor->isMessengerEnabled(),
            'queues' => $this->monitor->queues(),
            'workers' => $this->monitor->isMessengerEnabled() ? $this->monitor->workers() : null,
            'updatedAt' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailData(string $name): array
    {
        if (!preg_match(ChampsCronMonitorBundle::JOB_NAME_PATTERN, $name)) {
            throw $this->createNotFoundException();
        }

        $now = new \DateTimeImmutable();
        $job = $this->schedule->get($name);
        $history = $this->executions->findHistory($name, $this->historyLimit);

        if (null === $job && !$history) {
            throw $this->createNotFoundException();
        }

        $job ??= CronJob::unscheduled($name, $history[0]->getCommand(), $this->schedule->defaultRetentionDays());
        // a linha do topo mostra a última execução de verdade, não uma pulada pela trava
        $last = null;
        foreach ($history as $execution) {
            if (!$execution->getStatus()->isSkipped()) {
                $last = $execution;
                break;
            }
        }

        return [
            'layout' => $this->layout,
            'jobsDomain' => $this->jobsDomain,
            'row' => $this->monitor->row($job, $last, $now),
            'history' => array_map(fn (CronExecution $e) => [
                'execution' => $e,
                'state' => $this->monitor->state($e),
                'duration' => null !== $e->getDurationMs() ? CronMonitor::formatDuration($e->getDurationMs()) : null,
            ], $history),
            'log' => $this->monitor->tailLog($name, $this->logLines),
            'logPath' => $this->monitor->logPathForDisplay($name),
            'logLines' => $this->logLines,
            'updatedAt' => $now,
        ];
    }
}
