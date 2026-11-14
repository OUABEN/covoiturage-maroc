<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
use App\Repository\CityRepository;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use App\Repository\VehicleRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly CityRepository $cityRepository,
        private readonly UserRepository $userRepository,
        private readonly VehicleRepository $vehicleRepository,
        private readonly TripRepository $tripRepository,
        private readonly BookingRepository $bookingRepository,
        private readonly ReviewRepository $reviewRepository,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'citiesCount' => $this->cityRepository->count(),
            'usersCount' => $this->userRepository->count(),
            'vehiclesCount' => $this->vehicleRepository->count(),
            'tripsCount' => $this->tripRepository->count(),
            'bookingsCount' => $this->bookingRepository->count(),
            'reviewsCount' => $this->reviewRepository->count(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('AtlasCovoit Maroc — Administration');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('styles/admin-theme.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-gauge-high');
        yield MenuItem::section('Référentiel & Comptes');
        yield MenuItem::linkTo(CityCrudController::class, 'Villes', 'fa fa-city');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fa fa-users');
        yield MenuItem::linkTo(VehicleCrudController::class, 'Véhicules', 'fa fa-car');

        yield MenuItem::section('Covoiturage & Modération');
        yield MenuItem::linkTo(TripCrudController::class, 'Trajets', 'fa fa-route');
        yield MenuItem::linkTo(TripStopCrudController::class, 'Étapes (TripStop)', 'fa fa-location-dot');
        yield MenuItem::linkTo(BookingCrudController::class, 'Réservations (lecture seule)', 'fa fa-ticket');
        yield MenuItem::linkTo(ReviewCrudController::class, 'Avis & Modération', 'fa fa-star');

        yield MenuItem::section('Navigation');
        yield MenuItem::linkToRoute('Retour au site public', 'fa fa-arrow-left', 'app_home');
    }
}
