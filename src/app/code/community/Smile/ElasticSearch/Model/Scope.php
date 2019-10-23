<?php

class Smile_ElasticSearch_Model_Scope
{
    /**
     * @var Mage_Core_Model_Store
     */
    private $store;

    private function __construct(Mage_Core_Model_Store $store)
    {
        $this->store = $store;
    }

    public static function fromMagentoStore(Mage_Core_Model_Store $store)
    {
        return new static($store);
    }

    /**
     * @throws Mage_Core_Model_Store_Exception
     */
    public static function fromMagentoStoreId($storeId)
    {
        return new static(
            Mage::app()->getStore($storeId)
        );
    }

    public function getLanguageCode()
    {
        return $this->getHelper()->getLanguageCodeByStore($this->getStore());
    }

    public function getStoreId()
    {
        return $this->getStore()->getId();
    }

    private function getStore()
    {
        return $this->store;
    }

    /**
     * Returns search helper.
     *
     * @return Smile_ElasticSearch_Helper_Elasticsearch
     */
    private function getHelper()
    {
        return Mage::helper('smile_elasticsearch/elasticsearch');
    }

    public function getWebsiteId()
    {
        return $this->getStore()->getWebsiteId();
    }

    public function getIdentifier()
    {
        return 'store' . $this->getStoreId();
    }
}