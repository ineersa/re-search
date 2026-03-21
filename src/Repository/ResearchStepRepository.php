<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResearchStep>
 */
class ResearchStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchStep::class);
    }

    public function nextSequenceForRun(ResearchRun $run): int
    {
        $qb = $this->createQueryBuilder('step');
        $qb
            ->select('MAX(step.sequence)')
            ->andWhere('step.run = :run')
            ->setParameter('run', $run);

        $maxSequence = $qb->getQuery()->getSingleScalarResult();
        if (null === $maxSequence) {
            return 1;
        }

        return ((int) $maxSequence) + 1;
    }
}
