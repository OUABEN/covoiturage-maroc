<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Enum\BookingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Calcule en une seule requête DQL agrégée le nombre maximal de places occupées
     * par des réservations CONFIRMED sur l'ensemble des segments élémentaires k
     * appartenant à l'intervalle [$fromOrder, $toOrder[.
     */
    public function findMaxOccupiedSeatsInRange(Trip $trip, int $fromOrder, int $toOrder): int
    {
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('COALESCE(SUM(CASE WHEN bf.stopOrder <= s.stopOrder AND bt.stopOrder > s.stopOrder THEN b.seats ELSE 0 END), 0) AS occupiedSeats')
            ->from(TripStop::class, 's')
            ->leftJoin(
                Booking::class,
                'b',
                Join::WITH,
                'b.trip = s.trip AND b.status = :confirmedStatus'
            )
            ->leftJoin('b.fromStop', 'bf')
            ->leftJoin('b.toStop', 'bt')
            ->where('s.trip = :trip')
            ->andWhere('s.stopOrder >= :fromOrder')
            ->andWhere('s.stopOrder < :toOrder')
            ->setParameter('trip', $trip)
            ->setParameter('confirmedStatus', BookingStatus::CONFIRMED)
            ->setParameter('fromOrder', $fromOrder)
            ->setParameter('toOrder', $toOrder)
            ->groupBy('s.id')
            ->orderBy('occupiedSeats', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $result) {
            return 0;
        }

        return (int) $result['occupiedSeats'];
    }
}
