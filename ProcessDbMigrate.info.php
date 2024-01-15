<?php namespace ProcessWire;


$info = array(
	'permanent' => false,        // true if module is permanent and thus not uninstallable (3rd party modules should specify 'false')
	'title' => 'ProcessDbMigrate',
	'summary' => 'Manage migrations via the PW GUI',
	'comments' => 'Document (manually or by automated change tracking) and manage migrations. Allow roll-back and database comparisons.',
	'author' => 'Mark Evens',
	'version' => "2.0.8",
	// Versions >= 0.1.0 use FieldtypeDbMigrateRuntime not RuntimeOnly. Versions >= 1.0.0 have change tracking (scope at individual migration level for v2.0.0+).
	'autoload' => true,
	'singular' => true,
	'page' => array(            //install/uninstall a page for this process automatically
		'name' => 'dbmigrations',    //name of page to create
		'parent' => 'setup',    // parent name (under admin)
		'title' => 'Database Migrations',    // title of page
	),
	'icon' => 'upload',
	'requires' => 'PHP>=8.0', 'ProcessWire>=3.0.172',
	'installs' => 'FieldtypeDbMigrateRuntime', // Runtime field type for use with this module
	'permission' => 'admin-dbMigrate',         // ToDo refine permissions?
);