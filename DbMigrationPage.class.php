<?php

namespace ProcessWire;

//use DOMDocument;

/*
 * Need to allow for possibility of using DefaultPage (if it exists) as the base class
 */
if (class_exists('DefaultPage')) {
    class DummyMigrationPage extends DefaultPage { }
}
else
{
    class DummyMigrationPage extends Page { }
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
 * @property mixed $runtime_markup_migrationControl Page status
 * @property mixed $runtime_markup_migrationActions Migration actions
 * @property string $dbMigrateSummary Summary
 * @property string $dbMigrateAdditionalDetails Additional details
 * @property RepeaterPageArray|tpl_repeater_dbMigrateItem[] $dbMigrateItem Migration item
 * @property string $dbMigrateRestrictFields Restrict fields
 * @property RepeaterPageArray|tpl_repeater_dbMigrateSnippets[] $dbMigrateSnippets Snippets
 */

class DbMigrationPage extends DummyMigrationPage
{

 // Module constanta
    const MIGRATION_TEMPLATE = 'DbMigration';
    const MIGRATION_PARENT = 'dbmigrations/';
    const MIGRATION_PATH = 'DbMigrate/migrations/';
    const SOURCE_ADMIN = '/processwire/'; // The admin path of the source used to create the bootstrap json
    const EXCLUDE_TYPES = array('RuntimeMarkup', 'RuntimeOnly');  // Field types to always ignore
    const EXCLUDE_ATTRIBUTES = array('_importMode', 'template_id'); // Template/ field attributes to always ignore

    /**
     * Create a new DbMigration page in memory.
     *
     * @param Template $tpl Template object this page should use.
     */
    public function __construct(Template $tpl = null) {
        if (is_null($tpl)) $tpl = $this->templates->get('DbMigration');
        parent::__construct($tpl);

    }



public function init() {

}

    /**
     * Better to put hooks here rather than in ready.php
     * This is called from ready() in PrpcessDbMigrate.module as that is autoloaded
     */
    public function ready() {
        $this->set('adminPath',  wire('pages')->get(2)->path);
        $this->set('migrations', wire('pages')->get($this->adminPath . self::MIGRATION_PARENT));
        $this->set('migrationTemplate', wire('templates')->get(self::MIGRATION_TEMPLATE));
        $this->set('migrationsPath', wire('config')->paths->templates . self::MIGRATION_PATH);
        $this->set('configData', wire('modules')->getConfig('ProcessDbMigrate'));
        // Fix for versions < 3.0.152, but left in place regardless of version, in case custom page classes are not enabled
        if ($this->migrationTemplate->getPageClass() != __CLASS__) {
            $this->migrationTemplate->pageClass = __CLASS__;
            $this->migrationTemplate->save();
        }
        $this->addHookAfter("Pages::saved(template=$this->migrationTemplate)", $this, 'afterSaved');
        $this->addHookBefore("Pages::save(template=$this->migrationTemplate)", $this, 'beforeSaveThis');

        $this->set('ready', true);
    }

    /**
     * Where keys 0f $data are in the format of a pair x|y, replace this by just the pair member that exists in the current database
     * @param array $data
     * @param $type
     * @return array|null
     * @throws WireException
     * @throws WirePermissionException
     */
    protected function pruneKeys(array $data, $type) {
        if (!in_array($type, ['fields', 'templates', 'pages'])) return null;
        $newData = [];
        foreach ($data as $key => $value) {
            if (strpos($key, '|')) {
                $exists = [];
                $both = explode('|', $key);
                foreach ($both as $i => $keyTest) {
                    if ($type == 'pages') {
                        $exists[$i] = ($this->wire($type)->get($keyTest) and $this->wire($type)->get($keyTest)->id);
                    } else {
                        $exists[$i] = ($this->wire($type)->get($keyTest));
                    }
                }
                if ($exists[0] and $exists[1]) {
                    $this->wire()->notices->error('Unable to change name for ' . implode('|', $both) . ' as both names already exist');
                    continue;
                } elseif (!$exists[0] and !$exists[1]) {
                    $this->wire()->notices->error('Error in ' . $type . '. Neither "' . $both[0] . '" nor "' . $both[1] . '" exists.');
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
     * After save actions (none at present)
     * @param HookEvent $event
     * @throws WireException
     */
    public function afterSaved(HookEvent $event) {
        $p = $event->arguments(0);
        if (!$p or !$p->id) return;
        if ($this != $p) return;
        $k = 0;
        foreach ($this->dbMigrateItem as $item) {
            /* @var $item RepeaterDbMigrateItemPage */
            $k ++;
        if (!$item->dbMigrateType or !$item->dbMigrateAction or !$item->dbMigrateName) {
                $this->wire()->notices->warning('Missing values for item ' . $k);
                //bd($item, 'missing values in item');
        }
        }
    }

    /**
     * before save actions:
     * disallow saving of installable pages (must be generated from migration.json)
     * check overlapping scopes of exportable & unlocked pages
     * @param HookEvent $event
     * @throws WireException
     */
    protected function beforeSaveThis(HookEvent $event) {
        $p = $event->arguments(0);
        if (!$p or !$p->id) return;
        //bd($event, 'hook event');
        if ($this != $p) return;  // only want this method to run on the current instance, not all instances of this class
        /* @var $p DbMigrationPage */
        //bd([$p, $this, $p->meta('installable'), $this->meta('installable')], 'page $p in hook with $this and meta  for $p  and $this ');
        if ($this->meta('installable')) {
            if ($this->meta('allowSave')) {
                $event->return;
            } else {
                //bd($this, 'not saving page');
                $this->warning("$p->name - This page is only installable. Saving it has no effect.");
                $event->replace = true;
                $event->return;
            }
        } else {
            //bd($this, 'saving page');
            $itemList = $this->listItems(); // selector validation happens here
            // $itemList is array of arrays, each [type, action, name, oldName]

            // Validate names and related objects, where relevant
            $errors = $this->validateValues($itemList);
            if ($errors) {
                $this->wire()->notices->error(implode(', ', $errors));
                $event->replace = true;
                $event->return;
            }

            // Queue warnings for reporting on save
            $warnings = [];
            foreach ($itemList as $item) {
                if (!$item or !isset($item['type']) or !isset($item['name']) or !isset($item['action'])) continue;
                // check if objects exist
                $exists = ($item['type'] == 'pages') ? ($this->wire()->pages->get($item['name']) and $this->wire()->pages->get($item['name'])->id) : ($this->wire($item['type'])->get($item['name']));

                //Removed fields etc. which still exist or new/changed fields which do not exist
                if ($item['action'] == 'removed' and $exists) $warnings[] = "{$item['type']} -> {$item['name']} is listed as 'removed' but exists in the current database";
                if ($item['action'] != 'removed' and !$exists) $warnings[] = "{$item['type']} -> {$item['name']} is listed as 'new' or 'changed' but does not exist in the current database";
            }

            // check for overlapping scopes
            if (!$this->meta('locked')) {
                $checked = $this->checkOverlaps($itemList);
                if (!$checked) {
                    $event->replace = true;
                    $event->return;
                }
            }
            if ($warnings) $this->wire()->notices->warning(implode(', ', $warnings));
        }
    }


    /**
     * Check that names and oldNames of current migration do not overlap with those of other (unlocked) migrations
     * @param $itemList
     * @return bool
     * @return bool
     * @throws WireException
     */
    protected function checkOverlaps($itemList) {
        $warnings = [];
        if (!$this->migrations or !$this->migrations->id) {
            $this->wire()->notices->error('Missing dbmigrations page');
            return false;
        }
        $itemOldNames = array_filter($this->extractElements($itemList, 'oldName'));
        $itemNames = $this->extractElements($itemList, 'name');
        $intersection = [];
        $intersectionOld = [];
        //bd($itemNames, ' Names for this');
        foreach ($this->migrations->find("template=$this->migrationTemplate") as $migration) {
            /* @var $migration DbMigrationPage */
            if ($migration === $this) continue;
            if ($migration->meta('locked')) continue;
            $migrationList = $migration->listItems();
            $migrationNames = $this->extractElements($migrationList, 'name');
            $migrationOldNames = array_filter($this->extractElements($migrationList, 'oldName'));
            $intersect = array_intersect($itemNames, $migrationNames);
            $intersectOld = array_intersect($itemOldNames, $migrationOldNames);
            if ($intersect) $intersection[] = $migration->name . ': ' . implode(', ', $intersect);
            if ($intersectOld) $intersectionOld[] = $migration->name . ': ' . implode(', ', $intersectOld);
        }
        $intersectString = implode('; ', $intersection);
        if ($intersection) $warnings[] = "Item names in {$this->name} overlap with names in other migrations as follows - \n $intersectString";
        $intersectOldString = implode('; ', $intersectionOld);
        if ($intersectionOld) $warnings[] = "Item old names in {$this->name} overlap with old names in other migrations as follows - \n $intersectOldString";
        if ($warnings) {
            $warnings[] = "\nIt is recommended that you make the migrations disjoint, or install and lock an overlapping migration.";
        }
        if ($warnings) $this->wire()->notices->warning(implode('; ', $warnings));
        return true;
    }


    /**
     * @param $itemList
     * @param $element
     * @return array
     */
    public function extractElements($itemList, $element) {
        $elements = [];
        //bd($itemList, ' extract ' . $element);
        foreach ($itemList as $item) {
            $elements[] = $item[$element];
        }
        return $elements;
    }

    /**
     * Validate and expand selectors
     * Return list of all items in format [[type, action, name, oldName], [...], ...]
     * @return array[]
     * @throws WireException
     */
    public function listItems() {
        $list = [];
        foreach ($this->dbMigrateItem as $item) {
            /* @var $item RepeaterDbMigrateItemPage */
            if ($item->dbMigrateType->value == 'pages') {
                if (!$this->wire()->sanitizer->path($item->dbMigrateName)) {
                    //bd($item->dbMigrateName, 'Selector provided instead of path name');
                    // we have a selector
                    $names = [];
                    try {
                        $pages = $this->wire()->pages->find($item->dbMigrateName);
                        foreach ($pages as $page) {
                            $names[] = $page->path;
                        }
                    } catch (WireException $e) {
                        $this->wire()->notices->error('Invalid selector: ' . $item->dbMigrateName);
                    }
                } else {
                $names = [$item->dbMigrateName];
                }
            } else {
                $names =[$item->dbMigrateName];
            }
            foreach ($names as $name) {
                $list[] = ['type' => $item->dbMigrateType->value, 'action' => $item->dbMigrateAction->value, 'name' => $name, 'oldName' => $item->dbMigrateOldName];
            }
        }
        return $list;
    }

    /**
     * Standard sanitizer->path does not check for existence of leading and trailing slashes
     * @param $path
     * @return bool
     * @throws WireException
     */
    public function validPath($path) {
        return ($this->wire()->sanitizer->path($path) and strpos($path, '/') == 0 and strpos($path, '/', -1) !== false);
    }

    /**
     * @param $itemList
     * @return array
     * @throws WireException
     */
    protected function validateValues($itemList) {
        $errors = [];
        //bd($itemList, 'item list in validate');
        foreach ($itemList as $item)
            if ($item['type'] == 'pages') {
                if (!$this->validPath($item['name']) or ($item['oldName'] and !$this->validPath($item['oldName']))) {
                    $errors[] = 'Invalid path name (or old path name) for ' . $item['type'] . '->' . $item['action'] . '->' . $item['name'];
                }
            } else {
                if (!$this->wire->sanitizer->fieldName($item['name']) or ($item['oldName'] and !$this->wire->sanitizer->fieldName($item['oldName']))) {
                    $errors[] = 'Invalid name (or old name) for ' . $item['type'] . '->' . $item['action'] . '->' . $item['name'];
                }
            }
        return $errors;
    }

    /**
     * Return a list of all fields of the given types
     * @param array $types
     * @return array
     * @throws WireException
     */
    protected function excludeFieldsForTypes(array $types) {
        $fullTypes = [];
        foreach ($types as $type) {
            $fullTypes[] = (!strpos($type, 'Fieldtype'))  ? 'Fieldtype' . $type : $type;
        }
        $exclude = [];
        $fields = $this->wire()->fields->getAll();
        foreach ($fields as $field) {
            if (in_array($field->type->name, $fullTypes)) $exclude[] = $field->name;
            if (!is_object($field)) throw new WireException("bad field $field");
        }
        return $exclude;
    }




    /**
     * Cycle through items in a migration and get the data for each
     * If $newOld is 'old' then reverse the order and swap 'new' and 'removed' actions
     * @param $itemRepeater
     * @param $excludeAttributes
     * @param $excludeFields
     * @param $newOld
     * @param $compareType
     * @return array|array[]
     * @throws WireException
     * @throws WirePermissionException
     */
    protected function cycleItems($itemRepeater, $excludeAttributes, $excludeFields, $newOld, $compareType) {
        $data = [];
        $count = 0;
        $item = [];
        $files = [];
        if ($compareType == 'old') $itemRepeater = $itemRepeater->reverse();
        foreach ($itemRepeater as $repeaterItem) {
            /* @var $repeaterItem RepeaterDbMigrateItemPage */
            $item['type'] = $repeaterItem->dbMigrateType->value; // fields, templates or pages
            if ($compareType == 'old') {
                // swap new and removed for uninstall
                $item['action'] = ($repeaterItem->dbMigrateAction->value == 'new') ? 'removed' : ($repeaterItem->dbMigrateAction->value == 'removed' ? 'new' : 'changed');
            } else {
                $item['action'] = $repeaterItem->dbMigrateAction->value; // new, changed or removed as originally set
            }
            $item['name'] = $repeaterItem->dbMigrateName;  // for pages this is path or selector
            $item['oldName'] = $repeaterItem->dbMigrateOldName; // for pages this is path
            $item['id'] = $repeaterItem->id;
            //bd($item, 'item');
            $count ++;
            $migrationItem = $this->getMigrationItemData($count, $item, $excludeAttributes, $excludeFields, $newOld, $compareType);
            $data[] = $migrationItem['data'];
            $files = array_merge_recursive($files, $migrationItem['files']);
        }
        //bd($data, 'data returned by cycleItems for ' . $newOld);
        return ['data' => $data, 'files' => $files];
    }


    /**
     * Determine whether or not an item should exist in the current database
     * Note that if $this is 'installable' ($this->meta('installable) ) then we are in its target database
     * If $newOld is 'new' then we are using the migration items as defined in the page
     * If $newOld is 'old' then we are using the mirror terms (reverse order and 'new' and 'removed' swapped)
     * @param $compareType
     * @param $action
     * @return boolean
     */
protected function shouldExist($action, $compareType) {
    if ($action == 'changed') return true;
    // changed items should exist in source and target
    $actInd = ($action == 'new') ? 1 : 0;
    $newInd = ($compareType == 'new') ? 1 : 0;
    $sourceInd = (!$this->meta('installable')) ? 1 : 0;
    $ind = $actInd + $newInd + $sourceInd;
    return ($ind & 1); // test if odd by bit checking.
}


    /**
     * Get the migration data for an individual item
     * @param $k
     * @param $item
     * @param $excludeAttributes
     * @param $excludeFields
     * @param $newOld
     * @param $compareType
     * @return array
     * @throws WireException
     * @throws WirePermissionException
     */
    protected function getMigrationItemData($k, $item, $excludeAttributes, $excludeFields, $newOld, $compareType) {
        $data = [];
        $files = [];
        $empty = ['data' => [], 'files' => []];

        if (!$item['type'] or !$item['action'] or !$item['name']) {
           if ($newOld == 'new' and $compareType == 'new') {
               $this->wire()->notices->warning('Missing values for item ' . $k);
               //bd($item, 'missing values in item');
           }
            return $empty;
        }

        $itemName = $item['name'];  // This will be the name in the current environment
        if ($item['oldName']) {
            $isOld = $this->wire($item['type'])->get($item['oldName']);
            $isNew = $this->wire($item['type'])->get($item['name']);
            if ($isNew and $isNew->id and $isOld and $isOld->id) {
                $this->wire()->notices->warning("Both new name ({$item['name']}) and old name ({$item['oldName']}) exist in the database. Please use unique names.");
                return $empty;
            }
            if ($isOld and $isOld->id) {
                $itemName = $item['oldName'];
                //bd($item['oldName'], 'using old name');
            } elseif (!$isNew or !$isNew->id) {
                $this->wire()->notices->warning("Neither new name ({$item['name']}) nor old name ({$item['oldName']}) exist in the database.");
                return $empty;
            }

        }

        $pagePaths = [];
        // Need to unpack selectors here
        if ($item['type'] == 'pages') {
            if (!$this->wire()->sanitizer->path($itemName)) {
                //bd($itemName, 'Selector provided instead of path name');
                // we have a selector
                try {
                    $pagePaths = $this->wire()->pages->find($itemName);
                    $pagePaths = $pagePaths->getArray(); // want them as a php array not an object
                    // the array is of page objects, but that's OK here because getExportPageData allows objects or path names
                } catch (WireException $e) {
                    $this->wire()->notices->error('Invalid selector: ' . $itemName);
                    return $empty;
                }
            } else {
                // otherwise it should just be a single path - include the old name after a pipe, if available
                $path = $item['name'];
                $oldPath = ($item['oldName']) ? '|' . $item['oldName'] : '';
                $pagePaths = [$path . $oldPath];
            }

            //bd($pagePaths, 'Paths for ' . $item['action'] . ' ' . $item['name'] . ' in context ' . $compareType);
        }

        $shouldExist = $this->shouldExist($item['action'], $compareType); //should the item exist as a database object in this context?
        //bd($shouldExist, $item['type'] . ' - ' . $item['action'] . ' - ' . $itemName . ' should exist in context  ' . $compareType);

        try {
            $object = $this->wire($item['type'])->get($itemName);
        } catch (WireException $e) {
            $this->wire()->notices->error('Invalid name: ' . $itemName);
            return $empty;
        }
        //bd($object, 'object is');
        if (!$object or !$object->id or $object->id == 0 and $newOld != 'compare') {
            if ($shouldExist) $this->wire()->notices->warning($this->name . ': No database object for ' . $itemName);
            $data = $data = [$item['type'] => [$item['action'] => [$itemName => []]]];
            return ['data' =>$data, 'files' => []];
        } elseif (!$shouldExist and $newOld == 'new') {                               // 2nd condition is to avoid double reporting for new and old
            $this->wire()->notices->warning("{$this->name}: There is already a database object for $itemName but none should exist");
        }
        
        if ($item['type'] == 'pages') {
            $exportPages = $this->getExportPageData($pagePaths, $excludeFields);
            $objectData = $exportPages['data'];
            //bd($exportPages['files'], '$exportPages[files]');
            $files = $exportPages['files'];
            //bd($objectData, 'object data for paths ' . implode(', ', $pagePaths));
            foreach ($objectData as $p => $f) {
                foreach ($excludeFields as $excludeField) {
                    unset($objectData[$p][$excludeField]);
                }
            }
        } else {
            $objectData = $object->getExportData();
            if (isset($objectData['id'])) unset($objectData['id']);  // Don't want ids as they may be different in different dbs
            //bd($objectData, 'objectdata');
            if ($item['type'] == 'fields') {
                // enhance repeater field data
                if ($objectData['type'] == 'FieldtypeRepeater') {
                    $f = $this->wire('fields')->get($objectData['name']);
                    if ($f) {
                        $templateId = $f->get('template_id');
                        $templateName = $this->wire('templates')->get($templateId)->name;
                        $objectData['template_name'] = $templateName;
                    }
                    // and check that the corresponding template is earlier in the migration
                    $templateOk = false;
                    $i = 0;
                    $allItems = $this->dbMigrateItem;
                    if (isset($templateName)) {
                        //bd($templateName, 'templatename');
                        foreach ($allItems as $other) {
                            /* @var $other RepeaterDbMigrateItemPage */
                            //bd($other, 'other - item ' . $i);
                            //bd($other->dbMigrateType, 'other type');
                            if ($i >= $k) break;
                            $i++;
                            if ($other->dbMigrateType->value == 'templates' and $other->dbMigrateName == $templateName) {
                                $templateOk = true;
                                break;
                            }
                        }
                        if (!$templateOk and $newOld == 'new') {
                            $w2 = ($item['action'] = 'new') ? '. Template ' . $templateName . ' should be specified (as new) before the repeater' :
                                '. If it has changed, consider if template ' . $templateName . ' should be specified (as changed) before the repeater';
                            $this->wire()->notices->warning("No template specified earlier in installation for repeater {$objectData['name']}  $w2");
                        }

                    }
                }
            }
            foreach ($excludeAttributes as $excludeAttribute) {
                unset($objectData[$excludeAttribute]);
            }
            $objectOldName = ($item['oldName']) ? '|' . $item['oldName'] : '';
            $objectData = [$item['name'] . $objectOldName => $objectData];
        }

        $data = [$item['type'] => [$item['action'] => $objectData]];

        return ['data' => $data, 'files' => $files];
    }


    /**
     * Converts 3 indexes to one: type->action->name
     * Used for presentation purposes in previews
     * @param $data
     * @return array
     * @throws WireException
     */
    public function compactArray($data) {
        $newData = [];
        foreach ($data as $entry) {
            if (is_array($entry)) foreach ($entry as $type => $line) {
                if ($type == 'sourceDb') continue; // Ignore source database tags in comparisons
                if (is_array($line)) foreach ($line as $action => $item) {
                    if (is_array($item)) foreach ($item as $name => $values) {
                        if ($type == 'pages' and $action == 'removed' and !$this->wire()->sanitizer->path($name)) {
                            // don't want removed pages with selector as they get expanded elsewhere (ToDo Consider deleting $action=='removed' condition as probably irrelevant)
                            continue;
                        }
                        if (isset($values['id'])) unset($values['id']);
                        $newData[$type. '->' . $action . '->' . $name] = $values;
                    }
                }
            }
        }
        return $newData;
    }


    /**
     * Remove url and path from reported differences in image fields
     * They will almost always be different in the source and target databases
     * @param $diffs
     * @return mixed
     * @throws WireException
     * @throws WirePermissionException
     */
    public function pruneImageFields($diffs) {
        //bd($diffs, 'Page diffs before unset');
        // Prune the result for any image/file fields with url/path/modified/created mismatches (as this will probably always differ)
        foreach ($diffs as $pName => $data) {
            // $data should be a 2-item array
            if (is_array($data)) {
                if (strpos($pName, 'pages') === 0) {
                    $diffsRemain = false;
                    foreach ($data as $fName => $values) {
                        if (!$values or !is_array($values) or count($values) == 0) {
                            $diffsRemain = true;
                            continue;
                        }
                        $field = $this->wire('fields')->get($fName);
                        if ($field and ($field->type == 'FieldtypeImage' or $field->type == 'FieldtypeFile')) {
                            if (isset($values['url'])) unset($diffs[$pName][$fName]['url']);
                            if (isset($values['path'])) unset($diffs[$pName][$fName]['path']);
                            if (is_array($diffs[$pName][$fName]) and count($diffs[$pName][$fName]) > 0) {
                                $diffsRemain = true;
                                //bd($diffs[$pName][$fName], 'remaining diffs in images');
                            }
                        }
                        if ($field and $field->type == 'FieldtypeTextarea') {
                            //bd($values, 'textarea values');
//                            $newVals = [];
//                            foreach ($values as $value) {
//                                $re = '/\/files\/\d*\//m';
//                                $newVals[] = preg_replace($re, '/files/xxxx/', $value);
//                            }
                            //bd($this->meta('idMap'), 'id map in prune');
                            $values[1] = $this->replaceImgSrc($values[1], $this->meta('idMap'));
                            $diffs[$pName][$fName] = $values;
                            if ($values[0] != $values[1]) {
                                $diffsRemain = true;
                            } else {
                                unset($diffs[$pName][$fName]);
                            }
                            //bd($values, 'textarea values after replace');
                        }
                    }
                    if (!$diffsRemain) {
                        foreach ($data as $fName => $values) {
                            unset($diffs[$pName][$fName]);
                        }
                    }
                    if (!$diffsRemain and is_array($diffs[$pName]) and count($diffs[$pName]) == 0) unset($diffs[$pName]);
                }
            }
        }
        //bd($diffs, 'Page diffs after unset');
        return $diffs;
    }

    /**
     * exportData
     * This is  run in the 'source' database to export the migration data ($newOld = 'new')
     * It is also run in the target database on first installation of a migration to capture the pre-installation state ($newOld = 'old')
     * Running with $newOld = 'compare' creates cache files ('new-data.json' and 'old-data.json') for the current state,
     *    to compare against data.json files in 'new' and 'old' directories
     *
     * @param $newOld
     * @return array|void|null
     * @throws WireException
     * @throws WirePermissionException
     */
    public function exportData($newOld) {

        /*
         * INITIAL PROCESSING
         */
        if (!$this->ready) $this->ready();
        //bd($this, 'In exportData with newOld = ' . $newOld);
        $excludeFields = (isset($this->configData['exclude_fieldnames'])) ? str_replace(' ', '', $this->configData['exclude_fieldnames']) : '';
        $excludeFields = $this->wire()->sanitizer->array(str_replace(' ', '', $excludeFields), 'fieldName', ['delimiter' => ' ']);
        $excludeTypes = (isset($this->configData['exclude_fieldtypes'])) ? str_replace(' ', '', $this->configData['exclude_fieldtypes']) : '';
        $excludeTypes = $this->wire()->sanitizer->array(str_replace(' ', '', $excludeTypes), 'fieldName');
        $excludeTypes = array_merge($excludeTypes, self::EXCLUDE_TYPES);
        $excludeFieldsForTypes = $this->excludeFieldsForTypes($excludeTypes);
        $excludeFields = array_merge($excludeFields, $excludeFieldsForTypes);
        //bd($configData, '$configData in exportData');
        $excludeAttributes = (isset($configData['exclude_attributes'])) ? str_replace(' ', '', $configData['exclude_attributes']) : '';
        $excludeAttributes = $this->wire()->sanitizer->array(str_replace(' ', '', $excludeAttributes));
        $excludeAttributes = array_merge($excludeAttributes, self::EXCLUDE_ATTRIBUTES);
        $result = null;
        $migrationPath = $this->migrationsPath . $this->name . '/';
        $migrationPathNewOld = $migrationPath . $newOld . '/';
        if ($newOld != 'compare') {
            if ($newOld == 'old' and is_dir($migrationPathNewOld)) return;  // Don't over-write old directory once created
            if (!is_dir($migrationPathNewOld)) if (!wireMkdir($migrationPathNewOld, true)) {          // wireMkDir recursive
                throw new WireException("Unable to create migration directory: $migrationPathNewOld");
            }
            if (!is_dir($migrationPathNewOld . 'files/')) if (!wireMkdir($migrationPathNewOld . 'files/', true)) {
                throw new WireException("Unable to create migration files directory: {$migrationPathNewOld}bootstrap/");
            }
        }
        /*
         * GET DATA FROM PAGE AND SAVE IN JSON
         */
        $itemRepeater = $this->dbMigrateItem;
        $items = $this->cycleItems($itemRepeater, $excludeAttributes, $excludeFields, $newOld, 'new');
        $data = $items['data'];
        $files['new'] = $items['files'];
        $reverseItems = $this->cycleItems($itemRepeater, $excludeAttributes, $excludeFields, $newOld, 'old'); // cycleItems will reverse order for uninstall
        $reverseData = $reverseItems['data'];
        $files['old'] = $reverseItems['files'];
        $objectJson['new'] = wireEncodeJSON($data, true, true);
        //bd($objectJson['new'], 'New json created');
        $objectJson['old'] = wireEncodeJSON($reverseData, true, true);
        //bd($objectJson, '$objectJson ($newOld = ' . $newOld . ')');
        if ($newOld != 'compare') {
            file_put_contents($migrationPathNewOld . 'data.json', $objectJson[$newOld]);
            $this->wire()->notices->message('Exported object data as ' . $migrationPathNewOld . '"data.json"');
            //bd($files[$newOld], '$files[$newOld]');
            foreach ($files[$newOld] as $fileArray) {
                foreach ($fileArray as $id => $baseNames) {
                    $filesPath = $this->wire('config')->paths->files . $id . '/';
                    if (!is_dir($migrationPathNewOld . 'files/' . $id . '/')) if (!wireMkdir($migrationPathNewOld . 'files/' . $id . '/', true)) {
                        throw new WireException("Unable to create migration files directory: {$migrationPathNewOld}files/{$id}/");
                    }
                    if (is_dir($filesPath)) {
                        $copyFiles = [];
                        foreach ($baseNames as $baseName) {
                            //bd($baseName, 'Base name for id ' . $id);
                            if (is_string($baseName)) {
                                $copyFiles[] = $filesPath . $baseName;
                            } elseif (is_array($baseName)) {
                                $copyFiles = array_merge($copyFiles, $baseName);
                            }
                        }
                        //bd($copyFiles, 'copyfiles');
                        foreach ($copyFiles as $copyFile) {
                            if (file_exists($copyFile)) {
                                $this->wire()->files->copy($copyFile, $migrationPathNewOld . 'files/' . $id . '/');
                                $this->wire()->notices->message('Copied file ' . $copyFile . ' to ' . $migrationPathNewOld . 'files/' . $id . '/');
                            }
                        }
                    }
                }
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
        $migrationData = $this->getMigrationItemData(null, $item, $excludeAttributes, $excludeFields, $newOld, 'new')['data'];
        if (isset($this->configData['database_name'])) $migrationData['sourceDb'] = $this->configData['database_name'];
        $migrationObjectJson = wireEncodeJSON($migrationData, true, true);
        if ($newOld != 'compare') {
            file_put_contents($migrationPathNewOld . 'migration.json', $migrationObjectJson);
            $this->wire()->notices->message('Exported object data as ' . $migrationPathNewOld . '"data.json"');
        }

        /*
         * COMPARE CURRENT STATE WITH NEW / OLD STATES
         */
        if ($newOld == 'compare') {
            $cachePath = $this->wire()->config->paths->assets . 'cache/dbMigrate/';
            if (!is_dir($cachePath)) if (!wireMkdir($cachePath, true)) {          // wireMkDir recursive
                throw new WireException("Unable to create cache migration directory: $cachePath");
            }
            if ($data and $objectJson) {
                //bd($migrationPath, 'migrationPath');
                $newFile = (file_exists($migrationPath . 'new/data.json')) ? file_get_contents($migrationPath . 'new/data.json') : null;
                $oldFile = (file_exists($migrationPath . 'old/data.json')) ? file_get_contents($migrationPath . 'old/data.json') : null;
                file_put_contents($cachePath . 'old-data.json', $objectJson['old']);
                file_put_contents($cachePath . 'new-data.json', $objectJson['new']);
//                $cmpFile = (file_exists($cachePath . 'data.json')) ? file_get_contents($cachePath . 'data.json') : null;
                // sequence is unimportant
                //bd($newFile, 'New file');
                $newArray = $this->compactArray(wireDecodeJSON($newFile));
                $oldArray = $this->compactArray(wireDecodeJSON($oldFile));
                $cmpArray['new'] = $this->compactArray(wireDecodeJSON($objectJson['new']));
                $cmpArray['old'] = $this->compactArray(wireDecodeJSON($objectJson['old']));
                $R = $this->array_compare($newArray, $cmpArray['new']);
                $R = $this->pruneImageFields($R);
                //bd($R, ' array compare new->cmp');
                $jsonR = wireEncodeJSON($R);
                //bd($jsonR, ' array compare json new->cmp');
                $installedData = (!$R);
                $installedDataDiffs = $R;
                $R2 = $this->array_compare($oldArray, $cmpArray['old']);
                $R2 = $this->pruneImageFields($R2);
                //bd($R2, ' array compare old->cmp');
                $jsonR2 = wireEncodeJSON($R2);
                //bd($jsonR2, ' array compare json old->cmp');
                $uninstalledData = (!$R2);
                $uninstalledDataDiffs = $R2;
            } else {
                $installedData = true;
                $uninstalledData = true;
                $installedDataDiffs = [];
                $uninstalledDataDiffs = [];
            }

            /*
             * MIGRATION COMPARISON
            */
            // Migration comparison is only required in source database, to flag migration scope changes as pending
            if (!$this->meta('installable') and $this->data) {
                $newMigFile = (file_exists($migrationPath . 'new/migration.json')) ? file_get_contents($migrationPath . 'new/migration.json') : null;
                $oldMigFile = (file_exists($migrationPath . 'old/migration.json')) ? file_get_contents($migrationPath . 'old/migration.json') : null;

                if ($migrationObjectJson) {
                    file_put_contents($cachePath . 'migration.json', $migrationObjectJson);
                } else {
                    if (is_dir($cachePath) and file_exists($cachePath . 'migration.json')) unlink($cachePath . 'migration.json');
                }
                $cmpMigFile = (file_exists($cachePath . 'migration.json')) ? file_get_contents($cachePath . 'migration.json') : null;

                $R = $this->array_compare($this->compactArray(wireDecodeJSON($newMigFile)), $this->compactArray(wireDecodeJSON($cmpMigFile)));
                $R = $this->pruneImageFields($R);
                $installedMigration = (!$R);
                $installedMigrationDiffs = $R;
                $R2 = $this->array_compare(wireDecodeJSON($oldMigFile), wireDecodeJSON($cmpMigFile));
                $R2 = $this->pruneImageFields($R2);
                $uninstalledMigration = (!$R2);
                $uninstalledMigrationDiffs = $R2;
            } else {
                $installedMigration = true;
                $uninstalledMigration = true;
                $installedMigrationDiffs = [];
                $uninstalledMigrationDiffs = [];
            }


            $installed = ($installedData and $installedMigration);
            $uninstalled = ($uninstalledData and $uninstalledMigration);
//            $status = ($installed) ? 'installed' : (($uninstalled) ? 'uninstalled' : 'indeterminate');
//            $status = ($installed and $uninstalled) ? 'void' : $status;
            $locked = ($this->meta('locked'));
            if ($this->meta('installable')) {
                if ($installed) {
                    if ($uninstalled) {
                        $status = 'void';
                    } else {
                        $status = 'installed';
                    }
                } elseif ($uninstalled) {
                    $status = 'uninstalled';
                } elseif ($locked) {
                    $status = 'superseded';
                } else {
                    $status = 'indeterminate';
                }
            } else {
                if ($installed) {
                    $status = 'exported';
                } elseif ($locked) {
                    $status = 'superseded';
                } else {
                    $status = 'pending';
                }
            }
            $result = [
                'status' => $status,
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
            ];
            //bd($result, 'result');
        }
        return $result;

    }


    /**
     * Set import data for fields
     *
     * This is a direct lift from ProcessFieldExportImport with certain lines commented out as not required or amended with MDE annotation
     * It has now been so heavily hacked that it should probably be rewritten
     * @param $data array - decoded from JSON
     * @throws WireException
     * @throws WirePermissionException
     */
    protected function processImport(array $data) {         //MDE parameter added

//        $data = $this->session->get('FieldImportData');  //MDE not required
        if(!$data) throw new WireException("Invalid import data");

        $numChangedFields = 0;
        $numAddedFields = 0;
//        $skipFieldNames = array(); //MDE not applicable

        // iterate through data for each field
        foreach($data as $name => $fieldData) {

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
                $field = new Field();
                $field->name = $name;
            } else {
                $new = false;
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
                //bd($changes, 'changes in processimport');
                //MDE modified this section to provide better notices but suppress error re importing options
                foreach($changes as $key => $info) {
                    if ($info['error'] and strpos($key, 'export_options') !==0) {  // options have been dealt with by fix below, so don't report this error
                        //bd(get_class($field->type), 'reporting error');
                        $this->wire()->notices->error($this->_('Error:') . " $name.$key => $info[error]");
                    } else {
                        $this->message($this->_('Saved:') . " $name.$key => $info[new]");
                    }
                }
                // MDE end of mod
                $field->save();
                // MDE section added to deal with select options fields, which setImportData() does not fully handle
                if ($field->type == 'FieldtypeOptions') {
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
            } catch(\Exception $e) {
                $this->error($e->getMessage());
            }

            $data[$name] = $fieldData;
        }

//        $this->session->set('FieldImportSkipNames', $skipFieldNames);  //MDE not applicable
//        $this->session->set('FieldImportData', $data); //MDE not applicable
//        $numSkippedFields = count($skipFieldNames);  //MDE not applicable
        if($numAddedFields) $this->message(sprintf($this->_n('Added %d field', 'Added %d fields', $numAddedFields), $numAddedFields));
        if($numChangedFields) $this->message(sprintf($this->_n('Modified %d field', 'Modified %d fields', $numChangedFields), $numChangedFields));
//        if($numSkippedFields) $this->message(sprintf($this->_n('Skipped %d field', 'Skipped %d fields', $numSkippedFields), $numSkippedFields)); //MDE not applicable
//        $this->session->redirect("./?verify=1");  //MDE not applicable
    }


    /**
     * Finds $values which are repeaters and moves them out of $values into $repeaters
     * @param $values
     * @return array
     * @throws WireException
     * @throws WirePermissionException
     */
public function getRepeaters($values) {
    $repeaters = [];
    foreach ($values as $fieldName => $data) {
        $f = $this->wire('fields')->get($fieldName);
        if ($f and $f->type == 'FieldtypeRepeater') {
            $repeaterItems = [];
            unset($values[$fieldName]);
            foreach ($data as $datum) {
                if (isset($datum['data'])) $repeaterItems[] = $datum['data'];
            }
            $repeaters[$fieldName] = $repeaterItems;
        }
    }
    return ['repeaters' => $repeaters, 'values' => $values];
}

    /**
     * This installs ('new') or uninstalls ('old') depending on directory with json files
     * @param $newOld
     * @throws WireException
     * @throws WirePermissionException
     */
    public function installMigration($newOld) {
        if (!$this->ready) $this->ready();
       //$this, 'In install with newOld = ' . $newOld);
        // Backup the old installation first
        if ($newOld == 'new' and $this->name != 'dummy-bootstrap') $this->exportData('old');
        //NB The bootstrap is excluded from the above. A separate 'old' file is provided with the module and is used when uninstalling the module.
        $name = ($this->name == 'dummy-bootstrap') ? 'bootstrap' : $this->name;

        // Get the migration .json file for this migration
        $migrationPath = $this->wire('config')->paths->templates . self::MIGRATION_PATH  . $name . '/';  // NB cannot use $this->migrationsPath because as it fails with bootstrap (no template yet!)
        $migrationPathNewOld = $migrationPath . $newOld . '/';
        if (!is_dir($migrationPathNewOld)) {
            //bd($migrationPath, '$migrationPath. Name is ' . $name);
            $error = ($newOld == 'new') ? 'Cannot install - ' : 'Cannot uninstall - ';
            $error .= 'No "' . $newOld . '" directory for this migration.';
            $this->wire()->notices->error($error);
            return;
        }
        $dataFile = (file_exists($migrationPathNewOld . 'data.json')) ? file_get_contents($migrationPathNewOld . 'data.json') : null;
        if (!$dataFile) {
            $error = ($newOld == 'new') ? 'Cannot install - ' : 'Cannot uninstall - ';
            $error .= 'No "' . $newOld . '" data.json file for this migration.';
            if ($name == 'bootstrap') {
                $error .= ' Copy the old/data.json file from the module directory into the templates directory then try again?';
            }
            $this->wire()->notices->error($error);
            return;
        }
        $dataArray = wireDecodeJSON($dataFile);

        $message = [];
        $warning = [];
        $pagesInstalled = [];
        foreach ($dataArray as $repeat) {
            foreach ($repeat as $itemType => $itemLine) {
                //bd($itemLine, 'line');
                foreach ($itemLine as $itemAction => $items) {
                    //bd($items, 'items');
                    if ($itemAction != 'removed') {
                        switch ($itemType) {
                            // NB code below should handle multiple instances of objects, but only expect one at a time for fields and templates
                            case 'fields':
                                $this->installFields($items, $itemType);
                                break;
                            case 'templates' :
                                $this->installTemplates($items, $itemType);
                                break;
                            case 'pages' :
                                $pagesInstalled = array_merge($pagesInstalled, $this->installPages($items, $itemType, $newOld));
                                break;
                        }
                    } else {
                        $this->removeItems($items, $itemType);
                    }
                }
            }
        }
        if (!$this->name == 'dummy-bootstrap') {
        // update any images in RTE fields
        $idMapArray = $this->setIdMap($pagesInstalled);
        //bd($idMapArray, 'idMapArray');
        foreach($pagesInstalled as $page) {
            //bd($page, 'RTE? page');
            foreach ($page->getFields() as $field) {
                //bd([$page, $field], 'RTE? field');
                if ($field->type == 'FieldtypeTextarea') {
                    //bd([$page, $field], 'RTE field Y');
                    //bd($page->$field, 'Initial html');
                    $html = $page->$field;
                    $html = $this->replaceImgSrc($html, $idMapArray);
                    //bd($html, 'returned html');
                    $page->$field = $html;
                    $page->of(false);
                    $page->save($field);
                }
            }
        }
        }

        if ($message) $this->wire()->notices->message(implode(', ', $message));
        if ($warning) $this->wire()->notices->warning(implode(', ', $warning));
        //bd($newOld, 'finished install');
    }


    /**
     * Return an array of pairs origId => destId to map the ids of 'identical' pages in the source and target databases
     * This array is also stored in a meta value 'idMap' so that it is usable later
     * @param $pagesInstalled
     * @return array
     */
    protected function setIdMap($pagesInstalled) {
        $idMapArray = [];
        if (is_array($pagesInstalled)) foreach ($pagesInstalled as $page) {
            //bd($page, 'page in getidmap');
            if ($page and $page->meta('origId')) $idMapArray[$page->meta('origId')] = $page->id;
        }
        $this->meta('idMap', $idMapArray);
        return $idMapArray;
    }


    /**
     * Using the idMapArray (see setIdMap() ) replace original page id directories in <img> tags with destination page id directories
     * @param $html
     * @param $idMapArray
     * @return string|string[]|null
     * @throws WireException
     */
    protected function replaceImgSrc($html, $idMapArray) {
        if (!$idMapArray) return $html;
        if (strpos($html, '<img') === false) return $html; //return early if no images are embedded in html
        foreach ($idMapArray as $origId => $destId) {
            //bd([$origId, $destId], 'Id pair');
            $re = '/(<img.*' . str_replace('/', '\/', preg_quote($files = $this->wire()->config->urls->files)) . ')' . $origId . '(\/.*>)/m';
            //bd($re, 'regex pattern');
            $html = preg_replace($re, '${1}' . $destId . '$2', $html);
        }
        return $html;
}



    /**
     * @param $items
     * @param $itemType
     * @throws WireException
     * @throws WirePermissionException
     */
    protected function installFields($items, $itemType) {
        $items = $this->pruneKeys($items, $itemType);
        // repeater fields should be processed last as they may depend on earlier fields
        $repeaters = [];
        // remove them then ....
        foreach ($items as $name => $data) {
            //bd($data, 'data in install');
            if ($data['type'] == 'FieldtypeRepeater') {
                unset($items[$name]);
                $repeaters[$name] = $data;
            }
        }
        // process the non-repeaters first
        // method below is largely from PW core
        if ($items) $this->processImport($items);
        // then check the templates for the repeaters - they should be before the related field in the process list
        foreach ($repeaters as $repeaterName => $repeater) {
            $templateName = 'repeater_' . $repeater['name'];
            $t = $this->wire()->templates->get($templateName);
            if (!$t) {
                $this->wire()->notices->error('Cannot install repeater ' . $repeaterName . ' because template ' . $templateName . ' is missing. Is it out of sequence in the installation list?');
                unset($repeaters[$repeaterName]);
            }
        }
        // Now install the repeaters
        // but first set the template id to match the template we have
        $newRepeaters = [];
        foreach ($repeaters as $fName => $fData) {
            $tName = $fData['template_name'];
            $t = $this->wire('templates')->get($tName);
            if ($t) {
                $fData['template_id'] = $t->id;
            }
            unset($fData['template_name']);  // it was just a temp variable - no meaning to PW
            $newRepeaters[$fName] = $fData;
        }
        if ($newRepeaters) $this->processImport($newRepeaters);
    }


    /**
     * @param $items
     * @param $itemType
     * @throws WireException
     * @throws WirePermissionException
     */
    protected function installTemplates($items, $itemType) {
        $items = $this->pruneKeys($items, $itemType);
        foreach ($items as $name => $data) {
            $t = $this->wire('templates')->get($name);
            if ($t) {
                $result = $t->setImportData($data);
            } else {
                $t = $this->wire(new Template());
                /* @var $t Template */
                $result = $t->setImportData($data);
            }
            //bd($result, 'template result');
            if (isset($t) and $t and $result) {
                $this->saveItem($t);
                $this->wire()->notices->message('Saved new settings for ' . $name);
            } else {
                if ($result) {
                    $this->wire()->notices->warning(implode('| ', $result));
                } else {
                    $this->wire()->notices->message('No changes to ' . $name);
                }
            }
        }
    }


    /**
     * Install any pages in this migration
     * NB any hooks associated with pages will operate (perhaps more than once) ...
     * NB to alter any operation of such hooks etc., note that the session variable of 'installPages' is set for the duration of this method
     * @param $items
     * @param $itemType
     * @param $newOld
     * @return array
     * @throws WireException
     * @throws WirePermissionException
     */
    protected function installPages($items, $itemType, $newOld) {
        $this->wire()->session->set('dbMigrate_installPages', true);  // for use by host app
        $items = $this->pruneKeys($items, $itemType);
        //bd($items, 'install pages');
        $pagesInstalled = [];
        foreach ($items as $name => $data) {
            $p = $this->wire('pages')->get($name);
            /* @var $p DefaultPage */
            $pageIsHome = ($data['parent'] === 0);
            if ($this->name == 'dummy-bootstrap' and $name == self::SOURCE_ADMIN . self::MIGRATION_PARENT) {
                // Original admin url/path used in bootstrap json may differ from target system
                $parent = $this->wire()->pages ->get(2); // admin root
                $data['parent'] = $parent->path;
            } else {
                $parent = $this->wire()->pages->get($data['parent']);
            }
            //bd($data['parent'], 'data[parent]');

            //bd($parent, 'PARENT');
            $template = $this->wire()->templates->get($data['template']);
            if (!$pageIsHome and (!$parent or !$parent->id or !$template or !$template->id)) {
                $this->wire()->notices->error('Missing parent or template for page "' . $name . '". Page not created/saved.');
                break;
            }
            $data['parent'] = ($pageIsHome) ? 0 : $parent;
            $data['template'] = $template;

            $fields = $data;
            if  (isset($fields['id'])) {
                $origId = $fields['id'];

                // remove things that are not fields
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
            if ($p and $p->id) {
                $p->parent = $data['parent'];
                $p->template = $data['template'];
                $p->status = $data['status'];
                $fields = $this->setAndSaveComplex($fields, $p); // sets and saves complex fields, returning the other fields
                $p->of(false);
                $p->save();
                $fields = $this->setAndSaveFiles($fields, $newOld, $p); // saves files and images, returning other fields
                //bd($fields, 'fields to save');
                $p->setAndSave($fields);
                $this->setAndSaveRepeaters($repeaters, $p);
                $this->wire()->notices->message('Set and saved page ' . $name);
            } else {
                $template = $this->wire()->templates->get($data['template']);
                $pageClass = $template->getPageClass();
                $p = new $pageClass();
                $p->name = $name;
                $p->template = $data['template'];
                $p->status = $data['status'];
                $p->parent = $data['parent'];
                $p->of(false);
                $p->save();
                $p = $this->wire()->pages->get($p->id);
                $fields = $this->setAndSaveComplex($fields, $p); // sets and saves complex fields, returning the other fields
                $fields = $this->setAndSaveFiles($fields, $newOld, $p); // saves files and images, returning other fields
                $p->setAndSave($fields);
                $this->setAndSaveRepeaters($repeaters, $p);
                $this->wire()->notices->message('Created page ' . $name);
            }
            if ($origId) $p->meta('origId', $origId); // Save the id of the originating page for matching purposes
            $p->of(false);
            $p->save();
            $pagesInstalled[] = $p;
        }
        $this->wire()->session->remove('dbMigrate_installPages');
        return $pagesInstalled;
    }


    /**
     * NB any hooks associated with page->trash will operate
     * NB to alter any operation of such hooks etc., note that the session variable of 'removeItems' is set for the duration of this method
     * @param $items
     * @param $itemType
     * @return null
     * @throws WireException
     * @throws WirePermissionException
     */
    protected function removeItems($items, $itemType) {
        $this->wire()->session->set('dbMigrate_removeItems', true); // for use by host app
        //bd($items, 'items for deletion. item type is ' . $itemType);
        switch ($itemType) {
            case 'pages' :
                $this->wire('pages')->uncacheAll(); // necessary - otherwise PW may think pages have children etc. which have in fact just been deleted
                foreach ($items as $name => $data) {
                    // For new and changed pages, selector will have been decoded on export. However, for removed pages, decode needs to happen on install
                    if (!$this->wire()->sanitizer->path($name)) {
                        //bd($name, 'In removeItems - Selector provided instead of path name');
                        // we have a selector
                        try {
                            $pages = $this->wire()->pages->find($name);
                            $pages = $pages->getArray(); // want them as a php array not an object
                            // the array is of page objects, but that's OK here because getExportPageData allows objects or path names
                        } catch (WireException $e) {
                            $this->wire()->notices->error('In removeItems - Invalid selector: ' . $name);
                            return null;
                        }
                    } else {
                        $pages = [$this->wire('pages')->get($name)];
                    }
                    foreach ($pages as $p) {
                        if ($p and $p->id) {
                            // find and remove any images and files before deleting the page
                            $p->of(false);
                            $fields = $p->getFields();
                            foreach ($fields as $field) {
                                //bd($field, 'field to remove');
                                if ($field->type == 'FieldtypeImage' or $field->type == 'FieldtypeFile') {
                                    $p->$field->deleteAll();
                                }
                                if ($field->type == 'Pageimages' or $field->type == 'Pagefiles') {
                                    $p->$field->deleteAll();
                                }
                            }
                            $p->save();
                            //bd($p, '$p before delete');
                            //bd([$p->parent->name, $p->name], ' Parent and name');
                            //bd([trim($this->adminPath, '/'), trim(self::MIGRATION_PARENT, '/')], 'comparators');
                            if ($this->name == 'bootstrap' and $p->parent->name == trim($this->adminPath, '/') and $p->name  == trim(self::MIGRATION_PARENT, '/')) {
                                //bd($p, 'Deleting children too');

                                // we are uninstalling the module so remove all migration pages!
                                $p->trash(); // trash before deleting in case any hooks need to operate
                                $p->delete(true);
                            } else {
                                //bd($p, 'Only deleting page - will not delete if there are children. (This is ' . $this->name . ')');
                                try {
                                    $p->trash(); // trash before deleting in case any hooks need to operate
                                    $p->delete();
                                } catch (WireException $e) {
                                    $this->wire()->notices->error('Page: ' . $this->name . $e->getMessage());
                                }
                            }
                        }
                    }
                }
                break;
            case 'templates' :
                foreach ($items as $name => $data) {
                    //bd($name, 'deleting ' . $itemType);
                    $object = $this->wire($itemType)->get($name);
                    if ($object) {
                        $fieldgroup = $object->fieldgroup;
                        $this->wire($itemType)->delete($object);
                        $this->wire('fieldgroups')->delete($fieldgroup);
                    }
                }
                break;
            case 'fields' :
                foreach ($items as $name => $data) {
                    //bd($name, 'deleting ' . $itemType);
                    $object = $this->wire($itemType)->get($name);
                    if ($object) $this->wire($itemType)->delete($object);
                }
                break;
        }
        $this->wire()->session->remove('dbMigrate_removeItems');
    }

    /**
     * Refresh the migration page from migration.json data (applies to installable pages only - i.e. in target database
     * If the page has been (partially) installed, then it will not refresh because doing so resets the 'old' json to take account of the new scope
     * We only want the original uninstalled data in the old json, so the user must uninstall first before applying the new migration definition
     *
     * @param null $found
     * @return bool
     * @throws WireException
     * @throws WirePermissionException
     */
    public function refresh($found=null) {

        if (!$this->ready) $this->ready();
        // get the migration details
        $migrationPath = $this->migrationsPath . $this->name;
        if (!$found) {
            if (is_dir($migrationPath)) {
                $found = $migrationPath . '/new/migration.json';
            }
        }
        if (!file_exists($found)) {
            if ($this->meta('installable')) $this->wire()->notices->error('migration.json not found');
            return false;
        }
        $fileContents = wireDecodeJSON(file_get_contents($found));
        // set installable status according to database name
        $sourceDb = (isset($fileContents['sourceDb']) and $fileContents['sourceDb']) ? $fileContents['sourceDb'] : null;
        if ($sourceDb) {
            if ($this->configData['database_name'] and $sourceDb == $this->configData['database_name']) {
                if ($this->meta('installable')) $this->meta()->remove('installable');
            } else {
                if (!$this->meta('installable')) $this->meta('installable', true);
            }
        }
        if (isset($fileContents['sourceDb'])) unset($fileContents['sourceDb']);
        // set lock status according to presence of lockfile
        $migrationFolder = $this->migrationsPath . $this->name . '/';
        $migrationFiles = $this->wire()->files->find($migrationFolder);
        if (is_dir($migrationFolder) and in_array($migrationFolder . 'lockfile.txt', $migrationFiles)) {
            $this->meta('locked', true);
        } elseif ($this->meta('locked')) {
            $this->meta()->remove('locked');
        }
        // notify any conflicts
        $itemList = $this->listItems();
        if (!$this->meta('locked')) $this->checkOverlaps($itemList);
        //bd($this->meta('installable'), 'installable?');
        if (!$this->meta('installable')) return true;

        // for installable migrations (i.e. in target environment)...

        //bd($fileContents, 'already found file contents');

            // in practice there is only one item in the array as it is just for the migration page itself
        foreach ($fileContents as $type => $content) {
            //bd($content, 'content item');
            foreach ($content as $line) {
                foreach ($line as $pathName => $values) {
                    $pageName = $values['name'];
                    if ($this->name != $pageName) $this->wire()->notices->warning('Page name in migrations file is not the same as the host folder.');
                    $p = $this->migrations->get("name=$pageName, include=all");
                    /* @var $p MigrationPage */
                    // check if the definition has changed
                    $oldFile = $migrationPath . '/old/migration.json';
                    $fileTestCompare = [];
                    $fileCompare = [];
                    // only compare fields that actually affect the migration
                    $fieldsToCompare = [
                        'dbMigrateItem',
                        'dbMigrateRestrictFields'];
                    if (file_exists($oldFile)) {
                        $oldContents = wireDecodeJSON(file_get_contents($oldFile));
//                        $test1 = $this->reindexRepeaterItems($oldContents);
//                        $test2 = $this->getRepeaterItem($oldContents, '/images/');
//                        //bd($test1, 'TEST1');
//                        //bd($test2, 'TEST2');
// in practice there is only one item in the array as it is just for the migration page itself
                        if (isset($oldContents['sourceDb'])) unset($oldContents['sourceDb']);
                        foreach ($oldContents as $oldType => $oldContent) {
                            foreach ($oldContent as $oldLine) {
                                foreach ($oldLine as $oldPathName => $oldValues) {

//                                    $oldValues = (isset($oldContents[$type]['new'][$pathName])) ? $oldContents[$type]['new'][$pathName] : [];
                                    $oldTestValues = $oldValues;
                                    foreach ($oldTestValues as $k => $oldTestValue) {
                                        if (!in_array($k, $fieldsToCompare)) unset($oldTestValues[$k]);
                                    }
                                    $testValues = $values;
                                    foreach ($testValues as $k => $testValue) {
                                        if (!in_array($k, $fieldsToCompare)) unset($testValues[$k]);
                                    }
                                    $fileTestCompare = $this->array_compare($testValues, $oldTestValues);  // just the important changes
                                    $fileCompare = $this->array_compare($values, $oldValues); // all the changes
                                    //bd($fileTestCompare, '$fileTestCompare in refresh');
                                    //bd($fileCompare, '$fileCompare in refresh');
                                }
                            }
                        }
                    }
                    if ($fileTestCompare and !$this->exportData('compare')['uninstalled'] and !$this->name == 'bootstrap') {
                        $this->wire()->notices->warning("Migration definition has changed for $pageName \nYou must fully uninstall the current migration before refreshing the definition and installing the new migration.");
                        return false;
                    }
                    if (file_exists($oldFile) and !$fileCompare) continue;   // nothing changed at all so no action required
                    // So now we should have a new migration definition where the previous version has been uninstalled or the changes are only 'cosmetic'
                    // Delete the old files before continuing - a new version of these will be created when the new version of the migration is installed
                    // BUT only do this if the migration is fully uninstalled - do not do it when we are just updating cosmetic changes
                    if (is_dir($migrationPath . '/old/') and $this->exportData('compare')['uninstalled']) {
                        // Do not remove the old directory - retain as backup with date and time appended
                        $timeStamp = $this->wire()->datetime->date('Ymd-Gis');
                        $this->wire()->files->rename($migrationPath . '/old/', $migrationPath . 'old-' . $timeStamp . '/');
                    }
                    //bd($values, ' in page refresh with $values');
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
//            $values = $this->setFields($fields);
                    //bd($values, 'already found values');
                    //bd($p->meta('installable'), $p->name . ' installable?');
                    if ($p and $p->id and $p->meta() and $p->meta('installable')) {
                        $p->meta('allowSave' , true);  // to allow save
                        $p->setAndSave($values);
                        if (count($repeaters) > 0) $this->setAndSaveRepeaters($repeaters, $p);
                        $p->meta()->remove('allowSave');  // reset
                    } else {
                        $p->setAndSave($values);
                        if (count($repeaters) > 0) $this->setAndSaveRepeaters($repeaters, $p);
                    }
                }
            }
        }
        return true;
    }

    /**
     * Get array of page data, with some limitations to prevent unnecessary mismatching
     *
     * @param $exportPages
     * @param $excludeFields
     * @return array[]|null[]
     * @throws WireException
     */
    protected function getExportPageData($exportPages, $excludeFields) {
        $restrictFields = array_filter($this->wire()->sanitizer->array(str_replace(' ', '', $this->dbMigrateRestrictFields), 'fieldName', ['delimiter' => ' ']));
        $data = array();
        $files = array();
        $oldPage = '';
        $repeaterPages = array();
        // allow either page object or path as parameter. split out old names if path is a x|y pair
        foreach ($exportPages as $exportPage) {
            //bd($exportPage, 'exportpage');
            if (is_string($exportPage)) {
                if (strpos($exportPage,'|') > 0) {
                    $pathPairs = explode('|', $exportPage);
                    //bd($pathPairs, 'pairs');
                    $exportPage = $pathPairs[0];
                    $oldPage = $pathPairs[1];
                }
                $exportPageName = $this->wire()->sanitizer->path($exportPage);
                if ($exportPageName) {
                    $exportPage = $this->wire()->pages->get($exportPageName);
                } else {
                    $this->wire()->notices->error('Invalid Page to export: ' . $exportPage);
                    return ['data' => null];
                }
            }
            if (!$exportPage or !is_a($exportPage, 'Processwire\Page')  or !$exportPage->id) continue;
            // Now we have a page object we can continue
            $attrib = [];
            $attrib['template'] = $exportPage->template->name;
            $attrib['parent'] = ($exportPage->parent->path) ?: $exportPage->parent->id;  // id needed in case page is root
            $attrib['status'] = $exportPage->status;
            $attrib['name'] = $exportPage->name;
            $attrib['id'] = $exportPage->id;
            foreach ($exportPage->getFields() as $field) {
                $name = $field->name;
//                //bd($restrictFields, '$restrictFields');
                if ((count($restrictFields) > 0 and !in_array($name, $restrictFields)) or in_array($name, $excludeFields)) continue;
                $exportPageDetails = $this->getFieldData($exportPage, $field, $restrictFields, $excludeFields);
                $attrib = array_merge_recursive($attrib, $exportPageDetails['attrib']);
                $files[] = $exportPageDetails['files'];

            }
            $oldPath = ($oldPage) ? '|' . $oldPage : '';
            $data[$exportPage->path . $oldPath] = $attrib;
        }
        return ['data' => $data, 'files' => $files, 'repeaterPages' => $repeaterPages];
    }


    /**
     * @param array $repeaters
     * @param DbMigrationPage $page
     * @param bool $remove
     * @throws WireException
     */
    public function setAndSaveRepeaters(array $repeaters, $page=null, $remove=true) {
        if (!$page) $page = $this;
        foreach ($repeaters as $repeaterName => $repeaterData) {
            // $repeaterData should be an array of subarrays where each subarray is [fieldname => value, fieldname2 => value2, ...]
            // get the existing data as an array to be compared
            $subPages = $page->$repeaterName->getArray();
            $subPageArray = [];
            $subPageObjects = [] ; // to keep track of the subpage objects
            foreach ($subPages as $subPage) {
                $subFields = $subPage->getFields();
                $subFieldArray = [];
                foreach ($subFields as $subField) {
                    $subDetails = $this->getFieldData($subPage, $subField);
                    $subFieldArray = $subDetails['attrib'];
                }
                $subPageArray[] = $subFieldArray;
                $subPageObjects[] = $subPage;
            }
            // $subPageArray should now be a comparable format to $repeaterData
            //bd($subPageArray, 'Array from existing subpages');
            //bd($repeaterData, 'Array of subpages to be set');
            // update/remove existing subpages
            foreach ($subPageArray as $i => $oldSubPage) {    // $i allows us to find the matching existing subpage
                $subPage = $subPageObjects[$i];
                $found = false;
                foreach ($repeaterData as $j => $setSubPage) {
                    //bd([$oldSubPage, $setSubPage], 'old and set subpages');
                    if (!array_diff(array_map('serialize', $setSubPage), array_map('serialize', $oldSubPage))) {   //matching subpage, nothing new
                        // remove the matching item so as not to re-use it
                        unset($repeaterData[$j]);
                        $found = true;
                        break;
                    } elseif (!array_diff(array_map('serialize', $oldSubPage), array_map('serialize', $setSubPage))) {  //matching subpage, new data
                        $diff = array_diff(array_map('serialize', $setSubPage), array_map('serialize', $oldSubPage));  // the new data
                        $subPage->setAndSave[array_map('unserialize',$diff)];
                        unset($repeaterData[$j]);
                        $found = true;
                        break;
                    }
                }
                if (!$found and $remove) $page->$repeaterName->remove($subPage);   // remove any subpages not in the new array (unless option set to false)
            }
            // create new subpages for any items in $repeaterData which have not been matched
            foreach ($repeaterData as $item) {
                $newSubPage = $page->$repeaterName->getNew();
                $newSubPage->setAndSave($item);
                $page->$repeaterName->add($newSubPage);
            }
        }
        $page->of(false);
        $page->save();
    }

    /**
     * Get field data (as an array) for a page field
     * NB Repeater fields cause this method to be called recursively
     * @param $page
     * @param $field
     * @param array $restrictFields
     * @param array $excludeFields
     * @return array
     */
    public function getFieldData($page, $field, $restrictFields=[], $excludeFields=[]) {
        $attrib = [];
        $files = [];
        $name = $field->name;
        switch ($field->type) {
            case 'FieldtypePage' :
                $attrib[$name] =$this->getPageRef($page->$field);
                break;
            case 'FieldtypeFields':
                $contents = [];
                foreach ($page->$field as $fId) {
                    $f = $this->wire->fields->get($fId);
                    $contents[] = $f->name;
                }
                $attrib[$name] = $contents;
                break;
            case 'FieldtypeTemplates' :
                $contents = [];
                foreach ($page->$field as $tId) {
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
                $items = $page->$field->getArray();
                $files[$page->id] = [];
                foreach ($items as $item) {
                    $itemArray = $item->getArray();
                    // don't want these in item as they won't match in target system
                    unset($itemArray['modified']);
                    unset($itemArray['created']);
                    unset($itemArray['modified_users_id']);
                    unset($itemArray['created_users_id']);
                    $contents['items'][] = $itemArray; // sets remaining items - basename, description, tags, formatted, filesize
                    if ($field->type == 'FieldtypeImage') {
                        $files[$page->id] = array_merge($files[$page->id], $item->getVariations(['info' => true, 'verbose' => false]));
                    }
                    $files[$page->id][] = $itemArray['basename'];
                }
                $attrib[$name] = $contents;
                break;
//            case 'FieldtypeTextarea' :
//
//                break;
            case 'FieldtypeOptions' :
                $attrib[$name] = $page->$field->id;
                break;
            case 'FieldtypeRepeater' :
                $contents = [];
                foreach ($page->$field as $item) {
                    //bd($item, 'repeater item');
                    $itemId = $item->id;
                    $itemName = $item->name;
                    $itemSelector = $item->selector;
                    $itemParent = $item->parent->path;
                    $itemTemplate = $item->template->name;
                    $itemData = [];
                    $subFields = $item->getFields();
                    foreach ($subFields as $subField) {
                        //bd($subField, 'subfield of type ' . $subField->type);
                        if ((count($restrictFields) > 0 and !in_array($name, $restrictFields)) or in_array($name, $excludeFields)) continue;
                        // recursive call
                        $itemDetails = $this->getFieldData($item, $subField, $restrictFields, $excludeFields);
                        $subData = $itemDetails['attrib'];
                        //bd($subData, 'subData');
                        $itemData = array_merge_recursive($itemData, $subData);
                        $files = array_merge_recursive($files, $itemDetails['files']);
                    }
                    //bd($itemData, 'itemData for ' . $item->name);

                    $itemArray = ['template' => $itemTemplate, 'data' => $itemData];
                    // removed 'parent' => $itemParent, 'name' => $itemName, 'id' => $itemId, 'selector' => $itemSelector,
                    // (These cause mismatch problems and are not needed for installing the migration)
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
                foreach ($page->$field as $items) {
                    //bd($item, 'pagetable item');
                    $contents['items'] = [];
                    $items = $page->$field->getArray();
                    foreach ($items as $item) {
                        $contents['items'][] = ['name' => $item['name'], 'parent' => $item['parent']->path];
                    }
                    $attrib[$name] = $contents;
                }
                break;
            default :
                if (is_object($page->$field) and property_exists($page->$field, 'data')) {
                    $attrib[$name] = $page->$field->data;
                } else {
                    $attrib[$name] = $page->$field;
                }
                break;
        }
        return ['attrib' => $attrib, 'files' => $files];
    }


    public function getPageRef($pageRefObject) {
        if (!$pageRefObject) return false;
        $show = $pageRefObject->path;
        if (!$show) {  // in case of multi-page fields
            $contents = [];
            foreach ($pageRefObject as $p) {
                $contents[] = $p->path;
            }
            $show = $contents;
        }
        return $show;
    }



    /**
     * Updates page for complex fields (other than repeaters) and removes these from the list for standard save
     * @param $fields
     * @param null $page
     * @return mixed
     * @throws WireException
     */
    public function setAndSaveComplex($fields, $page=null) {
        if (!$page) $page = $this;
        foreach ($fields as $fieldName => $fieldValue) {
            $f = $this->wire()->fields->get($fieldName);
            if ($f) {
                if ($f->type == 'FieldtypeStreetAddress' or $f->type == 'FieldtypeSeoMaestro') {
                    $page->of(false);
                    foreach ($fieldValue as $name => $value) {
                        $page->$fieldName->$name = $value;
                    }
                } elseif ($f->type == 'FieldtypePageTable') {
                    $pa = new PageArray();
                    foreach ($fieldValue['items'] as $item) {
                        $p = $this->wire()->pages->get($item['parent'] . $item['name'] . '/');
                        if ($p and $p->id) $pa->add($p);
                    }
                    $page->$fieldName->add($pa);
                } else {
                    continue;
                }
                unset($fields[$fieldName]);
                $page->save($fieldName);
            }
        }
        return $fields;
    }


    /**
     * Updates page for files/images and removes these from fields array
     * @param array $fields This is all the fields to be set - the non-file/image fields are returned
     * @param string $newOld 'new' for install, 'old' for uninstall
     * @param Page $page Is $this if null
     * @param boolean $replace Replace items that match
     * @param boolean $remove Remove any old items that do not match
     * @return mixed
     * @throws WireException
     */
    public function setAndSaveFiles($fields, $newOld, $page = null, $replace = true, $remove = true) {
        if (!$page) $page = $this;
        foreach ($fields as $fieldName => $fieldValue) {
            $f = $this->wire()->fields->get($fieldName);
            if ($f and ($f->type == 'FieldtypeImage' or $f->type == 'FieldtypeFile')) {

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
                //bd([$notInProposed, $notInExisting, $inBoth], 'Venn');


                $proposedId = basename($fieldValue['url']); // The id from the database that was used to create the migration file
                if ($remove) foreach ($notInProposed as $item) {
                    //bd($page->$f, ' Should be page array with item to delete being ' . $item);
                    $page->$f->delete($item);
                }
                foreach ($fieldValue['items'] as $item) {
                    if (in_array($item['basename'], $notInExisting)) {
                        // check that there are no orphan files
                        $this->removeOrphans($page, $item);
                        $page->$f->add($migrationFilesPath . $proposedId . '/' . $item['basename']);
                    }
                    if (array_key_exists($item['basename'], $inBoth) and $replace) {
                        $page->$f->delete($item['basename']);
                        $this->removeOrphans($page, $item);
                        $page->$f->add($migrationFilesPath . $proposedId . '/' . $item['basename']);
                        $pageFile = $page->$f->getFile($item['basename']);
                        $page->$f->$pageFile->description = $item['description'];
                        $page->$f->$pageFile->tags = $item['tags'];
                        $page->$f->$pageFile->filesize = $item['filesize'];
                        //bd($page->$f->$pageFile, 'Pagefile object');
                    }
                }

                unset($fields[$fieldName]);
                $page->save($fieldName);
                // add the variants after the page save as the new files not created before that
                foreach ($fieldValue['items'] as $item) {
                    $this->addVariants($migrationFilesPath, $item['basename'], $page, $proposedId);
                }

            }
        }
        return $fields;
    }

protected function removeOrphans($page, $item) {
    $files = $this->wire()->config->paths->files;
    $orphans = $this->wire()->files->find($files . $page->id);
    foreach ($orphans as $orphan) {
        if (strpos(basename($orphan), $item['basename']) === 0) unlink($orphan);
    }
}

protected function addVariants($migrationFilesPath, $basename, $page, $proposedId) {
    $files = $this->wire()->config->paths->files;
        $variants = $this->wire('config')->files->find($migrationFilesPath . $proposedId . '/');
        foreach ($variants as $variant) {
            if (basename($variant) != $basename) {
                $this->wire()->files->copy($variant, $files . $page->id . '/');
            }
        }
}

    /**
     * Save template after setting fieldgroup contexts
     * @param $item
     */
    public function saveItem($item) {
        //bd($item, '$item in saveItem');
        $fieldgroup = $item->fieldgroup;
        //bd($fieldgroup, '$fieldgroup in saveItem');
        $fieldgroup->save();
//        $this->testContext();
        $fieldgroup->saveContext();
        //Todo The above does not work properly on the first (uninstall) save as PW - Fields::saveFieldgroupContext() - retrieves the old context
        //Todo However, clicking "Uninstall" a second time makes it pick up the correct context. Not sure of the cause of this.
//        $this->testContext();
        $item->save();
        if(!$item->fieldgroups_id) {
            $item->setFieldgroup($fieldgroup);
            $item->save();
        }
//        $this->testContext();
    }


//    /**
//     * @return string
//     */
//    public function migrationsPath() {
//        return wire()->config->paths->siteModules . 'ProcessDbMigrate/migrations/';
//    }


    /**
     * Returns an array of all differences between $A and $B
     * This is done recursively such that the first node in the tree where they differ is returned as a 2-element array
     * > > The first of these 2 elements is the $A value and the second is the $B value
     * > > This 2-element array is stored at the bottom of a tree with all the keys that match above it
     * @param array $A
     * @param array $B
     * @return array  arrays at bottom nodes should all be of 2 elements
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
        //bd($this->arrayRecursiveDiff_key($D, $C), 'arrayRecursiveDiff_key($D, $C)');
        //bd($R, 'array $R');
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
     * return elements in array1 which do not match keys (all the way down) in array2
     * @param $aArray1
     * @param $aArray2
     * @return array
     */
    public function arrayRecursiveDiff_key($aArray1, $aArray2) {
        $aReturn = array();
        foreach ($aArray1 as $mKey => $mValue) {
            if (array_key_exists($mKey, $aArray2)) {
                if (is_array($mValue)) {
                    $aRecursiveDiff = $this->arrayRecursiveDiff_key($mValue, $aArray2[$mKey]);
                    if (count($aRecursiveDiff)) {
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
     * Return will have all elements in $A not in $B (stored as [$A element, ''])
     * > plus all elements in both where they differ at key value $k, say, stored as $k => [$A element, $B element]
     * If $swap = true then the pair will have its elements swapped.
     * @param $aArray1
     * @param $aArray2
     * @param false $swap  // swap array order in return
     * @param int $deep
     * @return array
     */
    public function arrayRecursiveDiff_assoc($aArray1, $aArray2, $swap=false, $deep = 0) {
        $noValueText = ($deep == 0) ? 'No Object' : 'No Value';
        $noValue = '<span style="color:grey">(' . $noValueText . ')</span>';
        $aReturn = array();
        foreach ($aArray1 as $mKey => $mValue) {
            if (array_key_exists($mKey, $aArray2)) {
                if (is_array($mValue) and is_array($aArray2[$mKey])) {
                    $deep += 1;
                    $aRecursiveDiff = $this->arrayRecursiveDiff_assoc($mValue, $aArray2[$mKey], $swap, $deep);
                    if (count($aRecursiveDiff)) {
                        $aReturn[$mKey] = $aRecursiveDiff;
                    }
                } else {
                    if ($mValue != $aArray2[$mKey]) {
                        $aReturn[$mKey] = (!$swap) ? [$aArray2[$mKey], $mValue] :[$mValue, $aArray2[$mKey]];
                    }
                }
            } else {
                if ($mValue) $aReturn[$mKey] = (!$swap) ? [$noValue, $mValue] : [$mValue, $noValue];
            }
        }
        return $aReturn;
    }
}
