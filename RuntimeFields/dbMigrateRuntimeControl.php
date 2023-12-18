<?php namespace ProcessWire;
/**
 * Called by field dbMigrateRuntimeControl (FieldtypeDbMigrateRuntime)
 * Provides status information and locking/unlocking.
 * This updated version allows locking/unlocking of the page in the target system as well as the source system.
 */

if($page->template == ProcessDbMigrate::MIGRATION_TEMPLATE) {
	/* @var $page \ProcessWire\DbMigrationPage */
	$lockText = ($page->meta('installable')) ? __('Lock the page? Locking marks the migration as complete and reduces the risk of subsequent conflicts.') :
		__('Lock the page? Locking marks the migration as complete and reduces the risk of subsequent conflicts. You will need lock the target(s) separately or sync the lockfile to implement the lock in target environments.');
	$unlockText = ($page->meta('installable')) ? __('Unlock the page? Unlocking allows changes and may conflict with other migrations.') :
		__('Unlock the page? Unlocking allows changes and may conflict with other migrations. You will need to unlock the target(s) separately or remove the lockfile from target environments if you wish the unlock to be implemented there.');
	$config->js('dbMigrateRuntimeControl', [
		'lock' => $lockText,
		'unlock' => $unlockText
	]);

	$locked = ($page->meta('locked'));
	//bd($installedStatus, '$installedStatus in migration control');
	$display = wire('modules')->get("InputfieldMarkup");
	$installedStatus = $page->meta('installedStatus') ? : ['status' => 'None'];
	if($locked) {
		$text = __('This page is locked and cannot be changed or actioned unless you unlock it.');
		if($page->meta('installable')) {
			$text2 = __('The status of installation is') . ' "';
			$text2 .= $installedStatus['status'] . '".';
		} else {
			$text2 = __('The status of the export is') . ' "';
			$text2 .= $installedStatus['status'] . '".';
		}
	} else {
		if($page->meta('installable')) {
			$text = __('This page is installable here. It cannot be amended.');
			$text2 = __('The status of installation is') . ' "';
			$text2 .= $installedStatus['status'] . '".';
		} else {
			$text = __('This page is exportable - i.e. you can generate migration data from it.');
			$text2 = __('The status of the export is') . ' "';
			$text2 .= $installedStatus['status'] . '".';
		}
	}
	if($page->meta('installable')) {
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
//	if($page->meta('installable')) {
//		//locked status
//		if($page->meta('locked')) {
//			$btn = wire('modules')->get("InputfieldButton");
//			$btn->attr('id', "unlock-page");
//			$btn->attr('value', __(" You can only unlock this in the source system"));
//			$btn->class('fa fa-lock');
//			$btn->showInHeader();
//			$control->append($btn);
//		} else {
//			//Lock button
//			$btn = wire('modules')->get("InputfieldButton");
//			$btn->attr('id', "lock-page");
//			$btn->attr('value', __(' You can only lock this in the source system'));
//			$btn->class('fa fa-unlock');
//			$btn->showInHeader();
//			$control->append($btn);
//		}
//
//	} else {
		//Unlock button
		if($page->meta('locked')) {
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
//	}

	$form->append($control);

	echo $form->render();
}