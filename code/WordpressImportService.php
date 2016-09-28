<?php 

class WordpressImportService extends Object {

	/**
	 * @var WordpressDatabase
	 */
	public $_db = null;

	/** 
	 * The function to call on 'setContentOnRecord'.
	 *
	 * @var string
	 */
	public $_content_set_callback = 'setSiteTreeContent';

	/** 
	 * The function to call on 'getContentFromRecord'.
	 *
	 * @var string
	 */
	public $_content_get_callback = 'getSiteTreeContent';

	/** 
	 * @var array
	 */
	public $_classes_using_elemental = array();

	/** 
	 * @var array
	 */
	public $_classes_using_wordpress_extension = array();

	/**
	 * The root parent ID, this can be changed so that pages are put underneath
	 * Multisite/Subsite/etc objects when they belong at the top level
	 */
	public $root_parent_id = 0;

	/**
	 * Apply the filter to get Wordpress imported items by.
	 *
	 * @return SS_List
	 */
	public function applyWordpressFilter(SS_List $list, $wordpressTable = 'posts') {
		$list = $list->filter(array(
			'WordpressID:not' => 0,
			'WordpressTable'  => $wordpressTable,
		));
		return $list;
	}

	/**
     * Write and publish a record
     *
     * @return boolean
     */
    public function writeAndPublishRecord($record, $publishIfTrue = true) {
        $isExisting = $record->exists();
        try {
            $record->write();
            $this->log($record, $isExisting ? 'changed' : 'created');
            if ($publishIfTrue) {
                if ($record->hasMethod('doPublish')) {
                    if ($record->doPublish() === false) {
                        throw new Exception('Unable to publish #'.$record->ID);
                    }
                    $this->log($record, 'published');
                } else if ($record->hasMethod('publish')) {
                    $record->publish('Stage', 'Live');
                    $this->log($record, 'published');
                }
            }
            return true;
        } catch (Exception $e) {
            throw $e;
        }
        return false;
    }

	/**
	 * Import Wordpress post data as Silverstripe pages.
	 *
	 * If a record exists and the data no longer matches the Wordpress DB, then 
	 * the record will be updated to the WP DB.
	 *
	 * @param $arrayOfWpPostData Array of Wordpress Data
	 * @return void
	 */
	public function importPostsAsPages($arrayOfWpPostData = null) {
		$this->logFunctionStart(__FUNCTION__, func_get_args());
		if (!$arrayOfWpPostData) {
			// If passed in data and its empty, throw exception
			throw new WordpressImportException('First parameter passed into '.__FUNCTION__.' is empty.');
		}

		$existingWpRecords = singleton('Page')->WordpressRecordsByWordpressID();
		foreach ($arrayOfWpPostData as $wpData) {
			$wpID = $wpData['ID'];

			$record = null;
			if (isset($existingWpRecords[$wpID])) {
				$record = $existingWpRecords[$wpID];
			} else {
				$record = Page::create();
			}

			if ($record)
			{
				$wpMeta = $this->_db->attachAndGetPostMeta($wpData);

				$record->Title = $this->_db->process('post_title', $wpData['post_title']);
				// todo(Jake): use home constant/var in SS framework
				if ($record->URLSegment !== 'home') {
					$record->URLSegment = $wpData['post_name'];
				}
				$record->Created = $wpData['post_date'];
				$record->WordpressData = $wpData;

				if (!$record->exists())
				{
					$record->LastEdited = $wpData['post_modified'];
					$record->ShowInMenus = 0;
					// Set 'Sort' to a high number so everything can be shuffled to its Wordpress position
					// later.
					$record->Sort = 9000000;
					$this->setContentOnRecord($record, $wpData['post_content']);
					try {
						$isPublished = (isset($wpData['post_status']) && $wpData['post_status'] === 'publish');
						$this->writeAndPublishRecord($record, $isPublished);
					} catch (Exception $e) {
						$this->log($record, 'error', $e);
						throw $e;
					}
				}
				else
				{
					$this->setContentOnRecord($record, $wpData['post_content']);
					if ($changedFields = $record->getChangedFields(true, DataObject::CHANGE_VALUE))
					{
						try {
							$isPublished = ((isset($wpData['post_status']) && $wpData['post_status'] === 'publish') || $record->isPublished());
							$this->writeAndPublishRecord($record, $isPublished);
						} catch (Exception $e) {
							$this->log($record, 'error', $e);
							throw $e;
						}
					}
					else
					{
						$this->log($record, 'nochange');
					}
				}
			}
		}
		$this->logFunctionEnd(__FUNCTION__);
	}

	/**
	 * Import all Wordpress 'page' post_type items as Silverstripe
	 * pages.
	 *
	 * @return void
	 */
	public function importPages() {
		$this->logFunctionStart(__FUNCTION__);
		$this->importPostsAsPages($this->_db->getPages());
		$this->logFunctionEnd(__FUNCTION__);
	}

