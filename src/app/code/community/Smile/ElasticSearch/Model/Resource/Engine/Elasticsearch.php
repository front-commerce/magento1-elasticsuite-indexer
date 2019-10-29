<?php
/**
 * Elastic search engine.
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

// Include the Elasticsearch required libraries used by the adapter
require_once 'vendor/autoload.php';

/**
 * Elastic search engine.
 *
 * @category  Smile
 * @package   Smile_ElasticSearch
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2013 Smile
 * @license   Apache License Version 2.0
 */
class Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch
{

    /**
     * @var string
     */
    const CACHE_INDEX_PROPERTIES_ID = 'elasticsearch_index_properties';

    /**
     * @var string
     */
    const UNIQUE_KEY = 'unique';

    /**
     *
     * @var array List of default query parameters.
     */
    protected $_defaultQueryParams = array(
        'offset' => 0,
        'limit' => 100,
        'sort_by' => array(
            array(
                'relevance' => 'desc'
            )
        ),
        'store_id' => null,
        'locale_code' => null,
        'fields' => array(),
        'params' => array(),
        'ignore_handler' => false,
        'filters' => array()
    );

    /**
     * @var bool
     */
    protected $_test = null;

    /**
     *
     * @var array List of used fields.
     */
    protected $_usedFields = array(
        self::UNIQUE_KEY,
        'id',
        'sku',
        'price',
        'store_id',
        'categories',
        'show_in_categories',
        'visibility',
        'in_stock',
        'score'
    );

    /**
     *
     * @var Varien_Object
     */
    protected $_config;

    /**
     *
     * @var Elasticsearch\Client
     */
    protected $_client = null;

    /**
     * @deprecated Index must be passed as parameters to use the one matching the correct scope
     * @var Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index
     */
    protected $_currentIndex = null;

    /**
     *
     * @var string
     */
    protected $_currentIndexName = null;

    /**
     * Date formats used by the index.
     *
     * @var array()
     */
    protected $_dateFormats = array();

    /**
     * Base alias for all indexes
     *
     * @var string
     */
    protected $_aliasBase;

    /**
     * Types mappings.
     *
     * @var array
     */
    protected $_mappings = [];

    /**
     * Initializes search engine config and index name.
     *
     * @param array|bool $params Client init params.
     */
    public function __construct($params = false)
    {
        $config = $this->_getHelper()->getEngineConfigData();

        $this->_config = new Varien_Object($config);

        $clientBuilder = \Elasticsearch\ClientBuilder::create();
        $clientBuilder
            ->setHosts($config['hosts']);

        $this->_client = $clientBuilder->build();

        if (!isset($config['alias'])) {
            Mage::throwException('Alias must be defined for search engine client.');
        }
        $this->_aliasBase = $config['alias'];

        $mappingConfig = Mage::getConfig()->getNode(Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index::MAPPING_CONF_ROOT_NODE)->asArray();
        foreach ($mappingConfig as $type => $config) {
            if ($type === "product") {
                $this->_mappings[$type] = Mage::getResourceSingleton($config['model']);
                $this->_mappings[$type]->setType($type);
            }
        }

        $this->_currentIndex = Mage::getResourceModel('smile_elasticsearch/engine_elasticsearch_index');
        $this->_currentIndex->setAdapter($this)->setCurrentName($config['alias']);
    }

    /**
     * Get the ElasticSearch client instance
     *
     * @return \Elasticsearch\Client
     */
    public function getClient()
    {
        return $this->_client;
    }

    /**
     * Return the current index instance
     *
     * @deprecated Use getCurrentIndexesForScope
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index
     */
    public function getCurrentIndex()
    {
        return $this->_currentIndex;
    }

    /**
     * @param array $scopes
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index[]
     */
    public function getCurrentIndexesForScopes(array $scopes)
    {
        return array_reduce(
            array_map([$this, 'getCurrentIndexesForScope'], $scopes),
            'array_merge',
            []
        );
    }

