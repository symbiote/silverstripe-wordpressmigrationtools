<?php

/**
 * A basic example of how to write a custom Wordpress import task.
 */
class WordpressImportBasicTask extends BuildTask {
	/**
	 * @var WordpressDatabase
	 */
	protected $db = null;

	/**
	 * @var string
	 */
	protected $title = 'Wordpress Custom Import Task';

	/**
	 * @var string
	 */
	protected $description = 'Runs a custom Wordpress import';

	/**
	 * @var array
	 */
	private static $default_db = array(
		'database' 	 	 => 'wordpress-database',
		'username'		 => 'root',
		'password'		 => '',
		'table_prefix'   => 'wp'
	);

	/**
	 * The nav_menu to use for changing the SiteTree sort orders / parents.
	 *
	 * @var string
	 */
	private static $navigation_slug = '';

	/**
	 * The assets directory 
	 *
	 * @var string
	 */
	private static $assets_dir = "";

	/**
	 * @var array
	 */
	private static $dependencies = array(
		'wordpressImportService'		=> '%$WordpressImportService',
	);

	public function getTitle() {
		if ($this->class === __CLASS__) {
			return 'Wordpress Basic Import Task';
		} else {
			return $this->title;
		}
	}

	public function getDescription() {
		if ($this->class === __CLASS__) {
			return 'Runs a basic Wordpress import';
		} else {
			return $this->description;
		}
	}

	/**
	 * Disables task visibility and executability if its been extended.
	 * (So only the extended version appears)
	 *
	 * @return boolean
	 */
	public function isEnabled() {
		$subClasses = ClassInfo::subclassesFor(__CLASS__);
		if ($this->class === __CLASS__ && count($subClasses) > 1) {
			return false;
		} else {
			return $this->enabled;
		}
	}

	public function run($request) {
		if (!Director::is_cli() && !isset($_GET['run'])) {
			DB::alteration_message('Must add ?run=1', 'error');
			return false;
		}
		if (!Director::is_cli()) 
		{
			// - Add UTF-8 so characters will render as they should when debugging (so you can visualize inproperly formatted characters easier)
			// - Add base_tag so that printing blocks of HTML works properly with relative links (helps with visualizing errors)
?>
			<head>
				<?php echo SSViewer::get_base_tag(''); ?>
				<meta charset="UTF-8">
			</head>
<?php
		}
		increase_time_limit_to(300);

		WordpressDatabase::$default_config = $this->config()->default_db;
		$this->db = $this->wordpressImportService->getDatabase();

		// Login as default admin so 'canPublish()' definitely returns true in 'SiteTree::doPublish()'
		if (!Member::currentUser()) {
			$defaultAdmin = Member::default_admin();
			if ($defaultAdmin && $defaultAdmin->exists()) {
				Session::set('loggedInAs', $defaultAdmin->ID);
			}
		}

		// Unsure if the importing functionality can ever hit this, but just incase.
		if (Versioned::current_stage() !== 'Stage') {
			throw new Exception('Versioned::current_stage() must be "Stage".');
		}

		$this->runCustom($request);
	}

	public function runCustom($request) {
		$navSlug = $this->config()->navigation_slug;
		if ($navSlug === '') {
			try {
				// NOTE(Jake): If blank value is passed to 'updatePagesBasedOnNavMenu', it'll tell you the available menus with
				//			   an exception
				$this->wordpressImportService->updatePagesBasedOnNavMenu();
			} catch (WordpressImportException $e) {
				$this->wordpressImportService->log($e->toMessage(), 'error');
			}
			throw new WordpressImportException(__CLASS__.'::navigation_slug must be configured as either "false" or a menu');
		}
		$assetsDir = $this->config()->assets_dir;
		if ($assetsDir === '') {
			throw new WordpressImportException(__CLASS__.'::assets_dir must be configured as "false" or the local filepath of the WP assets. ie. C:\\wamp\\www\\SilverstripeExample\\assets\\WordpressUploads');
		}

		try {
			if ($assetsDir) {
				$this->wordpressImportService->importAttachmentsAsFiles($assetsDir);
			}
			
			// Import flat list of the pages
			$this->wordpressImportService->importPages();
			// Update the pages based on the Wordpress hierarchy
			$this->wordpressImportService->updatePagesBasedOnHierarchy();
			// This function expects URLSegment's to align with Wordpress site, so best to execute
			// before 'updatePagesBasedOnNavMenu'
			$this->wordpressImportService->fixPostContentURLs();
			$this->wordpressImportService->setHomepageToWordpressPageAndDeleteCurrentHomePage();
			if ($navSlug) {
				// Update page hierarchy based on a specific Wordpress navigation menu.
				$this->wordpressImportService->updatePagesBasedOnNavMenu($navSlug);
			}
			$this->wordpressImportService->importEvents_MyEventOn_Step1();
			try {
				// Requires Event Calendar module for this step
				$this->wordpressImportService->importEvents_MyEventOn_Step2();
			} catch (WordpressImportException $e) {
				$this->wordpressImportService->log($e->toMessage(), 'error');
			}
			try {
				// Import Gravity Forms as User Defined Forms.
				$this->wordpressImportService->importGravityForms();
			} catch (WordpressImportException $e) {
				$this->wordpressImportService->log($e->toMessage(), 'error');
			}
		} catch (Exception $e) {
			throw $e;
		}
	}
}