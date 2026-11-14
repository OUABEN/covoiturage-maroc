<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\City;
use App\Service\Geo\OsrmClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

class RouteControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testApiRouteEndpointReturnsDistanceDurationGeoJsonAndSuggestedPrice(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $agadir = (new City())->setName('ApiAgadir_'.$suffix)->setLatitude('30.427755')->setLongitude('-9.598107');
        $marrakech = (new City())->setName('ApiMarrakech_'.$suffix)->setLatitude('31.629472')->setLongitude('-7.981084');
        $this->em->persist($agadir);
        $this->em->persist($marrakech);
        $this->em->flush();

        // Inject MockHttpClient into OsrmClient so no real network call is performed
        $mockHttp = new MockHttpClient([
            new MockResponse(json_encode([
                'code' => 'Ok',
                'routes' => [
                    [
                        'distance' => 250000.0, // 250 km -> 250 * 0.60 = 150.00 MAD
                        'duration' => 9600.0,   // 160 min
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => [[-9.598107, 30.427755], [-7.981084, 31.629472]],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        static::getContainer()->set(OsrmClient::class, new OsrmClient($mockHttp, new ArrayAdapter()));

        $this->client->request('GET', sprintf('/api/route?from=%d&to=%d', $agadir->getId(), $marrakech->getId()));
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(250.0, (float) $payload['distanceKm']);
        self::assertSame(160, $payload['durationMinutes']);
        self::assertSame('150.00', $payload['suggestedPrice']);
        self::assertSame('LineString', $payload['geometry']['type']);
        self::assertFalse($payload['degraded']);

        // Invalid parameters -> 400 Bad Request
        $this->client->request('GET', '/api/route?from=0&to=0');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }
}
