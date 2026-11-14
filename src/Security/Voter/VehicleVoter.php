<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\Vehicle;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Vehicle>
 */
class VehicleVoter extends Voter
{
    public const MANAGE = 'VEHICLE_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof Vehicle;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Vehicle $vehicle */
        $vehicle = $subject;

        return null !== $vehicle->getOwner()
            && null !== $vehicle->getOwner()->getId()
            && $vehicle->getOwner()->getId() === $user->getId();
    }
}
