<?php

declare(strict_types=1);

namespace App\Command;

use App\Transcription\Provider\TelnyxTranscriptionConfiguration;
use App\Transcription\SttProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stt:providers',
    description: 'Show configured and registered STT providers.',
)]
final class ListSttProvidersCommand extends Command
{
    public function __construct(
        private readonly SttProviderRegistry $registry,
        private readonly TelnyxTranscriptionConfiguration $telnyxConfiguration,
        private readonly bool $localWorkerEnabled,
        private readonly bool $openAiTranscriptionEnabled,
        private readonly string $telnyxModel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->telnyxConfiguration->toProviderConfig();
        $io->writeln(sprintf('Configured STT_PROVIDER: %s', $this->registry->getDefaultProviderName()));
        $io->writeln(sprintf('TELNYX_TRANSCRIPTION_ENABLED: %s', $this->telnyxConfiguration->isEnabled() ? 'true' : 'false'));
        $io->writeln(sprintf('TELNYX_TRANSCRIPTION_MODEL: %s', $this->telnyxModel !== '' ? $this->telnyxModel : '(empty)'));
        $io->writeln(sprintf('TELNYX_TRANSCRIPTION_LANGUAGE: %s', (string) ($config['language'] ?? '')));
        $io->writeln(sprintf('TELNYX_TRANSCRIPTION_TRACK: %s', (string) ($config['track'] ?? '')));
        $io->newLine();

        $rows = [];
        foreach ($this->registry->getRegisteredProviderNames() as $name) {
            $rows[] = [
                $name,
                $this->enabledFlag($name),
                $this->implementedFlag($name),
                'local_worker' === $name ? 'worker claim API' : ('telnyx' === $name ? 'provider submit + webhook' : 'placeholder'),
            ];
        }

        $io->table(['Provider', 'Enabled', 'Implemented', 'Notes'], $rows);

        return Command::SUCCESS;
    }

    private function enabledFlag(string $provider): string
    {
        return match ($provider) {
            'telnyx' => $this->telnyxConfiguration->isEnabled() ? 'true' : 'false',
            'local_worker' => $this->localWorkerEnabled ? 'true' : 'false',
            'openai_whisper' => $this->openAiTranscriptionEnabled ? 'true' : 'false',
            'aws_transcribe' => 'false',
            default => 'unknown',
        };
    }

    private function implementedFlag(string $provider): string
    {
        return in_array($provider, ['telnyx', 'local_worker'], true) ? 'yes' : 'no';
    }
}
