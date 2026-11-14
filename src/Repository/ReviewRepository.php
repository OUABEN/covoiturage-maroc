<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Review;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * @return array{average: ?float, count: int}
     */
    public function getRatingSummaryForUser(User $user): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) AS avgRating, COUNT(r.id) AS totalReviews')
            ->where('r.target = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();

        $count = (int) ($row['totalReviews'] ?? 0);

        return [
            'average' => $count > 0 ? round((float) $row['avgRating'], 1) : null,
            'count' => $count,
        ];
    }
}