	/**
	 * Import Gravity Form as User Defined Form
	 */
	public function importGravityForms() {
		if (!class_exists('UserFormFieldEditorExtension')) {
			throw new WordpressImportException(__FUNCTION__.' requires User Defined Forms 3.0+');
		}
		//$list = ElementContent::get()->filter(array('HTML:PartialMatch' => '[gravityform'));

		// Get existing gravity form items
		$existingWpRecords = array();
		$list = $this->applyWordpressFilter(UserDefinedForm::get(), array('posts', 'rg_form'));
		foreach ($list as $record) {
			if (isset($record->WordpressData['gravityform'])) {
				// Handle case where a Page was the only one using a Gravity Form, so
				// the gravity form data was just attached to that existing data.
				$gfData = $record->WordpressData['gravityform'];
				if (!isset($gfData['id'])) {
					throw new Exception('Missing "id" in "gravityform" data on #'.$record->ID.' in its "WordpressData" column.');
				}
				$id = $gfData['id'];
				$existingWpRecords[$id] = $record;
			} else {
				// Handle case where Gravity form was used in multiple locations, wherein
				// a new page was created.
				$existingWpRecords[$record->WordpressID] = $record;
			}
		}

		$gfDB = new WordpressGravityForms($this->_db);
		foreach ($gfDB->getForms() as $gfData) {
			$gfID = (int)$gfData['id'];
			if ($existingWpRecords && isset($existingWpRecords[$gfID])) {
				// Skip existing imported gravity forms
				$this->log($record, 'nochange');
				continue;
			}
			$gfMeta = $gfDB->attachAndGetFormMeta($gfData);
			if (!isset($gfMeta['display_meta']['fields'])) {
				continue;
			}

			// Create array of EditableFormField's
			$fields = array();
			$fieldsData = $gfMeta['display_meta']['fields'];
			foreach ($fieldsData as $i => $fieldData) {
				if (!isset($fieldData['type'])) {
					throw new WordpressImportException('Gravity Form field is missing "type"');
				}
				$type = $fieldData['type'];
				$label = isset($fieldData['label']) ? $fieldData['label'] : null;
				$field = null;
				switch ($type)
				{
					case 'textarea':
						$field = EditableTextField::create();
						$field->Rows = 4;
					break;

					case 'name':
					case 'text':
					case 'phone':
						$field = EditableTextField::create();
					break;

					case 'email':
						$field = EditableEmailField::create();
					break;

					case 'number':
						$field = EditableNumericField::create();
						$field->MinValue = isset($fieldData['rangeMin']) ? $fieldData['rangeMin'] : 0;
						$field->MaxValue = isset($fieldData['rangeMax']) ? $fieldData['rangeMax'] : 0;
					break;

					case 'radio':
						$field = EditableRadioField::create();
					break;

					case 'checkbox':
						$choices = isset($fieldData['choices']) ? $fieldData['choices'] : false;
						if (!$choices) {
							throw new WordpressImportException('Cannot find "choices" on '.$type);
						}

						if (count($choices) == 1)
						{
							$field = EditableCheckbox::create();
							foreach ($choices as $choiceData)
							{
								$label = $choiceData['text'];
								break;
							}
						}
						else
						{
							$field = EditableCheckboxGroupField::create();
						}
					break;

					case 'select':
						$field = EditableDropdown::create();
					break;

					case 'captcha':
						// No Captcha field comes with User Defined Forms.
						$field = false;
					break;

					case 'html':
						// Ignore literal field
						$field = EditableLiteralField::create();
						$field->Content = isset($fieldData['content']) ? $fieldData['content'] : '';
					break;

					default:
						//Debug::dump($fieldData);
						throw new WordpressImportException('Gravity Form field is unhandled type "'.$type.'".');
					break;
				}
				if ($field === null)
				{
					throw new WordpressImportException('Gravity Form field is mishandled type "'.$type.'" $field isn\'t set.');
				}
				if ($field)
				{
					/*$descriptionPlacement = isset($fieldData['descriptionPlacement']) ? $fieldData['descriptionPlacement'] : 'above';
					if ($descriptionPlacement === 'above') {
						$field->Title = $label;
					} else if ($descriptionPlacement === 'below') {
						$field->RightTitle = $label;
					} else {
						throw new WordpressImportException('Invalid "descriptionPlacement" value "'.$descriptionPlacement.'"');
					}*/
					$field->Title = $label;
					$field->Placeholder = isset($fieldData['placeholder']) ? $fieldData['placeholder'] : null;
					$field->CustomErrorMessage = isset($fieldData['errorMessage']) ? $fieldData['errorMessage'] : null;
					$field->Required = isset($fieldData['isRequired']) ? $fieldData['isRequired'] : false;
					$field->Sort = $i + 1;

					$choices = isset($fieldData['choices']) ? $fieldData['choices'] : false;
					if ($choices && $field->hasMethod('Options')) {
						foreach ($choices as $choiceData) {
							$choice = EditableOption::create();
							$choice->Title = $choiceData['value'];
							$field->Options()->add($choice);
						}
					}

					$fields[] = $field;
				}
			}

			// Get existing page record if only a single page is using a gravity form.
			$oneGravityFormPageRecord = null;
			$pageContent = null;
			if (isset($this->_classes_using_elemental['SiteTree']) || isset($this->_classes_using_elemental['Page'])) {
				$list = ElementContent::get()->filter(array('HTML:PartialMatch' => '[gravityform id="'.$gfID.'"'));
				$list = $list->toArray();
				if (count($list) == 1) {
					$elementContent = $list[0];
					$oneGravityFormPageRecord = $elementContent->Parent();
					$pageContent = $elementContent->HTML;
				}
			} else {
				$list = SiteTree::get()->filter(array('Content:PartialMatch' => '[gravityform id="'.$gfID.'"'));
				$list = $list->toArray();
				if (count($list) == 1) {
					$oneGravityFormPageRecord = $list[0];
					$pageContent = $oneGravityFormPageRecord->Content;
				}
			}
			if (substr_count($pageContent, '[gravityform') > 1) {
				// If two gravity forms exist on the single page, don't make it write the UserDefinedForm
				// to an existing page.
				$oneGravityFormPageRecord = null;
			}
			/*if ($oneGravityFormPageRecord && $existingWpRecords && isset($existingWpRecords[$oneGravityFormPageRecord->WordpressID])) {
				// Skip existing imported gravity forms
				$this->log($record, 'nochange');
				continue;
			}*/

			// Create UDF
			if ($oneGravityFormPageRecord) {
				// If only one page is using a Gravity Form, transform it into a UserDefinedForm page.
				$record = $oneGravityFormPageRecord->newClassInstance('UserDefinedForm');
				$wordpressData = $record->WordpressData;
				$wordpressData['gravityform'] = $gfData;
				$record->WordpressData = $wordpressData;
			} else {
				// If multiple pages are using the same Gravity Form, just create the UserDefinedForm page.
				$record = UserDefinedForm::create();
				if (!isset($gfData['title'])) {
					throw new WordpressImportException('Gravity Form missing "title" field.');
				}
				$record->Title = $gfData['title'];
				if (!isset($gfData['date_created'])) {
					throw new WordpressImportException('Gravity Form missing "date_created" field.');
				}
				$record->Created = $gfData['date_created'];
				$record->WordpressData = $gfData;
			}
			foreach ($fields as $field) {
				$record->Fields()->add($field);
			}
			//Debug::dump($record->toMap()); 
			try {
				$isPublished = ((isset($gfData['is_active']) && $gfData['is_active']) || $record->isPublished());
				$this->writeAndPublishRecord($record, $isPublished);
			} catch (Exception $e) {
				$this->log($record, 'error', $e);
				throw $e;
			}
		}
	}

