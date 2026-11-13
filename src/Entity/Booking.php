<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'booking')]
#[ORM\Index(name: 'idx_booking_trip_status', columns: ['trip_id', 'status'])]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Trip $trip = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?User $passenger = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?TripStop $fromStop = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?TripStop $toStop = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?int $seats = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\PositiveOrZero]
    private ?string $price = null;

    #[ORM\Column(length: 30, enumType: BookingStatus::class)]
    private BookingStatus $status = BookingStatus::CONFIRMED;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $refundAmount = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * @var Collection<int, Review>
     */
    #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'booking')]
    private Collection $reviews;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reviews = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validateStops(ExecutionContextInterface $context): void
    {
        if (null === $this->fromStop || null === $this->toStop) {
            return;
        }

        if (null !== $this->trip && ($this->fromStop->getTrip() !== $this->trip || $this->toStop->getTrip() !== $this->trip)) {
            $context->buildViolation('Les arrêts de départ et d\'arrivée doivent appartenir au trajet réservé.')
                ->atPath('fromStop')
                ->addViolation();

            return;
        }

        if ($this->fromStop->getTrip() !== $this->toStop->getTrip()) {
            $context->buildViolation('Les arrêts de départ et d\'arrivée doivent appartenir au même trajet.')
                ->atPath('toStop')
                ->addViolation();

            return;
        }

        if ($this->fromStop->getStopOrder() >= $this->toStop->getStopOrder()) {
            $context->buildViolation('L\'ordre de l\'arrêt de départ doit être strictement inférieur à celui de l\'arrêt d\'arrivée.')
                ->atPath('toStop')
                ->addViolation();
        }
    }

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

    public function getPassenger(): ?User
    {
        return $this->passenger;
    }

    public function setPassenger(?User $passenger): static
    {
        $this->passenger = $passenger;

        return $this;
    }

    public function getFromStop(): ?TripStop
    {
        return $this->fromStop;
    }

    public function setFromStop(?TripStop $fromStop): static
    {
        $this->fromStop = $fromStop;

        return $this;
    }

    public function getToStop(): ?TripStop
    {
        return $this->toStop;
    }

    public function setToStop(?TripStop $toStop): static
    {
        $this->toStop = $toStop;

        return $this;
    }

    public function getSeats(): ?int
    {
        return $this->seats;
    }

    public function setSeats(int $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getStatus(): BookingStatus
    {
        return $this->status;
    }

    public function setStatus(BookingStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRefundAmount(): ?string
    {
        return $this->refundAmount;
    }

    public function setRefundAmount(?string $refundAmount): static
    {
        $this->refundAmount = $refundAmount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    /**
     * @return Collection<int, Review>
     */
    public function getReviews(): Collection
    {
        return $this->reviews;
    }
}

