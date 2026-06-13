<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ClickToCallRequestRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:click-to-call:recent',
    description: 'Display recent click-to-call requests.',
)]
final class RecentClickToCallCommand extends Command
{
    public function __construct(
        private readonly ClickToCallRequestRepository $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->repository->findRecent() as $request) {
            $rows[] = [
                $request->getId(),
                $request->getStatus(),
                $request->getTargetName() ?? '',
                $request->getAgentNumber(),
                $request->getTargetNumber(),
                $request->getAgentCallLegId() ?? '',
                $request->getTargetCallLegId() ?? '',
                $request->getBridgeStartedAt()?->format(DATE_ATOM) ?? '',
                $request->getRecordingStartedAt()?->format(DATE_ATOM) ?? '',
                $request->getErrorMessage() ?? '',
                $request->getCreatedAt()->format(DATE_ATOM),
            ];
        }

        (new SymfonyStyle($input, $output))->table([
            'ID',
            'Status',
            'Target Name',
            'Agent Number',
            'Target Number',
            'Agent Call Leg ID',
            'Target Call Leg ID',
            'Bridge Started At',
            'Recording Started At',
            'Error Message',
            'Created At',
        ], $rows);

        return Command::SUCCESS;
    }
}
