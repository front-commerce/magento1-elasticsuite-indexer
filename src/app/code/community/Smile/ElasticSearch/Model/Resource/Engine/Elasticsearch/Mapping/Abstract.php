<?php
/**
 * Abstract class that define a type mapping
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Searchandising Suite to newer
 * versions in the future.
 *
 * This work is a fork of Johann Reinke <johann@bubblecode.net> previous module
 * available at https://github.com/jreinke/magento-elasticsearch
 *
 * @category  Smile
 * @package   Smile_ElasticSearch
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2013 Smile
 * @license   Apache License Version 2.0
 */
abstract class Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract
{
    /**
     * @var string
     */
    const FIELD_TYPE_SEARCH = 'search';

    /**
     * @var string
     */
    const FIELD_TYPE_FILTER = 'filter';

    /**
     * @var string
     */
    const FIELD_TYPE_SORT   = 'sort';

    /**
     * @var string
     */
    const FIELD_TYPE_FACET  = 'facet';

    /**
     * @var string
     */
    const SEARCH_TYPE_NORMAL  = 'normal';

    /**
     * @var string
     */
    const SEARCH_TYPE_FUZZY = 'fuzzy';

    /**
     * @var string
     */
    const SEARCH_TYPE_PHONETIC = 'phonetic';

    /**
     * @var string
     */
    const SEARCH_TYPE_AUTOCOMPLETE = 'autocomplete';

    /**
     * ES Type.
     *
     * @var string
     */
    protected $_type;

    /**
     * Search fields.
     *
     * @var array
     */
    protected $_searchFields = array();

    /**
     * Data providers.
     *
     * @var array
     */
    protected $_dataProviders = array();

