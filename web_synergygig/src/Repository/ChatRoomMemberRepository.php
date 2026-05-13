<?php

namespace App\Repository;

use App\Entity\ChatRoom;
use App\Entity\ChatRoomMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ChatRoomMember> */

class ChatRoomMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatRoomMember::class);
    }

    /**
     * Find the other member in a DIRECT room.
     */
    public function findOtherMember(ChatRoom $room, User $currentUser): ?ChatRoomMember
    {
        return $this->createQueryBuilder('m')
            ->where('m.room = :room')
            ->andWhere('m.user != :user')
            ->setParameter('room', $room)
            ->setParameter('user', $currentUser)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find an existing DIRECT room between two users.
     */
    public function findDirectRoom(User $a, User $b): ?ChatRoom
    {
        $result = $this->createQueryBuilder('m1')
            ->select('IDENTITY(m1.room) as roomId')
            ->innerJoin(ChatRoomMember::class, 'm2', 'WITH', 'm1.room = m2.room')
            ->innerJoin(ChatRoom::class, 'r', 'WITH', 'm1.room = r')
            ->where('m1.user = :a')
            ->andWhere('m2.user = :b')
            ->andWhere('r.type = :type')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setParameter('type', 'DIRECT')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($result) {
            return $this->getEntityManager()->find(ChatRoom::class, $result['roomId']);
        }
        return null;
    }
}


