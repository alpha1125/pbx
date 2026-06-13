<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CallRecordingRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recordings:recent',
    description: 'Display recent call recording metadata.',
)]
final class RecentRecordingsCommand extends Command
{
    public function __construct(
        private readonly CallRecordingRepository $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->repository->findRecent() as $recording) {
            $rows[] = [
                $recording->getId(),
                $recording->getCallSession()->getId(),
                $recording->getProviderRecordingId() ?? '',
                $recording->getStatus(),
                $recording->getDurationSeconds() ?? '',
                $recording->getS3Bucket() ?? '',
                $recording->getS3Key() ?? '',
                $recording->getImportedAt()?->format(DATE_ATOM) ?? '',
                $recording->getCreatedAt()->format(DATE_ATOM),
            ];
        }

        (new SymfonyStyle($input, $output))->table([
            'ID',
            'Call Session ID',
            'Provider Recording ID',
            'Status',
            'Duration Seconds',
            'S3 Bucket',
            'S3 Key',
            'Imported At',
            'Created At',
        ], $rows);

        return Command::SUCCESS;
    }
}
