<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Command;

use PsychedCms\DataExchange\Import\ImportService;
use PsychedCms\DataExchange\Import\MediaImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'psychedcms:data:import',
    description: 'Import content from portable JSON format',
)]
final class DataImportCommand extends Command
{
    public function __construct(
        private readonly ImportService $importService,
        private readonly MediaImporter $mediaImporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Input directory', './data-exchange')
            ->addOption('types', 't', InputOption::VALUE_REQUIRED, 'Comma-separated content types (default: all)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without writing')
            ->addOption('on-missing-ref', null, InputOption::VALUE_REQUIRED, 'warn | skip | fail', 'warn');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $inputDir = $input->getOption('input');
        $dryRun = $input->getOption('dry-run');
        $onMissingRef = $input->getOption('on-missing-ref');

        if (!\in_array($onMissingRef, ['warn', 'skip', 'fail'], true)) {
            $io->error('--on-missing-ref must be one of: warn, skip, fail');

            return Command::FAILURE;
        }

        $typeFilter = [];
        $typesOption = $input->getOption('types');

        if ($typesOption !== null) {
            $typeFilter = array_map('trim', explode(',', $typesOption));
        }

        $io->title('PsychedCMS Data Import');
        $io->text('Source: ' . $inputDir);

        if ($dryRun) {
            $io->text('Mode: dry-run (no changes)');
        }

        if (!file_exists($inputDir . '/manifest.json')) {
            $io->error('manifest.json not found in ' . $inputDir);

            return Command::FAILURE;
        }

        try {
            $result = $this->importService->import(
                inputDir: $inputDir,
                typeFilter: $typeFilter,
                dryRun: $dryRun,
                onMissingRef: $onMissingRef,
            );
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();

        foreach ($result['imported'] as $type => $count) {
            $io->text(\sprintf('  %s: %d records', $type, $count));
        }

        // Import media if available
        $mediaDir = $inputDir . '/media';

        if (!$dryRun && is_dir($mediaDir)) {
            $mediaCount = $this->mediaImporter->import($mediaDir);

            if ($mediaCount > 0) {
                $io->text(\sprintf('  media: %d files', $mediaCount));
            }
        }

        // Show warnings
        if ($result['warnings'] !== []) {
            $io->warning(\sprintf('%d missing references:', \count($result['warnings'])));

            foreach (array_slice($result['warnings'], 0, 20) as $warning) {
                $io->text('  ' . $warning);
            }

            if (\count($result['warnings']) > 20) {
                $io->text(\sprintf('  ... and %d more', \count($result['warnings']) - 20));
            }
        }

        $total = array_sum($result['imported']);
        $label = $dryRun ? 'validated' : 'imported';
        $io->success(\sprintf('%s %d records across %d types', ucfirst($label), $total, \count($result['imported'])));

        return Command::SUCCESS;
    }
}
