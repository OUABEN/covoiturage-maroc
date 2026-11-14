<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Booking;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Booking>
 */
class BookingVoter extends Voter
{
    public const VIEW = 'BOOKING_VIEW';
    public const CANCEL = 'BOOKING_CANCEL';
    public const REVIEW = 'BOOKING_REVIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::CANCEL, self::REVIEW], true)
            && $subject instanceof Booking;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Booking $booking */
        $booking = $subject;

        return null !== $booking->getPassenger()
            && null !== $booking->getPassenger()->getId()
            && $booking->getPassenger()->getId() === $user->getId();
    }
}
