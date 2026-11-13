<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Trip;
use App\Entity\TripStop;

/**
 * Résultat d'une recherche de trajet sur une portion [fromStop, toStop]
 * incluant le prix exact de la portion (decimal string) et les places disponibles sur cette portion.
 *
 * @implements \ArrayAccess<string, mixed>
 */
readonly class TripSearchResult implements \ArrayAccess
{
    public function __construct(
        public Trip $trip,
        public TripStop $fromStop,
        public TripStop $toStop,
        public string $portionPrice,
        public int $availableSeats,
    ) {
    }

    public function offsetExists(mixed $offset): bool
    {
        return \in_array($offset, ['trip', 'fromStop', 'toStop', 'portionPrice', 'price', 'availableSeats'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'trip' => $this->trip,
            'fromStop' => $this->fromStop,
            'toStop' => $this->toStop,
            'portionPrice', 'price' => $this->portionPrice,
            'availableSeats' => $this->availableSeats,
            default => throw new \InvalidArgumentException(sprintf('Unknown offset "%s" on TripSearchResult.', (string) $offset)),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('TripSearchResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('TripSearchResult is immutable.');
    }
}
