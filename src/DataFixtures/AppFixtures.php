<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Contact;
use App\Entity\Equipment;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $existingUser = $manager->getRepository(User::class)->findOneBy(['email' => 'demo@firstfire.example']);
        if (null !== $existingUser) {
            return;
        }

        $tenant = (new Tenant('FirstFire HVAC Demo'))
            ->setLegalName('FirstFire HVAC Demo Inc.')
            ->setPhone('+12892079888')
            ->setEmail('dispatch@firstfire.example')
            ->setWebsite('https://pbx.firstfire.ca');
        $manager->persist($tenant);

        $user = (new User())
            ->setEmail('demo@firstfire.example')
            ->setFirstName('Demo')
            ->setLastName('User')
            ->setDisplayName('Demo User')
            ->setCellPhone('+14165550100')
            ->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'demo1234'));
        $manager->persist($user);

        $membership = (new UserTenantMembership($user, $tenant))
            ->setRoles(['ROLE_TENANT_ADMIN'])
            ->setIsDefault(true);
        $manager->persist($membership);

        $primaryContact = (new Contact($tenant, 'Lloyd'))
            ->setPrimaryPhone('+14168880123')
            ->setPrimaryEmail('lloyd@example.com');
        $manager->persist($primaryContact);

        $billingContact = (new Contact($tenant, 'Property Billing'))
            ->setCompanyName('Lloyd Holdings')
            ->setPrimaryEmail('billing@example.com');
        $manager->persist($billingContact);

        $property = (new Property($tenant, '123 Furnace Lane', 'Toronto', 'ON', 'M5V 2T6'))
            ->setPropertyType('single_family')
            ->setApproximateSquareFeet(2100)
            ->setYearBuilt(1998)
            ->setNotes('Demo property created for CRM phase 1.');
        $manager->persist($property);

        $manager->persist(
            (new PropertyContact($tenant, $property, $primaryContact))
                ->setRelationshipType(PropertyContact::RELATIONSHIP_OWNER)
                ->setIsPrimary(true)
                ->setStartDate(new \DateTimeImmutable('2024-01-01')),
        );
        $manager->persist(
            (new PropertyContact($tenant, $property, $billingContact))
                ->setRelationshipType(PropertyContact::RELATIONSHIP_BILLING_CONTACT)
                ->setStartDate(new \DateTimeImmutable('2024-01-01')),
        );

        $manager->persist(
            (new Equipment($tenant, $property, Equipment::TYPE_FURNACE))
                ->setBrand('Carrier')
                ->setModelNumber('59TP6B')
                ->setSerialNumber('FFCRM123456')
                ->setInstalledAt(new \DateTimeImmutable('2021-10-15'))
                ->setStatus(Equipment::STATUS_ACTIVE),
        );

        $rfq = (new Rfq('456 Heat Pump Road', 'Mississauga', 'ON', 'L5B 3C1'))
            ->setExternalReference('TP-RFQ-1001')
            ->setCustomerName('Morgan Homeowner')
            ->setCustomerPhone('+16475550123')
            ->setCustomerEmail('morgan@example.com')
            ->setProjectType('heat_pump_replacement')
            ->setDescription('Existing system is failing. Looking for replacement options.')
            ->setStatus(Rfq::STATUS_SENT_TO_VENDORS);
        $manager->persist($rfq);

        $manager->persist(
            new RfqInvitation($tenant, $rfq),
        );

        $manager->flush();
    }
}
