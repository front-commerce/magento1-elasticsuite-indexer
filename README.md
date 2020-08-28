# Magento 1 ElasticSearch Indexer :

This module is a fork from [ElasticSuite for Magento 1](https://github.com/Smile-SA/smile-magento-elasticsearch) aimed at providing an ElasticSearch indexing for Magento 1 that is compatible with the latest [ElasticSuite for Magento 2](https://github.com/Smile-SA/elasticsuite) schema.\
We recommend that you use [OpenMage LTS](https://www.openmage.org/) for a version of Magento 1 that is still supported.

**DISCLAIMER: we don't plan to support querying the index from Magento 1's frontend. However, if you'd like to take ownership of this aspect we would be glad to add you as a maintainer. Please [contact us](engineering@front-commerce.com).**

_Status: This module is used in production by some [Front-Commerce / Magento 1](https://www.front-commerce.com/en/) projects_

## Installation

You can install the module using **composer**, **modman** or by copying manually the content of the `src/` directory in your Magento installation.

When installed, you can login in the admin panel to configure it.

## Configuration

The module can be configured from the **System > Configuration > Catalog > Catalog Search** page and section. You first need to make sure that the Search Engine param is set to Smile Serchandizing Suite.

Configure the ElasticSearch server and port, and save the settings.

You should then **reindex** your store, by running: `php shell/indexer.php reindexall` (or `n98 index:reindex:all`).

> **TIPS:** in case of issues, take a look at the **Troubleshooting** section below.

## Troubleshooting

Here is an overview of the most common errors and solutions for them.

### `FORBIDDEN/12/index read-only / allow delete (api)` error

This error could appear during a reindexation. There are 2 possible causes.

#### The index is in read-only mode

For some reasons, your index could be in read-only mode. In this case, you should reconfigure the index [as detailed here](https://discuss.elastic.co/t/forbidden-12-index-read-only-allow-delete-api/110282/5):

```shell
curl -XPUT "http://localhost:9200/_settings" -H'Content-Type: application/json' -d'
{
  "index": {
    "blocks": {
      "read_only_allow_delete": "false"
    }
  }
}'
```

#### Not enough remaining space on disk

Another reason could be that your hard drive has not enough space left on the device (~ <10 Go). The solution is… to make some space on it!

Try to empty your trash, remove unused docker images (`docker image prune -a`) or other things like that! Note: [`ncdu`](https://dev.yorhel.nl/ncdu) is your friend.

### `Fatal error: Uncaught Error: Call to undefined method Mage_CatalogSearch_Model_Resource_Fulltext_Engine::getCurrentIndexesForScopes()`

There is a known unclear error message, that looks like the error below:

```
Fatal error: Uncaught Error: Call to undefined method Mage_CatalogSearch_Model_Resource_Fulltext_Engine::getCurrentIndexesForScopes() in /var/www/.modman/magento1-elasticsuite-indexer/src/app/code/community/Smile/ElasticSearch/Model/Indexer/Fulltext.php:189
Stack trace:
#0 /var/www/.modman/magento1-elasticsuite-indexer/src/app/code/community/Smile/ElasticSearch/Model/Indexer/Fulltext.php(175): Smile_ElasticSearch_Model_Indexer_Fulltext->getAllIndexes()
#1 /var/www/htdocs/app/code/core/Mage/Index/Model/Process.php(212): Smile_ElasticSearch_Model_Indexer_Fulltext->reindexAll()
#2 /var/www/htdocs/app/code/core/Mage/Index/Model/Process.php(260): Mage_Index_Model_Process->reindexAll()
#3 phar:///usr/local/bin/n98/src/N98/Magento/Command/Indexer/AbstractIndexerCommand.php(218): Mage_Index_Model_Process->reindexEverything()
#4 phar:///usr/local/bin/n98/src/N98/Magento/Command/Indexer/AbstractIndexerCommand.php(187): N98\Magento\Command\Indexer\AbstractIndexerCommand->executeProcess(Object(Symfony\Component\Console\Output\Console in /var/www/.modman/magento1-elasticsuite-indexer/src/app/code/community/Smile/ElasticSearch/Model/Indexer/Fulltext.php on line 189
```

The error is triggered when **your ElasticSearch configuration is incorrect**. The solution is to double-check your settings in admin. If the problem persists, please contact us.

> **Note:** the error is because in such situation `\Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch::getStatus()` returns `false` and our code doesn't handle this gracefully yet. We should throw an exception with a clearer message for developers. Hopefully in an upcoming version! Feel free to send us a PR for this.

## License

[Apache-2.0](./LICENSE)