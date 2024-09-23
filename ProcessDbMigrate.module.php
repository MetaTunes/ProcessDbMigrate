<?php namespace ProcessWire;

/**
 * ProcessWire DbMigrate
 * by Mark Evens
 * with contributions from Jonathan Lahijani and tips and snippets from Adrian Jones, Bernhard Baumrock and Robin Sallis
 *
 * Class ProcessDbMigrate
 *
 * A module to manage migrations through the PW GUI
 *
 * @package ProcessWire
 *
 * INFO-DERIVED PROPERTIES
 * ==================
 * @property string $name
 * @property string $parent Path to parent
 * @property string $parentUrl Url to parent
 * @property string $parentHttpUrl Full HttpUrl to parent
 * @property string $title
 * @property string $adminProcess
 *
 * MODULE CONFIGURATION PROPERTIES
 * ===============================
 * @property string $enable_dbMigrate Enable the module (not checking this removes almost all the functionality and does not show page contents for migrations). Default is checked
 * @property string $help The help md
 * @property string $database_name User-assigned name of the current database
 * @property string $suppress_hooks Suppress hook-related functionality (efectively disable module to speed site)
 * @property boolean $show_name Show database name as message on all admin
 * @property string $exclude_fieldtypes Field types to always exclude from migrations
 * @property string $exclude_fieldnames Field names to always exclude from migrations
 * @property string $exclude_attributes Object attributes to always exclude from migrations
 * @property boolean $auto_install Disable auto-install of bootstrap on upgrade. Default is checked.
 * @property boolean $prevent_overlap Prevent page changes where the page is within the scope of an unlocked installable migration. Default is checked.
 * @property integer $install_repeats Number of times to repeat the installation process (if necessary). Default is 3.
 *
 * OTHER SETTING PROPERTIES
 * ================================
 * @property object $migrations The parent page for migration pages
 * @property object $comparisons The parent page for comparison pages
 * @property object $migrationTemplate The template for migration pages
 * @property object $comparisonTemplate The template for comparison pages
 * @property object $migrationsTemplate The template for the parent page
 * @property string $migrationsPath Path to the directory holding the migrations .json files
 * @property string $comparisonsPath Path to the directory holding the comparisons .json files
 * @property string $modulePath Path to this module
 * @property string $bootstrapPath Path to the directory holding the original bootstrap data (it is copied to migrationsPath on installation)
 * @property DbMigrationPage $bootstrap
 * @property string $adminPath Path to the admin root (page id = 2)
 * @property string $adminUrl Url to admin root
 * @property string $adminHttpUrl Full url to admin root
 * @property object $trackingMigration The migration page with 'log changes' set (if any) - should be only one such page as hooks prevent more than one (otherwise first used)
 * @property object $trackingField The object currently being tracked by 'log changes' (if a field)
 *
 *
 * TEMP PROPERTIES
 * ================
 * @property boolean $first
 *
 * HOOKABLE METHODS
 * =================
 * @method array|string execute() Display setup page
 * @method string executeDatabaseComparison() Display database comparison setup page
 * @method void executeGetComparisons() Refresh comparisons
 * @method void executeGetMigrations() Refresh comparisons
 * @method void exportData($migrationPage) Export json from migration definition or compare with json
 * @method void install($upgrade = false) Install or upgrade the module
 * @method void installMigration($migrationPage) Install the specified migration
 * @method void lockMigration($migrationPage, $migrationFolder) Lock the migration
 * @method void newPage($template, $parent, $title, $values) Create new migration or comparison page
 * @method string previewDiffs($migrationPage, $comparisonType, $button) Preview migration differences
 * @method void removeFiles($migrationPage, $oldOnly = false) Remove json files for migration
 * @method void uninstallMigration($migrationPage) Uninstall (roll back) the specified migration
 * @method void unlockMigration($migrationPage, $migrationFolder) Unlock the migration
 * @method void upgrade($fromVersion, $toVersion) Upgrade the module
 *
 */
class ProcessDbMigrate extends Process implements Module, ConfigurableModule {

	/**
	 * Although getModuleInfo has been replaced by the separate info file, we need access to the info in this module, hence this function
	 *
	 * @return mixed
	 */
	public static function moduleInfo() {
		require('ProcessDbMigrate.info.php');
		/* @var $info array */ //$info is defined in ProcessDbMigrate.info.php
		//$this->bd($info, 'module info');
		return $info;
	}

	const debug = false;

	/*
	 * Name of template for migration pages
	 *
	 */
	const MIGRATION_TEMPLATE = 'DbMigration';
	/*
	 * Name of template for comparison pages
	 *
	 */
	const COMPARISON_TEMPLATE = 'DbComparison';
	/*
	 * Name of template for parent to migration and comparison pages
	 *
	 */
	const MIGRATION_PARENT_TEMPLATE = 'DbMigrations';
	/*
	 * Name of parent page for migration pages
	 *
	 */
	const MIGRATION_PARENT = 'dbmigrations/';
	/*
	 * Name of parent page for comparison pages
	 *
	 */
	const COMPARISON_PARENT = 'dbcomparisons/';
	/*
	 * (Partial) path to migrations
	 *
	 */
	const MIGRATION_PATH = 'DbMigrate/migrations/';
	/*
	 * (Partial) path to comparisons
	 *
	 */
	const COMPARISON_PATH = 'DbMigrate/comparisons/';
	/*
	 * Prefix to use for migrations created from comparisons
	 *
	 */
	const XCPREFIX = 'xc-';
	/*
	 * The admin path of the source used to create the bootstrap json
	 *
	 */
	const SOURCE_ADMIN = '/processwire/';
	/*
	 * Field types to always ignore in migrations
	 *
	 */
	const EXCLUDE_TYPES = array('RuntimeMarkup', 'RuntimeOnly', 'DbMigrateRuntime', 'MotifRuntime');
	/*
	 * Field and template attributes to always ignore in migrations
	 *
	 */
	const EXCLUDE_ATTRIBUTES = array('_importMode', 'repeaterFields', '_lazy', '_exportMode');
	// 'parent_id', template_id' and 'template_ids' are handled specifically (replaced with 'parent_path', 'template_name' and 'template_names') so no need to exclude here

	/**
	 * Construct
	 * Set default values for configuration settings
	 */
	public function __construct() {
		parent::__construct();
		$this->set('auto_install', 1);
		$this->set('prevent_overlap', 1);
		$this->set('enable_dbMigrate', 1);
		$this->set('install_repeats', 3);
	}

	public static function dbMigrateFields() {
		return wire('fields')->find("tags=dbMigrate");
	}

	public static function dbMigrateTemplates() {
		return wire('templates')->find("tags=dbMigrate");
	}

	/**
	 * Get a selector for all templates with the dbMigrate tag
	 * @return string
	 */
	public static function dbMigrateTemplateSelector() {
		$dbmTemplates = implode('|', self::dbMigrateTemplates()->explode('name'));
		// return wire('pages')->find("template=$dbmTemplates");
		return "template={$dbmTemplates}";
	}

	/**
	 * Initialize the module
	 *
	 * ProcessWire calls this method when the module is loaded. At this stage, all
	 * module configuration values have been populated.
	 *
	 * For “autoload” modules (such as this one), this will be called before ProcessWire’s API is ready.
	 * This is a good place to attach hooks (as is the “ready” method).
	 *
	 */
	public function init() {
		parent::init();
		$this->bd('init from ProcessDbMigrate.php');
		require_once('DbMigrationPage.class.php');
		require_once('DbComparisonPage.class.php');
		
		// Set properties
		$this->set('adminPath', wire('pages')->get(2)->path());
		$this->set('adminUrl', wire('pages')->get(2)->url());
		$this->set('adminHttpUrl', wire('pages')->get(2)->httpUrl());
		$this->set('name', self::moduleInfo()['page']['name']);
		$this->set('parent', $this->adminPath . self::moduleInfo()['page']['parent'] . '/');
		$this->set('parentUrl', $this->adminUrl . self::moduleInfo()['page']['parent'] . '/');
		$this->set('parentHttpUrl', $this->adminHttpUrl . self::moduleInfo()['page']['parent'] . '/');
		$this->set('title', self::moduleInfo()['page']['title']);
		$this->set('adminProcess', str_replace(__NAMESPACE__ . '\\', '', get_class($this)));
		$this->set('migrations', wire('pages')->get($this->adminPath . self::MIGRATION_PARENT));
		$this->set('comparisons', wire('pages')->get($this->adminPath . self::COMPARISON_PARENT));
		$this->set('migrationTemplate', wire('templates')->get(self::MIGRATION_TEMPLATE));
		$this->set('comparisonTemplate', wire('templates')->get(self::COMPARISON_TEMPLATE));
		$this->set('migrationsTemplate', wire('templates')->get(self::MIGRATION_PARENT_TEMPLATE));
		$this->set('migrationsPath', wire('config')->paths->templates . self::MIGRATION_PATH);
		$this->set('comparisonsPath', wire('config')->paths->templates . self::COMPARISON_PATH);
		$this->set('modulePath', wire('config')->paths->siteModules . basename(__DIR__) . '/');
		$this->set('bootstrapPath', $this->modulePath . 'bootstrap');
		$this->set('bootstrap', wire()->pages->get("parent=$this->migrations, template=$this->migrationTemplate, name=bootstrap"));
		// Need custom uninstall to uninstall bootstrap before uninstalling the module
		$this->addHookBefore("Modules::uninstall", $this, "customUninstall");
		// trigger init in Page class as it is not auto
		if(class_exists('DbMigrationPage')) {
			$p = $this->wire(new DbMigrationPage());
			$p->init();
		}
		if(class_exists('DbComparisonPage')) {
			$p = $this->wire(new DbComparisonPage());
			$p->init();
		}

		/*
		 * The following hooks need to be here in init() as the hooked methods are called before ready()
		 */
		// Make sure any migration page is refreshed before loading it and set the 'updated' meta (used to indicate if refresh completed fully)
		$this->addHookBefore('ProcessPageEdit::loadPage', function(HookEvent $event) {
			$id = $event->arguments(0);
			$p = $this->pages->get($id);
			if($p and $p->id and ($p->template == self::MIGRATION_TEMPLATE or $p->template == self::COMPARISON_TEMPLATE)) {
				/* @var $p DbMigrationPage */
				$p->meta('updated', false);
				$this->wire('process', $this); // Sets the process to ProcessDbMigrate, rather than ProcessPageEdit, which can cause problems in the refresh
				$this->bd($this->wire()->process, 'process module refresh');
				$updated = $p->refresh();
				$this->bd($updated, 'meta updated');
				$p->meta('updated', $updated);
			}
		});


		/*
		 * To track which host page containing a Page Table field is currently being edited
		 * NB the Ajax hook needs to be in init() as it is called before ready()
		 */
		$this->addHookAfter('InputfieldPageTable::render', $this, 'handlePageTable');
		// The ajax hook needs to be 'before' as ajax exits without triggering the after hook?
		$this->addHookBefore('InputfieldPageTableAjax::checkAjax', $this, 'handlePageTable');
		$this->set('trackingMigration', $this->getTrackingMigration());


		$this->set('trackingField', null);

		// CONFIGURE HELP TEMPLATE
		$t = $this->templates->get('DbMigrateHelp');
		if(!$t) return;
		$moduleName = basename(__DIR__);
		$t->filename = $this->config->paths->$moduleName . 'DbMigrateHelp.php';
		$this->bd('ProcessDbMigrate INIT DONE');
	}

	/**
	 * Called when ProcessWire’s API is ready (optional)
	 *
	 * This optional method is similar to that of init() except that it is called
	 * after the current $page has been determined and the API is fully ready to use.
	 * Use this method instead of (or in addition to) the init() method if your
	 * initialization requires that the `$page` API variable is available.
	 *
	 * @throws WireException
	 *
	 */
	public function ready() {
		$this->bd('ready from ProcessDbMigrate.php');
		$currentUser = wire()->users->getCurrentUser();
		if(!$currentUser->hasRole(self::moduleInfo()['permission']) && !$currentUser->isSuperuser()) return;

		$this->bd("READY");
		Debug::startTimer('from ready');
		$val = Debug::timer('ready');
		$this->addHookAfter('ProcessPageEdit::buildFormContent', $this, 'afterBuildFormContent');
		if(!$this->enable_dbMigrate) return;
		if(!$this->suppress_hooks) { // Skip all the hooks below to speed up the site (set in config). Functionality is lost but is not required unless migrations are required.
//			// Code below is used in various hooks to detect changes to objects
			$this->wire()->session->set('processed', []); // clear the session var that stores processed item ids
			$this->wire()->session->set('processed_repeater', []); // clear the session var that stores processed repeater item ids
			$this->wire()->session->set('processed_fieldgroup', []);
$this->bd($this->wire()->session->get('processed'), 'processed in ready' );


			$this->wire()->setTrackChanges(Wire::trackChangesValues);
			$val = Debug::timer('ready');
$this->bd($val, 'ready timer 4');
			//
			$this->addHookBefore("Pages::save", $this, 'beforeSave');
			$this->addHookAfter("Pages::save", $this, 'afterSave');
			$this->addHookAfter("Fieldtype::exportConfigData", $this, 'afterExportConfigData');
			$this->addHookAfter('ProcessTemplate::buildEditForm', $this, 'afterTemplateBuildEditForm');
			$this->addHookAfter('ProcessField::buildEditForm', $this, 'afterFieldBuildEditForm');
			$this->addHookBefore('Templates::save', $this, 'beforeSaveTemplate');
			$this->addHookBefore('Fields::save', $this, 'beforeSaveField');

			// Hooks below are to handle field and template changes where 'log changes' has been enabled
			// The functions they call are grouped together and documented there

			// clear any old 'current' meta values
			if($this->trackingMigration && $this->trackingMigration->id) {
				foreach($this->trackingMigration->meta()->getArray() as $metaKey => $metaValue) {
$this->bd($metaKey, 'remove meta key?');
					if(strpos($metaKey, 'current') === 0) {
						$this->trackingMigration->meta()->remove($metaKey);
					}
				}
//			$this->addHookAfter('Fieldgroups::saveReady()', $this, 'afterSaveReadyFieldgroup'); // not necessary as Fieldgroups extends WireSaveableItems
				$this->addHookAfter('WireSaveableItems::saveReady', $this, 'hookMetaSaveable');
				$this->addHookAfter('WireSaveableItems::renameReady', $this, 'hookMetaSaveable');
				$this->addHookBefore('FieldtypeRepeater::deleteField', $this, 'setMetaDeleteRepeater');
				$this->addHookBefore('Fields::saveFieldgroupContext', $this, 'beforeFieldsSaveFieldgroupContext');
				$this->addHookAfter('WireSaveableItems::added', $this, 'handleSaveableHook');
				$this->addHookAfter('WireSaveableItems::saved', $this, 'handleSaveableHook');

				// ToDo move into a method
				$this->addHookBefore('Fieldgroups::save', function($event) {
					$object = $event->object;
					$migration = $this->trackingMigration;
					$fg = $event->arguments(0);
					$this->bd($fg, 'new fg');
					$fg1 = $this->wire('fieldgroups')->getFreshSaveableItem($fg);
					$this->bd($fg1, 'fg1 orig saveable');
					$tps = $fg->getTemplates();
					foreach($tps as $tp) {
						$this->bd($tp, 'tp new saveable');
						$tp1 = $this->wire('templates')->getFreshSaveableItem($tp);
						$tp1->setFieldgroup($fg1);
						$this->bd($tp1, 'tp1 orig saveable');
						if($tp1) {
							$this->getObjectData($event, $tp1);
						} else {
							$migration->meta()->set("current_{$object}_{$tp->id}", []);
						}
					}
				});

				$this->addHookAfter('WireSaveableItems::renamed', $this, 'handleSaveableHook');
				$this->addHookAfter('WireSaveableItems::deleteReady', $this, 'handleSaveableHook');
				$this->addHookAfter('Fieldgroups::fieldRemoved', $this, 'afterFieldRemoved');
				$this->addHookAfter('Fields::saveFieldgroupContext', $this, 'afterFieldsSaveFieldgroupContext');
				$this->addHookAfter('Fieldgroups::save', $this, 'afterFieldgroupsSave');
				// and these to handle page changes
				//(meta for current is set in beforeSave hook)
				$this->addHookAfter('Pages::added', $this, 'handleSaveableHook');
				$this->addHookAfter('Pages::saved', $this, 'handleSaveableHook');
				$this->addHookAfter('Pages::renamed', $this, 'handleSaveableHook');
				$this->addHookAfter('Pages::deleteReady', $this, 'handleSaveableHook');
				$this->addHookBefore('Pages::delete', $this, 'beforeDeletePage');
				// need a 'getFresh' for fields and templates to track changes fully
				$this->addHook('WireSaveableItems::getFreshSaveableItem', $this, 'getFreshSaveableItem');

			}
		}
		$val = Debug::timer('ready');
		$this->bd($val, 'ready timer 5');

		// This hook needs to run regardless
		$this->addHookBefore('ProcessPageEdit::execute', $this, 'beforePageEditExecute');
		$this->bd('ProcessDbMigrate: ALL HOOKS RUN');


		// Trigger ready() in the Page Class (actually trigger all page classes)
		$page = $this->wire()->page;
		if($page and $page->template == 'admin') {
			$pId = $this->wire()->input->get->int('id');
			$pId = $this->wire('sanitizer')->int($pId);
			$p = ($pId > 0) ? $this->wire('pages')->get($pId) : null;
		}
		if(isset($p) and $p and $p->id and method_exists($p, 'ready')) $p->ready();
		$val = Debug::timer('ready');
		$this->bd($val, 'ready timer 6');

		// Show the database name if selected in config, but only for admins with permission (and superuser)
		if($this->dbName() and $this->show_name and wire('user')->hasPermission('admin-dbMigrate')) {
			$this->wire()->message('DATABASE NAME = ' . $this->dbName());
		}

		// Load .js file and pass variables
		$this->wire()->config->scripts->add($this->wire()->urls->siteModules . 'ProcessDbMigrate/ProcessDbMigrate.js');
		$this->wire()->config->styles->add($this->wire()->urls->siteModules . 'ProcessDbMigrate/ProcessDbMigrate.css');
		$this->wire->config->js('ProcessDbMigrate', [
			'confirmDelete' => $this->_('Please confirm that this migration is not used in any other database before deleting it.
If it has been used in another environment and is no longer wanted then you will need to remove any orphan json files there manually.')
		]);
		$val = Debug::timer('ready');
		$this->bd($val, 'ready timer 7');
		/*
		* Install the bootstrap if it exists, is installable and is not installed
		* NB this cannot be done as part of install() as not all the required API is present until ready() is called
		*/
		if($this->auto_install) {
			$this->bd("auto_install running! it runs anytime we view the database migrations page!!!");
			$temp = $this->migrationTemplate;
			$bootstrap = $this->wire()->pages->get("template=$temp, name=bootstrap");
			/* @var $bootstrap DbMigrationPage */
			$this->bd($bootstrap, 'bootstrap');
			if($bootstrap && $bootstrap->id && $bootstrap->meta('installable') &&
				(!isset($bootstrap->meta('installedStatus')['installed']) || !$bootstrap->meta('installedStatus')['installed'])) {
//				if($this->session->get('upgraded')) {
//					try {
//						$bootstrap->installMigration('new');
//					} catch(WirePermissionException $e) {
//						$this->wire()->session->warning($this->_('You do not have permission'));
//					} catch(WireException $e) {
//						$this->wire()->session->warning($this->_('Unable to install bootstrap'));
//					}
//				} else {
				try {
					$this->wire()->session->warning($this->_('Bootstrap not fully installed - Attempting re-install.'));
					$bootstrap->of(false);
					$bootstrap->meta()->remove('filesHash');
					$this->bd($bootstrap,'removed hash');
					$bootstrap->refresh();
					$this->bd($bootstrap, 'refreshed');
					$bootstrap->installMigration('new');
					$this->bd($bootstrap, 'installed');
				} catch(WirePermissionException $e) {
					$this->wire()->session->warning($this->_('You do not have permission'));
				} catch(WireException $e) {
					$this->wire()->session->warning($this->_('Unable to refresh bootstrap'));
				}
//				}
//				$this->session->remove('upgraded');
			}
			if($this->session->get('upgrade0.1.0')) {
				// remove the old RuntimeOnly fields if upgrading to 0.1.0
				foreach(['dbMigrateActions', 'dbMigrateControl', 'dbMigrateReady'] as $name) {
					$object = $this->wire('fields')->get($name);
					if($object) {
						$n = $object->name;
						$object->flags = Field::flagSystemOverride;
						$object->flags = 0;
						$object->save();
						try {
							$this->wire('fields')->delete($object);
						} catch(WireException $e) {
							$this->wire()->session->error('Object: ' . $object . ': ' . $e->getMessage());
							$this->bd($n, 'ERROR IN DELETION - ' . $e->getMessage());
						}
					}
				}
				$this->session->remove('upgrade0.1.0');
			}
		}
		$val = Debug::timer('ready');
		$this->bd($val, 'ready timer 8');
//		/*
//		 * Make sure all the assets are there for a page with repeaters
//		 * MOVED to beforePageEditExecute to stop it executing on saves and causing corruption issues
//		 */
//		$page = $this->page();
//		if($page and $page->template == 'admin') {
//			$pId = $this->wire()->input->get('id');
//			$pId = $this->wire('sanitizer')->int($pId);
//			if(is_int($pId) && $pId > 0) {
//				$p = $this->wire('pages')->get($pId);
//
//				$this->getInputfieldAssets($p);
//			}
//		}
		$this->bd('ProcessDbMigrate: READY DONE');
	}


	/**
	 * Set the meta('current') for the specified object, where it is a field or template
	 *
	 * @param $event
	 * @param $obj
	 * @return void
	 * @throws WireException
	 */
	public function getObjectData($event, $obj) {
		// NB The code below only deals with template and field objects. Pages are handled separately in beforeSave.
		$migration = $this->trackingMigration;
		$val = Debug::timer('ready');
		$this->bd($val, 'ready timer 1');
		$type = $typeName = $tracking = null;
		$this->objectType($event, $obj, $type, $typeName, $tracking);
		$this->bd(['obj' => $obj, 'type' => $type, 'typeName' => $typeName, 'tracking' => $tracking], 'objectType returned');
		if(!$type) return;
		$object = ($typeName == 'fields') ? 'field' : (($typeName == 'templates') ? 'template' : null);
		if(!$object) return;
		$scopedObjects = $this->wire()->$typeName->find($migration->$tracking);
		$val = Debug::timer('ready');
		$this->bd($val, 'ready timer 2');
		if($migration && $migration->id && $scopedObjects->has($obj)) {
			// Ignore templates and fields which belong to DbMigrate itself
			if(wireInstanceOf($obj, 'Template') && self::dbMigrateTemplates()->has($obj)) return;
			if(wireInstanceOf($obj, 'Field') && self::dbMigrateFields()->has($obj)) return;
			$objectData = $this->getExportDataMod($obj);
			$this->bd($objectData, 'objectData set to meta current');
			if(!$migration->meta()->get("current_{$object}_{$obj->id}")) $migration->meta()->set("current_{$object}_{$obj->id}", $objectData);
			$migMeta = $migration->meta()->getArray();
			$val = Debug::timer('ready');
			$this->bd(['object' => $obj->name, 'migration meta' => $migMeta, 'timer' => $val], 'ready timer 3');
			//$this->wire()->log->save('debug', "timer 3 for {$obj->name} is $val");
		}
		$this->bd($migration->meta()->getArray(), 'meta for ' . $migration->name);
	}

	/**
	 * Add environment to the database name, as required
	 */
	public function dbName() {
		if($this->append_env) {
			return (isset($this->wire()->config->dbMigrateEnv)) ? $this->database_name . $this->wire()->config->dbMigrateEnv : $this->database_name;
		} else {
			return $this->database_name;
		}
	}

	/**
	 * Make sure all the assets are there for a page with repeaters
	 *
	 * @param $p
	 * @return void
	 */
	protected function getInputfieldAssets($p) {
		foreach($p->getFields() as $field) {
			$inputfield = $field->getInputfield($p);
			$type = $inputfield->className;
			$name = $inputfield->attr('name');
			if($type == 'InputfieldRepeaterMatrix' || $type == 'InputfieldRepeater') {
				$repeater = $p->$name;
				if(!wireInstanceOf($repeater, 'PageArray')) {   // In case repeater is a FieldsetPage, so not an array
					$repeater = [$repeater];
				}
				foreach($repeater as $repeaterItem) {
					$repeaterItem->of(false);
					$this->getInputfieldAssets($repeaterItem);
				}
			} else {
				$p->of(false);
				$inputfield->renderReady();
				$this->bd(['page' => $p, 'inputfield' => $inputfield], 'inputfield');
			}
		}
	}

	/**
	 * NOT USED
	 *
	 * @param $event
	 * @return void
	 * @throws WireException
	 */
	protected function afterMoveable($event) {
		$this->bd($event, 'IN MOVEABLE HOOK');
		/** @var Page $page */
		$page = $event->object;
		$moveable = $event->return;
		foreach($this->migrations->find("template={$this->migrationTemplate}, include=all") as $migration) {
			/* @var $migration DbMigrationPage */
			if($migration->conflictFree()) continue;
			$migrationNames = $migration->itemNames('page');
			$this->bd($migrationNames, 'migration names');
			if(in_array($page->path, $migrationNames)) {
				$this->bd($page, 'NOT MOVEABLE');
				$moveable = false;
			}
		}
		$event->return = $moveable;
	}

//	protected function listAllFiles($dir) {
//		$assocArray = [];
//		if(scandir($dir)) {
//			$array = array_diff(scandir($dir), array('.', '..'));
//			foreach($array as $item) {
//				$assocArray[$item] = $dir .  $item;
//			}
//			unset($item);
//			foreach($assocArray as $item => $path) {
//				if(is_dir($path)) {
//					$assocArray = array_merge($assocArray, $this->listAllFiles($path . DIRECTORY_SEPARATOR));
//				}
//			}
//		}
//		return $assocArray;
//	}


	/**
	 *
	 * Install/upgrade the module
	 *
	 * @param false $upgrade
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___install($upgrade = false) {
		$this->bd('ProcessDbMigrate install');
		$enable = $this->enable_dbMigrate;
		$suppress = $this->suppress_hooks;
		if(!$enable) $this->set('enable_dbMigrate', 1);
		if($suppress) $this->set('suppress_hooks', 0);
		$this->init();  // Need the properties to be loaded for the bootstrap, but init() is not called before install()
		$this->bootstrap($upgrade);
		$this->init();  // re-initialise now everything should be there
		$this->set('enable_dbMigrate', $enable);
		$this->set('suppress_hooks', $suppress);
		if(!$this->migrations or !$this->migrations->id) {
			$this->wire->session->error($this->_('Bootstrap failed'));
			return;
		}
		$setupPage = $this->wire('pages')->get("template=admin, name={$this->name}");
		if(!$setupPage or !$setupPage->id) {
			// Create the admin page
			$tpl = $this->wire()->templates->get('admin');
			$p = $this->wire(new Page($tpl));
			$p->name = $this->name;
			$p->parent = $this->wire('pages')->get("name=setup, parent.id=2");;
			$p->title = $this->title;
			$p->process = $this->adminProcess;
			$p->of(false);
			$p->save();
		}
		/* CONSTRUCT HELP TEMPLATE AND PAGE
		* Kept within the module directory  (see forum post https://processwire.com/talk/topic/2676-configuring-template-path/page/3/)
		 */
		$this->createTemplate('DbMigrateHelp', 'DbMigrateHelp');
		$this->createPage('DbMigrate help', 'DbMigrateHelp');
	}

