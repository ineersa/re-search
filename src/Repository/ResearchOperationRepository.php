<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\ResearchRun;
use App\Entity\ResearchOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResearchOperation>
 */
class ResearchOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchOperation::class);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?ResearchOperation
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    /**
     * @return list<ResearchOperation>
     */
    public function findByRunTypeAndTurnOrderedByPosition(ResearchRun $run, ResearchOperationType $type, int $turnNumber): array
    {
        $operations = $this->findBy(
            [
                'run' => $run,
                'type' => $type->value,
                'turnNumber' => $turnNumber,
            ],
            ['position' => 'ASC', 'id' => 'ASC']
        );

        return array_values($operations);
    }

    public function hasNonTerminalByRunTypeAndTurn(ResearchRun $run, ResearchOperationType $type, int $turnNumber): bool
    {
        $qb = $this->createQueryBuilder('operation');
        $qb
            ->select('COUNT(operation.id)')
            ->andWhere('operation.run = :run')
            ->andWhere('operation.type = :type')
            ->andWhere('operation.turnNumber = :turnNumber')
            ->andWhere('operation.status IN (:statuses)')
            ->setParameter('run', $run)
            ->setParameter('type', $type->value)
            ->setParameter('turnNumber', $turnNumber)
            ->setParameter('statuses', [ResearchOperationStatus::QUEUED->value, ResearchOperationStatus::RUNNING->value]);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
