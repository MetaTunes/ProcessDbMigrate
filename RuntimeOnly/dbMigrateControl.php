<?php namespace ProcessWire;
/**
 * Created by PhpStorm.
 * User: Mark
 * Date: 01/02/2019
 * Time: 18:40
 */

if ($page->template == 'DbMigration') {
    /* @var $page \ProcessWire\DbMigrationPage */
//    $migrationPath = $page->migrationsPath() . $page->name . '/';
    $locked = ($page->meta('locked'));
//bd($installedStatus, '$installedStatus in migration control');
    $display = wire('modules')->get("InputfieldMarkup");
    if ($locked) {
        $text = 'This page is locked and cannot be changed or actioned unless you unlock it.';
        $text2 = '';
    } else {
        //  compare before render
        $installedStatus = $page->exportData('compare');
        $exportStatus = ($installedStatus['status'] == 'installed') ? 'exported' : 'pending';
        if ($page->meta('installable')) {
            $text = 'This page is installable here. It cannot be amended.';
            $text2 = 'The status of installation is "';
            $text2 .= $installedStatus['status'] . '".';
        } else {
            $text = 'This page is exportable - i.e. you can generate migration data from it.';
            $text2 = 'The status of the export is "';
            $text2 .= $exportStatus . '".';
        }
    }
    $display->value = $text . '<br/>' . $text2;
    echo $display->render();
    $form = wire(new InputfieldWrapper());
    $control = wire(new InputfieldWrapper());
    $control->wrapAttr('style', "display:none");
    if ($page->meta('installable')) {
        //locked status
        if ($page->meta('locked')) {
            $btn = wire('modules')->get("InputfieldButton");
            $btn->attr('id', "unlock-page");
            $btn->attr('value', " You can only unlock this in the source system");
            $btn->class('fa fa-lock');
            $btn->showInHeader();
            $control->append($btn);
        } else {
            //Lock button
            $btn = wire('modules')->get("InputfieldButton");
            $btn->attr('id', "lock-page");
            $btn->attr('value', ' You can only lock this in the source system');
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