	/**
	 *
	 */
	public function importAttachmentsAsFiles($directory = null) {
		// todo(Jake): make $directory use relative to the SS/basedir rather than full filepath
		$this->logFunctionStart(__FUNCTION__);
		if ($directory === null) {
			throw new Exception('Must provide a $directory parameter');
		}
		$directorIsCLI = Director::is_cli();

		$folderMap = array();

		$existingWpIDs = singleton('File')->WordpressIDsMap();

		$fileResolver = new WordpressAttachmentFileResolver($directory, getTempFolder());
		if (!$fileResolver->getFilesRecursive()) {
			$this->log('No files found recursively in '.$fileResolver->directory);
			$this->logFunctionEnd(__FUNCTION__);
			return;
		}
		$basePath = Director::baseFolder().DIRECTORY_SEPARATOR;
		$baseAssetPath = $basePath.ASSETS_DIR.DIRECTORY_SEPARATOR;

		$this->setupDefaultDatabaseIfNull();
		$attachments = $this->_db->getAttachments();

		foreach ($attachments as $wpData)
		{
			$wpID = $wpData['ID'];
			if (isset($existingWpIDs[$wpID]))
			{
				//$this->log('File (Wordpress ID: '.$wpID.') already imported.');
				continue;
			}

			$wpMeta = $this->_db->attachAndGetAttachmentMeta($wpData);
			if (isset($wpMeta['_wp_attached_file']))
			{
				$filepaths = $fileResolver->getFilepathsFromRecord($wpData);
				if (!$filepaths) 
				{
					$this->log('Unable to find matching file for "'.$wpMeta['_wp_attached_file'] . '"" database entry (Wordpress ID: '.$wpData['ID'].')', 'error');
					continue;
				}

				// Check each filepath and see what year/month pattern matches the current Wordpress attachment
				$yearMonthFile = $fileResolver->extractYearAndMonth($wpMeta['_wp_attached_file']);
				if (!$yearMonthFile) {
					throw new Exception('Doubled up basename and unable to determine year/month from _wp_attached_file postmeta.');
				}
				$chosenFilepath = null;
				foreach ($filepaths as $filepath) {
					$checkYearMonthAgainst = $fileResolver->extractYearAndMonth($filepath);
					if ($yearMonthFile === $checkYearMonthAgainst) {
						$chosenFilepath = $filepath;
					}
				}
				if ($chosenFilepath === null) {
					$errorString = "\n    - ".implode("\n    - ", $filepaths);
					$errorString = 'Unable to find EXACT matching file for "'.$wpMeta['_wp_attached_file'] . '"" database entry (Wordpress ID: '.$wpData['ID'].') -- Possible paths were: '.$errorString;
					if (!$directorIsCLI) {
						$errorString = nl2br($errorString);
					}
					$this->log($errorString, 'notice');
					continue;
				}

				// $chosenFilepath = Full filename (ie. C:/wamp/www/MySSSite)
				$relativeFilepath = str_replace($basePath, '', $chosenFilepath);
				if ($relativeFilepath === $chosenFilepath) {
					throw new Exception('Wordpress assets must be moved underneath your Silverstripe assets folder.');
				}

				// Convert from Windows backslash to *nix forwardslash
				$relativeFilepath = str_replace("\\", '/', $relativeFilepath);

				// Add S3 CDN path to the record
				$cdnFile = '';
				if (isset($wpMeta['amazonS3_info']) && isset($wpMeta['amazonS3_info']['key'])) {
					$cdnFile = 'Cdn:||' . $wpMeta['amazonS3_info']['key'];
				}

				// Feed record data into the object directly
				$recordData = array(
					'Title' => $wpData['post_title'],
					'Created'  => $wpData['post_date'],
					'LastEdited' =>  $wpData['post_modified'],
					'Content' => $wpData['post_content'],
					'Description' => $wpData['post_content'], // Support SSAU/ba-sis (https://github.com/silverstripe-australia/silverstripe-ba-sis)
					'CDNFile' => $cdnFile, // Support SSAU/cdncontent module (https://github.com/silverstripe-australia/silverstripe-cdncontent)
				);
				if ($fileResolver->isFileExtensionImage($relativeFilepath)) {
					$record = Image::create($recordData);
				} else {
					$record = File::create($recordData);
				}
				$record->Filename = $relativeFilepath;

				// Determine folder to save to
				$relativeDirectoryInsideAssets = str_replace(array($baseAssetPath, '\\'), array('', '/'), $chosenFilepath);
				$relativeDirectoryInsideAssets = dirname($relativeDirectoryInsideAssets);
				if (!isset($folderMap[$relativeDirectoryInsideAssets])) {
					// Using this to speed-up the lookup on already found or made directories
					$folderMap[$relativeDirectoryInsideAssets] = Folder::find_or_make($relativeDirectoryInsideAssets);
				}
				$folder = $folderMap[$relativeDirectoryInsideAssets];
				if (!$folder || !$folder->ID) {
					throw new Exception('Unable to determine or create Folder at: '.$relativeDirectoryInsideAssets);
				}
				$record->ParentID = $folder->ID;

				try {
					$record->WordpressData = $wpData;
					$record->write();
					$this->log('Added "'.$record->Title.'" ('.$record->class.') to #'.$record->ID, 'created');
				} catch (Exception $e) {
					//Debug::dump($relativeFilepath);
					//Debug::dump($e->getMessage()); exit;
					$this->log('Failed to write #'.$record->ID. ' ('.$record->class.', Wordpress ID: '.$wpData['ID'].') -- '.$e->getMessage(), 'error');
				}
			}
		}
		$this->logFunctionEnd(__FUNCTION__);
	}

