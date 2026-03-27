<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Command;

use PsychedCms\DataExchange\Export\ExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'psychedcms:data:export',
    description: 'Export content to portable JSON format',
)]
final class DataExportCommand extends Command
{
    public function __construct(
        private readonly ExportService $exportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', './data-exchange')
            ->addOption('types', 't', InputOption::VALUE_REQUIRED, 'Comma-separated content types (default: all)')
            ->addOption('with-media', null, InputOption::VALUE_NONE, 'Copy media files to output')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = $input->getOption('output');
        $pretty = $input->getOption('pretty');
        $withMedia = $input->getOption('with-media');

        $typeFilter = [];
        $typesOption = $input->getOption('types');

        if ($typesOption !== null) {
            $typeFilter = array_map('trim', explode(',', $typesOption));
        }

        $io->title('PsychedCMS Data Export');
        $io->text('Output: ' . $outputDir);

        if ($typeFilter !== []) {
            $io->text('Types: ' . implode(', ', $typeFilter) . ' (+ dependencies)');
        }

        $manifest = $this->exportService->export(
            outputDir: $outputDir,
            typeFilter: $typeFilter,
            withMedia: $withMedia,
            pretty: $pretty,
        );

        $io->newLine();

        foreach ($manifest->recordCounts as $type => $count) {
            $io->text(\sprintf('  %s: %d records', $type, $count));
        }

        $total = array_sum($manifest->recordCounts);
        $io->success(\sprintf('Exported %d records across %d types to %s', $total, \count($manifest->entityTypes), $outputDir));

        return Command::SUCCESS;
    }
}
