<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Property;
use App\Entity\Tenant;
use App\Service\CustomerHealthCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:crm:health',
    description: 'Show customer health scores for properties across tenants.',
)]
final class ShowCustomerHealthCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerHealthCalculatorService $calculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Limit to a specific tenant ID.')
            ->addOption('property', 'p', InputOption::VALUE_REQUIRED, 'Limit to a specific property ID.')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: table, json.', 'table')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max properties to display (0 = unlimited).', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = $input->getOption('format');
        $limit  = (int) $input->getOption('limit');

        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('p, t.name as tenant_name')
            ->from(Property::class, 'p')
            ->join('p.tenant', 't')
            ->orderBy('t.name, p.addressLine1')
            ->setMaxResults($limit);

        if ($tenantId = $input->getOption('tenant')) {
            $qb->andWhere('p.tenant = :tid')->setParameter('tid', (int) $tenantId);
        }

        if ($propertyId = $input->getOption('property')) {
            $qb->andWhere('p.id = :pid')->setParameter('pid', (int) $propertyId);
        }

        /** @var list<array{p: Property, tenant_name: string}> $results */
        $results = $qb->getQuery()->getResult();

        if (0 === \count($results)) {
            $io->warning('No properties found matching the given criteria.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($results as ['p' => $property, 'tenant_name' => $tenantName]) {
            $health = $this->calculator->calculate($property);
            $rows[] = [
                (string) $property->getId(),
                $tenantName,
                sprintf('%d/100', $health['score']),
                str_replace('_', ' ', ucfirst($health['category'])),
                sprintf('%d factor(s)', \count($health['factors'])),
            ];
        }

        match ($format) {
            'json' => $io->writeln(json_encode(array_map(fn ($r) => [
                'property_id'   => $r[0],
                'tenant'        => $r[1],
                'score'         => $r[2],
                'category'      => str_replace(' ', '_', strtolower($r[3])),
                'factor_count'  => (int) $r[4],
            ], $rows), JSON_PRETTY_PRINT)),
            default => $io->table(
                ['ID', 'Tenant', 'Score', 'Category', 'Factors'],
                $rows,
            ),
        };

        return Command::SUCCESS;
    }
}
