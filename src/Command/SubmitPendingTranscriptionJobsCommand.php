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
    name: 'app:transcription-jobs:submit-pending',
    description: 'Submit pending transcription jobs through the configured STT provider.',
)]
final class SubmitPendingTranscriptionJobsCommand extends Command
{
    public function __construct(
        private readonly TranscriptionJobService $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs to submit.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->jobs->submitPendingJobs(max(1, (int) $input->getOption('limit')));
        (new SymfonyStyle($input, $output))->success(sprintf(
            'Provider=%s submitted=%d failed=%d skipped=%d.',
            $result['provider'],
            $result['submitted'],
            $result['failed'],
            $result['skipped'],
        ));

        return Command::SUCCESS;
    }
}