	/**
	 * Update all pages to have the same parents they have in Wordpress.
	 * This ensures the URLSegment paths will be close/same as the Wordpress site.
	 */
	public function updatePagesBasedOnHierarchy() {
		$this->logFunctionStart(__FUNCTION__);
		$wordpressIDsToSilverstripeIDs = singleton('SiteTree')->WordpressIDToSilverstripeIDMap();
		$list = $this->applyWordpressFilter(SiteTree::get());
		foreach ($list as $record)
		{
			$wordpressParentID = $record->getField('WordpressParentID');

			if (isset($wordpressIDsToSilverstripeIDs[$wordpressParentID])) {
				$ssID = $wordpressIDsToSilverstripeIDs[$wordpressParentID];
				if ($record->ParentID != $ssID) {
					$record->ParentID = $ssID;
					try {
						$this->writeAndPublishRecord($record);
					} catch (Exception $e) {
						$this->log($record, 'error', $e);
					}
				}
			}
			else
			{
				$this->log($record, 'nochange');
			}
		}
		$this->logFunctionEnd(__FUNCTION__);
	}

	/**
	 * Import events created with: http://www.myeventon.com/
	 * into a 'CalendarEvent' Page type from Unclecheese's "Event Calendar" module
	 *
	 * Step 1) Import the post types as basic "Page" page types.
	 */
	public function importEvents_MyEventOn_Step1() {
		$this->logFunctionStart(__FUNCTION__);
		$arrayOfWpData = $this->_db->getPosts('ajde_events');
		if (!$arrayOfWpData) {
			$this->logFunctionEnd(__FUNCTION__);
			return;
		}

		// Import 'adje_events' loosely
		$this->importPostsAsPages($arrayOfWpData);

		$this->logFunctionEnd(__FUNCTION__);
	}

	/**
	 * Import events created with: http://www.myeventon.com/
	 * into a 'CalendarEvent' Page type from Unclecheese's "Event Calendar" module
	 *
	 * Step 2) Update the page to 'CalendarEvent' page type and add event data appropriately
	 */
	public function importEvents_MyEventOn_Step2($calendarHolder = null) {
		$this->logFunctionStart(__FUNCTION__);
		if (!class_exists('CalendarDateTime')) {
			throw new WordpressImportException(__FUNCTION__.' requires Unclecheese\'s "Event Calendar" module');
		}
		if (!CalendarDateTime::has_extension('WordpressImportDataExtension')) {
			throw new WordpressImportException('CalendarDateTime requires WordpressImportDataExtension.');
		}

		// Debug
		//Debug::dump($this->_db->getPosts('ajde_events', true)); exit;

		// Get the calendar holder the events should belong to.
		if ($calendarHolder === null)
		{
			$calendarHolder = Calendar::get()->filter(array(
				'WordpressData' => '1',
			))->first();
			if (!$calendarHolder)
			{
				$calendarHolder = Calendar::create();
				$calendarHolder->Title = 'Wordpress Imported Events';
				$calendarHolder->URLSegment = 'events';
				$calendarHolder->WordpressData = 1;
				try {
					$this->writeAndPublishRecord($calendarHolder);
				} catch (Exception $e) {
					$this->log($calendarHolder, 'error', $e);
				}
			}
		}

		// Convert to CalendarEvent and attach relevant event data
		$existingWpRecords = singleton('Page')->WordpressRecordsByWordpressID();
		foreach ($this->_db->getPosts('ajde_events') as $wpData) {
			$wpID = $wpData['ID'];

			if (!isset($existingWpRecords[$wpID])) {
				$this->log('Unable to find Wordpress ID #'.$wpID, 'error');
				continue;
			}
			$record = $existingWpRecords[$wpID];
			$wpMeta = $this->_db->attachAndGetPostMeta($wpData);
			if ($record->ClassName !== 'CalendarEvent') {
				$record = $record->newClassInstance('CalendarEvent');
			}
			$record->ParentID = $calendarHolder->ID;

			$startDate = (isset($wpMeta['evcal_srow']) && $wpMeta['evcal_srow']) ? (int)$wpMeta['evcal_srow'] : null;
			$endDate = (isset($wpMeta['evcal_erow']) && $wpMeta['evcal_erow']) ? (int)$wpMeta['evcal_erow'] : null;
			if ($startDate && $endDate) {
				$subRecord = null;
				if ($record->exists()) {
					$subRecord = $record->DateTimes()->find('WordpressID', $wpID);
				}
				if (!$subRecord) {
					$subRecord = CalendarDateTime::create();
				}
				$subRecord->AllDay 	  = (isset($wpMeta['evcal_allday']) && $wpMeta['evcal_allday'] === 'yes');
				$subRecord->StartDate = date('Y-m-d', $startDate);
				$subRecord->StartTime = date('H:i:s', $startDate);
				$subRecord->EndDate   = date('Y-m-d', $endDate);
				$subRecord->EndTime   = date('H:i:s', $endDate);
				$subRecord->WordpressData = $record->WordpressData;
				if (!$subRecord->exists()) {
					// NOTE(Jake): Will write $subRecord when $record is written if not exists, otherwise
					//			   it will write it when it's ->add()'d
					$record->DateTimes()->add($subRecord);
				} else {
					try {
						$record->write();
						$this->log($record, 'changed');
					} catch (Exception $e) {
						$this->log($record, 'error', $e);
					}
				}
			}

			// Support Addressable extension from Addressable module
			if (isset($wpMeta['evcal_location'])) {
				if ($record->Address !== $wpMeta['evcal_location']) {
					$record->Address = $wpMeta['evcal_location'];
				}
			}
			// Support Geocodable extension from Addressable module
			if (isset($wpMeta['evcal_lat'])) {
				$record->Lat = $wpMeta['evcal_lat'];
			}
			if (isset($wpMeta['evcal_lng'])) {
				$record->Lng = $wpMeta['evcal_lng'];
			}

			$changedFields = $record->getChangedFields(true, DataObject::CHANGE_VALUE);
			unset($changedFields['Lat']);
			unset($changedFields['Lng']);
			if ($changedFields) {
				try {
					$isPublished = ((isset($wpData['post_status']) && $wpData['post_status'] === 'publish') || $record->isPublished());
					$this->writeAndPublishRecord($record, $isPublished);
				} catch (Exception $e) {
					$this->log($record, 'error', $e);
				}
			} else {
				$this->log($record, 'nochange');
			}
		}
		$this->logFunctionEnd(__FUNCTION__);
	}

