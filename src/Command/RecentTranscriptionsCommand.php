<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CallTranscriptRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:transcriptions:recent',
    description: 'Display recent call transcript records.',
)]
final class RecentTranscriptionsCommand extends Command
{
    public function __construct(
        private readonly CallTranscriptRepository $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->repository->findRecent() as $transcript) {
            $rows[] = [
                $transcript->getId(),
                $transcript->getCallRecording()?->getId() ?? '',
                $transcript->getCallSession()?->getId() ?? '',
                $transcript->getProvider(),
                $transcript->getModel(),
                $transcript->getStatus(),
                $transcript->getLanguage() ?? '',
                $transcript->getDurationSeconds() ?? '',
                $transcript->getCreatedAt()->format(DATE_ATOM),
                $transcript->getCompletedAt()?->format(DATE_ATOM) ?? '',
                null === $transcript->getErrorMessage() ? '' : mb_strimwidth($transcript->getErrorMessage(), 0, 80, '...'),
            ];
        }

        (new SymfonyStyle($input, $output))->table([
            'ID',
            'Recording ID',
            'Call Session ID',
            'Provider',
            'Model',
            'Status',
            'Language',
            'Duration',
            'Created At',
            'Completed At',
            'Error',
        ], $rows);

        return Command::SUCCESS;
    }
}
