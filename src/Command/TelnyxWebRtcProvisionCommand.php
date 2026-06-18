<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TelnyxWebRtcProvisioningService;
use App\Service\TelnyxWebrtcTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telnyx:webrtc-provision',
    description: 'Validate and provision Telnyx WebRTC browser softphone credentials.',
)]
final class TelnyxWebRtcProvisionCommand extends Command
{
    public function __construct(
        private readonly TelnyxWebRtcProvisioningService $provisioningService,
        private readonly TelnyxWebrtcTokenService $tokenService,
        private readonly string $apiKey,
        private readonly string $credentialConnectionId,
        private readonly string $telephonyCredentialId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === trim($this->apiKey)) {
            $io->error('TELNYX_API_KEY is missing.');

            return Command::FAILURE;
        }

        if ('' === trim($this->credentialConnectionId)) {
            $io->error('TELNYX_WEBRTC_CREDENTIAL_CONNECTION_ID is missing.');

            return Command::FAILURE;
        }

        try {
            $connectionIds = $this->provisioningService->listCredentialConnectionIds();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (!in_array(trim($this->credentialConnectionId), $connectionIds, true)) {
            $io->error(sprintf('Credential connection "%s" was not found in Telnyx.', trim($this->credentialConnectionId)));
            $io->listing($connectionIds);

            return Command::FAILURE;
        }

        try {
            $credential = $this->provisioningService->getOrCreateTelephonyCredential('csr-browser-poc');
            $token = $this->tokenService->generateToken($credential->id);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Telnyx WebRTC browser softphone provisioning completed.');
        $io->writeln(sprintf('credential connection id: %s', trim($this->credentialConnectionId)));
        $io->writeln(sprintf('telephony credential id: %s', $credential->id));
        $io->writeln(sprintf('token generated: %s', '' !== trim($token) ? 'yes' : 'no'));

        if ('' === trim($this->telephonyCredentialId)) {
            $io->warning(sprintf(
                'Set TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID=%s in your environment to pin this credential.',
                $credential->id,
            ));
        }

        return Command::SUCCESS;
    }
}
