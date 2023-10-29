<?php

namespace ProcessWire;

/*
 * Need to allow for possibility of using DefaultPage (if it exists) as the base class so that any user-added methods
 * are kept
 */

use Exception;

if(class_exists('DefaultPage')) {
	class DummyMigrationPage extends DefaultPage {
	}
} else {
	class DummyMigrationPage extends Page {
	}
}


/**
 * Class DbMigrationPage
 * @package ProcessWire
 *
 * @property object $migrations The parent page for migration pages
 * @property object $migrationTemplate The template for migration pages
 * @property string $migrationsPath Path to the folder holding the migrations .json files
 * @property string $adminPath Path to the admin root (page id = 2)
 * @property object $configData Process module settings
 * @property boolean $ready To indicate tha ready() has run
 * @property object $dbMigrate ProcessDbMigrate module instance
 * @property string $dbName database name set in module config
 * @property string $title Title
 * @property mixed $dbMigrateRuntimeControl Page status
 * @property mixed $dbMigrateRuntimeActions Migration actions
 * @property string $dbMigrateSummary Summary
 * @property string $dbMigrateAdditionalDetails Additional details
 * @property RepeaterDbMigrateItemPage $dbMigrateItem Migration item
 * @property string $dbMigrateRestrictFields Restrict fields
 * @property RepeaterDbMigrateSnippetsPage $dbMigrateSnippets Snippets
 * @property mixed $dbMigrateRuntimeReady Hooks etc
 */
class DbMigrationPage extends DummyMigrationPage {

	// Module constants
	/*
	 * Fields which affect the migration - i.e. they contain key data determining the migration, rather than just information
	 *
	 */
	const KEY_DATA_FIELDS = array('dbMigrateItem', 'dbMigrateRestrictFields');
	/*
	 * ALL OTHER CONSTANTS ARE SET IN CLASS ProcessDbMigrate
	 */

	/**
	 * Create a new DbMigration page in memory.
	 *
	 * @param Template $tpl Template object this page should use.
	 *
	 */
	public function __construct(Template $tpl = null) {
		if(is_null($tpl)) $tpl = $this->templates->get('DbMigration');
		parent::__construct($tpl);

	}


	public function init() {
		//bd('INIT MIGRATION');
	}

	/**
	 * Better to put hooks here rather than in ready.php
	 * This is called from ready() in ProcessDbMigrate.module as that is autoloaded
	 *
	 * @throws WireException
	 */
	public function ready() {
		$this->set('adminPath', wire('pages')->get(2)->path);
		$this->set('migrations', wire('pages')->get($this->adminPath . ProcessDbMigrate::MIGRATION_PARENT));
		$this->set('migrationTemplate', wire('templates')->get(ProcessDbMigrate::MIGRATION_TEMPLATE));
		$this->set('migrationsPath', wire('config')->paths->templates . ProcessDbMigrate::MIGRATION_PATH);
		$this->set('configData', wire('modules')->getConfig('ProcessDbMigrate'));
		$dbMigrate = wire('modules')->get('ProcessDbMigrate');
		/* @var $dbMigrate ProcessDbMigrate */
		$this->set('dbMigrate', $dbMigrate);
		$this->set('dbName', $dbMigrate->dbName());

		if(isset($this->configData['suppress_hooks']) && $this->configData['suppress_hooks']) $this->wire()->error("Hook suppression is on - migrations will not work correctly - unset in the module settings.");
		// Fix for PW versions < 3.0.152, but left in place regardless of version, in case custom page classes are not enabled
		if($this->migrationTemplate->pageClass != __CLASS__) {
			$this->migrationTemplate->pageClass = __CLASS__;
			$this->migrationTemplate->save();
		}
		//
		$this->addHookAfter("Pages::saved(template=$this->migrationTemplate)", $this, 'afterSaved');
		$this->addHookBefore("Pages::save(template=$this->migrationTemplate)", $this, 'beforeSaveThis');
		$this->addHookBefore("Pages::trash(template=$this->migrationTemplate)", $this, 'beforeTrashThis');
		$this->addHookAfter("Pages::trashed(template=$this->migrationTemplate)", $this, 'afterTrashedThis');
		$this->addHookAfter("InputfieldFieldset::render", $this, 'afterFieldsetRender');


		$readyFile = $this->migrationsPath . '/' . $this->name . '/ready.php';
		if(file_exists($readyFile)) include $readyFile;
		//bd($this, 'Migration Page ready');
		$this->set('ready', true);
	}

	/**************************************************
	 *********** EXPORT SECTION ***********************
	 *************************************************/

	/**
	 * Export migration data to json files and compare differences
	 *
	 * This is  run in the 'source' database to export the migration data ($newOld = 'new')
	 * It is also run in the target database on first installation of a migration to capture the pre-installation state ($newOld = 'old')
	 * Running with $newOld = 'compare' creates cache files ('new-data.json' and 'old-data.json') for the current state,
	 *    to compare against data.json files in 'new' and 'old' directories
	 * json files are also created representing the migration page itself
	 *
	 * Return an array of items detailing the status and the differences
	 *
	 * @param $newOld
	 * @return array|void|null
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function exportData($newOld) {
		if(!$this->ready) $this->ready();

		// NB Inputfield::exportConfigData sometimes returns columnwidth = 100 even if it is not set. This hook (removed at the end of the method) tries to fix that
		// ToDo A more fundamental fix would be better
		$exportHookId = $this->addHookAfter("Inputfield::exportConfigData", function($event) {
			$dataIn = $event->arguments(0);
			//bd($dataIn, 'dataIn');
			$dataOut = $event->return;
			if(!isset($dataIn['columnWidth'])
				and isset($dataOut['columnWidth']) and $dataOut['columnWidth'] == 100) $dataOut['columnWidth'] = '';
			$event->return = $dataOut;
			//bd($dataOut, 'dataOut');
		});

		//bd($this->meta('draft'), 'meta draft');
		$directory = $this->migrationsPath;
		//bd($directory);

		/*
		 * INITIAL PROCESSING
		 */
		//bd($this, 'In exportData with newOld = ' . $newOld);
		$excludeFields = (isset($this->configData['exclude_fieldnames']))
			? str_replace(' ', '', $this->configData['exclude_fieldnames']) : '';
		$excludeFields = $this->wire()->sanitizer->array(str_replace(' ', '', $excludeFields), 'fieldName');
		$excludeTypes = (isset($this->configData['exclude_fieldtypes']))
			? str_replace(' ', '', $this->configData['exclude_fieldtypes']) : '';
		$excludeTypes = $this->wire()->sanitizer->array(str_replace(' ', '', $excludeTypes), 'fieldName');
		$excludeTypes = array_merge($excludeTypes, ProcessDbMigrate::EXCLUDE_TYPES);
		$excludeFieldsForTypes = $this->excludeFieldsForTypes($excludeTypes);
		$excludeFields = array_merge($excludeFields, $excludeFieldsForTypes);
		$excludeFieldsBasic = $this->excludeFieldsForTypes(ProcessDbMigrate::EXCLUDE_TYPES);
		//bd($this->configData, 'configData in exportData');
		$excludeAttributes = (isset($configData['exclude_attributes']))
			? str_replace(' ', '', $configData['exclude_attributes']) : '';
		$excludeAttributes = $this->wire()->sanitizer->array(str_replace(' ', '', $excludeAttributes));
		$excludeAttributes = array_merge($excludeAttributes, ProcessDbMigrate::EXCLUDE_ATTRIBUTES);
		$excludeAttributesBasic = ProcessDbMigrate::EXCLUDE_ATTRIBUTES;
		$result = null;
		$migrationPath = $directory . $this->name . '/';
		$migrationPathNewOld = $migrationPath . $newOld . '/';
		if($newOld != 'compare') {
			if($newOld == 'old' and is_dir($migrationPathNewOld)) return;  // Don't over-write old directory once created
			if(!is_dir($migrationPathNewOld)) if(!wireMkdir($migrationPathNewOld, true, "0777")) {          // wireMkDir recursive
				throw new WireException("Unable to create migration directory: $migrationPathNewOld");
			}
			if(!is_dir($migrationPathNewOld . 'files/')) if(!wireMkdir($migrationPathNewOld . 'files/', true, "0777")) {
				throw new WireException("Unable to create migration files directory: {$migrationPathNewOld}bootstrap/");
			}
		}

		/*
		* GET DATA FOR THE MIGRATION PAGE ITSELF AND SAVE IN JSON
		*/
		$item = [];
		$item['type'] = 'pages';
		$item['action'] = 'changed';
		$item['name'] = $this->path;
		$item['oldName'] = '';
		$migrationData = $this->getMigrationItemData(null, $item, $excludeAttributesBasic, $excludeFieldsBasic, $newOld, 'new')['data'];
		//bd($migrationData, 'migrationData');
		//bd($this->meta('sourceDb'), 'sourceDb');
		if($this->meta('draft') and $this->meta('sourceDb')) {
			$migrationData['sourceDb'] = $this->meta('sourceDb');
		} else if($this->dbName) {
			$migrationData['sourceDb'] = $this->dbName;
		}
		// Include an item for the site url as this may be different in the target
		$migrationData['sourceSiteUrl'] = $this->wire()->config->urls->site;
		$migrationObjectJson = wireEncodeJSON($migrationData, true, true);
		if($newOld != 'compare') {
			file_put_contents($migrationPathNewOld . 'migration.json', $migrationObjectJson);
			$this->wire()->session->message($this->_('Exported migration definition as ') . $migrationPathNewOld . 'migration.json');
		}

