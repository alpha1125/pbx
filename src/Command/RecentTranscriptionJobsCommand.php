<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TranscriptionJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:transcription-jobs:recent',
    description: 'Display recent transcription jobs.',
)]
final class RecentTranscriptionJobsCommand extends Command
{
    public function __construct(
        private readonly TranscriptionJobRepository $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->repository->findRecent() as $job) {
            $rows[] = [
                $job->getId(),
                $job->getCallRecording()->getId(),
                $job->getStatus(),
                $job->getProvider(),
                $job->getAttempts(),
                $job->getLockedBy() ?? '',
                $job->getLockedUntil()?->format(DATE_ATOM) ?? '',
                $job->getInputS3Key(),
                $job->getCompletedAt()?->format(DATE_ATOM) ?? '',
                null === $job->getErrorMessage() ? '' : mb_strimwidth($job->getErrorMessage(), 0, 80, '...'),
            ];
        }

        (new SymfonyStyle($input, $output))->table([
            'ID',
            'Recording ID',
            'Status',
            'Provider',
            'Attempts',
            'Locked By',
            'Locked Until',
            'Input S3 Key',
            'Completed At',
            'Error',
        ], $rows);

        return Command::SUCCESS;
    }
}
