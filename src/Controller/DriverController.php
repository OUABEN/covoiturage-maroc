<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\TripStatus;
use App\Exception\InvalidCancellationException;
use App\Exception\InvalidTripOperationException;
use App\Repository\CityRepository;
use App\Repository\TripRepository;
use App\Repository\VehicleRepository;
use App\Security\Voter\TripVoter;
use App\Security\Voter\VehicleVoter;
use App\Service\CancellationService;
use App\Service\TripService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DriverController extends AbstractController
{
    #[Route('/mes-trajets', name: 'app_my_trips', methods: ['GET'])]
    public function myTrips(
        TripRepository $tripRepository,
        VehicleRepository $vehicleRepository,
    ): Response {
        /** @var User $driver */
        $driver = $this->getUser();

        $allTrips = $tripRepository->findBy(
            ['driver' => $driver],
            ['createdAt' => 'DESC']
        );

        $publishedTrips = [];
        $historyTrips = [];

        foreach ($allTrips as $trip) {
            if (TripStatus::PUBLISHED === $trip->getStatus()) {
                $publishedTrips[] = $trip;
            } else {
                $historyTrips[] = $trip;
            }
        }

        $vehicles = $vehicleRepository->findBy(['owner' => $driver], ['id' => 'DESC']);

        return $this->render('driver/trips.html.twig', [
            'publishedTrips' => $publishedTrips,
            'historyTrips' => $historyTrips,
            'vehicles' => $vehicles,
        ]);
    }

    #[Route('/mes-vehicules', name: 'app_my_vehicles', methods: ['GET', 'POST'])]
    public function vehicles(
        Request $request,
        VehicleRepository $vehicleRepository,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('manage_vehicle', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton CSRF invalide.');

                return $this->redirectToRoute('app_my_vehicles');
            }

            $brand = trim((string) $request->request->get('brand', ''));
            $model = trim((string) $request->request->get('model', ''));
            $color = trim((string) $request->request->get('color', ''));
            $seats = $request->request->getInt('seats', 4);

            if ('' === $brand || '' === $model || '' === $color || $seats < 1 || $seats > 9) {
                $this->addFlash('danger', 'Veuillez remplir tous les champs du véhicule (1 à 9 places).');

                return $this->redirectToRoute('app_my_vehicles');
            }

            $vehicle = (new Vehicle())
                ->setOwner($user)
                ->setBrand($brand)
                ->setModel($model)
                ->setColor($color)
                ->setSeats($seats);

            $em->persist($vehicle);
            $em->flush();

            $this->addFlash('success', sprintf('Véhicule %s %s ajouté avec succès.', $brand, $model));

            return $this->redirectToRoute('app_my_vehicles');
        }

        return $this->render('driver/vehicles.html.twig', [
            'vehicles' => $vehicleRepository->findBy(['owner' => $user], ['id' => 'DESC']),
        ]);
    }

    #[Route('/mes-vehicules/{id}/supprimer', name: 'app_vehicle_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteVehicle(
        Vehicle $vehicle,
        Request $request,
        TripRepository $tripRepository,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(VehicleVoter::MANAGE, $vehicle);

        if (!$this->isCsrfTokenValid('delete_vehicle_'.$vehicle->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_my_vehicles');
        }

        if ($tripRepository->count(['vehicle' => $vehicle]) > 0) {
            $this->addFlash('danger', 'Impossible de supprimer un véhicule déjà utilisé par un trajet.');

            return $this->redirectToRoute('app_my_vehicles');
        }

        $em->remove($vehicle);
        $em->flush();

        $this->addFlash('success', 'Véhicule supprimé.');

        return $this->redirectToRoute('app_my_vehicles');
    }

    #[Route('/trajets/nouveau', name: 'app_trip_new', methods: ['GET', 'POST'])]
    public function newTrip(
        Request $request,
        CityRepository $cityRepository,
        VehicleRepository $vehicleRepository,
        TripService $tripService,
    ): Response {
        /** @var User $driver */
        $driver = $this->getUser();
        $vehicles = $vehicleRepository->findBy(['owner' => $driver], ['id' => 'DESC']);
        $cities = $cityRepository->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('publish_trip', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton CSRF invalide.');

                return $this->redirectToRoute('app_trip_new');
            }

            $vehicleId = $request->request->getInt('vehicle_id');
            $seatsTotal = $request->request->getInt('seats_total', 1);
            /** @var array<int, array<string, string>> $stopsInput */
            $stopsInput = $request->request->all('stops');

            $vehicle = $vehicleRepository->find($vehicleId);
            if (null === $vehicle) {
                $this->addFlash('danger', 'Veuillez choisir un véhicule valide.');

                return $this->redirectToRoute('app_trip_new');
            }

            $trip = (new Trip())
                ->setDriver($driver)
                ->setVehicle($vehicle)
                ->setSeatsTotal($seatsTotal);

            foreach (array_values($stopsInput) as $idx => $stopRow) {
                $cityId = (int) ($stopRow['city_id'] ?? 0);
                /** @var City|null $city */
                $city = $cityRepository->find($cityId);
                if (null === $city) {
                    $this->addFlash('danger', sprintf('Ville invalide à l\'étape #%d.', $idx + 1));

                    return $this->redirectToRoute('app_trip_new');
                }

                $arrStr = trim((string) ($stopRow['arrival_time'] ?? ''));
                $depStr = trim((string) ($stopRow['departure_time'] ?? ''));
                $price = 0 === $idx ? '0.00' : number_format((float) ($stopRow['price_from_previous'] ?? '0'), 2, '.', '');

                $stop = (new TripStop())
                    ->setCity($city)
                    ->setStopOrder($idx)
                    ->setArrivalTime('' !== $arrStr ? new \DateTimeImmutable($arrStr) : null)
                    ->setDepartureTime('' !== $depStr ? new \DateTimeImmutable($depStr) : null)
                    ->setPriceFromPrevious($price);

                $trip->addStop($stop);
            }

            try {
                $tripService->publish($trip, $driver);
                $this->addFlash('success', 'Votre trajet a été publié avec succès !');

                return $this->redirectToRoute('app_my_trips');
            } catch (InvalidTripOperationException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('driver/new_trip.html.twig', [
            'vehicles' => $vehicles,
            'cities' => $cities,
        ]);
    }

    #[Route('/mes-trajets/arrets/{id}/horaire', name: 'app_driver_stop_update_time', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStopTime(
        TripStop $stop,
        Request $request,
        TripService $tripService,
    ): Response {
        $this->denyAccessUnlessGranted(TripVoter::MANAGE, $stop->getTrip());

        if (!$this->isCsrfTokenValid('update_stop_'.$stop->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_my_trips');
        }

        /** @var User $driver */
        $driver = $this->getUser();

        $arrStr = trim((string) $request->request->get('arrival_time', ''));
        $depStr = trim((string) $request->request->get('departure_time', ''));

        $newArr = '' !== $arrStr ? new \DateTimeImmutable($arrStr) : $stop->getArrivalTime();
        $newDep = '' !== $depStr ? new \DateTimeImmutable($depStr) : $stop->getDepartureTime();

        try {
            $exceededTolerance = $tripService->updateStopTime($stop, $driver, $newArr, $newDep);
            if ($exceededTolerance) {
                $this->addFlash('warning', 'Horaire mis à jour (décalage > ±30 min : les passagers confirmés bénéficient du remboursement à 100 % en cas d\'annulation).');
            } else {
                $this->addFlash('success', 'Horaire de l\'étape mis à jour.');
            }
        } catch (InvalidTripOperationException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_my_trips');
    }

    #[Route('/mes-trajets/{id}/terminer', name: 'app_driver_trip_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function completeTrip(
        Trip $trip,
        Request $request,
        TripService $tripService,
    ): Response {
        $this->denyAccessUnlessGranted(TripVoter::MANAGE, $trip);

        if (!$this->isCsrfTokenValid('complete_trip_'.$trip->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_my_trips');
        }

        /** @var User $driver */
        $driver = $this->getUser();

        try {
            $tripService->complete($trip, $driver);
            $this->addFlash('success', 'Le trajet a été marqué comme terminé. Les avis croisés sont désormais ouverts.');
        } catch (InvalidTripOperationException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_my_trips');
    }

    #[Route('/mes-trajets/{id}/annuler', name: 'app_driver_trip_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancelTrip(
        Trip $trip,
        Request $request,
        CancellationService $cancellationService,
    ): Response {
        $this->denyAccessUnlessGranted(TripVoter::MANAGE, $trip);

        if (!$this->isCsrfTokenValid('cancel_trip_'.$trip->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_my_trips');
        }

        /** @var User $driver */
        $driver = $this->getUser();

        try {
            $cancellationService->cancelTripByDriver($trip, $driver);
            $this->addFlash('warning', 'Le trajet a été annulé. Toutes les réservations confirmées ont été remboursées à 100 %.');
        } catch (InvalidCancellationException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_my_trips');
    }
}
