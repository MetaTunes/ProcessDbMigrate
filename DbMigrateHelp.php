<?php namespace ProcessWire;
$config->styles->add($config->urls->templates . "styles/admin.css");
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>

.sidenav {
	height: 100%;
	width: 20%;
  position: fixed;
  z-index: 1;
  left: 0;
  background-color: blue;
  padding-top: 20px;
  float: left;
  overflow-y: scroll;
  top: 0;
  bottom: 0;
}

.sidenav a {
	padding: 6px 8px 6px 16px;
  text-decoration: none;
  color: white;
  display: block;
}

.sidenav a:hover {
	color: #f1f1f1;
}

.sidenav ul {
  margin-left: -30px;
  list-style-type: none;
}

.main {
	margin-left: 20%; /* Same as the width of the sidenav */
  width: 80%;
  padding: 0px 10px;
  float: left;
}

@media screen and (max-height: 450px) {
	.sidenav {padding-top: 15px;}
}
</style>
</head>
<body>
<div class="sidenav">
	<?php
	/*
	 * This sidebar code is based on the Hanna jumplinks code
	 */
	$for = 'h2 h3 h4';
	$forArray = explode(' ', $for);
	foreach($forArray as $k => $v) $forArray[$k] = trim($v);

	$for = implode('|', $forArray);
	$anchors = array();
	// Use saved help text if it exists - in case it was updated externally
	if(file_exists(wire('config')->paths->siteModules . basename(__DIR__) . '/helpText.html')) {
		$value = file_get_contents($this->modulePath . 'helpText.html');
	} else {
		$value = $page->dbMigrateAdditionalDetails;
	}

	if(preg_match_all('{<(' . $for . ')[^>]*>(.+?)</\1>}i', $value, $matches)) {
		foreach($matches[1] as $key => $tag) {
			$text = $matches[2][$key];
			$anchor = $sanitizer->pageName($text, true);
			$level = array_search($tag, $forArray);
			$anchors[$anchor]['text'] = $text;
			$anchors[$anchor]['level'] = $level;
			$full = $matches[0][$key];
			$value = str_replace($full, "<a name='$anchor'  href='#'></a>$full", $value);
		}
		$page->dbMigrateAdditionalDetails = $value;
	}

	if(count($anchors)) {
		echo "<ul class='uk-nav uk-nav-default'>";
		foreach($anchors as $anchor => $value) {
		    $text = $value['text'];
		    $level = $value['level'];
			$padding = ($level * 10) . 'px';
		    if(!$page->export) {   //$page->export is temp field set in page save hook (in ProcessDbMigrate) when exporting html
				echo "<li><a style='padding-left: $padding' href='$page->url#$anchor'>$text</a></li>";
			} else {
				echo "<li><a style='padding-left: $padding' href='#$anchor'>$text</a></li>";
            }
		}
		echo "</ul>";
	} else {
		echo '';
	}
	?>
</div>
<div class="main" edit="dbMigrateAdditionalDetails">
<?php
$html = $page->dbMigrateAdditionalDetails;
if(!$page->export) {  // see note above re $page->export
	$html = str_replace('src="help/', 'src="' . $this->wire('config')->urls->siteModules . basename(__DIR__) . '/help/', $html);
}
echo $html; // Will have anchors inserted by sidebar code
?>
</div>

</body>
</html>

