<?php

namespace Zeusi\JsonSchemaExtractor\Bridge\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;

#[AsCommand(
    name: 'json-schema-extractor:extract',
    description: 'Extracts a JSON Schema document for a PHP class.'
)]
final class ExtractJsonSchemaCommand extends Command
{
    /**
     * @param ServiceProviderInterface<SchemaExtractor> $extractors
     */
    public function __construct(
        private readonly ServiceProviderInterface $extractors,
        private readonly string $defaultExtractorName,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'Fully-qualified class name to extract.')
            ->addOption('extractor', null, InputOption::VALUE_REQUIRED, 'Configured extractor name to use.')
            ->addOption('compact', null, InputOption::VALUE_NONE, 'Print compact JSON output.');
    }

    /**
     * @throws \JsonException
     * @throws \LogicException
     * @throws \ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $className = $input->getArgument('class');
        if (!\is_string($className) || !class_exists($className)) {
            throw new \InvalidArgumentException('The "class" argument must be an existing class name.');
        }

        /** @var class-string $className */
        $extractorName = $input->getOption('extractor') ?? $this->defaultExtractorName;
        if (!\is_string($extractorName) || $extractorName === '' || !$this->extractors->has($extractorName)) {
            throw new \InvalidArgumentException('The requested extractor is not configured.');
        }

        $extractor = $this->extractors->get($extractorName);

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
        if ($input->getOption('compact') !== true) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $output->writeln(json_encode($extractor->extract($className), $flags));

        return Command::SUCCESS;
    }
}
