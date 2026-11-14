<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Trip;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Trip>
 */
class TripVoter extends Voter
{
    public const MANAGE = 'TRIP_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof Trip;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Trip $trip */
        $trip = $subject;

        return null !== $trip->getDriver()
            && null !== $trip->getDriver()->getId()
            && $trip->getDriver()->getId() === $user->getId();
    }
}
