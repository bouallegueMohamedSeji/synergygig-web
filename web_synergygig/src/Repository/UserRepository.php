<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, User::class);
    }

    public function save(User $entity, bool $flush = false): void {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $entity, bool $flush = false): void {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByEmail(string $email): ?User {
        /** @var User|null $result */
        $result = $this->findOneBy(['email' => $email]);
        return $result;
    }

    /** @return User[] */
    public function findActiveUsers(): array {
        return $this->findBy(['isActive' => true]);
    }

    /** @return User[] */
    public function findByRole(string $role): array {
        return $this->findBy(['role' => $role]);
    }

    /** @return User[] */
    public function search(string $query, int $limit = 20, int $offset = 0): array {
        return $this->createQueryBuilder('u')
            ->where('u.firstName LIKE :query OR u.lastName LIKE :query OR u.email LIKE :query')
            ->andWhere('u.isActive = true')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('u.firstName', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}
