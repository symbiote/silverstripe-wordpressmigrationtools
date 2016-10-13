<?php 

class WordpressImportDataExtension extends DataExtension {
	private static $db = array(
		'WordpressTable' => 'Varchar(50)',
		'WordpressID' => 'Int',
		'WordpressParentID' => 'Int',
		'WordpressData' => 'Text',
		'WordpressMetaData' => 'Text',
		'WordpressWasLastWriteImporter' => 'Boolean',
	);

	/**
	 * To turn on in your configuration once you've imported your Wordpress data.
	 *
	 * @var boolean
	 */
	private static $disable_write_check = false;

	/**
	 * If set to null, it will automatically set to true in a BuildTask/CLI context, otherwise
	 * it will be be false.
	 *
	 * @var null|boolean
	 */
	public static $throw_error_if_blank_wordpress_data = null;

	public function updateCMSFields(FieldList $fields) {
		$extFields = self::$db;
		$fields->removeByName(array_keys($extFields));
		if (!$this->owner->WordpressID)
		{
			return;
		}
		$compositeFields = array();
		foreach ($extFields as $name => $type)
		{
			$value = $this->owner->getField($name);
			$compositeFields[$name] = ReadonlyField::create($name.'_Readonly', FormField::name_to_label($name), $value);
		}
		if ($compositeFields)
		{
			$wordpressCompositeField = ToggleCompositeField::create('WordpressCompositeField', 'Wordpress', $compositeFields)->setHeadingLevel(4);
			if ($fields->fieldByName('Metadata')) {
				$fields->insertBefore($wordpressCompositeField, 'Metadata');
			} else if ($fields->fieldByName('Root')) {
				$fields->addFieldToTab('Root.Main', $wordpressCompositeField);
			} else {
				$fields->push($wordpressCompositeField);
			}
		}
	}

	public function setWordpressData($value) {
		$oldValue = $this->owner->getField('WordpressData');
		if (is_string($oldValue) && is_array($value)) {
			unset($value[WordpressDatabase::HINT]); // remove importer hints
			$valueJSON = WordpressUtility::utf8_json_encode($value);
			if ($oldValue === $valueJSON) {
				// If old value is the same as new value, don't set. This ensures
				// getChangedFields(true) isn't triggered by unchanged WordpressData as
				// "WordpressData" can either be an array of the data or the serialized string
				// of the data.
				return;
			}
		}
		$this->owner->setField('WordpressData', $value);
	}

	public function getWordpressData() {
		$value = $this->owner->getField('WordpressData');
		if (is_string($value)) {
			return json_decode($value, true);
		}
		return $value;
	}

	public function getWordpressMetaData() {
		$value = $this->owner->getField('WordpressMetaData');
		if (is_string($value)) {
			return json_decode($value, true);
		}
		return $value;
	}

