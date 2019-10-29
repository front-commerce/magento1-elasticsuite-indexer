<?php
/**
 * Data provider used to add product positions in virtual categories into the index
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Searchandising Suite to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile_VirtualCategories
 * @author    Romain Ruaud <romain.ruaud@smile.fr>
 * @copyright 2015 Smile
 * @license   Apache License Version 2.0
 */
class Smile_VirtualCategories_Model_Resource_Engine_Elasticsearch_Mapping_DataProvider_VirtualCategories_Position
    extends Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_DataProvider_Abstract
{
    /**
     * Retrieve custom position for products in virtual categories
     *
     * @param Smile_ElasticSearch_Model_Scope $scope
     * @param array $entityIds The entity ids
     *
     * @return array
     */
    public function getEntitiesData(Smile_ElasticSearch_Model_Scope $scope, $entityIds)
    {
        $result = array();

        $resourceModel    = Mage::getResourceModel("smile_virtualcategories/catalog_virtualCategory_product_position");
        $productsPosition = $resourceModel->getByProductIds($entityIds, $scope->getStoreId());

        // Init the field as empty to manage deletion of a previous custom position for products
        foreach ($entityIds as $entityId) {
            $result[(int) $entityId]["virtual_category_position"] = array();
        }

        // Populate matched products
        foreach ($productsPosition as $position) {
            $result[(int) $position["product_id"]]["virtual_category_position"][] = array(
                "virtual_category_id"       => (int) $position["category_id"],
                "category_product_position" => (int) $position["position"],
            );
        }

        return $result;
    }

    /**
     * Return custom mapping for data added by this provider
     *
     * @return array
     */
    public function getMappingProperties()
    {
        $mapping = array();

        $mapping['properties']['virtual_category_position'] = array(
            'type'       => 'nested',
            'properties' => array(
                'virtual_category_id'       => array('type' => 'long'),
                'category_product_position' => array('type' => 'long')
            )
        );

        return $mapping;
    }
}