	/**
	 *
	 * Upgrade the module
	 *
	 * @param int|string $fromVersion
	 * @param int|string $toVersion
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___upgrade($fromVersion, $toVersion) {
		$this->bd([$fromVersion, $toVersion], 'upgrade - from, to');
		$this->session->set('upgraded', true);
		// Versions >= 0.1.0 use FieldtypeDbMigrateRuntime not RuntimeOnly
		if(version_compare($toVersion, '0.1.0', '>=')
			and version_compare($fromVersion, '0.1.0', '<')) {
			// add the new fieldtype
			$this->wire()->modules->install('FieldtypeDbMigrateRuntime');
			// set session var to allow removal of old fields
			$this->session->set('upgrade0.1.0', true); // session var set here is used in ready() as old fields cannot be removed until after bootstrap install
		}
		// Versions >= 2.0.0 have tracking scope in the migration page, not the module
		if(version_compare($toVersion, '2.0.0', '>=')
			and version_compare($fromVersion, '2.0.0', '<')) {
			$this->warning($this->_("Scope of tracking for migrations using 'log changes' will be lost as this is now (post v2.0.0) maintained at the migration page level.\n
			 For any open migrations, you may need to re-set the tracking scope on the migration page."));
		}

		$this->___install(true);
	}

	/**
	 * Install the bootstrap page
	 *
	 * @param boolean $upgrade True for upgrade only.
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	protected function bootstrap($upgrade = false) {
		/*
		 * For an upgrade, we need to see if the bootstrap json files have changed.
		 * If they have, we will re-install the bootstrap using the new files, just like a new install
		 * If they haven't we don't need to do anything - our bootstrap is still good
		 */
		$this->bd($upgrade, 'bootstrap (upgrade?)');
		$sameBootstrap = false;
		$temp = $this->migrationTemplate;
		$bootstrap = $this->wire()->pages->get("template=$temp, name=bootstrap");
		$this->bd($bootstrap, 'bootstrap');
		/* @var $bootstrap DbMigrationPage */
		if($upgrade) {
			if($bootstrap && $bootstrap->id) {
				$currentFilesHash = $bootstrap->filesHash();
				$newFilesHash = $bootstrap->filesHash($this->modulePath);
				$sameBootstrap = ($currentFilesHash == $newFilesHash);
				$this->bd([$currentFilesHash, $newFilesHash], 'current & new hashes');
			}
		}
		$this->bd($sameBootstrap, 'samebootstrap');
		if($sameBootstrap) return;

		/*
		 * We have a new bootstrap to install
		 */
		// copy the bootstrap files to templates directory
		if(!is_dir($this->migrationsPath . 'bootstrap/')) if(!wireMkdir($this->migrationsPath . 'bootstrap/', true)) {
			throw new WireException($this->_('Unable to create migration directory') . ": {$this->migrationsPath}bootstrap/");
		}
		$this->bd(['bootstrapPath' => $this->bootstrapPath, 'migrationsPath' => $this->migrationsPath . 'bootstrap/'], 'copy files');
		$this->wire()->files->copy($this->bootstrapPath, $this->migrationsPath . 'bootstrap/');

		// Before we are able to use the copied .json files, we need to check and amend the admin root in use as it may differ in the target system
		if($this->adminPath != self::SOURCE_ADMIN) {
			$jsonFiles = ['/new/data.json', '/new/migration.json', '/old/data.json', '/old/migration.json'];
			foreach($jsonFiles as $jsonFile) {
				$json = (file_exists($this->migrationsPath . 'bootstrap' . $jsonFile))
					? file_get_contents($this->migrationsPath . 'bootstrap' . $jsonFile) : null;
				if($json) {
					$json = str_replace(self::SOURCE_ADMIN, $this->adminPath, $json);
					file_put_contents($this->migrationsPath . 'bootstrap' . $jsonFile, $json);
				}
			}
		}
		// Delete any old bootstrap before continuing
		if($bootstrap && $bootstrap->id) {
			if($bootstrap->isLocked()) $bootstrap->removeStatus(Page::statusLocked);
			$bootstrap->delete(true);
			$this->bd('deleted bootstrap');
		}

		// Install a dummy bootstrap using the copied/amended files
		$className = 'ProcessWire\\' . self::MIGRATION_TEMPLATE . 'Page';
		// We need a template to create the dummy bootstrap but the DbMigration template might not be installed yet
		$tpl = ($this->wire()->templates->get(self::MIGRATION_TEMPLATE)) ?: $this->wire('templates')->add('DbMigrateDummyBootstrapTemplate');
		$dummyBootstrap = $this->wire(new $className($tpl));  // dummy migration
		/*
		 * NB we cannot assign a template to dummy-bootstrap as we need to run it to create the template!!
		 */
		$dummyBootstrap->name = 'dummy-bootstrap';
		/* @var $dummyBootstrap DbMigrationPage */
		$dummyBootstrap->installMigration('new');
		// remove the dummy bootstrap template if necessary
		if($this->templates()->get('DbMigrateDummyBootstrapTemplate')) {
			$this->templates()->delete($this->templates()->get('DbMigrateDummyBootstrapTemplate'));
		}
		$this->bd('installed new bootstrap');

