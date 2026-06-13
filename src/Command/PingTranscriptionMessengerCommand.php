<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\PingTranscriptionMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:messenger:ping-transcription',
    description: 'Dispatch a ping through the transcription messenger transport.',
)]
final class PingTranscriptionMessengerCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pingId = bin2hex(random_bytes(8));
        $this->bus->dispatch(new PingTranscriptionMessage($pingId));

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Dispatched PingTranscriptionMessage %s to the transcription transport.',
            $pingId,
        ));

        return Command::SUCCESS;
    }
}
