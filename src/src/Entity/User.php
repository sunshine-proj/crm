<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'email_unique', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $passwordHash = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column(options: ['default' => '[]'])]
    private array $roles = [];

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $hourlyCostRateMinor = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $hourlyCostCurrency = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @see UserInterface
     * @param
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getHourlyCostRateMinor(): ?int
    {
        return $this->hourlyCostRateMinor;
    }

    public function getHourlyCostCurrency(): ?string
    {
        return $this->hourlyCostCurrency;
    }

    public function setHourlyCostRate(?int $rateMinor, ?string $currency): static
    {
        if (($rateMinor === null) !== ($currency === null) || ($rateMinor !== null && $rateMinor < 0)) {
            throw new \InvalidArgumentException('Hourly cost rate requires a non-negative amount and currency together.');
        }
        $this->hourlyCostRateMinor = $rateMinor;
        $this->hourlyCostCurrency = $currency;

        return $this;
    }
}