	/**
	 * Basically works like SiteTree::get_by_link but is capable of accounting for Multisites.
	 *
	 * @return SiteTree
	 */
	public function getByLink($link, $findOldPageFallback = true) {
		$prefix = '';
		if (class_exists('Multisites')) {
			$site = Multisites::inst()->getCurrentSite();
			if ($site) {
				$prefix = $site->URLSegment.'/';
			}
		}
		$link = trim($link, '/');
		$linkDirParts = explode('/', $link);

		$result = SiteTree::get_by_link($prefix.$link.'/');
		if ($result) {
			// Check if URLSegment matches the last part of the URL, as get_by_link
			// will return the parent page if there's no match.
			if ($result->URLSegment && $result->URLSegment === end($linkDirParts)) {
				return $result;
			}
			return false;
		}
		if ($findOldPageFallback) {
			$url = OldPageRedirector::find_old_page($linkDirParts);
			if ($url) {
				$result = $this->getByLink($url, false);
				return $result;
			}
		}
		return false;
	}

	/**
	 * 
	 */
	public function fixPostContentURLs() {
		$this->logFunctionStart(__FUNCTION__, func_get_args());

		// Get assets
		$fileOldURLtoNewURL = array();
		$fileOldURLtoID = array();
		$files = $this->applyWordpressFilter(File::get());
		foreach ($files as $record) {
			// ie. 'assets/WordpressUploads/2014/12/1.jpg'
			// or  'assets/WordpressUploads/2014/12/01235537/1.jpg'	
			$filename = $record->Filename;
			$filename = WordpressAttachmentFileResolver::extract_all_after_year($filename);

			$relativeLink = substr($record->Link(), 1);
			$fileOldURLtoNewURL[$filename] = $relativeLink;
			$fileOldURLtoID[$filename] = $record->ID;

			// If AmazonS3 style: '2014/12/01235537/1.jpg'
			$dirParts = explode('/', $filename);
			if (isset($dirParts[3])) {
				// Transform '2014/12/01235537/1.jpg'
				// to: '2014/12/1.jpg'
				// This handles the edge cases where Wordpress references how the assets
				// were stored, pre-AmazonS3 migration.
				unset($dirParts[2]);
				$filename = implode('/', $dirParts);
				$fileOldURLtoNewURL[$filename] = $relativeLink;
				$fileOldURLtoID[$filename] = $record->ID;
			}
		}

		// todo(Jake): Allow for setting a manual siteurl with configs that takes precedence
		//			   over this. Wordpress allows such behaviour with its define() configs.
		$wordpressSiteURL = $this->_db->getOption('siteurl');
		if (!$wordpressSiteURL) {
			throw new Exception('Unable to determine "siteurl" from options table.');
		}

		//
		$existingWpRecords = $this->applyWordpressFilter(Page::get());
		foreach ($existingWpRecords as $record) {
			$content = $this->getContentFromRecord($record);
			if (!$content) {
				continue;
			}
			$newContent = WordpressUtility::modify_html_attributes($content, function(&$attributes) use ($record, $fileOldURLtoNewURL, $fileOldURLtoID, $wordpressSiteURL) {
				/**
				 * @var $htmlAttr WordpressHTMLAttribute
				 */
				foreach ($attributes as $key => $htmlAttr) {
					$attrName = $htmlAttr->name;
					$attrValue = $htmlAttr->value;
					$isAssetURL = false;

					if ($wordpressSiteURL && strpos($attrValue, $wordpressSiteURL) !== FALSE) {
						// Replace any 'www.mywordpress.com' href's with a shortcode
						$relativeLink = str_replace($wordpressSiteURL, '', $attrValue);
						$page = $this->getByLink($relativeLink);
						if ($page) {
							$htmlAttr->value = '[sitetree_link,id='.$page->ID.']';
							//$this->log('Record #'.$record->ID.' - Mismatching URLSegment depth - Unable to make URL relative on attribute "'.$attrName.'": '.$attrValue, 'error', null, 1);
						} else {
							$filename = WordpressAttachmentFileResolver::extract_year_and_month($attrValue);
							if ($filename) {
								// If able to extract a year/month/etc, then it must be an asset
								$isAssetURL = true;
							} else {
								$this->log('Record #'.$record->ID.' - Unable to make URL relative on attribute "'.$attrName.'": '.$attrValue, 'error', null, 1);
							}
						}
					}
					$isAssetURL = $isAssetURL || (strpos($attrValue, '.amazonaws.com/') !== FALSE);
					if ($isAssetURL) {
						// Replace:
						// 'http://cdn.namespace.com.au.s3-ap-southeast-2.amazonaws.com/wp-content/uploads/2014/01/21090055/Read-the-transcript-here2.pdf'
						// With:
						// 'assets/WordpressUploads/2014/01/21090055/Read-the-transcript-here2.pdf'
						$dimensions = array();
						$filename = WordpressAttachmentFileResolver::extract_all_after_year($attrValue, $dimensions);
						if (!isset($fileOldURLtoNewURL[$filename])) {
							// Handle edge case where user uploads a file with pattern 'myname-300x300.jpg'
							// Subsequent thumbnails in Wordpress save as: 'myname-600x900-300x200.jpg'
							$filename = WordpressAttachmentFileResolver::extract_all_after_year($attrValue);
						}

						if (!isset($fileOldURLtoNewURL[$filename]) || !isset($fileOldURLtoID[$filename])) {
							$this->log("Record #".$record->ID." - Cannot find file: ".$filename, 'error', null, 1);
							continue;
						}
					
						$relativeLink = $fileOldURLtoNewURL[$filename];
						if ($htmlAttr->name === 'href') {
							// For 'href' links, put it into a format Silverstripe 3.2 will expect.
							$relativeLink = '[file_link,id='.$fileOldURLtoID[$filename].']';
						}
						if ($attrValue !== $relativeLink) {
							$htmlAttr->value = $relativeLink;
						}
					}
				}
			});
			if ($content !== $newContent) {
				try {
					$this->setContentOnRecord($record, $newContent);
					$this->log($record, 'changed');
				} catch (Exception $e) {
					$this->log($record, 'error', $e);
				}
			} else {
				$this->log($record, 'nochange');
			}
		}
		$this->logFunctionEnd(__FUNCTION__);
	}

