<?php

namespace App\Repository;

use App\Entity\CallSignal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallSignal> */

class CallSignalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallSignal::class);
    }

    /**
     * Get signals for a call that were sent by the OTHER user, after a given ID.
     */
    public function findNewSignals(int $callId, int $userId, int $afterId = 0): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.call = :callId')
            ->andWhere('s.fromUser != :userId')
            ->andWhere('s.id > :afterId')
            ->setParameter('callId', $callId)
            ->setParameter('userId', $userId)
            ->setParameter('afterId', $afterId)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clean up old signals for ended calls.
     */
    public function deleteForCall(int $callId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.call = :callId')
            ->setParameter('callId', $callId)
            ->getQuery()
            ->execute();
    }
}



