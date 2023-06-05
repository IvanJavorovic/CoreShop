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

namespace CoreShop\Bundle\ElasticsearchBundle\Worker\ElasticsearchWorker\Listing;

use CoreShop\Bundle\ElasticsearchBundle\Worker\ElasticsearchWorker;
use CoreShop\Component\Index\Listing\ListingInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use function PHPUnit\Framework\stringContains;

class Dao
{
    private int $lastRecordCount = 0;

    /**
     * @var array
     */
    protected $preparedGroupByValues = [];

    /**
     * @var array
     */
    protected $preparedGroupByValuesResults = [];

    public function __construct(private ElasticsearchWorker\Listing $model, private Connection $database)
    {
    }

    /**
     * @return QueryBuilder
     */
    public function createQueryBuilder()
    {
        return new QueryBuilder($this->database);
    }

    /**
     * Load objects.
     *
     *
     * @return array
     */
    public function load(QueryBuilder $queryBuilder)
    {
        $resultSet = $this->loadSet($queryBuilder);

        $results = [];

        if (empty($resultSet)) {
            $this->lastRecordCount = 0;
            return $results;
        }

        $this->lastRecordCount = $resultSet['hits']['total']['value'];

        foreach ($resultSet['hits']['hits'] as $hit) {
            $results[]['o_id'] = $hit['_id'];
        }

        return $results;
    }

    public function loadSet(QueryBuilder $queryBuilder, bool $withSource = false, bool $all = false): array
    {
        $esClient = $this->model->getWorker()->getElasticsearchClient();

        $queryBuilder->from($this->model->getQueryTableName(), 'q');
        if ($this->model->getVariantMode() == ListingInterface::VARIANT_MODE_INCLUDE_PARENT_OBJECT) {
            if (null !== $queryBuilder->getQueryPart('orderBy')) {
                $queryBuilder->select('q.o_virtualObjectId as o_id');
                $queryBuilder->addGroupBy('q.o_virtualObjectId');
            } else {
                $queryBuilder->select('q.o_virtualObjectId as o_id');
            }
        } else {
            $queryBuilder->select('q.o_id as o_id');
        }

        $queryBuilder->groupBy('o_id');

        $orderBys = $queryBuilder->getQueryPart('orderBy');

        foreach ($orderBys as $orderBy) {
            $orderBy = str_replace(' asc', '', $orderBy);
            $orderBy = str_replace(' desc', '', $orderBy);
            $groupBy = str_replace('`', '', $orderBy);
            $queryBuilder->addGroupBy($groupBy);
        }

        $params['body']['query'] = $this->formatQueryParams($queryBuilder->getSQL());

        try {
            $esQuery = $esClient->sql()->translate($params)->asArray();
        } catch (\Exception $exception) {
            return [];
        }

        $esQuery['_source'] = $withSource;
        $esQuery['size'] = ($withSource && $all) ? 10000 : ($this->model->getLimit() ?? 0);
        $esQuery['from'] = $this->model->getOffset() ?? 0;
        $esQuery['index'] = $this->model->getQueryTableName();
        $esQuery['type'] = "coreshop";

        if ($this->model->getSortByScore() === true) {
            $esQuery['body']['sort'][$this->model->getOrderKey()] = [
                'order' => $this->model->getOrder()
            ];

            $esQuery['body']['sort']['_score'] = [
                'order' => 'desc'
            ];

            $esQuery['size'] = 1;
            $esQuery['body']['query'] = $esQuery['query'];
            $esQuery['track_scores'] = true;
            unset($esQuery['query']);

            $maxScore = $esClient->search($esQuery)->asArray()['hits']['max_score'];
            $minScore = $maxScore * 0.9;

            $esQuery['size'] = ($withSource && $all) ? 10000 : ($this->model->getLimit() ?? 0);
            $esQuery['body']['min_score'] = $minScore;

            return $esClient->search($esQuery)->asArray();
        }

        $esQuery['body']['sort'][$this->model->getOrderKey()] = [
            'order' => $this->model->getOrder()
        ];

        $esQuery['body']['query'] = $esQuery['query'];
        unset($esQuery['query']);

        return $esClient->search($esQuery)->asArray();
    }

