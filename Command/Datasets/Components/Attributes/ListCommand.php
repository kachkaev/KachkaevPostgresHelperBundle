<?php

namespace Kachkaev\DAFBundle\Command\Datasets\Components\Attributes;

use Symfony\Component\Console\Input\InputArgument;

use Kachkaev\DAFBundle\Command\AbstractParameterAwareCommand;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class ListCommand extends AbstractParameterAwareCommand
{
    
    protected function configure()
    {
        $this
            ->setName('daf:datasets:components:attributes:list')
            ->makeDatasetAware()
            ->addArgument('component-name', InputArgument::REQUIRED, 'Name of the component')
            ->setDescription('Lists attributes components in the dataset component')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $extractedArguments = $this->processInput($input, $output);

        $datasetManager = $this->getDatasetManager($extractedArguments['domain-name']);
        $dataset = $datasetManager->get($extractedArguments['dataset-name']);
        $componentName = $input->getArgument('component-name');
        $componentAttributeManager = $dataset->getComponentAttributeManager();
        
        $outputFormatter = $this->getContainer()->get('pr.helper.output_formatter');
        
        $list = $componentAttributeManager->listAttributeNamesAndTypes($componentName);
        if (sizeof($list)) {
            $output->writeln(sprintf('<comment>List of attributes in component <info>%s</info> of dataset <info>%s</info>:</comment>', $componentName, $dataset->getFullName()));
            $outputFormatter->outputArrayAsAlignedList($output, $list);
        } else {
            $output->writeln(sprintf('Dataset <info>%s</info> has no components.', $dataset->getFullName()));
        }
    }
}