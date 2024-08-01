<?php
class HannaMigrate extends \ProcessWire\WireData implements \ProcessWire\Module {
	public static function getModuleInfo() {
		return array(
			'title' => 'Hanna Migrate',
			'summary' => 'Migrate Hanna codes between sites',
			'version' => '0.0.1',
			'author' => 'Mark Evens',
			'icon' => 'medium',
			'requires' => ['ProcessWire>=3.0.200'],
			'singular' => true,
		);
	}

	public function init() {

	}

	public function exportAll($migrationName = null) {
		$modules = $this->wire('modules');
		$hm = $modules->get('ProcessHannaCode');
		$ha = $hm->hannaCodes()->getAll();
		$exportAll = [];
		foreach($ha as $h) {
			$exportData = array(
				'name' => $h->name,
				'type' => $h->type,
				'code' => $hm->hannaCodes()->packCode($h->code, $h->attrs),
			);
			$exportAll[$h->name] = "!HannaCode:$h->name:" . base64_encode(json_encode($exportData)) . "/!HannaCode";
		}
		$directory = $this->migrationDirectory($migrationName);
		if(!is_dir($directory)) if(!\ProcessWire\wireMkdir($directory, true, "0777")) {          // wireMkDir recursive
			throw new WireException("Unable to create migration directory: $directory");
		}
		$exportJson = json_encode($exportAll);
		file_put_contents($directory . 'data.json', $exportJson);
	}

	public function importAll($migrationName = null) {
		$modules = $this->wire('modules');
		$hm = $modules->get('ProcessHannaCode');

		// Get the migration .json file for this migration
		$directory = $this->migrationDirectory($migrationName);
		$dataFile = (file_exists($directory . 'data.json'))
			? file_get_contents($directory . 'data.json') : null;

		$dataArray = json_decode($dataFile);
		if(!$dataArray) {
			throw new WireException("Failed to json decode import file");
		}

		foreach($dataArray as $data) {
			if(!preg_match('{!HannaCode:([^:]+):(.*?)/!HannaCode}s', $data, $matches)) {
				throw new WireException("Unrecognized Hanna Code format");
			}
			$name = $matches[1];
			$data = $matches[2];
			$data = base64_decode($data);
			if($data === false) {
				throw new WireException("Failed to base64 decode import data item");
			}

			$data = json_decode($data, true);
			if($data === false) {
				throw new WireException("Failed to json decode import data item");
			}

			if(empty($data['name']) || empty($data['code'])) {
				throw new WireException("Import data does not contain all required fields");
			}

			$h = $hm->hannaCodes()->get($name);
			if($h->id) {
				$this->error($this->_('Hanna Code with that name already exists'));
				$session->redirect('../');
				return '';
			}

			$data['type'] = (int)$data['type'];
			if($data['type'] & \ProcessWire\HannaCode::typePHP && !$hm->hasPermission('hanna-code-php')) {
				throw new WireException("You don't have permission to add/edit PHP Hanna Codes");
			}

			$h = new \ProcessWire\HannaCode();
			$hm->wire($h);
			$h->name = $name;
			$h->type = $data['type'];
			$h->code = $data['code'];
			$h->modified = time();

			if($hm->hannaCodes()->save($h)) {
				$this->message($this->_('Imported Hanna Code:') . " $name");
				$session->redirect("../edit/?id=$h->id");
			} else {
				throw new WireException("Error importing Hanna code");
			}
		}
		return '';

	}

	public function migrationDirectory($migrationName) {
		$modules = $this->wire('modules');
		$dbm = $modules->get('ProcessDbMigrate');
		if($dbm) {
			$migrationPage = ($migrationName) ? $dbm->migrations->get("template=$dbm->migrationTemplate, name=$migrationName") : new \ProcessWire\nullPage();
			/* @var $migrationPage \ProcessWire\DbMigrationPage */
			if($migrationPage->id > 0) {
				$directory = $dbm->migrationsPath;
				$migrationPath = $directory . $migrationPage->name . '/hanna-codes/';
			} else {
				$migrationPath = $dbm->migrationsPath . '/hanna-codes/';
			}
		} else {
			$migrationPath = $this->wire()->config->paths->assets . 'migrations/hanna-codes/';
		}
		return $migrationPath;
	}

}