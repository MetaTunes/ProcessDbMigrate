<?php namespace ProcessWire;
/**
 * Created by PhpStorm.
 * User: Mark
 * Date: 01/02/2019
 * Time: 18:40
 */

if ($page->template == 'DbMigration') {
    /* @var $page \ProcessWire\DbMigrationPage */
    $config->js('dbMigrateActions', [
        'confirmRemoveFiles' => __("Are you sure you want to remove the migration files? All files (including those required for uninstallation in this environment) will be removed. 
This may mean that some image files may be lost if they are not installed. Please check that all required image files are in /site/assets/files/ before proceeding. 
This page will remain and can be re-exported based on the current database (and files).")
    ]);
    if ($page->status != 1) {
        echo __("Page must be published before any actions are available");
    } else {

        //  compare before render
        $installedStatus = $page->exportData('compare');
        $form = wire(new InputfieldWrapper());
        $form->attr('id', 'actions_form');
        // Only show if unlocked
        if (!$page->meta('locked')) {
            $updated = $page->refresh();
            $migrationPath = $page->migrationsPath . $page->name . '/';

            //bd($installedStatus, '$installedStatus in migration actions');
            wire('modules')->get('JqueryUI')->use('modal');
            if ($page->meta('installable')) {
                if (!$installedStatus['installed'] and is_dir($migrationPath . 'new/') and $updated) {

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

                    if (!$installedStatus['installedData']) {
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
                    if ($updated) {
                        $annotate = (is_dir($migrationPath . 'old/')) ? __("You may uninstall it.") : __("It is not possible to uninstall it.");
                        $mkup->attr('value', __("This migration is installed. No further install actions are required.\n") . $annotate); //Install is adjective
                    } else {
                        $mkup->attr('value', __("The definition for this migration has changed. You need to uninstall it fully then refresh it before you can install the new version."));
                    }
                    $install->append($mkup);
                    $form->append($install);
                }
                if (!$installedStatus['uninstalled'] and is_dir($migrationPath . 'old/')) { // and $page->name != 'bootstrap') {

                    //Uninstall button

                    $uninstall = wire(new InputfieldWrapper());
                    $uninstall->label(__('Uninstall actions')); //Uninstall is adjective
                    if ($page->name != 'bootstrap') {
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

                    if (!$installedStatus['uninstalledData']) {
                        $btn = wire('modules')->get("InputfieldButton");
                        $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=uninstall&modal=1');
                        $btn->attr('id', "preview-diffs-uninstall");
                        $btn->attr('value', __("Preview differences"));
                        $btn->addClass("pw-modal");
                        $uninstall->append($btn);
                    }
                    $form->append($uninstall);
                } else {
//            $form->description('This migration is uninstalled');
                }
            } else {
                // Export button
                $btn = wire('modules')->get("InputfieldButton");
                $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/export-data/?id=" . $page->id);
                $btn->attr('id', "export_data");
                $btn->attr('value', __("Export Data"));
                $form->append($btn);

                // preview button
                if (!$installedStatus['installed']) {
                    $btn = wire('modules')->get("InputfieldButton");
                    $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=export&modal=1');
                    $btn->attr('id', "preview-diffs-export");
                    $btn->attr('value', __("Preview differences"));
                    $btn->notes(__('No differences will be shown for "removed" actions as these objects do not exist here.'));
                    $btn->addClass("pw-modal");
                    $form->append($btn);
                }

                //Remove migration files button
                if (is_dir($migrationPath . 'old/') or is_dir($migrationPath . 'new/')) {
                    $btn = wire('modules')->get("InputfieldButton");
                    $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/remove-files/?id=" . $page->id);
                    $btn->attr('id', "remove_files");
                    $btn->attr('value', __("Remove migration files"));
                    $btn->notes(__('Removes all files, but only files'));
                    $form->append($btn);
                }
            }
        } else {
            // for locked installable migrations show the complete diffs between old and new
            if ($page->meta('installable') and $installedStatus['reviewedDataDiffs']) {
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
