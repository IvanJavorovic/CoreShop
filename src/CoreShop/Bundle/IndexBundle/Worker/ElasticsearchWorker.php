<?php
/**
 * CoreShop.
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.org)
 * @license    https://www.coreshop.org/license     GNU General Public License version 3 (GPLv3)
 */

declare(strict_types=1);

namespace CoreShop\Bundle\IndexBundle\Worker;

use CoreShop\Component\Index\Extension\IndexColumnsExtensionInterface;
use CoreShop\Component\Index\Extension\IndexColumnTypeConfigExtension;
use CoreShop\Component\Index\Extension\IndexRelationalColumnsExtensionInterface;
use CoreShop\Component\Index\Extension\IndexSystemColumnTypeConfigExtension;
use CoreShop\Component\Index\Interpreter\LocalizedInterpreterInterface;
use CoreShop\Component\Index\Listing\ListingInterface;
use CoreShop\Component\Index\Model\IndexableInterface;
use CoreShop\Component\Index\Model\IndexColumnInterface;
use CoreShop\Component\Index\Model\IndexInterface;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Pimcore\Tool;

class ElasticsearchWorker extends AbstractWorker
{
    private static array $connectionCache = [];

    public function getElasticsearchClient(IndexInterface $index): Client
    {
        $config = $index->getConfiguration();

        if (empty($config['hosts'])) {
            throw new \Exception('No hosts defined for Elasticsearch');
        }

        if (isset(self::$connectionCache[$config['hosts']])) {
            return self::$connectionCache[$config['hosts']];
        }

        $builder = ClientBuilder::create();
        $builder->setHosts(explode(',', $config['hosts']));

        if (!empty($config['username']) && !empty($config['password'])) {
            $builder->setBasicAuthentication($config['username'], $config['password']);
        }

        self::$connectionCache[$config['hosts']] = $builder->build();

        return self::$connectionCache[$config['hosts']];
    }
    public function createOrUpdateIndexStructures(IndexInterface $index): void
    {
        $tableName = $this->getTablename($index->getName());
        $localizedTableName = $this->getLocalizedTablename($index->getName());
        $relationalTableName = $this->getRelationTablename($index->getName());

        $this->createTableSchema($index, $tableName);
        $this->createLocalizedTableSchema($index, $localizedTableName);
        $this->createRelationalTableSchema($index, $relationalTableName);
        $this->createLocalizedViews($index);
    }

    protected function createTableSchema(IndexInterface $index, string $tableName)
    {
        $this->truncateOrCreateTable($index, $tableName);

        $properties = $this->getTableSchemaProperties($index);

        $this->mapTableProperties($index, $tableName, $properties);
    }

    protected function getTableSchemaProperties(IndexInterface $index): array
    {
        $properties = [];

        foreach ($index->getColumns() as $column) {
            if ($column instanceof IndexColumnInterface) {
                $type = $column->getObjectType();
                $interpreterClass = $column->hasInterpreter() ? $this->getInterpreterObject($column) : null;
                if ($type !== 'localizedfields' && !$interpreterClass instanceof LocalizedInterpreterInterface) {
                    if (in_array($column->getDataType(), ['manyToOneRelation', 'manyToManyObjectRelation']) &&
                        in_array($column->getColumnType(), ['STRING', 'TEXT'])) {
                        $properties[$column->getName()] = [
                            'type' => 'keyword'
                        ];

                        continue;
                    }

                    $properties[$column->getName()] = [
                        'type' => $this->renderFieldType($column->getColumnType())
                    ];
                }
            }
        }

        foreach ($this->getExtensions($index) as $extension) {
            if ($extension instanceof IndexColumnsExtensionInterface) {
                foreach ($extension->getSystemColumns() as $name => $type) {
                    if (in_array($name, ['categoryIds', 'stores', 'parentCategoryIds'])) {
                        $properties[$name] = [
                            'type' => 'keyword'
                        ];

                        continue;
                    }

                    $properties[$name] = [
                        'type' => $this->renderFieldType($type)
                    ];
                }
            }
        }

        foreach ($this->getSystemAttributes() as $column => $type) {
            $properties[$column] = [
                'type' => $this->renderFieldType($type)
            ];
        }

        return $properties;
    }

    protected function createLocalizedTableSchema(IndexInterface $index, string $tableName)
    {
        $this->truncateOrCreateTable($index, $tableName);

        $properties = $this->getLocalizedTableProperties($index);

        $this->mapTableProperties($index, $tableName, $properties);
    }