    /**
     * Return the current index instance for a given scope
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index[]
     */
    public function getCurrentIndexesForScope(Smile_ElasticSearch_Model_Scope $scope)
    {
        $indexes = [];
        foreach ($this->_mappings as $type => $mapping) {
            /** @var Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index */
            $index = Mage::getResourceModel('smile_elasticsearch/engine_elasticsearch_index');
            $indexName = $this->aliasForScopedType($scope, $type);
            $index
                ->setAdapter($this)
                ->setCurrentName($indexName)
                ->setBaseName($indexName)
                ->setScope($scope)
                ->setMapping($mapping);
            $indexes[] = $index;
        }

        return $indexes;
    }

    /**
     * @param array $scopes
     * @param $type
     * @return array
     */
    public function getCurrentIndexesForScopesAndType(array $scopes, $type)
    {
        return array_filter(
            $this->getCurrentIndexesForScopes($scopes),
            function (Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index) use ($type) {
                return $index->isForType($type);
            }
        );
    }

    private function aliasForScopedType(Smile_ElasticSearch_Model_Scope $scope, $type)
    {
        return implode('_', [
            $this->_aliasBase,
            $scope->getIdentifier(),
            $type
        ]);
    }

    /**
     * Cleans caches.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch
     */
    public function cleanCache()
    {
        Mage::app()->removeCache(self::CACHE_INDEX_PROPERTIES_ID);

        return $this;
    }

    /**
     * Cleans index.
     *
     * This is part of the Engine interface (see \Mage_CatalogSearch_Model_Resource_Fulltext::cleanIndex)
     * and signature cannot be modified
     *
     * @param int    $storeId Store ind to be cleaned
     * @param int    $id      Document id to be cleaned
     * @param string $type    Document type to be cleaned
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch
     */
    public function cleanIndex($storeId = null, $id = null, $type = 'product')
    {
        if (is_null($id)) {
            return $this;
        } else if (!is_array($id)) {
            $id = array($id);
        }

        if (is_null($storeId)) {
             $scopes = Mage::helper('smile_elasticsearch')->getIndexScopes();
        } else if (!is_array($storeId)) {
            $scopes = array(Smile_ElasticSearch_Model_Scope::fromMagentoStoreId($storeId));
        } else {
            $scopes = array_map(['Smile_ElasticSearch_Model_Scope', 'fromMagentoStoreId'], $storeId);
        }
        $indexes = $this->getCurrentIndexesForScopesAndType($scopes, $type);

        $bulk = array('body' => array());

        foreach ($id as $currentId) {
            foreach ($indexes as $index) {
                $bulk['body'][] = array(
                    'delete' => array(
                        '_index' => $index->getCurrentName(),
                        '_type' => $type, // even though it is redundant since there is only 1 type per index, it seems to be mandatory in ES 6.7
                        '_id'    => $currentId
                    )
                );
            }
        }

        if (!empty($bulk['body'])) {
            $this->getClient()->bulk($bulk);
        }

        return $this;
    }

    /**
     * Saves products data in index.
     *
     * @param int    $storeId Store id
     * @param array  $indexes Documents data
     * @param string $type    Documents type
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch
     */
    public function saveEntityIndexes($storeId, $indexes, $type = 'product')
    {
        $object = new Varien_Object();
        $eventDatas = array(
            'type'     => $type,
            'indexes'  => $object->setBulk($indexes),
            'engine'   => $this,
            'store_id' => $storeId,
        );
        Mage::dispatchEvent('search_engine_save_entity_index_before', $eventDatas);
        Mage::dispatchEvent('search_engine_save_'.(string) $type.'_index_before', $eventDatas);

        $docs = $this->_prepareDocs($object->getBulk(), $type);
        $this->getCurrentIndex()->addDocuments($docs);

        Mage::dispatchEvent('search_engine_save_entity_index_after', $eventDatas);
        Mage::dispatchEvent('search_engine_save_'.(string) $type.'_index_after', $eventDatas);
        return $this;
    }

    /**
     * Checks Elasticsearch availability.
     *
     * @return bool
     */
    public function test()
    {
        if (null !== $this->_test) {
            return $this->_test;
        }

        try {
            $this->_test = $this->getStatus();
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_test = false;
        }

        return $this->_test;
    }