    /* Returns "phrase" suggestions based on entered search term, suggestion results are sorted by score */
    public function getSuggestions(string $searchTerm = '', array $fieldNames = [], int $numOfSuggestions = 1): array
    {
        $esClient = $this->model->getWorker()->getElasticsearchClient();

        $esQuery['_source'] = false;
        $esQuery['index'] = $this->model->getQueryTableName();
        $esQuery['type'] = "coreshop";
        $esQuery['body']['suggest']['text'] = $searchTerm;
        $words = explode(' ', $searchTerm);

        foreach ($fieldNames as $fieldName) {
            $esQuery['body']['suggest']["{$fieldName}_phrase_suggestions"] = [
                'phrase' => [
                    'field' => $fieldName,
                    'size' => $numOfSuggestions
                ]
            ];

            foreach ($words as $key => $word) {
                $esQuery['body']['suggest']["{$fieldName}_term_suggestions_{$key}"] = [
                    'text' => $word,
                    'term' => [
                        'field' => $fieldName,
                        'size' => $numOfSuggestions
                    ]
                ];
            }
        }

        $response = $esClient->search($esQuery)->asArray();

        $phraseSuggestions = [];

        foreach ($response['suggest'] as $suggestField => $suggest) {
            foreach ($suggest as $suggestion) {
                if (str_contains($suggestField, '_phrase_')) {
                    $phraseSuggestions = array_merge($phraseSuggestions, $suggestion['options']);
                }
            }
        }

        usort($phraseSuggestions, function ($item1, $item2) {
            return $item2['score'] <=> $item1['score'];
        });

        $phraseSuggestions = array_slice($phraseSuggestions, 0, $numOfSuggestions);

        if (!empty($phraseSuggestions)) {
            return $phraseSuggestions;
        }

        $termSuggestions = [];

        foreach ($response['suggest'] as $suggestField => $suggest) {
            foreach ($suggest as $suggestion) {
                if (str_contains($suggestField, '_term_')) {
                    if (empty($termSuggestions[$suggestion['text']])) {
                        $termSuggestions[$suggestion['text']] = [];
                    }

                    $termSuggestions[$suggestion['text']] = array_merge($termSuggestions[$suggestion['text']], $suggestion['options']);
                }
            }
        }

        foreach ($words as $key => $word) {
            $wordSuggestions = $termSuggestions[$word];

            if (empty($wordSuggestions)) {
                continue;
            }

            usort($wordSuggestions, function ($item1, $item2) {
                return $item2['score'] <=> $item1['score'];
            });

            if ($wordSuggestions[0]['score'] < .75) {
                continue;
            }

            $words[$key] = $wordSuggestions[0]['text'];
        }

        $termSuggestions = [
            [
                'text' => implode(' ', $words),
                'score' => 1
            ]
        ];

        return $termSuggestions;
    }