    protected function getLocalizedTableProperties(IndexInterface $index): array
    {
        $properties = [];

        foreach ($index->getColumns() as $column) {
            if ($column instanceof IndexColumnInterface) {
                $type = $column->getObjectType();
                $interpreterClass = $column->hasInterpreter() ? $this->getInterpreterObject($column) : null;
                if ($type === 'localizedfields' || $interpreterClass instanceof LocalizedInterpreterInterface) {
                    $properties[$column->getName()] = [
                        'type' => $this->renderFieldType($column->getColumnType())
                    ];
                }
            }
        }

        foreach ($this->getExtensions($index) as $extension) {
            if ($extension instanceof IndexColumnsExtensionInterface) {
                foreach ($extension->getLocalizedSystemColumns() as $name => $type) {
                    $config = ['notnull' => false];

                    //TODO check what this is
                    if ($extension instanceof IndexSystemColumnTypeConfigExtension) {
                        $config = array_merge($config, $extension->getSystemColumnConfig($name, $type));
                    }

                    $properties[$name] = [
                        'type' => $this->renderFieldType($type)
                    ];
                }
            }
        }

        foreach ($this->getLocalizedSystemAttributes() as $column => $type) {
            $properties[$column] = [
                'type' => $this->renderFieldType($type)
            ];
        }

        return $properties;
    }

    protected function createRelationalTableSchema(IndexInterface $index, string $tableName)
    {
        $this->truncateOrCreateTable($index, $tableName);

        $properties = [];

        foreach ($this->getExtensions($index) as $extension) {
            if ($extension instanceof IndexRelationalColumnsExtensionInterface) {
                foreach ($extension->getRelationalColumns() as $name => $type) {
                    $config = ['notnull' => false];

                    //TODO see what config is
                    if ($extension instanceof IndexSystemColumnTypeConfigExtension) {
                        $config = array_merge($config, $extension->getSystemColumnConfig($name, $type));
                    }

                    $properties[$name] = [
                        'type' => $this->renderFieldType($type)
                    ];
                }
            }
        }

        foreach ($this->getRelationalSystemAttributes() as $column => $type) {
            $properties[$column] = [
                'type' => $this->renderFieldType($type)
            ];
        }

        $this->mapTableProperties($index, $tableName, $properties);
    }

    protected function createLocalizedViews(IndexInterface $index)
    {
        $languages = Tool::getValidLanguages(); //TODO: Use Locale Service

        foreach ($languages as $language) {
            $localizedViewName = $this->getLocalizedViewName($index->getName(), $language);
            $this->truncateOrCreateTable($index, $localizedViewName);

            $properties = $this->getTableSchemaProperties($index);
            $properties = array_merge($properties, $this->getLocalizedTableProperties($index));

            $this->mapTableProperties($index, $localizedViewName, $properties);
        }
    }

    protected function truncateOrCreateTable(IndexInterface $index, string $tableName)
    {
        $params = ['index' => $tableName];

        try {
            $this->getElasticsearchClient($index)->indices()->delete($params);
        } catch (\Exception $e) {
            $this->logger->info((string)$e);
        }

        try {
            $result = $this->getElasticsearchClient($index)->indices()->exists($params)->asBool();
        } catch (\Exception $e) {
            $result = false;
            $this->logger->info((string)$e);
        }

        if (!$result) {
            $result = $this->getElasticsearchClient($index)->indices()->create(
                [
                    'index' => $tableName,
                    'body' => [
                        'settings' => [
                            "number_of_shards" => 5,
                            "number_of_replicas" => 0
                        ]
                    ]
                ]
            );

            $this->logger->info('Creating new Index. Name: ' . $tableName);

            if (!$result['acknowledged']) {
                throw new \Exception("Index creation failed. IndexName: " . $tableName);
            }
        } else {
            try {
                $this->getElasticsearchClient($index)->indices()->delete($params);
            } catch (\Exception $e) {
                $this->logger->info((string)$e);
            }
        }
    }

    protected function mapTableProperties(IndexInterface $index, string $tableName, array $properties)
    {
        $params = [
            'index' => $tableName,
            'type' => "coreshop",
            'include_type_name' => true,
            'body'  => [
                'properties' => $properties
            ]
        ];

        try {
            $this->getElasticsearchClient($index)->indices()->putMapping($params);
        } catch (\Exception $e) {
            $this->logger->info((string)$e);
        }
    }

    protected function typeCastValues(IndexColumnInterface $column, $value)
    {
        return $value;
    }

