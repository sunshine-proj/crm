<?php

namespace App\Service\Bank;

use App\Entity\BankAccount;
use App\Entity\BankStatementLine;
use App\Entity\PayablePayment;
use App\Entity\Payment;
use App\Repository\BankStatementLineRepository;
use App\Repository\PayablePaymentRepository;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matches bank statement lines to the customer receipts and vendor payments recorded in the application, and reports
 * what is left on either side. A match requires the exact amount and currency; the date window and the reference only
 * rank candidates. Each payment can be matched to at most one statement line.
 */
final class BankReconciliationService
{
    public const DATE_WINDOW_DAYS = 7;
    public const AUTO_MATCH_MIN_SCORE = 80;

    public function __construct(
        private readonly BankStatementLineRepository $lineRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PayablePaymentRepository $payablePaymentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{type: string, id: int, score: int, reasons: list<string>, description: string, date: string, amount_minor: int}> best first
     */
    public function suggestions(BankStatementLine $line): array
    {
        if (!$line->isOpen()) {
            return [];
        }
        $from = $line->getBookingDate()->modify('-'.self::DATE_WINDOW_DAYS.' days');
        $to = $line->getBookingDate()->modify('+'.self::DATE_WINDOW_DAYS.' days');
        $haystack = $this->normalise($line->getReference().' '.($line->getCounterparty() ?? ''));
        $candidates = [];

        if ($line->getAmountMinor() > 0) {
            foreach ($this->paymentRepository->findInPeriod($line->getCurrency(), $from, $to) as $payment) {
                if ($payment->getAmountMinor() !== $line->getAmountMinor() || $this->lineRepository->findMatchedTo(BankStatementLine::CUSTOMER_PAYMENT, (int) $payment->getId()) !== null) {
                    continue;
                }
                $invoice = $payment->getInvoice();
                $candidates[] = $this->score($line, BankStatementLine::CUSTOMER_PAYMENT, (int) $payment->getId(), $payment->getPaidAt(), $payment->getAmountMinor(),
                    sprintf('Receipt for %s (%s)', $invoice->getInvoiceNumber(), $invoice->getClientName()),
                    [$invoice->getInvoiceNumber(), $payment->getReference()], [$invoice->getClientName()], $haystack);
            }
        } else {
            foreach ($this->payablePaymentRepository->findInPeriod($line->getCurrency(), $from, $to) as $payment) {
                if ($payment->getAmountMinor() !== -$line->getAmountMinor() || $this->lineRepository->findMatchedTo(BankStatementLine::VENDOR_PAYMENT, (int) $payment->getId()) !== null) {
                    continue;
                }
                $bill = $payment->getBill();
                $candidates[] = $this->score($line, BankStatementLine::VENDOR_PAYMENT, (int) $payment->getId(), $payment->getPaidAt(), -$payment->getAmountMinor(),
                    sprintf('Payment of bill %s (%s)', $bill->getVendorReference(), $bill->getVendorName()),
                    [$bill->getVendorReference(), $payment->getReference()], [$bill->getVendorName()], $haystack);
            }
        }
        usort($candidates, static fn (array $a, array $b): int => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);

        return $candidates;
    }

    /** @throws \DomainException|\InvalidArgumentException */
    public function confirm(BankStatementLine $line, string $type, int $paymentId, string $actor): void
    {
        $amount = $line->getAmountMinor();
        if ($type === BankStatementLine::CUSTOMER_PAYMENT) {
            $payment = $this->paymentRepository->find($paymentId);
            $matches = $payment instanceof Payment && $payment->getAmountMinor() === $amount && $payment->getInvoice()->getCurrency() === $line->getCurrency();
        } elseif ($type === BankStatementLine::VENDOR_PAYMENT) {
            $payment = $this->payablePaymentRepository->find($paymentId);
            $matches = $payment instanceof PayablePayment && $payment->getAmountMinor() === -$amount && $payment->getBill()->getCurrency() === $line->getCurrency();
        } else {
            throw new \InvalidArgumentException('Match type must be customer_payment or vendor_payment.');
        }
        if ($payment === null) {
            throw new \DomainException('The recorded payment was not found.');
        }
        if (!$matches) {
            throw new \DomainException('The payment does not have the same amount and currency as the statement line.');
        }
        $other = $this->lineRepository->findMatchedTo($type, $paymentId);
        if ($other !== null && $other->getId() !== $line->getId()) {
            throw new \DomainException('This payment is already matched to another statement line.');
        }
        $line->matchTo($type, $paymentId, $actor);
        $this->entityManager->flush();
    }

    /**
     * Matches every open line that has exactly one strong candidate. Anything ambiguous is left for a person.
     *
     * @return array{matched: int, left_open: int}
     */
    public function autoMatch(BankAccount $account, string $actor): array
    {
        $matched = 0;
        $open = 0;
        $claimed = [];
        foreach ($this->lineRepository->search($account, 'unmatched', null, null, 1000) as $line) {
            $suggestions = array_values(array_filter($this->suggestions($line), static fn (array $s): bool => !isset($claimed[$s['type'].':'.$s['id']])));
            $best = $suggestions[0] ?? null;
            $runnerUp = $suggestions[1] ?? null;
            if ($best !== null && $best['score'] >= self::AUTO_MATCH_MIN_SCORE && ($runnerUp === null || $runnerUp['score'] < $best['score'])) {
                $line->matchTo($best['type'], $best['id'], $actor.' (auto)');
                $claimed[$best['type'].':'.$best['id']] = true;
                ++$matched;
            } else {
                ++$open;
            }
        }
        $this->entityManager->flush();

        return ['matched' => $matched, 'left_open' => $open];
    }

    /**
     * @return array{
     *   statement_lines: array{total: int, matched: int, ignored: int, unmatched: int},
     *   unmatched_lines: list<BankStatementLine>,
     *   unreconciled_receipts: list<Payment>, unreconciled_vendor_payments: list<PayablePayment>,
     *   statement_net_minor: int, matched_net_minor: int
     * }
     */
    public function report(BankAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $lines = $this->lineRepository->search($account, null, $from, $to, 5000);
        $counts = ['total' => count($lines), 'matched' => 0, 'ignored' => 0, 'unmatched' => 0];
        $unmatched = [];
        $net = 0;
        $matchedNet = 0;
        foreach ($lines as $line) {
            $net += $line->getAmountMinor();
            if ($line->getMatchType() === null) {
                ++$counts['unmatched'];
                $unmatched[] = $line;
            } elseif ($line->getMatchType() === BankStatementLine::IGNORED) {
                ++$counts['ignored'];
            } else {
                ++$counts['matched'];
                $matchedNet += $line->getAmountMinor();
            }
        }
        $receipts = array_values(array_filter(
            $this->paymentRepository->findInPeriod($account->getCurrency(), $from, $to),
            fn (Payment $payment): bool => $this->lineRepository->findMatchedTo(BankStatementLine::CUSTOMER_PAYMENT, (int) $payment->getId()) === null,
        ));
        $payments = array_values(array_filter(
            $this->payablePaymentRepository->findInPeriod($account->getCurrency(), $from, $to),
            fn (PayablePayment $payment): bool => $this->lineRepository->findMatchedTo(BankStatementLine::VENDOR_PAYMENT, (int) $payment->getId()) === null,
        ));

        return ['statement_lines' => $counts, 'unmatched_lines' => $unmatched, 'unreconciled_receipts' => $receipts, 'unreconciled_vendor_payments' => $payments, 'statement_net_minor' => $net, 'matched_net_minor' => $matchedNet];
    }

    /**
     * @param list<string|null> $references strings that identify the payment when they appear in the bank reference
     * @param list<string> $names
     * @return array{type: string, id: int, score: int, reasons: list<string>, description: string, date: string, amount_minor: int}
     */
    private function score(BankStatementLine $line, string $type, int $id, \DateTimeImmutable $paidAt, int $amountMinor, string $description, array $references, array $names, string $haystack): array
    {
        $score = 50;
        $reasons = ['exact amount'];
        $days = abs((int) $line->getBookingDate()->diff($paidAt)->format('%a'));
        $score += max(0, 10 - 2 * $days);
        $reasons[] = $days === 0 ? 'same day' : $days.' day(s) apart';
        foreach ($references as $reference) {
            $needle = $this->normalise((string) $reference);
            if (strlen($needle) >= 4 && str_contains($haystack, $needle)) {
                $score += 40;
                $reasons[] = 'reference found in the bank text';
                break;
            }
        }
        foreach ($names as $name) {
            $needle = $this->normalise($name);
            if (strlen($needle) >= 4 && str_contains($haystack, $needle)) {
                $score += 10;
                $reasons[] = 'counterparty name matches';
                break;
            }
        }

        return ['type' => $type, 'id' => $id, 'score' => $score, 'reasons' => $reasons, 'description' => $description, 'date' => $paidAt->format('Y-m-d'), 'amount_minor' => $amountMinor];
    }

    private function normalise(string $text): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $text) ?? '');
    }
}
