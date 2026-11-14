<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Repository\BookingRepository;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use App\Repository\VehicleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProfileController extends AbstractController
{
    #[Route('/profil/{id}', name: 'app_profile_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        User $user,
        ReviewRepository $reviewRepository,
        TripRepository $tripRepository,
        BookingRepository $bookingRepository,
        VehicleRepository $vehicleRepository,
    ): Response {
        $ratingSummary = $reviewRepository->getRatingSummaryForUser($user);
        $receivedReviews = $reviewRepository->findBy(
            ['target' => $user],
            ['createdAt' => 'DESC']
        );

        $completedDriverTripsCount = $tripRepository->count([
            'driver' => $user,
            'status' => TripStatus::COMPLETED,
        ]);

        $publishedDriverTripsCount = $tripRepository->count([
            'driver' => $user,
            'status' => TripStatus::PUBLISHED,
        ]);

        $confirmedBookingsCount = $bookingRepository->count([
            'passenger' => $user,
            'status' => BookingStatus::CONFIRMED,
        ]);

        $vehicles = $vehicleRepository->findBy(['owner' => $user], ['id' => 'DESC']);

        return $this->render('profile/show.html.twig', [
            'profileUser' => $user,
            'ratingSummary' => $ratingSummary,
            'receivedReviews' => $receivedReviews,
            'completedDriverTripsCount' => $completedDriverTripsCount,
            'publishedDriverTripsCount' => $publishedDriverTripsCount,
            'confirmedBookingsCount' => $confirmedBookingsCount,
            'vehicles' => $vehicles,
        ]);
    }
}