	/**
	 * Normally to be called after 'updatePagesBasedOnHierarchy', basically updates the ParentID/Sort of pages
	 * based on how they're structured for the given menu. 
	 *
	 * (Silverstripe doesn't seperate its menu and page-structure like Wordpress)
	 */
	public function updatePagesBasedOnNavMenu($menu_slug = '') {
		if (!$menu_slug) {
			// If first parameter left blank, throw exception showing the developer what menu slugs they can provide.
			$menuItems = '';
			foreach ($this->_db->getNavMenuTypes() as $navMenuType) {
				$menuItems .= $navMenuType['name'].' (slug: '.$navMenuType['slug'].')'.", \n";
			}
			throw new WordpressImportException("Must provide menu slug for ".__FUNCTION__."($menu_slug).\nAvailable menus:\n".$menuItems);
		}
		$this->logFunctionStart(__FUNCTION__);
		$wpNavMenuItems = $this->_db->getNavMenuItems($menu_slug);
		if (!$wpNavMenuItems)
		{
			throw new WordpressImportException("Bad menu slug. Either invalid or does not contain any menu items.");
		}

		// Make all non-Wordpress pages no longer show in menu.
		$list = SiteTree::get()->filter(array(
			'WordpressID' => 0, 
			'ShowInMenus' => 1,
		));
		foreach ($list as $record) {
			$record->ShowInMenus = 0;
			$record->WordpressData = true;
			try {
				$this->writeAndPublishRecord($record);
			} catch (Exception $e) {
				$this->log($record, 'error', $e);
			}
		}

		// Make SiteTree items match the nav_menu_item post_type's structure and sort order.
		$wordpressIDsToSilverstripeIDs = singleton('SiteTree')->WordpressIDToSilverstripeIDMap();
		$trackRecordsChangedByIDs = array();
		$list = $this->applyWordpressFilter(SiteTree::get());
		foreach ($wpNavMenuItems as $wpData)
		{
			$wpMeta = $this->_db->attachAndGetPostMeta($wpData);
			if (!isset($wpMeta['_menu_item_type'])) {
				throw new Exception('Menu item is missing _menu_item_type postmeta. Your Wordpress database might be too old. Update it if possible.');
			}
			$record = null;
			if (isset($wpMeta['_menu_item_object_id']) && $wpMeta['_menu_item_object_id'] > 0) 
			{
				// Detect if menu has an associated page ID, if so, and if that page has been imported, use it.
				$record = $list->find('WordpressID', $wpMeta['_menu_item_object_id']);
			}
			$type = $wpMeta['_menu_item_type'];
			if ($type === 'post_type' || $type === 'custom')
			{
				if (!$record) 
				{
					// If direct link to external URL or home.
					if (isset($wpMeta['_menu_item_url']) && $wpMeta['_menu_item_url']) 
					{
						// A direct URL link
						// Eg: "http://www.mylivesite.com.au/newsletters/" 
						$url = $wpMeta['_menu_item_url'];
						if ($url === '/')
						{
							// Detect "home" page
							// - _menu_item_object_id references itself in this case, so we
							//   can't use it to detect the record.
							$record = $list->filter(array(
								'URLSegment' => 'home',
							))->first();
						}
						else
						{
							$record = RedirectorPage::get()->filter(array(
								'RedirectionType' => 'External',
								'ExternalURL'	  => $url
							))->first();
							if (!$record) {
								$record = RedirectorPage::create();
								$record->RedirectionType = 'External';
								$record->ExternalURL = $url;
							}
							$record->WordpressData = $wpData;
						}
					} 
					else 
					{
						$this->log('Found "custom" menu item type (Post ID #'.$wpData['ID'].') with Sort "'.$sort.'". Unable to handle. Skipping.', 'notice');
						continue;
					}
				} 
			}
			else 
			{
				throw new Exception('Unable to handle menu type "'.$type.'"');
			}

			if (!$record) {
				DB::alteration_message('Unable to find menu items post record.', 'error');
				continue;
			}
			if (isset($trackRecordsChangedByIDs[$record->ID]))
			{
				DB::alteration_message('Already used "'.$record->Title.'" #'.$record->ID.' in this menu.', 'error');
				continue;
			}

			// Determine where to place Page in SS based on Wordpress IDs
			$wordpressParentID = 0;
			$wpParentNavItem = null;
			if (isset($wpMeta['_menu_item_menu_item_parent']) && $wpMeta['_menu_item_menu_item_parent'] > 0) 
			{
				$wpParentNavID = $wpMeta['_menu_item_menu_item_parent'];
				if ($wpParentNavID > 0 && isset($wpNavMenuItems[$wpParentNavID])) 
				{
					$wpParentNavItem = $wpNavMenuItems[$wpParentNavID];
					$wpParentMeta = $this->_db->attachAndGetPostMeta($wpParentNavItem);
					if (!$wpParentMeta)
					{
						throw new Exception('Missing meta on parent nav_menu_item #'.$wpParentNavID);
					}
					$wordpressParentID = $wpParentMeta['_menu_item_object_id'];
				}
				else
				{
					throw new Exception('Unable to find parent nav_menu_item #'.$wpParentNavID);
				}
			}
			if ($wordpressParentID > 0) {
				$silverstripeParentID = $wordpressIDsToSilverstripeIDs[$wordpressParentID];
				$record->ParentID = $silverstripeParentID;
			} else if ($wordpressParentID == 0) {
				$record->ParentID = $this->root_parent_id;
			}

			// Update with menu data
			$record->MenuTitle = $wpData['post_title'];
			if ($record instanceof RedirectorPage && $record->Title == '') {
				$record->Title = $record->MenuTitle;
			}
			$record->ShowInMenus = 1;
			// NOTE(Jake): Wordpress keeps its menu_order value going even under parents, like so:
			//			   - Menu Item 1			(menu_order = 1)
			//			 		- Menu Sub Item 1	(menu_order = 2)
			// 		       - Menu Item 2			(menu_order = 3)
			//					- Menu Sub Item 1	(menu_order = 4)
			//					- Menu Sub Item 2	(menu_order = 5)
			$record->Sort = $wpData['menu_order'];
			if ($changedFields = $record->getChangedFields(true, DataObject::CHANGE_VALUE))
			{
				try {
					$this->writeAndPublishRecord($record);
					$trackRecordsChangedByIDs[$record->ID] = $record->ID;
				} catch (Exception $e) {
					$this->log($record, 'error', $e);
				}
			}
			else
			{
				$this->log($record, 'nochange');
			}
		}
		$this->logFunctionEnd(__FUNCTION__);
	}

