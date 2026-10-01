<?php

namespace App\Tests\Unit\Dto;

use App\Dto\ClientContactInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ClientContactInputTest extends TestCase
{
    #[Test]
    public function it_rejects_blank_names_and_invalid_email_addresses(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        $violations = $validator->validate(new ClientContactInput(name: '', email: 'contact@'));

        self::assertCount(2, $violations);
    }

    #[Test]
    public function it_accepts_a_name_with_optional_contact_details(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        $violations = $validator->validate(new ClientContactInput(
            name: 'Morgan Lee',
            job_title: 'Project lead',
            email: 'morgan@example.com',
            phone: '+1 555 0100',
        ));

        self::assertCount(0, $violations);
    }
}
