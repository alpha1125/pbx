<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CallSessionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:calls:recent',
    description: 'Display recent durable call sessions.',
)]
final class RecentCallsCommand extends Command
{
    public function __construct(
        private readonly CallSessionRepository $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessions = $this->repository->findRecent();
        $billedSeconds = $this->repository->findBilledDurationSeconds($sessions);
        $rows = [];
        foreach ($sessions as $session) {
            $rows[] = [
                $session->getId(),
                $session->getProviderSessionId(),
                $session->getInboundFrom() ?? '',
                $session->getInboundTo() ?? '',
                $session->getStatus(),
                $session->getStartedAt()?->format(DATE_ATOM) ?? '',
                $session->getEndedAt()?->format(DATE_ATOM) ?? '',
                $session->getHangupCause() ?? '',
                null === ($billedSeconds[$session->getId()] ?? null)
                    ? 'N/A'
                    : number_format($billedSeconds[$session->getId()] / 60, 2, '.', ''),
            ];
        }

        (new SymfonyStyle($input, $output))->table([
            'ID',
            'Provider Session ID',
            'Inbound From',
            'Inbound To',
            'Status',
            'Started At',
            'Ended At',
            'Hangup Cause',
            'Billed Minutes',
        ], $rows);

        return Command::SUCCESS;
    }
}
