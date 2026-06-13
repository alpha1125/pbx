<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CallRecordingRepository;
use App\Service\RecordingImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recordings:import-pending',
    description: 'Import pending Telnyx recordings into the configured S3 bucket.',
)]
final class ImportPendingRecordingsCommand extends Command
{
    public function __construct(
        private readonly CallRecordingRepository $repository,
        private readonly RecordingImportService $importer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recordings = $this->repository->findPendingImports();
        foreach ($recordings as $recording) {
            $this->importer->import($recording);
        }

        $io->success(sprintf('Processed %d recording import(s).', count($recordings)));

        return Command::SUCCESS;
    }
}