    protected function handleArrayValues(IndexInterface $index, array $value)
    {
        return ',' . implode(',', $value) . ',';
    }

    public function deleteIndexStructures(IndexInterface $index)
    {
        try {
            $languages = Tool::getValidLanguages();

            foreach ($languages as $language) {
                $this->getElasticsearchClient($index)->indices()->delete([
                    'index' =>  $this->getLocalizedViewName($index->getName(), $language)
                ]);
            }

            $this->getElasticsearchClient($index)->indices()->delete([
                'index' => $this->getTablename($index->getName())
            ]);
            $this->getElasticsearchClient($index)->indices()->delete([
                'index' => $this->getRelationTablename($index->getName())
            ]);
            $this->getElasticsearchClient($index)->indices()->delete([
                'index' => $this->getLocalizedTablename($index->getName())
            ]);
        } catch (\Exception $e) {
            $this->logger->info((string)$e);
        }
    }

    public function renameIndexStructures(IndexInterface $index, string $oldName, string $newName): void
    {
        try {
            $languages = Tool::getValidLanguages();
            $potentialTables = [
                $this->getTablename($oldName) => $this->getTablename($newName),
                $this->getLocalizedTablename($oldName) => $this->getLocalizedTablename($newName),
                $this->getRelationTablename($oldName) => $this->getRelationTablename($newName),
            ];

            foreach ($languages as $language) {
                $potentialTables[$this->getLocalizedViewName($oldName, $language)] = $this->getLocalizedViewName($newName, $language);
            }

            foreach ($potentialTables as $oldTable => $newTable) {
                try {
                    $result = $this->getElasticsearchClient($index)->indices()->exists(['index' => $oldTable])->asBool();
                } catch (\Exception $e) {
                    $result = false;
                    $this->logger->info((string)$e);
                }

                if ($result) {
                    $params['body'] = [
                        'source' => [
                            'index' => $oldTable
                        ],
                        'dest' => [
                            'index' => $newTable
                        ]
                    ];

                    $this->getElasticsearchClient($index)->reindex($params);
                    $this->getElasticsearchClient($index)->indices()->delete([
                        'index' => $oldTable
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->info((string)$e);
        }
    }

    public function deleteFromIndex(IndexInterface $index, IndexableInterface $object): void
    {
        $params = [
            'index' => $this->getTablename($index->getName()),
            'type' => 'coreshop',
            'id' => $object->getId()
        ];

        try {
            $this->getElasticsearchClient($index)->delete($params);
        } catch (\Exception $e) {
            $this->logger->info((string)$e);
        }
    }

    public function deleteFromRelationalIndex(IndexInterface $index, IndexableInterface $object): void
    {
        $params = [
            'index' => $this->getRelationTablename($index->getName()),
            'type' => 'coreshop',
            'id' => $object->getId()
        ];

        try {
            $this->getElasticsearchClient($index)->delete($params);
        } catch (\Exception $e) {
            $this->logger->info((string)$e);
        }
    }

    public function updateIndex(IndexInterface $index, IndexableInterface $object): void
    {
        $doIndex = $object->getIndexable($index);

        if ($doIndex) {
            $preparedData = $this->prepareData($index, $object);

            $this->doInsertData($index, $this->getTablename($index->getName()), $preparedData['data'], (string)$object->getId());

            $this->doInsertLocalizedData($index, $preparedData['localizedData'], $object);

            $this->doInsertLocalizedViewData($index, $preparedData['data'], $preparedData['localizedData'], $object);

            $this->deleteFromRelationalIndex($index, $object);

            if (!empty($preparedData['relation'])) {
                foreach ($preparedData['relation'] as $relationRow) {
                    $objectId = $relationRow['src'].'_'.$relationRow['dest'].'_'.$relationRow['fieldname'];

                    $this->doInsertData($index, $this->getRelationTablename($index->getName()), $relationRow, $objectId);
                }
            }

        } else {
            $this->logger->info('Don\'t adding object ' . $object->getId() . ' to index.');

            $this->deleteFromIndex($index, $object);
        }
    }

    protected function doInsertData(IndexInterface $index, string $tableName, array $data, string $objectId): void
    {
        $params = [
            'index' => $tableName,
            'type' => 'coreshop',
            'id' => $objectId,
            'body' => $data
        ];

        try {
            $this->getElasticsearchClient($index)->index($params);
        } catch (\Exception $e) {
            $this->logger->info('Error during INDEX INSERT: '.$e);
        }
    }

    protected function doInsertLocalizedData(IndexInterface $index, array $data, IndexableInterface $object): void
    {
        $columns = $index->getColumns()->toArray();
        $columnNames = array_map(function (IndexColumnInterface $column) { return $column->getName(); }, $columns);

        foreach ($data['values'] as $language => $values) {
            $params = [];
            $params['oo_id'] = $data['oo_id'];
            $params['language'] = $language;

            foreach ($values as $key => $value) {
                if (in_array($key, $columnNames)) {
                    continue;
                }

                $params[$key] = $value;
            }

            foreach ($index->getColumns() as $column) {
                if (!array_key_exists($column->getName(), $values)) {
                    continue;
                }

                $params[$column->getName()] = $values[$column->getName()];
            }

            $this->doInsertData($index, $this->getLocalizedTablename($index->getName()), $params, (string)$object->getId());
        }
    }

    protected function doInsertLocalizedViewData(IndexInterface $index, array $data, array $localizedData, IndexableInterface $object): void
    {
        $columns = $index->getColumns()->toArray();
        $columnNames = array_map(function (IndexColumnInterface $column) { return $column->getName(); }, $columns);

        foreach ($localizedData['values'] as $language => $values) {
            $params = $data;
            $params['oo_id'] = $localizedData['oo_id'];
            $params['language'] = $language;

            foreach ($values as $key => $value) {
                if (in_array($key, $columnNames)) {
                    continue;
                }

                $params[$key] = $value;
            }

            foreach ($index->getColumns() as $column) {
                if (!array_key_exists($column->getName(), $values)) {
                    continue;
                }

                $params[$column->getName()] = $values[$column->getName()];
            }

            $this->doInsertData($index, $this->getLocalizedViewName($index->getName(), $language), $params, (string)$object->getId());
        }
    }

    public function renderFieldType(string $type)
    {
        switch ($type) {
            case IndexColumnInterface::FIELD_TYPE_INTEGER:
                return "integer";

            case IndexColumnInterface::FIELD_TYPE_BOOLEAN:
                return "boolean";

            case IndexColumnInterface::FIELD_TYPE_DATE:
                return "date";

            case IndexColumnInterface::FIELD_TYPE_DOUBLE:
                return "double";

            case IndexColumnInterface::FIELD_TYPE_STRING:
                return "keyword";

            case IndexColumnInterface::FIELD_TYPE_TEXT:
                return "keyword";
        }

        throw new \Exception($type . " is not supported by Elasticsearch Index");
    }

    public function getFieldTypeConfig(IndexColumnInterface $column)
    {
        $config = ['notnull' => false];

        foreach ($this->getExtensions($column->getIndex()) as $extension) {
            if ($extension instanceof IndexColumnTypeConfigExtension) {
                $config = array_merge($config, $extension->getColumnConfig($column));
            }
        }

        return $config;
    }

    public function getSystemFieldTypeConfig(IndexInterface $index, string $name, string $type)
    {
        $config = ['notnull' => false];

        foreach ($this->getExtensions($index) as $extension) {
            if ($extension instanceof IndexSystemColumnTypeConfigExtension) {
                $config = array_merge($config, $extension->getSystemColumnConfig($name, $type));
            }
        }

        return $config;
    }

    public function getList(IndexInterface $index): ListingInterface
    {
        return new \CoreShop\Bundle\IndexBundle\Worker\ElasticsearchWorker\Listing($index, $this);
    }

    public function getTablename(string $name): string
    {
        return 'coreshop_index_elasticsearch_' . strtolower($name);
    }

    public function getLocalizedTablename(string $name): string
    {
        return 'coreshop_index_elasticsearch_localized_' . strtolower($name);
    }

    public function getLocalizedViewName(string $name, string $language): string
    {
        return $this->getLocalizedTablename($name) . '_' . $language;
    }

    public function getRelationTablename(string $name): string
    {
        return 'coreshop_index_elasticsearch_relations_' . strtolower($name);
    }

    protected function getRelationalSystemAttributes(): array
    {
        return [
            'src' => IndexColumnInterface::FIELD_TYPE_INTEGER,
            'src_virtualObjectId' => IndexColumnInterface::FIELD_TYPE_INTEGER,
            'dest' => IndexColumnInterface::FIELD_TYPE_INTEGER,
            'fieldname' => IndexColumnInterface::FIELD_TYPE_STRING,
            'type' => IndexColumnInterface::FIELD_TYPE_STRING,
        ];
    }
}