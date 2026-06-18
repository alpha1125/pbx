<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\CustomerSentimentHistory;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class CustomerSentimentHistoryTest extends TestCase
{
    public function testCreatesHistoryWithLabels(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $user = (new User())->setEmail('csr@example.com')->setPassword('unused')->setDisplayName('CSR Agent');
        $history = new CustomerSentimentHistory(
            $tenant,
            $property,
            $user,
            CustomerSentimentHistory::SENTIMENT_FRUSTRATED,
            'Customer reported repeated system failures.',
        );

        self::assertSame($tenant, $history->getTenant());
        self::assertSame($property, $history->getProperty());
        self::assertSame($user, $history->getRecordedBy());
        self::assertSame(CustomerSentimentHistory::SENTIMENT_FRUSTRATED, $history->getSentiment());
        self::assertSame('Frustrated', $history->getSentimentLabel());
        self::assertSame('Customer reported repeated system failures.', $history->getNote());
        self::assertNotNull($history->getRecordedAt());
    }

    public function testOptionalLinksAndSetterNormalization(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $user = (new User())->setEmail('csr@example.com')->setPassword('unused')->setDisplayName('CSR Agent');
        $contact = new Contact($tenant, 'Primary Contact');
        $callSession = new CallSession('provider-session-1');
        $history = new CustomerSentimentHistory($tenant, $property, $user, '  positive  ');

        $history
            ->setContact($contact)
            ->setCallSession($callSession)
            ->setSentiment('  price_sensitive  ')
            ->setNote('  Customer asked about price options.  ');

        self::assertSame($contact, $history->getContact());
        self::assertSame($callSession, $history->getCallSession());
        self::assertSame('price_sensitive', $history->getSentiment());
        self::assertSame('Customer asked about price options.', $history->getNote());
        self::assertContains(CustomerSentimentHistory::SENTIMENT_PRICE_SENSITIVE, CustomerSentimentHistory::getSentimentKeys());
    }
}
