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
 *
 *
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
			if(!is_dir($migrationPathNewOld)) if(!wireMkdir($migrationPathNewOld, true)) {          // wireMkDir recursive
				throw new WireException("Unable to create migration directory: $migrationPathNewOld");
			}
			if(!is_dir($migrationPathNewOld . 'files/')) if(!wireMkdir($migrationPathNewOld . 'files/', true)) {
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
		} else if(isset($this->configData['database_name'])) {
			$migrationData['sourceDb'] = $this->configData['database_name'];
		}
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
							if(!wireMkdir($migrationPathNewOld . 'files/' . $id . '/', true)) {
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
			 * (introduced in version 0.1.0 - migrations created under earlier versions will not have done this ans therefore will have more limited scope change checking)
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
				if(!is_dir($cachePath)) if(!wireMkdir($cachePath, true)) {          // wireMkDir recursive
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
					$R = $this->array_compare($newArray, $cmpArray['new']);
					$R = $this->pruneImageFields($R, 'new');
					//bd($R, ' array compare new->cmp');
					//bd(wireEncodeJSON($R), ' array compare json new->cmp');
					$installedData = (!$R);
					$installedDataDiffs = $R;

					/*
					 * Compare 'old' data
					 */
					$R2 = $this->array_compare($oldArray, $cmpArray['old']);
					$R2 = $this->pruneImageFields($R2, 'old');
					//bd($R2, ' array compare old->cmp');
					//bd(wireEncodeJSON($R2), ' array compare json old->cmp');
					$uninstalledData = (!$R2);
					$uninstalledDataDiffs = $R2;

					/*
					* Finally compare the total difference between old and new files if both files are present
					 */
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
			if($item['action'] == 'new' and $compareType == 'new' and $newOld != 'compare') {
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
			//bd($objectData, 'object data for names ' . implode(', ', $names));
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
					$objects = $this->wire($type)->find($itemArray['name']. ", include=all"); // $itemArray['name'] is a selector
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
			$restrictFields = array_filter($this->wire()->sanitizer->array(
				str_replace(' ', '', $this->dbMigrateRestrictFields),
				'fieldName',
				['delimiter' => ','])
			);
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
		$attrib['parent'] = ($exportPage->parent->path) ?: $exportPage->parent->id;  // id needed in case page is root
		$attrib['status'] = $exportPage->status;
		$attrib['name'] = $exportPage->name;
		$attrib['id'] = $exportPage->id;
		foreach($exportPage->getFields() as $field) {
			$name = $field->name;
			//bd($restrictFields, '$restrictFields');
			if((count($restrictFields) > 0 and !in_array($name, $restrictFields)) or in_array($name, $excludeFields)) continue;
			$exportPageDetails = $this->getFieldData($exportPage, $field, $restrictFields, $excludeFields);
			$attrib = array_merge_recursive($attrib, $exportPageDetails['attrib']);
			$files[] = $exportPageDetails['files'];

		}
		foreach($excludeFields as $excludeField) {
			unset($attrib[$excludeField]);
		}
		$data[$key] = $attrib;
		//bd($data, 'returning data');
		return ['data' => $data, 'files' => $files, 'repeaterPages' => $repeaterPages];
	}

	/**
	 * Get field data (as an array) for a page field
	 * NB Repeater fields cause this method to be called recursively
	 *
	 * @param $page
	 * @param $field
	 * @param array $restrictFields
	 * @param array $excludeFields
	 * @return array
	 *
	 */
	public function getFieldData($page, $field, $restrictFields = [], $excludeFields = []) {
		$attrib = [];
		$files = [];
		$name = $field->name;
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
					//bd($files, 'files for page ' . $page->name);
				}
				$attrib[$name] = $contents;
				//bd($attrib, 'attrib');
				break;
//            case 'FieldtypeTextarea' :
//                  // uses default
//                break;
			case 'FieldtypeOptions' :
				$attrib[$name] = $page->$field->id;
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
						$itemDetails = $this->getFieldData($item, $subField, $restrictFields, $excludeFields);
						$subData = $itemDetails['attrib'];
						//bd($subData, 'subData');
						$itemData = array_merge_recursive($itemData, $subData);
						$files = array_merge_recursive($files, $itemDetails['files']);
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
				if(is_object($page->$field) and property_exists($page->$field, 'data')) {
					$attrib[$name] = $page->$field->data;
				} else {
					$attrib[$name] = $page->$field;
				}
				break;
		}
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
		if(!$object) {
			if(!$this->meta('draft')) $this->wire()->session->error($this->_($this->name . ': No object for ' . $item['name'] . '.'));
//                throw new WireException('missing object' . $item['name']); // for debugging
			return ['data' => [], 'files' => []];
		}
		//$this->wire()->session->set('fixExportConfigData', true);  // setting this allows hook ProcessDbMigrate::afterExportConfigData() to run and fix Fieldtype::exportConfigData() which causes problems
		$objectData = $object->getExportData();  // session var no longer used as fix should apply throughout
		//$this->wire()->session->remove('fixExportConfigData');

		if(isset($objectData['id'])) unset($objectData['id']);  // Don't want ids as they may be different in different dbs
		//bd($objectData, 'objectdata');
		if($item['type'] == 'fields') {
			// enhance repeater / page ref / custom field data
			if(in_array($objectData['type'], ['FieldtypeRepeater', 'FieldtypePage', 'FieldtypeImage', 'FieldtypeFile'])) {
				$f = $this->wire('fields')->get($objectData['name']);
				if($f) {
					if(in_array($objectData['type'], ['FieldtypeRepeater', 'FieldtypePage'])) {
						$templateId = $f->get('template_id');
						if($templateId) {
							$templateName = $this->wire('templates')->get($templateId)->name;
							$objectData['template_name'] = $templateName;
						}
						unset($objectData['template_id']);

						$templateIds = $f->get('template_ids');
						if($templateIds) {
							$objectData['template_names'] = [];
							foreach($templateIds as $templateId) {
								$templateName = $this->wire('templates')->get($templateId)->name;
								$objectData['template_names'][] = $templateName;
							}
						}
						unset($objectData['template_ids']);

					} else {
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
							$fieldType = strtolower(str_replace('Fieldtype', '',  $objectData['type']));
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
				if($type == 'sourceDb') continue; // Ignore source database tags in comparisons
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
					if($mValue != $aArray2[$mKey]) {
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
					$diffsRemain = [];
					foreach($data as $fName => $values) {
						$diffsRemain[$pName][$fName] = true;
						//bd([$fName => $values], "[Field name => Values] in pruneImageFields for $pName");
						if(!$values or !is_array($values) or count($values) == 0) {
							continue;
						}
						$field = $this->wire('fields')->get($fName);
						if($field and ($field->type == 'FieldtypeImage' or $field->type == 'FieldtypeFile')) {
							if(isset($values['url'])) unset($diffs[$pName][$fName]['url']);
							if(isset($values['path'])) unset($diffs[$pName][$fName]['path']);
							if(is_array($diffs[$pName][$fName]) and count($diffs[$pName][$fName]) > 0) {
								$diffsRemain[$pName][$fName] = true;
								//bd($diffs[$pName][$fName], 'remaining diffs in images');
							} else {
								$diffsRemain[$pName][$fName] = false;
							}
						} else if($field and $field->type == 'FieldtypeTextarea') {
							if($this->meta['idMap'] and count($values) == 2) {     // we have the different 2 vals and an idMap to fix the image/file links
								$newVals = [];
								foreach($values as $value) {
									$newVals[] = $this->replaceLink($value, $this->meta['idMap'], $newOld, true);
								}
								if($newVals and $newVals[0] == $newVals[1]) {
									$diffsRemain[$pName][$fName] = false;
								}
							}
						}
					}
					foreach($data as $fName => $values) {
						if(!$diffsRemain[$pName][$fName]) {
							//bd($diffs[$pName][$fName], "unset field $pName -> $fName");
							unset($diffs[$pName][$fName]);
							unset($diffsRemain[$pName][$fName]);
						}
					}
					if(count($diffsRemain[$pName]) == 0 and is_array($diffs[$pName]) and count($diffs[$pName]) == 0) {
						// (don't unset page if there are more than just image fields for this page)
						//bd($diffs[$pName], "unset page $pName");
						unset($diffs[$pName]);
					}
				}
			}
		}
		//bd($diffs, 'Page diffs after unset');
		return $diffs;
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
		//bd([$html,$idMapArray, $newOld, $checkFileEquality], 'In replaceLink with [$html,$idMapArray, $newOld, $checkFileEquality]');
		if(!$idMapArray) return $html;
		if(strpos($html, '<img') === false and strpos($html, '<a') === false) return $html; //return early if no images or links are embedded in html
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
		//bd($this, 'In install with newOld = ' . $newOld);

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
					} else {
						$this->removeItems($items, $itemType);
					}
				}
			}
		}
		if($this->name != 'dummy-bootstrap') {
			// update any images in RTE fields (links may be different owing to different page ids in source and target dbs)
			$idMapArray = $this->setIdMap($pagesInstalled);
			//bd($idMapArray, 'idMapArray');
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
				}
			}
			$this->exportData('compare'); // sets meta('installedStatus')
			$this->wire()->pages->___save($this, array('noHooks' => true, 'quiet' => true));
			$this->meta('updated', true);
		}
		if($message) $this->wire()->session->message(implode(', ', $message));
		if($warning) $this->wire()->session->warning(implode(', ', $warning));
		//bd($newOld, 'finished install');
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
		$this->wire()->session->set('dbMigrate_installFields', true);  // for use by host app. also used in beforeSave hook in ProcessDbMigrate.module
		$items = $this->pruneKeys($items, $itemType);
		// repeater fields should be processed last as they may depend on earlier fields
		$repeaters = [];
		$pageRefs = [];
		foreach($items as $name => $data) {
			// Don't want items which are selectors (these may be present if the selector yielded no items)
			if(!$this->wire()->sanitizer->validate($name, 'name')) continue;
			// remove the repeaters to a separate array
			if($data['type'] == 'FieldtypeRepeater') {
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
				}
				unset($fData['template_name']);  // it was just a temp variable - no meaning to PW
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
			$templateName = 'repeater_' . $repeater['name'];
			$t = $this->wire()->templates->get($templateName);
			if(!$t) {
				$this->wire()->session->error(sprintf(
						$this->_('Cannot install repeater %1$s because template %2$s is missing. Is it out of sequence in the installation list?'),
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
			$newRepeaters[$fName] = $fData;
		}
		if($newRepeaters) $this->processImport($newRepeaters);
		$this->wire()->session->remove('dbMigrate_installFields');
	}

	/**
	 * Where keys 0f $data are in the format of a pair x|y, replace this by just the pair member that exists in the current database
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
		$this->wire()->session->set('dbMigrate_installTemplates', true);  // for use by host app. also used in beforeSave hook in ProcessDbMigrate.module
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
			//bd($result, 'template result');
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
		$this->wire()->session->remove('dbMigrate_installPages');
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
		$item->save();
		if(!$item->fieldgroups_id) {
			$item->setFieldgroup($fieldgroup);
			$item->save();
		}
		//$this->testContext(); // for debugging
	}

	/**
	 * Just used for debugging saveItem()
	 * @throws WireException
	 */
	public function testContext() {
		$t = $this->wire()->templates->get('repeater_dbMigrateItem');
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
		$this->wire()->session->set('dbMigrate_installPages', true);  // for use by host app. also used in beforeSave hook in ProcessDbMigrate.module
		$items = $this->pruneKeys($items, $itemType);
		//bd($items, 'items in install pages');
		$pagesInstalled = [];
		foreach($items as $name => $data) {
			// Don't want items which are selectors (these may be present if the selector yielded no pages)
			if(!$this->wire()->sanitizer->path($name)) continue;

			$p = $this->wire('pages')->get($name);
			/* @var $p DefaultPage */
			$pageIsHome = ($data['parent'] === 0);
			if($this->name == 'dummy-bootstrap' and $name == ProcessDbMigrate::SOURCE_ADMIN . ProcessDbMigrate::MIGRATION_PARENT) {
				// Original admin url/path used in bootstrap json may differ from target system
				$parent = $this->wire()->pages->get(2); // admin root
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
			if($p and $p->id) {
				$p->parent = $data['parent'];
				$p->template = $data['template'];
				$p->status = $data['status'];
				$fields = $this->setAndSaveComplex($fields, $p); // sets and saves 'complex' fields, returning the other fields
				$p->of(false);
				$p->save();
				$fields = $this->setAndSaveFiles($fields, $newOld, $p); // saves files and images, returning other fields
				//bd($fields, 'fields to save');
				$p->setAndSave($fields);
				$this->setAndSaveRepeaters($repeaters, $p);
				$this->wire()->session->message($this->_('Set and saved page ') . $name);
			} else {
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
				$p->of(false);
				$p->save();
				$p = $this->wire()->pages->get($p->id);
				$fields = $this->setAndSaveComplex($fields, $p); // sets and saves 'complex' fields, returning the other fields
				$fields = $this->setAndSaveFiles($fields, $newOld, $p); // saves files and images, returning other fields
				$p->setAndSave($fields);
				$this->setAndSaveRepeaters($repeaters, $p);
				$this->wire()->session->message($this->_('Created page ') . $name);
			}
			if($origId) $p->meta('origId', $origId); // Save the id of the originating page for matching purposes
			$p->of(false);
			$p->save();
			//bd($p, 'saved page at end of install');
			$pagesInstalled[] = $p;
		}
		$this->wire()->session->remove('dbMigrate_installPages');
		return $pagesInstalled;
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
		foreach($values as $fieldName => $data) {
			$f = $this->wire('fields')->get($fieldName);
			if($f and ($f->type == 'FieldtypeRepeater' || $f->type == 'FieldtypeRepeaterMatrix')) {
				$repeaterItems = [];
				unset($values[$fieldName]);
				foreach($data as $datum) {
					if(isset($datum['data'])) $repeaterItems[] = $datum['data'];
				}
				$repeaters[$fieldName] = $repeaterItems;
			}
		}
		//bd($repeaters, 'repeaters');
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
	 * @param array $repeaters
	 * @param $page // the migration page
	 * @param bool $remove // remove old repeaters
	 * @throws WireException
	 *
	 */
	public function setAndSaveRepeaters(array $repeaters, $page = null, $remove = true) {
		//bd($remove, 'remove');
		if(!$page) $page = $this;
		foreach($repeaters as $repeaterName => $repeaterData) {

			/*
			* $repeaterData should be an array of subarrays where each subarray is [fieldname => value, fieldname2 => value2, ...]
			* Get the existing data as an array to be compared
			*/
			//bd($page, 'page at start of set and save repeaters');
			//bd($repeaterName, 'repeaterName');
			//bd($repeaterData, 'repeaterData');
			$subPages = $page->$repeaterName->getArray();
			$subPageArray = [];
			$subPageObjects = []; // to keep track of the subpage objects
			foreach($subPages as $subPage) {
				$subFields = $subPage->getFields();
				//bd($subFields, 'subfields');
				$subFieldArray = [];
				foreach($subFields as $subField) {
					$subDetails = $this->getFieldData($subPage, $subField);
					//bd($subDetails, 'subdetails');
					$subFieldArray = array_merge($subFieldArray, $subDetails['attrib']);
				}
				$subPageArray[] = $subFieldArray;
				$subPageObjects[] = $subPage;
			}

			/*
			* $subPageArray should now be a comparable format to $repeaterData
			*/
			//bd($subPageArray, 'Array from existing subpages');
			//bd($repeaterData, 'Array of subpages to be set');

			/*
			 * Update/remove existing subpages
			 */
			foreach($subPageArray as $i => $oldSubPage) {    // $i allows us to find the matching existing subpage
				$subPage = $subPageObjects[$i];
				$found = false;
				foreach($repeaterData as $j => $setSubPage) {
					//bd([$oldSubPage, $setSubPage], 'old and set subpages');
					//bd(array_diff(array_map('serialize', $setSubPage), array_map('serialize', $oldSubPage)));
					if(!array_diff(array_map('serialize', $setSubPage), array_map('serialize', $oldSubPage))) {   //matching subpage, nothing new
						//bd('matching subpage, nothing new');
						$subPage->sort = $j;  // for sorting when all done
						$subPage->save();
						// remove the matching item so as not to re-use it
						unset($repeaterData[$j]);
						$found = true;
						break;
					} else if(!array_diff(array_map('serialize', $oldSubPage), array_map('serialize', $setSubPage))) {  //matching subpage, new data
						$diff = array_diff(array_map('serialize', $setSubPage), array_map('serialize', $oldSubPage));  // the new data
						//bd($subPage, 'subpage before setting');

						// NB setAndSave() does not seem to work on repeater pages, so need to iterate

						foreach(array_map('unserialize', $diff) as $diffKey => $diffItem) {
							$subPage->$diffKey = $diffItem;
						}
						$subPage->sort = $j;  // for sorting when all done
						$subPage->save();
						//bd($subPage, 'subpage after setting');
						//bd($page, 'page after subpage setting');
						unset($repeaterData[$j]);
						$found = true;
						break;
					}
				}
				if(!$found and $remove) $page->$repeaterName->remove($subPage);   // remove any subpages not in the new array (unless option set to false)
			}
			// create new subpages for any items in $repeaterData which have not been matched
			foreach($repeaterData as $j => $item) {
				$newSubPage = $page->$repeaterName->getNew();
				$newSubPage->setAndSave($item);
				$newSubPage->sort = $j;  // for sorting when all done
				$newSubPage->save();
				$page->$repeaterName->add($newSubPage);
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
			//bd($page, 'page in getidmap');
			if($page and $page->meta('origId')) $idMapArray[$page->meta('origId')] = $page->id;  // $page->id is the new id (in the target)
		}
		$this->meta('idMap', $idMapArray);
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
			}
		}
		if(!file_exists($found)) {
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
			if(isset($this->configData['database_name']) and $this->configData['database_name']
				and $sourceDb == $this->configData['database_name']) {
				if($this->meta('installable')) $this->meta()->remove('installable');
			} else {
				if(!$this->meta('installable')) $this->meta('installable', true);
			}
			$this->meta('sourceDb', $sourceDb);
		}

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
		 * NB Also it is needed to display more meaning ful statuses - e.g. 'superseded'
		 */
		$this->exportData('compare'); // sets meta('installedStatus')

		//bd($this->meta('locked'), 'Locked status');
		if($this->meta('locked')) return false;
		/*
		* Continue only for unlocked pages
		 */

		if(isset($fileContents['sourceDb'])) unset($fileContents['sourceDb']);  // temporary so we don't attempt to process it

		// notify any conflicts
		$itemList = $this->listItems();
		if(!$this->meta('locked')) $this->checkOverlaps($itemList);

		//bd($this->meta('installable'), 'installable?');
		if(!$this->meta('installable') or $this->meta('locked')) return true;  // Don't need 2nd condition?

		/*
		* Only installable pages (i.e. in target environment) need to be refreshed from json files
		*/
		//bd($fileContents, 'already found file contents');
		// in practice there is only one item in the array (after 'sourceDb' has been unset) as it is just for the migration page itself
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
								if(!wireMkdir($migrationPath . '/archive/', true)) {          // wireMkDir recursive
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
						$p->setAndSave($values);
						if(count($repeaters) > 0) $this->setAndSaveRepeaters($repeaters, $p);
						$p->meta()->remove('allowSave');  // reset
					} else {
						$p->setAndSave($values);
						if(count($repeaters) > 0) $this->setAndSaveRepeaters($repeaters, $p);
					}
					//bd($p, 'p after save');
				}
			}
		}
		return true;
	}

	/**
	 * Parse items, expanding selectors as necessary
	 * Return list of all items in format [[type, action, name, oldName], [...], ...]
	 *
	 * @return array[]
	 * @throws WireException
	 *
	 */
	public function listItems() {
		$list = [];
		$items = $this->dbMigrateItem;
		foreach($items as $item) {
			$itemArray = $this->populateItem($item);
			if(!$itemArray['type'] or !$itemArray['name']) continue;
			$expanded = $this->expandItem($itemArray);
			foreach($expanded['items'] as $expandedItem) {
				$name = $expandedItem['name'];
				$oldName = $expandedItem['oldName'];
				$list[] = [
					'type' => $item->dbMigrateType->value,
					'action' => ($item->dbMigrateAction) ? $item->dbMigrateAction->value : 'changed',
					'name' => $name,
					'oldName' => $oldName];
			}
		}
		return $list;
	}

	/**
	 * Check that names and oldNames of current migration do not overlap with those of other (unlocked) migrations
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
		//bd([$p, $this, $p->meta('installable'), $this->meta('installable')], 'page $p in hook with $this and meta  for $p  and $this ');
		if($this->meta('installable')) {
			if($this->meta('allowSave')) {
				$event->return;
			} else {
				//bd($this, 'not saving page');
				//bd(debug_backtrace(), 'BACKTRACE');
				$this->warning("$p->name - " . $this->_("This page is only installable. Saving it has no effect."));
				$event->replace = true;
				$event->return;
			}
		} else {
			//bd($this, 'saving page');

			$itemList = $this->listItems(); // selector validation happens here
			// $itemList is array of arrays, each [type, action, name, oldName]

			// Validate names and related objects (in the current database context), where relevant
			$errors = $this->validateValues($itemList);
			if($errors) {
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
}