    /**
     * Returns advanced search results.
     *
     * @return Smile_ElasticSearch_Model_Resource_Catalog_Product_Collection
     */
    public function getAdvancedResultCollection()
    {
        return $this->getResultCollection();
    }

    /**
     * Checks if advanced index is allowed for current search engine.
     *
     * @return bool
     */
    public function allowAdvancedIndex()
    {
        return true;
    }

    /**
     * Return a new query instance
     *
     * @param string $type  Type of document for the query.
     * @param string $model Query model name (fulltext, autocomplete, ...).
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query_Abstract
     */
    public function createQuery($type, $model = 'smile_elasticsearch/engine_elasticsearch_query_fulltext')
    {
        $query = Mage::getResourceModel($model)
            ->setAdapter($this)
            ->setType($type);

        return $query;
    }

    /**
     * Returns resource name.
     *
     * @return string
     */
    public function getResourceName()
    {
        return 'smile_elasticsearch/advanced';
    }

    /**
     * Returns catalog product collection with current search engine set.
     *
     * @return Smile_ElasticSearch_Model_Resource_Catalog_Product_Collection
     */
    public function getResultCollection()
    {
        return Mage::getResourceModel('smile_elasticsearch/catalog_product_collection')->setEngine($this);
    }

    /**
     * Checks if layered navigation is available for current search engine.
     *
     * @return bool
     */
    public function isLayeredNavigationAllowed()
    {
        return true;
    }

    public function isLeyeredNavigationAllowed()
    {
        return $this->isLayeredNavigationAllowed();
    }

    /**
     * Prepares index data.
     * Should be overriden in child classes if needed.
     *
     * @param array  $index     Indexed data
     * @param string $separator Field separator into the index
     *
     * @return array
     */
    public function prepareEntityIndex($index, $separator = null)
    {
        return $this->_getHelper()->prepareIndexData($index, $separator);
    }

    /**
     * Transforms specified date to basic YYYY-MM-dd format.
     *
     * @param int    $storeId Current store id
     * @param string $date    Date to be transformed
     *
     * @return null string
     */
    protected function _getDate($storeId, $date = null)
    {
        if (! isset($this->_dateFormats[$storeId])) {
            $timezone = Mage::getStoreConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE, $storeId);
            $locale = Mage::getStoreConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_LOCALE, $storeId);
            $locale = new Zend_Locale($locale);

            $dateObj = new Zend_Date(null, null, $locale);
            $dateObj->setTimezone($timezone);
            $this->_dateFormats[$storeId] = array(
                $dateObj,
                $locale->getTranslation(null, 'date', $locale)
            );
        }

        if (is_empty_date($date)) {
            return null;
        }

        list ($dateObj, $localeDateFormat) = $this->_dateFormats[$storeId];
        $dateObj->setDate($date, $localeDateFormat);

        return $dateObj->toString('YYYY-MM-dd');
    }

    /**
     * Perpare document to be indexed
     *
     * @param Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index
     * @param array $docsData Source document data to be indexed
     * @return array
     */
    protected function _prepareDocs(Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index $index, $docsData)
    {
        if (!is_array($docsData) || empty($docsData)) {
            return array();
        }

        $docs = array();
        foreach ($docsData as $entityId => $data) {
            $document = $index->createDocument($data[self::UNIQUE_KEY], $data);
            array_push($docs, $document[0]);
            array_push($docs, $document[1]);
        }

        return $docs;
    }

    /**
     * Indicates if connection to the search engine is up or not
     *
     * @return bool
     */
    public function getStatus()
    {
        return $this->_client->ping();
    }

    /**
     * Read configuration from key
     *
     * @param string $key Name of the config param to retrieve
     *
     * @return mixed
     */
    public function getConfig($key)
    {
        return $this->_config->getData($key);
    }

    /**
     * Returns search helper.
     *
     * @return Smile_ElasticSearch_Helper_Elasticsearch
     */
    protected function _getHelper()
    {
        return Mage::helper('smile_elasticsearch/elasticsearch');
    }
}