		/* NB For normal upgrades, bootstrap installation is run by ready() if bootstrap is not installed
		It cannot be run here as not all the API is present
		*/
	}

	/**
	 * Custom uninstall routine
	 *
	 * @param $event
	 * @throws WireException
	 *
	 */
	public function customUninstall($event) {
		$class = $event->arguments(0);
		if(__NAMESPACE__ . '\\' . $class != __CLASS__) return;
		$this->bd('IN CUSTOM UNINSTALL - uninstalling....');
		$abort = false;

		// Make sure there is a bootstrap page
		$setupPage = $this->wire()->pages->get($this->adminPath . 'setup/dbmigrations/');
		if($setupPage and $setupPage->id) {
			if(!$this->bootstrap or !$this->bootstrap->id) {
				$this->error($this->_('Uninstall of bootstrap failed.') . "\n" .
					$this->_('No bootstrap page  - try going to setup page and refreshing before uninstalling'));
				$abort = true;
			}
		} else {
			$this->bd('NO SETUP PAGE');
			return;
		}
		//Check all templates with dbMigrate tags to ensure there are no migration pages
		$taggedTemplates = wire()->templates->find("tags=dbMigrate");
		$templateList = $taggedTemplates->implode('|', 'name');
		$pagesUsingTemplates = wire()->pages->find("template=$templateList, include=all"); // gets trashed pages as well
		if($pagesUsingTemplates and $pagesUsingTemplates->count() > 0) {
			$links = "";

			foreach($pagesUsingTemplates as $pageUsingTemplate) {
				if(
					($pageUsingTemplate->template == self::MIGRATION_TEMPLATE or $pageUsingTemplate->template == self::COMPARISON_TEMPLATE)
					and
					$pageUsingTemplate->name != 'bootstrap'
				) {
					$links .= "<li><a target='_blank' href='{$pageUsingTemplate->editUrl}'>{$pageUsingTemplate->get('title|name')}</a></li>";
				}
			}
		}

		if($links) {
			$this->error(
				// Delete all comparison pages and all migration pages except for bootstrap before uninstalling.
				$this->_('Uninstall aborted as there are existing migration and/or comparison pages.') .
					"<p class='uk-text-small uk-margin-small-top uk-margin-remove-bottom'>The following pages need to be trashed and completely deleted first:</p>" .
					"<ul class='uk-list-disc uk-text-small uk-margin-remove-top'>".$links."</ul>",
				Notice::allowMarkup
			);
			$event->replace = true; // prevents original uninstall
			$this->session->redirect("./edit?name=$class"); // prevent "module uninstalled" message
		}

		// unset system flags on templates and fields
		$dbMigrateTemplates = wire()->templates->find("tags=dbMigrate");
		foreach($dbMigrateTemplates as $t) {
			$t->flags = Template::flagSystemOverride;
			$t->flags = 0;
			foreach($t->fieldgroup as $f) {
				$f->flags = Field::flagSystemOverride;
				$f->flags = 0;
			}
		}
		$dbMigrateFields = wire()->fields->find("tags=dbMigrate");
		foreach($dbMigrateFields as $f) {
			$f->flags = Field::flagSystemOverride;
			$f->flags = 0;
		}
		// uninstall if there is a valid 'old' directory
		if(!$abort) {
			$this->bootstrap->ready();  // Need the properties to be loaded for the uninstall, but ready() is not called before uninstall()
			// remove the help page etc
			$helpPages = $this->pages()->find("template=DbMigrateHelp");
			foreach($helpPages as $helpPage) {
				$helpPage->delete(true);
			}
			$helpTemplate = $this->templates()->get('DbMigrateHelp');
			$fieldgroup = $helpTemplate->fieldgroup;
			$this->wire('templates')->delete($helpTemplate);
			$this->wire('fieldgroups')->delete($fieldgroup);
			// Uninstall all the bootstrap items
			try {
				$this->bd('uninstalling bootstrap');
				$this->bootstrap->installMigration('old');
			} catch(WireException $e) {
				$this->bd($e, 'WireException');
				$msg = $e->getMessage();
				$this->error($this->_('Uninstall of bootstrap failed or incomplete.') . "\n $msg \n" .
					$this->_('Re-install the module and fix the cause of the problem before uninstalling again.'));
				$abort = false; //allow uninstall to complete as re-installation of bootstrap may be required to enable proper uninstallation
			}
			$this->message(sprintf($this->_('Removed %s'), $setupPage->path));
			$this->wire()->pages->delete($setupPage, true);
		}
		// uninstall?
		if($abort) {
			$this->bd('ABORTING UNINSTALL');
			// there were some non-critical errors
			// close without uninstalling module -
			$event->replace = true; // prevents original uninstall
			$this->session->redirect("./edit?name=$class"); // prevent "module uninstalled" message
		}
	}


	/**
	 * Main admin page - list migrations & allows creation of new migration
	 *
	 * @return array|string
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___execute() {
		if(!$this->enable_dbMigrate) {
			$this->wire()->error("ProcessDbMigrate is disabled - it can be enabled in the module settings.");
			return;
		}
		if($this->suppress_hooks) $this->wire()->error("Hook suppression is on - migrations will not work correctly - unset in the module settings.");
		$pageEdit = $this->wire('urls')->admin . 'page/edit/?id=';

		$this->modules->get('JqueryWireTabs');

		/* @var $form InputfieldForm */
		$form = $this->modules->get("InputfieldForm");
		$form->attr('id', 'ProcessDbMigrate');

		// tab 1 - Migrations
		$tab = new InputfieldWrapper();
		$tab->attr('id', 'migrations');
		$tab->attr('title', 'Migrations');
		$tab->attr('class', 'WireTab');
		$field = $this->modules->get("InputfieldMarkup");
		$table = $this->wire('modules')->get("MarkupAdminDataTable");
		$table->headerRow(['Name', 'Type', 'Status', 'Title', 'Summary', 'Items', 'Created']);
		$table->setSortable(true);
		$table->setEncodeEntities(false);
		$this->moduleRefresh();
		$migrationPages = $this->migrations->find("template=$this->migrationTemplate, sort=-created, include=all");
		foreach($migrationPages as $migrationPage) {
			/* @var $migrationPage DbMigrationPage */
			if($migrationPage && $migrationPage->id) {
				$installedStatus = $migrationPage->meta('installedStatus');
				$status = ($installedStatus) ? $installedStatus['status'] : 'indeterminate';
				if(!$migrationPage->meta('locked')) {
					if($migrationPage->meta('installable')) {
						$statusColour = ($status == 'installed') ? 'lightgreen' : (($status == 'uninstalled') ? 'salmon' : 'orange');
					} else {
						$statusColour = ($status == 'exported') ? 'lightgreen' : 'salmon';
					}
				} else {
					// $status = 'Locked';
					$statusColour = 'LightGrey';
				}
				$this->bd($migrationPage, $status);
				$this->bd($installedStatus);
				$lockIcon = ($migrationPage->meta('locked')) ? '<i class="fa fa-lock"></i>' : '<i class="fa fa-unlock"></i>';
				$itemList = [];
				//foreach($migrationPage->getFormatted('dbMigrateItem') as $migrateItem) {
				foreach($migrationPage->dbMigrateItem->find("status=1") as $migrateItem) {
					/* @var $migrateItem RepeaterPage */
					$oldName = ($migrateItem->dbMigrateOldName) ? '|' . $migrateItem->dbMigrateOldName : '';
					$itemList[] = '<em>' . $migrateItem->dbMigrateAction->title . ' ' . $migrateItem->dbMigrateType->title . '</em>: ' . $migrateItem->dbMigrateName . $oldName;
				}
				$itemsString = implode("    ", $itemList);
				$magic = (!$migrationPage->meta('installable') && !$migrationPage->meta('locked') && $migrationPage->dbMigrateLogChanges == 1) ? '<span class="fa fa-magic"></span>' : '';
				$data = array(
					// Values with a string key are converter to a link: title => link
					$migrationPage->name => $pageEdit . $migrationPage->id,
					($migrationPage->meta('installable')) ? '<span class="fa fa-arrow-down"></span>' : '<span class="fa fa-arrow-up"></span>' . $magic,
					$lockIcon . ' <span style="background:' . $statusColour . '">' . $status . '</span>',
					$migrationPage->title,
					[$migrationPage->dbMigrateSummary, 'migration-table-text'],
					[$itemsString, 'migration-table-text'],
					date('Y-m-d', $migrationPage->created),
				);
				$table->row($data);
			}
		}
		$this->wire('modules')->get('JqueryUI')->use('modal');
		$out = '<div><h3>' . $this->_('Existing migrations are listed below. Go to the specific migration page for any actions.') . '</h3><p>' .
			$this->_('Exportable migrations') . ' - <span class="fa fa-arrow-up"></span> - ' .
			$this->_('originated in this database, can be edited here and are a source of a migration to be installed elsewhere.') . '</p><p>' .
			$this->_('------------------------') . ' - <span class="fa fa-magic"></span> - ' .
			$this->_('Indicates that "log changes" is active for this (exportable) migration.') . '</p><p>' .
			$this->_('Installable migrations') . ' - <span class="fa fa-arrow-down"></span> - ' .
			$this->_('originated from another database and can be installed/uninstalled here (except that "bootstrap" cannot be uninstalled). They cannot be changed except in the original database.') . '</p><p>' .
			$this->_('Locked migrations') . ' - <span class="fa fa-lock"></span> - ' .
			$this->_('can no longer be changed or actioned.') . '</p></div><div>';
		$out .= $table->render();
		$btnAddNew = $this->createNewButton($this->migrationTemplate, $this->migrations); //createNewButton also allows title and values to be set, but not used here
		$out .= $btnAddNew->render();
		$btn = $this->wire('modules')->get("InputfieldButton");
		$btn->attr('href', './get-migrations/');
		$btn->attr('id', "get_migrations");
		$btn->attr('value', "Refresh migrations");
		$btn->showInHeader();
		$out .= $btn->render();
		$field->value = $out;
		$tab->add($field);
		$form->append($tab);

		// tab 2 - Comparisons
		$tab = new InputfieldWrapper();
		$tab->attr('id', 'database-comparisons');
		$tab->attr('title', 'Database Comparisons');
		$tab->attr('class', 'WireTab');
		$field = $this->modules->get("InputfieldMarkup");
		/* @var $field InputfieldMarkup */
		$field->attr('id+name', 'database-comparisons');
		$table = $this->wire('modules')->get("MarkupAdminDataTable");
		$table->headerRow(['Name', 'Source DB', 'Title', 'Summary', 'Items', 'Created']);
		$table->setSortable(true);
		$table->setEncodeEntities(false);
		$comparisonPages = $this->comparisons->find("template=$this->comparisonTemplate, sort=-created, include=all");
		foreach($comparisonPages as $comparisonPage) {
			/* @var $comparisonPage DbComparisonPage */
			if($comparisonPage && $comparisonPage->id) {
				$itemList = [];
				foreach($comparisonPage->dbMigrateComparisonItem as $comparisonItem) {
					/* @var $comparisonItem RepeaterPage */
					$itemList[] = '<em>' . $comparisonItem->dbMigrateType->title . '</em>: ' . $comparisonItem->dbMigrateName;
				}
				$itemsString = implode("    ", $itemList);
				$data = array(
					// Values with a string key are converter to a link: title => link
					$comparisonPage->name => $pageEdit . $comparisonPage->id,
					$comparisonPage->meta('sourceDb'),
					$comparisonPage->title,
					$comparisonPage->dbMigrateSummary,
					$itemsString,
					date('Y-m-d', $comparisonPage->created),
				);
				$table->row($data);
			}
		}
		$this->wire('modules')->get('JqueryUI')->use('modal');
		$out = '<div><h3>' . $this->_('Existing database comparison pages are listed below. Go to the specific page to compare.') . '</h3></div>';
		$out .= $table->render();
		$btnAddNew = $this->createNewButton($this->comparisonTemplate, $this->comparisons); //createNewButton also allows title and values to be set, but not used here
		$out .= $btnAddNew->render();
		$btn = $this->wire('modules')->get("InputfieldButton");
		$this->bd($this->name, 'this name');
		$btn->attr('href', $this->parentHttpUrl . $this->name . "/get-comparisons/");
		$btn->attr('id', "get_comparisons");
		$btn->attr('value', "Refresh comparisons");
		$btn->showInHeader();
		$out .= $btn->render();
		$field->value = $out;
		$tab->add($field);
		$form->append($tab);

		return $form->render();
	}

	/**
	 * Refresh all migration pages - called by executeGetMigrations
	 *
	 * @param string $type
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function moduleRefresh($type = 'migrations') {
		$this->bd('in module refresh');
		$migrationPath = ($type == 'comparisons') ? $this->comparisonsPath : $this->migrationsPath;
		$migrationFiles = $this->wire('files')->find($migrationPath);
		$migrationFiles = array_filter($migrationFiles, function($e) {
			return (basename($e, '.json') == 'migration' and basename(pathinfo($e)['dirname']) == 'new');
		});
		$migrationPages = $this->wire(new PageArray());
		$this->bd($migrationFiles, 'migration files');
		if($type == 'migrations') {
			if(!$this->migrations or !$this->migrations->id or !$this->wire()->templates->get('DbMigrations')
				or !$this->wire()->templates->get('DbMigration')) {
				$this->wire()->session->error($this->_('No DbMigrations page'));
				$this->wire()->session->redirect('../');
				return;
			}
			$migrationPages = $this->migrations->find("template=$this->migrationTemplate, sort=-modified, include=all");
		}
		if($type == 'comparisons') {
			if(!$this->comparisons or !$this->comparisons->id or !$this->wire()->templates->get('DbMigrations')
				or !$this->wire()->templates->get('DbComparison')) {
				$this->wire()->session->error($this->_('No DbComparisons page'));
				$this->wire()->session->redirect('../');
				return;
			}
			$migrationPages = $this->comparisons->find("template=$this->comparisonTemplate, sort=-modified, include=all");
		}
		$alreadyFound = [];
		foreach($migrationPages as $migrationPage) {
			/* @var $migrationPage DbMigrationPage */
			$migrationDirectory = $migrationPath . $migrationPage->name . '/new/';
			$foundFiles = $this->wire('files')->find($migrationDirectory);
			foreach($foundFiles as $foundFile) {
				if(basename($foundFile, '.json') == 'migration') $alreadyFound[$migrationPage->name] = $foundFile;
			}
		}
		$wantedFiles = array_diff($migrationFiles, array_values($alreadyFound));
		$this->bd($alreadyFound, '$alreadyFound');
		$this->bd($wantedFiles, 'wanted files');
		foreach($wantedFiles as $file) {
			//Retrieve the data from our text file.
			$fileContents = wireDecodeJSON(file_get_contents($file));
			$this->bd($fileContents, 'wanted file contents');
			$sourceDb = null;
			if(isset($fileContents['sourceDb'])) {
				$sourceDb = $fileContents['sourceDb'];
				unset($fileContents['sourceDb']);
//				$sourceSiteUrl = $fileContents['sourceSiteUrl']; // Not needed
				unset($fileContents['sourceSiteUrl']);
			}
			foreach($fileContents as $content) {
				// There should only be one item in this context
				$this->bd($content, 'content');
				if(!is_array($content)) continue;
				foreach($content as $line) {
					foreach($line as $pathName => $values) {
						$pageName = $values['name'];
						$className = ($type == 'comparisons') ? 'ProcessWire\\' .
							self::COMPARISON_TEMPLATE . 'Page' : 'ProcessWire\\' . self::MIGRATION_TEMPLATE . 'Page';
						$newMigration = $this->wire(new $className());
						$newMigration->of(false);
						$newMigration->name = $pageName;
						$newMigration->parent = ($type == 'comparisons') ? $this->comparisons : $this->migrations;
						$newMigration->template = ($type == 'comparisons') ? 'DbComparison' : 'DbMigration';
						$newMigration->status = 1;
						$this->bd($values, 'in module refresh with $values');
						$newMigration->save(['noHooks' => true]);
						unset($values['id']);
						unset($values['parent']);
						unset($values['template']);
						unset($values['status']);
						// split out repeaters from values
						$r = $newMigration->getRepeaters($values);
						$repeaters = $r['repeaters'];
						$values = $r['values'];  // values has repeaters removed
						$this->bd($values, 'wanted values');
						$this->bd($newMigration, 'newMigration');
						$newMigration->created = filectime($file);
						$newMigration->setAndSave($values, ['noHooks' => true, 'quiet' => true]);
						$newMigration->setAndSaveRepeaters($repeaters, 'new', null, ['noHooks' => true]);
					}
				}
			}
			// To prevent re-saving, show 'Install' and 'Uninstall' buttons and remove 'Export Data' button - implicitly by setting meta('installable'):
			if(isset($newMigration) and $newMigration and $newMigration->id) {
				$newMigration->meta('installable', true);
				$newMigration->meta('sourceDb', $sourceDb);
				// For drafts created from database comparisons, set the 'draft' flag if there is no new/data.json file
				if($sourceDb != $this->dbName() and strpos($newMigration->name, self::XCPREFIX) == 0
					and !file_exists($migrationPath . $newMigration->name . '/new/data.json'))
					$newMigration->meta('draft', true);
			}
		}
		foreach($alreadyFound as $pName => $found) {
			$migrationPage = $this->$type->get("name=$pName");
			if($migrationPage and $migrationPage->id) {
				$migrationPage->ready(); // Need to trigger it as not auto
				$migrationPage->meta('updated', false);
				$updated = $migrationPage->refresh($found);
				$migrationPage->meta('updated', $updated);
			}
		}
	}

	/**
	 *
	 * Add a button to create new pages
	 *
	 * @param $tpl
	 * @param $page
	 * @param null $title
	 * @param array $values
	 * @return array|array[]|\array[][]|bool|float|int|int[]|mixed|null[]|\null[][]|object|_Module|Field|Fieldtype|Module|NullPage|Page|PageArray|Pages|Permission|Role|SessionCSRF|Template|User|Wire|WireArray|WireData|WireDataDB|WireInputData|string|string[]|\string[][]|null
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function createNewButton($tpl, $page, $title = '', $values = []) {
		$template = wire()->templates->get($tpl);
		$valuesStr = urlencode(serialize($values));
		$titleStr = urlencode($title);
		$btnAddNew = wire('modules')->get("InputfieldButton");
		$btnAddNew->attr('href', $this->parentHttpUrl . $this->name . "/new-page/?template=" . $template->name . "&parent=" .
			$page->id . "&title=" . $titleStr . "&values=" . $valuesStr);
		$btnAddNew->attr('id', "AddPage_" . $template);
		$btnAddNew->attr('value', "Add New " . $template);
		$btnAddNew->showInHeader();
		return $btnAddNew;
	}

	/**
	 *
	 * Create a new page
	 *
	 * @throws WireException
	 *
	 */
	public function executeNewPage() {
		$templateName = $this->wire()->input->get->text("template");
		$template = wire()->templates->get($templateName);  //name
		$parent = $this->wire()->input->get->int("parent");  //id
		$title = urldecode($this->wire()->input->get->text('title'));  //string
		$values = unserialize(urldecode($this->wire()->input->get->text('values'))); //array
		$this->newPage($template, $parent, $title, $values);
	}

	/**
	 * This is the hookable method for executeNewPage, with the GET variables as arguments
	 *
	 * @param $template
	 * @param $parent
	 * @param $title
	 * @param $values
	 * @throws WireException
	 *
	 */
	public function ___newPage($template, $parent, $title, $values) {

		$className = $template . 'Page';
		$url = './';
		if(!$title) {
			$this->bd($template, 'template');
			$url = wire('config')->urls->admin . "page/add/?parent_id=" . $parent . "&template_id=" . $template->id;
		} else {
			$this->bd($className, 'class name');
			$newPage = $this->wire(new $className());
			$newPage->of(false);
			$newPage->title = $title;
			$newPage->save();
			$newPage->setAndSave($values);
		}
		wire()->session->redirect($url);
	}

	/**
	 *
	 * View the database comparison setup page
	 *
	 * @return string
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___executeDatabaseComparison() {
		$pageEdit = $this->wire('urls')->admin . 'page/edit/?id=';

		$table = $this->wire('modules')->get("MarkupAdminDataTable");
		$table->headerRow(['Name', 'Source DB', 'Title', 'Summary', 'Items', 'Created']);
		$table->setSortable(true);
		$table->setEncodeEntities(false);
		$comparisonPages = $this->comparisons->find("template=$this->comparisonTemplate, sort=-created, include=all");
		foreach($comparisonPages as $comparisonPage) {
			/* @var $comparisonPage DbComparisonPage */
			if($comparisonPage && $comparisonPage->id) {
				$itemList = [];
				foreach($comparisonPage->dbMigrateComparisonItem as $comparisonItem) {
					/* @var $comparisonItem RepeaterPage */
					$itemList[] = '<em>' . $comparisonItem->dbMigrateType->title . '</em>: ' . $comparisonItem->dbMigrateName;
				}
				$itemsString = implode("    ", $itemList);
				$data = array(
					// Values with a string key are converter to a link: title => link
					$comparisonPage->name => $pageEdit . $comparisonPage->id,
					$comparisonPage->meta('sourceDb'),
					$comparisonPage->title,
					$comparisonPage->dbMigrateSummary,
					$itemsString,
					date('Y-m-d', $comparisonPage->created),
				);
				$table->row($data);
			}
			$itemList = [];

		}
		$this->wire('modules')->get('JqueryUI')->use('modal');
		$out = '<div><h3>' . $this->_('Existing database comparison pages are listed below. Go to the specific page to compare.') . '</h3></div>';
		$out .= $table->render();
		try {
			$btnAddNew = $this->createNewButton($this->comparisonTemplate, $this->comparisons);
		} catch(WireException $e) {
			$this->wire()->session->error($this->_('Error in creating button'));
		}
		//createNewButton also allows title and values to be set, but not used here
		$out .= $btnAddNew->render();
		$btn = $this->wire('modules')->get("InputfieldButton");
		$btn->attr('href', $this->parent . $this->name . '/get-comparisons/');
		$btn->attr('id', "get_comparisons");
		$btn->attr('value', "Refresh");
		$btn->showInHeader();
		$out .= $btn->render();
		return $out;
	}

	/**
	 * Execute method for refresh Linked by Refresh button on this page
	 *
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___executeGetMigrations() {
		$this->moduleRefresh();
		$this->wire()->session->redirect('../#migrations-tab');
	}

	/**
	 * Execute method for refresh Linked by Refresh button on this page
	 *
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___executeGetComparisons() {
		$this->moduleRefresh('comparisons');
		$this->wire()->session->redirect('../#database-comparisons-tab');
	}

	/**
	 * Execute method for export - linked by button in dbMigrateActions
	 *
	 * @throws WireException
	 *
	 */
	public function executeExportData() {
		$pageId = $this->wire()->input->get->int('id');
		$type = $this->wire()->input->get->text('type');
		$migrationPage = $this->wire()->pages->get($pageId);
		if($type == 'comparison') {
			/* @var $migrationPage DbComparisonPage */
		} else {
			/* @var $migrationPage DbMigrationPage */
		}
		$this->exportData($migrationPage);
		$this->wire()->session->redirect($this->wire()->urls->admin . 'page/edit/?id=' . $pageId);
	}

	/**
	 * This is the hookable method for export - it has the migration page as an argument
	 *
	 * @param $migrationPage
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___exportData($migrationPage) {
		/* @var $migrationPage DbMigrationPage */
		$migrationPage->exportData('new');
		$migrationPage->exportData('compare');
	}

	/**
	 * Execute method for removing files - linked by button in dbMigrateActions
	 *
	 * @throws WireException
	 *
	 */
	public function executeRemoveFiles() {
		$pageId = $this->wire()->input->get->int('id');
		$oldOnly = ($this->wire()->input->get->int('oldOnly') == 1);
		$migrationPage = $this->wire()->pages->get($pageId);
		/* @var $migrationPage DbMigrationPage */
		$this->removeFiles($migrationPage, $oldOnly);
		$this->wire()->session->redirect($this->wire()->urls->admin . 'page/edit/?id=' . $pageId);
	}

	/**
	 * This is the hookable method for removing files - it has the migration page as an argument
	 *
	 * @param $migrationPage
	 * @param bool $oldOnly
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___removeFiles($migrationPage, $oldOnly = false) {
		$type = (get_class($migrationPage) == 'ProcessWire\DbComparisonPage') ? 'comparison' : 'migration';
		if($type == 'comparison') {
			/* @var $migrationPage DbComparisonPage */
			$migrationPath = $this->comparisonsPath . $migrationPage->name . '/';
		} else {
			/* @var $migrationPage DbMigrationPage */
			$migrationPath = $this->migrationsPath . $migrationPage->name . '/';
		}
		if(is_dir($migrationPath)) {
			if($oldOnly) {
				$this->wire()->files->rmdir($migrationPath . 'old/', true);
				$migrationPage->refresh();
			} else {
				$this->wire()->files->rmdir($migrationPath, true);
			}
		}
	}

	/**
	 * Execute method for install - linked by button in dbMigrateActions
	 *
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function executeInstallMigration() {
		$pageId = $this->wire()->input->get->int('id');
		$migrationPage = $this->wire()->pages->get($pageId);
		/* @var $migrationPage DbMigrationPage */
		$this->installMigration($migrationPage);
		$this->wire()->session->redirect($this->wire()->urls->admin . 'page/edit/?id=' . $pageId);
	}

	/**
	 * This is the hookable method for install - it has the migration page as an argument
	 *
	 * @param $migrationPage
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___installMigration($migrationPage) {
		/* @var $migrationPage DbMigrationPage */
		$count = 0;
		$result = 'uninstalled';
		while($result != 'installed' and $count < $this->install_repeats) {
			$result = $migrationPage->installMigration('new');
			$count++;
		}
		$attempts = ($count == 1) ? 'attempt' : 'attempts';
		if($result != 'installed') {
			$this->wire()->session->error($this->_("Installation failed after $count $attempts - please preview differences to diagnose."));
		} else {
			$this->wire()->session->message($this->_("Installation succeeded after $count $attempts."));
		}

	}

	/**
	 * Execute method for uninstall - linked by button in dbMigrateActions
	 *
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function executeUninstallMigration() {
		$pageId = $this->wire()->input->get->int('id');
		$migrationPage = $this->wire()->pages->get($pageId);
		/* @var $migrationPage DbMigrationPage */
		$this->uninstallMigration($migrationPage);
		$this->wire()->session->redirect($this->wire()->urls->admin . 'page/edit/?id=' . $pageId);
	}

	/**
	 * This is the hookable method for uninstall - it has the migration page as an argument
	 *
	 * @param $migrationPage
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___uninstallMigration($migrationPage) {
		/* @var $migrationPage DbMigrationPage */
		$migrationPage->installMigration('old');
	}

	/**
	 * Lock/Unlock the migration
	 * NB linked by dbMigrateControl as URL hence PHPStorm does not show usage
	 *
	 * @throws WireException
	 *
	 */
	public function executeLock() {
		$pageId = $this->wire()->input->get->int('id');
		$action = $this->wire()->input->get->text('action');
		$migrationPage = $this->wire()->pages->get($pageId);
		$migrationFolder = $this->migrationsPath . $migrationPage->name . '/';
		if($action == 'lock') {
			$this->bd($migrationPage->meta('locked'), 'Meta locked');
			if(is_dir($migrationFolder)) {
				//NB restriction on locking removed from installable migrations
//				if(!$migrationPage->meta('installable')) {
					$this->lockMigration($migrationPage, $migrationFolder);
//				}
			} else {
				$this->wire()->session->error(sprintf($this->_("Unable to lock migration as no directory named %s exists."), $migrationPage));
			}
		} else {   // 'unlock'
			$migrationFiles = $this->wire()->files->find($migrationFolder);
			if(is_dir($migrationFolder) and in_array($migrationFolder . 'lockfile.txt', $migrationFiles)) {
//				if(!$migrationPage->meta('installable')) {
					$this->unlockMigration($migrationPage, $migrationFolder);
//				}
			}
		}
		$this->wire()->session->redirect($this->wire()->urls->admin . 'page/edit/?id=' . $pageId);
	}

	/**
	 * This is the hookable method for Lock - it has the migration page as an argument
	 *
	 * @param $migrationPage
	 * @param $migrationFolder
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___lockMigration($migrationPage, $migrationFolder) {
		/* @var $migrationPage DbMigrationPage */
		if($migrationPage && $migrationPage->id) {
			$migrationPage->exportData('compare'); // sets meta('installedStatus') to the latest status before locking
			$now = $this->wire()->datetime->date('Ymd-His');
			$this->wire()->files->filePutContents($migrationFolder . 'lockfile.txt', $now);
			$migrationPage->meta('locked', true);
		}
	}
	/**
	 * This is the hookable method for Unlock - it has the migration page as an argument
	 *
	 * @param $migrationPage
	 * @param $migrationFolder
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___unlockMigration($migrationPage, $migrationFolder) {
		/* @var $migrationPage DbMigrationPage */
		if($migrationPage && $migrationPage->id) {
			$this->wire()->files->unlink($migrationFolder . 'lockfile.txt');
			$migrationPage->meta()->remove('locked');
			$migrationPage->exportData('compare'); // sets meta('installedStatus') to the latest status after unlocking (May cause errors if unlocking superseded migration?)
		}
	}

	/**
	 *  Execute method for Preview
	 * NB linked by button in dbMigrateActions hence PHPStorm does not show usage
	 *
	 * @return string
	 * @throws WireException
	 *
	 */
	public function executePreviewDiffs() {
		$pageId = $this->wire()->input->get->int('id');
		$comparisonType = $this->wire()->input->get->text('target');
		$button = $this->wire()->input->get->text('button');
		$migrationPage = $this->wire()->pages->get($pageId);
		/* @var $migrationPage DbMigrationPage */
		return $this->previewDiffs($migrationPage, $comparisonType, $button);
	}

	/**
	 * This is the hookable method for PreviewDiffs - it has the migration page as an argument
	 *
	 * @param $migrationPage
	 * @param $comparisonType
	 * @param $button
	 * @return string
	 * @return string
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function ___previewDiffs($migrationPage, $comparisonType, $button) {
		if($migrationPage->template == self::COMPARISON_TEMPLATE) {
			$pageType = 'comparison';
			/* @var $migrationPage DbComparisonPage */
		} else {
			/* @var $migrationPage DbMigrationPage */
			$pageType = 'migration';
		}
		if($pageType == 'comparison' and $button == 'draft') {
			// remove any existing draft before creating a new one
			$nameRoot = self::XCPREFIX . $migrationPage->name;
			$drafts = $this->wire()->pages->find("parent=$this->migrations, name^=$nameRoot, include=all");
			$this->bd($this->migrations->path . $nameRoot, 'check existing');
			/*
			 * Delete any existing draft, but ONLY if it is still a draft (given by meta)
			 * Note that the 'draft' meta is removed once there is a data.json file in the migration directory (removal is by RuntimeFields/dbMigrateRuntimeAction.php)
			 */
			$this->wire()->session->set('trash-drafts', true);
			foreach($drafts as $draft) {
				$this->bd([$draft,$draft->meta('draft')], 'draft meta in loop');
				if($draft and $draft->id and $draft->meta('draft')) {
					$draft->ready(); // to ensure that hooks in DbMigrationPage class are operative
					$this->bd($draft, 'trashing');
					$draft->trash();  // hook will delete associated migration files
					$draft->delete(true);
				}
			}
			$this->wire()->session->remove('trash-drafts');
			$name = $nameRoot . '-' . $this->datetime->date();

			$draft = $this->wire(new DbMigrationPage());
			$draft->parent = $this->migrations;
			$draft->name = $name;
			$draft->title = $this->_('Generated from comparison') . ' "' . $migrationPage->title . '"';
			$draft->save(['noHooks' => true]);
			$draft->meta('sourceDb', $migrationPage->meta('sourceDb'));
			$draft->meta('draft', true);
			$draft->meta('installable', true);
			$this->bd($draft->meta('draft'), 'initial draft meta');
		} else {
			$draft = null;
		}
		$target = $comparisonType;
		$target = ($target == 'export') ? 'install' : $target;  // export comparison is same as install, but with different text
		$diffs = $target . 'edDataDiffs';  // e.g. uninstalledDataDiffs
		$newOld = [];
		switch($target) {
			case 'install' :
				$newOld = ['current', 'new'];
				break;
			case 'uninstall' :
				$newOld = ['current', 'old'];
				break;
			case 'review' :
				$newOld = ['old', 'new'];
				break;
		}
		$compare = $migrationPage->exportData('compare');
		$this->bd($compare, 'result');
		$arrayComparison = $compare[$diffs];
		$this->bd($arrayComparison, 'array comparison');
		$col1 = ($target == 'review') ? 'pre-installation' : 'current';
		$col2 = ($target == 'review') ? 'post-installation' : $comparisonType;
		if($pageType == 'comparison') $col2 = 'source';
		$out = "<h1>Differences between $col1 and $col2 data</h1>";
		if(!$arrayComparison) {
			if($compare['installedMigrationDiffs']) {
				$out .= '<h2>No data differences, but there are other differences in the migration definition.</h2>';
			} else {
				$out .= '<h2>No differences</h2>';
				if($draft and $draft->id and $draft->meta('draft')) {
					$draft->trash();
					$draft->delete(true);
					$out = $this->_('No draft migration has been created as there are no differences between the databases (per the comparison item)');
					$out .= '<br/>' . $this->_('If you believe there should be differences, try re-exporting the comparison from the source database first.');
				}
				return $out;
			}
		}
		if(!is_array($arrayComparison)) {
			$out .= '<h2>Invalid comparison</h2>';
			return $out;
		}
		$out .= '<div class="uk-overflow-auto">';
		$out .= '<table class="uk-table uk-table-divider uk-table-hover" style="white-space: pre-wrap; table-layout: fixed; width: 100%"><thead style="font-weight:bold"><tr><th class="uk-width-1-5">Key</th><th class="uk-width-2-5">' . $col1 . '</th><th class="uk-width-2-5">' . $col2 . '</th></tr></thead>';
		$out .= '<tbody>';
		$this->first = true;
		if($draft) {
			$draft->repeaters = ['dbMigrateItem' => []];
		}
		foreach($arrayComparison as $key => $value) {
			$keyArray = explode('->', $key); // sets 0, 1 , 2
			$out .= '<tr style="font-style:italic"><td>' . $key . '</td>';
			$out .= ($value) ? $this->formatCompare($migrationPage, $pageType, $value, $key, $newOld, $draft, $keyArray) : '';
			$out .= '</tr>';
			if(!$value and $draft) {
				$this->bd(['key' => $keyArray, 'draft' => $draft], 'adding from main __preview');
				$this->addDbMigrateItem($draft, $keyArray, 'new');
			}
		}
		if($draft) {
			$draft->setAndSaveRepeaters($draft->repeaters, 'new', $draft, ['noHooks' => true]);
			$draft->repeaters = [];
		}

		$this->bd($draft, 'draft in top');
		if($compare['installedMigrationDiffs']) {
			$out .= '<tr><td>Differences in migration definition that do not affect data.json files (but do affect migration.json):</td><td></td></tr>';
			foreach($compare['installedMigrationDiffs'] as $key => $value) {
				$out .= '<tr style="font-style:italic"><td>' . $key . '</td>';
				$out .= ($value) ? $this->formatCompare($migrationPage, $pageType, $value, $key, $newOld, $draft, []) : $value;
				$out .= '</tr>';
			}
		}
		$out .= '</tbody></table></div>';
		if($button == 'draft') {
			$draft->exportData('new');
			$out = '<p>' . $this->_('Draft migration.json file has been created in this directory') . ': ' . $this->migrationsPath . $draft->name . '/</p>';
			$out .= '<p>' . $this->_('You can view the draft migration at ') . '<a href="' . $this->wire()->urls->admin . 'page/edit/?id=' . $draft->id . '">' . $draft->name . '</a></p>';
			$out .= '<p>' . $this->_('However, no actions are available until data has been exported from the source database') . ': ' . $draft->meta('sourceDb') . '</p>';
			$out .= '<p>' . $this->_('Sync the draft migration directory to the source environment.
			 Then go to the source database and review the draft migration - you may need to re-order the items to take account of dependencies.
			 Then export the data and install it on the target environment in the normal way.');
		}
		return $out;
	}

	/**
	 * Markup for the Preview modal
	 *
	 * @param $value
	 * @param int $deep
	 * @param $newOld
	 * @param $key
	 * @param $migrationPage
	 * @param $pageType string For documentation purposes only
	 * @param $draft
	 * @param $keyArray
	 * @return string
	 * @throws WireException
	 *
	 */
	protected function formatCompare($migrationPage, $pageType, $value, $key, $newOld, &$draft, $keyArray, $deep = 0) {
		if(!$value) return '';
		if($pageType = 'comparison') {
			/* @var $migrationPage DbComparisonPage */
		} else {
			/* @var $migrationPage DbMigrationPage */
		}
		$this->bd($value, 'value in formatCompare');
		if($this->array_depth($value) == 1) {
			// at the bottom, there should be an unassociated array of length exactly = 2
			if(count($value) != 2) return '<td style="word-wrap: break-word">' . wireEncodeJSON($value) . '</td><td>' .
				'Item is not a pair' . '</td>';
			foreach($newOld as $col => $type) {
				if($type != 'current' and strpos($key, 'pages') === 0 and $value[$col] != strip_tags($value[$col])) {
					$value[$col] = $migrationPage->replaceImgSrcPath($value[$col], $type);
				}
			}
			if($draft) {
				$this->updateDraft($draft, $keyArray, $value);
			}
			return '<td style="word-wrap: break-word">' . $value[0] . '</td><td style="word-wrap: break-word">' .
				$value[1] . '</td>';
		}

		$this->bd($value, 'depth >= 2 value');
		if(count($value) == 2) {
			// check that one item is not a string, so we don't iterate further in  that case
			$a0 = array_slice($value, 0, 1, true);
			$a1 = array_slice($value, 1, 1, true);
			$v[0] = reset($a0);
			$v[1] = reset($a1);
			if(is_string($v[0]) or is_string($v[1])) {
				$this->bd($value, 'depth 2 count 2 value where one element is a string');
				foreach($newOld as $col => $type) {
					$v[$col] = (is_array($v[$col])) ? wireEncodeJSON($v[$col], true, true) : $v[$col];
					if(strpos($key, 'pages') === 0 and is_string($v[$col]) and $v[$col] != strip_tags($v[$col])) {
						// NB In this case $v[$col] is json so we need to fix quotes in img src
						if($type != 'current') {
							$v[$col] = $migrationPage->replaceImgSrcPath($v[$col], $type, true);
						} else {
							$v[$col] = str_replace('\"', "'", $v[$col]);
						}
					}
				}
				if($draft) {
					$this->updateDraft($draft, $keyArray, $v);
				}
				return '<td style="word-wrap: break-word">' . $v[0] . '</td><td style="word-wrap: break-word">' . $v[1] . '</td>';
			}
		}

		$out = '';
		$deep += 1;
		foreach($value as $k => $v) {
			$out .= '</tr><tr><td>' . str_repeat('>&nbsp;', $deep) . $k . '</td>';
			if(is_array($v)) {
				$out .= $this->formatCompare($migrationPage, $pageType, $v, $key, $newOld, $draft, $keyArray, $deep);
			}
			$this->bd($out, 'out for ' . $k);
		}
		return $out;
	}

	/**
	 * Return the maximum depth of a multi-dimensional array
	 * Currently just used in the module, but seems to be a useful function
	 *
	 * @param array $array
	 * @return int
	 *
	 */
	public function array_depth(array $array) {
		$max_depth = 1;
		foreach($array as $value) {
			if(is_array($value)) {
				$depth = $this->array_depth($value) + 1;

				if($depth > $max_depth) {
					$max_depth = $depth;
				}
			}
		}
		return $max_depth;
	}

	/**
	 *
	 * Create migration items from database comparison
	 *
	 * @param $draft
	 * @param $keyArray
	 * @param $v
	 * @throws WireException
	 *
	 */
	protected function updateDraft(&$draft, $keyArray, $v) {
		if(strpos($v[0], '!!NO_OBJECT!!')) {
			$action = 'new';
		} else if(strpos($v[1], '!!NO_OBJECT!!')) {
			$action = 'removed';
		} else {
			$action = 'changed';
		}
		$this->addDbMigrateItem($draft, $keyArray, $action);
	}

	/**
	 *
	 * Add specified migration item to draft migration
	 *
	 * @param $draft
	 * @param $action
	 * @param $keyArray
	 * @return DbMigrationPage
	 * @throws WireException
	 *
	 */
	protected function addDbMigrateItem(&$draft, $keyArray, $action) {
		/* @var $draft DbMigrationPage */
		$name = $keyArray[2];
		$type = $keyArray[0];
//		$repeaters = ['dbMigrateItem' => [[
//			'dbMigrateName' => $name,
//			'dbMigrateAction' => $action,
//			'dbMigrateType' => $type
//		]]];
		$newItem = [
			'dbMigrateName' => $name,
			'dbMigrateAction' => $action,
			'dbMigrateType' => $type
		];
		$repeaters = $draft->repeaters['dbMigrateItem'];
		$repeaters[] = $newItem;
//		$repeaters = array_unique($repeaters);
		$newRepeaters = [];
		foreach($repeaters as $repeater) {
			if(in_array($repeater, $newRepeaters)) continue;
			$newRepeaters[] = $repeater;
		}
		$draft->repeaters = ['dbMigrateItem' => $newRepeaters];
		$this->bd($draft, 'draft after addDbMigrateItem');
//		$draft->setAndSaveRepeaters($repeaters, 'new', $draft, $this->first);  // remove other repeaters on first setting...
//		$this->first = false;  // ...but not thereafter
		return $draft;
	}

	/**
	 * Config inputfields
	 *
	 * @param InputfieldWrapper $inputfields
	 * @throws WireException
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$modules = $this->wire()->modules;

		// Load custom CSS and JS
		$config = $this->wire()->config;
		$info = $this->moduleInfo();
		$version = $info['version'];
		$config->styles->add($config->urls->$this . "{$this}.css?v=$version");
		$config->scripts->add($config->urls->$this . "{$this}.js?v=$version");

		$moduleUrl = $this->wire()->urls->siteModules . 'ProcessDbMigrate/';

		$help = self::helpPopout('Popout indexed help', true);

		/* @var InputfieldCheckbox $f */
		$f = $modules->InputfieldCheckbox;
		$f_name = 'enable_dbMigrate';
		$f->name = $f_name;
		$f->label = $this->_('Enable DbMigrate');
		$f->description = $this->_('Uncheck to completely disable ProcessDbMigrate');
		$f->notes = $this->_('Unchecking will hide all features, even for superusers. Use this on the live site unless you wish to install migrations.');
		$f->columnWidth = 50;
		$f->value = $this->$f_name;
		$f->checked = ($f->value == 1) ? 'checked' : '';
		$inputfields->add($f);

		/* @var InputfieldCheckbox $f */
		$f = $modules->InputfieldCheckbox;
		$f_name = 'suppress_hooks';
		$f->name = $f_name;
		$f->label = $this->_('Suppress hooks');
		$f->description = $this->_('Suppress hooks and messages');
		$f->notes = $this->_('This will disable some of the DbMigrate functionality, but will speed up the site when migrations are not needed. 
		Use this (with the module enabled) if you want to see migrations but not update or use them');
		$f->columnWidth = 50;
		$f->value = $this->$f_name;
		$f->checked = ($f->value == 1) ? 'checked' : '';
		$inputfields->add($f);

		// Database naming fieldset
		/** @var InputfieldFieldset $dbName */
		$dbName = $this->wire(new InputfieldFieldset());
		$dbName->label = $this->_('Database naming');
		$dbName->columnWidth = 100;
		$inputfields->add($dbName);

		/* @var InputfieldText $f */
		$f = $modules->InputfieldText;
		$f_name = 'database_name';
		$f->name = $f_name;
		$f->label = $this->_('Database name');
		$f->description = $this->_('Optional name for this database - to tag migrations exported from it.');
		$f->notes = $this->_('If used, any migrations exported from this database will be tagged with its name. 
			Imported migrations tagged with this name will be treated as exportable, not installable (see help for more detail).');
		$f->columnWidth = 34;
		$f->value = $this->$f_name;
		$dbName->add($f);

		/* @var InputfieldCheckbox $f */
		$f = $modules->InputfieldCheckbox;
		$f_name = 'append_env';
		$f->name = $f_name;
		$f->label = $this->_('Append environment to database name');
		$f->description = $this->_('Use {database name}_{environment name} to differentiate between environments');
		$f->notes = $this->_('The concatenated name will be used in notices and to tag migrations. Thus the same "database name" can be used
								in the development and production databases, permitting importing of the latter without needing to change the name. 
								The environment name must be set in the config.php file as $config->dbMigrateEnv.');
		$f->columnWidth = 33;
		$f->value = $this->$f_name;
		$f->checked = ($f->value == 1) ? 'checked' : '';
		$dbName->add($f);

		/* @var InputfieldCheckbox $f */
		$f = $modules->InputfieldCheckbox;
		$f_name = 'show_name';
		$f->name = $f_name;
		$f->label = $this->_('Show database name in notice');
		$f->description = $this->_('Display the database name as a notice in every admin page.');
		$f->notes = $this->_('Only displayed if user is superuser or has admin-dbMigrate permission');
		$f->columnWidth = 33;
		$f->value = $this->$f_name;
		$f->checked = ($f->value == 1) ? 'checked' : '';
		$dbName->add($f);

		/*
		 * TRACKING IS NOW IN THE MIGRATION PAGE (post version 2.0.0)
		 */
//		// Tracking fieldset
//		/** @var InputfieldFieldset $tracking */
//		$tracking = $this->wire(new InputfieldFieldset());
//		$tracking->label = $this->_('Scope of change tracking');
//		$tracking->description = $this->_('Use selectors to define scope of object changes to be tracked');
//		$tracking->notes = $this->_('If you do not enter a selector then no changes will be tracked. Enter id>0 to (potentially) track everything.');
//		$tracking->columnWidth = 100;
//		$inputfields->add($tracking);
//
//		/* @var InputfieldTextarea $f */
//		$f = $modules->InputfieldText;
//		$f_name = 'field_tracking';
//		$f->name = $f_name;
//		$f->label = $this->_('Fields to track');
//		$f->description = $this->_('Use selector to specify fields to track');
//		$f->notes = $this->_('In addition, any excluded fields and dbMigrate fields will not be tracked');
//		$f->columnWidth = 34;
//		$f->value = $this->$f_name;
//		$tracking->add($f);
//
//		/* @var InputfieldTextarea $f */
//		$f = $modules->InputfieldText;
//		$f_name = 'template_tracking';
//		$f->name = $f_name;
//		$f->label = $this->_('Templates to track');
//		$f->description = $this->_('Use selector to specify templates to track');
//		$f->notes = $this->_('In addition, any dbMigrate templates will not be tracked');
//		$f->notes = $this->_('');
//		$f->columnWidth = 33;
//		$f->value = $this->$f_name;
//		$tracking->add($f);
//
//		/* @var InputfieldTextarea $f */
//		$f = $modules->InputfieldText;
//		$f_name = 'page_tracking';
//		$f->name = $f_name;
//		$f->label = $this->_('Pages to track');
//		$f->description = $this->_('Use selector to specify pages to track');
//		$f->notes = $this->_("Typically these will be pages holding settings etc., not normal user-updated pages");
//		$f->columnWidth = 33;
//		$f->value = $this->$f_name;
//		$tracking->add($f);

		// Exclusions fieldset
		/** @var InputfieldFieldset $exclusions */
		$exclusions = $this->wire(new InputfieldFieldset());
		$exclusions->label = $this->_('Global exclusions');
		$exclusions->notes = $this->_('Exclusions specified here will apply to all migrations');
		$exclusions->columnWidth = 100;
		$inputfields->add($exclusions);

		/* @var InputfieldTextarea $f */
		$f = $modules->InputfieldTextarea;
		$f_name = 'exclude_fieldtypes';
		$f->name = $f_name;
		$f->label = $this->_('Exclude Field types');
		$f->description = $this->_('Field types that are selected here will be excluded from page migrations. Enter the field type as text, (comma-separated for multiple types).');
		$f->notes = $this->_('DbMigrateRuntime, RuntimeMarkup and RuntimeOnly field types are excluded anyway as they do not hold data.'); //RuntimeMarkup and RuntimeOnly are names
		$f->columnWidth = 34;
		$f->value = $this->$f_name;
		$exclusions->add($f);

		/* @var InputfieldTextarea $f */
		$f = $modules->InputfieldTextarea;
		$f_name = 'exclude_fieldnames';
		$f->name = $f_name;
		$f->label = $this->_('Exclude Fields');
		$f->description = $this->_('Fields that are selected here will be excluded from page migrations. Enter the field name as text, (comma-separated for multiple field names).');
		$f->notes = $this->_('For example fields which do not hold data.');
		$f->columnWidth = 33;
		$f->value = $this->$f_name;
		$exclusions->add($f);

		/* @var InputfieldTextarea $f */
		$f = $modules->InputfieldTextarea;
		$f_name = 'exclude_attributes';
		$f->name = $f_name;
		$f->label = $this->_('Exclude Attributes');
		$f->description = $this->_('Attributes that are selected here will be excluded from template and field migrations. Enter the attribute name as text, (comma-separated for multiple attributes).');
		$f->notes = $this->_("For example differences in CKEditor plugins that you don't want reported.");
		$f->columnWidth = 33;
		$f->value = $this->$f_name;
		$exclusions->add($f);

		/* @var InputfieldCheckbox $f */
		$f = $modules->InputfieldCheckbox;
		$f_name = 'auto_install';
		$f->name = $f_name;
		$f->label = $this->_('Auto-install bootstrap?');
		$f->description = $this->_('Automatically install bootstrap on upgrade or other change.');
		$f->notes = $this->_('Leave checked in normal use. Deselect it if you wish to preview any bootstrap changes before installing them.');
		$f->columnWidth = 50;
		$f->value = $this->$f_name;
		$f->checked = ($f->value == 1) ? 'checked' : '';
		$inputfields->add($f);

		/* @var InputfieldCheckbox $f */
		$f = $modules->InputfieldCheckbox;
		$f_name = 'prevent_overlap';
		$f->name = $f_name;
		$f->label = $this->_('Prevent conflicting saves?');
		$f->description = $this->_('Any changes to objects in a target database which are within the scope of active installable migrations will be prevented.');
		$f->notes = $this->_('Leave checked in normal use. Deselect if it causes problems - but be aware that this may affect the ability to uninstall the related migration(s). Note that a migration is deemed to be "active" after the first installation.');
		$f->columnWidth = 50;
		$f->value = $this->$f_name;
		$f->checked = ($f->value == 1) ? 'checked' : '';
		$inputfields->add($f);

		/* @var InputfieldInteger $f */
		$f = $modules->InputfieldInteger;
		$f_name = 'install_repeats';
		$f->name = $f_name;
		$f->label = $this->_('Number of installation attempts to make (min 1, default 3, max 5)');
		$f->description = $this->_('Enter the number of repeated installation attempts to make before giving up.');
		$f->notes = $this->_("Multiple installation attempts may be needed if there are circular references between migration items. \n
		If it can be installed in fewer attempts then it will be. \n
		If it cannot be installed in the number of attempts specified then an error message will be shown and the installation will be incomplete.");
		$f->columnWidth = 100;
		$f->value = $this->$f_name;
		$f->min = 1;
		$f->max = 5;
		$inputfields->add($f);

		/* @var InputfieldMarkup $f */
		$f = $modules->InputfieldMarkup;
		$f_name = 'help';
		$f->name = $f_name;
		$f->label = $this->_('Help');
		$f->description = $this->_('');
		$f->notes = $this->_('');
		$f->columnWidth = 100;
		$f->value = $help;
		$f->collapsed = Inputfield::collapsedYes;
		$inputfields->add($f);

	}

	/*********************
	 ******* HOOKS********
	 ********************/


	/**
	 * Before save or moved actions:
	 * Disallow saving of pages which are inside the scope of unlocked installable migrations
	 *
	 * @param HookEvent $event
	 * @param string $action 'move' or 'save'
	 * @throws WireException
	 *
	 */
	protected function beforeSave(HookEvent $event) {
		/*
		 * This hook only operates on pages saved outside the installPages method
		 */
		$p = $event->arguments(0);
		// Ignore dbMigrate pages
		$t = $this->wire()->templates->get($p->template);
		if(!$p || !$p->id || !$t || $t->hasTag('dbMigrate')) {
			if($t && $t->name == 'repeater_dbMigrateItem') $p->of(false); // enable saving of dbMigrateItem repeater items
			return;
		}
		//
		$this->bd($event, 'event in beforeSave');
		if($this->trackingMigration) {
//			$oldPage = $this->pages()->getFresh($p->id); // No longer needed - done as part of data gathering
			if($p instanceof RepeaterMatrixPage || $p instanceof RepeaterPage) {
				if($p->parentPrevious && $p->parentPrevious->path() != $p->parent()->path()) {
					$this->bd(['prevName' => $p->namePrevious, 'name' => $p->name, 'prevParent' => $p->parentPrevious->path(), 'parent' => $p->parent()->path()], 'compare paths');
					$this->error(sprintf($this->_('Changes to id %s not saved. ProcessDbMigrate cannot track changes to repeater page paths. Amend repeaters from their host page, not directly, when "log changes" is enabled'), $p->id));
					$event->replace = true;
					$event->return = false;
					return;
				}
			}
			$this->setPageMeta($p);
		}
		if($this->prevent_overlap) { // module config setting
			if(!$this->wire()->session->get('dbMigrate_install') &&
				!$this->wire()->session->get('dbMigrate_removeItems') &&
				!$this->wire()->session->get('dbMigrate_exportDataMod') &&
				!$this->wire()->session->get('dbMigrate_bypassSaveHook')) { // allow saves as part of the migration installation process!
				$action = $event->method; // 'save' or 'moved'
				$action = ($action == 'save') ? 'save' : 'move';
				$this->bd($p, $action);
				$conflict = $this->preventConflict($p, $action, 'page');
				$this->bd($conflict, 'conflict');
				if($conflict) {
					$this->error($conflict);
					$event->replace = true;
					$event->return = false;
				}
			}
		}
	}

	protected function beforeDeletePage($event) {
		$page = $event->arguments(0);
		if($page instanceof RepeaterMatrixPage || $page instanceof RepeaterPage) {
			$caller = DEBUG::backtrace()[0]['file'];
			if(strpos($caller, 'ProcessPageEdit') !== false) {
				// delete was called directly from page edit, not via FieldtypeRepeater
				$this->error(sprintf($this->_('Id %s not deleted. ProcessDbMigrate cannot track changes to direct deletions of repeater pages. Amend repeaters from their host page, not directly, when "log changes" is enabled'), $page->id));
				$event->replace = true;
				$event->return = false;
			}
		}
	}

	/**
	 * Set page meta data for tracking
	 *
	 * @param $page
	 * @param $overrideScope
	 * @return void
	 * @throws WireException
	 */
	protected function setPageMeta($page, $overrideScope = false) {
		if(!$page || !$page->id) return;
		$migration = $this->trackingMigration;
		if(!$migration || !$migration->id) return;
		if($migration->meta()->get("current_page_{$page->id}")) return;
		$scopedObjects = $this->wire()->pages->find($migration->dbMigratePageTracking); //NB May not include a new page
		$matches = false;
		// See if page meets criteria, even if not found
		$matches = ($page->matches($migration->dbMigratePageTracking));
		if($page instanceof RepeaterMatrixPage || $page instanceof RepeaterPage) {
			$rootParent = $page->getForPageRoot();
$this->bd([$rootParent, $rootParent->motif_layout_components], 'root parent, components -  in setPageMeta');
			if($rootParent->matches($migration->dbMigratePageTracking)) {
				$matches = true;
				// If repeater was edited directly then we might not have the 'current' data for the parent
				if(!$migration->meta()->get("current_page_{$rootParent->id}")) {
//					$oldRoot = $this->pages()->getFresh($rootParent->id); // NB I don't think this works. Only the non-repeater fields are fresh - the repeater items seem to be the cached values NOW DONE in getPageExportData
					$this->setPageMeta($rootParent);
				}
			}
		}
		$pagePath = $page->path();
		// Include any items already in the migration as being in scope
		if($migration->dbMigrateItem->has("dbMigrateName={$pagePath}")) $overrideScope = true;
		if(($scopedObjects->has($page) || $matches || $overrideScope)) {
			if(!$migration->meta('installable') && !$migration->meta('locked') && $migration->dbMigrateLogChanges == 1) { // 1 is 'Log changes'
				if($page->id) {
					$objectData = $this->getPageExportData($migration, $page, true);  // $fresh = true means that fresh data from DB will be used throughout (including for repeater fields)
					$this->bd($objectData, "object data for page {$page->id}");
					$migration->meta()->set("current_page_{$page->id}", $objectData);
				}
			}
		}
		$this->bd($migration->meta()->getArray(), 'meta at end of setpagemeta');
	}

	/**
	 * After save actions:
	 * Create help file from page
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	protected function afterSave(HookEvent $event) {
		$p = $event->arguments(0);
		if($p->template == 'DbMigrateHelp') {
			$this->bd($p, 'after save hook');
			$origText = $p->dbMigrateAdditionalDetails;
			$p->export = true;  // set temp field so that render processing is correct for exported version of page (used in the template file DbMigrateHelp.php)
			$html = $p->render();
			$this->bd($html, 'html');
			file_put_contents($this->modulePath . 'help.html', $html);
			$text = $p->dbMigrateAdditionalDetails;
			file_put_contents($this->modulePath . 'helpText.html', $text);
			$this->bd($origText, 'origtext');
			$p->dbMigrateAdditionalDetails = $origText;
			$this->bd($p->dbMigrateAdditionalDetails, '$p->dbMigrateAdditionalDetails');
			$p->export = false;
		}
	}


	/**
	 * Before save template actions:
	 * Disallow saving of templates which are inside the scope of unlocked installable migrations
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	protected function beforeSaveTemplate(HookEvent $event) {
		/*
		 * This hook only operates on pages saved outside the installTemplates method
		 */
		$t = $event->arguments(0);
		// Ignore dbMigrate templates
		if($t->hasTag('dbMigrate')) return;
		$this->bd('in beforeSaveTemplate hook');

		if($this->prevent_overlap) { //config setting
			if(!$this->wire()->session->get('dbMigrate_install') and !$this->wire()->session->get('dbMigrate_removeItems')) {
				$this->bd($t, 't');
				$conflict = $this->preventConflict($t, 'save', 'template');
				if($conflict) {
					$this->error($conflict);
					$event->replace = true;
					$event->return = false;
					return;
				}
			}
		}
	}


	/**
	 * Before save field actions:
	 * Disallow saving of templates which are inside the scope of unlocked installable migrations
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	protected function beforeSaveField(HookEvent $event) {
		/*
		 * This hook only operates on pages saved outside the installFields method
		 */
		$this->bd('in beforeSaveField hook');
		if($this->prevent_overlap) { // moduleconfig setting
			if(!$this->wire()->session->get('dbMigrate_install') and !$this->wire()->session->get('dbMigrate_removeItems')) {
				$f = $event->arguments(0);
				$conflict = $this->preventConflict($f, 'save', 'field');
				if($conflict) {
					$this->error($conflict);
					$event->replace = true;
					$event->return = false;
				}
			}
		}

	}


	/**
	 * After page edit build form:
	 * Display warning re saving of pages which are inside the scope of unlocked installable migrations
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	protected function afterBuildFormContent(HookEvent $event) {
		$page = page();
		$type = 'page';
		if($page and $page->template == 'admin') {
			$pId = wire()->input->get('id');
			$page = wire('pages')->get($pId);
		}
		if(!$page || !$page->id) return;
		if($page->template != 'DbMigration') return;
		if(!$this->enable_dbMigrate) {
			$event->replace = true;
			$msg = new InputfieldMarkup();
			$msg->markupText = "<span style='font-weight: bold'> Module ProcessDbMigrate is not enabled. It can be enabled in the module settings. </span>";
			$event->return = $msg;
			return;
		}

		if(!$this->suppress_hooks) {
			$conflict = $this->preventConflict($page, 'edit', $type);
			if($conflict) {
				$this->warning($conflict);
			}
		}

		// Amend layout for installable migrations
		if($page->meta('installable')) {
			$form = $event->return;
			$inputfield = $form->getChildByName('dbMigrateLogChanges');
			if($inputfield) {
				$inputfield->collapsed = Inputfield::collapsedHidden;
				$this->bd($inputfield, 'inputfield logchanges Y');
			}
			$inputfield2 = $form->getChildByName('dbMigrateRuntimeAction');
			if($inputfield2) {
				$inputfield2->columnWidth = 100;
				$this->bd($inputfield2, 'inputfield action Y');
			}
			$inputfield3 = $form->getChildByName('dbMigrateItem');
			if($inputfield3) {
				$this->bd($inputfield3, 'repeater if');
				$inputfield3->notes = 'These items are automatically created and cannot be added to, deleted or sorted. Any changes must be in the source database.';
				// Do not allow addition or deletion of items
				$fixedNumber = $inputfield3->value->count();
				$inputfield3->repeaterMaxItems = $fixedNumber;
				$inputfield3->repeaterMinItems = $fixedNumber;
			}
		}

	}

	/**
	 * Before page edit build form:
	 * Load saved text into help field
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	protected function beforePageEditExecute(HookEvent $event) {
//		$page = page();
//		if ($page and $page->template == 'admin') {
//			$pId = wire()->input->get('id');
//			$page = wire('pages')->get($pId);
//		}
//		if(!$event->object) return;
		$this->bd($event->object, 'event object in beforePageEditExecute');
		$id = $this->wire('input')->get->id;
		if(!$id) return;
		$id = $this->wire('sanitizer')->int($id);
		if($id <= 0) return;
		$page = $this->wire('pages')->get($id);

		/*
		 * Make sure all the assets are there for a page with repeaters
		 * NB This needs to be placed here, rather than in the general ready() method so that it only operates on page edit, not save etc.
		 */
		$this->getInputfieldAssets($page);


		// Help text is updated from module file even if page exists  - it should be the same as the saved value unless changed externally (e.g. update without version change)
		if($page and $page->template == 'DbMigrateHelp') {
			if(file_exists($this->modulePath . 'helpText.html')) {
				$page->dbMigrateAdditionalDetails = file_get_contents($this->modulePath . 'helpText.html');
			}
		}
	}

	/**
	 * After template edit build form:
	 * Display warning re saving of templates which are inside the scope of unlocked installable migrations
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	protected function afterTemplateBuildEditForm(HookEvent $event) {
		$template = $event->arguments(0);
		$type = 'template';
		$conflict = $this->preventConflict($template, 'edit', $type);
		if($conflict) {
			$this->warning($conflict);
		}
	}

	/**
	 * After field edit build form:
	 * Display warning re saving of templates which are inside the scope of unlocked installable migrations
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	protected function afterFieldBuildEditForm(HookEvent $event) {
		$object = $event->object;
		if($object and get_class($object) == 'ProcessWire\ProcessField') {
			$fId = wire()->input->get('id');
			$field = wire('fields')->get($fId);
			$type = 'field';
			$this->bd($field, 'PREVENT FIELD CONFLICT');
			$conflict = $this->preventConflict($field, 'edit', $type);
			if($conflict) {
				$this->warning($conflict);
			}
		}
	}

	/**
	 * After fieldgroup edit build form,svae page, save field and save template:
	 * Display warning re saving of templates which are inside the scope of unlocked installable migrations
	 *
	 * @param $object
	 * @param $action
	 * @param $type
	 * @return string|null
	 * @throws WireException
	 */
	protected function preventConflict(&$object, $action, $type = 'page') {
		$conflict = null;
		if(!$object) return null;
		$name = ($type == 'page') ? $object->path : $object->name;
		if($action == 'save' && $type == 'page' && isset($object->parentPrevious) && $object->parentPrevious != $object->parent) {
			$action = 'move';
			$name = $object->parentPrevious->path . $object->name . '/';  // need the path before it was moved
			$this->bd($name, 'revised name');
		}
		foreach($this->migrations->find("template={$this->migrationTemplate}, include=all") as $migration) {
			/* @var $migration DbMigrationPage */
			$this->bd($migration->meta(), 'meta');
			if($migration->conflictFree()) continue;
			$migrationNames = $migration->itemNames($type);

			$this->bd(['name' => $name, 'mignames' => $migrationNames], 'names');
			if(in_array($name, $migrationNames)) {
				$this->bd([debug_backtrace(), DEBUG::backtrace()], 'backtrace');
				if($action == 'edit') {
					$conflict = sprintf($this->_('This %1$s %2$s is the target of an active migration: %3$s. Do not edit it here - edit in the source database instead.'), $type, $name, $migration->name);
				} else {  // $action == 'save' or 'move'
					$conflict = sprintf($this->_('Unable to %5$s %1$s %2$s as it is the target of an active migration: %3$s. See %4$s module settings.'), $type, $name, $migration->name, self::moduleInfo()['title'], $action);
					$this->bd(DEBUG::backtrace(), 'PW backtrace');
					if($action == 'move') {
						// Because this hook operates after the move, we need to reverse the move if it conflicts with an open migration
						// NB However, it is not necessary in this context because the calling hook sets $event->replace = true so the save is not executed.
						//$object->parent = $object->parentPrevious;
						// modified $object will be returned to the calling hook as it is an argument passed by reference - so no need to save it here
					}
				}
//				throw new WirePermissionException($conflict);
//				break;
			}
		}
		$this->bd(['object' => $object, 'session' => $this->wire()->session], 'PREVENT ' . $type . ' CONFLICT');
		return $conflict;
	}

	/**
	 * Field::exportData has a bug in the following two lines
	 *
	 * $typeData = $this->type->exportConfigData($this, $data);
	 * $data = array_merge($data, $typeData);
	 *
	 * This code causes the $typedata from the default config settings to over-ride the actual field data
	 * The 2nd line should be $data = array_merge($typeData, $data);
	 *
	 * The following hook (before Fieldtype::exportConfigData) is designed to fix this by merging the data the correct way
	 *   before passing it back to Field::exportData
	 *
	 * ToDo A fix for the core code would be better!
	 *
	 * @param HookEvent $event
	 *
	 * @throws WireException
	 */

	public function afterExportConfigData(HookEvent $event) {
		$this->bd('RUNNING afterExportConfigData hook');
		$data = $event->arguments(0)->data;
		$value = $event->return;
		$newData = array_merge($value, $data);
		$event->return = $newData;
	}


	/****************************************************************************************************************************
	 ** Hooks below are to detect changes in templates/fields/pages for logging to an open migration (with log changes enabled) **
	 *
	 * Note that, as well as hooking the 'normal' field and template add/save/delete events, there are a number of special cases:
	 * when fieldgroups are saved, the template is not necessarily saved, so the saved() event has to be triggered  on the
	 * template for its hook to operate
	 * when fieldgroup contexts are changed (e.g. in repeater and repeater matrix subfields), then there is no $fieldgroup->save()
	 * as the db is updated directly, so it is necessary to hook Fields::saveFieldgroupContext()
	 *****************************************************************************************************************************/

	/**
	 * To get the type of the object and return (by reference) the name, type key and tracking name
	 *
	 * @param $object Templates|Fields|Pages
	 * @param $type int|null 1=field 2=template
	 * @param $typeName string template|field
	 * @param $tracking string dbMigrateTemplateTracking|dbMigrateFieldTracking
	 * @return void
	 */
	protected function objectType($event, $object, &$type, &$typeName, &$tracking) {
		$this->bd($event, 'event in objectType');
		if(!$object) return;
		//$this->wire()->log->save('debug', 'Object type: ' . $object->type . ' - Progress A');
		switch(true) {
			case (wireInstanceOf($object, 'Templates') || wireInstanceOf($object, 'Template')):
				$typeName = 'templates';
				$tracking = 'dbMigrateTemplateTracking';
				$type = 2;
				break;
			case (wireInstanceOf($object, 'Fields') || wireInstanceOf($object, 'Field')):
				$typeName = 'fields';
				$tracking = 'dbMigrateFieldTracking';
				$type = 1;
				break;
			case (wireInstanceOf($object, 'Fieldgroups') || wireInstanceOf($object, 'Fieldgroup')):
				$tpls = $event->arguments(0)->getTemplates();
				foreach($tpls as $tpl) {
					$this->bd($event->method, 'event->method');
					switch($event->method) {
						case 'saved':
							$this->wire()->templates->saved($tpl); // to ensure related template hook is triggered
							break;
						case 'save' :
							$this->wire()->templates->saveReady($tpl);
							break;
					}
				}
				break;
			case (wireInstanceOf($object, 'Pages') || wireInstanceOf($object, 'Page')):
				$typeName = 'pages';
				$tracking = 'dbMigratePageTracking';
				$type = 3;
				break;
			default:
				return;
		}
		return;
	}

	/**
	 * Get the (first) migration page with log changes enabled
	 *
	 * @return DbMigrationPage|null
	 */
	protected function getTrackingMigration() {
		$migrations = $this->pages()->find("template={$this->migrationTemplate}");
		foreach($migrations as $migration) {
			if(!$migration || !$migration->id) continue;
			if(!$migration->meta('installable') && !$migration->meta('locked') && $migration->dbMigrateLogChanges != 1
				&& ($migration->dbMigrateFieldTracking || $migration->dbMigrateTemplateTracking || $migration->dbMigratePageTracking)) {
				$this->wire()->session->error($migration->name . $this->_(": Migration is open with tracking scope, but log changes is not enabled.\n
			 Changes to this migration will NOT be logged. If you wish to log a recent change, you may need to reverse it, enable 'log changes' and redo the change.\n
			 To remove this message, lock the migration or enable 'log changes' and save the migration."));
			}
			if(!$migration->meta('installable') && !$migration->meta('locked') && $migration->dbMigrateLogChanges == 1) { // 1 is 'Log changes' There should only be one such migration
				/* @var $migration DbMigrationPage */
				return $migration;
			}
		}
		return null;
	}

	/**
	 * Called by hooks on saveReady/renameReady WireSaveableItems
	 * Set the various metadata:
	 * •    The ‘current’ data for the object (i.e. before any changes)
	 * •    The ‘changed’ (‘object’) data for the object
	 * •    The ‘base’ data for the object when the related migration item was first created
	 *
	 * @param HookEvent $event
	 * @return void
	 * @throws WireException
	 */
	protected function hookMetaSaveable(HookEvent $event) {
		$type = strtolower(wireClassName($event->object));
		$method = $event->method;
		$item = $event->arguments(0);
		$migration = $this->getTrackingMigration();

		if(!$migration) return;

		$this->setMetaSaveable($event, $type, $method, $item, $migration);
	}

	/**
	 * Called by hooks on saveReady/renameReady WireSaveableItems via hookMetaSaveable()
	 *  Set the various metadata:
	 *  •    The ‘current’ data for the object (i.e. before any changes)
	 *  •    The ‘changed’ (‘object’) data for the object
	 *  •    The ‘base’ data for the object when the related migration item was first created
	 *
	 * @param $event
	 * @param $type
	 * @param $method
	 * @param $item
	 * @param $migration
	 * @param $oldFieldgroup
	 * @return void
	 * @throws WireException
	 */
	protected function setMetaSaveable($event, $type, $method, $item, $migration, $oldFieldgroup = null) {
		$object = ($type == 'fields') ? 'field' : (($type == 'templates') ? 'template' : null);
		if($object && $item->id) {
			if(wireInstanceOf($item, 'Field') && !$this->trackingField) $this->set('trackingField', $item); // This will capture the FIRST tracked object after ready()
			// if $item->id is 0 then the meta is set by the 'added' case in handleSaveableItem (after the save, so it has an id then)
			$meta = $migration->meta()->get("current_{$object}_{$item->id}");
			if($meta !== null) return; // already got it
		}
		$this->bd([$event, $type, $item], 'event, type, item in setmeta');
		$this->bd($method, 'method in setmeta');
		if($type == 'fieldgroups') {
			// Not sure this session var is necessary - it was an attempt to fix a problem that has been fixed differently
			$processedFieldgroup = $this->wire()->session->get("processed_fieldgroup");
			if(in_array($item->id, $processedFieldgroup)) return;
			$processedFieldgroup[] = $item->id;
			$this->wire()->session->set("processed_fieldgroup", $processedFieldgroup);
			$oldFieldgroup = $this->wire($type)->getFreshSaveableItem($item);
			$this->bd($oldFieldgroup, 'oldFieldgroup 1');
			$tpls = $item->getTemplates();
			foreach($tpls as $tpl) {
				if(!in_array($tpl->id, $this->wire()->session->get('processed'))) {
//					$this->setMetaSaveable($event, 'templates', $method, $tpl, $migration, $oldFieldgroup);
					$this->wire()->templates->saveReady($tpl);
				}
			}
		} else {
			if($item->id > 0) {
				$oldItem = $this->wire($type)->getFreshSaveableItem($item);
//				if($type == 'templates' && $oldFieldgroup) {
//					$this->bd($oldFieldgroup, 'oldFieldgroup 2');
//					$oldItem->setFieldgroup($oldFieldgroup);
//				}
				$same = ($item == $oldItem);
				$this->bd(['item' => $item, 'oldItem' => $oldItem, 'same' => $same], "item, oldItem in after saveReady $type");
				if($oldItem) {
					$this->getObjectData($event, $oldItem);
				} else {
					$migration->meta()->set("current_{$object}_{$item->id}", []);
				}
			}
		}
	}

	/**
	 * Called by hook on FieldtypeRepeater::deleteField
	 * To track which repeater fields are being deleted
	 *
	 * @param $event
	 * @return void
	 */
	protected function setMetaDeleteRepeater($event) {
		$field = $event->arguments(0);
		$migration = $this->trackingMigration;
		if($migration && $migration->id) {
			$objectData = $this->getExportDataMod($field);
			$migration->meta()->set("current_field_{$field->id}", $objectData);
			$this->set('trackingField', $field);
		}
	}

	/**
	 * Called by hook on InputfieldPageTable::render and InputfieldPageTableAjax::checkAjax
	 * To track which host pages containing a Page Table field is are being edited
	 *
	 * @param $event
	 * @return void
	 */
	protected function handlePageTable($event) {
		$this->bd('In handlePageTable');
		$object = $event->object;
		if(wireInstanceOf($object, 'InputfieldPageTableAjax')) {
			$id = (int)$object->input->get('id');
			if($id) {
//				$hostPage = $this->pages()->get($id);
//				$this->bd($hostPage, 'hostPage in handlePageTable');
				// If there is a tracking migration then save the host page with nohooks and quiet
				$this->bd($this->trackingMigration, 'trackingMigration in handlePageTable');
				if($this->trackingMigration && $object->input->get('InputfieldPageTableAdd')) {
					// set a session var with the host page id
					$this->session->set('pageTableHostId', $id);
					$this->bd($this->session->get('pageTableHostId'), 'set session var pageTableHostId');
//					$hostPage->of(false);
//					$this->setPageMeta($hostPage, true);
//					$this->addTrackedMigrationItem($hostPage, 3, 2, '', [], true);
				}
			}
		} else {
				// If the object is an InputfieldPageTable, get the session var and add a migration item for the host page
				if(wireInstanceOf($object, 'InputfieldPageTable')) {
					$hostId = $this->session->get('pageTableHostId');
					$this->bd($hostId, 'hostId in handlePageTable');
					if($this->trackingMigration && $hostId) {
						$hostPage = $this->pages()->get($hostId);
						$pathSelector = $hostPage->path;
						$this->bd($pathSelector, 'pathSelector');
						$inMigration = $this->trackingMigration->dbMigrateItem->find("dbMigrateName=$pathSelector");
						$this->bd($inMigration, 'inMigration');
						if(!$inMigration || $inMigration->count() == 0) {
							$this->setPageMeta($hostPage, true);
							$this->addTrackedMigrationItem($hostPage, 3, 2, '', [], true);
						}
						$this->session->remove('pageTableHostId');
					}
				}
			}

//		$sessionArray = [];
//		if(wireInstanceOf($object, 'InputfieldPageTable')) {
//			$input = $object->wire()->input;
//			$editId = (int)$input->get('id');
//			$sessionArray = ($this->session->get('pageTableHostId')) ?: [];
//			if(is_int($sessionArray)) $sessionArray = [$sessionArray];
//			$sessionArray[] = $editId;
//		} else if (wireInstanceOf($object, 'InputfieldPageTableAjax')) {
//			$this->bd('in checkAjax hook');
//			$this->log->save('debug', 'In checkAjax hook');
//			$sessionArray = $this->session->get('pageTableHostId');
//			if($sessionArray && count($sessionArray) > 0) $sessionArray = []; // array_pop($sessionArray);
//		}
//		$this->session->set('pageTableHostId', $sessionArray);
//		$this->bd([$object, $this->session->get('pageTableHostId')], 'set pageTableHostId: object, session var');
//		$this->bd(Debug::backtrace(), 'backtrace in handlePageTable');
	}

	/**
	 * Called by hooks on added/saved/renamed/deleteReady on WireSaveableItems and Pages
	 *
	 * @param $event
	 * @return void
	 * @throws WireException
	 */
	protected function handleSaveableHook($event) {
		Debug::saveTimer('from ready', 'to handleSaveableHook' . $event->object->name);
		$value = Debug::getSavedTimer('from ready');
		$this->bd($value, 'from ready timer');
		Debug::saveTimer('from hook', 'to handleSaveableHook for ' . $event->object->name);
		$value2 = Debug::getSavedTimer('from hook');
		$this->bd($value2, 'from hook timer');
		Debug::startTimer('from hook');
		$this->bd([$event->method, $event], 'handleSaveableHook event');
		$migration = $this->trackingMigration;
		if(!$migration || !$migration->id) return;
		$type = $typeName = $tracking = null;
		$object = $event->object;
		$this->objectType($event, $object, $type, $typeName, $tracking);
		if(!$type) return;
		$item = $event->arguments(0);
		$this->bd($item, 'item in handlesaveablehook');
		$this->bd(DEBUG::backtrace(), 'backtrace');
		// Ignore fields and templates which were created by DbMigrate and also any DbMigrate pages
		if($type == 1 && self::dbMigrateFields()->has($item)) return;
		if($type == 2 && self::dbMigrateTemplates()->has($item)) {
			$this->bd($item, 'dBMigrateTemplate');
			return;
		}
		$templateSelector = self::dbMigrateTemplateSelector();
		$this->bd($templateSelector, ' templateSelector');
		if($type == 3 && $item->matches($templateSelector)) return;
		$this->bd([$event->method, $event], 'wanted handleSaveableHook event');
		// Don't process items a second time
		$processed = $this->wire()->session->get('processed');
		$this->bd($processed, 'processed');
		if(in_array($item->id, $processed)) {
			return;
			// NB remove the commented lines below when all working ok - session var setting has moved to addTrackedMigrationItem
//		} else {
//			$processed[] = $item->id;
//			$this->wire()->session->set('processed', $processed);
		}
		// (session var will be reset in ready() )

		$itemName = ($type < 3) ? $item->name : $item->path;
		$oldName = '';
		$objType = substr($typeName, 0, -1); // fields -> field etc.
		switch($event->method) {
			case 'saved':
			case 'renamed':
				$action = 2; // 'Changed' action
				if($type < 3) {
					$oldName = $event->arguments(1);  // not sure this works, so it is caught later in addTrackedMigrationItem() by comparing $objectData and $currentData
				} else {
					$page = $event->arguments(0);
					$prevParentPath = ($page->parentPrevious) ? $page->parentPrevious->path() : $page->parent()->path();
					$prevName = ($page->namePrevious) ? $page->namePrevious : $page->name;
					$this->bd([$prevParentPath, $prevName], 'previous');
					if($prevParentPath == '') {   //page was site home page
						$oldName = '/';
					} else {
						$oldName = $prevParentPath . $prevName . '/';
					}
					$this->bd($oldName, 'oldName');
				}
				$oldName = ($oldName != $itemName) ? $oldName : '';
				break;
			case 'added':
				// set the meta here, rather than in setMetaSaveable or setPageMeta, because now we have the id
				$migration->meta()->set("current_{$objType}_{$item->id}", []);
				$metaArray = $migration->meta()->getArray();
				$action = 1;
				break;
			case 'deleteReady':
				$action = 3; // 'Removed' action
				break;
			default:
				$action = 0;
				break;
		}
		$scopedObjects = $this->wire()->$typeName->find($migration->$tracking); //NB May not include a new page
		$matches = false;
		$inMigration = new PageArray();
		$this->bd([$type, $action, $item, $migration->$tracking], 'type action item tracking');
		if($type == 3) {
			//$this->wire()->log->save('debug', $item->name . 'Progress 1');
			// If the page is just a repeater parent for repeater field that has been added/changed then omit it as installation in target will recreate it
			if($this->trackingField && $item->name == FieldtypeRepeater::fieldPageNamePrefix . $this->trackingField->id) return; // FieldtypeRepeater::fieldPageNamePrefix is 'for_field_'
			// Also, if we are deleting a repeater parent for a field that is in the migration (and will be deleted after this page is deleted) then omit it for the same reason
			$fieldId = str_replace(FieldtypeRepeater::fieldPageNamePrefix, '', $item->name);
			//$dbMigrateItem = $migration->getFormatted('dbMigrateItem');
			$dbMigrateItem = $migration->dbMigrateItem->find("status=1");
			$newFields = $dbMigrateItem->find("dbMigrateType=1");
			foreach($newFields as $newField) {
				$sourceField = $this->pages()->get("name={$newField->dbMigrateName}");
				if($sourceField->id == $fieldId) return;
			}

			// See if page meets criteria, even if not found
			$matches = ($item->matches($migration->$tracking));
			//$this->wire()->log->save('debug', $item->name . 'Progress 2');
			// If a repeater page is changed directly, rather than via its 'getFor' parent, then the (root) parent will be treated as being changed (if it is inside the scope)
			if($item instanceof RepeaterMatrixPage || $item instanceof RepeaterPage) {
				$rootParent = $item->getForPageRoot();
				$this->bd($rootParent, 'root parent');
				if($rootParent->matches($migration->$tracking)) {
					// save the root so that it gets registered as the changed item, rather than the repeater page
					// this will also get the root's 'current' meta data and fetch any image sources and rte links for it
					$rootParent->of(false);
					$this->pages()->save($rootParent, ['noFields' => true]); // 'noFields' => true is to prevent any recursion
				}
			}
//			$this->wire()->log->save('debug', $item->name . 'Progress 2a');

			//if($matches) $this->bd($item, 'page matches tracking selector');

			// Include any pages that are already in the migration, although technically outside scope of logging
			$pagePath = $item->path();
			$pathSelector = ($oldName) ? "$pagePath|$oldName" : $pagePath;
			$inMigration = $migration->dbMigrateItem->find("dbMigrateName=$pathSelector");
			// Above was the following line. Not sure why, other than that, previously, I had not specified $migration->trackingMigration, so I just looked for any
			//$inaMigration = $this->pages()->find("template={$this->migrationTemplate}, dbMigrateItem.dbMigrateName=$pathSelector");
//			$this->wire()->log->save('debug', 'Progress 3');
		}
		$rteLinks = [];
		if($action > 0 && (($scopedObjects->has($item) || $matches || $inMigration->count() > 0))) {   // was $inaMigration
			if($type == 3) {
				//Process any pages which hold files used in RTE fields on the current page BEFORE processing this page
				$imageSources = $this->findRteImageSources($item);
				$this->bd($imageSources, 'save image sources');
				foreach($imageSources as $imageSource) {
					$this->setPageMeta($imageSource, true);
					$this->addTrackedMigrationItem($imageSource, 3, 2, '', [], true);
//					$this->wire()->log->save('debug', 'Progress 3a');
					$rteLinks[] = $imageSource;
				}
				// Also add info of other (non-image) links as they might be possible dependencies
				$rteLinks = array_merge($rteLinks, $this->findRteLinks($item)->explode(function($item) {
					return $item;
				})); // without the function, explode() returns null for each page
				//If the page is in a page table, include its parent/host page in the migration
				$this->bd($item, 'item in handleSaveableHook - check for page table');
//				$pageTableHost = $this->pageTableHosts($item);
//				if($pageTableHost) {
//					$this->bd($pageTableHost, 'host in handleSaveableHook');
//					$this->setPageMeta($pageTableHost, true);
//					$this->addTrackedMigrationItem($pageTableHost, 3, 2, '', [], true);

//				}
				$this->addTrackedMigrationItem($item, $type, $action, $oldName, $rteLinks);
				//$this->wire()->log->save('debug', 'Progress 3b');
			}
			$this->addTrackedMigrationItem($item, $type, $action, $oldName);
		}
	}

	/**
	 * Called by hook on Fieldgroups::fieldRemoved
	 * Trigger a 'saved' action on the related template
	 *
	 * @param HookEvent $event
	 * @return void
	 * @throws WireException
	 */
	protected function afterFieldRemoved(HookEvent $event) {
		$this->bd($event, 'event in fieldgroups hook');
		$fieldgroup = $event->arguments(0);
		$field = $event->arguments(1);
		foreach($this->wire()->templates as $template) {
			if($template->fieldgroup->id !== $fieldgroup->id) continue;
			$this->bd([$template, $fieldgroup], 'template & fieldgroup');
			$this->wire()->templates->saved($template); // To trigger saved hook
		}
	}

	/**
	 * Called by hook before Fieldgroups::saveFieldgroupContext
	 * Trigger a 'saved' action on the related template
	 *
	 * @param HookEvent $event
	 * @return void
	 * @throws WireException
	 */
	protected function beforeFieldsSaveFieldgroupContext(HookEvent $event) {
		$field = $event->arguments(0);
		$fieldgroup = $event->arguments(1);
		$this->bd([$field, $fieldgroup], 'beforeFieldsSaveFieldgroupContext');
		$tpls = $fieldgroup->getTemplates();
		foreach($tpls as $tpl) {
			$this->wire()->templates->saveReady($tpl); // to ensure related template hook is triggered
		}
	}

	/**
	 * Called by hook after Fieldgroups::saveFieldgroupContext
	 * Trigger a 'saved' action on the related template
	 *
	 * @param HookEvent $event
	 * @return void
	 * @throws WireException
	 */
	protected function afterFieldsSaveFieldgroupContext(HookEvent $event) {
		$field = $event->arguments(0);
		$fieldgroup = $event->arguments(1);
		$this->bd([$field, $fieldgroup], 'afterFieldsSaveFieldgroupContext');
		$tpls = $fieldgroup->getTemplates();
		foreach($tpls as $tpl) {
			$this->wire()->templates->saved($tpl); // to ensure related template hook is triggered
		}
	}

	/**
	 * Called by hook after Fieldgroups::save
	 * Trigger a 'saved' action on the related template
	 *
	 * @param HookEvent $event
	 * @return void
	 * @throws WireException
	 */
	protected function afterFieldgroupsSave(HookEvent $event) {
		$fieldgroup = $event->arguments(0);
		$this->bd($fieldgroup, 'afterFieldgroupsSave');
		$tpls = $fieldgroup->getTemplates();
		foreach($tpls as $tpl) {
			$this->wire()->templates->saved($tpl); // to ensure related template hook is triggered
		}
	}

	/**
	 * Called by various methods
	 * To add a migration item to the tracking migration, as appropriate
	 *
	 * @param $item Template|Field
	 * @param $type int 1=field 2=template
	 * @param $action int 1=new 2=changed 3=removed
	 * @param $oldName string blank if name not changed
	 * @return void
	 */
	protected function addTrackedMigrationItem($item, $type, $action, $oldName = '', $rteLinks = [], $forceAction = false) {
		if(!$item) return;
//		$this->wire()->log->save('debug', 'Progress 4');
		if($type == 1) {
			$object = 'field';
		} else if($type == 2) {
			$object = 'template';
		} else if($type == 3) {
			$object = 'page';
		} else {
			return;
		}
		$itemName = ($type < 3) ? $item->name : $item->path;
		$this->bd(['item' => $item, 'type' => $type, 'action' => $action, 'oldName' => $oldName, 'rteLinks' => $rteLinks], 'In addMigrationItem');
		$this->bd([DEBUG::backtrace(), debug_backtrace()], 'backtrace addMigration');
		// get the non-installable migration page with 'log changes' enabled (there should only be one as any others are trapped on saving)
		$migration = $this->trackingMigration;
		if(!$migration || !$migration->id) return;
		/* @var $migration DbMigrationPage */
		$migration->of(false); // Needed for the case when saved() is called directly in afterFieldRemoved etc
		if(!$migration->meta('installable') && !$migration->meta('locked') && $migration->dbMigrateLogChanges == 1) { // 1 is 'Log changes'
//			$this->wire()->log->save('debug', 'Progress 5');
			$itemMatches = $this->itemMatches($migration, $item);
			$objectData = $this->setObjectData($migration, $item, $type);
			$currentData = ($migration->meta("current_{$object}_{$item->id}")) ?: []; // The data for the object before it was changed
			$baseData = ($migration->meta("base_{$object}_{$item->id}")) ?: []; // The data for the object before the first recorded change
			$this->bd(['meta' => $migration->meta()->getArray(), 'new' => $objectData, 'current' => $currentData], 'meta, new and current data');

			// but first exclude any objects that didn't really change (i.e. nothing changed that is relevant to dbMigrate)
			if(is_array($objectData) && is_array($currentData)) {
				$diff = $migration->array_compare($objectData, $currentData);
			} else {
				$diff = ($objectData == $currentData);
			}
			if(!$diff && !$forceAction && $action != 3) { // Exclude 'removed' action because it operates on deleteReady, so the object data will not have changed
				$this->bd($currentData, 'NO CHANGES');
				return;
			}
			$this->bd($diff, 'there is a diff so proceed');

			// setting processed after we have ascertained there is a difference to process
			$processed[] = $item->id;
			$this->wire()->session->set('processed', $processed);
			// (session var will be reset in ready() )


			//Catch any missed renames
			if($type < 3 && isset($objectData['name']) && isset($currentData['name']) && $objectData['name'] != $currentData['name']) {
				$oldName = $currentData['name'];
				$this->bd($oldName, 'old name');
			}

			$sourceData = array();
			$sourceData['id'] = $item->id;
			switch($type) {
				case 1: // fields
					$sourceData['source'] = 'field';
					$sourceData['type'] = $item->type->name;
					$sourceData['template_id'] = $item->template_id;
					$sourceData['parent_id'] = $item->parent_id;
					break;
				case 2: // templates
					$sourceData['source'] = 'template';
					$fields = $item->fieldgroup;
					$fieldArray = array();
					foreach($fields as $field) {
						$fieldData = array();
						$fieldData['id'] = $field->id;
						$fieldArray[] = $fieldData;
					}
					$sourceData['fields'] = $fieldArray;
					$childTemplates = $item->childTemplates;
					$childTemplateArray = array();
					foreach($childTemplates as $childTemplate) {
						$childTemplateData = array();
						$childTemplateData['id'] = $childTemplate;
						$childTemplateArray[] = $childTemplateData;
					}
					$sourceData['childTemplates'] = $childTemplateArray;
					$parentTemplates = $item->parentTemplates;
					$parentTemplateArray = array();
					foreach($parentTemplates as $parentTemplate) {
						$parentTemplateData = array();
						$parentTemplateData['id'] = $parentTemplate;
						$parentTemplateArray[] = $parentTemplateData;
					}
					$sourceData['parentTemplates'] = $parentTemplateArray;
					break;
				case 3: // pages
//					$this->wire()->log->save('debug', 'Progress 6');
					$sourceData['source'] = 'page';
					$sourceData['template_id'] = $item->template->id;
					$sourceData['parent_id'] = $item->parent()->id;
					$fields = $item->getFields();
					$pageArray = array();
					foreach($fields as $field) {
						if(in_array($field->type, ['FieldtypePage', 'FieldtypePageTable'])) {
							$pageRefs = $item->$field;
							if(!($pageRefs instanceof PageArray)) $pageRefs = [$pageRefs];
							foreach($pageRefs as $pageRef) {
								if($pageRef) {
									$pageData = array();
									$pageData['id'] = $pageRef->id;
									$pageArray[] = $pageData;
								}
							}
						}
					}
					$sourceData['pageRefs'] = $pageArray;
					$linkArray = [];
					foreach($rteLinks as $rteLink) {
						if($rteLink) $linkArray[] = ['id' => $rteLink->id];
					}
					$sourceData['rteLinks'] = $linkArray;
					break;
			}
//			$this->wire()->log->save('debug', 'Progress 7');
			$this->bd(['currentData' => $currentData, 'sourceData' => $sourceData], 'object data');
			if(!$currentData && $action == 2 && !$forceAction) {
				// action says it is changed, but it didn't exist before, so we want to keep it as a new item ($forceAction will override this)
				$action = 1;
			}

			// Case 1: Item exists with same fields - just update the source data
			if($migration->dbMigrateItem->has("dbMigrateName={$itemName}, dbMigrateOldName={$oldName}, dbMigrateType={$type}, dbMigrateAction={$action}")) {
				$this->bd($migration->dbMigrateItem->get("dbMigrateName={$itemName}, dbMigrateOldName={$oldName}, dbMigrateType={$type}, dbMigrateAction={$action}"), 'CASE 1');
				// If the item is now the same as its base state, remove it
				$this->bd(debug_backtrace(), 'backtrace at case 1');
				$this->bd([$objectData, $baseData], 'object & base data');
				$diff = $migration->array_compare($objectData, $baseData);
				$this->bd($diff, 'diff');
				$migrationItem = $migration->dbMigrateItem->get("dbMigrateName={$itemName}");
				$migrationItem->of(false);
				if(!$diff) {
					$this->removeMigrationItem($migration, $migrationItem, $object, $item);
				} else {
					$migrationItem->meta()->set('sourceData', $sourceData);
					$sourceData['dependencies'] = $migration->getDependencies($migrationItem, $item);
					$migrationItem->meta()->set('sourceData', $sourceData);
					$this->bd($sourceData, 'setting sourceData 1');
					$migration->dependencySort()->save(); // Need to save to ensure that sort occurs
				}
				return;
			}
			// Case 2: Item exists but something has changed - replace it
			if($migration->dbMigrateItem->has("dbMigrateName={$itemName}, dbMigrateType={$type}") ||
				$migration->dbMigrateItem->has("dbMigrateName={$oldName}, dbMigrateType={$type}") ||
				$itemMatches) {
				/* NB Considered if we can do away with the first 2 conditions above.
				 * However, $itemMatches is only set where the existing migration item has a meta('sourceData') - i.e. has been created by this tracking
				 * The other conditions will catch items that have been maually added
				 * ToDo Consider whether a source id meta can be added to all migration items (e.g. via a hook)?
				*/

				//// Case 2a: Name is the same
				if($migration->dbMigrateItem->has("dbMigrateName={$itemName}, dbMigrateType={$type}")) {
					$migrationItem = $migration->dbMigrateItem->get("dbMigrateName={$itemName}, dbMigrateType={$type}");
					$migrationItem->of(false);
					$this->bd($migrationItem, 'CASE 2a');
					//// Special case - deleting a new object, so just remove the item
					if($migrationItem->dbMigrateAction->id == 1 && $action == 3) {
						$this->removeMigrationItem($migration, $migrationItem, $object, $item);
						return;
					}
				}
				//// Case 2b: Name has changed
				if($migration->dbMigrateItem->has("dbMigrateName={$oldName}, dbMigrateType={$type}")) {
					$migrationItem = $migration->dbMigrateItem->get("dbMigrateName={$oldName}, dbMigrateType={$type}");
					$migrationItem->of(false);
					$migrationItem->dbMigrateName = $itemName;
					// For page types, check if there are any children in the migration and change their (path) names to match
					// NB this only works for children that were added by change tracking, not manually added children
					if($migrationItem->dbMigrateType->id == 3) {
						foreach($item->children() as $itemChild) {
							$itemChildMatches = $this->itemMatches($migration, $itemChild);
							if($itemChildMatches) {
								$migrationItemChild = $migration->dbMigrateItem->get("id=$itemChildMatches");
								if($migrationItemChild) {
									$migrationItemChild->setAndSave('dbMigrateName', $itemChild->path()); // should be the new path
									$this->bd($migrationItemChild->dbMigrateName, 'new child name');
									$migration->dependencySort()->save();
								}
							}
						}
					}
					$this->bd($migrationItem, 'CASE 2b');

					
					$migrationItem->dbMigrateAction = ($migrationItem->dbMigrateAction->id == 1 && $action != 3) ? $migrationItem->dbMigrateAction->id : $action;
					if($migrationItem->dbMigrateAction->id == 2 && !$migrationItem->dbMigrateOldName) {
						$migrationItem->dbMigrateOldName = $oldName;
						//Don't change old name if already exists (i.e. name has been changed before)
						//And don't add an old name if the item is a new one
					}

					//// Special case - deleting an existing object - reset the name and old name because we just want the one (original) name for removed objects
					if($action == 3 && $migrationItem->dbMigrateOldName) {
						$migrationItem->dbMigrateName = $migrationItem->dbMigrateOldName;
						$migrationItem->dbMigrateOldName = '';
						$this->bd($migrationItem, 'deleting existing object - use old name');
					}
					$this->bd($migrationItem, 'AAAA');
				}

				//// Case 2c A migration item for this item id exists but neither the name nor old name match
				/// (We could have changed the name in the API, for example)
				if($itemMatches) {
					$migrationItem = $migration->dbMigrateItem->get("id=$itemMatches");
					$migrationItem->of(false);
					if($migrationItem && $migrationItem->dbMigrateName != $itemName && $migrationItem->dbMigrateName != $oldName) {
						$migrationItem->dbMigrateName = $itemName;
						// oldname should be unchanged (assuming it exists)
					}
				}

				if($migrationItem && $migrationItem->id && $migrationItem->dbMigrateName == $migrationItem->dbMigrateOldName) $migrationItem->dbMigrateOldName = '';

				$this->bd($migrationItem, 'BBBB');

				// If the item is now the same as its base state, remove it
				$this->bd([$objectData, $baseData], 'object & base data');
				if(is_array($objectData) && is_array($baseData)) {
					$diff = $migration->array_compare($objectData, $baseData);
				} else {
					$diff = ($objectData == $baseData);
				}
				$this->bd($diff, 'diff');
				if(!$diff) {
					$this->removeMigrationItem($migration, $migrationItem, $object, $item);
				} else {
					$this->bd($migrationItem, 'saving changed item');
					$migrationItem->meta()->set('sourceData', $sourceData);
					$sourceData['dependencies'] = $migration->getDependencies($migrationItem, $item);
					$migrationItem->meta()->set('sourceData', $sourceData);
					$this->bd($sourceData, 'setting sourceData 2');
					$migrationItem->of(false);
					$migrationItem->save();
					$migration->dependencySort()->save();
				}
				// Update the currentData meta so that the action cannot accidentally be implemented twice
				$migration->meta()->set("current_{$object}_{$item->id}", $this->setObjectData($migration, $item, $type));
				// (Normally currentData meta will be reset on ready(), but if PW calls saved twice before the next ready() it won't get reset, hence the need for the above)

				$this->bd('CASE 2 complete');
				return;
			}
			// Case 3: Item does not exist - create it

			// Repeater fields will be saved twice* and the migration dbMigrateItem does not yet know about the first save
			// However the baseData meta will have been set, so here we prevent the creation of a second new item
			// (* A new repeater field generates a new template which is saved first and also saves the field**, all before the original field save happens)
			// (** In the case of a RepeaterMatrix, this field object has the RepeaterMatrixField class whereas the original (deferred save) object just has the Field class)
			if($item->type == 'FieldtypeRepeater' || $item->type == 'FieldtypeRepeaterMatrix') {
				$this->bd(['migration' => $migration, 'item' => $item, 'objectData' => $objectData, 'currentData' => $currentData, 'baseData' => $baseData], 'Repeater field');
				// if($baseData !== null) return;
			}

			//
			// Now continue with real changes
			$this->bd($migration, 'Case 3 - adding new item');
			$this->bd(['item' => $item, 'type' => $type, 'action' => $action, 'oldName' => $oldName], 'In case 3');
//			$this->wire()->log->save('debug', 'Progress 8');
			$migrationItem = $migration->dbMigrateItem->getNew();
			// nope; neither does removing all of this!!!
			$migrationItem->of(false);
			$migrationItem->dbMigrateType = $type;
			$this->bd($migrationItem->dbMigrateType->title, 'type title');
			$migrationItem->dbMigrateAction = $action;
			$migrationItem->dbMigrateName = $itemName;
			$migrationItem->dbMigrateOldName = ($itemName == $oldName) ? '' : $oldName;
			$migrationItem->save();
			$migrationItem->meta()->set('sourceData', $sourceData); // Needs to be after initial save as otherwise no page id exists
			$sourceData['dependencies'] = $migration->getDependencies($migrationItem, $item);
			$migrationItem->meta()->set('sourceData', $sourceData);
			$this->bd($sourceData, 'setting sourceData 3');
			$this->bd($migration->dbMigrateItem, 'dbMigrateItem before addition');
			// nope
			//$migration->of(false);
			if(!$migration->dbMigrateItem->has("dbMigrateName=$itemName")) {  // just in case it is already there - don't duplicate it
				$migration->dbMigrateItem->add($migrationItem);
				$this->bd($migrationItem, 'added migration item');
			}
			$migration->meta()->set("base_{$object}_{$item->id}", $currentData);
			// nope
			//$migration->of(false);
			$migration->dependencySort()->save();
			$this->bd(['migration' => $migration, 'currentData' => $currentData], 'Done CASE 3');
		}
//		$this->wire()->log->save('debug', 'Progress 9');
	}

	/**
	 * Remove an item from the tracking migration
	 *
	 * @param $migration
	 * @param $migrationItem
	 * @param $object
	 * @param $item
	 * @return void
	 * @throws WireException
	 */
	protected function removeMigrationItem($migration, $migrationItem, $object, $item) {
		$this->bd(['migration' => $migration, 'migItem' => $migrationItem, 'obj' => $object, 'item' => $item], 'removemigrationitem');
		if(!$migration || !$migration->id) return;
		$migration->dbMigrateItem->remove($migrationItem);
		$migration->meta()->remove("base_{$object}_{$item->id}");
		$migration->save();
		if($object == 'page') {
			//Update migration for any pages which hold files used in RTE fields on the current page
			$imageSources = $this->findRteImageSources($item);
			$this->bd($imageSources, 'save image sources');
			foreach($imageSources as $imageSource) {
				$imgSrcPath = $imageSource->path();
				if($migration->dbMigrateItem->has("dbMigrateName={$imgSrcPath}")) {
					$scopedObjects = $this->wire()->pages->find($migration->dbMigratePageTracking);
					if($scopedObjects->has($imageSource)) {
						// Case where the imagesource page is within the scope of change tracking in its own right
						// If the current data for the imagesource page are the same as the 'base data' then this will cause it to be removed too
						// Otherwise the item will remain even though the cause of it being there originally no longer exists
						// This is because a change was made to it after it was added
						$this->setPageMeta($imageSource, true);
						$this->addTrackedMigrationItem($imageSource, 3, 2, '');
					} else {
						// Case where the imagesource page is not within the scope of change tracking 
						// Just remove it
						$imgSrcMigItem = $migration->dbMigrateItem->get("dbMigrateName={$imgSrcPath}");
						$migration->dbMigrateItem->remove($imgSrcMigItem);
						$migration->meta()->remove("base_page_{$imageSource->id}");
						$migration->save();
					}
				}
			}
		}
	}

	/**
	 * Helper functions for above
	 */

	/**
	 * Get the id of any migration item (in $migration) which is derived from the $item object (via change tracking)
	 *
	 * @param $migration
	 * @param $item
	 * @return mixed|null
	 */
	public function itemMatches($migration, $item) {
		/* @var DbMigrationPage $migration */
		$existingItemIds = [];
		//foreach($migration->getFormatted('dbMigrateItem') as $migrationItem) {
		foreach($migration->dbMigrateItem->find("status=1") as $migrationItem) {
			if($migrationItem && $migrationItem->id && isset($migrationItem->meta('sourceData')['id']) && $migrationItem->meta('sourceData')['id']) {
				$existingItemIds[$migrationItem->meta('sourceData')['id']] = $migrationItem->id;
			}
		}
		$this->bd($existingItemIds, 'existing item ids');
		if(array_key_exists($item->id, $existingItemIds)) {
			$itemMatches = $existingItemIds[$item->id];
			$this->bd($item->name, 'Already in migration as ' . $migration->dbMigrateItem->get("id=$itemMatches")->dbMigrateName);
		} else {
			$itemMatches = null;
		}
		$this->bd($itemMatches, 'Migration item id for ' . $item->name);
		return $itemMatches;
	}

	/**
	 * Get the object data for the migration item
	 *
	 * @param $migration
	 * @param $item
	 * @param $type
	 * @return array|\Exception[]|null
	 * @throws WireException
	 */
	public function setObjectData($migration, $item, $type) {
		$objectData = ($type < 3) ? $this->getExportDataMod($item) : // The data for the  object ( getExportData() not necessary for new objects and can cause problems)
			$this->getPageExportData($migration, $item); // Field exclusions may vary between migrations
		return $objectData;
	}

	/**
	 * Get the object data for fields and templates
	 *
	 * @param $obj
	 * @return array|\Exception[]|void
	 * @throws WireException
	 */
	public function getExportDataMod($obj) {
		// To deal with cross-pollution of repeater configs by repeater matrix configs we suspend those methods temporarily
		// (see https://processwire.com/talk/topic/27988-config-calls-deprecated-method/#comment-229594)
		$configHook = $this->addHookBefore("FieldtypeRepeaterMatrix::getConfigInputfields($obj)", $this, 'beforeGetConfigInputfields'); // see below

		if(($obj instanceof Field) && !($obj instanceof RepeaterMatrixField) && $obj->type == 'FieldtypeRepeaterMatrix') {
			$processed = $this->wire()->session->get('processed_repeater');
			$caller = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
			$this->bd($processed, 'processed repeater in getExportDataMod');
			if(in_array($obj->id, $processed) && $caller != 'getExportStructureData') {   // Skip the export if this is a duplicate, but don't skip the export when installing a migration
				return;
			}
			$processed[] = $obj->id;
			$this->wire()->session->set('processed_repeater', $processed);
			if(wire()->modules->isInstalled('FieldtypeRepeaterMatrix')) {
				// Make sure that RepeaterMatrixField class  exists
				if(!wireClassExists('ProcessWire\RepeaterMatrixField')) {
					require_once(wire()->config->paths->siteModules . 'FieldtypeRepeaterMatrix/RepeaterMatrixField.php');
				}
				$newObj = self::cast($obj, 'ProcessWire\RepeaterMatrixField');
			} else {
				wire()->session->error($this->_("Attempting to install a RepeaterMatrix field but FieldtypeRepeaterMatrix module is not installed"));
			}
			$this->bd([$obj, $newObj], 'obj, cast object');
			$this->bd([DEBUG::backtrace(), debug_backtrace()], 'backtrace');
		} else {
			$newObj = $obj;
		}
		$this->bd($newObj, 'newObj');
		try {
			$this->wire()->session->set('dbMigrate_exportDataMod', true);
			$objectData = $newObj->getExportData();
			$this->wire()->session->remove('dbMigrate_exportDataMod');
			// getExportData is inconsistent in returning template_id - sometimes it is the template name
			if(isset($objectData['template_id']) && $objectData['template_id'] && !is_numeric($objectData['template_id'])) {
				$this->bd($objectData['template_id'], 'fixing template_id');
				$name = $objectData['template_id'];
				if(is_string($name) && $this->wire()->templates->get("name={$name}")) {
					$this->bd($this->wire->templates->get("name={$name}"), 'template in getexportdatamod');
					$objectData['template_id'] = (string)$this->wire()->templates->get("name={$name}")->id;
				}
			}
			//
			$this->bd([$objectData['name'], $objectData], 'objectData in getExportDataMod');
		} catch(\Exception $ex) {
			$objectData = [$ex];
			$this->bd($objectData, 'objectData exception in getExportDataMod');
		}

		$this->wire()->removeHook($configHook);
		return $objectData;
	}

	/**
	 * Get the object data for pages
	 *
	 * @param $migration DbMigrationPage
	 * @param $exportPage Page
	 * @param $fresh boolean If true then a fresh copy pages will be used, rather than the cached version, throughout the process (including repeater pages)
	 * @return array
	 * @throws WireException
	 */
	public function getPageExportData($migration, $exportPage, $fresh = false) {
		/* @var $migration DbMigrationPage */
		// $fields = "field=" . implode('|', array_values($exportPage->getFields()->explode('name')));
		$this->bd($fields, 'fields for getExport');
		// $exportPage = $this->pages()->find("id={$page->id}", $fields)->first();


		$this->bd([$exportPage->name, $exportPage], 'page in getExportPage');
		$attrib = [];
		$files = [];
		if($fresh) {
			$exportPage = $this->wire()->pages->getFresh($exportPage->id);
		}
		$attrib['template'] = $exportPage->template->name;
		$attrib['parent'] = ($exportPage->parent->path) ?: $exportPage->parent->id;  // id needed in case page is root
		$attrib['status'] = $exportPage->status;
		$attrib['name'] = $exportPage->name;
		$attrib['id'] = $exportPage->id;
		$restrictFields = $migration->restrictFields();

		$excludeFields = (isset($this->exclude_fieldnames)) ? str_replace(' ', '', $this->exclude_fieldnames) : '';
		$excludeFields = $this->wire()->sanitizer->array(str_replace(' ', '', $excludeFields), 'fieldName');
		$excludeTypes = (isset($this->exclude_fieldtypes)) ? str_replace(' ', '', $this->exclude_fieldtypes) : '';
		$excludeTypes = $this->wire()->sanitizer->array(str_replace(' ', '', $excludeTypes), 'fieldName');
		$excludeTypes = array_merge($excludeTypes, self::EXCLUDE_TYPES);
		$excludeFieldsForTypes = $migration->excludeFieldsForTypes($excludeTypes);
		$excludeFields = array_merge($excludeFields, $excludeFieldsForTypes);

		$migration->getAllFieldData($exportPage, $restrictFields, $excludeFields, $attrib, $files, $fresh);
		foreach($excludeFields as $excludeField) {
			unset($attrib[$excludeField]);
		}
		$this->bd([$exportPage->name, $attrib], 'attrib returned for export page');
		// $exportPage = $this->pages()->getFresh("{$exportPage->id}");
		$this->bd($exportPage, 'plain page');
		$this->bd($this->pages()->findRaw("id={$exportPage->id}")[$exportPage->id], 'findraw');
		// $fields = $exportPage->getFields()->explode('name');
		$this->bd($this->pages()->findJoin("id={$exportPage->id}", $fields)->first(), 'findjoin');
		return $attrib;
	}

	/**
	 * Hook before FieldtypeRepeaterMatrix::getConfigInputfields($obj)
	 * Cast config inputfields of FieldtypeRepeaterMatrix to FieldtypeRepeater, to avoid errors
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	protected function beforeGetConfigInputfields(HookEvent $event) {
		$event->replace = true;
		$this->bd($event->object, 'in beforeGetConfigInputfields');
		$this->bd($event->arguments(0), 'field in beforeGetConfigInputfields');
		$plainRepeater = $this->cast($event->object, 'ProcessWire\FieldtypeRepeater'); // to get the parent method ($event->object is FieldtypeRepeaterMatrix, parent is FieldtypeRepeater)
		$event->return = $plainRepeater->getConfigInputfields($event->arguments(0)); // arguments(0) is $field
	}


	/**
	 * The next 2 methods form a set. The purpose is to identify pages which hold any images contained in RTE fields of the current page
	 * It is possible that the dependency might not be entirely accurate for nested repeater fields with complex relationships of image sources
	 * However, this module is not principally concerned with the sort of user pages that might have these rare conditions
	 */
	/**
	 * @param $page
	 * @param $imageSources
	 * @return WireArray
	 */
	public function findRteImageSources($page, $imageSources = null) {
		$imageSources = ($imageSources) ?: new PageArray();
		foreach($page->getFields() as $field) {
			$this->bd([$page, $field], 'RTE? field');
			if($field) {
				if($field->type == 'FieldtypeTextarea') {
					$this->bd([$page, $field], 'RTE field Y');
					$html = $page->$field;
					$imageSources->add($this->findHtmlImageSources($html));
				}
				if($field->type == 'FieldtypeRepeater' || $field->type == 'FieldtypeRepeaterMatrix') {
					$repeaterPages = $page->$field;
					foreach($repeaterPages as $repeaterPage) {
						$imageSources->add($this->findRteImageSources($repeaterPage));
					}
				}
			}
		}
		return $imageSources->unique();
	}
	/**
	 * @param $html
	 * @return WireArray
	 * @throws WireException
	 */
	protected function findHtmlImageSources($html) {
		if(strpos($html, '<img') === false and strpos($html, '<a') === false) return $html; //return early if no images or links are embedded in html
		$imageSources = new PageArray();
		$re = '/assets\/files\/(\d*)\//m';
		preg_match_all($re, $html, $matches, PREG_SET_ORDER, 0);
		if($matches) {
			foreach($matches as $match) {
				$imageSource = $this->wire()->pages->get("id={$match[1]}");
				$imageSources->add($imageSource);
			}
		}
		return $imageSources->unique();
	}

	/**
	 * This is a simpler version of the above pair - for just finding RTE fields with links in them
	 *
	 * @param $page
	 * @param $links
	 * @return WireArray
	 * @throws WireException
	 */
	protected function findRteLinks($page, $links = null) {
		$links = ($links) ?: new PageArray();
		foreach($page->getFields() as $field) {
			$this->bd([$page, $field], 'RTE? field (for findRteLinks)');
			if($field) {
				if($field->type == 'FieldtypeTextarea') {
					$this->bd([$page, $field], 'RTE field Y');
					$html = $page->$field;
					$re = '/href=\"(.*)\"/m';
					preg_match_all($re, $html, $matches, PREG_SET_ORDER, 0);
					$this->bd($matches, 'matches');
					foreach($matches as $match) {
						$path = $match[1];
						if($this->sanitizer->path($path)) {
							$link = $this->pages()->get($path);
							$this->bd($link, 'rtelink');
							if($link) $links->add($link);
						}
					}
				}
				if($field->type == 'FieldtypeRepeater' || $field->type == 'FieldtypeRepeaterMatrix') {
					$repeaterPages = $page->$field;
					foreach($repeaterPages as $repeaterPage) {
						$links->add($this->findRteLinks($repeaterPage));
					}
				}
			}
		}
		$this->bd($links->unique(), "returning unique links for {$page->name}");
		return $links->unique();
	}

	/**
	 * Fields and templates have no equivalent method to getFresh() for pages, so this new method is added by hook to WireSaveableItems
	 *
	 * @param $event
	 * @return void
	 * @throws WireException
	 */
	public function getFreshSaveableItem($event) {
		$this->bd($event, 'event in getFreshSaveableItem');
		$saveables = $event->object;
		/* @var $saveables WireSaveableItems */
		$item = $event->arguments(0);
		$this->bd([$item, $saveables, $item->id], 'item, saveables, item id');
		$database = $this->wire()->database;
		$selector = "id=" . $item->id;
		$this->bd($selector, 'selector');
		$sql = $saveables->getLoadQuery($selector);

		/* NB When using a selector, getLoadQuery appears to return the bindKey, not the bindValue, in the WHERE statement
		 * (see https://processwire.com/talk/topic/28886-databasequery-problem/)
		 * so we need to replace the keys by the values in the returned result before executing the query
		 * otherwise we get a SQL syntax error
		*/
		// Get the values
		$bindValues = $sql->bindValues;
		// Get the query as a string
		$sql = $sql->getQuery();
		//Replace keys by values
		foreach($bindValues as $k => $v) {
			$sql = str_replace($k, $v, $sql);
		}
		//
		$this->bd($sql, 'revised SQL string');
		$query = $database->prepare($sql);
		$this->bd($query, 'query');
		$query->execute();
		$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
		$this->bd($rows, 'rows');
		$freshItem = null;
		if($item) {
			$items = new WireArray();
			foreach($rows as $row) {
				$newItem = $saveables->initItem($row, $items);
				/* @var $newItem Fieldgroup */
				$freshItem = ($freshItem) ? $freshItem->setArray($newItem->getArray()) : $newItem;
				if(wireInstanceOf($saveables, 'Fieldgroups') && $row['data']) {
					$freshItem->setFieldContextArray($row['fields_id'], json_decode($row['data'], true));
				}
				$this->bd($freshItem, 'freshItem interim');
			}
		}
		$this->bd($freshItem, 'freshItem');
		$event->return = $freshItem;
	}

	/**
	 * Class casting
	 *
	 * @param string|object $destination
	 * @param object $sourceObject
	 * @return object
	 */
	public static function cast($sourceObject, $destination) {
		if(is_string($destination)) {
			// $destination = new $destination(); // replaced by below to avoid calling constructor
			$destination = (new \ReflectionClass($destination))->newInstanceWithoutConstructor();
		}
		$sourceReflection = new \ReflectionObject($sourceObject);
		$destinationReflection = new \ReflectionObject($destination);
		$sourceProperties = $sourceReflection->getProperties();
		foreach($sourceProperties as $sourceProperty) {
			$sourceProperty->setAccessible(true);
			$name = $sourceProperty->getName();
			$value = $sourceProperty->getValue($sourceObject);
			if($destinationReflection->hasProperty($name)) {
				$propDest = $destinationReflection->getProperty($name);
				$propDest->setAccessible(true);
				$propDest->setValue($destination, $value);
			} else {
				$destination->$name = $value;
			}
		}
		return $destination;
	}

////////////////////// Not currently used  ////////////////////////////////////////////
//	/**
//	 * Get the pages ('hosts') with page table fields that have the given page in their scope
//	 * This is so that the hosts can be flagged as changed when the page is changed or added as a pages::saved hook may not work
//	 *
//	 * @param $page
//	 * @return array
//	 * @throws WireException
//	 */
//	public function pageTableHosts($page) {
//		$pageTableFields = $this->wire()->fields->find('type=FieldtypePageTable');
//		$parent = $page->parent();
//		$hosts = new PageArray();
//		foreach($pageTableFields as $pageTableField) {
//			$parentId = $this->inPageTableScope($page, $pageTableField);
//			$this->bd($parentId, 'parent id in pageTableHosts');
//			$this->log->save('debug', "Parent id for {$page->name} in {$pageTableField->name} is $parentId");
//			if($parentId !== false) {
//				if($parentId == 0) {
//					$this->bd('adding to hosts');
//					$hosts->add($parent);
//				} else {
//					//find all pages that have a $pageTableField field
//					$fieldgroups = $this->wire()->fieldgroups;
//					$this->bd($fieldgroups, 'fieldgroups');
//					$this->wire()->log->save('debug', 'Got fieldgroups');
//					foreach($fieldgroups as $fieldgroup) {
//						/* @var $fieldgroup Fieldgroup */
//						$this->bd($fieldgroup, 'fieldgroup');
//						$this->log->save('debug', "Checking fieldgroup {$fieldgroup->name}");
//						if($fieldgroup->hasField($pageTableField)) {
//							$templates = $fieldgroup->getTemplates();
//							$this->bd($templates, 'templates');
//							$templateSelector = implode('|', $templates->explode('id'));
//							$this->log->save('debug', "Checking templates $templateSelector");
//							$pages = $this->wire()->pages->find("template=$templateSelector, include=all");
//							$this->bd($pages, 'pages');
//							$hosts->add($pages);
//						}
//					}
//				}
//			}
//		}
//		$this->log->save('debug', "Hosts for {$page->name} are " . $hosts->implode(', ', 'name'));
//		$sessionIds = $this->session->get('pageTableHostId');
//		$this->bd($sessionIds, 'pageTableHost ids before host selection');
//		$this->bd($hosts, 'hosts in pageTableHosts');
//		$host = null;
//		if($hosts->count() > 1 && $sessionIds) {
//			while(count($sessionIds) > 0) {
//				$hostId = array_pop($sessionIds);
//				$host = (is_int($hostId)) ? $hosts->get("id=$hostId, include=all") : null;
//				if($host) {
//					$this->warning("Multiple potential hosts for page table item {$page->name} :- Just including {$host->path} in the migration. Add other pages to migration manually if required.");
//					// Reset the session var with the reduced array, but first reinstate the current host page
//					array_push($sessionIds, $hostId);
//					$this->session->set('pageTableHostId', $sessionIds);
//					break;
//				}
//			}
//
//			if(!$host) {
//				$host = $hosts->first();
//				$this->warning("Multiple potential hosts for page table item {$page->name} :- {$hosts->implode(', ', 'name')}. \n
//					This may cause problems with change tracking. Using first host - ($host->path} - only. Remove and/or add other pages to migration manually if required.");
//			}
//		} else {
//			$host = $hosts->first();
//		}
//		$this->bd($sessionIds, 'pageTableHost ids after host selection');
//		$this->bd(Debug::backtrace(), 'backtrace in pageTableHosts');
//		return $host;
//	}
//
//	/**
//	 * Is the page within the scope of a page table field?
//	 *
//	 * @param $page
//	 * @param $pageTable
//	 * @return bool/int
//	 *
//	 */
//	public function inPageTableScope($page, $pageTableField) {
//		$parent = $page->parent();
//		$data = $this->accessProtected($pageTableField, 'data');
//		$this->bd($data, 'data in inPageTableScope');
//		$templateId = $data['template_id'];
//		$parentId = $data['parent_id'];
//		$this->bd($templateId, 'template id in inPageTableScope');
//		$selector = implode('|', $templateId);
////		$requiredParentId = ($parentId == 0) ? $parent->id : $parentId;
//		$templates = $this->templates()->find("id=$selector");
//		/* @var $templates TemplatesArray */
//		$this->bd([$page, $parent, $templates], 'page, parent, templates');
//		if($templates->has($page->template) && ($parentId == 0 || $parent->id == $parentId)) {
//			return $parentId;
//		} else {
//			return false;
//		}
//	}
//
/////////////////////////////////////////////////////////////////////////////////

	/**
	 * Get data for protected properties
	 *
	 * @param $obj
	 * @param $prop
	 * @return mixed
	 * @throws \ReflectionException
	 */
	protected function accessProtected($obj, $prop) {
		$reflection = new \ReflectionClass($obj);
		$property = $reflection->getProperty($prop);
		$property->setAccessible(true);
		return $property->getValue($obj);
	}

	/*
	 ************************************
	 *********** HELP PAGE **************
	 ************************************
	 */
	//NB See also afterSave and beforeBuildFormContent Hooks above

	/**
	 * Create template
	 * Called on install / update
	 *
	 * @param $template_name
	 * @param $template_label
	 */
	protected function createTemplate($template_name, $template_label) {
		if($this->templates->get($template_name)) {
			//$this->warning($this->_("Template '$template_name' already exists.")); // Warning removed to prevent it on update
			return;
		}
		if($this->fieldgroups->get($template_name)) {
			// Keep this warning in case fieldgroup exists when template doesn't - maybe a name already in use, or a previous botched install
			$this->warning(sprintf($this->_('Fieldgroup %1$s already exists. To remove it, use "$fg = $fieldgroups->get(%2$s); $fieldgroups->delete($fg);" in Tracy console.'), $template_name, $template_name));
			return;
		}
		$fg = new Fieldgroup();
		$fg->name = $template_name;
		$fg->add($this->wire()->fields->get('title'));
		$fg->add($this->wire()->fields->get('dbMigrateAdditionalDetails'));
		$fg->save();
		$t = new Template();
		$t->name = $template_name;
		$t->label = $template_label;
		$t->fieldgroup = $fg;
		$t->compile = 0;
		$t->noPrependTemplateFile = true;
		$t->noAppendTemplateFile = true;
		$t->flags = 8; // system
		$t->set('tags', 'dbMigrate');  // Can't use addTag() prior to 3.0.172
		$t->save();
		$f = $t->fieldgroup->getField('dbMigrateAdditionalDetails', true);
		$f->label = 'Help text';
		$f->rows = 16;
		$this->fields->saveFieldgroupContext($f, $fg);
		$t->save();
		$this->message("Created template '$template_name'.");
	}

	/**
	 * Create page
	 * Called on install / update
	 *
	 * @param $page_title
	 * @param $template_name
	 */
	protected function createPage($page_title, $template_name) {
		$page_name = $this->sanitizer->pageName($page_title, true);
		$parent = $this->parent . $this->name . '/';
		$p = $this->pages->get("parent=$parent, name=$page_name");
		$pid = $p->id;
		if(!$p or !$pid) {
			$this->bd($parent, 'parent for new page');
			$p = new Page();
			$p->template = $template_name;
			$p->parent = $parent;
			$p->name = $page_name;
			$p->title = $page_title;
			$this->message("Created page '$page_name'.");
		}
		// Help text is updated from module file even if page exists (e.g. for update)
		if($template_name == 'DbMigrateHelp' && file_exists($this->modulePath . 'helpText.html')) {
			$p->dbMigrateAdditionalDetails = file_get_contents($this->modulePath . 'helpText.html');
		}
		$p->of(false);
		$p->save();

	}

	/**
	 * Popout for various pages
	 *
	 * @param $label
	 * @param $fullText
	 * @return string
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	static function helpPopout($label, $fullText = false) {
		$helpPagePath = wire('pages')->get(2)->path() . self::moduleInfo()['page']['parent'] . '/' . self::moduleInfo()['page']['name'] . '/dbmigrate-help/';
		$helpPage = wire()->pages->get($helpPagePath);
		if($helpPage and $helpPage->id) {
			$help = "<span><a class='popout-help' href='{$helpPage->url}' title='Pop-out'>$label <i class='fa fa-external-link'></i></a></span>";
			if($fullText) $help .= $helpPage->dbMigrateAdditionalDetails;
		} else {
			$help = '';
		}
		return $help;
	}

/*
 * Debugging
 * Only call bd if self::debug is true
 */
	public static function bd($a, $b = null) {
		if(self::debug && function_exists('bd')) ($b === null ? bd($a) : bd($a, $b));
	}

	/*
	 * Debugging
	 * Always call bd regardless of self::debug
	 */
	public static function bdAlways($a, $b = null) {
		if(function_exists('bd')) ($b === null ? bd($a) : bd($a, $b));
	}

}
