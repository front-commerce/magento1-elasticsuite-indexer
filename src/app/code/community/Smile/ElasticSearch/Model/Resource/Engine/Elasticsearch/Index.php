<?php
/**
 * ElaticSearch query model
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
class Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index
    extends Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Abstract
{
    /**
     * @var int
     */
    const COPY_DATA_BULK_SIZE = 1000;

    /**
     * @var string
     */
    const MAPPING_CONF_ROOT_NODE = 'global/smile_elasticsearch/mapping';

    /**
     * @var string
     */
    const FULL_REINDEX_REFRESH_INTERVAL = '10s';

    /**
     * @var string
     */
    const DIFF_REINDEX_REFRESH_INTERVAL = '1s';

    /**
     * Index name.
     *
     * @var string
     */
    protected $_name;

    /**
     * @var string
     */
    protected $_baseIndexName;

    /**
     * Types mappings.
     *
     * @deprecated Index now has only one mapping
     *
     * @var array
     */
    protected $_mappings = array();

    /**
     * Does the index needs to be installed or not.
     *
     * @var boolean
     */
    protected $_indexNeedInstall = false;

    /**
     * Date format used by the index.
     *
     * @var string
     */
    protected $_dateFormat = 'date';

    /**
     * Snowball languages.
     *
     * @var array
     * @link http://www.elasticsearch.org/guide/reference/index-modules/analysis/snowball-tokenfilter.html
    */
    protected $_snowballLanguages = array(
        'armenian', 'basque', 'catalan', 'danish', 'dutch', 'english', 'finnish', 'french',
        'german', 'hungarian', 'italian', 'kp', 'lovins', 'norwegian', 'porter', 'portuguese',
        'romanian', 'russian', 'spanish', 'swedish', 'turkish',
    );

    /**
     * Beider-Morse algorithm supported languages (can be used for phonetic matching)
     *
     * @var array
     */
    protected $_beiderMorseLanguages = array(
        'english', 'french', 'german', 'hungarian', 'italian', 'romanian', 'russian', 'spanish', 'turkish'
    );

    /**
     * @var Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract
     */
    protected $_mapping;

    /**
     * @var Smile_ElasticSearch_Model_Scope $scope
     */
    protected $_scope;

    /**
     * Init mappings while the index is init
     */
    public function __construct()
    {
        $mappingConfig = Mage::getConfig()->getNode(self::MAPPING_CONF_ROOT_NODE)->asArray();
        foreach ($mappingConfig as $type => $config) {
            if ($type === "product") {
                $this->_mappings[$type] = Mage::getResourceSingleton($config['model']);
                $this->_mappings[$type]->setType($type);
            }
        }
    }

    /**
     * Set base index name, to allow generating unique names.
     *
     * @param string $indexName Base name of the index.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index
     */
    public function setBaseName($indexName)
    {
        $this->_baseIndexName = $indexName;
        return $this;
    }

    /**
     * Set current index name.
     *
     * @param string $indexName Name of the index.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index
     */
    public function setCurrentName($indexName)
    {
        $this->_currentIndexName = $indexName;
        return $this;
    }

    /**
     * Get name of the current index.
     *
     * @return string
     */
    public function getCurrentName()
    {
        return $this->_currentIndexName;
    }

    /**
     * Retrieve status data on the current index.
     * Access to the ES _status API.
     *
     * @return array
     */
    public function getStatus()
    {
        $result = false;
        $indexName = $this->getCurrentName();
        $statQuery = array('index' => $indexName);
        $indexStatResponse = $this->getClient()->indices()->stats($statQuery);

        if (isset($indexStatResponse['indices'])) {
            $result = current($indexStatResponse['indices']);
        }

        return $result;
    }

    /**
     * Refreshes index
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index Self reference
     */
    public function refresh()
    {
        $indices = $this->getClient()->indices();
        $params  = array('index' => $this->getCurrentName());
        if ($indices->exists($params)) {
            $indices->refresh($params);
        }
        return $this;
    }

    /**
     * Optimizes index
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index Self reference
     */
    public function forceMerge()
    {
        $indices = $this->getClient()->indices();
        $params  = array('index' => $this->getCurrentName());
        if ($indices->exists($params)) {
            $indices->forceMerge($params);
        }
        return $this;
    }

    /**
     * Return index settings.
     *
     * @return array
     */
    protected function _getSettings()
    {
        $indexSettings = array(
            'number_of_replicas'               => 0,
            "refresh_interval"                 => self::FULL_REINDEX_REFRESH_INTERVAL,
            "merge.scheduler.max_thread_count" => 1
        );

        $indexSettings['analysis'] = $this->getConfig('analysis_index_settings');
        $synonyms = Mage::getResourceModel('smile_elasticsearch/catalogSearch_synonym_collection')->exportSynonymList();

        if (!empty($synonyms)) {
            $indexSettings['analysis']['filter']['synonym'] = array(
                'type'     => 'synonym',
                'synonyms' => $synonyms
            );
        }

        $availableFilters = array_keys($indexSettings['analysis']['filter']);

        foreach ($indexSettings['analysis']['filter'] as &$filter) {
            if ($filter['type'] == 'elision') {
                $filter['articles'] = explode(',', $filter['articles']);
            }
        }

        foreach ($indexSettings['analysis']['analyzer'] as &$analyzer) {
            $analyzer['filter'] = isset($analyzer['filter']) ? explode(',', $analyzer['filter']) : array();
            $analyzer['filter'] = array_values(array_intersect($availableFilters, $analyzer['filter']));
            $analyzer['char_filter'] = isset($analyzer['char_filter']) ? explode(',', $analyzer['char_filter']) : array();
        }

        $indexSettings = $this->_addLanguageAnalyzerToSettings($indexSettings, $this->_scope->getLanguageCode(), $availableFilters);

        if ($this->isIcuFoldingEnabled()) {
            foreach ($indexSettings['analysis']['analyzer'] as &$analyzer) {
                array_unshift($analyzer['filter'], 'icu_folding');
                array_unshift($analyzer['filter'], 'icu_normalizer');
            }
            unset($analyzer);
        }

        return $indexSettings;
    }

    /**
     * Append analyzers for a given language.
     *
     * @param array  $indexSettings    Index settings.
     * @param string $languageCode     New language code.
     * @param array  $availableFilters List of available filters.
     *
     * @return array
     */
    protected function _addLanguageAnalyzerToSettings($indexSettings, $languageCode, $availableFilters)
    {
        $lang = strtolower(Zend_Locale_Data::getContent('en', 'language', $languageCode));

        $analyzerName = $this->analyzerName($languageCode);
        $indexSettings['analysis']['analyzer'][$analyzerName] = array(
            'type' => 'custom',
            'tokenizer' => 'whitespace',
            'filter' => array( 'word_delimiter', 'length', 'lowercase', 'ascii_folding', 'synonym'),
            'char_filter' => array('html_strip')
        );

        if (isset($indexSettings['analysis']['language_filters'][$lang])) {
            $additionalFilters = explode(',', $indexSettings['analysis']['language_filters'][$lang]);
            $indexSettings['analysis']['analyzer'][$analyzerName]['filter'] = array_merge(
                $indexSettings['analysis']['analyzer'][$analyzerName]['filter'],
                $additionalFilters
            );
        }

        $indexSettings['analysis']['analyzer'][$analyzerName]['filter'] = array_values(
            array_intersect(
                $indexSettings['analysis']['analyzer'][$analyzerName]['filter'],
                $availableFilters
            )
        );

        if (in_array($lang, $this->_snowballLanguages)) {
            $languageStemmer = $lang;
            if (isset($indexSettings['analysis']['language_stemmers'][$lang])) {
                $languageStemmer = $indexSettings['analysis']['language_stemmers'][$lang];
            }
            $indexSettings['analysis']['filter']['snowball_' . $languageCode] = array(
                'type' => 'stemmer', 'language' => $languageStemmer
            );
            $indexSettings['analysis']['analyzer'][$analyzerName]['filter'][] = 'snowball_' . $languageCode;
        }

        if (in_array($lang, $this->_beiderMorseLanguages)) {
            $indexSettings['analysis']['filter']['beidermorse_' . $languageCode] = array(
                'type' => 'phonetic', 'encoder' => 'beider_morse', 'languageset' => $lang
            );
            $indexSettings['analysis']['analyzer'][$this->phoneticAnalyzerName($languageCode)] = array(
                'type' => 'custom', 'tokenizer' => 'standard', 'char_filter' => 'html_strip',
                'filter' => array(
                    "standard", "ascii_folding", "lowercase", "stemmer", "beidermorse_" . $languageCode
                )
            );
        }

        return $indexSettings;
    }

    /**
     * Return a mapping used to index entities.
     *
     * @param string $type Retrieve mapping for a type (product, category, ...). @deprecated
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract
     */
    public function getMapping($type = null)
    {
        return $this->_mapping;
    }

    public function setMapping(Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract $mapping)
    {
        $this->_mapping = $mapping;
    }

    /**
     * Return all available mappings.
     *
     * @return array
     */
    public function getAllMappings()
    {
        return $this->_mappings;
    }

    /**
     * Creates or updates Elasticsearch index.
     *
     * @link http://www.elasticsearch.org/guide/reference/mapping/core-types.html
     * @link http://www.elasticsearch.org/guide/reference/mapping/multi-field-type.html
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index
     *
     * @throws Exception
     */
    protected function _prepareIndex()
    {
        try {
            $indexSettings = $this->_getSettings();
            $indices = $this->getClient()->indices();
            $params = array('index' => $this->getCurrentName());

            $indexedDataType = $this->getMapping()->getType();
            if ($indices->exists($params)) {

                $indices->close($params);

                $settingsParams = $params;
                $settingsParams['body']['settings'] = $indexSettings;
                $indices->putSettings($settingsParams);

                $mapping = $params;
                $mapping['body']['mappings'][$indexedDataType] = $this->getMapping()->getMappingProperties($this, false);
                $indices->putMapping($mapping);

                $indices->open();
            } else {
                $params['body']['settings'] = $indexSettings;
                $params['body']['settings']['number_of_shards'] = (int) $this->getConfig('number_of_shards');
                $params['body']['mappings'][$indexedDataType] = $this->getMapping()->getMappingProperties($this, false);

                $properties = new Varien_Object($params);
                Mage::dispatchEvent('smile_elasticsearch_index_create_before', array('index_properties' => $properties ));
                $indices->create($properties->getData());
            }
        } catch (Exception $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            throw $e;
        }

        return $this;
    }

    /**
     * Indicates if the phonetic machine is enabled for the current locale
     *
     * @param string $languageCode Language code. @deprecated
     *
     * @return boolean
     */
    public function isPhoneticSupported()
    {
        $lang = strtolower(Zend_Locale_Data::getContent('en', 'language', $this->_scope->getLanguageCode()));
        return in_array($lang, $this->_beiderMorseLanguages);
    }

    /**
     * Update index settings to refresh the synomyms list.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter
     */
    public function updateSynonyms()
    {
        $synonyms = Mage::getResourceModel('smile_elasticsearch/catalogSearch_synonym_collection')->exportSynonymList();
        $indices = $this->getClient()->indices();
        $params = array('index' => $this->getCurrentName());

        if ($indices->exists($params) && !empty($synonyms)) {
            $updateSettings = $params;
            $updateSettings['body']['analysis']['filter']['synonym'] = array(
                'type'     => 'synonym',
                'synonyms' => $synonyms
            );
            $indices->close($params);
            $indices->putSettings($updateSettings);
            $indices->open($params);
        }

        return $this;
    }


    /**
     * Checks if ICU folding is enabled.
     *
     * @link http://www.elasticsearch.org/guide/reference/index-modules/analysis/icu-plugin.html
     * @return bool
     */
    public function isIcuFoldingEnabled()
    {
        return (bool) $this->getConfig('enable_icu_folding');
    }

    /**
     * Prepare a new index for full reindex
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter Self Reference
     */
    public function prepareNewIndex()
    {
        $helper = $config = $this->_getHelper();
        $config = $helper->getEngineConfigData();

        // Compute index name
        $indexName = $helper->getHorodatedName($this->_baseIndexName);
        if (isset($config['indices_pattern'])) {
            $indexName = $helper->getHorodatedName($this->_baseIndexName, $config['indices_pattern']);
        }
        // Set the new index name
        $this->setCurrentName($indexName);

        // Indicates an old index exits
        $this->_indexNeedInstall = true;
        $this->_prepareIndex();

        return $this;
    }

    /**
     * Install the new index after full reindex
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter
     */
    public function installNewIndex()
    {
        if ($this->_indexNeedInstall) {
            $this->forceMerge();
            Mage::dispatchEvent('smile_elasticsearch_index_install_before', array('index_name' => $this->getCurrentName()));

            $indices = $this->getClient()->indices();
            $alias = $this->_baseIndexName;
            $indices->putSettings(
                array(
                    'index' => $this->getCurrentName(),
                    'body'  => array(
                        'number_of_replicas'        => (int) $this->getConfig('number_of_replicas'),
                        "refresh_interval"          => self::DIFF_REINDEX_REFRESH_INTERVAL,
                    )
                )
            );

            $deletedIndices = array();
            $aliasActions = array();
            $aliasActions[] = array('add' => array('index' => $this->getCurrentName(), 'alias' => $alias));
            try {
                $allIndices = $indices->getMapping(array('index'=> $alias));
            } catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
                $allIndices = array();
            }
            foreach (array_keys($allIndices) as $index) {
                if ($index != $this->getCurrentName()) {
                    $deletedIndices[] = $index;
                    $aliasActions[] = array('remove' => array('index' => $index, 'alias' => $alias));
                }
            }

            $indices->updateAliases(array('body' => array('actions' => $aliasActions)));

            foreach ($deletedIndices as $index) {
                Mage::dispatchEvent('smile_elasticsearch_index_delete_before', array('index_name' => $index));
                $indices->delete(array('index' => $index));
            }
        }
    }

    /**
     * Load a mapping from ES.
     *
     * @param string $type The type of document we want the mapping for.
     *
     * @return array|null
     */
    public function loadMappingPropertiesFromIndex($type)
    {
        $result = null;
        $params = array('index'=> $this->getCurrentName());
        if ($this->getClient()->indices()->exists($params)) {
            $params['type'] = $type;
            $mappings = $this->getClient()->indices()->getMapping($params);
            if (isset($mappings[$this->getCurrentName()]['mappings'][$type])) {
                $result = $mappings[$this->getCurrentName()]['mappings'][$type];
            }
        }
        return $result;
    }

    /**
     * Create document to index.
     *
     * @param string $id Document Id
     * @param array $data Data indexed
     * @param string $type Document type
     * @return array Json representation of the bulk document
     */
    public function createDocument($id, array $data = array(), $type = null)
    {
        $headerData = array(
            '_index'   => $this->getCurrentName(),
            '_type'    => is_null($type) ? $this->getMapping()->getType() : $type,
            '_id'      => $id,
            '_routing' => $id,
        );

        if (isset($data['_parent'])) {
            $headerData['_parent'] = $data['_parent'];
        }

        $headerRow = array('index' => $headerData);
        $dataRow = $data;

        $result = array($headerRow, $dataRow);
        return $result;
    }

    /**
     * Bulk document insert
     *
     * @param array $docs Document prepared with createDoc methods
     *
     * @return  Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter Self reference
     *
     * @throws Exception
     *
     * @deprecated use exectureBulk() instead
     */
    public function addDocuments(array $docs)
    {
        $this->executeBulk($docs);
        return $this;
    }

    /**
     * Copy all data of a type from an index to the current one
     *
     * @param string $index Source Index for the copy.
     * @param string $type  Type of documents to be copied.
     *
     * @return  Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter Self reference
     */
    public function copyDataFromIndex($index, $type)
    {
        if ($this->getClient()->indices()->exists(array('index' => $index))) {
            $scrollQuery = array(
                'index'  => $index,
                'type'   => $type,
                'size'   => self::COPY_DATA_BULK_SIZE,
                'scroll' => '5m',
            );

            $scroll = $this->getClient()->search($scrollQuery);
            $indexDocumentCount = 0;

            if ($scroll['_scroll_id'] && $scroll['hits']['total'] > 0) {
                $scroller = array('scroll' => '5m', 'scroll_id' => $scroll['_scroll_id']);
                while ($indexDocumentCount <= $scroll['hits']['total']) {
                    $docs = array();
                    $data = $this->getClient()->scroll($scroller);

                    foreach ($data['hits']['hits'] as $item) {
                        $docs = array_merge(
                            $docs,
                            $this->createDocument($item['_id'], $item['_source'], 'stats')
                        );
                    }

                    $this->executeBulk($docs);
                    $indexDocumentCount = $indexDocumentCount + self::COPY_DATA_BULK_SIZE;
                }
            }
        }

        return $this;
    }

    /**
     * Update document to index.
     *
     * @param string $id   Document Id
     * @param array  $data Data indexed
     * @param string $type Document type
     *
     * @return string Json representation of the bulk document
     */
    public function updateDocument($id, array $data = array(), $type = 'product')
    {
        $headerData = array(
            '_index'   => $this->getCurrentName(),
            '_type'    => $type,
            '_id'      => $id,
            '_routing' => $id,
        );

        if (isset($data['_parent'])) {
            $headerData['_parent'] = $data['_parent'];
        }

        $headerRow = array('update' => $headerData);
        $dataRow   = array('doc' => $data);

        $result = array($headerRow, $dataRow);
        return $result;
    }

    /**
     * Bulk document insert
     *
     * @param array $docs Document prepared with createDoc/updateDoc methods
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Abstract Self reference
     *
     * @throws Exception
     *
     */
    public function executeBulk(array $docs)
    {
        try {
            if (!empty($docs)) {
                $bulkParams = array('body' => $docs);
                $ret = $this->getClient()->bulk($bulkParams);
            }
        } catch (Exception $e) {
            throw($e);
        }

        return $this;
    }

    /**
     * @param Smile_ElasticSearch_Model_Scope $scope
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index
     */
    public function setScope(Smile_ElasticSearch_Model_Scope $scope)
    {
        $this->_scope = $scope;
        return $this;
    }

    /**
     * @param $languageCode
     * @return string
     */
    private function analyzerName($languageCode)
    {
        return 'analyzer_' . $languageCode;
    }

    /**
     * @return string
     */
    public function getLanguageAnalyzerName()
    {
        return $this->analyzerName($this->_scope->getLanguageCode());
    }

    /**
     * @param $languageCode
     * @return string
     */
    private function phoneticAnalyzerName($languageCode)
    {
        return 'phonetic_' . $languageCode;
    }

    /**
     * @return string
     */
    public function getPhoneticAnalyzerName()
    {
        return $this->phoneticAnalyzerName($this->_scope->getLanguageCode());
    }

    /**
     * @param null $ids
     * @return void
     */
    public function rebuildIndex($ids = null)
    {
        $this->getMapping()->rebuildIndex($this, $ids);
        $this->refresh();
    }

    /**
     * @return Smile_ElasticSearch_Model_Scope
     */
    public function getScope()
    {
        return $this->_scope;
    }

    /**
     * Wether the index contains data of the given type or not
     *
     * @param $type
     * @return boolean
     */
    public function isForType($type)
    {
        return $type === $this->getMapping()->getType();
    }
}
