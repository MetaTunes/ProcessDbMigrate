<?php namespace ProcessWire;
/**
 * Created by PhpStorm.
 * User: Mark
 * Date: 01/02/2019
 * Time: 18:40
 */

if ($page->template == 'DbMigration') {
    /* @var $page \ProcessWire\DbMigrationPage */
    if ($page->status != 1) {
        echo('Page must be published before any actions are available');
    } else {
        $updated = $page->refresh();
        $migrationPath = $page->migrationsPath . $page->name . '/';
        $form = wire(new InputfieldWrapper());
        $form->attr('id', 'actions_form');
        //  compare before render
        $installedStatus = $page->exportData('compare');
        //bd($installedStatus, '$installedStatus in migration actions');
        wire('modules')->get('JqueryUI')->use('modal');
        // Only show if unlocked
        if (!$page->meta('locked')) {
            if ($page->meta('installable')) {
                if (!$installedStatus['installed'] and is_dir($migrationPath . 'new/') and $updated) {

                    //Install button
                    $install = wire(new InputfieldWrapper());
                    $install->label('Install actions');
                    $install->description('This migration is not (fully) installed. Install it or preview to see the effect of installation.');
                    $btn = wire('modules')->get("InputfieldButton");
                    $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/install-migration/?id=" . $page->id);
                    $btn->attr('id', "install_migration");
                    $btn->attr('value', "Install Migration");
                    $btn->notes("If an installation did not work completely,\n try redoing it before diagnosing differences with previews.");
                    $install->append($btn);

                    //Preview button

                    if (!$installedStatus['installedData']) {
                        $btn = wire('modules')->get("InputfieldButton");
                        $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=install&modal=1');
                        $btn->attr('id', "preview-diffs-install");
                        $btn->attr('value', "Preview differences");
                        $btn->addClass("pw-modal");
                        $install->append($btn);
                    }
                    $form->append($install);
                } else {
                    $install = wire(new InputfieldWrapper());
                    $install->label('Install actions');
                    $mkup = wire('modules')->get("InputfieldMarkup");
                    if ($updated) {
                        $annotate = (is_dir($migrationPath . 'old/')) ? "You may uninstall it." : "It is not possible to uninstall it.";
                        $mkup->attr('value', "This migration is installed. No further install actions are required.\n" . $annotate);
                    } else {
                        $mkup->attr('value', "The definition for this migration has changed. You need to uninstall it fully then refresh it before you can install the new version.");
                    }
                    $install->append($mkup);
                    $form->append($install);
                }
                if (!$installedStatus['uninstalled'] and is_dir($migrationPath . 'old/')) { // and $page->name != 'bootstrap') {

                    //Uninstall button

                    $uninstall = wire(new InputfieldWrapper());
                    $uninstall->label('Uninstall actions');
                    if ($page->name != 'bootstrap') {
                        $uninstall->description('This migration is not (fully) uninstalled. Uninstall it or preview to see the effect of uninstallation.');
                        $btn = wire('modules')->get("InputfieldButton");
                        $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/uninstall-migration/?id=" . $page->id);
                        $btn->attr('id', "uninstall_migration");
                        $btn->attr('value', "Uninstall Migration");
                        $btn->notes("If an uninstallation did not work completely,\n try redoing it before diagnosing differences with previews.");
                        $uninstall->append($btn);
                    } else {
                        $uninstall->notes("Bootstrap is only uninstallable by uninstalling ProcessDbMigrate module");
                    }

                    //Preview button

                    if (!$installedStatus['uninstalledData']) {
                        $btn = wire('modules')->get("InputfieldButton");
                        $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=uninstall&modal=1');
                        $btn->attr('id', "preview-diffs-uninstall");
                        $btn->attr('value', "Preview differences");
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
                $btn->attr('value', "Export Data");
                $form->append($btn);

                // preview button
                if (!$installedStatus['installed']) {
                    $btn = wire('modules')->get("InputfieldButton");
                    $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/preview-diffs/?id=" . $page->id . '&target=export&modal=1');
                    $btn->attr('id', "preview-diffs-export");
                    $btn->attr('value', "Preview differences");
                    $btn->addClass("pw-modal");
                    $form->append($btn);
                }

                //Remove migration files button
                if (is_dir($migrationPath . 'old/') or is_dir($migrationPath . 'new/')) {
                    $btn = wire('modules')->get("InputfieldButton");
                    $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/remove-files/?id=" . $page->id);
                    $btn->attr('id', "remove_files");
                    $btn->attr('value', "Remove migration files");
                    $btn->notes('Removes all files, but only files');
                    $form->append($btn);
                }
            }
        }
        echo $form->render();
    }
}