		if(!$this->meta('draft') or $newOld != 'new') {   // meta('draft') denotes draft migration prepared from comparison

			/*
			 * GET DATA FROM PAGE AND SAVE IN JSON
			 */
			$itemRepeater = $this->dbMigrateItem;
			//bd($itemRepeater, $itemRepeater);
			$items = $this->cycleItems($itemRepeater, $excludeAttributes, $excludeFields, $newOld, 'new');
			$data = $items['data'];
			//bd($data, 'data for json');
			$files['new'] = $items['files'];
			$reverseItems = $this->cycleItems($itemRepeater, $excludeAttributes, $excludeFields, $newOld, 'old'); // cycleItems will reverse order for uninstall
			$reverseData = $reverseItems['data'];
			$files['old'] = $reverseItems['files'];
			//bd($files, 'files in export data');
			$objectJson['new'] = wireEncodeJSON($data, true, true);
			//bd($objectJson['new'], 'New json created');
			$objectJson['old'] = wireEncodeJSON($reverseData, true, true);
			//bd($objectJson, '$objectJson ($newOld = ' . $newOld . ')');
			if($newOld != 'compare') {
				file_put_contents($migrationPathNewOld . 'data.json', $objectJson[$newOld]);
				$this->wire()->session->message($this->_('Exported object data as') . ' ' . $migrationPathNewOld . 'data.json');
				//bd($files[$newOld], '$files[$newOld]');
				foreach($files[$newOld] as $fileArray) {
					foreach($fileArray as $id => $baseNames) {
						$filesPath = $this->wire('config')->paths->files . $id . '/';
						if(!is_dir($migrationPathNewOld . 'files/' . $id . '/')) {
							if(!wireMkdir($migrationPathNewOld . 'files/' . $id . '/', true, "0777")) {
								throw new WireException("Unable to create migration files directory: {$migrationPathNewOld}files/{$id}/");
							}
						}
						if(is_dir($filesPath)) {
							$copyFiles = [];
							foreach($baseNames as $baseName) {
								//bd($baseName, 'Base name for id ' . $id);
								if(is_string($baseName)) {
									$copyFiles[] = $filesPath . $baseName;
								} else if(is_array($baseName)) {
									$copyFiles = array_merge($copyFiles, $baseName);
								}
							}
							//bd($copyFiles, 'copyfiles');
							foreach($copyFiles as $copyFile) {
								if(file_exists($copyFile)) {
									$this->wire()->files->copy($copyFile, $migrationPathNewOld . 'files/' . $id . '/');
									$this->message(sprintf($this->_('Copied file %s to '), $copyFile) . $migrationPathNewOld . 'files/' . $id . '/');
								} else {
									$installType = ($newOld == 'new') ? 'Install' : 'Uninstall'; // $newOld is 'new' or 'old' in this context
									$this->error(sprintf($this->_('File %1$s does not exist in this environment. %2$s will not be usable.'), $copyFile, $installType));
								}
							}
						}
					}
				}
			}
			/*
			 * ON CREATION OF OLD JSON FILE, MAKE A COPY OF THE ORIGINAL NEW JSON FILE FOR LATER COMPARISON
			 * (introduced in version 0.1.0 - migrations created under earlier versions will not have done this and therefore will have more limited scope change checking)
			 */
			$newFileExists = (file_exists($migrationPath . 'new/data.json'));
			if($newFileExists and $newOld == 'old') {
				$this->wire()->files->copy($migrationPath . 'new/data.json', $migrationPath . 'old/orig-new-data.json');
			}
			/*
			 * COMPARE CURRENT STATE WITH NEW / OLD STATES
			 */
			if($newOld == 'compare') {
				$cachePath = $this->wire()->config->paths->assets . 'cache/dbMigrate/';
				if(!is_dir($cachePath)) if(!wireMkdir($cachePath, true, "0777")) {          // wireMkDir recursive
					throw new WireException("Unable to create cache migration directory: $cachePath");
				}
				if($data and $objectJson) {
					//bd($migrationPath, 'migrationPath');
					/*
					 * Get file data
					 */
					$newFile = (file_exists($migrationPath . 'new/data.json'))
						? file_get_contents($migrationPath . 'new/data.json') : null;
					$oldFile = (file_exists($migrationPath . 'old/data.json'))
						? file_get_contents($migrationPath . 'old/data.json') : null;
					file_put_contents($cachePath . 'old-data.json', $objectJson['old']);
					file_put_contents($cachePath . 'new-data.json', $objectJson['new']);
					//bd($newFile, 'New file');
					$newArray = $this->compactArray(wireDecodeJSON($newFile));
					//bd($newArray, 'newArray');
					$oldArrayFull = wireDecodeJSON($oldFile);
					$oldArray = $this->compactArray($oldArrayFull);
					//bd('New compare');
					$cmpArray['new'] = $this->compactArray(wireDecodeJSON($objectJson['new']));
					//bd($cmpArray['new'], 'cmpArray');
					$cmpArrayFull['old'] = wireDecodeJSON($objectJson['old']);
					$cmpArray['old'] = $this->compactArray($cmpArrayFull['old']);

					/*
					 * Compare 'new' data
					 */
					//bd('new data');
					$R = $this->array_compare($newArray, $cmpArray['new']);
					$R = $this->pruneImageFields($R, 'new');
					//bd($R, ' array compare new->cmp');
					//bd(wireEncodeJSON($R), ' array compare json new->cmp');
					$installedData = (!$R);
					$installedDataDiffs = $R;

					/*
					 * Compare 'old' data
					 */
					//bd('old data');
					$R2 = $this->array_compare($oldArray, $cmpArray['old']);
					$R2 = $this->pruneImageFields($R2, 'old');
					//bd($R2, ' array compare old->cmp');
					//bd(wireEncodeJSON($R2), ' array compare json old->cmp');
					$uninstalledData = (!$R2);
					$uninstalledDataDiffs = $R2;

					/*
					* Finally compare the total difference between old and new files if both files are present
					 */
					//bd('total data');
					if($newFile and $oldFile) {
						$R3 = $this->array_compare($newArray, $oldArray);
						$R3 = $this->pruneImageFields($R3, 'both');
						$reviewedDataDiffs = $R3;
					} else {
						$reviewedDataDiffs = [];
					}

					/*
					* DETECT ANY SCOPE CHANGES AS EVIDENCED IN THE NEW/DATA.JSON FILE
					*/
					$origNewFile = (file_exists($migrationPath . 'old/orig-new-data.json'))
						? file_get_contents($migrationPath . 'old/orig-new-data.json') : null;
					$origNewArray = $this->compactArray(wireDecodeJSON($origNewFile));
					$scopeDiffs = [];
					$scopeChange = false;
					/*
					 * For migrations set using v0.1.0 or later, an 'orig-new-data' json file should have been copied at installation time
					 * Check the current 'new' data.json to see if the scope generated is different form the scope on initial installation
					 */
					if($origNewFile and $newFile) {
						//bd(['new' => $newArray, 'orig' => $origNewArray], 'new and orig');
						$scopeDiffs = array_diff_key($newArray, $origNewArray); // array
						$scopeChange = (count($scopeDiffs) > 0); // Boolean
					}
				} else {
					$installedData = true;
					$uninstalledData = true;
					$installedDataDiffs = [];
					$uninstalledDataDiffs = [];
					$reviewedDataDiffs = [];
					$scopeDiffs = [];
					$scopeChange = false;
				}

				/*
				 * MIGRATION COMPARISON
				*/
				// migration.json is just a single page, so json differs from data.json by missing square brackets.
				// Add the square brackets to the migration.json so that we can use the same compactArray() method as we use for data.json
				if($this->data) {
					$newMigFile = (file_exists($migrationPath . 'new/migration.json'))
						? '[' . file_get_contents($migrationPath . 'new/migration.json') . ']' : null;
					$oldMigFile = (file_exists($migrationPath . 'old/migration.json'))
						? '[' . file_get_contents($migrationPath . 'old/migration.json') . ']' : null;

					if($migrationObjectJson) {
						file_put_contents($cachePath . 'migration.json', $migrationObjectJson);
					} else {
						if(is_dir($cachePath) and file_exists($cachePath . 'migration.json'))
							unlink($cachePath . 'migration.json');
					}
					$cmpMigFile = (file_exists($cachePath . 'migration.json'))
						? '[' . file_get_contents($cachePath . 'migration.json') . ']' : null;
					//bd('new migration');
					$R = $this->array_compare($this->compactArray(wireDecodeJSON($newMigFile)), $this->compactArray(wireDecodeJSON($cmpMigFile)));
					$R = $this->pruneImageFields($R, 'new');
					$installedMigration = (!$R);
					$installedMigrationDiffs = $R;
					//bd('old migration');
					$R2 = $this->array_compare($this->compactArray(wireDecodeJSON($oldMigFile)), $this->compactArray(wireDecodeJSON($cmpMigFile)));
					$R2 = $this->pruneImageFields($R2, 'old');
					$uninstalledMigration = (!$R2);
					$uninstalledMigrationDiffs = $R2;
					// This comparison only looks at the migration elements that affect the database
					if($oldMigFile) {
						//bd('migration key only');
						$R3 = $this->array_compare($this->compactArray(wireDecodeJSON($oldMigFile), true),
							$this->compactArray(wireDecodeJSON($cmpMigFile), true));
						$R3 = $this->pruneImageFields($R3, 'old');
						$uninstalledMigrationKey = (!$R3);
						$uninstalledMigrationKeyDiffs = $R3;
					} else {
						$uninstalledMigrationKey = true;
						$uninstalledMigrationKeyDiffs = [];
					}
				} else {
					$installedMigration = true;
					$uninstalledMigration = true;
					$installedMigrationDiffs = [];
					$uninstalledMigrationDiffs = [];
					$uninstalledMigrationKey = true;
					$uninstalledMigrationKeyDiffs = [];
				}

				/*
				 * REPORT THE STATUS
				 */
				$installed = ($installedData and $installedMigration);
				$uninstalled = ($uninstalledData and $uninstalledMigration);
				$locked = ($this->meta('locked'));
				if($this->meta('installable')) {
					if($installed) {
						if($uninstalled) {
							$status = 'void';
						} else {
							$status = 'installed';
						}
					} else if($uninstalled) {
						$status = 'uninstalled';
					} else if($locked) {
						$status = 'superseded';
					} else {
						$status = 'indeterminate';
					}
				} else {
					if($installed) {
						$status = 'exported';
					} else if($locked) {
						$status = 'superseded';
					} else {
						$status = 'pending';
					}
				}
				$result = [
					'status' => $status,
					'scopeChange' => $scopeChange,
					'scopeDiffs' => $scopeDiffs,
					'installed' => $installed,
					'uninstalled' => $uninstalled,
					'installedData' => $installedData,
					'uninstalledData' => $uninstalledData,
					'installedDataDiffs' => $installedDataDiffs,
					'uninstalledDataDiffs' => $uninstalledDataDiffs,
					'installedMigration' => $installedMigration,
					'installedMigrationDiffs' => $installedMigrationDiffs,
					'uninstalledMigration' => $uninstalledMigration,
					'uninstalledMigrationDiffs' => $uninstalledMigrationDiffs,
					'uninstalledMigrationKey' => $uninstalledMigrationKey,
					'uninstalledMigrationKeyDiffs' => $uninstalledMigrationKeyDiffs,
					'reviewedDataDiffs' => $reviewedDataDiffs,
					'timestamp' => $this->wire()->datetime->date()
				];
				//bd($result, 'result in exportData');
			}
			$this->meta('installedStatus', $result);
			if(!$this->meta('installable') and $newOld == 'new')
				$this->wire()->pages->___save($this, array('noHooks' => true, 'quiet' => true));  // to ensure reload after export

			// Remove the hook that was added at the start of this method
			$this->removeHook($exportHookId);

			return $result;
		}
	}

	/**
	 * Cycle through items in a migration and get the data for each
	 * If $compareType is 'old' then reverse the order and swap 'new' and 'removed' actions
	 *
	 * @param $itemRepeater // The migration item
	 * @param $excludeAttributes
	 * @param $excludeFields
	 * @param $newOld // 'new'. 'old', or 'compare'
	 * @param $compareType // 'new' to create install data; 'old' to create uninstall data
	 * @return array|array[]
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function cycleItems($itemRepeater, $excludeAttributes, $excludeFields, $newOld, $compareType) {
		$data = [];
		$count = 0;
		$item = [];
		$files = [];
		if(!$itemRepeater) return ['data' => '', 'files' => ''];
		if($compareType == 'old') $itemRepeater = $itemRepeater->reverse();
		foreach($itemRepeater as $repeaterItem) {
			/* @var $repeaterItem RepeaterDbMigrateItemPage */
			$item = $this->populateItem($repeaterItem, ($compareType == 'old')); // swap new and removed if compareType is 'old'
			//bd($item, 'item');
			$count++;
			$migrationItem = $this->getMigrationItemData($count, $item, $excludeAttributes, $excludeFields, $newOld, $compareType);
			$data[] = $migrationItem['data'];
			//bd($migrationItem['files'], 'migrationItem files');
			$files = array_merge_recursive($files, $migrationItem['files']);
			//bd($files, 'files at end of cycleItems');
		}
		//bd($data, 'data returned by cycleItems for ' . $newOld);
		return ['data' => $data, 'files' => $files];
	}

	/**
	 * Get the migration data for an individual item
	 *
	 * @param $k // The sequence number (starting at 0) of the migration item within the migration
	 * @param $item
	 * @param $excludeAttributes
	 * @param $excludeFields
	 * @param $newOld // 'new'. 'old', or 'compare'
	 * @param $compareType // 'new' to create install data; 'old' to create uninstall data
	 * @return array
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function getMigrationItemData($k, $item, $excludeAttributes, $excludeFields, $newOld, $compareType) {
		/*
		 * Initial checks
		 */
		$files = [];
		$empty = ['data' => [], 'files' => []];
		$item['action'] = (isset($item['action'])) ? $item['action'] : 'changed';
		if(!$item['type'] or !$item['name']) {
			if($newOld == 'new' and $compareType == 'new') {
				$this->wire()->session->warning($this->_('Missing values for item ') . $k);
				//bd($item, 'missing values in item');
			}
			return $empty;
		}
		$itemName = $item['name'];  // This will be the name in the source environment
		//bd($itemName, 'itemName');

		/*
		 * Convert selectors into individual items where they exist in the current database
		 */
		$expanded = $this->expandItem($item); // $expanded['items'] is the list of items derived from the selector
		$selector = ($expanded['selector']); // true if original item was a selector
		$old = $expanded['old']; // Not currently used

		if($selector and $item['oldName']) {
			$this->wire()->session->warning($this->_('Old name is not applicable when a selector is used. It will be treated as being blank.'));
			$item['oldName'] = '';
		}

		/*
		 * Check old names (where name is not a selector)
		 */
		if(!$selector) {
			if($item['oldName']) {
				$isOld = $this->wire($item['type'])->get($item['oldName']);
				$isNew = $this->wire($item['type'])->get($item['name']);
				if($isNew and $isNew->id and $isOld and $isOld->id) {
					$this->wire()->session->warning(sprintf($this->_('Both new name (%1$s) and old name (%2$s) exist in the database. Please use unique names.'), $item['name'], $item['oldName']));
					return $empty;
				}
				if($isOld and $isOld->id) {
					$itemName = $item['oldName'];
					//bd($item['oldName'], 'using old name');
				} else if(!$isNew or !$isNew->id) {
					$this->wire()->session->warning(sprintf($this->_('Neither new name (%1$s) nor old name (%2$s) exist in the database.'), $item['name'], $item['oldName']));
					return $empty;
				}
			}
		}

		/*
		 * Should anything have been found and was it?
		 */
		$expandedItems = $expanded['items']; // the list of items derived from the selector (or just from the name/path)
		$noFind = (count($expandedItems) == 0); // name/path or selector yields no results
		$shouldExist = $this->shouldExist($item['action'], $compareType); //should the item exist as a database object in this context?
		//bd(['item' => $item, 'compareType' => $compareType, 'shouldExist' => $shouldExist, 'noFind' => $noFind, 'OK' => ($shouldExist xor $noFind)], "Test existence");

		if(!$this->meta('installable')) { // i.e. we are in the source database
			if($noFind and $shouldExist) $this->wire()->session->warning($this->name . ': ' .
				sprintf($this->_('No %s object for '), $item['type']) . $itemName);
			if(!$noFind and !$shouldExist) $this->wire()->session->warning("{$this->name}: " .
				sprintf($this->_('There is already a %1s object for %2s but none should exist'), $item['type'], $itemName));
		}

		/*
		 * Where there is nothing in this database for the item, just return the array keys with no values
		 */
		if($noFind and ($item['action'] == 'removed' or $item['action'] == 'new')) {
			$data = [$item['type'] => [$item['action'] => [$itemName => []]]];
			//bd($data, 'Returning data for new/removed which do not exist in current db');
			if($newOld == 'new' || ($newOld == 'compare' && $compareType == 'new')) $flag = 'new';
			if($newOld == 'old' || ($newOld == 'compare' && $compareType == 'old')) $flag = 'removed';
			if(isset($flag) && $item['action'] == $flag) {
				$this->wire()->session->warning(sprintf($this->_('Selector "%s" did not select any items'), $item['name']));
			}
			return ['data' => $data, 'files' => []];
		}
		if($noFind) {
			// 'changed' items should exist in all contexts
			$this->wire()->session->warning($this->name . ': ' . sprintf($this->_('No %s object for '), $item['type']) . $itemName);
			return $empty;
		}

		/*
		 * If we got this far, then we should have found some matching objects in the current database
		 * So get the export data for them
		 */
		$objectData = [];
		foreach($expandedItems as $expandedItem) {
			//bd($expandedItem, 'expandedItem');
			$object = $expandedItem['object'];
			// (For non-draft migrations) check object existence if migration is not exported/installed (draft migrations are created from comparisons)
			if(!$this->meta('draft') and (!$this->meta('installedStatus') or !$this->meta('installedStatus')['installed'])) {
				if(!$object or !$object->id or $object->id == 0 and $newOld != 'compare') {
					if($shouldExist) $this->wire()->session->warning($this->name . ': ' .
						sprintf($this->_('No %s object for '), $item['type']) . $itemName);
					$data = [$item['type'] => [$item['action'] => [$itemName => []]]];
					return ['data' => $data, 'files' => []];
				} else if(!$shouldExist and $newOld == 'new') {      // 2nd condition is to avoid double reporting for new and old
					$this->wire()->session->warning("{$this->name}: " .
						sprintf($this->_('There is already a %1s object for %2s but none should exist'), $item['type'], $itemName));
				}
			}

			/*
			 * GET DATA FOR ITEMS IN THE DATABASE
			 */
			$name = $expandedItem['name'];
			$oldName = $expandedItem['oldName'];
			$key = ($oldName) ? $name . '|' . $oldName : $name;

			if($item['type'] == 'pages') {
				$exportObjects = $this->getExportPageData($k, $key, $object, $excludeFields);
			} else {
				$exportObjects = $this->getExportStructureData($k, $key, $item, $object, $excludeAttributes, $newOld, $compareType);
			}
			$objectData = array_merge($objectData, $exportObjects['data']);
			//bd($exportObjects['files'], '$exportObjects[files]');
			$files = array_merge($files, $exportObjects['files']);
			//bd($objectData, 'object data');
		}

		/*
		 * Return the result
		 */
		$data = [$item['type'] => [$item['action'] => $objectData]];
		return ['data' => $data, 'files' => $files];
	}

	/**
	 * Return a list of all fields of the given types
	 * (used to convert excluded types into excluded names)
	 *
	 * @param array $types
	 * @return array
	 * @throws WireException
	 *
	 */
	protected function excludeFieldsForTypes(array $types) {
		$fullTypes = [];
		foreach($types as $type) {
			$fullTypes[] = (!strpos($type, 'Fieldtype')) ? 'Fieldtype' . $type : $type;
		}
		$exclude = [];
		$fields = $this->wire()->fields->getAll();
		foreach($fields as $field) {
			if(in_array($field->type->name, $fullTypes)) $exclude[] = $field->name;
			if(!is_object($field)) throw new WireException("bad field $field");
		}
		return $exclude;
	}

	/**
	 * Take migration items (provided as an array of attributes) and expand any which are selectors
	 * Return the (expanded) item object(s) and name(s) in an array in format
	 *   ['selector' => true/false, 'old' => true/false, 'items' => ['object' => object, 'name' => name, 'oldName' => oldName]]
	 *   where 'selector' denotes that the name was a selector and 'old' denotes that the 'oldName' has been necessary to find the object
	 *
	 * @param $itemArray
	 * @return array
	 * @throws WireException
	 *
	 */
	public function expandItem($itemArray) {
		/* @var $item RepeaterDbMigrateItemPage */
		//bd($itemArray, 'In expandItem with ' . $itemArray['name']);
		$type = $itemArray['type'];
		$result = [];
		$result['selector'] = false;
		$result['old'] = false;
		$empty = ['selector' => false, 'old' => false, 'items' => []];
		if($type == 'pages') {
			$testName = $this->wire()->sanitizer->path($itemArray['name']);
			$nameType = 'path';
		} else {
			$testName = $this->wire()->sanitizer->validate($itemArray['name'], 'name');
			$nameType = 'name';
		}
		if(!$testName) {
			//bd($itemArray['name'], 'Selector provided instead of path/name');
			// we have a selector
			try {
				if($type == 'pages') {
					$objects = $this->wire($type)->find($itemArray['name'] . ", include=all"); // $itemArray['name'] is a selector
				} else {
					$objects = $this->wire($type)->find($itemArray['name']);
				}

				// for pages, sort by path if required
				//bd($itemArray['name'], 'Name for sort path test');
				$objects = $objects->getArray(); // convert to plain array
				//bd($objects);
				if($type == 'pages' and strpos($itemArray['name'], 'sort=path')) {
					usort($objects, function($a, $b) {
						return strnatcmp($a->path, $b->path);
					});
				}

				$result['items'] = [];
				foreach($objects as $object) {
					if($object->id) $result['items'][] = ['object' => $object, 'name' => $object->$nameType, 'oldName' => ''];
				}
				$result['selector'] = true;
			} catch(WireException $e) {
				//bd($itemArray, 'invalid selector');
				$this->wire()->session->error($this->_('Invalid selector: ') . $itemArray['name']);
				return $empty;
			}
		} else {
			try {
				$result['items'] = [];
				$oldName = ($itemArray['oldName']) ?: $itemArray['name'];
				$object = $this->wire($type)->get($itemArray['name']);
				if(!$object or !$object->id) {
					/*
					 * Hack introduced to deal with failure to get bootstrap in earlier PW versions
					 * (see https://processwire.com/talk/topic/25940-issue-with-pages-getpathname-in-pw30148/?tab=comments#comment-216359)
					 */
					$s1 = basename($itemArray['name']);
					$s2 = dirname($itemArray['name']);
					$object = $this->wire()->pages->get("name=$s1, parent=$s2");
				}
				if(!$object or !$object->id) {
					$object = $this->wire($type)->get($oldName);
					$result['old'] = true;
				}
				if($object and $object->id)
					$result['items'] = [['object' => $object, 'name' => $itemArray['name'], 'oldName' => $itemArray['oldName']]];
			} catch(WireException $e) {
				//bd($itemArray, 'invalid name/path');
				$this->wire()->session->error($this->_('Invalid name/path: ') . $itemArray['name']);
				return $empty;
			}
		}
		//bd($result, 'expansion result');
		return $result;
	}

	/**
	 * Determine whether or not an item should exist in the current database
	 * * Changed items should exist in source and target
	 * * New items should exist in the source but not the target
	 * * Removed items should exist in the target but not the source
	 *
	 * Note that if $this is 'installable' ($this->meta('installable) ) then we are in its target database, else we are in its source database
	 *
	 * If $compareType is 'new' then we are using the migration items as defined in the page
	 * If $compareType is 'old' then we are using the mirror terms (reverse order and 'new' and 'removed' swapped)
	 *
	 * @param $compareType
	 * @param $action
	 * @return boolean
	 *
	 */
	protected function shouldExist($action, $compareType) {
		if($action == 'changed') return true;
		$actInd = ($action == 'new') ? 1 : 0;
		$newInd = ($compareType == 'new') ? 1 : 0;
		$sourceInd = (!$this->meta('installable')) ? 1 : 0;
		$ind = $actInd + $newInd + $sourceInd;
		return ($ind & 1); // test if odd by bit checking.
	}

	/**
	 * Get array of page data, with some limitations to prevent unnecessary mismatching
	 *
	 * @param $k
	 * @param $key
	 * @param $exportPage
	 * @param $excludeFields
	 * @return array[]|null[]
	 * @throws WireException
	 *
	 */
	protected function getExportPageData($k, $key, $exportPage, $excludeFields) {
		if($k !== null) {  // $k=null for migration page itself. Restrict fields must not operate on migration page.
			$restrictFields = $this->restrictFields();
		} else {
			$restrictFields = [];
		}
		$data = array();
		$files = array();
		$oldPage = '';
		$repeaterPages = array();

		//bd($exportPage, 'exportpage');
		if(!$exportPage
			or !is_a($exportPage, 'Processwire\Page')
			or !$exportPage->id)
			return ['data' => [], 'files' => [], 'repeaterPages' => []];
		// Now we are sure we have a page object we can continue
		$attrib = [];
		$attrib['template'] = $exportPage->template->name;
		$attrib['parent'] = ($exportPage->parent->path) ?: $exportPage->parent->id;  // id needed in case page is root (will be 0)
		$attrib['status'] = $exportPage->status;
		$attrib['name'] = $exportPage->name;
		$attrib['id'] = $exportPage->id;
		$this->getAllFieldData($exportPage, $restrictFields, $excludeFields, $attrib, $files);
		foreach($excludeFields as $excludeField) {
			unset($attrib[$excludeField]);
		}
		$data[$key] = $attrib;
		//bd($data, 'returning data');
		return ['data' => $data, 'files' => $files, 'repeaterPages' => $repeaterPages];
	}

	public function restrictFields() {
		$restrictFields = array_filter($this->wire()->sanitizer->array(
			str_replace(' ', '', $this->dbMigrateRestrictFields),
			'fieldName',
			['delimiter' => ','])
		);
		return $restrictFields;
	}

	/**
	 * @param $exportPage Page
	 * @param $restrictFields array
	 * @param $excludeFields array
	 * @param $attrib array
	 * @param $files array
	 * @param $fresh boolean Use fresh pages from DB throughout
	 * @return void
	 */
	public function getAllFieldData($exportPage, $restrictFields, $excludeFields, &$attrib, &$files, $fresh = false) {
		foreach($exportPage->getFields() as $field) {
			$name = $field->name;
			//bd($restrictFields, '$restrictFields');
			if((count($restrictFields) > 0 && !in_array($name, $restrictFields)) || in_array($name, $excludeFields)) continue;
			$exportPageDetails = $this->getFieldData($exportPage, $field, $restrictFields, $excludeFields, $fresh);
			$attrib = array_merge_recursive($attrib, $exportPageDetails['attrib']);
			$files[] = $exportPageDetails['files'];
			//bd($files, 'files in getAllFieldData');
		}
	}

	/**
	 * Get field data (as an array) for a page field
	 * NB Repeater fields cause this method to be called recursively
	 *
	 * @param $page
	 * @param $field
	 * @param array $restrictFields
	 * @param array $excludeFields
	 * @param bool $fresh Use fresh pages from DB throughout
	 * @return array
	 *
	 */
	public function getFieldData($page, $field, $restrictFields = [], $excludeFields = [], $fresh = false) {
		$attrib = [];
		$files = [];
		$name = $field->name;
		if($fresh) $page = $this->wire()->pages->getFresh($page->id);
		if(!$page->data($name)) $page->set($name, $page->$field);
		// $page = $this->pages()->findJoin("id={$page->id}", "$name")->first();
		if($page->data($name) == null) return ['attrib' => $attrib, 'files' => $files];  // NB changed from if(!$page->data($name). Review options if this causes probs, but remember need to return empty values.
		//bd([$page, $field, $page->$field], 'page field before setting');
		switch($field->type) {
			case 'FieldtypePage' :
				$attrib[$name] = $this->getPageRef($page->$field);
				break;
			case 'FieldtypeFields':
				$contents = [];
				foreach($page->$field as $fId) {
					$f = $this->wire->fields->get($fId);
					$contents[] = $f->name;
				}
				$attrib[$name] = $contents;
				break;
			case 'FieldtypeTemplates' :
				$contents = [];
				foreach($page->$field as $tId) {
					$t = $this->wire->templates->get($tId);
					$contents[] = $t->name;
				}
				$attrib[$name] = $contents;
				break;
			case 'FieldtypeImage' :
			case 'FieldtypeFile' :
				$contents = [];
				$contents['url'] = $page->$field->url;
				$contents['path'] = $page->$field->path;
				$contents['items'] = [];
				$contents['custom_fields'] = [];
				if(get_class($page->$field) == "ProcessWire\Pageimage" or get_class($page->$field) == "ProcessWire\Pagefile") {
					$items = [$page->$field];  // need it to be an array if only singular image or file
				} else {
					$items = $page->$field->getArray();
				}
				$files[$page->id] = [];
				foreach($items as $item) {
					$itemArray = $item->getArray();
					// don't want these in item as they won't necessarily match in target system
					unset($itemArray['modified']);
					unset($itemArray['created']);
					unset($itemArray['modified_users_id']);
					unset($itemArray['created_users_id']);
					unset($itemArray['formatted']);
					// If there are custom fields, capture these separately
					$imageTemplate = $this->wire()->templates->get('field-' . $name);
					if($imageTemplate) {
						$imageTemplateName = $imageTemplate->name;
						$itemArray['custom_fields']['template'] = $imageTemplateName;
						$templateItems = $imageTemplate->fieldgroup;
						foreach($templateItems as $templateItem) {
							$templateItemName = $templateItem->name;
							$itemArray['custom_fields']['items'][$templateItemName] = $item->$templateItemName;
						}
					}
					$contents['items'][] = $itemArray; // sets unremoved items - basename, description, tags, filesize, as well as any custom fields
					//
					if($field->type == 'FieldtypeImage') {
						$files[$page->id] = array_merge($files[$page->id], $item->getVariations(['info' => true, 'verbose' => false]));
					}
					$files[$page->id][] = $itemArray['basename'];
//					bd($files, 'files for page ' . $page->name);
				}
				$attrib[$name] = $contents;
				//bd($attrib, 'attrib');
				break;
//            case 'FieldtypeTextarea' :
//                  // uses default
//                break;
			case 'FieldtypeOptions' :
				$attrib[$name] = implode('|', $page->$field->explode('id'));
				break;
			case 'FieldtypeRepeater' :
			case 'FieldtypeRepeaterMatrix' :
				$contents = [];
				foreach($page->$field as $item) {
					//bd($item, 'repeater item');
					$itemId = $item->id; // see comment below
					$itemName = $item->name;
					$itemSelector = $item->selector; // see comment below
					$itemParent = $item->parent->path;
					$itemTemplate = $item->template->name;
					$itemData = [];
					$subFields = $item->getFields();
					foreach($subFields as $subField) {
						//bd($subField, 'subfield of type ' . $subField->type);
						if((count($restrictFields) > 0 and !in_array($name, $restrictFields)) or in_array($name, $excludeFields)) continue;
						// recursive call
						$itemDetails = $this->getFieldData($item, $subField, $restrictFields, $excludeFields, $fresh);
						$subData = $itemDetails['attrib'];
						//bd($subData, 'subData');
						$itemData = array_merge_recursive($itemData, $subData);
						//bd([$files, $itemDetails['files']], 'merging files');
						foreach($itemDetails['files'] as $key => $file) {
							$files[$key] = $file;
						} // Can't use $files = array_merge_recursive($files, $itemDetails['files']); because integer indexes get re-sequenced
					}
					if($field->type == 'FieldtypeRepeaterMatrix') {
						$repeater_matrix_type_str = FieldtypeRepeater::templateNamePrefix . 'matrix_type';
						$itemData[FieldtypeRepeater::templateNamePrefix . 'matrix_type'] = $item->$repeater_matrix_type_str;
						$itemData['depth'] = $item->depth;
					}
					if($field->type == 'FieldtypeRepeater') {
						$itemData['depth'] = $item->depth;
					}
					//bd($itemData, 'itemData for ' . $item->name);

					$itemArray = ['template' => $itemTemplate, 'data' => $itemData];
					/*
					* removed 'parent' => $itemParent, 'name' => $itemName, 'id' => $itemId, 'selector' => $itemSelector,
					* (These cause mismatch problems and are not needed for installing the migration)
					*/
					//bd($itemArray, 'repeater array created');
					$contents[] = $itemArray;
					//bd($contents, 'repeater contents 1');
					$repeaterPages[] = $itemParent . $itemName;
					//bd($repeaterPages, '$repeaterPages in getExportPageData');
				}
				//bd($contents, 'repeater contents 2');
				$attrib[$name] = $contents;
				break;
			case 'FieldtypePageTable' :
				$contents = [];
				foreach($page->$field as $items) {
					//bd($item, 'pagetable item');
					$contents['items'] = [];
					$items = $page->$field->getArray();
					foreach($items as $item) {
						$contents['items'][] = ['name' => $item['name'], 'parent' => $item['parent']->path];
					}
					$attrib[$name] = $contents;
				}
				break;
			default :
				if(is_object($page->$field) && property_exists($page->$field, 'data')) {
					//bd([$page, $field, $page->$field, $page->$field->data], 'page field data');
					$attrib[$name] = $page->$field->data;
				} else {
					//bd([$page, $field, $page->$field], 'page field');
					$attrib[$name] = $page->$field;
				}
				break;
		}
		// bd($page, 'page after setting');
		return ['attrib' => $attrib, 'files' => $files];
	}

	/**
	 * Export item for a page ref field
	 *
	 * @param $pageRefObject
	 * @return array|false
	 *
	 */
	public function getPageRef($pageRefObject) {
		if(!$pageRefObject) return false;
		$show = $pageRefObject->path;
		if(!$show) {  // in case of multi-page fields
			$contents = [];
			foreach($pageRefObject as $p) {
				if($p and is_object($p) and $p->id) $contents[] = $p->path;
			}
			$show = $contents;
		}
		return $show;
	}

	/**
	 * Get export data for fields and templates
	 *
	 * @param $k // The sequence number of the migration item (starting at 0)
	 * @param $key
	 * @param $item
	 * @param $object
	 * @param $excludeAttributes
	 * @param $newOld // 'new'. 'old', or 'compare'
	 * @param $compareType // 'new' to create install data; 'old' to create uninstall data
	 * @return array|array[]
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function getExportStructureData($k, $key, $item, $object, $excludeAttributes, $newOld, $compareType) {
		//bd($name, 'getExportDataStructure');
		//bd($item['type'], 'type');
		//bd($name, 'name');
//		bd($object, 'object in getExportStructureData');
		if(!$object) {
			if(!$this->meta('draft')) $this->wire()->session->error($this->_($this->name . ': No object for ' . $item['name'] . '.'));
//                throw new WireException('missing object' . $item['name']); // for debugging
			return ['data' => [], 'files' => []];
		}
		$objectData = $this->dbMigrate->getExportDataMod($object);  // session var no longer used as fix should apply throughout
		if(!$objectData) {
//			bd($objectData, 'objectData in getExportStructureData');
			$this->wire()->session->error($this->_($this->name . ': No object data for ' . $item['name'] . '.'));
			return ['data' => [], 'files' => []];
		}

		if(isset($objectData['id'])) unset($objectData['id']);  // Don't want ids as they may be different in different dbs
		//bd($objectData, 'objectdata');
		if($item['type'] == 'fields') {
			// enhance repeater / page ref / custom field data
			if(isset($objectData['type']) && in_array($objectData['type'], ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix', 'FieldtypePage', 'FieldtypeImage', 'FieldtypeFile'])) {
				$f = $this->wire('fields')->get($objectData['name']);
				if($f) {
					if(in_array($objectData['type'], ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix', 'FieldtypePage'])) {
						$templateId = (int)$f->get('template_id');
						if($templateId) {
							$templateName = $this->wire('templates')->get($templateId)->name;
							$objectData['template_name'] = $templateName;
						}
						unset($objectData['template_id']);

						$parentId = (int)$f->get('parent_id');
						if($parentId && $objectData['type'] == 'FieldtypePage') {   // Don't want to set parent_path for repeaters as not needed and references may differ in the target db
							$parentPath = $this->wire('pages')->get($parentId)->path;
							$objectData['parent_path'] = $parentPath;
						}
						unset($objectData['parent_id']);

						$templateIds = $f->get('template_ids');
						if($templateIds) {
							$objectData['template_names'] = [];
							foreach($templateIds as $templateId) {
								$templateName = $this->wire('templates')->get($templateId)->name;
								$objectData['template_names'][] = $templateName;
							}
						}
						unset($objectData['template_ids']);

						// Repeater matrix fields have field ids set in the in matrix{n}_field array.
						// We need to convert these to  names so that they can be found in the target.
						if($objectData['type'] == 'FieldtypeRepeaterMatrix') {
							$maxTypes = ($f instanceof RepeaterMatrixField) ? $f->getMaxMatrixTypes() : 0;
							if($maxTypes > 0) {
								for($typeCount = 1; $typeCount <= $maxTypes; $typeCount++) {
									$matrixItems = $f->get("matrix{$typeCount}_fields");
									if($matrixItems !== null) {
										$matrixItemNames = [];
										foreach($matrixItems as $matrixItem) {
											$matrixItemNames[] = $this->fields()->get($matrixItem)->name;
										}
										$objectData["matrix_field_names"][$typeCount] = $matrixItemNames;
										// (set this as an array rather than "matrix{$typeCount}_field_names" as we can foreach that more easily on the install)
										unset($objectData["matrix{$typeCount}_fields"]);
									}
								}
							}
						}
						if($objectData['type'] == 'FieldtypeRepeaterMatrix' || $objectData['type'] == 'FieldtypeRepeater') {
							unset($objectData['fieldContexts']); // unnecessary as contained in the related template
						}

					} else {              // images and files
						$customFields = 'field-' . $objectData['name'];
						if($this->wire()->templates->get($customFields)) {
							$objectData['template_name'] = $templateName = $customFields;
						}
					}
				}
				// and check that the corresponding template is earlier in the migration (but not for database comparisons)
				if($this->template == ProcessDbMigrate::MIGRATION_TEMPLATE) {
					$templateOk = false;
					$i = 0;
					$allItems = $this->dbMigrateItem;
					if($compareType == 'new' and $newOld == 'new' and isset($templateName)) {
						//bd($templateName, 'templatename');
						foreach($allItems as $other) {
							/* @var $other RepeaterDbMigrateItemPage */
							//bd($other, 'other - item ' . $i);
							//bd($other->dbMigrateType, 'other type');
							if($i >= $k) break;
							$i++;
							if($other->dbMigrateType->value == 'templates' and $other->dbMigrateName == $templateName) {
								$templateOk = true;
								break;
							}
						}
						if(!$templateOk) {
							$fieldType = strtolower(str_replace('Fieldtype', '', $objectData['type']));
							$w2 = ($item['action'] == 'new') ? '. ' . sprintf($this->_('Template %1$s should be specified (as new) before the %2$s field.'), $templateName, $fieldType) :
								'. ' . sprintf($this->_('If it has changed, consider if template %1$s should be specified (as changed) before the %2$s field.'), $templateName, $fieldType);
							$this->wire()->session->warning(sprintf($this->_("No template specified earlier in migration item list for %s field: "), $fieldType) . $objectData['name'] . $w2);
						}
					}
				}
			}
		}
		foreach($excludeAttributes as $excludeAttribute) {
			unset($objectData[$excludeAttribute]);
		}
		$data[$key] = $objectData;
		//bd($data, 'returning objectdata');
		return ['data' => $data, 'files' => []];
	}

	/**
	 * Return an array of item details with action swapped if requested
	 *
	 * @param $migrationItem
	 * @param bool $swap
	 * @return array
	 *
	 */
	protected function populateItem($migrationItem, $swap = false) {
		$item['type'] = $migrationItem->dbMigrateType->value; // fields, templates or pages
		if($migrationItem->dbMigrateAction) {
			if($swap) {
				// swap new and removed for uninstall
				$item['action'] = ($migrationItem->dbMigrateAction->value == 'new')
					? 'removed' : ($migrationItem->dbMigrateAction->value == 'removed' ? 'new' : 'changed');
			} else {
				$item['action'] = $migrationItem->dbMigrateAction->value; // new, changed or removed as originally set
			}
		}
		$item['name'] = $migrationItem->dbMigrateName;  // for pages this is path or selector
		$item['oldName'] = $migrationItem->dbMigrateOldName; // for pages this is path
		$item['id'] = $migrationItem->id;
		return $item;
	}

	/**
	 * Converts 3 indexes to one: type->action->name
	 * Used for presentation purposes in previews
	 *
	 * @param $data
	 * @param $keyOnly
	 * @return array
	 * @throws WireException
	 *
	 */
	public function compactArray($data, $keyOnly = null) {
		$newData = [];
		foreach($data as $entry) {
			if(is_array($entry)) foreach($entry as $type => $line) {
				if($type == 'sourceDb' || $type == 'sourceSiteUrl') continue; // Ignore source database tags in comparisons
				//bd([$type, $line], 'compact item');
				if(is_array($line)) foreach($line as $action => $item) {
					if(is_array($item)) foreach($item as $name => $values) {
						if($type == 'pages' and $action == 'removed' and !$this->wire()->sanitizer->path($name)) {
							/*
							* don't want removed pages with selector as they get expanded elsewhere
							 * (ToDo Consider deleting $action=='removed' condition as probably irrelevant)
							*/
							continue;
						}
						//bd([$name, $values], 'compact item - name, values');
						if(isset($values['id'])) unset($values['id']);
						if($keyOnly) {
							foreach($values as $k => $v) {
								if(!in_array($k, self::KEY_DATA_FIELDS)) unset($values[$k]);
							}
						}
						$newData[$type . '->' . $action . '->' . $name] = $values;
					}
				}
			}
		}
		return $newData;
	}

	/**
	 * Returns an array of all differences between $A and $B
	 * This is done recursively such that the first node in the tree where they differ is returned as a 2-element array
	 * > > The first of these 2 elements is the $A value and the second is the $B value
	 * > > This 2-element array is stored at the bottom of a tree with all the keys that match above it
	 *
	 * @param array $A
	 * @param array $B
	 * @return array  arrays at bottom nodes should all be of 2 elements
	 *
	 */
	public function array_compare(array $A, array $B) {
		//bd($A, 'array $A');
		//bd($B, 'array $B');
		// $C will have all elements in $A not in $B (stored as [$A element, ''])
		// plus all elements in both where they differ at key value $k, say, stored as $k => [$A element, $B element]
		$C = $this->arrayRecursiveDiff_assoc($A, $B);
		//bd($C, 'array $C');
		// $D will have all elements in $B not in $A (stored as ['', $B element])
		// plus all elements in both where they differ at key value $k, say, stored as $k => [$A element, $B element]
		$D = $this->arrayRecursiveDiff_assoc($B, $A, true);
		//bd($D, 'array $D');
		// ideally array_merge should remove duplication where $C and $D have identical elements, but it doesn't so remove them from $D first
		$R = array_merge_recursive($C, $this->arrayRecursiveDiff_key($D, $C));
		//$this->arrayRecursiveDiff_key($D, $C), 'arrayRecursiveDiff_key($D, $C)');
		//bd($R, 'array $R');

		// SORT NOT IMPLEMENTED
//        uksort($R, function($a, $b) use ($A, $B) {
//            if (array_key_exists($a, $A) and !array_key_exists($a, $B)) return -1;
//            if (array_key_exists($a, $B) and !array_key_exists($a, $A)) return 1;
//            if (array_key_exists($b, $A) and !array_key_exists($b, $B)) return 1;
//            if (array_key_exists($b, $B) and !array_key_exists($b, $A)) return -1;
//            return 0;
//        });
//        //bd($R, ' sorted array $R');

		return $R;
	}


	/**
	 * Return a php array of pages matching the selector
	 * Allow the possibility of sort=path in the selector
	 * Sort the array so that dependencies are in the right order
	 *
	 * @param $item
	 * @param $itemName
	 * @param $newOld
	 * @return array
	 * @throws WireException
	 *
	 * / NOT CURRENTLY USED
	 * protected function getSelectorResult($item, $itemName, $newOld) {
	 * try {
	 * $results = $this->wire($item['type'])->find($itemName);
	 * $results = $results->getArray(); // want them as a php array not an object
	 * // the array is of page objects, but that's OK here because getExportPageData allows objects or path names
	 * // selectors do not respect sort=path - we want parents before children and natural sort order is, well, more natural
	 * if ($itemType = 'pages' and strpos($itemName, 'sort=path')) {
	 * usort($results, function ($a, $b) {
	 * return strnatcmp($a->path, $b->path);
	 * });
	 * if ($item['action'] == 'removed') $results = array_reverse($results); // children before parents when deleting!
	 * }
	 * if ($newOld == 'old') $results = array_reverse($results); // To make sure page actions occur in reverse on uninstall
	 * // NB Removed  {and $item['action'] == 'removed'} from condition here
	 * $selector = true;
	 *
	 * } catch (WireException $e) {
	 * $this->wire()->session->error($this->_('Invalid selector: ') . $itemName);
	 * $selector = false;
	 * $results = [];
	 * }
	 * return ['selector' => $selector, 'results' => $results];
	 * }
	 */

	/**
	 * Return will have all elements in $A not in $B (stored as [$A element, ''])
	 * > plus all elements in both where they differ at key value $k, say, stored as $k => [$A element, $B element]
	 * (NB a difference will not be reported where, for example, the A element is 0 and the B element is "")
	 * If $swap = true then the pair will have its elements swapped.
	 *
	 * @param $aArray1
	 * @param $aArray2
	 * @param false $swap // swap array order in return
	 * @param int $deep
	 * @return array
	 *
	 */
	public function arrayRecursiveDiff_assoc($aArray1, $aArray2, $swap = false, $deep = 0) {
		if($deep == 0) {
			$noValueText = $this->_('No Object');
			$noValueMarker = '<!--!!NO_OBJECT!!-->';
		} else {
			$noValueText = $this->_('No Value');
			$noValueMarker = '<!--!!NO_VALUE!!-->';
		}

		$noValue = $noValueMarker . '<span style="color:grey">(' . $noValueText . ')</span>';
		$aReturn = array();
		foreach($aArray1 as $mKey => $mValue) {
			if(array_key_exists($mKey, $aArray2)) {
				if(is_array($mValue) and is_array($aArray2[$mKey])) {
					$deep += 1;
					$aRecursiveDiff = $this->arrayRecursiveDiff_assoc($mValue, $aArray2[$mKey], $swap, $deep);
					if(count($aRecursiveDiff)) {
						$aReturn[$mKey] = $aRecursiveDiff;
					}
				} else {
					if(($mValue || $aArray2[$mKey]) && $mValue != $aArray2[$mKey]) {
						$aReturn[$mKey] = (!$swap) ? [$aArray2[$mKey], $mValue] : [$mValue, $aArray2[$mKey]];
					}
				}
			} else {
				if($mValue) $aReturn[$mKey] = (!$swap) ? [$noValue, $mValue] : [$mValue, $noValue];
			}
		}
		return $aReturn;
	}

	/**
	 * return elements in array1 which do not match keys (all the way down) in array2
	 *
	 * @param $aArray1
	 * @param $aArray2
	 * @return array
	 *
	 */
	public function arrayRecursiveDiff_key($aArray1, $aArray2) {
		$aReturn = array();
		foreach($aArray1 as $mKey => $mValue) {
			if(array_key_exists($mKey, $aArray2)) {
				if(is_array($mValue)) {
					$aRecursiveDiff = $this->arrayRecursiveDiff_key($mValue, $aArray2[$mKey]);
					if(count($aRecursiveDiff)) {
						$aReturn[$mKey] = $aRecursiveDiff;
					}
				} else {
					return [];
				}
			} else {
				$aReturn[$mKey] = $mValue;
			}
		}
		return $aReturn;
	}

	/**
	 * Remove url and path from reported differences in image fields
	 * They will almost always be different in the source and target databases
	 * Also, replace links in rich text fields with the correct files directory (as page ids may differ between source and target dbs) before seeing if they are truly different
	 *
	 * @param $diffs // The current source<->target differences
	 * @param $newOld // 'new' (if comparing new/data.json), 'old' (if comparing old/data.json), or 'both' (if comparing new/data.json to old/data.json)
	 * @return mixed
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function pruneImageFields($diffs, $newOld) {
		//bd($diffs, 'Page diffs before unset');
		foreach($diffs as $pName => $data) {
			if(is_array($data)) {
				if(strpos($pName, 'pages') === 0) {
//					$diffsRemain = [];
					foreach($data as $fName => $values) {
						$diffsRemain[$pName][$fName] = true;
						//bd([$fName => $values], "[Field name => Values] in pruneImageFields for $pName");
						if(!$values or !is_array($values) or count($values) == 0) {
							continue;
						}
						$field = $this->wire('fields')->get($fName);

						$pruned = $this->pruneDetails($diffs, $diffsRemain, $field, $pName, $fName, $values, $newOld);
						$diffs = $pruned['diffs'];


					}
				}
			}
		}
		//bd($diffs, 'Page diffs after unset');
		return $diffs;
	}

	protected function pruneDetails($diffs, $diffsRemain, $field, $pName, $fName, $values, $newOld, $depth = []) {

		//bd(['pName' => $pName, 'fName' => $fName, 'values' => $values, 'newOld' => $newOld, 'depth' => $depth], 'in prune details');
		$keyChainAll = $keyChainUrl = $keyChainPath = $keyChain = array_merge([$pName, $fName], $depth);
		array_push($keyChainUrl, 'url');
		array_push($keyChainPath, 'path');
		array_push($keyChainAll, 'ALLKEYS');

		if($field and ($field->type == 'FieldtypeImage' or $field->type == 'FieldtypeFile')) {

			if(isset($values['url'])) $diffs = $this->del($diffs, $keyChainUrl);
			//bd(['diffs'=> $diffs, 'values' => $values], 'diffs after unset url');
			if(isset($values['path'])) $diffs = $this->del($diffs, $keyChainPath);

			$diffs = $this->array_filter_recursive($diffs);

		} else if($field and $field->type == 'FieldtypeTextarea') {
			if($this->meta['idMap'] and count($values) == 2) {     // we have the different 2 vals and an idMap to fix the image/file links
				$newVals = [];
				foreach($values as $value) {
					$newVals[] = $this->replaceLink($value, $this->meta['idMap'], $newOld, true);
				}
				//bd($newVals, 'new vals');
				if($newVals and $newVals[0] == $newVals[1]) {
					//bd(['pName' => $pName, 'fName' => $fName, 'depth' => $depth], 'unset ALLKEYS');

					$diffs = $this->del($diffs, $keyChainAll);

				}
			}
		} else if($field && ($field->type == 'FieldtypeRepeater' || $field->type == 'FieldtypeRepeaterMatrix')) {
			foreach($values as $key => $value) {
				if(!is_array($value) or count($value) == 0 or !isset($value['data'])) {
					continue;
				}
				$newData = $value['data'];
				foreach($newData as $newFName => $newValues) {
					$tempDepth = $depth; // Need to reset depth so that it is only recursive while the fields are repeaters
					$newField = $this->fields()->get($newFName);
					$ct = array_push($tempDepth, $key, 'data', $newFName);
					$pruned = $this->pruneDetails($diffs, $diffsRemain, $newField, $pName, $fName, $newValues, $newOld, $tempDepth);
					$diffs = $pruned['diffs'];
					$diffsRemain = $pruned['diffsRemain'];
				}
			}

		}

		return ['diffs' => $diffs, 'diffsRemain' => $diffsRemain];
	}

	public function del($target, $keyChain) {
		//bd(['target' => $target, 'keyChain' => $keyChain], 'diffs entering deepUnset v2');
		foreach($target as $key => $value) {
			$testKeyChain = $keyChain;
			$testKey = array_shift($testKeyChain);
			if(is_array($value)) {
				//bd(['key' => $key, 'testKey' => $testKey, 'testKeyChain' => $testKeyChain], '$value is array');
				if($testKey == $key || $testKey == 'ALLKEYS') {
					$target[$key] = $this->del($value, $testKeyChain);
					if(!count($target[$key])) {
						unset($target[$key]);
					}
				} else {
					continue;
				}
			} else if(!count($testKeyChain)) {
				//bd(['testKey' => $testKey, 'testKeyChain' => $testKeyChain], '$value is not array');
				unset($target[$key]);
			}
		}
		//bd(['target' => $target, 'keyChain' => $keyChain], 'diffs returning from deepUnset v2');
		return $target;
	}


	/**
	 * Recursively filter an array
	 *
	 * @param array $array
	 *
	 * @return array
	 */
	public function array_filter_recursive(array $array) {
		foreach($array as $key => $row) {
			if(!is_array($row)) {
				if(!$row) {
					unset($array[$key]);
				}
				return $array;
			}
			if(!$this->array_filter_recursive($row)) unset($array[$key]);
		}
		return $array;
	}

	/**
	 * Using the idMapArray (see setIdMap() ) replace original page id directories in <img> tags with destination page id directories
	 *
	 * @param $html
	 * @param $idMapArray
	 * @param $newOld // 'new' (if comparing new/data.json), 'old' (if comparing old/data.json), or 'both' (if comparing new/data.json to old/data.json)
	 * @param bool $checkFileEquality
	 * @return string|string[]|null
	 * @throws WireException
	 *
	 */
	protected function replaceLink($html, $idMapArray, $newOld, $checkFileEquality = false) {
		//bd(['html' => $html, 'idMap' => $idMapArray, 'newOld' => $newOld, 'checkFileEquality' => $checkFileEquality], 'In replaceLink');
		if(strpos($html, '<img') === false and strpos($html, '<a') === false) return $html; //return early if no images or links are embedded in html
		//bd($html, 'old html');
		if(!$checkFileEquality) l('HTML: ' . $html, 'debug');
		// In case one of the source and target sites have a segment root
		// NB doesn't handle the case where they both have segments, but are different!
		$targetSiteUrl = $this->wire()->config->urls->site;
		$sourceSiteUrl = ($this->sourceSiteUrl) ?: '/site/';
		if(strlen($this->wire()->config->urls->site) > strlen($sourceSiteUrl)) {
			$segDiff = 1;
			$siteSegment = str_replace($sourceSiteUrl, '', $targetSiteUrl);
		} else if(strlen($this->wire()->config->urls->site) < strlen($sourceSiteUrl)) {
			$segDiff = -1;
			$siteSegment = str_replace($this->wire()->config->urls->site, '', $sourceSiteUrl);
		} else {
			$segDiff = 0;
			$siteSegment = null;
		}
		if($newOld != 'old' && $siteSegment && $segDiff == 1) {
			$siteSegment = trim($siteSegment, '/');

			/*
			 * The regex is intended to match:
			 * 	(a) relative references
			 *  (b) references with the httpHost name
			 * and add the segment prefix for the target site at the start (a) or after the httpHost name (b)
			 * NB This only works if the source site has no segment prefix or has one identical to the target
			 */
			$re = '/(=\"|' . preg_quote($this->wire()->config->httpHost, '/') . ')\/(?!' . preg_quote($siteSegment, '/') . '\/)(.*)(?=\")/mU';
			//bd($re, 'regex');
			preg_match_all($re, $html, $matches, PREG_SET_ORDER, 0);
			if($matches) {
				foreach($matches as $match) {
					if(!$checkFileEquality) l('MATCH[2]: ' . $match[2], 'debug');
					//bd($match[2], 'match 2');
					$html = str_replace($match[2], $siteSegment . '/' . $match[2], $html);
				}
			}
		}
		//bd($html, 'new html');
		if(!$checkFileEquality) l('New HTML: ' . $html, 'debug');


		if(!$idMapArray) return $html;
		foreach($idMapArray as $origId => $destId) {
			//bd([$origId, $destId], 'Id pair');
			$re = '/(' . str_replace('/', '\/', preg_quote($this->wire()->config->urls->files)) . ')' . $origId . '(\/)/mU';
			//bd($re, 'regex pattern');
			if($checkFileEquality) {
				// check that the files in $destId directory are the same as in the migration directory
				// If any (referenced in the html) are different then don't amend the html for the new path
				if($newOld == 'both') {
					$destPath = $this->wire()->config->paths->templates . ProcessDbMigrate::MIGRATION_PATH .
						$this->name . '/' . 'old' . '/files/' . $destId . '/';
					$migPath = $this->wire()->config->paths->templates . ProcessDbMigrate::MIGRATION_PATH .
						$this->name . '/' . 'new' . '/files/' . $origId . '/';
				} else {
					$destPath = $this->wire()->config->paths->files . $destId . '/';
					$migPath = $this->wire()->config->paths->templates . ProcessDbMigrate::MIGRATION_PATH .
						$this->name . '/' . $newOld . '/files/' . $origId . '/';
				}
				$destFiles = $this->wire()->files->find($destPath);
				$migFiles = $this->wire()->files->find($migPath);
				array_walk($destFiles, function(&$item, $k) {
					$item = basename($item);
				});
				array_walk($migFiles, function(&$item, $k) {
					$item = basename($item);
				});
				//bd([$destPath, $migPath], '[$destPath, $migPath]');
				//bd([$destFiles, $migFiles], '[$destFiles, $migFiles]');
				$commonFiles = array_intersect($destFiles, $migFiles);
				$foundFiles = [];
				foreach($commonFiles as $commonFile) {
					//bd(basename($commonFile), 'checking file in html');
					if(strpos($html, basename($commonFile)) === false) continue;
					$foundFiles[] = $commonFile;
				}
				//bd($foundFiles, 'files in html');
				foreach($foundFiles as $foundFile) {
					$destFile = $destPath . $foundFile;
					$migFile = $migPath . $foundFile;
					//bd([$destFile, $migFile], 'Test file equality');
					if(filesize($destFile) != filesize($migFile)
						or md5_file($destFile) != md5_file($migFile)
					) return $html;
					//bd([$destFile, $migFile], 'Files are equal');
				}
			}
			$html = preg_replace($re, '${1}' . $destId . '$2', $html);
		}
		//bd($html, 'Returning html from replaceLink');
		return $html;
	}

	/**************************************************************
	 ******************* INSTALL SECTION **************************
	 *************************************************************/


	/**
	 * This installs ('new') or uninstalls ('old') depending on directory with json files
	 *
	 * @param $newOld // 'new' to install, 'old' to uninstall
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function installMigration($newOld) {
		if(!$this->ready and $this->name != 'dummy-bootstrap') $this->ready();  // don't call ready() for dummy-bootstrap as it has no template assigned at this point
		$this->wire()->log->save('debug', 'In install with newOld = ' . $newOld);

		// Backup the old installation first
		if($newOld == 'new' and $this->name != 'dummy-bootstrap') $this->exportData('old');
		/*
		* NB The bootstrap is excluded from the above. A separate (manually constructed) 'old' file is provided
		 * for the bootstrap as part of the module and is used when uninstalling the module.
		*/
		$name = ($this->name == 'dummy-bootstrap') ? 'bootstrap' : $this->name;

		// Get the migration .json file for this migration
		$migrationPath = $this->wire('config')->paths->templates . ProcessDbMigrate::MIGRATION_PATH . $name . '/';
		// NB cannot use $this->migrationsPath because as it fails with dummy-bootstrap (no template yet!)

		$migrationPathNewOld = $migrationPath . $newOld . '/';
		if(!is_dir($migrationPathNewOld)) {
			//bd($migrationPath, '$migrationPath. Name is ' . $name);
			$error = ($newOld == 'new') ? $this->_('Cannot install - ') : $this->_('Cannot uninstall - ');
			$error .= sprintf($this->_('No "%s" directory for this migration.'), $newOld);
			$this->wire()->session->error($error);
			return;
		}
		$dataFile = (file_exists($migrationPathNewOld . 'data.json'))
			? file_get_contents($migrationPathNewOld . 'data.json') : null;
		if(!$dataFile) {
			$error = ($newOld == 'new') ? $this->_('Cannot install - ') : $this->_('Cannot uninstall - ');
			$error .= sprintf($this->_('No "%s" data.json file for this migration.'), $newOld);
			if($name == 'bootstrap') {
				$error .= $this->_(' Copy the old/data.json file from the module directory into the templates directory then try again?');
			}
			$this->wire()->session->error($error);
			return;
		}
		$dataArray = wireDecodeJSON($dataFile);

		$message = [];
		$warning = [];
		$pagesInstalled = [];
		foreach($dataArray as $repeat) {
			foreach($repeat as $itemType => $itemLine) {
				//bd($itemLine, 'itemline');
				foreach($itemLine as $itemAction => $items) {
					//bd($items, 'items');
					if($itemAction != 'removed') {
						$this->wire()->session->set('dbMigrate_install', true);  // for use by host app. also used in beforeSave hook in ProcessDbMigrate.module
						switch($itemType) {
							// NB code below should handle multiple instances of objects, but we only expect one at a time for fields and templates
							case 'fields':
								$this->installFields($items, $itemType);
								break;
							case 'templates' :
								$this->installTemplates($items, $itemType);
								break;
							case 'pages' :
								//bd($items, 'items for install');
								$pagesInstalled = array_merge($pagesInstalled, $this->installPages($items, $itemType, $newOld));
								break;
						}
						$this->wire()->session->remove('dbMigrate_install');
					} else {
						$this->removeItems($items, $itemType);
					}
				}
			}
		}
		//bd($pagesInstalled, 'pages installed');
		if($this->name != 'dummy-bootstrap') {
			// update any images in RTE fields (links may be different owing to different page ids in source and target dbs)
			$idMapArray = $this->setIdMap($pagesInstalled);
			//bd($idMapArray, 'idMapArray');

			$this->fixRteHtml($pagesInstalled, $idMapArray, $newOld);

			$this->exportData('compare'); // sets meta('installedStatus')
			$this->wire()->pages->___save($this, array('noHooks' => true, 'quiet' => true));
			$this->meta('updated', true);
		}
		if($message) $this->wire()->session->message(implode(', ', $message));
		if($warning) $this->wire()->session->warning(implode(', ', $warning));
		//bd($newOld, 'finished install');
	}

	protected function fixRteHtml($pagesInstalled, $idMapArray, $newOld) {
		foreach($pagesInstalled as $page) {
			//bd($page, 'RTE? page');
			foreach($page->getFields() as $field) {
				//bd([$page, $field], 'RTE? field');
				if($field->type == 'FieldtypeTextarea') {
					//bd([$page, $field], 'RTE field Y');
					//bd($page->$field, 'Initial html');
					$html = $page->$field;
					$html = $this->replaceLink($html, $idMapArray, $newOld);
					//bd($html, 'returned html');
					$page->$field = $html;
					$page->of(false);
					$page->save($field);
				}
				if($field->type == 'FieldtypeRepeater' || $field->type == 'FieldtypeRepeaterMatrix') {
					$repeaterPagesInstalled = $page->$field;
					$idMapArray = array_merge($idMapArray, $this->setIdMap($repeaterPagesInstalled));
					$this->fixRteHtml($repeaterPagesInstalled, $idMapArray, $newOld);
				}
			}
		}
	}

	/**
	 * Install fields in the migration
	 *
	 * @param $items
	 * @param $itemType
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function installFields($items, $itemType) {
		$this->wire()->log->save('debug', 'install fields');
		$items = $this->pruneKeys($items, $itemType);
		// repeater fields should be processed last as they may depend on earlier fields
		$repeaters = [];
		$pageRefs = [];
		$names = [];
		foreach($items as $name => $data) {
			// Don't want items which are selectors (these may be present if the selector yielded no items)
			if(!$this->wire()->sanitizer->validate($name, 'name')) continue;
			// keep track of all names for later saving
			$names[] = $name;
			// remove the repeaters to a separate array
			if(in_array($data['type'], ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix'])) {
				unset($items[$name]);
				$repeaters[$name] = $data;
			} else if($data['type'] == 'FieldtypePage') {
				unset($items[$name]);
				$pageRefs[$name] = $data;
			}
		}
		/*
		 * Process the non-repeaters and non-pagerefs first
		 */
		// method below is largely from PW core
		if($items) $this->processImport($items);

		// now the page refs
		//bd($pageRefs, 'page refs');
		$newPageRefs = [];
		foreach($pageRefs as $fName => $fData) {
			if(isset($fData['template_name'])) {
				$tName = $fData['template_name'];
				$t = $this->wire('templates')->get($tName);
				if($t) {
					$fData['template_id'] = $t->id;
				} else {
					$this->wire()->session->error(sprintf(
							$this->_('Cannot install field %1$s properly because template %2$s is missing. Is it missing or out of sequence in the installation list?'),
							$fName,
							$tName)
					);
				}
				unset($fData['template_name']);  // it was just a temp variable - no meaning to PW
			}

			if(isset($fData['parent_path'])) {
				$pPath = $fData['parent_path'];
				$pt = $this->wire('pages')->get($pPath);
				if($pt) {
					$fData['parent_id'] = $pt->id;
				} else {
					$this->wire()->session->error(sprintf(
							$this->_('Cannot install field %1$s properly because parent page %2$s is missing. Is it missing or out of sequence in the installation list?'),
							$fName,
							$pPath)
					);
				}
				unset($fData['parent_path']);  // it was just a temp variable - no meaning to PW
			}

			if(isset($fData['template_names'])) {
				$tNames = $fData['template_names'];
				if($tNames) {
					$fData['template_ids'] = [];
					foreach($tNames as $tName) {
						$t = $this->wire('templates')->get($tName);
						if($t) {
							$fData['template_ids'][] = $t->id;
						}
					}
				}
				unset($fData['template_names']);  // it was just a temp variable - no meaning to PW
			}

			$newPageRefs[$fName] = $fData;
		}
		//bd($newPageRefs, 'new page refs');
		if($newPageRefs) $this->processImport($newPageRefs);

		// then check the templates for the repeaters - they should be before the related field in the process list
		foreach($repeaters as $repeaterName => $repeater) {
			$templateName = FieldtypeRepeater::templateNamePrefix . $repeater['name'];
			$t = $this->wire()->templates->get($templateName);
			if(!$t) {
				$this->wire()->session->error(sprintf(
						$this->_('Cannot install repeater %1$s because template %2$s is missing. Is it missing or out of sequence in the installation list?'),
						$repeaterName,
						$templateName)
				);
				unset($repeaters[$repeaterName]);
			}
		}
		// Now install the repeaters
		// but first set the template id to match the template we have
		$newRepeaters = [];
		foreach($repeaters as $fName => $fData) {
			$tName = $fData['template_name'];
			$t = $this->wire('templates')->get($tName);
			if($t) {
				$fData['template_id'] = $t->id;
			}
			unset($fData['template_name']);  // it was just a temp variable - no meaning to PW

			if($fData['type'] == 'FieldtypeRepeaterMatrix') {
				if(isset($fData['matrix_field_names'])) {
					foreach($fData['matrix_field_names'] as $matrixType => $itemNames) {
						$itemIds = array();
						foreach($itemNames as $itemName) {
							$itemIds[] = $this->fields()->get($itemName)->id;
						}
						$fData["matrix{$matrixType}_fields"] = $itemIds;
					}
				}
				unset($fData['matrix_field_names']); // it was just a temp variable - no meaning to PW
				//bd($fData, 'fData');
			}

			$newRepeaters[$fName] = $fData;
		}
		if($newRepeaters) $this->processImport($newRepeaters);

		// We have to get export data now as it triggers the config fields. Otherwise install has to be run twice in some situations
		foreach($names as $name) {
			$f = $this->wire()->fields->get($name);
			//bd($f, 'pre-getting export data');
			if($f) {
				$objectData = $this->dbMigrate->getExportDataMod($f);
			}
		}
	}

	/**
	 * Where keys of $data are in the format of a pair x|y, replace this by just the pair member that exists in the current database
	 *
	 * @param array $data
	 * @param $type
	 * @return array|null
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function pruneKeys(array $data, $type) {
		if(!in_array($type, ['fields', 'templates', 'pages'])) return null;
		$newData = [];
		foreach($data as $key => $value) {
			if(strpos($key, '|')) {
				$exists = [];
				$both = explode('|', $key);
				foreach($both as $i => $keyTest) {
					if($type == 'pages') {
						$exists[$i] = ($this->wire($type)->get($keyTest) and $this->wire($type)->get($keyTest)->id);
					} else {
						$exists[$i] = ($this->wire($type)->get($keyTest));
					}
				}
				if($exists[0] and $exists[1]) {
					$this->wire()->session->error(sprintf($this->_('Unable to change name for %s as both names already exist'), implode('|', $both)));
					continue;
				} else if(!$exists[0] and !$exists[1]) {
					$this->wire()->session->error(sprintf($this->_('Error in %1$s. Neither "%2$s" nor "%3$s" exists.'), $type, $both[0], $both[1]));
					continue;
				} else {
					$key2 = ($exists[0]) ? $both[0] : $both[1];
					$newData[$key2] = $value;
				}
			} else {
				$newData[$key] = $value;
			}
		}
		return $newData;
	}

	/**
	 * Set import data for fields
	 *
	 * This is a direct lift from ProcessFieldExportImport with certain lines commented out as not required or amended with MDE annotation
	 * It has now been so heavily hacked that it should probably be rewritten
	 *
	 * @param $data array - decoded from JSON
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function processImport(array $data) {         //MDE parameter added

//        $data = $this->session->get('FieldImportData');  //MDE not required
		if(!$data) throw new WireException("Invalid import data");

		$numChangedFields = 0;
		$numAddedFields = 0;
//        $skipFieldNames = array(); //MDE not applicable

		// iterate through data for each field
		foreach($data as $name => $fieldData) {
			// Don't want items which are selectors (these may be present if the selector yielded no items)
			if(!$this->wire()->sanitizer->validate($name, 'name')) continue;
			//
			$name = $this->wire('sanitizer')->fieldName($name);

			// MDE not applicable
//            if(!$this->input->post("import_field_$name")) {
//                $skipFieldNames[] = $name;
//                unset($data[$name]);
//                continue;
//            }

			$field = $this->wire('fields')->get($name);

			if(!$field) {
				$new = true;
				$field = $this->wire(new Field());
				$field->name = $name;
			} else {
				$new = false;
				//MDE added
				//bd($fieldData, 'data in processImport before resetting');

				// If field data is not set, remove it from original
				$oldData = $field->getArray();
				foreach($oldData as $key => $value) {
					if(!isset($fieldData[$key]) and $key != 'id') {
						$field->remove($key);
						//bd($key, 'removed key');
					}
				}
				$field->save();
				//bd($field->getArray(), ' field data in processImport after resetting');
				//MDE end of added
			}

			unset($fieldData['id']);
			//MDE not applicable
//            foreach($fieldData as $property => $value) {
//                if(!in_array($property, $this->input->post("field_$name"))) {
//                    unset($fieldData[$property]);
//                }
//            }

			try {
//bd($fieldData, 'fieldData');
				if(!wire('page')) wire()->set('page', $this); // To prevent spurious errors from InputfieldSelect, as we may not be on an actual page
				$changes = $field->setImportData($fieldData);
				//bd($changes, $field->name . ': changes in processimport');
				//MDE modified this section to provide better notices but suppress error re importing options
				foreach($changes as $key => $info) {
					if($info['error'] and strpos($key, 'export_options') !== 0) {  // options have been dealt with by fix below, so don't report this error
						//bd(get_class($field->type), 'reporting error');
						$this->wire()->session->error($this->_('Error:') . " $name.$key => $info[error]");
					} else {
						$this->message($this->_('Saved:') . " $name.$key => $info[new]");
					}
				}
				// MDE end of mod


				//bd($field, 'field before save');
				$field->save();
				// MDE section added to deal with select options fields, which setImportData() does not fully handle
				if($field->type == 'FieldtypeOptions') {
					$manager = $this->wire(new SelectableOptionManager());
					// get the options string from the data file array
					$options = $fieldData['export_options']['default'];
					// now set the options, removing any others that were there
					$manager->setOptionsString($field, $options, true);
					$field->save();
				}
				// MDE end of mod
				if($new) {
					$numAddedFields++;
					$this->message($this->_('Added field') . ' - ' . $name);
				} else {
					$numChangedFields++;
					$this->message($this->_('Modified field') . ' - ' . $name);
				}
			} catch(Exception $e) {
				$this->error($e->getMessage());
			}

			$data[$name] = $fieldData;
		}

//        $this->session->set('FieldImportSkipNames', $skipFieldNames);  //MDE not applicable
//        $this->session->set('FieldImportData', $data); //MDE not applicable
//        $numSkippedFields = count($skipFieldNames);  //MDE not applicable
		if($numAddedFields) $this->message(sprintf($this->_n('Added %d field', 'Added %d fields', $numAddedFields), $numAddedFields));
		if($numChangedFields) $this->message(sprintf($this->_n('Modified %d field', 'Modified %d fields', $numChangedFields), $numChangedFields));
//        if($numSkippedFields) $this->message(sprintf($this->_n('Skipped %d field', 'Skipped %d fields', $numSkippedFields), $numSkippedFields)); //MDE not used
//        $this->session->redirect("./?verify=1");  //MDE not used
	}

	/**
	 * Install templates in the migration
	 *
	 * @param $items
	 * @param $itemType
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function installTemplates($items, $itemType) {
		$items = $this->pruneKeys($items, $itemType);
		foreach($items as $name => $data) {
			// Don't want items which are selectors (these may be present if the selector yielded no items)
			if(!$this->wire()->sanitizer->validate($name, 'name')) continue;

			$t = $this->wire('templates')->get($name);
			if($t) {
				$result = $t->setImportData($data);
			} else {
				$t = $this->wire(new Template());
				/* @var $t Template */
				$result = $t->setImportData($data);
			}