	/**
	 * @return void
	 */
	public function setHomepageToWordpressPageAndDeleteCurrentHomePage() {
		$this->logFunctionStart(__FUNCTION__);
		$wordpressHomeID = $this->_db->getFrontPageID();
		if ($wordpressHomeID) 
		{
			$record = SiteTree::get()->find('WordpressID', $wordpressHomeID);
			if ($record && $record->exists())
			{
				// todo(Jake): use home constant/var in SS framework
				if ($record->URLSegment !== 'home')
				{
					$record->setField('URLSegment', 'home');
					if ($record->getChangedFields(true, DataObject::CHANGE_VALUE)) 
					{
						$list = SiteTree::get()->filter(array(
							'WordpressID' => 0,
							'URLSegment'  => 'home'
						));
						foreach ($list as $i => $oldHomePage) {
							try {
								$oldHomePage->delete();
								$oldHomePage->doUnpublish();
								$this->log($oldHomePage, 'deleted');
							} catch (Exception $e) {
								$this->log($oldHomePage, 'delete_error', $e);
							}
						}
						try {
							$this->writeAndPublishRecord($record);
						} catch (Exception $e) {
							$this->log($record, 'error', $e);
							throw $e;
						}
					}
				}
			}
		}
		$this->logFunctionEnd(__FUNCTION__);
	}

	public function __construct() {
		parent::__construct();

		// Put extension checks into hash maps
		foreach (ClassInfo::subclassesFor('DataObject') as $class)
		{
			if ($class::has_extension('ElementPageExtension'))
			{
				$this->_classes_using_elemental[$class] = true;
			}
			if ($class::has_extension('WordpressImportDataExtension'))
			{
				$this->_classes_using_wordpress_extension[$class] = true;
			}
		}

		// If Elemental is on the base SiteTree or Page type, use the elemental callback by default.
		if (isset($this->_classes_using_elemental['SiteTree']) || isset($this->_classes_using_elemental['Page'])) {
			$this->setContentCallbacks('setElementalContent', 'getElementalContent');
		}

		// Set default root parent ID
		if (class_exists('Multisites') && $this->root_parent_id == 0) 
		{
			$site = null;
			$sites = Site::get()->toArray();
			if (count($sites) == 1) {
				$site = $sites[0];
			} else {
				foreach ($sites as $it) 
				{
					if ($site->IsDefault) 
					{
						$site = $it;
						break;
					}		
				}
			}
			if ($site)
			{
				$this->root_parent_id = $site->ID;
			}
		}
	}

	/**
	 * @return WordpressImportService
	 */
	public function setDatabase(WordpressDatabase $database) {
		$this->_db = $database;
		return $this;
	}

	/**
	 * @return WordpressDatabase
	 */
	public function getDatabase() {
		$this->setupDefaultDatabaseIfNull();
		return $this->_db;
	}

	/**
	 * Set the function to call on 'setContentOnRecord'.
	 * eg. For DNA Design's Elemental module, setContentApplyCallback('setAndWriteElementalContentOnRecord')
	 *
	 * @return WordpressImportService
	 */
	public function setContentCallbacks($setFunction, $getFunction) {
		if (is_string($setFunction)) {
			if (!$this->hasMethod($setFunction)) {
				throw new Exception($this->class.' does not have function "'.$setFunction.'"');
			}
			$this->_content_set_callback = $setFunction;
			$this->_content_get_callback = $getFunction;
		} else {
			throw new Exception('Unsupported type passed to '.__FUNCTION__);
		}
		return $this;
	}

	/**
	 *	Set the $Content
	 */
	public function setContentOnRecord(DataObjectInterface $record, $post_content) {
		return $this->{$this->_content_set_callback}($record, $post_content);
	}

	/**
	 * @return string
	 */
	public function getContentFromRecord(DataObjectInterface $record) {
		return $this->{$this->_content_get_callback}($record);
	}

	/**
	 * 
	 */
	public function setSiteTreeContent(DataObjectInterface $record, $post_content) {
		$post_content = trim($this->_db->process('post_content', $post_content));
		if (!$post_content) {
			return;
		}
		if ($record->Content !== $post_content) {
			$record->Content = $post_content;
		}
	}

	/**
	 * @return string
	 */
	public function getSiteTreeContent(DataObjectInterface $record) {
		return $record->Content;
	}

