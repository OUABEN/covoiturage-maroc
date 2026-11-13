<?php

declare(strict_types=1);

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Exception\NotEnoughSeatsException;
use App\Kernel;
use App\Service\BookingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

[, $tripId, $passengerId, $fromStopId, $toStopId] = $argv;

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);
/** @var BookingService $bookingService */
$bookingService = $container->get(BookingService::class);

$trip = $em->find(Trip::class, (int) $tripId);
$passenger = $em->find(User::class, (int) $passengerId);
$fromStop = $em->find(TripStop::class, (int) $fromStopId);
$toStop = $em->find(TripStop::class, (int) $toStopId);

try {
    $booking = $bookingService->book($trip, $passenger, $fromStop, $toStop, 1);
    fwrite(STDOUT, 'BOOKED:'.$booking->getId());
    exit(0);
} catch (NotEnoughSeatsException $e) {
    fwrite(STDOUT, 'NOT_ENOUGH_SEATS:'.$e::class);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR:'.$e::class.':'.$e->getMessage());
    exit(1);
}
