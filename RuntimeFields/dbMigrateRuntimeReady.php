<?php namespace ProcessWire;

/**
 * Called by field dbMigrateRuntimeReady (FieldtypeDbMigrateRuntime)
 * Echoes contents of site/templates/DbMigrate/migrations/{migration name}/ready.php which may contain hooks etc
 * The ready.php file is 'included' in DbMigrationPage::ready() if it exists.
 */

$config->js('dbMigrateRuntimeReady', [
	'description' => sprintf(__('Contents of site/templates/DbMigrate/migrations/%s/ready.php'), $page->name),
]);
$readyFile = "{$page->migrationsPath}/{$page->name}/ready.php";
if(file_exists($readyFile)) {
	echo '<pre>' . htmlspecialchars(file_get_contents($readyFile)) . '</pre>';
} else {
	$out = __("NO FILE") . '<br/>';
	$out .= sprintf(__("Create the file  - site/templates/DbMigrate/migrations/%s/ready.php - if you wish to add hooks."), $page->name) . '<br/>';
	$out .= sprintf(__("Remember to use %s as the first line."), htmlspecialchars('<?php  namespace ProcessWire;')) . '<br/>';
	$out .= __("Hooks will be specific to this migration page - use \$this to reference it.") . '<br/>';
	$out .= __("See the help file for more details.");
	echo $out;
}