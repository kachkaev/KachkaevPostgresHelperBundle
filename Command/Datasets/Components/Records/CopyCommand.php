<?php

namespace Kachkaev\PostgresHelperBundle\Command\Datasets\Components\Records;

use Symfony\Component\Console\Input\InputOption;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Kachkaev\PostgresHelperBundle\Command\AbstractParameterAwareCommand;

class CopyCommand extends AbstractParameterAwareCommand
{
    
    protected function configure()
    {
        $this
            ->setName('ph:datasets:components:records:copy')
            ->setDescription('Copies records into the component from another dataset')
            ->makeDatasetAware()
            ->makeForceAware()
            ->addArgument('component-name', InputArgument::REQUIRED, 'Name of the component')
            ->addArgument('origin-dataset-name', InputArgument::REQUIRED, 'Name of the dataset within the same schema to copy data from')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Filter (WHERE statement) to select what records to copy')
            ->addOption('existing-only', null, InputOption::VALUE_NONE, 'Only update attribute values of the records that already exist in the destination dataset component')
            ->addOption('ignore-attribute-mismatch', null, InputOption::VALUE_NONE, 'Does not throw an error when there are mismatches in attributes (columns) between the datasets')
            ->addOption('attribute-mappings', null, InputOption::VALUE_REQUIRED, 'Comma-separated array of attribute (column) names that need to be renamed / casted, e.g. "myfield->myfield_with_new_name,myotherfield::int->myotherfield_of_new_type"')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->processInput($input, $output, $extractedArguments);
        
        $datasetManager = $this->getDatasetManager($extractedArguments['dataset-schema']);
        $destinationDataset = $datasetManager->get($extractedArguments['dataset-name']);
        $sourceDataset = $datasetManager->get($input->getArgument('origin-dataset-name'));
        $componentName = $input->getArgument('component-name');
        $filter = $input->getOption('filter');
        $attributeMappingsAsStr = $input->getOption('attribute-mappings');

        $attributeMappings = [];
        if ($attributeMappingsAsStr) {
            $attributeMappingsAsRawArray = explode(',', $attributeMappingsAsStr);
            foreach($attributeMappingsAsRawArray as $am) {
                if (!preg_match('/^\s*([a-z_0-9\:\s]+)\s*->\s*([a-z_0-9]+)\s*$/i', $am, $matches)) {
                    throw new \InvalidArgumentException(sprintf('Could not parse attribute-mappings option, please check its format'));
                }
                $attributeMappings[$matches[1]] = $matches[2];
            }
        }

        $totalIdCount = $sourceDataset->getComponentRecordManager()->count($componentName, $filter);
        $intersectingIdCount = $destinationDataset->getComponentRecordManager()->countIntersectingIds($componentName, $sourceDataset, $filter);
        $addingIdCount = $input->getOption('existing-only') ? 0 : $totalIdCount - $intersectingIdCount;

        if ($this->forceNotUsed($input, $output, sprintf('%s recrods will be replaced and %s added.', number_format($intersectingIdCount), number_format($addingIdCount)))) {
            return;
        }

        // Do the action
        $output->write(sprintf('Replacing %s records and adding %s...', number_format($intersectingIdCount), number_format($addingIdCount)));
        $destinationDataset->getComponentRecordManager()->copy($componentName, $sourceDataset, $filter, $input->getOption('existing-only'), $input->getOption('ignore-attribute-mismatch'), $attributeMappings, $output);
        $output->writeln('Done.');
    }
}