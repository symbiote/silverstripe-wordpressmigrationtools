Wordpress Migration Tools
====================================

A Wordpress Importer that handles various operations:
- Import Pages (WordpressImportService::importPages)
- Update imported pages to have same Wordpress page hierarchy (WordpressImportService::updatePagesBasedOnHierarchy)
- Fix $Content or Elemental's ElementContent $HTML to point assets to local URLs or shortcodes as appropriate (WordpressImportService::fixPostContentURLs)
- Update Home page to match Wordpress home page (WordpressImportService::setHomepageToWordpressPageAndDeleteCurrentHomePage)
- Update imported pages to have match the provided Wordpress menu hierarchy (WordpressImportService::updatePagesBasedOnNavMenu)
- Import Gravity Forms data as UserDefinedForm pages (WordpressImportService::importGravityForms)

## Quick Start

- Configure WordpressImportBasicTask items in in YML
```yaml
---
Name: wordpress_import
After:
  - 'framework/*'
  - 'cms/*'
---
WordpressImportBasicTask:
  default_db:
    database: 'my-wordpress-db'
    username: 'root'
    password: ''
    table_prefix: 'wp'
  navigation_slug: 'top-navigation'
```
- Run "Wordpress Basic Import Task" from /dev/tasks

## Modifying/Extending the importer

This Wordpress importer is designed to allow you to take the functionality you want to use specifically and then easily tack your own logic on top. The simplest way to do this is to create your own task that extends 'WordpressImportBasicTask' and override the 'runCustom' function. From here you can either choose to call 'parent::runCustom()' or copy-paste the seperate import function calls and comment out what you don't need.

```php
<?php
class WordpressTask extends WordpressImportBasicTask {
	public function runCustom($request) {
		/**
		 * Uncomment this or copy-paste the various functions called in the 
		 * parent into this and comment out what you don't need.
		 */
		//parent::runCustom($request);

		try {
			// Overriden to just import a flat list of pages
			$this->wordpressImportService->importPages();
			// ... and fix the content href's/src's
			$this->wordpressImportService->fixPostContentURLs();
		} catch (Exception $e) {
			throw $e;
		}
	}
```