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
    name: 'app:transcripts:recent',
    description: 'Display recent call transcripts.',
)]
final class RecentTranscriptsCommand extends Command
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
                $transcript->getTranscriptionJob()?->getId() ?? '',
                $transcript->getCallSession()?->getId() ?? '',
                $transcript->getCallRecording()?->getId() ?? '',
                $transcript->getProvider(),
                $transcript->getModel() ?? '',
                $transcript->getStatus(),
                $transcript->getLanguage() ?? '',
                $transcript->getDurationSeconds() ?? '',
                $transcript->getCreatedAt()->format(DATE_ATOM),
            ];
        }

        (new SymfonyStyle($input, $output))->table([
            'ID',
            'Job ID',
            'Call Session ID',
            'Recording ID',
            'Provider',
            'Model',
            'Status',
            'Language',
            'Duration',
            'Created At',
        ], $rows);

        return Command::SUCCESS;
    }
}
