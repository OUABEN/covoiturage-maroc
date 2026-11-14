<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TripStopRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TripStopRepository::class)]
#[ORM\Table(name: 'trip_stop')]
#[ORM\UniqueConstraint(name: 'uniq_trip_stop_trip_order', columns: ['trip_id', 'stop_order'])]
#[UniqueEntity(fields: ['trip', 'stopOrder'], message: 'Cet ordre d\'arrêt existe déjà pour ce trajet.')]
class TripStop
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'stops')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Trip $trip = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?City $city = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?int $stopOrder = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $arrivalTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $originalArrivalTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $departureTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $originalDepartureTime = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\PositiveOrZero]
    private string $priceFromPrevious = '0.00';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(?Trip $trip): static
    {
        $this->trip = $trip;

        return $this;
    }

    public function getCity(): ?City
    {
        return $this->city;
    }

    public function setCity(?City $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getStopOrder(): ?int
    {
        return $this->stopOrder;
    }

    public function setStopOrder(int $stopOrder): static
    {
        $this->stopOrder = $stopOrder;

        return $this;
    }

    public function getArrivalTime(): ?\DateTimeImmutable
    {
        return $this->arrivalTime;
    }

    public function setArrivalTime(?\DateTimeImmutable $arrivalTime): static
    {
        if (null === $this->originalArrivalTime && null !== $arrivalTime) {
            $this->originalArrivalTime = $arrivalTime;
        }
        $this->arrivalTime = $arrivalTime;

        return $this;
    }

    public function getOriginalArrivalTime(): ?\DateTimeImmutable
    {
        return $this->originalArrivalTime;
    }

    public function setOriginalArrivalTime(?\DateTimeImmutable $originalArrivalTime): static
    {
        $this->originalArrivalTime = $originalArrivalTime;

        return $this;
    }

    public function getDepartureTime(): ?\DateTimeImmutable
    {
        return $this->departureTime;
    }

    public function setDepartureTime(?\DateTimeImmutable $departureTime): static
    {
        if (null === $this->originalDepartureTime && null !== $departureTime) {
            $this->originalDepartureTime = $departureTime;
        }
        $this->departureTime = $departureTime;

        return $this;
    }

    public function getOriginalDepartureTime(): ?\DateTimeImmutable
    {
        return $this->originalDepartureTime;
    }

    public function setOriginalDepartureTime(?\DateTimeImmutable $originalDepartureTime): static
    {
        $this->originalDepartureTime = $originalDepartureTime;

        return $this;
    }

    public function getPriceFromPrevious(): string
    {
        return $this->priceFromPrevious;
    }

    public function setPriceFromPrevious(string $priceFromPrevious): static
    {
        $this->priceFromPrevious = $priceFromPrevious;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('#%d %s', (int) $this->stopOrder + 1, (string) $this->city?->getName());
    }
}