    /**
     * Load Group by values.
     *
     * @param string       $fieldName
     * @param bool         $countValues
     *
     * @return array
     */
    public function loadGroupByValues(QueryBuilder $queryBuilder, $fieldName, $countValues = false)
    {
        $queryBuilder->from($this->model->getQueryTableName(), 'q');
        $queryBuilder->groupBy('q.' . $this->quoteIdentifier($fieldName));
        $queryBuilder->orderBy('q.' . $this->quoteIdentifier($fieldName));

        $esClient = $this->model->getWorker()->getElasticsearchClient();

        if ($countValues) {
            if ($this->model->getVariantMode() == ListingInterface::VARIANT_MODE_INCLUDE_PARENT_OBJECT) {
                $queryBuilder->select($this->quoteIdentifier($fieldName) . ' AS value, count(DISTINCT o_virtualObjectId) AS count');
            } else {
                $queryBuilder->select($this->quoteIdentifier($fieldName) . ' AS value, count(*) AS count');
            }

            $params['body']['query'] = $this->formatQueryParams($queryBuilder->getSQL());

            if ($this->model->getSortByScore() === true) {
                try {
                    $esQuery = $esClient->sql()->translate($params)->asArray();
                } catch (\Exception $exception) {
                    return [];
                }

                $esQuery['_source'] = true;
                $esQuery['from'] = 0;
                $esQuery['index'] = $this->model->getQueryTableName();
                $esQuery['type'] = "coreshop";

                $esQuery['size'] = 1;
                $esQuery['body']['query'] = $esQuery['query'];
                $esQuery['track_scores'] = true;
                unset($esQuery['query']);

                $maxScore = $esClient->search($esQuery)->asArray()['hits']['max_score'];
                $minScore = $maxScore * 0.9;

                $esQuery['size'] = 10000;
                $esQuery['body']['min_score'] = $minScore;

                $mappedResults = $this->mapResults($esClient->sql()->query($params)->asArray());
                $groupedValues = $this->mapHitResults($esClient->search($esQuery)->asArray(), $fieldName);

                return array_values(array_filter($mappedResults, function ($item) use ($groupedValues) {
                    return in_array($item['value'], $groupedValues);
                }));
            }

            return $this->mapResults($esClient->sql()->query($params)->asArray());
        }

        $queryBuilder->select($this->quoteIdentifier($fieldName));
        $params['body']['query'] = $this->formatQueryParams($queryBuilder->getSQL());

        if ($this->model->getSortByScore() === true) {
            try {
                $esQuery = $esClient->sql()->translate($params)->asArray();
            } catch (\Exception $exception) {
                return [];
            }

            $esQuery['_source'] = true;
            $esQuery['from'] = 0;
            $esQuery['index'] = $this->model->getQueryTableName();
            $esQuery['type'] = "coreshop";

            $esQuery['size'] = 1;
            $esQuery['body']['query'] = $esQuery['query'];
            $esQuery['track_scores'] = true;
            unset($esQuery['query']);

            $maxScore = $esClient->search($esQuery)->asArray()['hits']['max_score'];
            $minScore = $maxScore * 0.9;

            $esQuery['size'] = 10000;
            $esQuery['body']['min_score'] = $minScore;

            return $this->mapHitResults($esClient->search($esQuery)->asArray(), $fieldName);
        }

        $mappedResults = $this->mapResults($esClient->sql()->query($params)->asArray());

        return array_map(function (array $mappedData) use ($fieldName) {
            return str_replace(',', '', (string)$mappedData[$fieldName]);
        }, $mappedResults);
    }

    /**
     * Load Grouo by Relation values.
     *
     * @param string       $fieldName
     * @param bool         $countValues
     *
     * @return array
     */
    public function loadGroupByRelationValues(QueryBuilder $queryBuilder, $fieldName, $countValues = false)
    {
        return $this->loadGroupByRelationValuesAndType($queryBuilder, $fieldName, null, $countValues);
    }

