<?php namespace ProcessWire;
/**
 * Created by PhpStorm.
 * User: Mark
 * Date: 01/02/2019
 * Time: 18:40
 */

if ($page->template == 'DbMigration') {
    /* @var $page \ProcessWire\DbMigrationPage */
    $config->js('dbMigrateControl', [
        'lock' => __('Lock the page? Locking marks the migration as complete and reduces the risk of subsequent conflicts. You will need to sync the lockfile to implement the lock in target environments.'),
        'unlock' => __('Unlock the page? Unlocking allows changes and may conflict with other migrations. You will need to remove the lockfile from target environments if you wish the unlock to be implemented there.')
    ]);
//    $migrationPath = $page->migrationsPath() . $page->name . '/';
    $locked = ($page->meta('locked'));
//bd($installedStatus, '$installedStatus in migration control');
    $display = wire('modules')->get("InputfieldMarkup");
    if ($locked) {
        $text = __('This page is locked and cannot be changed or actioned unless you unlock it.');
        $text2 = '';
    } else {
        //  compare before render
        $installedStatus = $page->exportData('compare');
        if ($page->meta('installable')) {
            $text = __('This page is installable here. It cannot be amended.');
            $text2 = __('The status of installation is') . ' "';
            $text2 .= $installedStatus['status'] . '".';
        } else {
            $text = __('This page is exportable - i.e. you can generate migration data from it.');
            $text2 = __('The status of the export is') . ' "';
            $text2 .= $installedStatus['status'] . '".';
        }
    }
    if ($page->meta('installable')) {
        $text3 = __(' Source database for this migration is ');
        $text3 .= ($page->meta('sourceDb')) ?: __('not named');
    } else {
        $text3 = '';
    }
    $display->value = $text . '<br/>' . $text2 . '<br/>' . $text3 . '.';
    echo $display->render();
    $form = wire(new InputfieldWrapper());
    $control = wire(new InputfieldWrapper());
    $control->wrapAttr('style', "display:none");
    if ($page->meta('installable')) {
        //locked status
        if ($page->meta('locked')) {
            $btn = wire('modules')->get("InputfieldButton");
            $btn->attr('id', "unlock-page");
            $btn->attr('value', __(" You can only unlock this in the source system"));
            $btn->class('fa fa-lock');
            $btn->showInHeader();
            $control->append($btn);
        } else {
            //Lock button
            $btn = wire('modules')->get("InputfieldButton");
            $btn->attr('id', "lock-page");
            $btn->attr('value', __(' You can only lock this in the source system'));
            $btn->class('fa fa-unlock');
            $btn->showInHeader();
            $control->append($btn);
        }

    } else {
        //Unlock button
        if ($page->meta('locked')) {
            $btn = wire('modules')->get("InputfieldButton");
            $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/lock/?id=" . $page->id . '&action=unlock');
            $btn->attr('id', "unlock-page");
            $btn->attr('value', " Unlock");
            $btn->class('fa fa-lock');
            $btn->showInHeader();
            $control->append($btn);
        } else {
            //unlock status
            $btn = wire('modules')->get("InputfieldButton");
            $btn->attr('id', "lock-page");
            $btn->attr('href', wire('config')->urls->admin . "setup/dbmigrations/lock/?id=" . $page->id . '&action=lock');
            $btn->attr('value', ' Lock');
            $btn->class('fa fa-unlock');
            $btn->showInHeader();
            $control->append($btn);
        }
    }

    $form->append($control);


    echo $form->render();
}