//			bd([$t, $result], 'template result');
			if(isset($t) and $t and $result) {
				$this->saveItem($t);
				$this->wire()->session->message($this->_('Saved new settings for ') . $name);
			} else {
				if($result) {
					$this->wire()->session->warning(implode('| ', $result));
				} else {
					$this->wire()->session->message($this->_('No changes to ') . $name);
				}
			}
		}
	}

	/**
	 * Save template after setting fieldgroup contexts
	 *
	 * @param $item
	 *
	 */
	public function saveItem($item) {
		//bd(debug_backtrace(), ' Backtrace in saveitem');
		//bd($item, '$item in saveItem');
		$fieldgroup = $item->fieldgroup;
		//bd($fieldgroup, '$fieldgroup in saveItem');
		$fieldgroup->save();
		//$this->testContext(); // for debugging
		$fieldgroup->saveContext();
		//Todo The above does not always work properly on the first (uninstall) save as PW - Fields::saveFieldgroupContext() - retrieves the old context
		//Todo However, clicking "Uninstall" a second time makes it pick up the correct context. Not sure of the cause of this.
		//$this->testContext(); // for debugging
		$savedItem = $item->save();
		//bd($savedItem, 'saved template');
		if(!$item->fieldgroups_id) {
			//bd($item, 'setting fieldgroup');
			$item->setFieldgroup($fieldgroup);
			$savedItem2 = $item->save();
			//bd($savedItem2, 'saved template with new fieldgroup');
		}
		//$this->testContext(); // for debugging
	}

	/**
	 * Just used for debugging saveItem()
	 *
	 * @throws WireException
	 */
	public function testContext() {
		$t = $this->wire()->templates->get(FieldtypeRepeater::templateNamePrefix . 'dbMigrateItem');
		$f = $this->wire()->fields->get('dbMigrateName');
		$f = $f->getContext($t);
		$a = $f->getArray();
		//bd($a, 'Result of testContext');
	}


	/**
	 * Install any pages in this migration
	 * NB any hooks associated with pages will operate (perhaps more than once) ...
	 * NB to alter any operation of such hooks etc., note that the session variable of 'installPages' is set for the duration of this method
	 *
	 * @param $items
	 * @param $itemType
	 * @param $newOld
	 * @return array
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function installPages($items, $itemType, $newOld) {
		$items = $this->pruneKeys($items, $itemType);
		//bd($items, 'items in install pages');
		$pagesInstalled = [];
		foreach($items as $name => $data) {
			// Don't want items which are selectors (these may be present if the selector yielded no pages)
			if(!$this->wire()->sanitizer->path($name)) continue;
			//bd($name, 'name to install');
			$p = $this->wire('pages')->get($name);
			$this->wire()->log->save('debug', 'Installing page ' . $name);
			/* @var $p DefaultPage */
			$pageIsHome = ($data['parent'] === 0);
			if($this->name == 'dummy-bootstrap' &&
				($name == ProcessDbMigrate::SOURCE_ADMIN . ProcessDbMigrate::MIGRATION_PARENT || $name == ProcessDbMigrate::SOURCE_ADMIN . ProcessDbMigrate::COMPARISON_PARENT)) {
				// Original admin url/path used in bootstrap json may differ from target system
				$parent = $this->wire()->pages->get("id=2"); // admin root
				$data['parent'] = $parent->path;
			} else {
				$parent = $this->wire()->pages->get($data['parent']);
			}
			//bd($data['parent'], 'data[parent]');
			//bd($parent, 'PARENT');
			$template = $this->wire()->templates->get($data['template']);
			if(!$pageIsHome and (!$parent or !$parent->id or !$template or !$template->id)) {
				$this->wire()->session->error(sprintf($this->_('Missing parent or template for page "%s". Page not created/saved.'), $name));
				break;
			}
			$data['parent'] = ($pageIsHome) ? 0 : $parent;
			$data['template'] = $template;

			$fields = $data;
			// remove attributes that are not fields
			if(isset($fields['id'])) {
				$origId = $fields['id'];
				unset($fields['id']);
			} else {
				$origId = null;
			}
			unset($fields['parent']);
			unset($fields['template']);
			unset($fields['status']);
			unset($fields['hostPagePath']);
			unset($fields['hostFieldName']);

			$r = $this->getRepeaters($fields);
			$repeaters = $r['repeaters'];
			$fields = $r['values'];
			/// Update or create a new page as necessary ////
			if($p and $p->id) {
				$p = $this->updatePage($p, $name, $data, $fields, $repeaters, $newOld);
			} else {
				$p = $this->newPage($name, $data, $fields, $repeaters, $newOld);
			}
			///////
			if($origId) $p->meta('origId', $origId); // Save the id of the originating page for matching purposes
			$p->of(false);
			$p->save();
			//bd($p, 'saved page at end of install');
			$pagesInstalled[] = $p;
		}
		return $pagesInstalled;
	}

	public function updatePage($p, $name, $data, $fields, $repeaters, $newOld) {
		$this->wire()->log->save('debug', 'Updating page ' . $p->name);
		$p->parent = $data['parent'];
		$p->template = $data['template'];
		$p->status = $data['status'];
//bd($fields, 'SAVE complex in update');
		$fields = $this->setAndSaveComplex($fields, $p); // sets and saves 'complex' fields, returning the other fields
		$p->of(false);
//bd($p, 'SAVE page in update');
		$p->save();
		$fields = $this->setAndSaveFiles($fields, $newOld, $p); // saves files and images, returning other fields
		//bd($fields, 'fields to save');
//bd($fields, 'SAVE fields in update');
		$p->setAndSave($fields);
//bd($repeaters, 'SAVE repeaters in update');
		$this->setAndSaveRepeaters($repeaters, $newOld, $p);
		$this->wire()->session->message($this->_('Set and saved page ') . $name);
		return $p;
	}

	public function newPage($name, $data, $fields, $repeaters, $newOld) {
		$this->wire()->log->save('debug', 'New page ' . $data['name']);
		$template = $this->wire()->templates->get($data['template']);
		if(method_exists($template, 'getPageClass')) { // method_exists test is for versions <3.0.152
			if($template->getPageClass()) {
				$pageClass = $template->getPageClass();
				$p = $this->wire(new $pageClass());
			} else {
				$p = $this->wire(new Page());
			}
		} else {
			if($template->pageClass) {
				$pageClass = $template->pageClass;
				$p = $this->wire(new $pageClass());

			} else {
				$p = $this->wire(new Page());
			}
		}
		$p->name = $data['name'];
		$p->template = $data['template'];
		$p->status = $data['status'];
		$p->parent = $data['parent'];
		//bd($p, 'saving new page');
		$p->of(false);
		$p->save();
		$p = $this->wire()->pages->get($p->id);
		$fields = $this->setAndSaveComplex($fields, $p); // sets and saves 'complex' fields, returning the other fields
		$fields = $this->setAndSaveFiles($fields, $newOld, $p); // saves files and images, returning other fields
		$p->setAndSave($fields);
		$this->setAndSaveRepeaters($repeaters, $newOld, $p);
		$this->wire()->session->message($this->_('Created page ') . $name);
		return $p;
	}

	/**
	 * Finds $values which are repeaters and moves them out of $values into $repeaters
	 *
	 * @param $values
	 * @return array
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function getRepeaters($values) {
		$repeaters = [];
//		bd($values, 'values before removing repeaters');
		foreach($values as $fieldName => $data) {
			$f = $this->wire('fields')->get($fieldName);
//			bd($f, "field for $fieldName");
			if($f and ($f->type == 'FieldtypeRepeater' || $f->type == 'FieldtypeRepeaterMatrix')) {
				$repeaterItems = [];
				unset($values[$fieldName]);
				foreach($data as $datum) {
					if(isset($datum['data'])) $repeaterItems[] = $datum['data'];
				}
				$repeaters[$fieldName] = $repeaterItems;
			}
		}
//		bd($repeaters, 'repeaters');
		return ['repeaters' => $repeaters, 'values' => $values];
	}

	/**
	 * Updates page for complex fields (other than repeaters) and removes these from the list for standard save
	 * See field types in the code
	 *
	 * Saves the complex fields and returns any others
	 *
	 * @param $fields
	 * @param null $page
	 * @return mixed
	 * @throws WireException
	 *
	 */
	public function setAndSaveComplex($fields, $page = null) {
		if(!$page) $page = $this;
		foreach($fields as $fieldName => $fieldValue) {
			if($fieldValue !== false) {
				$f = $this->wire()->fields->get($fieldName);
				if($f) {
					//bd(['page' => $page->name, 'field' => $f->name, 'field value' => $page->$f, 'field type' => gettype($page->$f)], 'field type in setAndSaveComplex');
					// object-types
					if($f->type == 'FieldtypeStreetAddress' or $f->type == 'FieldtypeSeoMaestro' or $f->type == 'FieldtypeMeasurement') {
						$page->of(false);
						foreach($fieldValue as $name => $value) {
							$page->$fieldName->$name = $value;
						}
					} else if($f->type == 'FieldtypePageTable') {
						$pa = $this->wire(new PageArray());
						foreach($fieldValue['items'] as $item) {
							$p = $this->wire()->pages->get($item['parent'] . $item['name'] . '/');
							if($p and $p->id) $pa->add($p);
						}
						$page->$fieldName->add($pa);
					} else if($f->type == 'FieldtypePage') {
						$a = $this->wire(new PageArray());
						if(is_string($fieldValue)) $fieldValue = [$fieldValue]; // to deal with singleton pages
						foreach($fieldValue as $item) {
							$p = $this->wire()->pages->get($item);
							if($p) $a->add($p);
						}
						$page->of(false);
						$page->$fieldName = $a;
						//bd($page->$fieldName, 'page field value');
					} else if($f->type == 'FieldtypeTemplates') {
						$a = [];
						//bd($fieldValue, 'field type templates');
						foreach($fieldValue as $item) {
							$t = $this->wire()->templates->get($item);
							if($t) $a[] = $t->id;
						}
						$page->of(false);
						$page->$fieldName = $a;
						//bd($page->$fieldName, 'template field value');
					} else {
						continue;
					}
					unset($fields[$fieldName]);
					$page->save($fieldName);
				}
			}
		}
		return $fields;
	}

	/**
	 * Updates page for files/images and removes these from returned fields array
	 *
	 * @param array $fields This is all the fields to be set - the non-file/image fields are returned
	 * @param string $newOld 'new' for install, 'old' for uninstall
	 * @param Page $page Is $this if null
	 * @param boolean $replace Replace items that match
	 * @param boolean $remove Remove any old items that do not match
	 * @return mixed
	 * @throws WireException
	 *
	 */
	public function setAndSaveFiles(array $fields, string $newOld, $page = null, $replace = true, $remove = true) {
		if(!$page) $page = $this;
		foreach($fields as $fieldName => $fieldValue) {
			$f = $this->wire()->fields->get($fieldName);
			if($f and ($f->type == 'FieldtypeImage' or $f->type == 'FieldtypeFile')) {
				$migrationFilesPath = $this->migrationsPath . $this->name . '/' . $newOld . '/files/';
				$existingItems = $page->$f->getArray();
				//bd($existingItems, '$existingItems');
				//bd($fieldValue, 'proposed value');
				$existingItemBasenames = array_filter($existingItems, function($v) {
					return basename($v->url);
				});
				//bd($existingItemBasenames, '$existingItemBasenames');
				$proposedItemBasenames = $fieldValue['items'];
				array_walk($proposedItemBasenames, function(&$v) {
					$v = $v['basename'];
				});
				//bd($proposedItemBasenames, '$proposedItemBasenames');
				$notInProposed = array_diff($existingItemBasenames, $proposedItemBasenames);
				$notInExisting = array_diff($proposedItemBasenames, $existingItemBasenames);
				$inBoth = array_intersect($existingItemBasenames, $proposedItemBasenames);
				//bd(['field' => $fieldValue, 'Venn' => [$notInProposed, $notInExisting, $inBoth]], 'Venn [$notInProposed, $notInExisting, $inBoth]');
				$proposedId = basename($fieldValue['url']); // The id from the database that was used to create the migration file
				if($remove) foreach($notInProposed as $item) {
					//bd($page->$f, ' Should be page array with item to delete being ' . $item);
					$page->$f->delete($item);
				}
				foreach($fieldValue['items'] as $item) {
					if(array_key_exists($item['basename'], $inBoth) and $replace) {
						//bd($item['basename'], 'deleting file to be replaced');
						$page->$f->delete($item['basename']);
					} else if(!in_array($item['basename'], $notInExisting)) {
						continue;
					}
					// add the pageFile
					// check that there are no orphan files
					$this->removeOrphans($page, $item);
					$addFile = $migrationFilesPath . $proposedId . '/' . $item['basename'];
					//bd($addFile, 'adding file');
					if(file_exists($addFile)) {
						$page->$f->add($addFile);
					} else {
						$this->error(sprintf($this->_('Page %1$s: File %2$s does not exist in source environment'), $page->path, $addFile));
					}
					$pageFile = $page->$f->getFile($item['basename']);
					$page->$f->$pageFile->description = $item['description'];
					$page->$f->$pageFile->tags = $item['tags'];
					$page->$f->$pageFile->filesize = $item['filesize'];
//                    $page->$f->$pageFile->formatted = $item['formatted']; // NB Removed this as ->formatted does not seem to be consistent and may cause spurious mismatches
					//bd($page->$f->$pageFile, 'Pagefile object');
					if(isset($item['custom_fields'])) {
						foreach($item['custom_fields']['items'] as $customField => $customValue) {
							$page->$f->$pageFile->$customField = $customValue;
						}
					}
				}
				unset($fields[$fieldName]);
				$page->save($fieldName);
				// add the variants after the page save as the new files not created before that
				foreach($fieldValue['items'] as $item) {
					$this->addVariants($migrationFilesPath, $item['basename'], $page, $proposedId);
				}

			}
		}
		return $fields;
	}

	/**
	 * Remove files belonging to $item
	 *
	 * @param $page
	 * @param $item
	 * @throws WireException
	 *
	 */
	protected function removeOrphans($page, $item) {
		$files = $this->wire()->config->paths->files;
		$orphans = $this->wire()->files->find($files . $page->id);
		foreach($orphans as $orphan) {
			if(strpos(basename($orphan), $item['basename']) === 0) unlink($orphan);
		}
	}

	/**
	 * Copy all variants in the migration
	 *
	 * @param $migrationFilesPath
	 * @param $basename
	 * @param $page
	 * @param $proposedId
	 * @throws WireException
	 *
	 */
	protected function addVariants($migrationFilesPath, $basename, $page, $proposedId) {
		$files = $this->wire()->config->paths->files;
		$variants = $this->wire('config')->files->find($migrationFilesPath . $proposedId . '/');
		foreach($variants as $variant) {
			$this->wire()->files->copy($variant, $files . $page->id . '/');
		}
	}

	/**
	 * setAndSave method for repeaters
	 *
	 * @param array $repeaters the repeaters array supplied from the json - i.e. the data to be installed
	 * @param $page // the migration page
	 * @param bool $remove // remove old repeaters
	 * @throws WireException
	 *
	 */
	public function setAndSaveRepeaters(array $repeaters, $newOld, $page = null, $remove = true) {
		// $remove is now redundant
		if(!$page) $page = $this;
		foreach($repeaters as $repeaterName => $repeaterData) {

			/*
			* $repeaterData should be an array of subarrays where each subarray is [fieldname => value, fieldname2 => value2, ...], each subarray corresponding to a repeater item
			 * For repeater matrix fields, the subarray will also contain 'repeater_matrix_type' => value and 'depth' => value (actuaLly depth might now be in ordinary repeaters now too)
			 *
			* Get the existing data as an array to be compared
			*/
//bd([$page, $repeaters], 'page, repeaters:  at start of set and save repeaters');
//bd($repeaterName, 'repeaterName');
			//bd($repeaterData, 'repeaterData');
			$subPages = ($page->$repeaterName) ? $page->$repeaterName->getArray() : []; // array of repeater items from the existing page
			$subPageArray = []; // to build an array based on the above items which will be in a similar format to $repeaterData
			$subPageObjects = []; // to keep track of the subpage objects in the same order as the above

			foreach($subPages as $subPage) {
				$subFields = $subPage->getFields();  // NB Or $subPage->fields ??
				//bd($subFields, 'subfields');
				$subFieldArray = [];
				foreach($subFields as $subField) {
					$subDetails = $this->getFieldData($subPage, $subField);
					//bd($subDetails, 'subdetails');
					$subFieldArray = array_merge($subFieldArray, $subDetails['attrib']);
					// Repeater fields, in addition to the normal fields also have a depth attribute which needs to be compared
					$subFieldArray['depth'] = $subPage->depth;
					// And RepeaterMatrix fields, also have  a type attribute
					if($this->wire('fields')->get($repeaterName)->type == 'FieldtypeRepeaterMatrix') {
						$repeater_matrix_type_str = FieldtypeRepeater::templateNamePrefix . 'matrix_type';
						$subFieldArray[FieldtypeRepeater::templateNamePrefix . 'matrix_type'] = $subPage->$repeater_matrix_type_str;
					}
				}
				$subPageArray[] = $subFieldArray;
				$subPageObjects[] = $subPage;
			}
			// json file may not have depth set
			array_walk($repeaterData, function(&$datum, $k) {
				if(!isset($datum['depth'])) $datum['depth'] = 0;
			});
			// NB This is over-ridden by the following filter, but is left here in case that filter needs to be removed or amended

			// remove empty elements which might otherwise cause a spurious mismatch
			array_walk($repeaterData, function(&$datum, $k) {
				$datum = array_filter($datum);
			});
			array_walk($subPageArray, function(&$item, $k) {
				$item = array_filter($item);
			});

			/*
			* $subPageArray should now be a comparable format to $repeaterData
			*/
//bd($subPageArray, 'Array from existing subpages');
//bd($repeaterData, 'Array of subpages to be set');

			/*
			 * Update/remove existing subpages
			 * The approach is to find any subpages that match first
			 * Any matched existing subpage will be left and the 'new' one removed from the replacement set
			 * Any unmatched existing subpages are removed
			 */
			foreach($subPageArray as $i => $oldSubPage) {    // $i allows us to find the matching existing subpage (in next line)
				$subPage = $subPageObjects[$i];
				$found = false;
				foreach($repeaterData as $j => $setSubPage) {
					//bd([$oldSubPage, $setSubPage], 'old and set subpages');

					if($oldSubPage == $setSubPage) {
						unset($repeaterData[$j]);
						$found = true;
						//bd($oldSubPage, 'subpage not changed, resetting sort');
						if($subPage->sort != $j) {
							$subPage->sort = $j;
							//bd($subPage, 'SAVE subpage');
							$subPage->save();
						}
						break; // don't want to match more than one page
						// NB This assumes that the repeater data does not include duplicates, other than deliberately.
						// NB  i.e. that the data array has been made unique, if there is a risk of unwanted duplicates
					}

				}
				if(!$found) {
					//bd($subPage, 'removing subpage');
					$page->$repeaterName->remove($subPage);
				}   // remove any subpages not in the new array (and previously: unless option set to false, but this has now been removed)
			}

			//bd($page, 'page after removing old repeaters');
			/*
			 * Now add the replacement subpages for any $repeaterData items that are left
			 */
			if($repeaterData) {
				$page->of(false);
//bd($page, 'SAVE page');
				$page->save($repeaterName);  // ToDo Is this necessary?
				foreach($repeaterData as $j => $item) {
					//bd($item, 'data for new subpage');
//					$dataField = $this->wire('fields')->get($repeaterName);
//					$page->of(false);
					$repeaterField = $this->wire('fields')->get($repeaterName);
					if($repeaterField->type == 'FieldtypeRepeaterMatrix') {
						if(!($repeaterField instanceof RepeaterMatrixField)) {
							$repeaterField = ProcessDbMigrate::cast($repeaterField, 'ProcessWire\RepeaterMatrixField');
							$repeaterField->save();
						}
						if(!$page->$repeaterName) $page->$repeaterName = new RepeaterMatrixPageArray($page, $repeaterField);
						$newSubPage = $page->$repeaterName->getNewItem();
						$typeAttr = 'matrix' . $item[FieldtypeRepeater::templateNamePrefix . 'matrix_type'] . '_name';
						$matrixType = $repeaterField->$typeAttr;
						$newSubPage->setForField($repeaterField); //NB Need to make sure the getForField is a RepeaterMatrixField object, not just a plain Field (set in cast() method above)
						$newSubPage->setMatrixType($matrixType); // this will fail if the getForField is just a plain field as the getMatrixTypes() method will not be available
						unset($item[FieldtypeRepeater::templateNamePrefix . 'matrix_type']);
						if(isset($item['depth'])) {
							$newSubPage->setDepth($item['depth']);
							unset($item['depth']);
						}
//					$newSubPage->save();
					} else {
						//bd($page->fields, 'allowed fields');
						if(!($repeaterField instanceof RepeaterField)) {
							$repeaterField = ProcessDbMigrate::cast($repeaterField, 'ProcessWire\RepeaterField');
							$repeaterField->save();
						}
						if(!$page->$repeaterName) $page->$repeaterName = new RepeaterPageArray($page, $repeaterField);
						$newSubPage = $page->$repeaterName->getNew();
						if(isset($item['depth'])) {
							$newSubPage->setDepth($item['depth']);
							unset($item['depth']);
						}
					}
					$newSubPage->sort = $j;
//				$newSubPage->setAndSave($item);

					// NB Rather than attempt to set the page fields here, use a recursive call
//bd($newSubPage, 'SAVE newsubpage');
					$newSubPage->save(); // Make sure the new subpage is in the database before we attempt to update it
					$r = $this->getRepeaters($item);
					$subRepeaters = $r['repeaters'];
					$fields = $r['values'];
					$item['parent'] = $newSubPage->parent;
					$item['template'] = $newSubPage->template;
					$item['status'] = $newSubPage->status;
					$newSubPage = $this->updatePage($newSubPage, $newSubPage->name, $item, $fields, $subRepeaters, $newOld);

					$newSubPage->sort = $j;  // for sorting when all done
					//bd($newSubPage, 'SAVE newsubpage2');
					$newSubPage->save();
//				bd($newSubPage, 'added new subpage');
					//bd($page, 'saved page after new sub page');
				}
				//bd($page, 'SAVE page2');
				$page->save();
				/*
				 * NB End of replacement pages
				 */
			}

			$page->$repeaterName->sort('sort');
		}
		$page->of(false);
		$page->save();
		//bd($page, 'page at end of set and save repeaters');
	}

	/**
	 * Remove items with action = 'removed'
	 *
	 * NB any hooks associated with page->trash will operate
	 * NB to alter any operation of such hooks etc., note that the session variable of 'removeItems' is set for the duration of this method
	 *
	 * @param $items
	 * @param $itemType
	 * @return null
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function removeItems($items, $itemType) {
		$this->wire()->session->set('dbMigrate_removeItems', true); // for use by host app
		//bd($items, 'items for deletion. item type is ' . $itemType);
		// For new and changed pages, selector will have been decoded on export. However, for removed pages, decode needs to happen on install
		$expandedAll = [];
		foreach($items as $itemName => $data) {
			$item['name'] = $itemName;
			$item['type'] = $itemType;
			$item['action'] = 'removed';
			$item['oldName'] = '';
			$expandedAll[] = $this->expandItem($item);
		}
		//bd($expandedAll, 'expanded items for deletion');
		$objectsAll = [];
		foreach($expandedAll as $expanded) {
			$objects = array_map(function($x) {
				return $x['object'];
			}, $expanded['items']);
			$objectsAll = array_merge($objectsAll, $objects);
		}
		//bd($objectsAll, 'objects for deletion');
		switch($itemType) {
			case 'pages' :
				$pages = $objectsAll;
				foreach($pages as $p) {
					if($p and $p->id) {
						// find and remove any images and files before deleting the page
						$p->of(false);
						$fields = $p->getFields();
						foreach($fields as $field) {
							//bd($field, 'field to remove');
							if($field->type == 'FieldtypeImage' or $field->type == 'FieldtypeFile') {
								$p->$field->deleteAll();
							}
							if($field->type == 'Pageimages' or $field->type == 'Pagefiles') {
								$p->$field->deleteAll();
							}
						}
						$this->wire()->pages->___save($p, ['noHooks' => true]); // no hooks
						//bd($p, '$p before trash');
						$p = $this->wire->pages->get($p->id);  // reload the page (Not sure this is necessary, but should ensure we have the database object, not the one in memory)
						//bd([$p->parent->name, $p->name], ' Parent and name');
						//bd([trim($this->adminPath, '/'), trim(self::MIGRATION_PARENT, '/')], 'comparators');
						if($this->name == 'bootstrap' and $p->parent->name == trim($this->adminPath, '/')
							and $p->name == trim(ProcessDbMigrate::MIGRATION_PARENT, '/')) {
							// we are uninstalling the module so remove all migration pages!
							//bd($p, 'Deleting children too');
							if($p->isLocked()) $p->removeStatus(Page::statusLocked);
							$p->trash(); // trash before deleting in case any hooks need to operate
							$p->delete(true);
						} else {
							//bd($p, 'Only deleting page - will not delete if there are children. (This is ' . $this->name . ')');
							try {
								if($p->isLocked()) $p->removeStatus(Page::statusLocked);
								$p->trash(); // trash before deleting in case any hooks need to operate
								//bd($p, '$p before delete');
								if($p->numChildren == 0) {  // to provide more helpful error message than the standard one from the delete method
									$p->delete();
								} else {
									$this->wire()->session->error(sprintf(
											$this->_('Page %s has children (which may be hidden or unpublished). Revise your migration to delete these first.'),
											$p->name)
									);
								}
								$this->wire('pages')->uncacheAll(); // necessary - otherwise PW may think pages have children etc. which have in fact just been deleted
							} catch(WireException $e) {
								$this->wire()->session->error('Page ' . $this->name . ': ' . $e->getMessage()); // for any error types other than numChildren
							}
						}
					}
				}

				break;
			case 'templates' :
				foreach($objectsAll as $object) {
					//bd($name, 'deleting ' . $itemType);
					if($object) {
						if($object->flags == 8) {
							$this->wire()->session->error(sprintf(
									$this->_('Template %s is a system template. If you really wish to delete it, please remove the system flag first.'),
									$object->name)
							);
						} else {
							try {
								$fieldgroup = $object->fieldgroup;
								$this->wire($itemType)->delete($object);
								$this->wire('fieldgroups')->delete($fieldgroup);
							} catch(WireException $e) {
								$this->wire()->session->error('Page: ' . $this->name . ' - ' . $e->getMessage());
							}
						}
					}
				}
				break;
			case 'fields' :
				foreach($objectsAll as $object) {
					//bd($object, 'deleting ' . $itemType);
					//bd($object->flags, 'FLAGS');
					if($object) {
						if($object->flags == 8) {
							$this->wire()->session->error(sprintf(
									$this->_('Field %s is a system field. If you really wish to delete it, please remove the system flag first.'),
									$object->name)
							);
						} else {
							try {
								$this->wire($itemType)->delete($object);
							} catch(WireException $e) {
								$this->wire()->session->error('Page: ' . $this->name . $e->getMessage());
							}
						}
					}
				}
				break;
		}
		$this->wire()->session->remove('dbMigrate_removeItems');
	}

	/**
	 * Return an array of pairs origId => destId to map the ids of 'identical' pages in the source and target databases
	 * This array is also stored in a meta value 'idMap' so that it is usable later
	 *
	 * @param $pagesInstalled // all pages installed (php array of page objects)
	 * @return array
	 *
	 */
	protected function setIdMap($pagesInstalled) {
		$idMapArray = [];
		if(is_array($pagesInstalled)) foreach($pagesInstalled as $page) {
			if(!($page instanceof Page)) {
				$this->wire()->session->error($this->_('Error in installing pages. Are required fields missing or out of sequence in the installation list?'));
				continue;
			}
//			bd($page, 'page in getidmap');
//			bd([debug_backtrace(), DEBUG::backtrace()], 'backtrace');
			if($page and $page->meta('origId')) $idMapArray[$page->meta('origId')] = $page->id;  // $page->id is the new id (in the target)
		}
		$prevMap = ($this->meta('idMap')) ?: [];
		$this->meta('idMap', array_merge($prevMap, $idMapArray));
		return $idMapArray;
	}

	/**
	 * Replace image paths in RTE (Textarea) fields with the path to the file in the migration folder - for preview purposes
	 *
	 * @param string $html //  Text in the RTE field
	 * @param string $newOld // Context - to get the file from the 'new' or 'old' directory as appropriate
	 * @param bool $json
	 * @param string $path
	 * @return string|string[]|null
	 * @throws WireException
	 *
	 */
	public function replaceImgSrcPath(string $html, string $newOld, $json = false, $path = ProcessDbMigrate::MIGRATION_PATH) {
		if(strpos($html, '<img') === false and strpos($html, '<a') === false) return $html; //return early if no images are embedded in html
		//bd($re, 'regex pattern');
		// First, fix the site path if it is different from the source (NOT NECESSARY - handled elsewhere)
//		$sourceSiteUrl = ($this->sourceSiteUrl) ?: '/site/';
//		$html = str_replace($sourceSiteUrl, $this->wire()->config->urls->site, $html);

		// Now use the migration files
		$newHtml = '';
		$count = 0;
		while($newHtml != $html) {
			$newHtml = str_replace(
				$this->wire()->config->urls->files,
				$this->wire()->config->urls->templates . $path . $this->name . '/' . $newOld . '/files/',
				$html
			);
			if($count > 100) break;
			$count++;
		}
		// because json uses double quotes, need to change escaped double quotes to single quotes
		if($json) $newHtml = str_replace('\"', "'", $newHtml);
		//bd($html, 'old html');
		//bd($newHtml, 'new html');
		return $newHtml;
	}


	/***********************************************
	 ************ GENERAL SECTION ******************
	 **********************************************/

	/**
	 * Refresh the migration page from migration.json data (applies to installable pages only - i.e. in target database)
	 * If the page has been (partially) installed, then it will not refresh from changed migration.json because doing so resets the 'old' json to take account of the new scope
	 * We only want the original uninstalled data in the old json, so the user must uninstall first before applying the new migration definition
	 *
	 * For non-installable pages (i.e. in their source database), just refresh the installed status and check overlaps between unlocked migrations
	 *
	 * @param null $found
	 * @return bool
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function refresh($found = null) {
		//bd('IN REFRESH');
		//bd($this->migrationsPath, '$this->migrationsPath');
		if(!$this->ready) $this->ready();

		// Get the migration details - exit if they don't exist
		$migrationPath = $this->migrationsPath . $this->name;
		if(!$found) {
			if(is_dir($migrationPath)) {
				$found = $migrationPath . '/new/migration.json';
//bd($found, 'found file');
			}
		}
		if(!$found || ($found && !file_exists($found))) {
			if($this->meta('installable')) {
				$this->wire()->session->error($this->_('migration.json not found'));
			} else {
				if($this->meta('installedStatus')) $this->meta()->remove('installedStatus');  // in case exported files removed by user, reset meta
			}
			return false;
		}
		$fileContents = wireDecodeJSON(file_get_contents($found));

		/*
		 * Set installable status according to database name (if it exists)
		 */
		$sourceDb = (isset($fileContents['sourceDb']) and $fileContents['sourceDb']) ? $fileContents['sourceDb'] : null; // Database that was the source for this migration (if named)
		//bd($sourceDb, 'sourceDb');
		if($sourceDb) {
			if($this->dbName
				and $sourceDb == $this->dbName) {
				if($this->meta('installable')) $this->meta()->remove('installable');
			} else {
				if(!$this->meta('installable')) $this->meta('installable', true);
			}
			$this->meta('sourceDb', $sourceDb);
		}
		$this->sourceSiteUrl = (isset($fileContents['sourceSiteUrl']) and $fileContents['sourceSiteUrl']) ? $fileContents['sourceSiteUrl'] : $this->wire()->config->urls->site;

		/*
		 * Set lock status according to presence of lockfile
		 */
		$migrationFolder = $this->migrationsPath . $this->name . '/';
		$migrationFiles = $this->wire()->files->find($migrationFolder);
		if(is_dir($migrationFolder) and in_array($migrationFolder . 'lockfile.txt', $migrationFiles)) {
			$this->meta('locked', true);
		} else if($this->meta('locked')) {
			$this->meta()->remove('locked');
		}

		/*
		 * Get the installed status - NB it is debatable whether or not this is a good idea for locked migrations as it may slow up the status page refresh,
		 * NB but not doing so causes problems when a development database is created from a live version with locked migrations.
		 * NB Also it is needed to display more meaningful statuses - e.g. 'superseded'
		 */
		// Only proceed with refresh if something has changed
		$filesHash = $this->filesHash();
		$dbName = $this->dbMigrate->dbName();
