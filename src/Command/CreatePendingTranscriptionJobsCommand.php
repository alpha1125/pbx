<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TranscriptionJobService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:transcription-jobs:create-pending',
    description: 'Create missing DB-backed transcription jobs for imported recordings.',
)]
final class CreatePendingTranscriptionJobsCommand extends Command
{
    public function __construct(
        private readonly TranscriptionJobService $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs to create.', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $created = $this->jobs->createPendingJobsForImportedRecordings($limit);

        (new SymfonyStyle($input, $output))->success(sprintf('Created %d pending transcription job(s).', $created));

        return Command::SUCCESS;
    }
}
