<?php
namespace Kachkaev\DAFBundle\Model\Dataset;

use Kachkaev\DAFBundle\Model\Domain\DomainManager;

use JMS\DiExtraBundle\Annotation as DI;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Templating\EngineInterface;

use Kachkaev\DAFBundle\Model\Validator\ValidatorInterface;
use Kachkaev\DAFBundle\Model\Validator\DatasetNameValidator;
use Kachkaev\DAFBundle\Model\ManagerInterface;
use Kachkaev\DAFBundle\Model\TemplateManaging\SQLTemplateManager;

/**
 * Manages datasets
 *
 * @author  "Alexander Kachkaev <alexander@kachkaev.ru>"
 */

abstract class DatasetManager implements ManagerInterface
{
    /**
     *  @var ContainerInterface */
    protected $container;

    /**
     *  @var SQLTemplateManager */
    protected $sqlTemplateManager;

    /**
     * @var DomainManager
     */
    protected $domainManager;

    /**
     * @var DatasetNameValidator
     */
    protected $datasetNameValidator;

    protected $componentAttributeUpdaters;

    protected $domainName = 'public';

    protected $class;

    // List of objects handled by the manager as name=>object
    protected $list = [];

    /**
     * @DI\InjectParams({
     *     "container" = @DI\Inject("service_container"),
     * })
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->sqlTemplateManager = $container->get('daf.sql_template_manager');
        $this->domainManager = $container->get('daf.domain_manager');
        $this->datasetNameValidator = $this->getValidator('dataset_name');
        $this->updateList();
    }

    /**
     * Updates the list of known datasets
     */
    public function updateList()
    {
        $listOfExistingNames = $this->sqlTemplateManager->runAndFetchAllAsList('daf#datasets/list', [
                'domainName'=>$this->domainName
                ]);

        $newList = [];
        $oldList = $this->list;

        // Moving existing references to objects into a new list
        foreach ($listOfExistingNames as $name) {
            $newList[$name] = null;
            if (array_key_exists($name, $oldList)) {
                if ($oldList[$name] !== null) {
                    $newList[$name] = $oldList[$name];
                }
                unset ($oldList[$name]);
            }
        };

        $this->list = $newList;
    }

    /**
     * Returns the name of an element by its reference in the dataset
     * @return string
     */
    public function getName(Dataset $dataset)
    {
        return array_search($dataset, $this->list, true);
    }

    /**
     * Returns managed domain name
     * @return string
     */
    public function getDomainName()
    {
        return $this->domainName;
    }

    /**
     * Returns names of datasets
     * @return array
     */
    public function listNames()
    {
        return array_keys($this->list);
    }

    /**
     * Checks if given dataset exists
     * @return boolean
     */
    public function has($datasetName)
    {
        $this->datasetNameValidator->assertValid($datasetName);
        return array_key_exists($datasetName, $this->list);
    }

    /**
     * Does nothing if given dataset exists, throws an exception otherwise
     * @throws \LogicException if given dataset does not exist
     * @throws \InvalidArgumentException if the name of given dataset is invalid
     */
    public function assertHaving($datasetName, $errorMessage)
    {
        if (!$this->has($datasetName)) {
            throw new \LogicException($errorMessage);
        }
    }

    /**
     * Does nothing if given dataset does not exist, throws an exception otherwise
     * @throws \LogicException if given dataset exist
     * @throws \InvalidArgumentException if the name of given dataset is invalid
     */
    public function assertNotHaving($datasetName, $errorMessage)
    {
        if ($this->has($datasetName)) {
            throw new \LogicException($errorMessage);
        }
    }

    /**
     * Initialises given dataset
     *
     * @return Dataset
     */
    public function init($datasetName)
    {
        $this->assertNotHaving($datasetName, sprintf('Cannot initialise dataset %s.%s as it already exists in the database', $this->domainName, $datasetName));

        // Creating meta table
        $this->sqlTemplateManager->run('daf#datasets/init', [
                'domainName'=>$this->domainName,
                'datasetName'=>$datasetName,
                ]);

        $dataset = new $this->class($this);
        $this->list[$datasetName] = $dataset;
        $dataset->updateName();

        $this->updateFunctions();

        return $dataset;
    }

    /**
     * Renames given dataset
     */
    public function rename($datasetName, $newDatasetName)
    {
        $datasetToRename = $this->get($datasetName);

        $this->assertNotHaving($newDatasetName, sprintf('Unable to rename dataset %s.%s to %s.%s as such dataset already exists', $this->domainName, $datasetName, $this->domainName, $newDatasetName));

        $this->sqlTemplateManager->run('daf#datasets/rename', [
                'domainName'=>$this->domainName,
                'datasetName'=>$datasetName,
                'newDatasetName'=>$newDatasetName,
                ]);
        $this->list[$newDatasetName] = $this->list[$datasetName];
        unset ($this->list[$datasetName]);
        if ($this->list[$newDatasetName]) {
            $this->list[$newDatasetName]->updateName();
        }

        $this->updateFunctions();
    }

    /**
     * Duplicates given dataset
     */
    public function duplicate($datasetName, $newDatasetName)
    {
        $datasetToRename = $this->get($datasetName);

        if ($this->has($newDatasetName)) {
            throw new \InvalidArgumentException(sprintf('Unable to duplicate dataset %s.%s to %s.%s as such dataset already exists', $this->domainName, $datasetName, $this->domainName, $newDatasetName));
        }

        $this->sqlTemplateManager->run('daf#datasets/duplicate', [
                'domainName'=>$this->domainName,
                'datasetName'=>$datasetName,
                'duplicateDatasetName'=>$newDatasetName,
                ]);
        $this->list[$newDatasetName] = null;

        $this->updateFunctions();
    }