//bd($this->meta('filesHash'), 'fileshash');
		if(
			($this->meta('filesHash') && $this->meta('filesHash') == $filesHash) &&
			($this->meta('hostDb') && $this->meta('hostDb') == $dbName)
		) {
//bd($this, 'skipping refresh as no changes');
			return true;
		} else {
			$this->meta->set('filesHash', $filesHash);
			$this->meta->set('hostDb', $dbName);
			$this->exportData('compare'); // sets meta('installedStatus')
		}

		//bd($this->meta('locked'), 'Locked status');
		if($this->meta('locked')) return true; //ToDo changed from return false. Is this correct?
		/*
		* Continue only for unlocked pages
		 */
//bd($this, 'continuing with refresh');

		if(isset($fileContents['sourceDb'])) unset($fileContents['sourceDb']);  // temporary so we don't attempt to process it
		if(isset($fileContents['sourceSiteUrl'])) unset($fileContents['sourceSiteUrl']);

		// notify any conflicts
		$itemList = $this->listItems();
		if(!$this->meta('locked')) $this->checkOverlaps($itemList);
//bd($this->meta('installable'), 'installable?');
		if(!$this->meta('installable') or $this->meta('locked')) return true;  // ToDo Don't need 2nd condition?

		/*
		* Only installable pages (i.e. in target environment) need to be refreshed from json files
		*/
