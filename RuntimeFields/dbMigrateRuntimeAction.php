<?php namespace ProcessWire;

/**
 * Called by field dbMigrateRuntimeAction (FieldtypeDbMigrateRuntime)
 * Provides Install buttons etc.
 */

if($page->template == ProcessDbMigrate::MIGRATION_TEMPLATE) {
	/* @var $page \ProcessWire\DbMigrationPage */
	$migrationPath = $config->paths->templates . ProcessDbMigrate::MIGRATION_PATH . $page->name . '/';
	$config->js('dbMigrateRuntimeAction', [

		'confirmRemoveFiles' => __("Are you sure you want to remove the migration files? \nAll files (including those required for uninstallation in this environment) will be removed. 
\nThis may mean that some image files may be lost if they are not installed. Please check that all required image files are in /site/assets/files/ before proceeding. 
\nThis page will remain and can be re-exported based on the current database (and files)."),

		'confirmInstall' => __("Installing or uninstalling a migration will change your database. 
\nWhile this should be reversible, it is strongly recommended that you BACK UP your database before carrying out this action.
\nClick OK to proceed or cancel if not."),

		'confirmRemoveOld' => __("You are about to remove the migration files required to uninstall the migration.\n") . __("If you do this, you will no longer be able to uninstall the migration back to its original state.\n") .
			sprintf(__('Instead, if re-installing the migration, a new set of %sold/ files will be created to allow uninstallation back to just the current state.'), $migrationPath) .
			__("\nPlease only proceed if you accept this.") . sprintf(__(' It may be advisable to take a copy of the current %s/old/ files before proceeding'), $page->name),

	]);
	echo ProcessDbMigrate::helpPopout('Help');
	if($page->status != 1) {
		echo __("Page must be published before any actions are available");
	} else {
		$installedStatus = $page->meta('installedStatus'); // set by DbMigrationPage::exportData('compare')
		$form = wire(new InputfieldWrapper());
		$form->attr('id', 'actions_form');

		// Only show actions if unlocked
		if(!$page->meta('locked')) {
			$updated = $page->meta('updated');
			//bd($installedStatus, '$installedStatus in migration actions');
			wire('modules')->get('JqueryUI')->use('modal');
			//bd($migrationPath, 'mig path');
			if($page->meta('installable') and file_exists($migrationPath . 'new/data.json')) {
				//bd($page, 'REMOVING DRAFT meta');
				if($page->meta('draft')) $page->meta()->remove('draft');
				if(!$installedStatus['installed'] and is_dir($migrationPath . 'new/') and $updated) {

					//Install button
					$install = wire(new InputfieldWrapper());
					$install->label(__('Install actions')); //Install is adjective
					$install->description(__('This migration is not (fully) installed. Install it or preview to see the effect of installation.'));
					$btn = wire('modules')->get("InputfieldButton");
					$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/install-migration/?id=" . $page->id);
					$btn->attr('id', "install_migration");
					$btn->attr('value', __("Install Migration")); // Install is verb
					$btn->notes(__("If an installation did not work completely,\n try redoing it before diagnosing differences with previews."));
					$install->append($btn);

					//Preview button
					if(!$installedStatus['installedData']) {
						$btn = wire('modules')->get("InputfieldButton");
						$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=install&modal=1');
						$btn->attr('id', "preview-diffs-install");
						$btn->attr('value', __("Preview differences"));
						$btn->addClass("pw-modal");
						$install->append($btn);
					}

					$form->append($install);

				} else {
					$install = wire(new InputfieldWrapper());
					$install->label(__('Install actions')); //Install is adjective
					$mkup = wire('modules')->get("InputfieldMarkup");
					if($updated) {
						$annotate = (is_dir($migrationPath . 'old/')) ? __("You may uninstall it.") : __("It is not possible to uninstall it.");
						$mkup->attr('value', __("This migration is installed. No further install actions are required.\n") . $annotate); //Install is adjective
					} else {
						if(!$page->meta('installedStatus')['uninstalledMigrationKey']) {
							$mkup->attr('value', __("The migration definition on this page has a different scope than that in /old/migration.json. 
							Perhaps you have restored an earlier backup database, in which case find the matching /archive/old/ files and restore those to /old/."));
						} else {
							$mkup->attr('value', __("The definition for this migration has changed. You need to uninstall it fully then refresh it before you can install the new version."));
						}
						$btn = wire('modules')->get("InputfieldButton");
						$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/remove-files/?id=" . $page->id . "&oldOnly=1");
						$btn->attr('id', "remove_old");
						$btn->attr('value', __("Remove uninstallation files - caution!"));
						$btn->attr('style', 'background-color:red');
						$btn->notes(__("If all else fails, this will remove the /old/ directory and refresh the page. Be aware that this will reset the uninstall action to only restore the current state, not the original state."));
					}
					$install->append($mkup);
					if(isset($btn)) $install->append($btn);
					$form->append($install);
				}
				if(!$installedStatus['uninstalled'] and is_dir($migrationPath . 'old/')) {

					//Uninstall button
					$uninstall = wire(new InputfieldWrapper());
					$uninstall->label(__('Uninstall actions')); //Uninstall is adjective
					if($page->name != 'bootstrap') {
						$uninstall->description(__('This migration is not (fully) uninstalled. Uninstall it or preview to see the effect of uninstallation.'));
						$btn = wire('modules')->get("InputfieldButton");
						$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/uninstall-migration/?id=" . $page->id);
						$btn->attr('id', "uninstall_migration");
						$btn->attr('value', __("Uninstall Migration")); // Uninstall is verb
						$btn->notes(__("If an uninstallation did not work completely,\n try redoing it before diagnosing differences with previews."));
						$uninstall->append($btn);
					} else {
						$uninstall->notes(__("Bootstrap is only uninstallable by uninstalling ProcessDbMigrate module"));
					}

					//Preview button
					if(!$installedStatus['uninstalledData']) {
						$btn = wire('modules')->get("InputfieldButton");
						$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=uninstall&modal=1');
						$btn->attr('id', "preview-diffs-uninstall");
						$btn->attr('value', __("Preview differences"));
						$btn->addClass("pw-modal");
						$uninstall->append($btn);
					}

					$form->append($uninstall);
				}

			} else if(!$page->meta('installable')) {

				// Export button
				$btn = wire('modules')->get("InputfieldButton");
				$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/export-data/?id=" . $page->id);
				$btn->attr('id', "export_data");
				$btn->attr('value', __("Export Data"));
				$form->append($btn);

				// Preview button
				if(!isset($installedStatus['installed']) || !$installedStatus['installed']) {
					$btn = wire('modules')->get("InputfieldButton");
					$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=export&modal=1');
					$btn->attr('id', "preview-diffs-export");
					$btn->attr('value', __("Preview differences"));
					$btn->notes(__('No differences will be shown for "removed" actions as these objects do not (or, at least, should not) exist here.'));
					$btn->addClass("pw-modal");
					$form->append($btn);
				}

				//Remove migration files button
				if(is_dir($migrationPath . 'old/') or is_dir($migrationPath . 'new/')) {
					$btn = wire('modules')->get("InputfieldButton");
					$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/remove-files/?id=" . $page->id);
					$btn->attr('id', "remove_files");
					$btn->attr('value', __("Remove migration files"));
					$btn->notes(__('Removes all files, but only files'));
					$form->append($btn);
				}

			} else {
				echo __("You need to export data from the source database in order to install this migration");
				if($page->meta('draft')) echo('<br/>' . __("The migration files need to be sync'd first") . ': ' . $migrationPath);
			}

		} else {
			// for locked installable migrations show the complete diffs between old and new
			if($page->meta('installable') and $installedStatus['reviewedDataDiffs']) {
				$review = wire(new InputfieldWrapper());
				$review->label(__('Review all changes'));
				$review->description(__('No actions are available, but you can review all the changes included in this migration'));
				$review->notes(__('Note that it is assumed the migration was fully installed before locking. Subsequent changes may have occurred.'));
				$btn = wire('modules')->get("InputfieldButton");
				$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=review&modal=1');
				$btn->attr('id', "preview-diffs-review");
				$btn->attr('value', __("Review total differences"));
				$btn->addClass("pw-modal");
				$review->append($btn);
				$form->append($review);
			}
		}
		echo $form->render();
	}
}

if($page->template == ProcessDbMigrate::COMPARISON_TEMPLATE) {
	/* @var $page \ProcessWire\DbComparisonPage */
	if($page->status != 1) {
		echo __("Page must be published before any actions are available");
	} else {
		$installedStatus = $page->meta('installedStatus');
		$comparisonPath = $page->comparisonsPath . $page->name . '/';
		$form = wire(new InputfieldWrapper());
		$form->attr('id', 'actions_form');
		if($page->meta('installable')) {

			// Compare button
			$btn = wire('modules')->get("InputfieldButton");
			$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=install&modal=1');
			$btn->attr('id', "compare-diffs");
			$btn->attr('value', __("Compare database"));
			$btn->notes(__('Differences will only be shown within the scope of the items below.') . '&emsp;');
			$btn->addClass("pw-modal");
			$form->append($btn);

			// Button to create draft migration from comparison
			$btn = wire('modules')->get("InputfieldButton");
			$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=install&button=draft&modal=1');
			$btn->attr('id', "prepare-draft");
			$btn->attr('value', __("Create a draft migration for this comparison"));
			$btn->notes(__('Migration will be restricted to the scope of the items below. '));
			$btn->addClass("pw-modal");
			$form->append($btn);

		} else {
			// Export button
			$btn = wire('modules')->get("InputfieldButton");
			$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/export-data/?id=" . $page->id . "&type=comparison");
			$btn->attr('id', "export_data");
			$btn->attr('value', __("Export Data"));
			$form->append($btn);


			// Preview button
			if(!isset($installedStatus['installed']) || !$installedStatus['installed']) {
				$btn = wire('modules')->get("InputfieldButton");
				$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=export&modal=1');
				$btn->attr('id', "preview-diffs-export");
				$btn->attr('value', __("Preview differences"));
//                $btn->notes(__(''));
				$btn->addClass("pw-modal");
				$form->append($btn);
			}

			//Remove migration files button
			if(is_dir($comparisonPath . 'old/') or is_dir($comparisonPath . 'new/')) {
				$btn = wire('modules')->get("InputfieldButton");
				$btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/remove-files/?id=" . $page->id);
				$btn->attr('id', "remove_files");
				$btn->attr('value', __("Remove comparison files"));
				$btn->notes(__('Removes all files, but only files'));
				$form->append($btn);
			}
		}
		echo $form->render();
	}
}
