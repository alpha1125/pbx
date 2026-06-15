<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CapturePolicyResolver;
use App\Transcription\Provider\TelnyxTranscriptionConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:capture-policy:debug',
    description: 'Show the effective capture policy and Telnyx capture-related configuration.',
)]
final class CapturePolicyDebugCommand extends Command
{
    public function __construct(
        private readonly CapturePolicyResolver $resolver,
        private readonly TelnyxTranscriptionConfiguration $transcriptionConfiguration,
        private readonly string $recordingFormat,
        private readonly string $recordingChannels,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $default = $this->resolver->defaultForContext('default');

        $io->table(['Setting', 'Value'], [
            ['recordAudio', $default->recordAudio ? 'true' : 'false'],
            ['transcribeAudio', $default->transcribeAudio ? 'true' : 'false'],
            ['TELNYX_RECORDING_FORMAT', $this->recordingFormat],
            ['TELNYX_RECORDING_CHANNELS', $this->recordingChannels],
            ['TELNYX_TRANSCRIPTION_LANGUAGE', $this->transcriptionConfiguration->getLanguage()],
            ['TELNYX_TRANSCRIPTION_TRACK', $this->transcriptionConfiguration->getTrack()],
            ['TELNYX_TRANSCRIPTION_MODEL', $this->transcriptionConfiguration->getModel() ?? ''],
            ['TELNYX_TRANSCRIPTION_ENGINE', $this->transcriptionConfiguration->getEngine()],
        ]);

        return Command::SUCCESS;
    }
}
