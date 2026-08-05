<?php declare(strict_types=1);

namespace TestDataGenerator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\Context;
use TestDataGenerator\Service\DataImporter;

#[AsCommand(
    name: 'test-data:generate',
    description: 'Generate test data'
)]
class GenerateTestDataCommand extends Command
{
    private DataImporter $dataImporter;

    public function __construct(DataImporter $dataImporter)
    {
        parent::__construct();
        $this->dataImporter = $dataImporter;
    }

    protected function configure(): void
    {
        $this->addOption('reviews', 'r', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Generate product reviews');
        $this->addOption('manufacturers', 'm', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Generate manufacturers / brands');
        $this->addOption('manufacturers-count', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Number of manufacturers to generate', 5);
        $this->addOption('manufacturers-branch', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Branch / industry for generated manufacturers');
        $this->addOption('categories-count', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Number of categories to generate', 1);
        $this->addOption('products-count', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Number of products to generate', 2);
        $this->addOption('images', 'i', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Generate product cover images and brand logos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting test data generation...');
        $context = Context::createDefaultContext();
        
        $generateReviews = (bool) $input->getOption('reviews');
        $generateManufacturers = (bool) $input->getOption('manufacturers');
        $manufacturersCount = (int) $input->getOption('manufacturers-count');
        $manufacturersBranch = $input->getOption('manufacturers-branch') ? (string) $input->getOption('manufacturers-branch') : null;
        $categoriesCount = (int) $input->getOption('categories-count');
        $productsCount = (int) $input->getOption('products-count');
        $generateImages = (bool) $input->getOption('images');

        $this->dataImporter->importData(
            categoriesCount: $categoriesCount,
            productsCount: $productsCount,
            generateImages: $generateImages,
            useExistingCategories: false,
            createTranslationsOnly: false,
            context: $context,
            generateReviews: $generateReviews,
            generateManufacturers: $generateManufacturers,
            manufacturersCount: $manufacturersCount,
            manufacturersBranch: $manufacturersBranch
        );

        $output->writeln('Finished test data generation!');
        return Command::SUCCESS;
    }
}
