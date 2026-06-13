<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CallSummaryRepository;
use App\Service\OllamaSummaryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:summaries:run-pending',
    description: 'Process pending call summaries through Ollama.',
)]
final class RunPendingSummariesCommand extends Command
{
    public function __construct(
        private readonly CallSummaryRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly OllamaSummaryService $summaries,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum pending summaries to process.', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $processed = 0;
        $failed = 0;

        foreach ($this->repository->findPending($limit) as $summary) {
            $summary->setStatus('processing')->setProvider('ollama')->setModel($this->summaries->getModel())->touch();

            try {
                $result = $this->summaries->summarize($summary->getCallTranscript());
                $this->summaries->applySummary($summary, $result);
                ++$processed;
            } catch (\Throwable $exception) {
                $summary
                    ->setStatus('failed')
                    ->setErrorMessage($exception->getMessage())
                    ->touch();
                ++$failed;
            }
        }

        $this->entityManager->flush();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Processed %d pending summary row(s); %d failed.',
            $processed,
            $failed,
        ));

        return Command::SUCCESS;
    }
}
