<?php

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Client;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class ClientActivityLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function record(?Client $client, ?User $author, string $type, string $message): ?Activity
    {
        if ($client === null) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $activity = (new Activity())
            ->setClient($client)
            ->setAuthor($author)
            ->setType($type)
            ->setMessage($message)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($activity);

        return $activity;
    }
}