	public function onBeforeWrite() {
		if (Config::inst()->get(__CLASS__, 'disable_write_check')) {
			return;
		}
		$this->owner->WordpressWasLastWriteImporter = 0;

		if (self::$throw_error_if_blank_wordpress_data === null) {
			$controller = (Controller::has_curr()) ? Controller::curr() : null;
			if ($controller && ($controller instanceof DatabaseAdmin || $controller instanceof TestRunner)) {
				// Don't throw error if creating records in /dev/build
				self::$throw_error_if_blank_wordpress_data = false;
			} else {
				// Throw errors if executing from command line or TaskRunner
				self::$throw_error_if_blank_wordpress_data = (Director::is_cli() || $controller instanceof TaskRunner);
			}
		}
		if ($this->owner instanceof Folder)
		{
			// Ignore Folder type as Wordpress doesn't have an equivalent
			return;
		}
		$wordpressData = $this->owner->getField('WordpressData');
		if (self::$throw_error_if_blank_wordpress_data && !$wordpressData) {
			throw new Exception('Attempted to write record without WordpressData.');
		}
		// Only run this code if an external source set "WordpressData" to an array.
		// This avoids this executing for an existing record that will just see WordpressData as a json-encoded array (string)
		if ($wordpressData && is_array($wordpressData))
		{
			$this->owner->WordpressWasLastWriteImporter = 1;

			if ($wordpressData === true || $wordpressData === 1) {
				// Use true/1 to to hint it was created/changed in an import context but it
				// doesn't have any actual data attached to it.
				$this->owner->WordpressData = '1';
			} else {
				$wordpressTable = isset($wordpressData[WordpressDatabase::HINT]['table']) ? $wordpressData[WordpressDatabase::HINT]['table'] : null;
				if ($wordpressTable) {
					$this->owner->WordpressTable = $wordpressTable;
				}

				// setWordpressID
				$wordpressID = null;
				if (isset($wordpressData['ID'])) {
					// Handle ID format for anything in wp_posts table.
					$wordpressID = $wordpressData['ID'];
				} else if (isset($wordpressData['term_id'])) {
					// Handle ID format for anything in wp_terms table.
					$wordpressID = $wordpressData['term_id'];
				} else if (isset($wordpressData['id'])) {
					// Handle ID format for Gravity Forms
					$wordpressID = $wordpressData['id'];
				}
				if ($wordpressID === null) {
					throw new Exception('Attempted to write record without setting WordpressID.');
				}
				$this->owner->WordpressID = $wordpressID;
				$this->owner->WordpressParentID = isset($wordpressData['post_parent']) ? $wordpressData['post_parent'] : null;

				// encodeMeta
				$wordpressMetaData = isset($wordpressData[WordpressDatabase::HINT]['meta']) ? $wordpressData[WordpressDatabase::HINT]['meta'] : null;
				if ($wordpressMetaData && is_array($wordpressMetaData)) {
					$this->owner->WordpressMetaData = WordpressUtility::utf8_json_encode($wordpressMetaData);
				}

				// encodeData
				$wordpressData = $this->owner->WordpressData;
				if ($wordpressData && is_array($wordpressData)) {
					unset($wordpressData[WordpressDatabase::HINT]); // remove importer hints
					$this->owner->WordpressData = WordpressUtility::utf8_json_encode($wordpressData);
					if ($this->owner->WordpressData === false) {
						throw new WordpressImportException(WordpressUtility::json_last_error_msg());
					}
				}
			}
		}
	}

	/**
	 * Get list of in-use Wordpress IDs in the SS Database.
	 *
	 * @return array
	 */
	public function WordpressIDsMap($wordpressTable = 'posts') {
		// todo(Jake): Automatically figure out what class has WordpressID in the table
		//			   ie. EventPage->ThisFunc() should "just work" if SiteTree has the extension.
		$class = $this->owner->class;
		if ($class::has_extension(__CLASS__)) {
			$result = array();
			$result[0] = 0; // Make WordpressID = 0, always "exist"
			$queryResults = DB::query('SELECT WordpressID FROM '.$class.' WHERE WordpressID <> 0 AND WordpressTable = \''.Convert::raw2sql($wordpressTable).'\'');
			foreach ($queryResults as $data) {
				$id = $data['WordpressID'];
				$result[$id] = $id;
			}
			return $result;
		} else {
			throw new WordpressImportException($class.' must have '.get_called_class().' extension.');
		}
		return array();
	}

	/**
	 * Get list of Wordpress-imported records with the key being the ID.
	 * Used for getting existing records and updating them during import processes.
	 *
	 * @return array
	 */
	public function WordpressRecordsByWordpressID($wordpressTable = 'posts') {
		$class = $this->owner->class;

		$result = array();
		$list = singleton('WordpressImportService')->applyWordpressFilter($class::get(), $wordpressTable);
		foreach ($list as $record) {
			$result[$record->WordpressID] = $record;
		}
		return $result;
	}

	/**
	 * @return array
	 */
	public function WordpressIDToSilverstripeIDMap($wordpressTable = 'posts') {
		// todo(Jake): Automatically figure out what class has WordpressID in the table
		//			   ie. EventPage->ThisFunc() should "just work" if SiteTree has the extension.
		$class = $this->owner->class;
		if ($class::has_extension(__CLASS__)) {
			$result = array();
			$queryResults = DB::query('SELECT WordpressID, ID FROM '.$class.' WHERE WordpressID <> 0 AND WordpressTable = \''.Convert::raw2sql($wordpressTable).'\'');
			foreach ($queryResults as $data) {
				$result[$data['WordpressID']] = $data['ID'];
			}
			return $result;
		} else {
			throw new WordpressImportException($class.' must have '.get_called_class().' extension.');
		}
		return array();
	}
}