    /**
     * Load Grouo by Relation values and type.
     */
    public function loadGroupByRelationValuesAndType(QueryBuilder $queryBuilder, string $fieldName, ?string $type = null, bool $countValues = false): array
    {
        $queryBuilder->from($this->model->getRelationTablename(), 'q');
        $esClient = $this->model->getWorker()->getElasticsearchClient();

        if ($countValues) {
            $subQueryBuilder = new QueryBuilder($this->database);
            $subQueryBuilder->select($this->quoteIdentifier('o_id'));
            $subQueryBuilder->from($this->model->getQueryTableName(), 'q');
            $subQueryBuilder->where($queryBuilder->getQueryPart('where'));

            if ($this->model->getVariantMode() === ListingInterface::VARIANT_MODE_INCLUDE_PARENT_OBJECT) {
                $queryBuilder->select($this->quoteIdentifier('dest') . ' AS ' . $this->quoteIdentifier('value') . ', count(DISTINCT src_virtualObjectId) AS ' . $this->quoteIdentifier('count'));
                $queryBuilder->where('fieldname = ' . $this->quote($fieldName));

                if (null !== $type) {
                    $queryBuilder->where('type = ' . $this->quote($type));
                }
            } else {
                $queryBuilder->select($this->quoteIdentifier('dest') . ' AS ' . $this->quoteIdentifier('value') . ', count(*) AS ' . $this->quoteIdentifier('count'));
                $queryBuilder->where('fieldname = ' . $this->quote($fieldName));

                if (null !== $type) {
                    $queryBuilder->where('type = ' . $this->quote($type));
                }
            }

            $params['body']['query'] = $this->formatQueryParams($subQueryBuilder->getSQL());

            if ($this->model->getSortByScore() === true) {
                try {
                    $esQuery = $esClient->sql()->translate($params)->asArray();
                } catch (\Exception $exception) {
                    return [];
                }

                $esQuery['body']['sort'][$this->model->getOrderKey()] = [
                    'order' => $this->model->getOrder()
                ];

                $esQuery['_source'] = true;
                $esQuery['from'] = 0;
                $esQuery['index'] = $this->model->getQueryTableName();
                $esQuery['type'] = "coreshop";

                $esQuery['size'] = 1;
                $esQuery['body']['query'] = $esQuery['query'];
                $esQuery['track_scores'] = true;
                unset($esQuery['query']);
                unset($esQuery['sort']);

                $maxScore = $esClient->search($esQuery)->asArray()['hits']['max_score'];
                $minScore = $maxScore * 0.9;

                $esQuery['size'] = 10000;
                $esQuery['body']['min_score'] = $minScore;

                $srcIds = $this->mapHitResults($esClient->search($esQuery)->asArray(), 'o_id');
            } else {
                $srcs = $esClient->sql()->query($params)->asArray();

                $srcIds = array_map(function ($item) {
                    return $item[0];
                }, $srcs['rows']);
            }

            if (count($srcIds)) {
                $srcIds = implode(',', $srcIds);
                $queryBuilder->andWhere('src IN (' . $srcIds . ')');
            }

            $queryBuilder->groupBy('dest');

            $params['body']['query'] = $this->formatQueryParams($queryBuilder->getSQL());

            return $this->mapResults($esClient->sql()->query($params)->asArray());
        }

        $queryBuilder->select($this->quoteIdentifier('dest'));
        $queryBuilder->where('fieldname = ' . $this->quote($fieldName));

        if (null !== $type) {
            $queryBuilder->where('type = ' . $this->quote($type));
        }

        $subQueryBuilder = new QueryBuilder($this->database);
        $subQueryBuilder->select('src');
        $subQueryBuilder->from($this->model->getRelationTablename(), 'q');
        $subQueryBuilder->where($queryBuilder->getQueryPart('where'));

        $params['body']['query'] = $this->formatQueryParams($subQueryBuilder->getSQL());

        if ($this->model->getSortByScore() === true) {
            try {
                $esQuery = $esClient->sql()->translate($params)->asArray();
            } catch (\Exception $exception) {
                return [];
            }

            $esQuery['body']['sort'][$this->model->getOrderKey()] = [
                'order' => $this->model->getOrder()
            ];

            $esQuery['_source'] = true;
            $esQuery['from'] = 0;
            $esQuery['index'] = $this->model->getQueryTableName();
            $esQuery['type'] = "coreshop";

            $esQuery['size'] = 1;
            $esQuery['body']['query'] = $esQuery['query'];
            $esQuery['track_scores'] = true;
            unset($esQuery['query']);
            unset($esQuery['sort']);

            $maxScore = $esClient->search($esQuery)->asArray()['hits']['max_score'];
            $minScore = $maxScore * 0.9;

            $esQuery['size'] = 10000;
            $esQuery['body']['min_score'] = $minScore;

            $srcIds = $this->mapHitResults($esClient->search($esQuery)->asArray(), 'o_id');
        } else {
            $srcs = $esClient->sql()->query($params)->asArray();

            $srcIds = array_map(function ($item) {
                return $item[0];
            }, $srcs['rows']);
        }

        if (count($srcIds)) {
            $srcIds = implode(',', $srcIds);
            $queryBuilder->andWhere('src IN (' . $srcIds . ')');
        }

        $queryBuilder->groupBy('dest');

        $params['body']['query'] = $this->formatQueryParams($queryBuilder->getSQL());

        $queryResult = $this->mapResults($esClient->sql()->query($params)->asArray());

        $result = [];

        foreach ($queryResult as $row) {
            if ($row['dest']) {
                $result[] = $row['dest'];
            }
        }

        return $result;
    }