    /**
     * Saves contents of dataset tables into a dump file
     * @return string name of the file that was created
     */
    public function backup($datasetName, $backupDirectory)
    {
        $this->assertHaving($datasetName, sprintf('Unable to backup dataset %s.%s as such dataset does not exist', $this->domainName, $datasetName));

        $connectionParams = $this->getDatabaseConnectionParams();

        // Verify backup directory
        $backupDirectory = rtrim($backupDirectory, '/');
        if (!is_dir($backupDirectory)) {
            throw new \InvalidArgumentException(sprintf('Backup directory %s does not exist, please create it before doing backup', var_export($backupDirectory, true)));
        }
        if (!is_writable($backupDirectory)) {
            throw new \InvalidArgumentException(sprintf('Backup directory %s is not writable, please fix it before doing backup', var_export($backupDirectory, true)));
        }

        $outputFilename = sprintf('%s.%s-%s.pgdump', $this->domainName, $datasetName, date('Ymd-Hi'));
        $outputFilepath = sprintf('%s/%s', $backupDirectory, $outputFilename);

        // Construct the command
        $command = sprintf('export PGUSER="%s" && export PGPASSWORD="%s" && pg_dump --host=localhost %s --table="%s.%s__*" --format=custom --file="%s" 2>&1 && unset PGPASSWORD && unset PGUSER',
                    $connectionParams['user'],
                    $connectionParams['password'],
                    $connectionParams['dbname'],
                    $this->domainName,
                    $datasetName,
                    $outputFilepath
                );

        // Execute the command
        exec($command, $commandOutputArray, $commandResult);
        $commandOutput = implode(PHP_EOL, $commandOutputArray);

        // Handle failure
        if ($commandResult) {
            throw new \RuntimeException($commandOutput);
        }

        return realpath($outputFilepath);
    }

    /**
     * Restores dataset tables from a dump file
     */
    public function restore($backupFilename, $options = null, OutputInterface $output = null)
    {
        $connectionParams = $this->getDatabaseConnectionParams();

        $oldListOfDatasets = $this->listNames();

        // Check backup file
        if (!file_exists($backupFilename)) {
            throw new \InvalidArgumentException(sprintf('Restoring file %s does not exist.', var_export($backupFilename, true)));
        }

        // Construct the command
        $command = sprintf('export PGUSER="%s" && export PGPASSWORD="%s" && pg_restore --exit-on-error --host=localhost --dbname=%s --format=custom --schema=%s "%s" 2>&1 && unset PGPASSWORD && unset PGUSER',
                    $connectionParams['user'],
                    $connectionParams['password'],
                    $connectionParams['dbname'],
                    $this->domainName,
                    $backupFilename
                );

        // Execute the command
        exec($command, $commandOutputArray, $commandResult);
        $commandOutput = implode(PHP_EOL, $commandOutputArray);

        // Handle failure
        if ($commandResult) {
            if (preg_match('/relation "(.+)__(.+)" already exists/', $commandOutput, $matches)) {
                throw new \LogicException(sprintf('Dataset %s.%s already exists in the database. Rename or delete it before restoring data from backup.', $this->domainName, $matches[1]));
            } else {
                throw new \RuntimeException($commandOutput);
            }
        }

        $this->updateList();
        $this->updateFunctions();

        $datasetName = array_values(array_diff($this->listNames(), $oldListOfDatasets))[0];

        return $datasetName;
    }

    /**
     * Returns dataset object by name
     * @return Dataset
     */
    public function get($datasetName)
    {
        $this->assertHaving($datasetName, sprintf('Cannot get dataset %s.%s as it does not exist in the database', $this->domainName, $datasetName));

        if (!$this->list[$datasetName]) {
            $dataset = new $this->class($this);
            $this->list[$datasetName] = $dataset;
            $dataset->updateName();
        }

        return $this->list[$datasetName];
    }

    /**
     * Deletes given dataset by name
     */
    public function delete($datasetName)
    {
        $this->assertHaving($datasetName, sprintf('Cannot delete dataset %s.%s as it does not exist in the database', $this->domainName, $datasetName));

        $dataset = $this->get($datasetName);

        // Deleting all tables starting with name__
        $this->sqlTemplateManager->run('daf#datasets/delete', [
                'domainName'=>$this->domainName,
                'datasetName'=>$datasetName,
            ]);

        $dataset->updateName();

        unset ($this->list[$datasetName]);
    }

    /**
     * Returns validator
     * @return ValidatorInterface
     */
    public function getValidator($validatorName)
    {
        foreach ([$this->domainName, 'daf'] as $domain) {
            $serviceName = 'daf.validator.'.$validatorName;
            if ($this->container->has($serviceName))
                return $this->container->get($serviceName);
        }
        throw new \InvalidArgumentException(sprintf('Validator %s does not exist', $validatorName));
    }

    /**
     * Returns sql template manager
     * @return SQLTemplateManager
     */
    public function getSQLTemplatManager()
    {
        return $this->sqlTemplateManager;
    }

    /**
     * Returns service container
     * @return ContainerInterface
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Extracts the name of the database (needed to backup and restore)
     */
    protected function getDatabaseConnectionParams()
    {
        return $this->container->get('doctrine.dbal.main_connection')->getParams();
    }

    public function updateFunctions()
    {
        $this->updateList();
        $this->domainManager->updateFunctions($this->domainName);
    }

    /**
     * Returns all registered component attribute updaters
     * (services tagged with daf.component_attribute_updater)
     */
    public function getComponentAttributeUpdaters()
    {
        return $this->container->get('daf.component_attribute_updaters')->getAll();
    }

}
