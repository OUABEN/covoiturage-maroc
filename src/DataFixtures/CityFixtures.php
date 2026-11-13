<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\City;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CityFixtures extends Fixture
{
    public const CITY_AGADIR = 'city_agadir';
    public const CITY_MARRAKECH = 'city_marrakech';
    public const CITY_ESSAOUIRA = 'city_essaouira';
    public const CITY_CASABLANCA = 'city_casablanca';
    public const CITY_RABAT = 'city_rabat';
    public const CITY_TIZNIT = 'city_tiznit';
    public const CITY_TAROUDANT = 'city_taroudant';
    public const CITY_SAFI = 'city_safi';
    public const CITY_EL_JADIDA = 'city_el_jadida';
    public const CITY_FES = 'city_fes';

    public function load(ObjectManager $manager): void
    {
        $citiesData = [
            self::CITY_AGADIR => ['name' => 'Agadir', 'lat' => '30.427755', 'lng' => '-9.598107'],
            self::CITY_MARRAKECH => ['name' => 'Marrakech', 'lat' => '31.629472', 'lng' => '-7.981084'],
            self::CITY_ESSAOUIRA => ['name' => 'Essaouira', 'lat' => '31.508493', 'lng' => '-9.759504'],
            self::CITY_CASABLANCA => ['name' => 'Casablanca', 'lat' => '33.573110', 'lng' => '-7.589843'],
            self::CITY_RABAT => ['name' => 'Rabat', 'lat' => '34.020882', 'lng' => '-6.841650'],
            self::CITY_TIZNIT => ['name' => 'Tiznit', 'lat' => '29.697424', 'lng' => '-9.731620'],
            self::CITY_TAROUDANT => ['name' => 'Taroudant', 'lat' => '30.470280', 'lng' => '-8.876950'],
            self::CITY_SAFI => ['name' => 'Safi', 'lat' => '32.299390', 'lng' => '-9.237180'],
            self::CITY_EL_JADIDA => ['name' => 'El Jadida', 'lat' => '33.231633', 'lng' => '-8.500712'],
            self::CITY_FES => ['name' => 'Fès', 'lat' => '34.018125', 'lng' => '-5.007845'],
        ];

        foreach ($citiesData as $reference => $data) {
            $city = (new City())
                ->setName($data['name'])
                ->setLatitude($data['lat'])
                ->setLongitude($data['lng']);

            $manager->persist($city);
            $this->addReference($reference, $city);
        }

        $manager->flush();
    }
}