//bd($fileContents, 'already found file contents');
		// in practice there is only one item in the array (after 'sourceDb' & 'sourceSiteUrl' have been unset) as it is just for the migration page itself
		foreach($fileContents as $type => $content) {
			//bd($content, 'content item');
			foreach($content as $line) {
				foreach($line as $pathName => $values) {
					$pageName = $values['name'];
					if($this->name != $pageName) $this->wire()->session->warning($this->_('Page name in migrations file is not the same as the host folder.'));
					$p = $this->migrations->get("name=$pageName, include=all");
					/* @var $p DbMigrationPage */
					// bootstrap must always refresh because on upgrade, the old/migration.json will have been updated as uninstall is not permitted
					if($this->name != 'bootstrap') {
						// check if the old migration has the same scope as the page
						if(!$this->meta('installedStatus')['uninstalledMigrationKey']) return false;
						// check if the definition has changed
						$oldFile = $migrationPath . '/old/migration.json';
						$fileTestCompare = [];
						$fileCompare = [];
						// only compare fields that actually affect the migration
						$fieldsToCompare = self::KEY_DATA_FIELDS;
						if(file_exists($oldFile)) {
							$oldContents = wireDecodeJSON(file_get_contents($oldFile));
							if(isset($oldContents['sourceDb'])) unset($oldContents['sourceDb']);
							if(isset($oldContents['sourceSiteUrl'])) unset($oldContents['sourceSiteUrl']);
							foreach($oldContents as $oldType => $oldContent) {
								foreach($oldContent as $oldLine) {
									foreach($oldLine as $oldPathName => $oldValues) {
										$oldTestValues = $oldValues;
										foreach($oldTestValues as $k => $oldTestValue) {
											if(!in_array($k, $fieldsToCompare)) unset($oldTestValues[$k]);
										}
										$testValues = $values;
										foreach($testValues as $k => $testValue) {
											if(!in_array($k, $fieldsToCompare)) unset($testValues[$k]);
										}
										$fileTestCompare = $this->array_compare($testValues, $oldTestValues);  // just the important changes
										unset($values['id']);
										unset($oldValues['id']);
										$fileCompare = $this->array_compare($values, $oldValues); // all the changes
										//bd($fileTestCompare, '$fileTestCompare in refresh');
										//bd($fileCompare, '$fileCompare in refresh');
									}
								}
							}
						}

						/*
						 * A further check here, particularly for migrations which reference selectors:
						 * The migration page may be unchanged, but database changes may mean that different pages are within the scope, so we need to recreate the 'old' data
						 * Approach is to compare the pages in the scope as evidenced by the difference between the previous new.json file and the one that has now been created
						 * This approach was introduced in v 0.1.0 and involves saving an orig-new-data.json file in the old directory for later comparison
						 * Earlier migrations (i.e. created before v0.1.0) will not have saved this file and so will omit this test
						 * This comparison is done in exportData(). If there is a difference, the result array element 'scopeChange' was set to true (and the diffs are in 'scopeDiffs').
						 */
						$installedStatus = $this->meta('installedStatus');
						$scopeChange = $installedStatus['scopeChange'];
						//bd([$fileTestCompare, $scopeChange], '[fileTestCompare, scopeChange]');
						if((file_exists($oldFile)) and ($fileTestCompare or $scopeChange) and !$installedStatus['uninstalled']) {
							$this->wire()->session->warning(sprintf(
									$this->_("Migration definition has changed for %s \nYou must fully uninstall the current migration before refreshing the definition and installing the new migration."),
									$pageName)
							);
							return false;
						}

						if(file_exists($oldFile) and !$fileCompare and !$scopeChange) continue;   // nothing changed at all so no action required.
						// NB since there is only one item, 'continue' has the same effect as 'return true'
						/*
						* So now we should have a new migration definition where the previous version has been uninstalled **or** the changes are only 'cosmetic'
						* Archive the old files before continuing - a new version of these will be created when the new version of the migration is installed
						* BUT only do this if the migration is fully uninstalled - do not do it when we are just updating cosmetic changes
						*/
						if(is_dir($migrationPath . '/old/') and $installedStatus['uninstalled']) {
							/*
							 * Do not remove the old directory - retain as backup with date and time appended
							 * From v0.1.0, the archives are in the archive/ directory. Before that they were in the top migrations/{migration name} directory
							 * Archive directories will be deleted if 'delete migration files' is selected in the source database
							 *  * (but will obviously only be deleted in the target environment if this is fully sync'd)
							 */
							//bd(['installedStatus' => $installedStatus, 'fileCompare' => $fileCompare, 'fileTestCompare' => $fileTestCompare, 'scopeChange' => $scopeChange], 'Before renaming Old');
							$timeStamp = $this->wire()->datetime->date('Ymd-Gis');
							$this->wire()->session->warning(sprintf($this->_('Scope has changed. Directory %1s has been moved to %2s. New %3s directory will be created on install.'),
								$migrationPath . '/old/', $migrationPath . 'old-' . $timeStamp . '/', $migrationPath . '/old/'));
							if(!is_dir($migrationPath . '/archive/')) {
								if(!wireMkdir($migrationPath . '/archive/', true, "0777")) {          // wireMkDir recursive
									throw new WireException($this->_("Unable to create migration directory:") . $migrationPath . '/archive/');
								}
							}
							if(is_dir($migrationPath . '/archive/')) {
								$this->wire()->files->rename($migrationPath . '/old/', $migrationPath . '/archive/' . 'old-' . $timeStamp . '/');
								//bd($migrationPath . '/archive/' . 'old-' . $timeStamp . '/', 'RENAMED FILE');
							}
						}
					}
					//bd($values, ' in page refresh with $values');
					// Remove non-field attributes
					unset($values['parent']);
					unset($values['id']);
					unset($values['template']);
					unset($values['status']);
					$r = $this->getRepeaters($values);
					//bd($r, 'return from getrepeaters');
					$repeaters = $r['repeaters'];
					$values = $r['values'];
					// set the ordinary values first
					//bd($values, ' in page refresh with $values after unset');
					//bd($p->meta('installable'), $p->name . ' installable?');
					//bd($p, 'p before save');
					if($p and $p->id and $p->meta() and $p->meta('installable')) {
						$p->meta('allowSave', true);  // to allow save
						//bd([$p, $values], 'page, values');
						$p->setAndSave($values);
						if(count($repeaters) > 0) $this->setAndSaveRepeaters($repeaters, 'new', $p);
						$p->meta()->remove('allowSave');  // reset
					} else {
						$p->setAndSave($values);
						if(count($repeaters) > 0) $this->setAndSaveRepeaters($repeaters, 'new', $p);
					}
					//bd($p, 'p after save');
				}
			}
		}
		return true;
	}

	public function filesHash($path = null) {
		$hashAlgo = (in_array('xxh128', hash_algos())) ? 'xxh128' : ((in_array('md4', hash_algos())) ? 'md4' : hash_algos()[0]);
		if(!$path) $path = $this->wire()->config->paths->templates . ProcessDbMigrate::MIGRATION_PATH;
		//bd($path, 'fileshash path');
		$fileArray = $this->wire()->files->find($path . $this->name . '/');
		//bd($fileArray, 'fileArray');
		$hashString = '';
		foreach($fileArray as $file) {
			$hashString .= hash_file($hashAlgo, $file);
		}
		return ($hashString) ? hash($hashAlgo, $hashString) : null;
	}

	/**
	 * Migrations which are locked, not installable, or which have not yet had an installation attempt present no conflict issues
	 *
	 * @return bool
	 */
	public function conflictFree() {
		if($this->meta('locked')) return true;
		if(!$this->meta('installable')) return true;
		if(!is_dir(wire('config')->paths->templates . ProcessDbMigrate::MIGRATION_PATH . $this->name . '/old/')) return true; // no conflict if old files not yet created
		return false;
	}

	/**
	 * Get names and old names of items
	 *
	 * @return array|bool|mixed|PageArray|string|null
	 * @throws WireException
	 */
	public function itemNames($type) {
		$migId = $this->id;
		$migNames = $this->wire()->cache->getFor('type_' . $type, 'migration_' . $migId, WireCache::expireSave, //NB need to expire on any save as items may move in or out of scope
			function() use ($type) {
				$list = $this->listItems($type);
				$names = $this->extractElements($list, 'name');
				$oldNames = array_filter($this->extractElements($list, 'oldName'));
				$names = array_merge($names, $oldNames);
				return $names;
			});
		return $migNames;
	}

	/**
	 * Parse items, expanding selectors as necessary
	 * Return list of all items in format [[type, action, name, oldName], [...], ...]
	 *
	 * @return array[]
	 * @throws WireException
	 *
	 */
	public function listItems($type = null) {
		$list = [];
		$items = $this->dbMigrateItem;
		foreach($items as $item) {
			$itemArray = $this->populateItem($item);
			if(!$itemArray['type'] or !$itemArray['name']) continue;
			$expanded = $this->expandItem($itemArray);
			foreach($expanded['items'] as $expandedItem) {
				$name = $expandedItem['name'];
				$oldName = $expandedItem['oldName'];
				$itemType = $item->dbMigrateType->value;
				if($type || $type == $itemType) {
					$list[] = [
						'type' => $item->dbMigrateType->value,
						'action' => ($item->dbMigrateAction) ? $item->dbMigrateAction->value : 'changed',
						'name' => $name,
						'oldName' => $oldName];
				}
			}
		}
		return $list;
	}

	/**
	 * Check that names and oldNames of current migration do not overlap with those of other (unlocked) migrations
	 *
	 * @param $itemList
	 * @return bool
	 * @return bool
	 * @throws WireException
	 *
	 */
	protected function checkOverlaps($itemList) {
		$warnings = [];
		if(!$this->migrations or !$this->migrations->id) {
			$this->wire()->session->error($this->_('Missing dbmigrations page'));
			return false;
		}
		$itemOldNames = array_filter($this->extractElements($itemList, 'oldName'));
		$itemNames = $this->extractElements($itemList, 'name');
		$intersection = [];
		$intersectionOld = [];
		//bd($itemNames, ' Names for this');
		foreach($this->migrations->find("template={$this->migrationTemplate}, include=all") as $migration) {
			/* @var $migration DbMigrationPage */
			if($migration->id == $this->id) continue;
			if($migration->meta('locked')) continue;
			$migrationList = $migration->listItems();
			$migrationNames = $this->extractElements($migrationList, 'name');
			$migrationOldNames = array_filter($this->extractElements($migrationList, 'oldName'));
			$intersect = array_intersect($itemNames, $migrationNames);
			$intersectOld = array_intersect($itemOldNames, $migrationOldNames);
			if($intersect) $intersection[] = $migration->name . ': ' . implode(', ', $intersect);
			if($intersectOld) $intersectionOld[] = $migration->name . ': ' . implode(', ', $intersectOld);
		}
		$intersectString = implode('; ', $intersection);
		if($intersection) $warnings[] = sprintf($this->_("Item names in %s overlap with names in other migrations as follows"), $this->name) .
			" - \n $intersectString";
		$intersectOldString = implode('; ', $intersectionOld);
		if($intersectionOld) $warnings[] = sprintf($this->_("Item old names in %s overlap with old names in other migrations as follows"), $this->name) .
			" - \n $intersectOldString";
		if($warnings) {
			$warnings[] = "\n" . $this->_("It is recommended that you make the migrations disjoint, or install and lock an overlapping migration.");
		}
		if($warnings) $this->wire()->session->warning(implode('; ', $warnings));
		return true;
	}

	/**
	 * Cross-section of a 2-dim array
	 * extracts selected $element from each array in $itemList
	 *
	 * @param $itemList
	 * @param $element
	 * @return array
	 *
	 */
	public function extractElements($itemList, $element) {
		$elements = [];
		//bd($itemList, ' extract ' . $element);
		foreach($itemList as $item) {
			$elements[] = $item[$element];
		}
		return $elements;
	}

	/**
	 * Check migration items are valid
	 *
	 * @param $itemList
	 * @return array
	 * @throws WireException
	 *
	 */
	protected function validateValues($itemList) {
		$errors = [];
		//bd($itemList, 'item list in validate');
		foreach($itemList as $item)
			if($item['type'] == 'pages') {
				if(!$this->validPath($item['name']) or ($item['oldName'] and !$this->validPath($item['oldName']))) {
					$errors[] = $this->_('Invalid path name (or old path name) for ') . $item['type'] . '->' . $item['action'] . '->' . $item['name'];
				}
			} else {
				if(!$this->wire->sanitizer->fieldName($item['name']) or ($item['oldName'] and !$this->wire->sanitizer->fieldName($item['oldName']))) {
					$errors[] = $this->_('Invalid name (or old name) for ') . $item['type'] . '->' . $item['action'] . '->' . $item['name'];
				}
			}
		return $errors;
	}

	/**
	 * Check path is valid
	 * (standard sanitizer->path does not check for existence of leading and trailing slashes)
	 *
	 * @param $path
	 * @return bool
	 * @throws WireException
	 *
	 */
	public function validPath($path) {
		return ($this->wire()->sanitizer->path($path) and strpos($path, '/') == 0 and strpos($path, '/', -1) !== false);
	}

	/*********************************
	 * ****** Dependency sorting *****
	 *********************************/

	/**
	 * Sort migration items to take account of dependencies
	 *
	 * @return $this
	 * @throws WireException
	 */
	public function dependencySort() {
		$items = $this->dbMigrateItem;
		//bd($items, 'items in dependency sort');
		$matrix = $this->createDependencyMatrix($items); // also sets temporary field 'mysort' to each item in items
		//bd($items, 'items in dependency sort after creating matrix');
		$sorted = $this->topologicalSort($matrix);
		//bd($sorted, 'sorted');
		foreach($sorted as $elem) {
			//bd($elem, 'elem');
		}
		$i = 0;
		while(!$sorted->isEmpty()) {
			$itemNumber = $sorted->dequeue();
			//bd($itemNumber, 'itemNumber');
			$item = $items->get("mysort=$itemNumber");
			//bd($item, 'item');
			if($item && $item->sort != $i) {
				$item->of(false);
				$item->sort = $i;
				$item->save();
			}
			$i++;
		}
		return $this;
	}

	/**
	 * Get the migration items which this item is dependent on (returned as an array of ids)
	 * For fields the ids will either be templates or pages
	 * For templates the ids will be fields
	 * For pages the ids will be pages
	 *
	 * If the meta('sourceData') has been set for a migration item, then that will be used in preference to retrieving the objects
	 * (Where objects have been removed, the meta is the only source of the relevant info)
	 *
	 * @param $migrationItem
	 * @return array
	 * @throws WireException
	 */
	public function getDependencies($migrationItem, $item = null) {
//bd(['item' => $migrationItem, 'sourceData' => $migrationItem->meta('sourceData')], 'item in getDependencies');
		$sourceData = $migrationItem->meta('sourceData');
		$items = $this->dbMigrateItem;
		switch($migrationItem->dbMigrateType->id) {
			case 1: // field
				$templateItem = null;
				$parentItem = null;
				$dependentTypes = ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix', 'FieldtypePage'];
				$dependent = ($sourceData && isset($sourceData['type']) && in_array($sourceData['type'], $dependentTypes));
				$field = ($item) ?: $this->wire()->fields->get("name={$migrationItem->dbMigrateName}");
				if((!$sourceData || !isset($sourceData['type'])) && !$field) return [];
				if((!$sourceData || !isset($sourceData['type'])) && !$dependent) {
					$dependent = ($field->type == 'FieldtypeRepeater' || $field->type == 'FieldtypeRepeaterMatrix' || $field->type == 'FieldtypePage');
				}
				if($dependent) {
					if($sourceData && isset($sourceData['template_id'])) {
						$templateItem = $this->findMigrationItemsByObjectId('template', [$sourceData['template_id']]);
						$templateItem = ($templateItem) ? $templateItem->first() : null;
						bd($templateItem, 'template item in getDependencies 1');
					}
					if(!$templateItem) {
						$template = (isset($field->template_id)) ? $this->wire()->templates->get("id={$field->template_id}") : null;
						if($template) {
							$templateItem =  $items->get("dbMigrateType=2, dbMigrateName={$template->name}, dbMigrateAction!=2");
							// (NB dbMigrateAction!=2: Not interested if the template has only changed - not new or removed - as it will not affect the dependent field)
							// ToDo consider whether a similar logic applies elsewhere, to prevent spurious cyclical dependencies
							// ToDo check that this does not create a problem where the template name has been changed
						}
						$templateItem = ($templateItem) ? $templateItem->id : null;
//						bd($templateItem, 'template item in getDependencies 2');
					}
					if($sourceData && isset($sourceData['parent_id'])) {
						$parentItem = $this->findMigrationItemsByObjectId('page', [$sourceData['parent_id']]);
						$parentItem = ($parentItem) ? $parentItem->first() : null;
					}
					if(!$parentItem) {
						$parent = (isset($field->parent_id)) ? $this->wire()->pages->get("id={$field->parent_id}") : null;
						$parentPath = ($parent) ? $parent->path() : null;
						if($parentPath) {
							$parentItem =  $items->get("dbMigrateType=2, dbMigrateName={$parentPath}");
						}
						$parentItem = ($parentItem) ? $parentItem->id : null;
					}
				}
				return (['template_item' => $templateItem, 'parent_item' => $parentItem]);
				break;
			case 2: // template
				$fieldArray = [];
				$childTemplatesArray = [];
				$parentTemplatesArray = [];
				$template = ($item) ?: $this->wire()->templates->get("name={$migrationItem->dbMigrateName}");
				if($sourceData && isset($sourceData['fields'])) {
					$fieldArray = $this->findMigrationItemsByObjectId('field', $sourceData['fields'])->explode();
				}
				if(!$fieldArray && $template) {
					$fields = $template->fieldgroup;
					foreach($fields as $field) {
//						bd($field, 'field');
						$fieldItem = $items->get("dbMigrateType=1, dbMigrateName={$field->name}");
						if($fieldItem) {
							//bd($fieldItem);
							$fieldArray[] = $fieldItem->id;
						}
					}
				}
				if($sourceData && isset($sourceData['childTemplates'])) {
					$childTemplatesArray = $this->findMigrationItemsByObjectId('template', $sourceData['childTemplates'])->explode();
				}
				if(!$childTemplatesArray && $template) {
					$childTemplates = $template->childTemplates;
					foreach($childTemplates as $childTemplate) {
						$ct = $this->wire()->templates->get($childTemplate);
						$childTemplateItem = ($ct) ? $items->get("dbMigrateType=2, dbMigrateName={$ct->name}") : null;
						if($childTemplateItem) {
							$childTemplatesArray[] = $childTemplateItem->id;
						}
					}
				}
				if($sourceData && isset($sourceData['parentTemplates'])) {
					$parentTemplatesArray = $this->findMigrationItemsByObjectId('template', $sourceData['parentTemplates'])->explode();
				}
				if(!$parentTemplatesArray && $template) {
					$parentTemplates = $template->parentTemplates;
					foreach($parentTemplates as $parentTemplate) {
						$pt = $this->wire()->templates->get($parentTemplate);
						$parentTemplateItem = ($pt) ? $items->get("dbMigrateType=2, dbMigrateName={$pt->name}") : null;
						if($parentTemplateItem) {
							$parentTemplatesArray[] = $parentTemplateItem->id;
						}
					}
				}
				return ['fields' => $fieldArray, 'childTemplates' => $childTemplatesArray, 'parentTemplates' => $parentTemplatesArray];
				break;
			case 3: // page
				$pageArray = [];
				$pageArray2 = [];
				$parentItem = null;
				$templateItem = null;
				$pagePath = $migrationItem->dbMigrateName;
				//bd($pagePath, 'pagepath');
				$page = ($item) ?: $this->wire()->pages->get("path={$pagePath}, include=all");
				//bd($page, 'page in getdependencies for page');
				if($sourceData && isset($sourceData['parent_id'])) {
					$parentItem = $this->findMigrationItemsByObjectId('page', [$sourceData['parent_id']]);
					$parentItem = ($parentItem) ? $parentItem->first() : null;
				}
				if(!$parentItem && $page) {
					$parentItem = ($page && $page->id) ? $this->findMigrationItemsByObjectId('page', $page->parent()->id) : null;
					$parentItem = ($parentItem) ? $parentItem->first() : null;
				}

				if($sourceData && isset($sourceData['template_id'])) {
					$templateItem = $this->findMigrationItemsByObjectId('template', [$sourceData['template_id']]);
					$templateItem = ($templateItem) ? $templateItem->first() : null;
				}
				if(!$templateItem && $page) {
					$templateItem = ($page && $page->id) ? $this->findMigrationItemsByObjectId('template', $page->template->id) : null;
					$templateItem = ($templateItem) ? $templateItem->first() : null;
				}

				if($sourceData && isset($sourceData['pageRefs'])) {
					$pageArray = $this->findMigrationItemsByObjectId('page', $sourceData['pageRefs'])->explode();
				}
				if(!$pageArray && $page) {
					$fields = $page->getFields();
					//bd($fields, 'fields in getdependencies for page');
					foreach($fields as $field) {
						if($field->type == 'FieldtypePage') {
							$pageRefs = $page->$field;
							//bd($pageRefs, 'pageRefs');
							if(!($pageRefs instanceof PageArray)) $pageRefs = [$pageRefs];
							foreach($pageRefs as $pageRef) {
								if($pageRef) {
									$pageItem = $items->get("dbMigrateType=3, dbMigrateName={$pageRef->name}");
									if($pageItem) {
										// NB this only records dependencies if the other page is in a migration item. Should it be restrictive like this?
										$pageArray[] = $pageItem->id;
									}
								}
							}
						}
					}
					//bd($pageArray, 'pageArray');
				}
				if($sourceData && isset($sourceData['rteLinks'])) {
					$pageArray2 = $this->findMigrationItemsByObjectId('page', $sourceData['rteLinks'])->explode();
				}
				if(!$pageArray2 && $page) {
					$dbM = $this->wire('modules')-> get('ProcessDbMigrate');
					$imageSources = $dbM->findRteImageSources($page); // page array
					$otherLinks = $dbM->findRteLinks($page);
					$pageRefs2 = $imageSources->add($otherLinks)->explode('id');
					foreach($pageRefs2 as $pageRef) {
						if($pageRef) {
							$pageItem = $items->get("dbMigrateType=3, dbMigrateName={$pageRef->name}");
							if($pageItem) {
								// NB this only records dependencies if the other page is in a migration item. Should it be restrictive like this?
								$pageArray2[] = $pageItem->id;
							}
						}
					}
				}
				//bd(['template_item' => $templateItem, 'parent_item' => $parentItem, 'pageRefs' => $pageArray, 'rteLinks' => $pageArray2], 'return from getDependencies');
				return ['template_item' => $templateItem, 'parent_item' => $parentItem, 'pageRefs' => $pageArray, 'rteLinks' => $pageArray2];
				break;
		}
		return [];
	}

	/**
	 * Given an array of object ids, find the ids of any matching migration items (i.e. pages in the dbMigrate repeater field page array)
	 *
	 * @param $idArray
	 * @return WireArray
	 */
	public function findMigrationItemsByObjectId($objectType, $idArray) {
		//NB if two items of different object types have same object id there will be confusion (and possibly cyclic graph)
		//ToDo Fix it!
		$items = $this->dbMigrateItem;
		$itemArray = new WireArray();
		if(!is_array($idArray) && is_int($idArray)) $idArray = [$idArray];
		if(!is_array($idArray)) return $itemArray;
		$flatArray = [];
		foreach($idArray as $arrayItem) {
			if(is_int($arrayItem)) {
				$flatArray[] = $arrayItem;
			} else if(is_array($arrayItem)) {
				$flatArray[] = $arrayItem['id'];
			} else {
				return $itemArray;
			}
		}
		foreach($items as $item) {
			$sourceData = $item->meta('sourceData');
			if($sourceData && isset($sourceData['id']) && isset($sourceData['source']) && $sourceData['source'] == $objectType
				&& in_array($sourceData['id'], $flatArray)) {
				$itemArray->add($item->id);
			} else {
				$types = $objectType . 's';
				foreach($flatArray as $objectId) {
					$object = $this->wire($types)->get($objectId);
//					bd($object, 'object in findMigrationItemsByObjectId');
					$name = ($types == 'pages') ? 'path' : 'name';
//					bd([$item->dbMigrateType->value, $item->dbMigrateName], 'item type and name');
					if($item->dbMigrateType->value == $types && $item->dbMigrateName == $object->$name) {
						$itemArray->add($item->id);
					}
				}

			}
		}
//		bd(['type' => $objectType, 'idArray' => $idArray, 'itemArray' => $itemArray], 'findMigrationItemsByObjectId');
		return $itemArray;
	}

	/**
	 * Create a matrix representing the dependencies
	 * The matrix is an array of n arrays of length n, with n = the number of nodes and the entry in row i col j is 1 if j is dependent on i
	 *
	 * @return array
	 * @throws WireException
	 */
	public function createDependencyMatrix(&$items) {
		if(!$items) return;
		$this->of(false);
		$ind = 0;
		foreach($items as $item) {
			$item->of(false);
			$item->set('mysort', $ind);
			$item->save();
			$ind++;
		}
//		bd($items, 'items before sort');
		$items->sort('mysort');
//bd($items, 'items after sort');
		$size = $items->count();
		// Create a zero-filled matrix [size x size]
		$matrix = array_fill(0, $size, array_fill(0, $size, 0));
		$i = 0;
		foreach($items as $item) {
			//bd([$item->dbMigrateType->value, $item->dbMigrateName], 'item type & name');
			$sourceData = $item->meta('sourceData');
			//bd($sourceData, 'sourceData');
			switch($item->dbMigrateType->id) {
				case 1 : // Field
					// Repeaters are dependent on templates and page ref fields may be dependent on templates or pages ('parent' for selection)
					$dependencies = $this->getDependencies($item);
//bd([$item->dbMigrateName, $dependencies], 'field item and dependencies');
					$templateItem = (isset($dependencies['template_item'])) ? $dependencies['template_item'] : null;
					if($templateItem && is_int($templateItem)) {
bd($templateItem, 'setting template item for field');
						$this->setDependencyMatrixEntry($matrix, $items, $templateItem, $i);
					}
					$parentItem = (isset($dependencies['parent_item'])) ? $dependencies['parent_item'] : null;
					if($parentItem && is_int($parentItem)) {
//bd($parentItem, 'setting parent item for field');
						$this->setDependencyMatrixEntry($matrix, $items, $parentItem, $i);
					}
					break;
				case 2 : // Template
					$dependencies = $this->getDependencies($item);
					//bd([$item->dbMigrateName, $dependencies], 'template item and dependencies');
					foreach(['fields', 'childTemplates', 'parentTemplates'] as $dependencyType) {
						if(isset($dependencies[$dependencyType])) foreach($dependencies[$dependencyType] as $dependency) {
							if($dependency && is_int($dependency)) {
//bd($dependency, "setting $dependencyType item for template");
								$this->setDependencyMatrixEntry($matrix, $items, $dependency, $i);
							}
						}
					}
					break;
				case 3 : // Page
					$dependencies = $this->getDependencies($item);
					$templateItem = (isset($dependencies['template_item'])) ? $dependencies['template_item'] : null;
					if($templateItem && is_int($templateItem)) {
//bd($templateItem, 'setting template item for page');
						$this->setDependencyMatrixEntry($matrix, $items, $templateItem, $i);
					}
					$parentItem = (isset($dependencies['parent_item'])) ? $dependencies['parent_item'] : null;
					if($parentItem && is_int($parentItem)) {
//bd($parentItem, 'setting parent item for page');
						$this->setDependencyMatrixEntry($matrix, $items, $parentItem, $i);
					}
					if(isset($dependencies['pageRefs'])) foreach($dependencies['pageRefs'] as $dependency) {
						if($dependency && is_int($dependency)) {
//bd($dependency, 'setting pageref item');
							$this->setDependencyMatrixEntry($matrix, $items, $dependency, $i);
						}
					}
					if(isset($dependencies['rteLinks'])) foreach($dependencies['rteLinks'] as $dependency) {
						if($dependency && is_int($dependency)) {
//bd($dependency, 'setting rteLink item');
							$this->setDependencyMatrixEntry($matrix, $items, $dependency, $i);
						}
					}
					break;
				default :
					$dependencies = [];
					break;
			}
			$sourceData = $item->meta('sourceData');
			if($sourceData && isset($sourceData['id'])) {
				$sourceData['dependencies'] = $dependencies;
				$item->meta()->set('sourceData', $sourceData);
			}
			/* Hopefully the matrix does not include any cycles. However, we can safely remove self-dependent items (i.e. where matrix[i][i] = 1)
			 * because these will not affect the sort order (an item being dependent on itself has no impact on the order)
			 */
			$matrix[$i][$i] = 0;
			//bd($matrix[$i], 'matrix for item ' . $i);
			$i++;
		}
//		bd($matrix, 'matrix');
		return $matrix;
	}

	/**
	 * Place a '1' in the matrix to represent dependency of col j on row i
	 *
	 * @param $matrix
	 * @param $relatedItem
	 * @param $i
	 * @return void
	 */
	protected function setDependencyMatrixEntry(&$matrix, $items, $relatedItemId, $i) {
		//bd([$matrix, $items, $relatedItemId, $i], 'params to setDependencyMatrixEntry');
		$relatedItem = $items->get("id=$relatedItemId");
//		bd($relatedItem, 'relatedItem');
		if(!$relatedItem) return;
		$j = $relatedItem->mysort;
		if($relatedItem->dbMigrateAction->value == 'new' || $relatedItem->dbMigrateAction->value == 'changed') {
			// $i is dependent on $j
			$matrix[$j][$i] = 1;
		} else if($relatedItem->dbMigrateAction->value == 'removed') {
			// dependency is reversed for removals
			$matrix[$i][$j] = 1;
		}
		//bd($matrix, 'matrix in setDependencyMatrixEntry');
	}

	/**
	 * This method uses Kahn's algorithm to do a topological sort of a Directed Acyclic Graph ('DAG').
	 * The graph is represented by a nxn matrix (array of n arrays of length n) with n = the number of nodes and the entry in row i col j is 1 if j is dependent on i
	 *
	 * Kahn's algorithm has the following steps to find the topological ordering from a DAG:
	 * Calculate the indegree (incoming edges) for each of the vertex and put all vertices in a queue where the indegree is 0. Also, initialize the count for the visited node to 0.
	 * Remove a vertex from the queue and perform the following operations on it:
	 * 1. Increment the visited node count by 1.
	 * 2. Reduce the indegree for all adjacent vertices by 1.
	 * 3. If the indegree of the adjacent vertex becomes 0, add it to the queue.
	 * Repeat step 2 until the queue is empty.
	 * If the count of the visited node is not the same as the count of the nodes, then topological sorting is not possible for the given DAG.
	 *
	 * @param array $matrix
	 * @return \SplQueue
	 */
	public function topologicalSort(array $matrix): \SplQueue {
		$order = new \SplQueue;
		$queue = new \SplQueue;
		$size = count($matrix);
		$incoming = array_fill(0, $size, 0);

		for($i = 0; $i < $size; $i++) {
			for($j = 0; $j < $size; $j++) {
				if($matrix[$j][$i]) {
					$incoming[$i]++;
				}
			}
			if($incoming[$i] == 0) {
				$queue->enqueue($i);
			}
		}
		while(!$queue->isEmpty()) {
			$node = $queue->dequeue();
			for($i = 0; $i < $size; $i++) {
				if($matrix[$node][$i] == 1) {
					$matrix[$node][$i] = 0;
					$incoming[$i]--;
					if($incoming[$i] == 0) {
						$queue->enqueue($i);
					}
				}
			}
			$order->enqueue($node);
		}

		//bd([$order, $size], 'order, size');
		if($order->count() != $size) {// cycle detected
			$this->error($this->_('Cannot resolve sort - migration items have cyclical dependencies'));
			return new \SplQueue;
		}

		return $order;
	}

	/***********************************
	 *********** HOOKS *****************
	 **********************************/

	/**
	 * Before save actions:
	 * Disallow saving of installable pages (must be generated from migration.json)
	 * * (note that when a page is saved on installation, hooks are disabled)
	 * Check overlapping scopes of exportable unlocked pages
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	protected function beforeSaveThis(HookEvent $event) {
		$p = $event->arguments(0);
		if(!$p or !$p->id) return;
		//bd($event, 'hook event');
		if($this->id != $p->id) return;  // only want this method to run on the current instance, not all instances of this class
		/* @var $p DbMigrationPage */
		// Sort migration items by dependency NB as a side-effect, this prevents migration items from being deleted manually if sorting or log changes enabled
		if(!$p->meta('installable') && !$p->meta('locked') && $p->dbMigrateLogChanges < 2) { // 0 is 'sort items', 1 is 'Log changes', 2 is 'manual'
			$p->dependencySort();
//bd($p, 'sorted migration before save');
			$event->arguments(0, $p); // will be overridden if save not allowed
		}
		//bd([$p, $this, $p->meta('installable'), $this->meta('installable')], 'page $p in hook with $this and meta  for $p  and $this ');

		// Prevent more than one migration from having 'log changes' enabled
		if(!$p->meta('installable') && !$p->meta('locked') && $p->dbMigrateLogChanges == 1) {
			$error = false;
			foreach($this->migrations->children("dbMigrateLogChanges=1") as $migration) {
				if(!$migration->meta('installable') && !$migration->meta('locked') && $migration->id != $p->id) {
					$error = true;
					$this->error($this->_("You can only log changes for one migration at a time. \n
					Set the other page - " . $migration->name . " - to 'sort items or 'manual', or lock it, if you want to log changes for this one.\n
					This migration has been temporarily set to 'Sort items'. "));
				}
			}
			if($error) {
				$p->dbMigrateLogChanges = 0; // 'sort items'
				$event->arguments(0, $p);
			}
		}

		if($this->meta('installable')) {
			if($this->meta('allowSave')) {
				$event->return;
			} else {
				//bd($this, 'not saving page');
				//bd(debug_backtrace(), 'BACKTRACE');
				$this->error("$p->name - " . $this->_("This page is only installable. Saving it has no effect."));
				$event->replace = true;
				$event->return;
			}
		} else {


			$itemList = $this->listItems(); // selector validation happens here
			// $itemList is array of arrays, each [type, action, name, oldName]

			// Validate names and related objects (in the current database context), where relevant
			$errors = $this->validateValues($itemList);
			if($errors) {
				//bd($this, 'not saving page');
				$this->wire()->session->error(implode(', ', $errors));
				$event->replace = true;
				$event->return;
			}

			// Queue warnings for reporting on save
			$warnings = [];
			foreach($itemList as $item) {
				if(!$item or !isset($item['type']) or !isset($item['name']) or !isset($item['action'])) continue;
				// check if objects exist
				$exists = ($item['type'] == 'pages') ? ($this->wire()->pages->get($item['name'])
					and $this->wire()->pages->get($item['name'])->id) : ($this->wire($item['type'])->get($item['name']));

				//Removed fields etc. which still exist or new/changed fields which do not exist
				if($item['action'] == 'removed' and $exists) $warnings[] = "{$item['type']} -> {$item['name']} " .
					$this->_("is listed as 'removed' but exists in the current database");
				if($item['action'] != 'removed' and !$exists) $warnings[] = "{$item['type']} -> {$item['name']} " .
					$this->_("is listed as 'new' or 'changed' but does not exist in the current database");
			}

			// check for overlapping scopes
			if(!$this->meta('locked')) {
				$checked = $this->checkOverlaps($itemList);
				if(!$checked) {
					//bd($this, 'not saving page');
					$event->replace = true;
					$event->return;
				}
			}
			if($warnings) $this->wire()->session->warning(implode(",\n", $warnings));
		}
	}


	/**
	 * After saved actions
	 * Warn of missing elements in migration items
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	public function afterSaved(HookEvent $event) {
		$p = $event->arguments(0);
		if(!$p or !$p->id) return;
		if($this->id != $p->id) return;
		$k = 0;
		foreach($this->dbMigrateItem as $item) {
			/* @var $item RepeaterDbMigrateItemPage */
			$k++;
			if(!$item->dbMigrateType or !$item->dbMigrateAction or !$item->dbMigrateName) {
				$this->wire()->session->warning($this->_('Missing values for item ') . $k);
				//bd($item, 'missing values in item');
			}
		}
	}

	protected function beforeTrashThis(HookEvent $event) {
		//bd([$this, $event], '[$this, $event] in before trash');
		$p = $event->arguments(0);
		if(!$p or !$p->id) return;
		//bd($event, 'hook event');
		if($this->id != $p->id) return;  // only want this method to run on the current instance, not all instances of this class
		$migrationPath = $this->migrationsPath . $this->name . '/';
		if(is_dir($migrationPath)) {

			if(!$this->meta('draft')) {
				wire()->session->warning('Cannot delete a migration page which has files. Please delete the migration files first. 
			Also check that this migration is not used by any other database.');
				$event->replace = true;
				$event->return = null;
			} else {
				// For draft migrations (created from database comparisons), delete the migration files
				$migrationPath = $this->migrationsPath . $this->name . '/';
				if(is_dir($migrationPath)) {
					//bd($migrationPath, 'Deleting migration files');
					$this->wire()->files->rmdir($migrationPath, true);
				}
				$this->meta()->remove('installable'); // there are no migration files, so remove the 'installable' meta to enable deletion
			}
		} else {
			$this->meta()->remove('installable'); // there are no migration files, so remove the 'installable' meta to enable deletion
		}
	}

	protected function afterTrashedThis(HookEvent $event) {
		$p = $event->arguments(0);
		if(!$p or !$p->id) return;
		//bd($event, 'hook event');
		if($this->id != $p->id) return;  // only want this method to run on the current instance, not all instances of this class
		if(!$this->wire()->session->get('trash-drafts')) { // drafts being deleted on creation of new draft - want to stay on page
			// Find where the setup page is because it might have been moved after installation
			$admins = wire()->pages->find("template=admin, include=all");
			$setupPage = null;
			foreach($admins as $p) {
				if($p->process == 'ProcessDbMigrate') {
					$setupPage = $p;
					break;
				}
			}
			if($setupPage) {
				$this->wire()->session->redirect($setupPage->url);
			}
		}
	}


	// Add classes to selected migration row Repeater items
	protected function afterFieldsetRender(HookEvent $event) {
		/* @var $fieldset InputfieldFieldset */
		$fieldset = $event->object;
		$attr = $fieldset->wrapAttr();
//		bd([$fieldset, $attr], 'fieldset, wrapattr');
		// Fieldsets in a Repeater inputfield have a data-page attribute
		if(isset($attr['data-page'])) {
			// Get the Repeater item
			$p = $this->pages((int)$attr['data-page']);
			// Check field values and add classes accordingly
			if($p->dbMigrateType && $p->dbMigrateType->value && $p->dbMigrateAction && $p->dbMigrateAction->value) {
				$type = $p->dbMigrateType->value;
				$action = $p->dbMigrateAction->value;
				$fieldset->addClass("$type-$action");
			}
			// If item has a dependencies
			if($p->meta('sourceData')) {
				if(isset($p->meta('sourceData')['id']) && $p->meta('sourceData')['id']) {
					$fieldset->addClass('has-wand');
				}
				if(isset($p->meta('sourceData')['dependencies']) && !empty(array_filter($p->meta('sourceData')['dependencies']))) {
					$fieldset->addClass('has-arrows');
				}
			}
		}
	}
}
