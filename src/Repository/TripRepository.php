<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\TripSearchResult;
use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\BookingStatus;
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
     * à la date $date (ou départ futur si $date est null), et retourne pour chaque trajet trouvé le prix de la portion (via bcmath)
     * et le nombre de places disponibles sur cette portion (ne retourne que les portions ayant >= $minSeats places).
     *
     * @return list<TripSearchResult>
     */
    public function search(City $fromCity, City $toCity, ?\DateTimeInterface $date = null, int $minSeats = 1): array
    {
        $qb = $this->createQueryBuilder('t')
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
            ->leftJoin('allStops.city', 'c')
            ->leftJoin('t.driver', 'd')
            ->leftJoin('t.vehicle', 'v')
            ->addSelect('allStops', 'c', 'd', 'v')
            ->where('t.status = :status')
            ->setParameter('fromCity', $fromCity)
            ->setParameter('toCity', $toCity)
            ->setParameter('status', TripStatus::PUBLISHED)
            ->orderBy('sFrom.departureTime', 'ASC');

        if (null !== $date) {
            $dayStart = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
            $dayEnd = \DateTimeImmutable::createFromInterface($date)->setTime(23, 59, 59);
            $qb->andWhere('sFrom.departureTime >= :dayStart')
                ->andWhere('sFrom.departureTime <= :dayEnd')
                ->setParameter('dayStart', $dayStart)
                ->setParameter('dayEnd', $dayEnd);
        } else {
            $qb->andWhere('sFrom.departureTime >= :now')
                ->setParameter('now', new \DateTimeImmutable());
        }

        /** @var list<Trip> $trips */
        $trips = $qb->getQuery()->getResult();

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
     * Recherche les trajets PUBLISHED à venir passant par $fromCity avec leurs destinations possibles
     * (arrêts ultérieurs avec places disponibles et prix de la portion).
     *
     * @return list<array{trip: Trip, fromStop: TripStop, destinations: list<array{stop: TripStop, portionPrice: string, availableSeats: int}>}>
     */
    public function findUpcomingFromCity(City $fromCity, ?\DateTimeInterface $date = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->innerJoin(
                TripStop::class,
                'sFrom',
                Join::WITH,
                'sFrom.trip = t AND sFrom.city = :fromCity'
            )
            ->leftJoin('t.stops', 'allStops')
            ->leftJoin('allStops.city', 'c')
            ->leftJoin('t.driver', 'd')
            ->leftJoin('t.vehicle', 'v')
            ->addSelect('allStops', 'c', 'd', 'v')
            ->where('t.status = :status')
            ->setParameter('fromCity', $fromCity)
            ->setParameter('status', TripStatus::PUBLISHED)
            ->orderBy('sFrom.departureTime', 'ASC');

        if (null !== $date) {
            $dayStart = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
            $dayEnd = \DateTimeImmutable::createFromInterface($date)->setTime(23, 59, 59);
            $qb->andWhere('sFrom.departureTime >= :dayStart')
                ->andWhere('sFrom.departureTime <= :dayEnd')
                ->setParameter('dayStart', $dayStart)
                ->setParameter('dayEnd', $dayEnd);
        } else {
            $qb->andWhere('sFrom.departureTime >= :now')
                ->setParameter('now', new \DateTimeImmutable());
        }

        /** @var list<Trip> $trips */
        $trips = $qb->getQuery()->getResult();

        $results = [];
        foreach ($trips as $trip) {
            $fromStop = null;
            foreach ($trip->getStops() as $stop) {
                if ($stop->getCity()?->getId() === $fromCity->getId()) {
                    $fromStop = $stop;
                    break;
                }
            }

            if (null === $fromStop) {
                continue;
            }

            $destinations = [];
            $runningPrice = '0.00';
            foreach ($trip->getStops() as $stop) {
                if ((int) $stop->getStopOrder() > (int) $fromStop->getStopOrder()) {
                    $runningPrice = bcadd($runningPrice, $stop->getPriceFromPrevious(), 2);
                    $seats = $this->availabilityService->availableSeats($trip, $fromStop, $stop);
                    if ($seats > 0) {
                        $destinations[] = [
                            'stop' => $stop,
                            'portionPrice' => $runningPrice,
                            'availableSeats' => $seats,
                        ];
                    }
                }
            }

            if (!empty($destinations)) {
                $results[] = [
                    'trip' => $trip,
                    'fromStop' => $fromStop,
                    'destinations' => $destinations,
                ];
            }
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

    /**
     * Charge tous les trajets d'un conducteur avec ses arrêts, villes, véhicule,
     * réservations et passagers en une seule requête (sans N+1).
     *
     * @return list<Trip>
     */
    public function findByDriverWithDetails(User $driver): array
    {
        /** @var list<Trip> $trips */
        $trips = $this->createQueryBuilder('t')
            ->leftJoin('t.vehicle', 'v')
            ->leftJoin('t.stops', 's')
            ->leftJoin('s.city', 'sc')
            ->leftJoin('t.bookings', 'b')
            ->leftJoin('b.passenger', 'p')
            ->leftJoin('b.fromStop', 'fs')
            ->leftJoin('fs.city', 'fsc')
            ->leftJoin('b.toStop', 'ts')
            ->leftJoin('ts.city', 'tsc')
            ->addSelect('v', 's', 'sc', 'b', 'p', 'fs', 'fsc', 'ts', 'tsc')
            ->where('t.driver = :driver')
            ->setParameter('driver', $driver)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $trips;
    }

    /**
     * Agrège via une requête dédiée les places confirmées et le revenu exact en bcmath
     * sur les réservations CONFIRMED des trajets d'un conducteur.
     *
     * @return array{passengersTransported: int, estimatedRevenue: string}
     */
    public function getDriverBookingAggregates(User $driver): array
    {
        /** @var list<array{seats: int, price: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('b.seats AS seats', 'b.price AS price')
            ->from(Booking::class, 'b')
            ->innerJoin('b.trip', 't')
            ->where('t.driver = :driver')
            ->andWhere('b.status = :confirmed')
            ->setParameter('driver', $driver)
            ->setParameter('confirmed', BookingStatus::CONFIRMED)
            ->getQuery()
            ->getArrayResult();

        $passengersTransported = 0;
        $estimatedRevenue = '0.00';

        foreach ($rows as $row) {
            $passengersTransported += (int) $row['seats'];
            $estimatedRevenue = bcadd($estimatedRevenue, (string) $row['price'], 2);
        }

        return [
            'passengersTransported' => $passengersTransported,
            'estimatedRevenue' => $estimatedRevenue,
        ];
    }
}
