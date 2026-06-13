<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CallSummaryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:summaries:recent',
    description: 'Display recent call summaries.',
)]
final class RecentSummariesCommand extends Command
{
    public function __construct(
        private readonly CallSummaryRepository $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->repository->findRecent() as $summary) {
            $rows[] = [
                $summary->getId(),
                $summary->getCallTranscript()->getId(),
                $summary->getStatus(),
                $summary->getProvider(),
                $summary->getModel() ?? '',
                $summary->getCreatedAt()->format(DATE_ATOM),
                null === $summary->getErrorMessage() ? '' : mb_strimwidth($summary->getErrorMessage(), 0, 80, '...'),
            ];
        }

        (new SymfonyStyle($input, $output))->table([
            'ID',
            'Transcript ID',
            'Status',
            'Provider',
            'Model',
            'Created At',
            'Error',
        ], $rows);

        return Command::SUCCESS;
    }
}
