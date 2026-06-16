<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenant')]
#[ORM\Index(name: 'idx_tenant_name', columns: ['name'])]
class Tenant
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalName = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phone = null;

    #[Assert\Email]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $quoteTaxRateBps = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 30])]
    private int $invoiceDueDays = 30;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $invoicePaymentInstructions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $invoiceFooter = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $rfqVendorEnabled = false;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rfqServiceAreaCountries = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rfqServiceAreaProvinces = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rfqServiceAreaCities = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rfqServiceAreaPostalPrefixes = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $rfqVendorEmailNotificationsEnabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $rfqVendorSmsNotificationsEnabled = false;

    public function __construct(string $name)
    {
        $this->name = trim($name);
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $legalName): static
    {
        $this->legalName = null !== $legalName ? trim($legalName) : null;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = null !== $phone ? trim($phone) : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null !== $email ? trim($email) : null;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = null !== $website ? trim($website) : null;

        return $this;
    }

    public function getQuoteTaxRateBps(): int
    {
        return $this->quoteTaxRateBps;
    }

    public function setQuoteTaxRateBps(int $quoteTaxRateBps): static
    {
        $this->quoteTaxRateBps = max(0, $quoteTaxRateBps);

        return $this;
    }

    public function getInvoiceDueDays(): int
    {
        return $this->invoiceDueDays;
    }

    public function setInvoiceDueDays(int $invoiceDueDays): static
    {
        $this->invoiceDueDays = max(0, $invoiceDueDays);

        return $this;
    }

    public function getInvoicePaymentInstructions(): ?string
    {
        return $this->invoicePaymentInstructions;
    }

    public function setInvoicePaymentInstructions(?string $invoicePaymentInstructions): static
    {
        $this->invoicePaymentInstructions = null !== $invoicePaymentInstructions ? trim($invoicePaymentInstructions) : null;

        return $this;
    }

    public function getInvoiceFooter(): ?string
    {
        return $this->invoiceFooter;
    }

    public function setInvoiceFooter(?string $invoiceFooter): static
    {
        $this->invoiceFooter = null !== $invoiceFooter ? trim($invoiceFooter) : null;

        return $this;
    }

    public function isRfqVendorEnabled(): bool
    {
        return $this->rfqVendorEnabled;
    }

    public function setRfqVendorEnabled(bool $rfqVendorEnabled): static
    {
        $this->rfqVendorEnabled = $rfqVendorEnabled;

        return $this;
    }

    /** @return list<string> */
    public function getRfqServiceAreaCountries(): array
    {
        return $this->rfqServiceAreaCountries;
    }

    /** @param list<string> $rfqServiceAreaCountries */
    public function setRfqServiceAreaCountries(array $rfqServiceAreaCountries): static
    {
        $this->rfqServiceAreaCountries = $this->normalizeStringList($rfqServiceAreaCountries, 'upper');

        return $this;
    }

    /** @return list<string> */
    public function getRfqServiceAreaProvinces(): array
    {
        return $this->rfqServiceAreaProvinces;
    }

    /** @param list<string> $rfqServiceAreaProvinces */
    public function setRfqServiceAreaProvinces(array $rfqServiceAreaProvinces): static
    {
        $this->rfqServiceAreaProvinces = $this->normalizeStringList($rfqServiceAreaProvinces, 'upper');

        return $this;
    }

    /** @return list<string> */
    public function getRfqServiceAreaCities(): array
    {
        return $this->rfqServiceAreaCities;
    }

    /** @param list<string> $rfqServiceAreaCities */
    public function setRfqServiceAreaCities(array $rfqServiceAreaCities): static
    {
        $this->rfqServiceAreaCities = $this->normalizeStringList($rfqServiceAreaCities, 'lower');

        return $this;
    }

    /** @return list<string> */
    public function getRfqServiceAreaPostalPrefixes(): array
    {
        return $this->rfqServiceAreaPostalPrefixes;
    }

    /** @param list<string> $rfqServiceAreaPostalPrefixes */
    public function setRfqServiceAreaPostalPrefixes(array $rfqServiceAreaPostalPrefixes): static
    {
        $this->rfqServiceAreaPostalPrefixes = $this->normalizePostalPrefixList($rfqServiceAreaPostalPrefixes);

        return $this;
    }

    public function isRfqVendorEmailNotificationsEnabled(): bool
    {
        return $this->rfqVendorEmailNotificationsEnabled;
    }

    public function setRfqVendorEmailNotificationsEnabled(bool $rfqVendorEmailNotificationsEnabled): static
    {
        $this->rfqVendorEmailNotificationsEnabled = $rfqVendorEmailNotificationsEnabled;

        return $this;
    }

    public function isRfqVendorSmsNotificationsEnabled(): bool
    {
        return $this->rfqVendorSmsNotificationsEnabled;
    }

    public function setRfqVendorSmsNotificationsEnabled(bool $rfqVendorSmsNotificationsEnabled): static
    {
        $this->rfqVendorSmsNotificationsEnabled = $rfqVendorSmsNotificationsEnabled;

        return $this;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function normalizeStringList(array $values, string $case = 'trim'): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $clean = trim((string) $value);
            if ('' === $clean) {
                continue;
            }

            $clean = match ($case) {
                'upper' => mb_strtoupper($clean),
                'lower' => mb_strtolower($clean),
                default => $clean,
            };

            $normalized[$clean] = $clean;
        }

        return array_values($normalized);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function normalizePostalPrefixList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $clean = strtoupper(preg_replace('/\s+/', '', trim((string) $value)) ?? '');
            if ('' === $clean) {
                continue;
            }

            $normalized[$clean] = $clean;
        }

        return array_values($normalized);
    }
}
