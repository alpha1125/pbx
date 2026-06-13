<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\TranscribeRecordingMessage;
use App\Repository\CallRecordingRepository;
use App\Repository\CallTranscriptRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:transcriptions:start-pending',
    description: 'Dispatch transcription jobs for imported recordings without a current transcript.',
)]
final class StartPendingTranscriptionsCommand extends Command
{
    public function __construct(
        private readonly CallRecordingRepository $recordings,
        private readonly CallTranscriptRepository $transcripts,
        private readonly MessageBusInterface $bus,
        private readonly string $model,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum recordings to dispatch.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $dispatched = 0;
        foreach ($this->recordings->findImportedWithoutCurrentTranscript('openai', $this->model, $limit, $this->transcripts) as $recording) {
            $this->bus->dispatch(new TranscribeRecordingMessage($recording->getId()));
            ++$dispatched;
        }

        (new SymfonyStyle($input, $output))->success(sprintf('Dispatched %d transcription job(s).', $dispatched));

        return Command::SUCCESS;
    }
}
