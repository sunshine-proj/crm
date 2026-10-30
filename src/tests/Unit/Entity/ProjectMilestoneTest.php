<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\ProjectMilestone;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectMilestoneTest extends TestCase
{
    #[Test]
    public function it_requires_completion_before_invoice_linkage(): void
    {
        $milestone = new ProjectMilestone(new Project(), 'Launch', '', null, 25000, 'EUR');
        $invoice = new Invoice();

        $this->expectException(DomainException::class);
        $milestone->markInvoiced($invoice);
    }

    #[Test]
    public function a_completed_milestone_can_be_invoiced_once(): void
    {
        $milestone = new ProjectMilestone(new Project(), 'Launch', '', null, 25000, 'EUR');
        $invoice = new Invoice();
        $milestone->complete();
        $milestone->markInvoiced($invoice);

        self::assertSame('invoiced', $milestone->getStatus());
        self::assertSame($invoice, $milestone->getInvoice());
        $this->expectException(DomainException::class);
        $milestone->markInvoiced($invoice);
    }
}