    /**
     * Get Count.
     *
     *
     * @return int
     */
    public function getCount(QueryBuilder $queryBuilder)
    {
        $esClient = $this->model->getWorker()->getElasticsearchClient();

        $queryBuilder->from($this->model->getQueryTableName(), 'q');
        if ($this->model->getVariantMode() == ListingInterface::VARIANT_MODE_INCLUDE_PARENT_OBJECT) {
            $queryBuilder->select('count(DISTINCT o_virtualObjectId)');
        } else {
            $queryBuilder->select('count(*)');
        }

        $queryBuilder->setMaxResults(1);
        $params['body']['query'] = $this->formatQueryParams($queryBuilder->getSQL());

        $queryResult = $this->mapResults($esClient->sql()->query($params)->asArray());

        return $queryResult[0]['count(*)'];
    }

    /**
     * quote value.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function quote($value)
    {
        return $this->database->quote($value);
    }

    protected function formatQueryParams(string $sqlQuery): string
    {
        $sqlQuery = str_replace('`', '', $sqlQuery);
        return str_replace("\'", '', $sqlQuery);
    }

    public function mapResults(array $results): array
    {
        $mappedResults = [];

        foreach ($results['rows'] as $rowKey => $row) {
            foreach ($row as $columnKey => $rowVal) {
                $columnName = $results['columns'][$columnKey]['name'];
                $mappedResults[$rowKey][$columnName] = $rowVal;
            }
        }

        return $mappedResults;
    }

    public function mapHitResults(array $results, string $fieldName): array
    {
        $mappedResults = [];

        foreach ($results['hits']['hits'] as $row) {
            $mappedResults[] = $row['_source'][$fieldName];
        }

        return $mappedResults;
    }

    /**
     * quote identifier.
     *
     * @param string $value
     *
     * @return string
     */
    public function quoteIdentifier($value)
    {
        return $this->database->quoteIdentifier($value);
    }

    /**
     * returns order by statement for similarity calculations based on given fields and object ids.
     */
    public function buildSimilarityOrderBy(array $fields, int $objectId): string
    {
        //TODO: similarity
        /*
        try {
            $fieldString = '';
            $maxFieldString = '';

            foreach ($fields as $field) {
                if ($field instanceof AbstractSimilarity) {
                    if (!empty($fieldString)) {
                        $fieldString .= ',';
                        $maxFieldString .= ',';
                    }


                    $fieldString .= $this->db->quoteIdentifier($field->getField());
                    $maxFieldString .= 'MAX('.$this->db->quoteIdentifier($field->getField()).') as '.$this->db->quoteIdentifier($field->getField());
                }
            }

            $query = 'SELECT '.$fieldString.' FROM '.$this->model->getQueryTableName().' a WHERE a.o_id = ?;';
            $objectValues = $this->db->fetchRow($query, $objectId);

            $query = 'SELECT '.$maxFieldString.' FROM '.$this->model->getQueryTableName().' a';
            $maxObjectValues = $this->db->fetchRow($query);

            if (!empty($objectValues)) {
                $subStatement = [];

                foreach ($fields as $field) {
                    if ($field instanceof AbstractSimilarity) {
                        if ($objectValues[$field->getField()]) {
                            $subStatement[] =
                                '(' .
                                $this->db->quoteIdentifier($field->getField()) . '/' . $maxObjectValues[$field->getField()] .
                                ' - ' .
                                $objectValues[$field->getField()] / $maxObjectValues[$field->getField()] .
                                ') * ' . $field->getWeight();
                        }
                    }
                }

                if (count($subStatement) > 0) {
                    $statement = 'ABS('.implode(' + ', $subStatement).')';

                    return $statement;
                }
            } else {
                throw new \Exception('Field array for given object id is empty');
            }
        } catch (\Exception $e) {
        }*/

        return '';
    }

    /**
     * returns where statement for fulltext search index.
     *
     * @param array  $fields
     * @param string $searchString
     *
     * @return string
     */
    public function buildFulltextSearchWhere($fields, $searchString)
    {
        $columnNames = [];

        foreach ($fields as $c) {
            $columnNames[] = $this->quoteIdentifier($c);
        }

        return 'MATCH (' . implode(',', $columnNames) . ') AGAINST (' . $this->quote($searchString) . ' IN BOOLEAN MODE)';
    }

    /**
     * get the record count for the last select query.
     */
    public function getLastRecordCount(): int
    {
        return $this->lastRecordCount;
    }
}
