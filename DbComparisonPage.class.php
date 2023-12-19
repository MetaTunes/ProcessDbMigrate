<?php

namespace ProcessWire;


/**
 * Class DbComparisonPage
 *
 * @package ProcessWire
 *
 * @property object $comparisons The parent page for comparison pages
 * @property object $comparisonTemplate The template for comparison pages
 * @property string $comparisonsPath Path to the folder holding the comparisons .json files
 * @property string $adminPath Path to the admin root (page id = 2)
 * @property object $configData Process module settings
 * @property boolean $ready To indicate tha ready() has run
 *
 *
 * @property string $title Title
 * @property string $dbMigrateSummary Summary
 * @property string $dbMigrateAdditionalDetails Additional details
 * @property RepeaterPageArray|RepeaterDbMigrateComparisonItemPage[] $dbMigrateComparisonItem Comparison item
 * @property string $dbMigrateRestrictFields Restrict fields
 * @property RepeaterPageArray|tpl_repeater_dbMigrateSnippets[] $dbMigrateSnippets Snippets
 */
class DbComparisonPage extends DbMigrationPage {


	// Module constants
	// Constants are set in ProcessDbMigrate

	/**
	 * Create a new DbComparison page in memory.
	 *
	 * @param Template $tpl Template object this page should use.
	 *
	 */
	public function __construct(Template $tpl = null) {
		if(is_null($tpl)) $tpl = $this->templates->get('DbComparison');
		parent::__construct($tpl);

	}


	public function init() {
		//bd('INIT COMPARISON');
	}

	/**
	 * Better to put hooks here rather than in ready.php
	 * This is called from ready() in ProcessDbMigrate.module as that is autoloaded
	 *
	 * @throws WireException
	 *
	 */
	public function ready() {
		$this->set('adminPath', wire('pages')->get(2)->path);
		$this->set('comparisons', wire('pages')->get($this->adminPath . ProcessDbMigrate::COMPARISON_PARENT));
		$this->set('comparisonTemplate', wire('templates')->get(ProcessDbMigrate::COMPARISON_TEMPLATE));
		$this->set('comparisonsPath', wire('config')->paths->templates . ProcessDbMigrate::COMPARISON_PATH);
		$this->set('configData', wire('modules')->getConfig('ProcessDbMigrate'));
		$dbMigrate = wire('modules')->get('ProcessDbMigrate');
		$this->set('dbMigrate', $dbMigrate);
		$this->set('dbName', $dbMigrate->dbName());
		if(isset($this->configData['suppress_hooks']) && $this->configData['suppress_hooks']) $this->wire()->error("Hook suppression is on - migrations will not work correctly - unset in the module settings.");

		// Fix for PW versions < 3.0.152, but left in place regardless of version, in case custom page classes are not enabled
		if($this->comparisonTemplate->pageClass != __CLASS__) {
			$this->comparisonTemplate->pageClass = __CLASS__;
			$this->comparisonTemplate->save();
		}

		$this->addHookAfter("Pages::saved(template=$this->comparisonTemplate)", $this, 'afterSaved');
		$this->addHookBefore("Pages::save(template=$this->comparisonTemplate)", $this, 'beforeSaveThis');

		$this->set('ready', true);
	}

	/*
	 * METHODS WHICH MODIFY THE PARENT CLASS
	 */

	/**
	 *
	 * Refresh page from json
	 *
	 * @param null $found
	 * @return bool
	 * @throws WireException
	 * @throws WirePermissionException
	 *
	 */
	public function refresh($found = null) {
		$this->migrationsPath = $this->comparisonsPath;
		$this->migrations = $this->comparisons;
		return parent::refresh($found);
	}

	/**
	 *
	 * Parse items, expanding selectors as necessary
	 * Return list of all items in format [[type, action, name, oldName], [...], ...]
	 *
	 * @return array[]
	 * @throws WireException
	 *
	 */
	public function listItems($type = null) {
		$this->dbMigrateItem = $this->dbMigrateComparisonItem;
		return parent::listItems($type);
	}

	/**
	 * @param $newOld
	 * @param false $noSave
	 * @return array|void|null
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	public function exportData($newOld, $noSave = false) {
		$this->ready();
		$this->dbMigrateItem = $this->dbMigrateComparisonItem;
		$this->migrationsPath = $this->comparisonsPath;
		return parent::exportData($newOld, $noSave);
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
	public function replaceImgSrcPath(string $html, string $newOld, $json = false, $path = ProcessDbMigrate::COMPARISON_PATH) {
		return parent::replaceImgSrcPath($html, $newOld, $json, $path);
	}

	/*
	 * Override irrelevant functions with nulls
	 */

	public function afterSaved(HookEvent $event) {
	}

	protected function checkOverlaps($itemList) {
	}

	protected function beforeSaveThis(HookEvent $event) {
	}

	/*
	 *   These are not overridden
	 */

//    protected function beforeTrashThis(HookEvent $event) {}

//    protected function afterTrashedThis(HookEvent $event) {}
}