<?php

define('WORDPRESS_MIGRATION_TOOLS_DIR', basename(dirname(__FILE__)));

if(WORDPRESS_MIGRATION_TOOLS_DIR != 'wordpressmigrationtools') {
	throw new Exception(
		"The Wordpress Migration Tools module must be in a directory named 'wordpressmigrationtools', not " . WORDPRESS_MIGRATION_TOOLS_DIR
	);
}