	/**
	 * Write an ElementContent block with $Content
	 */
	public function setElementalContent(DataObjectInterface $record, $post_content) {
		if (!isset($this->_classes_using_wordpress_extension['ElementContent'])) {
			throw new WordpressImportException('Must put "WordpressImportDataExtension" on BaseElement.');
		}
		if (!isset($this->_classes_using_elemental[$record->class])) {
			if ($record instanceof SiteTree) {
				throw new WordpressImportException('Must put "WordpressImportDataExtension" on SiteTree.');
			} else {
				throw new WordpressImportException('Must put "WordpressImportDataExtension" on '.$record->class.'.');
			}
		}
		$post_content = trim($this->_db->process('post_content', $post_content));

		$elementBlocks = $record->ElementArea()->Elements();
		if (!$record->exists()) {
			$subRecord = ElementContent::create();
			$subRecord->HTML = $post_content;
			$subRecord->WordpressData = $record->WordpressData;
			$elementBlocks->add($subRecord);
			// note(Jake): when $record is written, the rest will write into it. 3.2+ at least.
		} else {
			$subRecord = $elementBlocks->filter(array(
				'WordpressID' => $record->WordpressID,
				'ClassName' => 'ElementContent',
			))->first();
			if (!$subRecord) {
				$subRecord = ElementContent::create();
			}
			// Avoid getChangedFields triggering below by checking equality first.
			if ($subRecord->HTML !== $post_content)  {
				$subRecord->HTML = $post_content;
			}
			$subRecord->WordpressData = $record->WordpressData;
			if (!$subRecord->exists()) {
				$elementBlocks->add($subRecord);
			} else {
				$changedFields = $subRecord->getChangedFields(true, DataObject::CHANGE_VALUE);
				// NOTE(Jake): Checking for changes against HTML can be finicky, so ignore it. Any changes
				//			   should be caught in the 'WordpressData' serialized-string and trigger a write
				//			   anyway.
				unset($changedFields['HTML']);
				if ($changedFields) {
					try {
						$subRecord->write();
						$this->log($subRecord, 'changed', null, 1);
						if ($record->isPublished() && $subRecord->hasMethod('publish')) {
							$subRecord->publish('Stage', 'Live');
							$this->log($subRecord, 'published', null, 1);
						}
					} catch (Exception $e) {
						$this->log($subRecord, 'error', $e, 1);
						throw $e;
					}
				}
			}
		}
	}

	/**
	 * @return string|boolean
	 */
	public function getElementalContent(DataObjectInterface $record) {
		$elementBlocks = $record->ElementArea()->Elements();
		if ($elementBlocks instanceof UnsavedRelationList) {
			return false;
		}
		$subRecord = $elementBlocks->filter(array(
			'WordpressID' => $record->WordpressID,
			'ClassName' => 'ElementContent',
		))->first();
		if ($subRecord) {
			return $subRecord->HTML;
		}
		return false;
	}

	public function logFunctionStart($message, $args = array()) {
		/*if ($args) {
			$message = $message.'('.implode(',', $args).')';
		}*/
		$middle = '| Start "'.$message.'" |';
		$top_bottom = str_repeat('-', strlen($middle));
		DB::alteration_message($top_bottom);
		DB::alteration_message($middle);
		DB::alteration_message($top_bottom);
	}

	public function logFunctionEnd($message) {
		$middle = '| End "'.$message.'" |';
		$top_bottom = str_repeat('-', strlen($middle));
		DB::alteration_message($top_bottom);
		DB::alteration_message($middle);
		DB::alteration_message($top_bottom);
	}

	/**
	 * 
	 */
	public function log($messageOrDataObject, $type = '', Exception $exception = null, $indent = 0) {
		$message = '';
		for ($i = 0; $i < $indent; ++$i) {
			$message .= '--';
		}
		if ($message) {
			$message .= ' ';
		}

		if (is_object($messageOrDataObject)) {
			$record = $messageOrDataObject;
			// todo(Jake): Cleanup and make this a sort of configurable sprintf() hash list.
			//		       ie. "Added $Title ($Class) to #$ID"
			//
			//			   This will allow other people to change phrasing to something that suits them with
			//			   ease.
			switch ($type)
			{
				case 'created':
					$message .= 'Added "'.$record->Title.'" ('.$record->class.') to #'.$record->ID;
				break;

				case 'error':
					$message .= 'Failed to write #'.$record->ID;
				break;

				case 'changed':
				case 'notice':
					$message .= 'Changed "'.$record->Title.'" ('.$record->class.') #'.$record->ID;
				break;

				case 'deleted':
					$message = 'Deleted "'.$record->Title.'" ('.$record->class.') #'.$record->ID;
					$type = 'created';
				break;

				// Special Cases for $record
				case 'published':
					$message .= 'Published "'.$record->Title.'" ('.$record->class.') #'.$record->ID;
					$type = 'changed';
				break;

				case 'delete_error':
					$message = 'Unable to delete "'.$record->Title.'" ('.$record->class.') #'.$record->ID;
					$type = 'error';
				break;

				case 'nochange':
					$message = 'No changes to "'.$record->Title.'" ('.$record->class.') #'.$record->ID;
					$type = '';
				break;

				default:
					throw new Exception('Invalid log type ("'.$type.'") passed with $record.');
				break;
			}
		} else {
			$message .= $messageOrDataObject;
		}
		if ($exception) {
			$message .= ' -- '.$exception->getMessage() . ' -- File: '.basename($exception->getFile()).' -- Line '.$exception->getLine();
		}

		switch ($type)
		{
			case '':
				DB::alteration_message($message);
			break;

			case 'created':
				DB::alteration_message($message, 'created');
			break;

			case 'error':
				set_error_handler(array($this, 'log_error_handler'));
				user_error($message, E_USER_WARNING);
				restore_error_handler();
			break;

			case 'changed':
				DB::alteration_message($message, 'changed');
			break;

			case 'notice':
				DB::alteration_message($message, 'notice');
			break;

			default:
				throw new Exception('Invalid log $type ('.$type.') passed.');
			break;
		}
	}

	/**
	 * Custom error handler so that 'user_error' underneath the 'log' function just prints 
	 * like everything else.
	 */ 
	public function log_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
		DB::alteration_message($errstr, 'error');
	}

	/**
	 * @return WordpressDatabase
	 */
	public function setupDefaultDatabaseIfNull() {
		if ($this->_db === null) {
			$this->_db = new WordpressDatabase;
		} 
		return $this->_db;
	}
}