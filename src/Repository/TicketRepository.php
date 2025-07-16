<?php

namespace App\Repository;

use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /**
     * Save a ticket to the database
     */
    public function save(Ticket $ticket, bool $flush = true): void
    {
        $this->getEntityManager()->persist($ticket);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a ticket from the database
     */
    public function remove(Ticket $ticket, bool $flush = true): void
    {
        $this->getEntityManager()->remove($ticket);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find tickets by simulation status
     * 
     * @return Ticket[]
     */
    public function findBySimulationStatus(bool $isSimulated): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.isSimulated = :val')
            ->setParameter('val', $isSimulated)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tickets with match count greater than or equal to the given value
     * 
     * @param int $minMatches
     * @return Ticket[]
     */
    public function findByMinimumMatches($minMatches)
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.matchCount >= :val')
            ->setParameter('val', $minMatches)
            ->orderBy('t.matchCount', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
