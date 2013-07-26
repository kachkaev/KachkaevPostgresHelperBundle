<?php

namespace Kachkaev\PostgresHelperBundle\Command\Datasets\Components\Records;

use Kachkaev\PostgresHelperBundle\Model\Dataset\AbstractComponentRecordPopulator;

use Symfony\Component\Console\Input\InputOption;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Kachkaev\PostgresHelperBundle\Command\AbstractParameterAwareCommand;

class PopulateCommand extends AbstractParameterAwareCommand
{
    
    protected function configure()
    {
        $this
            ->setName('ph:datasets:components:records:populate')
            ->setDescription('Populates the component with records using a corresponding service')
            ->makeDatasetAware()
            ->addArgument('component-name', InputArgument::REQUIRED, 'Name of the component')
            ->addOption('thread-count', 't', InputOption::VALUE_REQUIRED, 'Number of threads to run (only applicable to some populators)', 0)
            ->addOption('gui', 'g', InputOption::VALUE_NONE, 'Turn on gui support (only applicable to some populators)')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->processInput($input, $output, $extractedArguments);
        
        $datasetManager = $this->getDatasetManager($extractedArguments['dataset-schema']);
        $dataset = $datasetManager->get($extractedArguments['dataset-name']);
        $componentRecordManager = $dataset->getComponentRecordManager();
        
        $populator = $componentRecordManager->populate($input->getArgument('component-name'), [
                'thread-count' => $input->getOption('thread-count'),
                'gui' => $input->getOption('gui')
            ], $output);
    }
}