<?php

namespace App\Service\Dms;

use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Daily records lifecycle, safe to run repeatedly (`app:dms:lifecycle`, run by the scheduler):
 * 1. registered documents whose effective date has come are in force;
 * 2. documents linked as replaced by a document in force are superseded (their open acknowledgements closed);
 * 3. documents past their expiry date are archived (which starts their retention period).
 * Each change is recorded in the document's trail as "automatic (lifecycle)" and the author is notified.
 */
final class DocumentLifecycle
{
    public const ACTOR = 'automatic (lifecycle)';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentService $documents,
        private readonly DmsNotifier $notifier,
    ) {
    }

    /** @return array{in_force: int, superseded: int, archived: int} */
    public function run(\DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0);
        $result = ['in_force' => 0, 'superseded' => 0, 'archived' => 0];

        foreach ($this->documentRepository->findDueToTakeEffect($today) as $document) {
            if ($document->takeEffect($today)) {
                $this->documents->record($document, self::ACTOR, 'in_force', ['effective_on' => $document->getEffectiveOn()?->format('Y-m-d')]);
                $this->notifier->lifecycle($document->getAuthor(), $document, sprintf('"%s" is now in force.', $document->getTitle()), sprintf('lifecycle:%d:in_force', $document->getId()));
                ++$result['in_force'];
            }
        }
        $this->entityManager->flush();

        $result['superseded'] = $this->documents->supersedeReplacedBy(null, self::ACTOR);
        $this->entityManager->flush();

        foreach ($this->documentRepository->findExpired($today) as $document) {
            if ($document->hasExpired($today)) {
                $this->documents->archiveAs($document, self::ACTOR, 'expired '.$document->getExpiresOn()?->format('Y-m-d'));
                $this->notifier->lifecycle($document->getAuthor(), $document, sprintf('"%s" expired and was archived.', $document->getTitle()), sprintf('lifecycle:%d:archived', $document->getId()));
                ++$result['archived'];
            }
        }
        $this->entityManager->flush();

        return $result;
    }
}