    /**
     * Search helper.
     *
     * @var Smile_ElasticSearch_Helper_Data
     */
    protected $_helper;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->_helper = Mage::helper('smile_elasticsearch');
    }

    /**
     * Retrieve data providers as defined in configuration
     *
     * @return array
     */
    public function getDataProviders()
    {
        if ($this->_dataProviders == null) {
            $this->_dataProviders = array();
            $configurationRoot = Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index::MAPPING_CONF_ROOT_NODE;
            $configurationNode = $configurationRoot . "/" . $this->_type . "/data_providers";
            $config = Mage::getConfig()->getNode($configurationNode);

            if ($config) {
                foreach ($config->asArray() as $dataProviderIdentifier => $dataProviderModelName) {
                    $dataProvider = Mage::getResourceModel($dataProviderModelName);
                    if ($dataProvider) {
                        $dataProvider->setMapping($this);
                        $this->_dataProviders[$dataProviderIdentifier] = $dataProvider;
                    }
                }
            }
        }

        return $this->_dataProviders;
    }

    /**
     * Retrieve a data provider by its identifier
     *
     * @param string $dataProviderIdentifier The data provider identifier
     *
     * @return null
     */
    public function getDataProvider($dataProviderIdentifier)
    {
        $dataProviders = $this->getDataProviders();
        $dataProvider  = null;
        if ($dataProviders[$dataProviderIdentifier]) {
            $dataProvider = $dataProviders[$dataProviderIdentifier];
        }
        return $dataProvider;
    }

    /**
     * Set index type for the current mapping.
     *
     * @param string $type The new type.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract
     */
    public function setType($type)
    {
        $this->_type = $type;
        return $this;
    }

    /**
     * Get the mapping type
     *
     * @return string
     */
    public function getType()
    {
        return $this->_type;
    }

    /**
     * Return a list of all searchable field for the current type (by locale code).
     *
     * @param string $languageCode Language code.
     * @param string $searchType   Type of search currentlty used.
     * @param string $analyzer     Allow to force the analyzer used for the field (whitespace, ...).
     *
     * @return array.
     */
    abstract public function getSearchFields($languageCode, $searchType = null, $analyzer = null);

    /**
     * Return the ES field name
     *
     * @param string $field        Document base field (name, size, ...).
     * @param string $languageCode Language code we want the field for.
     * @param string $type         How the field will be used : search, filter, facet, sort
     * @param string $analyzer     Allow to force the analyzer used for the field (whitespace, ...).
     *
     * @return string
     */
    public function getFieldName($field, $languageCode, $type = self::FIELD_TYPE_SEARCH, $analyzer = null)
    {
        $mapping = $this->getMappingProperties();

        $useOptions        = isset($mapping['properties']['options_' . $field . '_' . $languageCode]);
        $typesUsingOptions = array(self::FIELD_TYPE_SEARCH, self::FIELD_TYPE_SORT, self::FIELD_TYPE_FACET);
        $typesUsedInSearch = array('text', 'keyword');

        if (in_array($type, $typesUsingOptions) && $useOptions) {
            $field = 'options_' . $field . '_' . $languageCode;
        } else if (isset($mapping['properties'][$field . '_' . $languageCode])) {
            $field = $field . '_' . $languageCode;
        }

        if (isset($mapping['properties'][$field]['type'])) {

            $mappingType = $mapping['properties'][$field]['type'];
            if (!in_array($mappingType, $typesUsedInSearch) && $type == self::FIELD_TYPE_SEARCH) {
                $field = false;
            }

            if ($field && $mappingType == 'text') {
                if ($analyzer == null && in_array($type, array(self::FIELD_TYPE_FILTER, self::FIELD_TYPE_FACET))) {
                    $analyzer = 'untouched';
                } else if ($analyzer == null && $type == self::FIELD_TYPE_SORT) {
                    $analyzer = 'sortable';
                }

                if ($analyzer != null) {
                    $field = $field . '.' . $analyzer;
                }
            }
        }

        return $field;
    }

    /**
     * Prepare the spelling fied during mapping generation
     *
     * @return array
     */
    protected function _getSpellingFieldMapping(Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index)
    {
        $mapping = array();
        $defaultAnalyzer = $index->getLanguageAnalyzerName();
        $baseFieldProperties = array('type' => 'text', 'store' => false);
        foreach (array('search', 'spelling', 'autocomplete') as $currentField) {
            $currentIndexField = $currentField;
            $mapping[$currentIndexField] = $baseFieldProperties;
            $mapping[$currentIndexField]['analyzer'] = $defaultAnalyzer;
            $mapping[$currentIndexField]['fields'] = array(
                'whitespace' => array_merge(array('analyzer' => 'whitespace'), $baseFieldProperties),
            );

            if ($currentField == 'autocomplete') {
                $mapping[$currentIndexField]['fields']['edge_ngram_front'] = array_merge(
                    array('analyzer' => 'edge_ngram_front'),
                    $baseFieldProperties
                );
            }

            if ($index->isPhoneticSupported()) {
                $mapping[$currentIndexField]['fields']['phonetic'] = array_merge(
                    array('analyzer' => $index->getPhoneticAnalyzerName()),
                    $baseFieldProperties
                );
            }
        }

        return $mapping;
    }

    /**
     * Return mapping for an attribute of type varchar
     *
     * @param string $fieldName Name of the field
     * @param Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index
     * @param string $type ES core type (string default)
     * @param bool $sortable Can the attribute be used for sorting
     * @param bool $fuzzy Can the attribute be used in fuzzy searches.
     * @param bool $facet Can the attribute be used as a facet.
     * @param bool $autocomplete Can the attribute be used in autocomplete.
     * @param bool $searchable Can the attribute be used in search.
     *
     * @return array string
     */
    protected function _getStringMapping(
        $fieldName, Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index, $type = 'text', $sortable = false,
        $fuzzy = true, $facet = true, $autocomplete = true, $searchable = true
    ) {
        $mapping = array();

        $analyzers = array('whitespace');

        $mapping[$fieldName] = array(
            'type' => $type, 'analyzer' => $index->getLanguageAnalyzerName(), 'store' => false,
            'fields' => array()
        );

        if ($autocomplete == true || $facet == true) {
            $analyzers[] = 'edge_ngram_front';

            if ($facet == true) {
                $mapping[$fieldName]['fields']['untouched'] = array(
                    'type' => $type === 'text' ? 'keyword' : $type, 'index' => true, 'store' => false
                );
            }

            if ($autocomplete == true) {
                $mapping[$fieldName]['copy_to'][] = 'autocomplete';
            }
        }

        if ($sortable == true) {
            $analyzers[] = 'sortable';
        }

        if ($fuzzy == true) {
            $mapping[$fieldName]['copy_to'][] = 'spelling';
        }

        if ($index->isPhoneticSupported()) {
            $analyzers[] = 'phonetic';
        }

        foreach ($analyzers as $analyzer) {
            $analyserOptions = array('type' => $type, 'analyzer' => $analyzer, 'store' => false);
            if ($analyzer == 'phonetic') {
                $analyserOptions['analyzer'] = $index->getPhoneticAnalyzerName();
            }
            $mapping[$fieldName]['fields'][$analyzer] = $analyserOptions;
        }

        if ($searchable) {
            $mapping[$fieldName]['copy_to'][] = 'search';
        }

        return $mapping;
    }

    /**
     * Get mapping properties as stored into the index
     *
     * @param Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index
     * @param bool $useCache Indicates if the cache should be used or if the mapping should be rebuilt.
     *
     * @return array
     */
    public function getMappingProperties(Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index, $useCache = true)
    {
        $indexName = $index->getCurrentName();

        $cacheKey = 'SEARCH_ENGINE_MAPPING_' . $indexName . $this->_type;

        if ($this->_mapping[$cacheKey] == null && $useCache) {
            $mapping = Mage::app()->loadCache($cacheKey);
            if ($mapping) {
                $this->_mapping[$cacheKey] = unserialize($mapping);
            }
        }

        if ($this->_mapping[$cacheKey] === null) {

            $this->_mapping[$cacheKey] = $index->loadMappingPropertiesFromIndex($this->_type);
            if ($this->_mapping[$cacheKey] === null) {
                $this->_mapping[$cacheKey] = $this->_getMappingProperties($index);
            }

            $mapping = serialize($this->_mapping[$cacheKey]);

            Mage::app()->saveCache(
                $mapping, $cacheKey, array('CONFIG', 'EAV_ATTRIBUTE'),
                $this->_helper->getCacheLifetime()
            );
        }

        return $this->_mapping[$cacheKey];
    }

    /**
     * Get the size of each bulk of product indexed
     *
     * @return int
     */
    protected function _getBatchIndexingSize()
    {
        return max(1, (int) Mage::getStoreConfig('catalog/search/elasticsearch_batch_indexing_size'));
    }

    /**
     * Get analyzer for a search type.
     *
     * @param string $languageCode Language code.
     * @param string $searchType   Search type.
     *
     * @return string
     */
    protected function _getDefaultAnalyzerBySearchType($languageCode, $searchType)
    {
        $analyzer = null;

        if ($searchType == self::SEARCH_TYPE_FUZZY) {
            $analyzer = 'whitespace';
        } else if ($searchType == self::SEARCH_TYPE_PHONETIC) {
            $analyzer = 'phonetic_' . $languageCode;
        } else if ($searchType == self::SEARCH_TYPE_AUTOCOMPLETE) {
            $analyzer = 'edge_ngram_front';
        }

        return $analyzer;
    }

    /**
     * As fields are copied into spelling or autocomplete, we can use a default field to reduce the number of fields
     * into multi_match query.
     * Kind of equivalent to _all fields but search type dependant.
     *
     * @param string $languageCode Language code.
     * @param string $searchType   Search type.
     *
     * @return string
     */
    protected function _getDefaultSearchFieldBySearchType($languageCode, $searchType)
    {
        $defaultSearchFields = array();

        if (in_array($searchType, array(self::SEARCH_TYPE_FUZZY, self::SEARCH_TYPE_PHONETIC))) {
            $defaultSearchFields = 'spelling_' . $languageCode;
        } else if ($searchType == self::SEARCH_TYPE_NORMAL) {
            $defaultSearchFields = 'search_' . $languageCode;
        } else if ($searchType == self::SEARCH_TYPE_AUTOCOMPLETE) {
            $defaultSearchFields = 'autocomplete_' . $languageCode;
        }
        return $defaultSearchFields;
    }


    /**
     * Return the current index.
     *
     * @deprecated Mapping must not have information about index. Please inject additional parameters instead.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index
     */
    public function getCurrentIndex()
    {
        $engine = Mage::helper('catalogsearch')->getEngine();
        return $engine->getCurrentIndex();
    }

    /**
     * Save docs to the index
     *
     * @param Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index
     * @param array $entityIndexData Doc values.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Catalog_Eav_Abstract
     */
    protected function _saveIndexes(Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index, $entityIndexData)
    {
        foreach ($this->getDataProviders() as $dataProvider) {
            $entityIds = array_keys($entityIndexData);
            $externalData = $dataProvider->getEntitiesData($index->getScope(), $entityIds);
            foreach ($entityIndexData as $entityId => &$entityData) {
                if (isset($externalData[$entityId])) {
                    $entityData += $externalData[$entityId];
                }
            }
        }

        Mage::helper('catalogsearch')->getEngine()->saveEntityIndexes($index, $entityIndexData);
        return $this;
    }

    /**
     * Get mapping properties as stored into the index
     *
     * @param Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index
     * @return array
     */
    protected function _getMappingProperties(Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index)
    {
        $mapping = array(
            '_all' => array('enabled' => false),
            'properties' => array()
        );

        foreach ($this->getDataProviders() as $dataProvider) {
            $mapping = array_merge_recursive(
                $mapping,
                $dataProvider->getMappingProperties()
            );
        }

        return $mapping;
    }

    /**
     * Rebuild the index (full or diff).
     *
     * @param Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index The index to be rebuilt.
     * @param array|null $ids Ids the index should be rebuilt for. If null, processing a fulll reindex
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract
     */
    abstract public function rebuildIndex(Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index, $ids = null);

}
