<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\TripSearchResult;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Enum\TripStatus;
use App\Service\AvailabilityService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trip>
 */
class TripRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AvailabilityService $availabilityService,
    ) {
        parent::__construct($registry, Trip::class);
    }

    /**
     * Recherche les trajets PUBLISHED reliant $fromCity à $toCity (avec sFrom.stopOrder < sTo.stopOrder)
     * à la date $date, et retourne pour chaque trajet trouvé le prix de la portion (via bcmath)
     * et le nombre de places disponibles sur cette portion (ne retourne que les portions ayant >= $minSeats places).
     *
     * @return list<TripSearchResult>
     */
    public function search(City $fromCity, City $toCity, \DateTimeInterface $date, int $minSeats = 1): array
    {
        $dayStart = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
        $dayEnd = \DateTimeImmutable::createFromInterface($date)->setTime(23, 59, 59);

        /** @var list<Trip> $trips */
        $trips = $this->createQueryBuilder('t')
            ->innerJoin(
                TripStop::class,
                'sFrom',
                Join::WITH,
                'sFrom.trip = t AND sFrom.city = :fromCity'
            )
            ->innerJoin(
                TripStop::class,
                'sTo',
                Join::WITH,
                'sTo.trip = t AND sTo.city = :toCity AND sFrom.stopOrder < sTo.stopOrder'
            )
            ->leftJoin('t.stops', 'allStops')
            ->addSelect('allStops')
            ->where('t.status = :status')
            ->andWhere('sFrom.departureTime >= :dayStart')
            ->andWhere('sFrom.departureTime <= :dayEnd')
            ->setParameter('fromCity', $fromCity)
            ->setParameter('toCity', $toCity)
            ->setParameter('status', TripStatus::PUBLISHED)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd)
            ->orderBy('sFrom.departureTime', 'ASC')
            ->getQuery()
            ->getResult();

        $results = [];

        foreach ($trips as $trip) {
            $fromStop = null;
            $toStop = null;

            foreach ($trip->getStops() as $stop) {
                if (null === $fromStop && $stop->getCity()?->getId() === $fromCity->getId()) {
                    $fromStop = $stop;
                } elseif (null !== $fromStop && $stop->getCity()?->getId() === $toCity->getId() && $stop->getStopOrder() > $fromStop->getStopOrder()) {
                    $toStop = $stop;
                    break;
                }
            }

            if (null === $fromStop || null === $toStop) {
                continue;
            }

            $availableSeats = $this->availabilityService->availableSeats($trip, $fromStop, $toStop);
            if ($availableSeats < $minSeats) {
                continue;
            }

            $portionPrice = '0.00';
            foreach ($trip->getStops() as $stop) {
                $order = $stop->getStopOrder();
                if (null !== $order && $order > $fromStop->getStopOrder() && $order <= $toStop->getStopOrder()) {
                    $portionPrice = bcadd($portionPrice, $stop->getPriceFromPrevious(), 2);
                }
            }

            $results[] = new TripSearchResult(
                trip: $trip,
                fromStop: $fromStop,
                toStop: $toStop,
                portionPrice: $portionPrice,
                availableSeats: $availableSeats,
            );
        }

        return $results;
    }

    /**
     * Retourne les $limit prochains trajets PUBLISHED dont le départ est futur,
     * triés par date de départ croissante puis places restantes, en une seule requête eager (sans N+1).
     *
     * @return list<Trip>
     */
    public function findUpcomingPopular(int $limit = 6): array
    {
        $now = new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('t')
            ->innerJoin('t.stops', 'firstStop', Join::WITH, 'firstStop.stopOrder = 1')
            ->leftJoin('t.stops', 'allStops')
            ->leftJoin('allStops.city', 'c')
            ->leftJoin('t.driver', 'd')
            ->leftJoin('t.vehicle', 'v')
            ->addSelect('allStops', 'c', 'd', 'v')
            ->where('t.status = :status')
            ->andWhere('firstStop.departureTime >= :now')
            ->setParameter('status', TripStatus::PUBLISHED)
            ->setParameter('now', $now)
            ->orderBy('firstStop.departureTime', 'ASC')
            ->addOrderBy('t.seatsTotal', 'DESC')
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: true);

        /** @var list<Trip> $trips */
        $trips = iterator_to_array($paginator);

        return $trips;
    }
}
