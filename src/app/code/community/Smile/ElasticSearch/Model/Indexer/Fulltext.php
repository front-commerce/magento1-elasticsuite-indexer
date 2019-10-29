<?php
/**
 * Smile_ElasticSearch custom indexer for search.
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
class Smile_ElasticSearch_Model_Indexer_Fulltext extends Mage_CatalogSearch_Model_Indexer_Fulltext
{
    /**
     * (Dummy) Register indexer event
     *
     * @param Mage_Index_Model_Event $event Indexer event
     *
     * @return Mage_CatalogSearch_Model_Indexer_Fulltext
     */
    public function register(Mage_Index_Model_Event $event)
    {
        $helper = Mage::helper('smile_elasticsearch');
        if ($helper->isEnterpriseSupportEnabled() == true) {
            return $this;
        }
        return parent::register($event);
    }

    /**
     * (Dummy) Process event
     *
     * @param Mage_Index_Model_Event $event Indexer event
     *
     * @return Mage_Index_Model_Indexer_Abstract
     */
    public function processEvent(Mage_Index_Model_Event $event)
    {
        $helper = Mage::helper('smile_elasticsearch');
        if ($helper->isEnterpriseSupportEnabled() == true) {
            return $this;
        }
        return parent::processEvent($event);
    }

    /**
     * (Dummy) Check if event can be matched by process
     *
     * @param Mage_Index_Model_Event $event Indexer event
     *
     * @return bool
     */
    public function matchEvent(Mage_Index_Model_Event $event)
    {
        $helper = Mage::helper('smile_elasticsearch');
        if ($helper->isEnterpriseSupportEnabled() == true) {
            return false;
        }
        return parent::matchEvent($event);
    }

    /**
     * Process event
     * Allows to partially update index for atomic changes
     *
     * @param Mage_Index_Model_Event $event Event to be indexed.
     *
     * @return void
     */
    protected function _processEvent(Mage_Index_Model_Event $event)
    {
        $data = $event->getNewData();

        if (!empty($data['catalogsearch_fulltext_reindex_all'])) {
            $this->reindexAll();
        } else if (!empty($data['catalogsearch_delete_product_id'])) {
            $productId = $data['catalogsearch_delete_product_id'];

            if (!$this->_isProductComposite($productId)) {
                $parentIds = $this->_getResource()->getRelationsByChild($productId);
                if (!empty($parentIds)) {
                    $this->updateProductDataAcrossIndexesFor($parentIds);
                }
            }

            $this->_getIndexer()
                ->cleanIndex(null, $productId)
                ->resetSearchResults();

        } else if (!empty($data['catalogsearch_update_product_id'])) {
            $productId = $data['catalogsearch_update_product_id'];
            $productIds = array($productId);

            if (!$this->_isProductComposite($productId)) {
                $parentIds = $this->_getResource()->getRelationsByChild($productId);
                if (!empty($parentIds)) {
                    $productIds = array_merge($productIds, $parentIds);
                }
            }
            $this->_getIndexer()->cleanIndex(null, $productIds);
            $this->updateProductDataAcrossIndexesFor($productIds);
            $this->_getIndexer()->resetSearchResults();

        } else if (!empty($data['catalogsearch_product_ids'])) {
            // mass action
            $productIds = $data['catalogsearch_product_ids'];
            $parentIds = $this->_getResource()->getRelationsByChild($productIds);
            if (!empty($parentIds)) {
                $productIds = array_merge($productIds, $parentIds);
            }

            if (!empty($data['catalogsearch_website_ids'])) {
                $websiteIds = $data['catalogsearch_website_ids'];
                $actionType = $data['catalogsearch_action_type'];

                if ($actionType === 'remove' || $actionType === 'add') {
                    $indexes = $this->getAllIndexesForType('product');
                    foreach ($indexes as $index) {
                        $scope = $index->getScope();
                        if (in_array($scope->getWebsiteId(), $websiteIds)) {
                            $indexer = $this->_getIndexer();
                            if ($actionType == 'remove') {
                                $indexer->cleanIndex($scope->getStoreId(), $productIds);
                            } else if ($actionType == 'add') {
                                $index->rebuildIndex($productIds);
                            }
                            $indexer->resetSearchResults();
                        }
                    }
                }
            }

            if (isset($data['catalogsearch_status'])) {
                $status = $data['catalogsearch_status'];
                if ($status == Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                    $this->updateProductDataAcrossIndexesFor($productIds);
                    $this->_getIndexer()->resetSearchResults();
                } else {
                    $this->_getIndexer()->cleanIndex(null, $productIds);
                    $this->updateProductDataAcrossIndexesFor($productIds);
                    $this->_getIndexer()->resetSearchResults();
                }
            }

            if (isset($data['catalogsearch_force_reindex'])) {
                $this->_getIndexer()->cleanIndex(null, $productIds);
                $this->updateProductDataAcrossIndexesFor($productIds);
                $this->_getIndexer()->resetSearchResults();
            }
        }
    }

    /**
     * @deprecated one must now use indexes directly (see `getAllIndexes()` for instance)
     */
    public function getCurrentIndex()
    {
        throw new LogicException('Outdated usage of \Smile_ElasticSearch_Model_Indexer_Fulltext::getCurrentIndex(). Please update your code accordingly.');
    }

    /**
     * Rebuild all index data
     *
     * @return void
     */
    public function reindexAll()
    {
        $indexes = $this->getAllIndexes();

        foreach ($indexes as $index) {
            $index->prepareNewIndex();
            $index->rebuildIndex();
            $index->installNewIndex();
        }
    }

    /**
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index[]
     */
    public function getAllIndexes()
    {
        return Mage::helper('catalogsearch')->getEngine()->getCurrentIndexesForScopes(
            Mage::helper('smile_elasticsearch')->getIndexScopes()
        );
    }

    /**
     * @param $type
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Index[]
     */
    public function getAllIndexesForType($type)
    {
        return Mage::helper('catalogsearch')->getEngine()->getCurrentIndexesForScopesAndType(
            Mage::helper('smile_elasticsearch')->getIndexScopes(),
            $type
        );
    }

    /**
     * @param array $productIds
     */
    private function updateProductDataAcrossIndexesFor(array $productIds)
    {
        $indexes = $this->getAllIndexesForType('product');
        foreach ($indexes as $index) {
            $index->rebuildIndex($productIds);
        }
    }
}
