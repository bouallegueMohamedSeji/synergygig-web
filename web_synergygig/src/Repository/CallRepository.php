<?php

namespace App\Repository;

use App\Entity\Call;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTime;

/** @extends ServiceEntityRepository<Call> */
class CallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Call::class);
    }

    public function save(Call $entity, bool $flush = false): void {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<Call> */
    public function findIncomingForUser(User $user): array {
        return $this->createQueryBuilder('c')
            ->where('c.callee = :user')
            ->andWhere('c.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'RINGING')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find incoming RINGING calls by raw callee user ID (used by JWT API).
     *
     * @return list<Call>
     */
    public function findIncomingForUserById(int $userId): array {
        return $this->createQueryBuilder('c')
            ->join('c.callee', 'u')
            ->where('u.id = :uid')
            ->andWhere('c.status = :status')
            ->setParameter('uid', $userId)
            ->setParameter('status', 'RINGING')
            ->orderBy('c.created_at', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Find active (RINGING or CONNECTED) call for a user by ID. */
    public function findActiveCallById(int $userId): ?Call {
        return $this->createQueryBuilder('c')
            ->join('c.caller', 'ca')
            ->leftJoin('c.callee', 'ce')
            ->where('ca.id = :uid OR ce.id = :uid')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('uid', $userId)
            ->setParameter('statuses', ['RINGING', 'CONNECTED'])
            ->orderBy('c.created_at', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Call> */
    public function findCallHistory(User $user, int $limit = 50): array {
        return $this->createQueryBuilder('c')
            ->where('c.caller = :user OR c.callee = :user')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['CONNECTED', 'ENDED', 'MISSED', 'REJECTED'])
            ->orderBy('c.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findActiveCall(User $user): ?Call {
        return $this->createQueryBuilder('c')
            ->where('(c.caller = :user OR c.callee = :user)')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['RINGING', 'CONNECTED'])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}



