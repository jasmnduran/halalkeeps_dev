<?php
/** Minimal, non-mutating interface from document screening to HaCeRa AI context. */
declare(strict_types=1);

final class DocumentScreeningHaCeRaBridge
{
    /** @var DocumentScreeningRepository */
    private $repository;

    public function __construct(mysqli $conn)
    {
        $this->repository = new DocumentScreeningRepository($conn);
    }

    /** @return array<int,array<string,mixed>> */
    public function findingsForRestaurant(int $restaurantId): array
    {
        return $this->repository->haceraEvidenceForRestaurant($restaurantId);
    }
}
