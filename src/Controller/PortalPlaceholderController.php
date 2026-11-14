<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PortalPlaceholderController extends AbstractController
{
    #[Route('/mes-reservations', name: 'app_my_bookings', methods: ['GET'])]
    public function myBookings(): Response
    {
        return new Response('Mes réservations', Response::HTTP_OK);
    }

    #[Route('/mes-trajets', name: 'app_my_trips', methods: ['GET'])]
    public function myTrips(): Response
    {
        return new Response('Mes trajets', Response::HTTP_OK);
    }

    #[Route('/trajets/nouveau', name: 'app_trip_new', methods: ['GET', 'POST'])]
    public function newTrip(): Response
    {
        return new Response('Publier un trajet', Response::HTTP_OK);
    }

    #[Route('/profil/{id}', name: 'app_profile_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function profile(int $id): Response
    {
        return new Response('Profil utilisateur #'.$id, Response::HTTP_OK);
    }

    #[Route('/admin', name: 'admin', methods: ['GET'])]
    public function admin(): Response
    {
        return new Response('Administration', Response::HTTP_OK);
    }
}
