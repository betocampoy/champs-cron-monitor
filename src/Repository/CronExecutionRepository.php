<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\Repository;

use BetoCampoy\Champs\CronMonitor\Entity\CronExecution;
use BetoCampoy\Champs\CronMonitor\Enum\ExecutionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronExecution>
 */
class CronExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronExecution::class);
    }

    /**
     * Última execução de verdade de cada job (as puladas pela trava não contam: elas aparecem à
     * parte, em countSkippedSince()).
     *
     * @return array<string, CronExecution> nome => execução
     */
    public function findLastByName(): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT MAX(id) FROM ' . CronExecution::TABLE . ' WHERE status <> :skipped GROUP BY name',
            ['skipped' => ExecutionStatus::Skipped->value],
        );
        if (!$ids) {
            return [];
        }

        $byName = [];
        foreach ($this->findBy(['id' => $ids]) as $execution) {
            $byName[$execution->getName()] = $execution;
        }

        return $byName;
    }

    /**
     * Execuções puladas pela trava desde $since, por job: é o sinal de sobreposição (a anterior
     * ainda rodava quando o cron disparou a próxima).
     *
     * @return array<string, int> nome => quantidade
     */
    public function countSkippedSince(\DateTimeInterface $since): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT name, COUNT(*) FROM ' . CronExecution::TABLE . ' WHERE status = :skipped AND started_at >= :since GROUP BY name',
            ['skipped' => ExecutionStatus::Skipped->value, 'since' => $since->format('Y-m-d H:i:s')],
        );

        return array_map('intval', $rows);
    }

    /**
     * @return list<CronExecution>
     */
    public function findHistory(string $name, int $limit): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.name = :name')
            ->setParameter('name', $name)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
