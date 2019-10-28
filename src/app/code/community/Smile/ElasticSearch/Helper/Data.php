<?php
/**
 * Search helper
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
class Smile_ElasticSearch_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Temporary feature flag to disable multi-store indexation in a single index
     * while https://github.com/front-commerce/magento1-elasticsuite-indexer/issues/7 is not implemented
     */
    const FEATURE_MULTI_STORE_ENABLED = true;

    /**
     * Allowed languages.
     * Example: array('en_US' => 'en', 'fr_FR' => 'fr')
     *
     * @var array
     */
    protected $_languageCodes = array();

    public function getIndexScopes()
    {
        return array_values(array_map(
            ['Smile_ElasticSearch_Model_Scope', 'fromMagentoStore'],
            $this->getIndexedStores())
        );
    }

    private function getIndexedStores()
    {
        $stores = Mage::app()->getStores();
        if (static::FEATURE_MULTI_STORE_ENABLED) {
            return $stores;
        }

        $firstKey = array_shift(array_keys($stores));
        return [
            $firstKey => $stores[$firstKey]
        ];
    }

    public function getIndexedStoreIdsFromWebsiteIds($ids)
    {
        if (static::FEATURE_MULTI_STORE_ENABLED) {
            // TODO Get rid of usages of this method
            throw new LogicException('Multi-store is not available yet. See https://github.com/front-commerce/magento1-elasticsuite-indexer/issues/7');
        }

        $websiteId = current($ids);
        $storeIds = Mage::app()->getWebsite($websiteId)->getStoreIds();
        return [array_shift($storeIds)];
    }

    /**
     * Returns cache lifetime in seconds.
     *
     * @return int
     */
    public function getCacheLifetime()
    {
        return Mage::getStoreConfig('core/cache/lifetime');
    }

    /**
     * Returns search engine config data.
     *
     * @param string $prefix Configuration prefix to be loaded
     * @param mixed  $store  Store we want the configuration for
     *
     * @return array
     */
    public function getEngineConfigData($prefix = '', $store = null)
    {
        $config = Mage::getStoreConfig('catalog/search', $store);
        $data = array();
        if ($prefix) {
            foreach ($config as $key => $value) {
                $matches = array();
                if (preg_match("#^{$prefix}(.*)#", $key, $matches)) {
                    $data[$matches[1]] = $value;
                }
            }
        } else {
            $data = $config;
        }

        return $data;
    }

    /**
     * Returns language code of specified locale code.
     *
     * @param string $localeCode Locale we want the ES language code
     *
     * @return bool
     */
    public function getLanguageCodeByLocaleCode($localeCode)
    {
        $localeCode = $localeCode;
        $localeCodeParts = explode('_', $localeCode);
        return current($localeCodeParts);
    }

    /**
     * Returns store language code.
     *
     * @param mixed $store The store we want the language code for
     *
     * @return bool
     */
    public function getLanguageCodeByStore($store = null)
    {
        if (!isset($this->_languageCodes[$store->getStoreId()])) {
            $this->_languageCodes[$store->getStoreId()] = $this->getLanguageCodeByLocaleCode($this->getLocaleCode($store));
        }
        return $this->_languageCodes[$store->getStoreId()];
    }

    /**
     * Returns store locale code.
     *
     * @param null $store The store we want the locale code for
     *
     * @return string
     */
    public function getLocaleCode($store = null)
    {
        return Mage::getStoreConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_LOCALE, $store);
    }

    /**
     * Returns search config data field value.
     *
     * @param string $field Name of the fied (ie: elasticsearch_servers)
     * @param mixed  $store Store we want the config for
     *
     * @return mixed
     */
    public function getSearchConfigData($field, $store = null)
    {
        $path = 'catalog/search/' . $field;

        return Mage::getStoreConfig($path, $store);
    }

    /**
     * Checks if configured engine is active.
     *
     * @return bool
     */
    public function isActiveEngine()
    {
        $engine = $this->getSearchConfigData('engine');
        if ($engine && Mage::getConfig()->getResourceModelClassName($engine)) {
            $model = Mage::getResourceSingleton($engine);
            return $model
                && $model instanceof Smile_ElasticSearch_Model_Resource_Engine_ElasticSearch
                && $model->test();
        }

        return false;
    }

    /**
     * Checks if debug mode is enabled.
     *
     * @return bool
     */
    public function isDebugEnabled()
    {
        $config = $this->getEngineConfigData();

        return array_key_exists('enable_debug_mode', $config) && $config['enable_debug_mode'];
    }

    /**
     * Method that can be overriden for customing product data indexation.
     *
     * @param array  $index     Data to be indexed
     * @param string $separator Separator used into the index
     *
     * @return array
     */
    public function prepareIndexData($index, $separator = null)
    {
        return $index;
    }

    /**
     * Indicates if the current Magento instance is a Enterprise one.
     *
     * @return bool
     */
    public function isEnterpriseSupportEnabled()
    {
        return Mage::helper('core')->isModuleEnabled('Enterprise_CatalogSearch');
    }

    /**
     * Retrieve attributes that can be set to "is_filterable" by their frontend input
     *
     * @return array
     */
    public function getSearchableAttributesFrontendInputs()
    {
        $frontendInputs = array('select', 'multiselect', 'price', 'text');

        $eventData = new Varien_Object(array('frontend_inputs' => $frontendInputs));

        Mage::dispatchEvent('smile_elasticsearch_get_searchable_attributes_frontend_inputs', array('attribute_data' => $eventData));

        $frontendInputs = $eventData->getFrontendInputs();

        return $frontendInputs;
    }
}
