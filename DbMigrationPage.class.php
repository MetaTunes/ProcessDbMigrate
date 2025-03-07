<?php

namespace ProcessWire;

/*
 * Need to allow for possibility of using DefaultPage (if it exists) as the base class so that any user-added methods
 * are kept
 */

use Exception;

if(wireClassExists('DefaultPage')) {
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
 * @property int $dbMigrateLogChanges Toggle: 1 = Log changes, 0 = Sort on save, 2 = Manual
 * @property string $dbMigrateFieldTracking Selector for fields to be tracked if 'log changes' is on
 * @property string $dbMigrateTemplateTracking Selector for templates to be tracked if 'log changes' is on
 * @property string $dbMigratePageTracking Selector for pages to be tracked if 'log changes' is on
 * @property string $dbMigrateSummary Summary
 * @property string $dbMigrateAdditionalDetails Additional details
 * @property RepeaterDbMigrateItemPage $dbMigrateItem Migration item
 * @property string $dbMigrateRestrictFields Restrict fields
 * @property RepeaterDbMigrateSnippetsPage $dbMigrateSnippets Snippets
 * @property mixed $dbMigrateRuntimeReady Hooks etc
 *
 */
class DbMigrationPage extends DummyMigrationPage {

	// Module constants
	/*
	 * Fields which affect the migration - i.e. they contain key data determining the migration, rather than just information
	 *
	 */
	const KEY_DATA_FIELDS = array('dbMigrateItem', 'dbMigrateRestrictFields');
	const INFO_ONLY_FIELDS = array('dbMigrateSummary', 'dbMigrateAdditionalDetails', 'dbMigrateSnippets');
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

	/**
	 * Get the data for a migration item
	 * @return void
	 */
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
		$dbM = wire('modules')->get('ProcessDbMigrate');
		/* @var $dbM ProcessDbMigrate */
		$this->set('dbM', $dbM);
		$this->set('dbName', $this->dbM->dbName());

		if(isset($this->configData['suppress_hooks']) && $this->configData['suppress_hooks']) $this->wire()->error("Hook suppression is on - migrations will not work correctly - unset in the module settings.");
		// Fix for PW versions < 3.0.152, but left in place regardless of version, in case custom page classes are not enabled
		if($this->migrationTemplate->pageClass != __CLASS__) {
			$this->migrationTemplate->pageClass = __CLASS__;
			$this->migrationTemplate->save();
		}
		// Omit hooks if $this is null page (e.g. dummy-bootstrap)
		if($this->id) {
			$this->addHookAfter("Pages::saved(template=$this->migrationTemplate)", $this, 'afterSaved');
			$this->addHookBefore("Pages::save(template=$this->migrationTemplate)", $this, 'beforeSaveThis');
			$this->addHookBefore("Pages::trash(template=$this->migrationTemplate)", $this, 'beforeTrashThis');
			$this->addHookAfter("Pages::trashed(template=$this->migrationTemplate)", $this, 'afterTrashedThis');
			$this->addHookAfter("InputfieldFieldset::render", $this, 'afterFieldsetRender');


			$readyFile = $this->migrationsPath . '/' . $this->name . '/ready.php';
			if(file_exists($readyFile)) include_once $readyFile;
		}
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
		if(!$this->id) return;

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
		//bd($excludeFields, 'excludeFields');
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
		// Include an item for the site url and admin url as these may be different in the target
		$migrationData['sourceSiteUrl'] = $this->wire()->config->urls->site;
		$migrationData['sourceAdminUrl'] = $this->wire()->config->urls->admin;
		$migrationObjectJson = $this->modifiedJsonEncode($migrationData);
		if($newOld != 'compare') {
			file_put_contents($migrationPathNewOld . 'migration.json', $migrationObjectJson);
			$this->wire()->session->message($this->_('Exported migration definition as ') . $migrationPathNewOld . 'migration.json');
		}

		/*
		 * NOW CREATE THE MAIN JSON DATA FILES
		 */
		if(!$this->meta('draft') or $newOld != 'new') {   // meta('draft') denotes draft migration prepared from comparison

			/*
			 * GET DATA FROM PAGE AND SAVE IN JSON
			 */
			//$itemRepeater = $this->getFormatted('dbMigrateItem'); //getFormatted to get only published items
			$itemRepeater = $this->dbMigrateItem->find("status=1");
			//bd($itemRepeater, $itemRepeater);
			if($newOld == 'new' || $newOld == 'compare') {
				$items = $this->cycleItems($itemRepeater, $excludeAttributes, $excludeFields, $newOld, 'new');
				$data = $items['data'];
				//[$this, $data], 'this, data for json');
				$files['new'] = $items['files'];
				$objectJson['new'] = $this->modifiedJsonEncode($data);
				//bd($objectJson['new'], 'New json created');
			}
			if($newOld == 'old' || $newOld == 'compare') {
				$reverseItems = $this->cycleItems($itemRepeater, $excludeAttributes, $excludeFields, $newOld, 'old'); // cycleItems will reverse order for uninstall
				$reverseData = $reverseItems['data'];
				$files['old'] = $reverseItems['files'];
				$objectJson['old'] = $this->modifiedJsonEncode($reverseData);
			}
			//bd($files, 'files in export data');
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
					//bd($this->modifiedJsonEncode($R), ' array compare json new->cmp');
					$installedData = (!$R);
					$installedDataDiffs = $R;

					/*
					 * Compare 'old' data
					 */
					//bd('old data');
					$R2 = $this->array_compare($oldArray, $cmpArray['old']);
					$R2 = $this->pruneImageFields($R2, 'old');
					//bd($R2, ' array compare old->cmp');
					//bd($this->modifiedJsonEncode($R2), ' array compare json old->cmp');
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
					$newMigrationArray = wireDecodeJSON($newMigFile);
//					bd($newMigrationArray, 'newMigrationArray');
					$cmpMigrationArray = wireDecodeJSON($cmpMigFile);
//					bd($cmpMigrationArray, 'cmpMigrationArray');

					// make sure that the sourceadmin name is changed to match the current environment
					$sourceAdmin = (isset($cmpMigrationArray[0]['sourceAdminUrl'])) ?  $cmpMigrationArray[0]['sourceAdminUrl'] : ProcessDbMigrate::SOURCE_ADMIN;
//					bd($sourceAdmin, 'sourceAdmin');
					$oldSourceAdmin = (isset($newMigrationArray[0]['sourceAdminUrl'])) ? $newMigrationArray[0]['sourceAdminUrl'] : ProcessDbMigrate::SOURCE_ADMIN;
//					bd([$this->name, $sourceAdmin, $oldSourceAdmin], 'migration, sourceAdmin, oldSourceAdmin');
					if($sourceAdmin != $oldSourceAdmin) {  // NB sourceAdmin and oldSourceAdmin already have leading and trailing slashes
						$pagesChanged = $newMigrationArray[0]['pages']['changed'];
						$oldKey = array_key_first($pagesChanged);
						$newKey = str_replace($oldSourceAdmin, $sourceAdmin, $oldKey);
						if($newKey != $oldKey) {
							$pagesChanged[$newKey] = $pagesChanged[$oldKey];
							unset($pagesChanged[$oldKey]);
							$pagesChanged[$newKey]['parent'] = str_replace($oldSourceAdmin, $sourceAdmin, $pagesChanged[$newKey]['parent']);
							$newMigrationArray[0]['pages']['changed'] = $pagesChanged;
							$newMigrationArray[0]['sourceAdminUrl'] = $sourceAdmin;
						}
//						bd($newMigrationArray, 'newMigrationArray after');
						//bd($sourceAdmin, 'sourceAdmin');
					}
					//

					$R = $this->array_compare($this->compactArray($newMigrationArray), $this->compactArray($cmpMigrationArray));
					$R = $this->pruneImageFields($R, 'new');
					$installedMigration = (!$R);
					$installedMigrationDiffs = $R;
					//bd('old migration');
					$R2 = $this->array_compare($this->compactArray(wireDecodeJSON($oldMigFile)), $this->compactArray($cmpMigrationArray));
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

	public function modifiedJsonEncode($data) {
		$json = wireEncodeJSON($data, true, true);
		$json = str_replace('\t', ' ', $json);
		//bd($json, 'modified json');
		return $json;
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
		if(!$itemRepeater || $itemRepeater->isUnpublished) return ['data' => '', 'files' => ''];
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
		if(!$this->id) return $empty;
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

			// $flag indicates which actions should have associated item in the current database
			if($this->meta('installable')) { 	// target database
				$flag = ($compareType == 'new') ? 'removed' : 'new';
			} else { 							// source database
				$flag = 'new';
			}
			//bd(['action' => $item['action'], 'newOld' => $newOld, 'compareType' => $compareType, 'flag' => $flag]);
			if(isset($flag) && $item['action'] == $flag) {
				//bd(['action' => $item['action'], 'flag' => $flag], 'Reporting exception');
				//bd(debug::backtrace(), 'backtrace');
				$this->wire()->session->warning(sprintf($this->_('Selector "%s" did not select any items'), $item['name']));
			}
			return ['data' => $data, 'files' => []];
		}
		if($noFind) {
			// 'changed' items should exist in all contexts
			//bd('No object for ' . $itemName . '.');
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

	/**
	 * Get array of restricted fields - i.e. only these fields to be considered in migrating pages
	 *
	 * @return array
	 * @throws WireException
	 */
	public function restrictFields() {
		if(!$this->dbMigrateRestrictFields) return [];
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
		if(!$exportPage || !$exportPage->id) return;
		foreach($exportPage->getFields() as $field) {
			$name = $field->name;
			//bd($restrictFields, '$restrictFields');
			//bd($name, '$name');
			if((count($restrictFields) > 0 && !in_array($name, $restrictFields)) || in_array($name, $excludeFields)) continue;
			$exportPageDetails = $this->getFieldData($exportPage, $field, $restrictFields, $excludeFields, $fresh);
			if(isset($exportPageDetails['attrib'])) $attrib = array_merge_recursive($attrib, $exportPageDetails['attrib']);
			//bd([$exportPage, $field, $attrib], 'exportPage, field, attrib in getAllFieldData');
			if(isset($exportPageDetails['files'])) $files[] = $exportPageDetails['files'];
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
		if(!$page->hasField($name)) {    // NB changed from (1) if(!$page->data($name) and then from (2) $page->data($name) === null. Review options if this causes probs, but remember need to return empty values if there is no item
			// NB (2) Must be === otherwise items with value=0 get discarded as if they had no value and cause mismatch errors in target
			//bd([$page, $name], 'returning empty');
			return ['attrib' => $attrib, 'files' => $files];
		}
		//bd([$page, $name], 'continuing with getFieldData');
		switch($field->type) {
			case 'FieldtypePage' :
//			case 'FieldtypePageTable' :
				$attrib[$name] = $this->getPageRef($page->$field);
				break;
			case 'FieldtypePageTable' :  // NB Not sure why I replaced the above with this, which returns name and parent path separately
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
				$contents['url'] = ($page->$field && $page->$field->url) ? $page->$field->url : '';
				$contents['path'] = ($page->$field && $page->$field->path) ? $page->$field->path : '';
				$contents['items'] = [];
				$contents['custom_fields'] = [];
				if($page->$field && (get_class($page->$field) == "ProcessWire\Pageimage" || get_class($page->$field) == "ProcessWire\Pagefile")) {
					$items = [$page->$field];  // need it to be an array if only singular image or file
				} else {
					$items = ($page->$field) ? $page->$field->getArray() : [];
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
						$subData = $itemDetails['attrib'] ?? [];
						//bd($subData, 'subData');
						$itemData = array_merge_recursive($itemData, $subData);
						//bd([$files, $itemDetails['files']], 'merging files');
						if(isset($itemDetails['files'])) {
							foreach($itemDetails['files'] as $key => $file) {
								$files[$key] = $file;
							} // Can't use $files = array_merge_recursive($files, $itemDetails['files']); because integer indexes get re-sequenced
						}
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
			case 'FieldtypeTextarea' :
				if(is_object($page->$field) && property_exists($page->$field, 'data')) {
					//bd([$page, $field, $page->$field, $page->$field->data], 'page field data');
					$attrib[$name] = $page->$field->data;
				} else {
					//bd([$page, $field, $page->$field], 'page field');
					$attrib[$name] = $page->$field;
					//bd($this->dbM->findRteImageSources($page), 'rte images');
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
		//bd($item['type'], 'type');
		//bd($object, 'object in getExportStructureData');
		if(!$object) {
			if(!$this->meta('draft')) $this->wire()->session->error($this->_($this->name . ': No object for ' . $item['name'] . '.'));
//                throw new WireException('missing object' . $item['name']); // for debugging
			return ['data' => [], 'files' => []];
		}
		$objectData = $this->dbM->getExportDataMod($object);  // session var no longer used as fix should apply throughout
		if(!$objectData) {
			//bd($objectData, 'objectData in getExportStructureData');
			$this->wire()->session->error($this->_($this->name . ': No object data for ' . $item['name'] . '.'));
			return ['data' => [], 'files' => []];
		}

		if(isset($objectData['id'])) unset($objectData['id']);  // Don't want ids as they may be different in different dbs
		//bd($objectData, 'objectdata');
		if($item['type'] == 'fields') {
			// enhance repeater / page ref / custom field data
			if(isset($objectData['type']) && in_array($objectData['type'], ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix', 'FieldtypePage', 'FieldtypePageTable', 'FieldtypeImage', 'FieldtypeFile'])) {
				$f = $this->wire('fields')->get($objectData['name']);
				if($f) {
					if(in_array($objectData['type'], ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix', 'FieldtypePage', 'FieldtypePageTable'])) {

						// 'template_id' is used by Page and PageTable types
						$templateId = $f->get('template_id');
						if($templateId) {
							//bd($templateId, 'template id');
							if(!is_array($templateId)) {
								$templateId = [$templateId];
								$singular = true;
							} else {
								$singular = false;
							}
							$objectData['template_name'] = [];
							foreach($templateId as $id) {
								$templateName = $this->wire('templates')->get($id)->name;
								$objectData['template_name'][] = $templateName;
							}
							if($singular) $objectData['template_name'] = $objectData['template_name'][0];
							//bd($objectData['template_name'], 'template names');
						}
						unset($objectData['template_id']);
						//

						// 'template_ids' is used by Repeater and RepeaterMatrix types
						$templateIds = $f->get('template_ids');
						if($templateIds) {
							$objectData['template_names'] = [];
							foreach($templateIds as $templateId) {
								$templateName = $this->wire('templates')->get($templateId)->name;
								$objectData['template_names'][] = $templateName;
							}
						}
						unset($objectData['template_ids']);
						//

						$parentId = (int)$f->get('parent_id');
						if($parentId && in_array($objectData['type'], ['FieldtypePage', 'FieldtypePageTable'])) {   // Don't want to set parent_path for repeaters as not needed and references may differ in the target db
							$parentPath = $this->wire('pages')->get($parentId)->path;
							$objectData['parent_path'] = $parentPath;
						}
						unset($objectData['parent_id']);

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
											if($this->fields()->get($matrixItem)) $matrixItemNames[] = $this->fields()->get($matrixItem)->name;
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
					//$allItems = $this->getFormatted('dbMigrateItem'); // getFormatted to get only published items
					$allItems = $this->dbMigrateItem->find("status=1");
					if($compareType == 'new' and $newOld == 'new' and isset($templateName)) {
						//bd($templateName, 'templatename');
						foreach($allItems as $other) {
							/* @var $other RepeaterDbMigrateItemPage */
							if($other->isUnpublished) continue;
							//bd($other, 'other - item ' . $i);
							//bd($other->dbMigrateType, 'other type');
							if($i >= $k) break;
							$i++;
							if($other->dbMigrateType->value == 'templates' and $other->dbMigrateName == $templateName) {
								$templateOk = true;
								break;
							}
						}
						if(!$templateOk && $this->dbMigrateLogChanges == 2) {  // only show warnings for manual sorting. Auto sorting should have fixed it
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
	public function ___array_compare(array $A, array $B) {
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
		//bd($this->meta('idMap'), 'idMap');
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
						// $values should either be a 2-item array (the items to be compared)
						// or an array tree ending with 2-item arrays at the bottom (where fields are repeater/PageTable types)
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

	/**
	 * Remove url and path from reported differences in image fields
	 *  & replace links in rich text fields with the correct files directory
	 * (as page ids may differ between source and target dbs)
	 *
	 * @param $diffs
	 * @param $diffsRemain
	 * @param $field
	 * @param $pName
	 * @param $fName
	 * @param $values
	 * @param $newOld
	 * @param $depth
	 * @return array
	 * @throws WireException
	 */
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
		} else if($field && ($field->type == 'FieldtypeRepeater' || $field->type == 'FieldtypeRepeaterMatrix' || $field->type == 'FieldtypePageTable')) {
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


	/**
	 * Recursively unset an array element
	 * NB This is not the same as array_filter_recursive which removes empty elements
	 *
	 * @param $target
	 * @param $keyChain
	 * @return mixed
	 */
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
		if(!$checkFileEquality) l('HTML: ' . $html, 'debug'); //Tracy log
		// In case one of the source and target sites have a segment root
		// NB This has been substantially modified from 2.0.18 Old code is commented out while the new code is on probation
		// NB But best to compare with saved version 2.0.18 if reversion needed
		$targetSiteUrl = $this->wire()->config->urls->site;
		//bd($targetSiteUrl, 'target site url');
		$sourceSiteUrl = ($this->sourceSiteUrl) ?: '/site/';
		//bd($sourceSiteUrl, 'source site url');
//		if(strlen($this->wire()->config->urls->site) > strlen($sourceSiteUrl)) {
//			$segDiff = 1;
//			$siteSegment = str_replace($sourceSiteUrl, '', $targetSiteUrl);
//		} else if(strlen($this->wire()->config->urls->site) < strlen($sourceSiteUrl)) {
//			$segDiff = -1;
//			$siteSegment = str_replace($this->wire()->config->urls->site, '', $sourceSiteUrl);
//		} else {
//			$segDiff = 0;
//			$siteSegment = null;
//		}
		if($newOld != 'old') {   // cut  && $siteSegment && $segDiff == 1
//			$siteSegment = trim($siteSegment, '/');

			/*
			 * The regex is intended to match:
			 * 	(a) relative references
			 *  (b) references with the httpHost name
			 * and add the segment prefix for the target site at the start (a) or after the httpHost name (b)
			 * NB This now matches all references to the source site, not just those with the segment root
			 * NB The site segment is updated in the link first before than replacing in the html
			 */
//			$re = '/(=\"|' . preg_quote($this->wire()->config->httpHost, '/') . ')\/(?!' . preg_quote($siteSegment, '/') . '\/)(.*)(?=\")/mU';
			$re = '/(=\"|' . preg_quote($this->wire()->config->httpHost, '/') . ')\/(.*)(?=\")/mU'; //new
			//bd($re, 'regex');
			preg_match_all($re, $html, $matches, PREG_SET_ORDER, 0);
			if($matches) {
				foreach($matches as $match) {
					if(!$checkFileEquality) l('MATCH[2]: ' . $match[2], 'debug'); // Tracy log
					//bd($match[2], 'match 2');
					$newSiteMatch = str_replace(ltrim($sourceSiteUrl, '/'), ltrim($targetSiteUrl, '/'), $match[2]); //new (& now using ltrim, not trim)
					//bd($newSiteMatch, 'new site match');
					$html = str_replace($match[2], $newSiteMatch, $html); // new
				}
			}
		}
		//bd($html, 'new html');
		if(!$checkFileEquality) l('New HTML: ' . $html, 'debug'); // Tracy log

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
	public function installMigration($newOld, $dummy = false, $quiet = false) {

		// nope!
		$this->of(false);

		$this->wire()->log->save('debug', 'In install with newOld = ' . $newOld);

		if(!$this->ready and $this->name != 'dummy-bootstrap') {
			$this->ready(); // don't call ready() for dummy-bootstrap as it has no template assigned at this point
		}

		// Backup the old installation first &  initialise id map and ignoreDiffs
		if($newOld == 'new' and ($this->name != 'dummy-bootstrap' && !$dummy)) {
			$this->exportData('old');
			$this->meta()->set('idMap', []);  // can't set meta on dummy page!!
			$this->meta()->set('ignoreDiffs', []);  // not set in this class - might be set by hooks
		}
		/*
		* NB The bootstrap is excluded from the above. A separate (manually constructed) 'old' file is provided
		 * for the bootstrap as part of the module and is used when uninstalling the module.
		 * Also note that here, and below, meta can only be set if there is a real page in the database
		 *
		 * $dummy allows for a similar override where this method is called by another module making use of this install feature
		 * where that module also provides its own 'old' files
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
		$migrationFile = (file_exists($migrationPathNewOld . 'migration.json'))
			? file_get_contents($migrationPathNewOld . 'migration.json') : null;
		if(!$dataFile) {
			$error = ($newOld == 'new') ? $this->_('Cannot install - ') : $this->_('Cannot uninstall - ');
			$error .= sprintf($this->_('No "%s" data.json file for this migration.'), $newOld);
			if($name == 'bootstrap') {
				$error .= $this->_(' Copy the old/data.json file from the module directory into the templates directory then try again?');
			}
			$this->wire()->session->error($error);
			return;
		}
		if(!$migrationFile) {
			$error = ($newOld == 'new') ? $this->_('Cannot install - ') : $this->_('Cannot uninstall - ');
			$error .= sprintf($this->_('No "%s" migration.json file for this migration.'), $newOld);
			if($name == 'bootstrap') {
				$error .= $this->_(' Copy the old/migration.json file from the module directory into the templates directory then try again?');
			}
			$this->wire()->session->error($error);
			return;
		}
		$dataArray = wireDecodeJSON($dataFile);
		if($this->name != 'dummy-bootstrap' && !$dummy) $this->meta('dataArray', $dataArray); // available for hooks
		$migrationArray = wireDecodeJSON($migrationFile);
		if($this->name != 'dummy-bootstrap' && !$dummy) $this->meta('migrationArray', $migrationArray); // available for hooks

		$message = [];
		$warning = [];
		$pagesInstalled = [];
		foreach($dataArray as $repeat) {
			foreach($repeat as $itemType => $itemLine) {
				//bd($itemLine, 'itemline');
				foreach($itemLine as $itemAction => $items) {
					//bd($items, 'items');
					//$this->wire()->log->save('debug', json_encode($items));
					if($itemAction != 'removed') {
						$this->wire()->session->set('dbMigrate_install', true);  // for use by host app. also used in beforeSave hook in ProcessDbMigrate.module
						switch($itemType) {
							// NB code below should handle multiple instances of objects, but we only expect one at a time for fields and templates
							// NB Can't set meta for dummy page
							case 'fields':
								if($this->name != 'dummy-bootstrap' && !$dummy) {
									if(!$this->meta('installFields')) {
										$this->meta('installFields', $items); // available for hooks
									} else {
										$this->meta('installFields', array_merge($this->meta('installFields'), $items)); // available for hooks
									}
								}
								$this->installFields($items, $itemType, $quiet);
								break;
							case 'templates' :
								if($this->name != 'dummy-bootstrap' && !$dummy) {
									if(!$this->meta('installTemplates')) {
										$this->meta('installTemplates', $items); // available for hooks
									} else {
										$this->meta('installTemplates', array_merge($this->meta('installTemplates'), $items)); // available for hooks
									}
								}
								$this->installTemplates($items, $itemType);
								break;
							case 'pages' :
								if($this->name != 'dummy-bootstrap' && !$dummy) {
									if(!$this->meta('installPages')) {
										$this->meta('installPages', $items); // available for hooks
									} else {
										$this->meta('installPages', array_merge($this->meta('installPages'), $items)); // available for hooks
									}
								}
								$pagesInstalled = array_merge($pagesInstalled, $this->installPages($items, $itemType, $newOld, $migrationArray, $dummy));
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
		if($this->name != 'dummy-bootstrap' && !$dummy && $this->id) {
			// update any images in RTE fields (links may be different owing to different page ids in source and target dbs)
			$idMapArray = $this->setIdMap($pagesInstalled);
			//bd($idMapArray, 'idMapArray');


			$this->of(false);

			$this->fixRteHtml($pagesInstalled, $idMapArray, $newOld);

			$this->exportData('compare'); // sets meta('installedStatus')
			$this->wire()->pages->___save($this, array('noHooks' => true, 'quiet' => true));
			$this->meta('updated', true);
		}
		if($message) $this->wire()->session->message(implode(', ', $message));
		if($warning) $this->wire()->session->warning(implode(', ', $warning));
		//bd($newOld, 'finished install');
		return ($this && $this->id && isset($this->meta('installedStatus')['status'])) ? $this->meta('installedStatus')['status'] : 'indeterminate';
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
					$page->save($field, ['noHooks' => true]);
				}
				if($field->type == 'FieldtypeRepeater' || $field->type == 'FieldtypeRepeaterMatrix' || $field->type == 'FieldtypePageTable') {
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
	protected function installFields($items, $itemType, $quiet = false) {
		//$this->wire()->log->save('debug', 'install fields');
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
			} else if(in_array($data['type'], ['FieldtypePage', 'FieldtypePageTable'])) {
				unset($items[$name]);
				$pageRefs[$name] = $data;
			}
		}
		/*
		 * Process the non-repeaters and non-pagerefs first
		 */
		// method below is largely from PW core
		if($items) $this->processImport($items, $quiet);

		// now the page refs
		//bd($pageRefs, 'page refs');
		$newPageRefs = [];
		foreach($pageRefs as $fName => $fData) {
			if(isset($fData['template_name'])) {
				$tName = $fData['template_name'];
				// Note that PageTable fields will have multiple (array of) templates, even though the name implies a single one
				if(!is_array($tName)) {
					$tName = [$tName];
					$singular = true;
				} else {
					$singular = false;
				}
				$fData['template_id'] = [];
				foreach($tName as $name) {
					$t = $this->wire('templates')->get($name);
					if($t) {
						$fData['template_id'][] = $t->id;
					} else {
						$error = sprintf(
							$this->_('Cannot install field %1$s properly because template %2$s is missing. Is it missing or out of sequence in the installation list?'),
							$fName,
							$name);
						if($quiet) {
							$this->wire()->log->save('dbMigrate', $error);
						} else {
							$this->wire()->session->error($error);
						}
					}
				}
				if($singular && is_array($fData['template_id']) && count($fData['template_id']) > 0) {
					$fData['template_id'] = $fData['template_id'][0];
				}
				unset($fData['template_name']);  // it was just a temp variable - no meaning to PW
			}

			if(isset($fData['parent_path'])) {
				$pPath = $fData['parent_path'];
				$pt = $this->wire('pages')->get($pPath);
				if($pt && $pt->id) {
					$fData['parent_id'] = $pt->id;
					//bd($pPath, 'set parent to id ' . $pt->id);
				} else {
					$error = sprintf(
						$this->_('Cannot install field %1$s properly because parent page %2$s is missing. Is it missing or out of sequence in the installation list?'),
						$fName,
						$name);
					if($quiet) {
						$this->wire()->log->save('dbMigrate', $error);
					} else {
						$this->wire()->session->error($error);
					}
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
		if($newPageRefs) $this->processImport($newPageRefs, $quiet);

		// then check the templates for the repeaters - they should be before the related field in the process list
		foreach($repeaters as $repeaterName => $repeater) {
			$templateName = FieldtypeRepeater::templateNamePrefix . $repeater['name'];
			$t = $this->wire()->templates->get($templateName);
			if(!$t) {
				$error = sprintf(
					$this->_('Cannot install repeater %1$s because template %2$s is missing. Is it missing or out of sequence in the installation list?'),
					$fName,
					$name);
				if($quiet) {
					$this->wire()->log->save('dbMigrate', $error);
				} else {
					$this->wire()->session->error($error);
				}
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
							if($this->fields()->get($itemName)) $itemIds[] = $this->fields()->get($itemName)->id;
						}
						$fData["matrix{$matrixType}_fields"] = $itemIds;
					}
				}
				unset($fData['matrix_field_names']); // it was just a temp variable - no meaning to PW
				//bd($fData, 'fData');
			}

			$newRepeaters[$fName] = $fData;
		}
		if($newRepeaters) $this->processImport($newRepeaters, $quiet);

		// We have to get export data now, even though we don't use it, because it triggers the config fields. Otherwise install has to be run twice in some situations
		foreach($names as $name) {
			$f = $this->wire()->fields->get($name);
			//bd([$this, $f], '$this, $f: pre-getting export data');
			if($f) {
				// fix for dummy-bootstrap as no template and ready() not yet run
				if($this->name == 'dummy-bootstrap') {
					$dbMigrate = wire('modules')->get('ProcessDbMigrate');
					$objectData = $dbMigrate->getExportDataMod($f);
				} else {
					$objectData = $this->dbM->getExportDataMod($f);
				}
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
	protected function processImport(array $data, $quiet = false) {         //MDE parameter added

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
				//MDE modified this section to provide better notices but suppress error re importing options or if $quiet set
				foreach($changes as $key => $info) {
					if($info['error'] and strpos($key, 'export_options') !== 0) {  // options have been dealt with by fix below, so don't report this error
						//bd(get_class($field->type), 'reporting error');
						if(!$quiet) $this->wire()->session->error($this->_('Error:') . " $name.$key => $info[error]");
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
				if(!$quiet) $this->error($e->getMessage());
			}

			$data[$name] = $fieldData;
		}

		//bd($field, 'Field after save');

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
		//$this->wire()->log->save('debug', json_encode($items));
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
//			//bd([$t, $result], 'template result');
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
	protected function installPages($items, $itemType, $newOld, $migrationArray, $dummy) {
		//$this->wire()->log->save('debug', json_encode($items));
		$items = $this->pruneKeys($items, $itemType);
		//bd($items, 'items in install pages');
		//bd($migrationArray, 'migrationArray in install pages');
		// Replace admin paths with target admin url if different from source
		$sourceAdmin = (isset($migrationArray['sourceAdminUrl'])) ?  $migrationArray['sourceAdminUrl'] : ProcessDbMigrate::SOURCE_ADMIN;
		if($sourceAdmin != $this->config()->urls->admin) {
			// recursively replace all source admin paths with the target path
			$items = $this->replaceAdminPath($items, $sourceAdmin, $this->config()->urls->admin);
		}
		//if($this->id) $this->sourceSiteUrl = ($migrationArray['sourceSiteUrl']) ??  '';
		//bd([$this, $dummy], 'this, dummy');
		$this->sourceSiteUrl = ($migrationArray['sourceSiteUrl']) ??  '';
		//bd($items, 'items after replaceAdminPath');

		$pagesInstalled = [];
		foreach($items as $name => $data) {
			//bd([$name, $data], 'item in install pages');
			if(!$data) continue;
			// Don't want items which are selectors (these may be present if the selector yielded no pages)
			if(!$this->wire()->sanitizer->path($name)) {
				//bd([$name, $data], 'name, data being passed as not a path');
				continue;
			}
			//bd([$name, $data], 'name, data to install');
			$p = $this->wire('pages')->get($name);
			//$this->wire()->log->save('debug', 'Installing page ' . $name);
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
				$this->wire()->session->warning(sprintf($this->_('Missing parent or template for page "%s". Page not created/saved in this installation attempt (may be achieved later).'), $name));
				continue;
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
				//bd($p, 'page to update');
				$p = $this->updatePage($p, $name, $data, $fields, $repeaters, $newOld);
			} else {
				//bd($name, 'new page');
				$p = $this->newPage($name, $data, $fields, $repeaters, $newOld);
			}
			///////
			if($origId && $p && $p->id ) $p->meta('origId', $origId); // Save the id of the originating page for matching purposes
			$p->of(false);
			$p->save();
			//bd($p, 'saved page at end of install');
			$pagesInstalled[] = $p;
		}
		return $pagesInstalled;
	}

	/**
	 * Replace admin paths in $items with target admin path if different from source
	 *
	 * @param $items
	 * @param $sourceAdmin
	 * @param $targetAdmin
	 * @return array
	 */
	public function replaceAdminPath($items, $sourceAdmin, $targetAdmin) {
		$newItems = [];
		foreach($items as $name => $data) {
			$name = str_replace($sourceAdmin, $targetAdmin, $name);
			if(is_array($data)) {
				$newItems[$name] = $this->replaceAdminPath($data, $sourceAdmin, $targetAdmin);
			} else {
				$newItems[$name] = str_replace($sourceAdmin, $targetAdmin, $data);
			}
		}
		return $newItems;
	}


	/**
	 * Save the updated fields for a page
	 *
	 *
	 * @param $p
	 * @param $name
	 * @param $data
	 * @param $fields
	 * @param $repeaters
	 * @param $newOld
	 * @return mixed
	 * @throws WireException
	 */
	public function updatePage($p, $name, $data, $fields, $repeaters, $newOld) {
		//$this->wire()->log->save('debug', 'Updating page ' . $p->name);
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
		$this->setAndSaveRepeaters($repeaters, $newOld, $p, ['noHooks' => true]);
		$this->wire()->session->message($this->_('Set and saved page ') . $name);
		return $p;
	}

	public function newPage($name, $data, $fields, $repeaters, $newOld) {
		//$this->wire()->log->save('debug', 'New page ' . $data['name']);
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
		$this->setAndSaveRepeaters($repeaters, $newOld, $p, ['noHooks' => true]);
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
		//bd($values, 'values before removing repeaters');
		foreach($values as $fieldName => $data) {
			$f = $this->wire('fields')->get($fieldName);
			//bd($f, "field for $fieldName");
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
						//bd([$fieldName, $fieldValue], 'fieldName, fieldValue of PageTable in setAndSaveComplex');
						foreach($fieldValue['items'] as $item) {
							//bd($item, 'item in PageTable');
							$p = $this->wire()->pages->get($item['parent'] . $item['name'] . '/');
							if($p and $p->id) {
								$pa->add($p);
							} else {
								$this->warning(sprintf($this->_('Page %1$s: PageTable item %2$s does not exist in target environment'), $page->path, $item['parent'] . $item['name'] . '/'));
							}
						}
						//bd($page, 'page');
						//bd($p, 'p');
						//bd($fieldName, 'fieldName');
						if($page->$fieldName) {
							$page->$fieldName->add($pa);
						} else {
							$page->$fieldName = $pa;
						}
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
					$page->of(false);
					$page->save($fieldName, ['noHooks' => true]);
					// NB using 'noHooks' => true to prevent spurious errors from hooks, particularly in the case of FieldtypePage when ConnectPageFields is installed
					// see https://processwire.com/talk/topic/14689-connect-page-fields/?do=findComment&comment=240586
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
				$existingItems = ($page->$f) ? $page->$f->getArray() : [];
				//bd($existingItems, '$existingItems');
				//bd($fieldValue, 'proposed value');
				$existingItemBasenames = array_filter($existingItems, function($v) {
					return basename($v->url);
				});
				//bd($existingItemBasenames, '$existingItemBasenames');
				//$this->wire()->log->save('debug', json_encode($existingItemBasenames));
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
				$page->save($fieldName, ['noHooks' => true]);
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
	public function setAndSaveRepeaters(array $repeaters, $newOld, $page = null, $options = []) {

		if(!$page) $page = $this;
		// nope/actually not necessary
		//$page->of(false);
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
					if(isset($subDetails['attrib'])) $subFieldArray = array_merge($subFieldArray, $subDetails['attrib']);
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


//				//bd($subPageArray, 'Array from existing subpages of motif_image_component before array_filter');
//				//bd($repeaterData, 'Array of subpages to be set before array_filter');


			// remove null (not 'empty') elements which might otherwise cause a spurious mismatch
			/* NB This previously used array_filter with no callback, but this removed empty text and 0 values, which are valid and distinct
			* (e.g for a toggle field, empty can be no selection and 0 can be a third option)
			 */
			array_walk($repeaterData, function(&$datum, $k) {
				$datum = array_filter($datum, function ($var) {
					return !is_null($var);
				});
			});
			array_walk($subPageArray, function(&$item, $k) {
				$item = array_filter($item, function ($var) {
					return !is_null($var);
				});
			});



			/*
			* $subPageArray should now be a comparable format to $repeaterData
			*/

//				//bd($subPageArray, 'Array from existing subpages of motif_image_component after array_filter');
//				//bd($repeaterData, 'Array of subpages to be set after array_filter');

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
							$subPage->of(false);
							$subPage->save(null, $options);
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
				$page->save($repeaterName, $options);  // ToDo Is this necessary?
				foreach($repeaterData as $j => $item) {
					//bd($item, 'data for new subpage');
//					$dataField = $this->wire('fields')->get($repeaterName);
//					$page->of(false);
					$repeaterField = $this->wire('fields')->get($repeaterName);
					if($repeaterField->type == 'FieldtypeRepeaterMatrix') {
						if(!($repeaterField instanceof RepeaterMatrixField)) {
							if(wire()->modules->isInstalled('FieldtypeRepeaterMatrix')) {
								// Make sure that RepeaterMatrixField class  exists
								if(!wireClassExists('ProcessWire\RepeaterMatrixField')) {
									require_once(wire()->config->paths->siteModules . 'FieldtypeRepeaterMatrix/RepeaterMatrixField.php');
								}
								$repeaterField = ProcessDbMigrate::cast($repeaterField, 'ProcessWire\RepeaterMatrixField');
								$repeaterField->save(null, $options);
							} else {
								wire()->session->error(__("Attempting to install a RepeaterMatrix field but FieldtypeRepeaterMatrix module is not installed"));
							}
						}
						if(!$page->$repeaterName) $page->$repeaterName = new RepeaterMatrixPageArray($page, $repeaterField);
						$newSubPage = $page->$repeaterName->getNewItem();
						$typeAttr = 'matrix' . $item[FieldtypeRepeater::templateNamePrefix . 'matrix_type'] . '_name';
						//bd($typeAttr, 'typeattr');
						$matrixType = $repeaterField->$typeAttr;
						//bd($matrixType, 'matrixtype');
						$newSubPage->setForField($repeaterField); //NB Need to make sure the getForField is a RepeaterMatrixField object, not just a plain Field (set in cast() method above)
						$newSubPage->setMatrixType($matrixType); // this will fail if the getForField is just a plain field as the getMatrixTypes() method will not be available
						unset($item[FieldtypeRepeater::templateNamePrefix . 'matrix_type']);
						if(isset($item['depth'])) {
							$newSubPage->setDepth($item['depth']);
							unset($item['depth']);
						}
//					$newSubPage->save(null, $options);
					} else {
						//bd($page->fields, 'allowed fields');
						if(!($repeaterField instanceof RepeaterField)) {
							$repeaterField = ProcessDbMigrate::cast($repeaterField, 'ProcessWire\RepeaterField');
							$repeaterField->save(null, $options);
						}
						if(!$page->$repeaterName) $page->$repeaterName = new RepeaterPageArray($page, $repeaterField);
						$newSubPage = $page->$repeaterName->getNew();
						if(isset($item['depth'])) {
							$newSubPage->setDepth($item['depth']);
							unset($item['depth']);
						}
					}
					$newSubPage->sort = $j;
//				$newSubPage->setAndSave($item, $options);

					// NB Rather than attempt to set the page fields here, use a recursive call
					//bd($newSubPage, 'SAVE newsubpage');
					$newSubPage->save(null, $options); // Make sure the new subpage is in the database before we attempt to update it
					$r = $this->getRepeaters($item);
					$subRepeaters = $r['repeaters'];
					$fields = $r['values'];
					$item['parent'] = $newSubPage->parent;
					$item['template'] = $newSubPage->template;
					$item['status'] = $newSubPage->status;
					$newSubPage = $this->updatePage($newSubPage, $newSubPage->name, $item, $fields, $subRepeaters, $newOld);

					$newSubPage->sort = $j;  // for sorting when all done
					//bd($newSubPage, 'SAVE newsubpage2');
					$newSubPage->save(null, $options);
					//bd($newSubPage, 'added new subpage');
					//bd($page, 'saved page after new sub page');
				}
				//bd($page, 'SAVE page2');
				$page->save(null, $options);
				/*
				 * NB End of replacement pages
				 */
			}

			$page->$repeaterName->sort('sort');
		}
		//$page->of(false);
		$page->save(null, $options);
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
						////bd([trim($this->adminPath, '/'), trim(ProcessDbMigrate::MIGRATION_PARENT, '/')], 'comparators');
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
								try {
									$p->trash(); // trash before deleting in case any hooks need to operate
								} catch(WireException $e) {
									$this->wire()->session->warning('Page ' . $p->name . ':  This page could not be trashed, but will be deleted.');
								}
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
								$this->wire()->session->error('Page ' . $p->name . ': ' . $e->getMessage()); // for any error types other than numChildren
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
			//bd($page, 'page in getidmap');
			//bd([debug_backtrace(), DEBUG::backtrace()], 'backtrace');
			if($page && $page->id && $page->meta('origId')) $idMapArray[$page->meta('origId')] = $page->id;  // $page->id is the new id (in the target)
		}
		$prevMap = ($this->meta('idMap')) ?: [];
		$this->meta('idMap', $prevMap + $idMapArray);  // Can't use array_merge as the keys will be renumbered
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
		if(!$this->id) return false;

		//$this->of(false);

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
		$dbName = $this->dbM->dbName();
		//bd([$filesHash, $this->meta('filesHash')], 'fileshash, meta fileshash');
		if(
			($this->meta('filesHash') && $this->meta('filesHash') == $filesHash) &&
			($this->meta('hostDb') && $this->meta('hostDb') == $dbName)
		) {
			//bd($this, 'skipping refresh as no changes');
			return true;
		} else {
			//bd($this, 'refreshing as changes');
			$this->meta()->set('filesHash', $filesHash);
			$this->meta()->set('hostDb', $dbName);
			//bd([$filesHash, $this->meta('filesHash')], 'fileshash, meta fileshash 2');
			$this->exportData('compare'); // sets meta('installedStatus')
			//bd([$filesHash, $this->meta('filesHash')], 'fileshash, meta fileshash 3');
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
			if(!is_array($content)) continue;
			foreach($content as $line) {
				foreach($line as $pathName => $values) {
					//bd($values, 'values');
					$pageName = $values['name'];
					if($this->name != $pageName) $this->wire()->session->warning($this->_('Page name in migrations file is not the same as the host folder.'));
					$p = $this->migrations->get("name=$pageName, include=all");
					//bd($p, 'p');
					//bd($this, 'this');
					// !!! NECESSARY !!!
					$this->of(false);
					$p->of(false);
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
								if(!is_array($oldContent)) continue;
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

						if(file_exists($oldFile) and !$fileCompare and !$scopeChange) {
							$infoOnlyValues = array_filter($values, function($k) {
								return in_array($k, self::INFO_ONLY_FIELDS);
							}, ARRAY_FILTER_USE_KEY);
							//bd($infoOnlyValues, 'info only values');
							$this->setMigrationPageValues($p, $infoOnlyValues);
							return true;
						}   // only info fields (possibly) changed so no further action required.

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
					$this->setMigrationPageValues($p, $values);
					//bd($p, 'p after save');
				}
			}
		}
		return true;
	}

	protected function setMigrationPageValues($p, $values) {
		$r = $this->getRepeaters($values);
		//bd($r, 'return from getrepeaters');
		$repeaters = $r['repeaters'];
		$values = $r['values'];
		// set the ordinary values first
		if($p and $p->id and $p->meta() and $p->meta('installable')) {
			// nope!
			$p->of(false);
			$this->of(false);
			$p->meta('allowSave', true);  // to allow save
			//bd([$p, $values], 'page, values');
			// this is the issue!!! use save. not setAndSave which was incorrectly used
			$p->save($values, ['noHooks' => true, 'quiet' => true]);
			if(count($repeaters) > 0) $this->setAndSaveRepeaters($repeaters, 'new', $p, ['noHooks' => true, 'quiet' => true]);
			$p->meta()->remove('allowSave');  // reset
		} else {
			// this is the issue!!! use save. not setAndSave which was incorrectly used
			$p->save($values, ['noHooks' => true, 'quiet' => true]);
			if(count($repeaters) > 0) $this->setAndSaveRepeaters($repeaters, 'new', $p, ['noHooks' => true, 'quiet' => true]);
		}
	}

	/**
	 * Get a hash string for a file for comparison
	 *
	 * @param $path
	 * @return string|null
	 * @throws WireException
	 */
	public function filesHash($path = null) {
		//bd(DEBUG::backtrace(), 'backtrace');
		$hashAlgo = (in_array('xxh128', hash_algos())) ? 'xxh128' : ((in_array('md4', hash_algos())) ? 'md4' : hash_algos()[0]);
		if(!$path) $path = $this->wire()->config->paths->templates . ProcessDbMigrate::MIGRATION_PATH;
		//bd($path, 'fileshash path');
		$fileArray = $this->wire()->files->find($path . $this->name . '/');
		$fileArray = array_filter($fileArray, function($item) {return !str_contains($item, 'Zone.Identifier');});
		//bd($fileArray, 'fileArray');
		$hashString = '';
		foreach($fileArray as $file) {
			$hashString .= hash_file($hashAlgo, $file);
			//bd([$file, $hashString], 'file, hash');
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
		//$items = $this->getFormatted('dbMigrateItem'); // getFormatted to get only published items
		$items = $this->dbMigrateItem->find("status=1"); // get only published items (avoid potential orphans)
		foreach($items as $item) {
			if($item->isUnpublished) continue; // @todo: still necessary?
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
		//$items = $this->getFormatted('dbMigrateItem');
		$items = $this->dbMigrateItem->find("status=1");
		//bd($items, 'items in dependency sort');
		$items->unique();
		//bd($items, 'unique items in dependency sort');
		$matrix = $this->createDependencyMatrix($items); // also sets temporary field 'mysort' to each item in items
		//bd($items, 'items in dependency sort after creating matrix');
		//bd($matrix, 'matrix before topological sort');
		$sorted = $this->topologicalSort($matrix, $items);
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
		//bd(['item' => $migrationItem, 'name' => $migrationItem->dbMigrateName, 'sourceData' => $migrationItem->meta('sourceData')], 'item in getDependencies');
		$sourceData = $migrationItem->meta('sourceData');
		//$items = $this->getFormatted('dbMigrateItem');
		$items = $this->dbMigrateItem->find("status=1");
		//bd($items, 'items in getDependencies');
		switch($migrationItem->dbMigrateType->id) {
			case 1: // field
				$templateItem = null;
				$parentItem = null;
				$dependentTypes = ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix', 'FieldtypePage','FieldtypePageTable'];
				$dependent = ($sourceData && isset($sourceData['type']) && in_array($sourceData['type'], $dependentTypes));
				$field = ($item) ?: $this->wire()->fields->get("name={$migrationItem->dbMigrateName}");
				if((!$sourceData || !isset($sourceData['type'])) && !$field) return [];
				if((!$sourceData || !isset($sourceData['type'])) && !$dependent) {
					$dependent = (in_array($field->type, ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix', 'FieldtypePage', 'FieldtypePageTable']));
				}
				if($dependent) {
					if($sourceData && isset($sourceData['template_id'])) {

						if(!is_array($sourceData['template_id'])) $sourceData['template_id'] = [$sourceData['template_id']];
						$templateItem = $this->findMigrationItemsByObjectId('template', $sourceData['template_id']);
//						$templateItem = ($templateItem) ? $templateItem->first() : null;
						//bd($templateItem, 'template item in getDependencies 1');
					}
					if(!$templateItem) {
						if(isset($field->template_id)) {
							$idString = (is_array($field->template_id)) ? implode('|', $field->template_id) : $field->template_id;
							$templates = $this->wire()->templates->find("id=$idString");
						} else {
							$templates = null;
						}
						//bd($field->template_id, 'template_id');
						if($templates) {
							$names = $templates->implode('|', 'name');
							$templateItem =  $items->find("dbMigrateType=2, dbMigrateName=$names");
							// (NB  Not selecting dbMigrateAction!=2 even though not interested if the template has only changed, as these will be excluded in setDependencyMatrixEntry()
						}
						$templateItem = ($templateItem) ? $templateItem->explode('id') : [];
//						$templateItem = ($templateItem) ? $templateItem->first()->id : null;
						//bd($templateItem, 'template item in getDependencies 2');
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
				$accessRolesArray = [];
				if($sourceData && isset($sourceData['accessRoles'])) {
					$accessRolesArray = $this->findMigrationItemsByObjectId('field', $sourceData['accessRoles'])->explode();
				}
				if(!$accessRolesArray && $field && $field->useRoles) {
					$accessRoles = array_merge($field->editRoles, $field->viewRoles);
					foreach($accessRoles as $accessRole) {
						$role = $this->wire()->roles->get($accessRole);
						$accessRoleItem = ($role) ? $items->get("dbMigrateType=3, dbMigrateName={$role->name}") : null;
						if($accessRoleItem) {
							$accessRolesArray[] = $accessRoleItem->id;
						}
					}
				}

				if(!is_array($templateItem)) $templateItem = [$templateItem];
				return (['template_item' => $templateItem, 'parent_item' => [$parentItem], 'access_roles' =>$accessRolesArray]);
				break;
			case 2: // template
				$fieldArray = [];
				$childTemplatesArray = [];
				$parentTemplatesArray = [];
				$accessRolesArray = [];
				$rolesPermissionsArray = [];
				$template = ($item) ?: $this->wire()->templates->get("name={$migrationItem->dbMigrateName}");
				if($sourceData && isset($sourceData['fields'])) {
					$fieldArray = $this->findMigrationItemsByObjectId('field', $sourceData['fields'])->explode();
				}
				if(!$fieldArray && $template) {
					$fields = $template->fieldgroup;
					foreach($fields as $field) {
						//bd($field, 'field');
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
				if($sourceData && isset($sourceData['accessRoles'])) {
					$accessRolesArray = $this->findMigrationItemsByObjectId('template', $sourceData['accessRoles'])->explode();
				}
				if(!$accessRolesArray && $template && $template->useRoles) {
					$accessRoles = array_merge($template->editRoles, $template->addRoles, $template->createRoles);
					$accessRoles = array_unique($accessRoles);
					foreach($accessRoles as $accessRole) {
						$role = $this->wire()->roles->get($accessRole);
						$accessRoleItem = ($role) ? $items->get("dbMigrateType=3, dbMigrateName={$role->name}") : null;
						if($accessRoleItem) {
							$accessRolesArray[] = $accessRoleItem->id;
						}
					}
				}
				if($sourceData && isset($sourceData['rolesPermissions'])) {
					$rolesPermissionsArray = $this->findMigrationItemsByObjectId('template', $sourceData['rolesPermissions'])->explode();
				}
				if(!$rolesPermissionsArray && $template && $template->useRoles) {
					$rolesPermissions= $template->rolesPermissions;
					foreach($rolesPermissions as $roleId => $permissionId) {
						$role = $this->wire()->roles->get($roleId);
						$roleItem = ($role) ? $items->get("dbMigrateType=3, dbMigrateName={$role->name}") : null;
						if($roleItem) {
							$rolesPermissionsArray[] = $roleItem->id;
						}
						$permission = $this->wire()->permissions->get($permissionId);
						$permissionsItem = ($permission) ? $items->get("dbMigrateType=3, dbMigrateName={$permission->name}") : null;
						if($permissionsItem) {
							$rolesPermissionsArray[] = $permissionsItem->id;
						}
					}
				}
				return ['fields' => $fieldArray, 'childTemplates' => $childTemplatesArray, 'parentTemplates' => $parentTemplatesArray, 'accessRoles' => $accessRolesArray];
				break;
			case 3: // page
				$pageArray = [];
				$pageArray2 = [];
				$parentItem = [];
				$templateItem = [];
				$pagePath = $migrationItem->dbMigrateName;
				//bd($pagePath, 'pagepath');
				$page = ($item) ?: $this->wire()->pages->get("path={$pagePath}, include=all");
				//bd($page, 'page in getdependencies for page');
				if($sourceData && isset($sourceData['parent_id'])) {
					$parentItem = $this->findMigrationItemsByObjectId('page', [$sourceData['parent_id']]);
//					$parentItem = ($parentItem) ? $parentItem->first() : null;
				}
				if(!$parentItem && $page) {
					$parentItem = ($page && $page->id) ? $this->findMigrationItemsByObjectId('page', $page->parent()->id) : [];
//					$parentItem = ($parentItem) ? $parentItem->first() : null;
				}

				if($sourceData && isset($sourceData['template_id'])) {
					$templateItem = $this->findMigrationItemsByObjectId('template', [$sourceData['template_id']]);
//					$templateItem = ($templateItem) ? $templateItem->first() : null;
				}
				if(!$templateItem && $page) {
					$templateItem = ($page && $page->id) ? $this->findMigrationItemsByObjectId('template', $page->template->id) : [];
//					$templateItem = ($templateItem) ? $templateItem->first() : null;
				}

				if($sourceData && isset($sourceData['pageRefs'])) {
					$pageArray = $this->findMigrationItemsByObjectId('page', $sourceData['pageRefs'])->explode();
					//bd($pageArray, 'page array A');
				}
				if(!$pageArray && $page) {
					$fields = $page->getFields();
					//bd($fields, 'fields in getdependencies for page');
					foreach($fields as $field) {
						if(in_array($field->type, ['FieldtypePage', 'FieldtypePageTable'])) {
							$pageRefs = $page->$field;
							//bd($pageRefs, 'pageRefs');
							if(!($pageRefs instanceof PageArray)) $pageRefs = [$pageRefs];
							foreach($pageRefs as $pageRef) {
								if($pageRef) {
									$pageItem = $items->get("dbMigrateType=3, dbMigrateName={$pageRef->name}"); // Only interested in new or removed items, but others are excluded in the matrix build
									if($pageItem) {
										// NB this only records dependencies if the other page is in a migration item. Should it be restrictive like this?
										$pageArray[] = $pageItem->id;
									}
								}
							}
						}
					}
				}

				if($sourceData && isset($sourceData['rteLinks'])) {
					//bd($sourceData['rteLinks'], 'sourcedata - rtelinks');
					$pageArray2 = $this->findMigrationItemsByObjectId('page', $sourceData['rteLinks'])->explode();
				}
				if(!$pageArray2 && $page) {
					$dbM = $this->wire('modules')-> get('ProcessDbMigrate');
					$imageSources = $dbM->findRteImageSources($page); // page array
					$otherLinks = $dbM->findRteLinks($page);
					$pageRefs2 = $imageSources->add($otherLinks)->explode('id');
					foreach($pageRefs2 as $pageRef) {
						//bd($pageRef, ' page ref in rte dependencies');
						$pageObject = ($pageRef) ? $this->wire->pages->get("id=$pageRef") : null;
						//bd($pageObject, ' page object in rte dependencies');
						if($pageObject) {
							$pageItem = $items->get("dbMigrateType=3, dbMigrateName={$pageObject->name}");
							if($pageItem) {
								//bd($pageItem, ' page item in rte dependencies');
								// NB this only records dependencies if the other page is in a migration item. Should it be restrictive like this?
								$pageArray2[] = $pageItem->id;
							}
						}
					}
				}

				/*
				 * Code below was removed as exclusion now takes place in the sort
				 */
//				// Convert wireArrays to plain arrays
//				if(wireInstanceOf($templateItem, WireArray())) $templateItem = $templateItem->explode();
//				if(wireInstanceOf($parentItem, WireArray())) $parentItem = $parentItem->explode();
//				/*
//				 * Remove any dependencies where the related item has only changed (not new or removed)
//				 */
//				$result = ['template_item' => $templateItem, 'parent_item' => $parentItem, 'pageRefs' => $pageArray, 'rteLinks' => $pageArray2];
//				foreach($result as $element => $resultArray) {
//					foreach($resultArray as $pageMigrationId) {
//						$changeOnly = $items->get("id=$pageMigrationId, dbMigrateAction=2");
//						if($changeOnly && $changeOnly->id) {
//							$key = array_search($pageMigrationId, $resultArray);
//							if($key !== null) unset($resultArray[$key]);
//							$result[$element] = $resultArray;
//						}
//					}
//				}

				//bd(['template_item' => [$templateItem], 'parent_item' => [$parentItem], 'pageRefs' => $pageArray, 'rteLinks' => $pageArray2], 'return from getDependencies');
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
		//$items = $this->getFormatted('dbMigrateItem');
		$items = $this->dbMigrateItem->find("status=1");
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
					//bd($object, 'object in findMigrationItemsByObjectId');
					$name = ($types == 'pages') ? 'path' : 'name';
					//bd([$item->dbMigrateType->value, $item->dbMigrateName], 'item type and name');
					if($item->dbMigrateType->value == $types && $item->dbMigrateName == $object->$name) {
						$itemArray->add($item->id);
					}
				}

			}
		}
		//bd(['type' => $objectType, 'idArray' => $idArray, 'itemArray' => $itemArray], 'findMigrationItemsByObjectId');
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
		//bd($items, 'items before sort');
		$items->sort('mysort');
		//bd($items, 'items after sort');
		$size = $items->count();
		// Create a zero-filled matrix [size x size]
		$matrix = array_fill(0, $size, array_fill(0, $size, 0));
		//bd($matrix, 'matrix zeroed');
		$i = 0;
		foreach($items as $item) {
			if($item->isUnpublished) continue;
			//bd([$item->dbMigrateType->value, $item->dbMigrateName], 'item type & name');
			$sourceData = $item->meta('sourceData');
			//bd($sourceData, 'sourceData');
			switch($item->dbMigrateType->id) {
				case 1 : // Field
					// Repeaters are dependent on templates and page ref fields may be dependent on templates or pages ('parent' for selection)
					$dependencies = $this->getDependencies($item);

					foreach(['template_item', 'parent_item', 'access_roles'] as $dependencyType) {
						if(isset($dependencies[$dependencyType])) foreach($dependencies[$dependencyType] as $dependency) {
							if($dependency && is_int($dependency)) {
								//bd($dependency, "setting $dependencyType item for field");
								$this->setDependencyMatrixEntry($matrix, $items, $dependency, $i);
							}
						}
					}

					break;
				case 2 : // Template
					$dependencies = $this->getDependencies($item);
					//bd([$item->dbMigrateName, $dependencies], 'template item and dependencies');
					foreach(['fields', 'childTemplates', 'parentTemplates', 'accessRoles'] as $dependencyType) {
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
					//bd(['migration item name' => $item->dbMigrateName, 'migration item id' => $item->id, 'dependencies' => $dependencies], 'got dependencies for page');
					foreach(['template_item', 'parent_item', 'pageRefs'] as $dependencyType) {
						if(isset($dependencies[$dependencyType]) && $dependencies[$dependencyType]) {
							foreach($dependencies[$dependencyType] as $dependency) {
								if($dependency && is_int($dependency)) {
									//bd($dependency, "setting $dependencyType item for page");
									$this->setDependencyMatrixEntry($matrix, $items, $dependency, $i);
								}
							}
						}
						//bd($matrix, 'matrix for ' . $dependencyType);
					}
					//bd($matrix, 'final matrix for ' . $item->dbMigrateName);
//
//					$templateItem = (isset($dependencies['template_item'])) ? $dependencies['template_item'] : null;
//					if($templateItem && is_int($templateItem)) {
////bd($templateItem, 'setting template item for page');
//						$this->setDependencyMatrixEntry($matrix, $items, $templateItem, $i);
//					}
//					$parentItem = (isset($dependencies['parent_item'])) ? $dependencies['parent_item'] : null;
//					if($parentItem && is_int($parentItem)) {
////bd($parentItem, 'setting parent item for page');
//						$this->setDependencyMatrixEntry($matrix, $items, $parentItem, $i);
//					}
//					if(isset($dependencies['pageRefs'])) foreach($dependencies['pageRefs'] as $dependency) {
//						if($dependency && is_int($dependency)) {
////bd($dependency, 'setting pageref item');
//							$this->setDependencyMatrixEntry($matrix, $items, $dependency, $i);
//						}
//					}
					/*
					 * I think there is no need to create a matrix entry for rte dependencies
					 * The rte links will only be invoked when the related page is opened, not when migration actions are performed
					 * By the time the page is opened, the rest of the migration should be complete and the links will work
					 * The following code hs therefore been commented out (but left here in case need in the future)
					 *
					if(isset($dependencies['rteLinks'])) foreach($dependencies['rteLinks'] as $dependency) {
						if($dependency && is_int($dependency)) {
//bd($dependency, 'setting rteLink item');
							$this->setDependencyMatrixEntry($matrix, $items, $dependency, $i);
						}
					}
					*/
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
			//bd($matrix, 'matrix in loop');
			$i++;
		}
		//bd($matrix, 'matrix after loop');
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
		//bd($relatedItem, 'relatedItem');
		if(!$relatedItem) return;
		$j = $relatedItem->mysort;
		if($relatedItem->dbMigrateAction->value == 'new') { //} || $relatedItem->dbMigrateAction->value == 'changed') { NB no dependency if item is just changed
			// $i is dependent on $j
			$matrix[$j][$i] = 1;
		} else if($relatedItem->dbMigrateAction->value == 'removed') {
			// dependency is reversed for removals
			$matrix[$i][$j] = 1;
		}
		//bd($matrix[$j][$i], "dependency of $i on $j");
		//if(($j == 2 && $i == 3) or ($j ==  3 && $i == 2) ) bd(debug_backtrace());
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
	public function topologicalSort(array $matrix, $items, $try = 0): \SplQueue {
		$origMatrix = $matrix;
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
			if($try) {
				//bd($origMatrix, 'Matrix after failing at second attempt');
				return new \SplQueue;
			} // already tried once
			//bd($origMatrix, 'matrix before findcycles');
			$cycles = $this->findCycles($origMatrix, $items);
			$cycleMessages = $cycles['msg'];
			$negativeMatrix = $cycles['negativeMatrix'];
			//bd($negativeMatrix, 'negativeMatrix');
			//bd($cycleMessages, 'cycles');
			$this->error($this->_("($this->title) Cannot resolve sort - migration items have cyclical dependencies. See messages for details.\n
			The migration may still be installable but may need more than one attempt to install."));
			$this->message($this->_("($this->title) Cannot resolve sort - migration items have cyclical dependencies as follows:\n * " . $cycleMessages .
				"\n(a-->b means a is dependent on b. F, T & P denote if the item is a field, template or page)"));
			//Subtract the negative matrix from the original matrix to remove the cycles
			for($i = 0; $i < $size; $i++) {
				for($j = 0; $j < $size; $j++) {
					$matrix[$i][$j] -= $negativeMatrix[$i][$j];
				}
			}
			//bd($matrix, 'matrix after removing cycles');
			$order = $this->topologicalSort($matrix, $items, 1);
		}
		//bd($order, 'order');
		return $order;
	}

	/********************************************
	****** Find the cycles for error msg *******/

	/**
	 * Use Depth-First-Search method to find cycles
	 * See https://www.baeldung.com/cs/detecting-cycles-in-directed-graph
	 * The first thing is to make sure that we repeat the DFS from each unvisited vertex in the graph
	 *
	 * @param $matrix the dependency matrix
	 * @param $items the migration items
	 * @return array text description of cycles and negative matrix to remove them
	 */
	protected function findCycles($matrix, $items) {
		$size = count($matrix);
		//bd($matrix, 'matrix in findCycle');
		// Create a filled array
		$visited = array_fill(0, $size, 'not_visited');
		$detectedCycles = [];
		foreach($matrix as $vertex => $edgeArray) {
			if($visited[$vertex] == 'not_visited') {
				$stack = [];
				array_push($stack, $vertex);
				$visited[$vertex] = 'in_stack';
				//bd($stack, 'stack 1');
				$this->processDFSTree($matrix, $stack, $visited, $detectedCycles);
			}
		}
		//bd($detectedCycles, 'detectedCycles in findCycle');
		$msg = [];
		// Create a zero-filled matrix [size x size] to represent the negative of the dependency matrix entries which have caused cycles
		$negativeMatrix = array_fill(0, $size, array_fill(0, $size, 0));
		foreach($detectedCycles as $cycle) {
			//Place a 1 in the negative matrix for the pair (a,b) where a is the last value in the cycle and b is the first value in the cycle
			//bd($cycle, 'cycle in findCycle');
			$negativeMatrix[end($cycle)][reset($cycle)] = 1;
			//bd($negativeMatrix, 'negativeMatrix in findCycle');
			$textCycle = [];
			foreach($cycle as $key => $itemNumber) {
				$item = $items->get("mysort=$itemNumber");
				$itemName = $item->dbMigrateName;
				$itemType = $item->dbMigrateType;
				$itemText = $itemName . '(' . $itemType->title[0] . ')';
				$textCycle[] = $itemText;
				if($key == 0) $firstItem = $itemText;
			}
			$textCycle[] = $firstItem; // so as to show a complete loop
			$textCycle = implode('-->', $textCycle);
			$msg[] = $textCycle;
		}
		$msg = implode("\n * ", $msg);
		return ['msg' => $msg, 'negativeMatrix' => $negativeMatrix];

	}

	/**
	 * The second part is the DFS processing itself (a recursive function).
	 * In this part, we need to make sure we have access to what is in the stack of the DFS to be able to check for the back edges.
	 * And whenever we find a vertex that is connected to some vertex in the stack, we know we’ve found a cycle (or multiple cycles)
	 *
	 * @param $matrix
	 * @param $stack
	 * @param $visited
	 * @param $detectedCycles
	 * @return void return is in parameters
	 */
	protected function processDFSTree($matrix, &$stack, &$visited, &$detectedCycles) {
		$stackTop = end($stack);
		//bd([$stackTop, $matrix[$stackTop]], 'stacktop, matrix[stacktop]');
		if($stackTop !== false) {
			foreach($matrix[$stackTop] as $vertex => $edge) {
				if($edge) {
					//bd('got edge');
					//bd([$vertex, $edge], 'vertex, edge');
					if($visited[$vertex] == 'in_stack') {
						$this->printCycle($stack, $vertex, $detectedCycles);
					} else if($visited[$vertex] == 'not_visited') {
						array_push($stack, $vertex);
						$visited[$vertex] = 'in_stack';
						//bd($stack, 'stack 2');
						$this->processDFSTree($matrix, $stack, $visited, $detectedCycles);
						//bd($stack, 'stack 2a');
					}
				}
			}
			$visited[$stackTop] = 'not_visited';
			array_pop($stack);
		}
	}

	/**
	 * @param $stack
	 * @param $vertex
	 * @param $detectedCycles
	 * @return void return is in parameters
	 */
	protected function printCycle($stack, $vertex, &$detectedCycles) {  // NB $stack is not returned by reference
		$stackTop = end($stack);
		$cycle = [];
		array_push($cycle, $stackTop);
		array_pop($stack);
		$count = 0;
		//bd([$cycle, $vertex], 'cycle, vertex - check');
			while(end($cycle) != $vertex) {
				array_push($cycle, end($stack));
				array_pop($stack);
				$count++;
				if($count > count($stack)) break; // circuit breaker in case of problem or excess
			}
			$newCycle = (!in_array($cycle, $detectedCycles) && (count(array_unique($cycle)) != 2 || !in_array(array_reverse($cycle), $detectedCycles)));
			// Last element is to avoid double counting mutually dependent pairs of items
		if($newCycle && count(array_unique($cycle)) !== 1) {
			// NB the 2nd term above is because sometimes a 'cycle' can be created with 2 elements, each false
			array_push($detectedCycles, $cycle);
			//bd($cycle, 'cycle added');
		}
		//bd($detectedCycles, 'detectedCycles in printCycle');
	}

	///////////////////////////////////////////////////////


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
		if($p->dbMigrateLogChanges == 1 && !$p->dbMigrateFieldTracking && !$p->dbMigrateTemplateTracking && !$p->dbMigratePageTracking) {
			$this->error("$p->name - " . $this->_("You have chosen to log changes but not selected any scope.\n
			Please de-select 'log changes' or enter scope(s) before saving this page."));
			$event->return;
		}
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
		//foreach($this->getFormatted('dbMigrateItem') as $item) { // getFormatted to get only published items
		foreach($this->dbMigrateItem->find("status=1") as $item) {
			/* @var $item RepeaterDbMigrateItemPage */
			$k++;
			if(!$item->dbMigrateType or !$item->dbMigrateAction or !$item->dbMigrateName) {
				$this->wire()->session->warning($this->_('Missing values for item ') . $k);
				//bd($item, 'missing values in item');
			}
		}
	}

	/**
	 * Before trash actions
	 * Check if migration has files and warn if so
	 * If draft migration, delete migration files
	 * If not draft migration and there are no migration files, remove 'installable' meta to allow deletion
	 *
	 * @param HookEvent $event
	 * @return void
	 * @throws WireException
	 */
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

	/**
	 * After trash actions
	 * Redirect to setup page if not deleting draft migration
	 *
	 * @param HookEvent $event
	 * @return void
	 * @throws WireException
	 */
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

	/**
	 * after FieldsetRender
	 * Add classes to selected migration row Repeater items
	 * To prettify the display of migration items on the migration page
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	protected function afterFieldsetRender(HookEvent $event) {
		/* @var $fieldset InputfieldFieldset */
		$fieldset = $event->object;
		$attr = $fieldset->wrapAttr();
		//bd([$fieldset, $attr], 'fieldset, wrapattr');
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