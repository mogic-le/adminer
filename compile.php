#!/usr/bin/env php
<?php
include __DIR__ . "/adminer/include/version.inc.php";
include __DIR__ . "/adminer/include/errors.inc.php";
include __DIR__ . "/externals/JsShrink/jsShrink.php";

function add_apo_slashes($s) {
	return addcslashes($s, "\\'");
}

function add_quo_slashes($s) {
	$return = $s;
	$return = addcslashes($return, "\n\r\$\"\\");
	$return = preg_replace('~\0(?![0-7])~', '\\\\0', $return);
	$return = addcslashes($return, "\0");
	return $return;
}

function remove_lang($match) {
	global $translations;
	$idf = strtr($match[2], array("\\'" => "'", "\\\\" => "\\"));
	$s = ($translations[$idf] ?: $idf);
	if ($match[3] == ",") { // lang() has parameters
		return $match[1] . (is_array($s) ? "lang(array('" . implode("', '", array_map('add_apo_slashes', $s)) . "')," : "sprintf('" . add_apo_slashes($s) . "',");
	}
	return ($match[1] && $match[4] ? $s : "$match[1]'" . add_apo_slashes($s) . "'$match[4]");
}

function lang_ids($match) {
	global $lang_ids;
	$lang_id = &$lang_ids[stripslashes($match[1])];
	if ($lang_id === null) {
		$lang_id = count($lang_ids) - 1;
	}
	return ($_SESSION["lang"] ? $match[0] : "lang($lang_id$match[2]");
}

function put_file($match) {
	global $project, $VERSION, $driver;
	if (basename($match[2]) == '$LANG.inc.php') {
		return $match[0]; // processed later
	}
	$return = file_get_contents(__DIR__ . "/$project/$match[2]");
	$return = preg_replace('~namespace Adminer;\s*~', '', $return);
	if (basename($match[2]) == "file.inc.php") {
		$return = str_replace("\n// caching headers added in compile.php", (preg_match('~-dev$~', $VERSION) ? '' : '
if ($_SERVER["HTTP_IF_MODIFIED_SINCE"]) {
	header("HTTP/1.1 304 Not Modified");
	exit;
}

header("Expires: " . gmdate("D, d M Y H:i:s", time() + 365*24*60*60) . " GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: immutable");
'), $return, $count);
		if (!$count) {
			echo "adminer/file.inc.php: Caching headers placeholder not found\n";
		}
	}
	if ($driver && preg_match('~/drivers/~', $match[2])) {
		$return = preg_replace('~^if \(isset\(\$_GET\["' . $driver . '"]\)\) \{(.*)^}~ms', '\1', $return);
		// check function definition in drivers
		if ($driver != "mysql") {
			preg_match_all(
				'~\bfunction ([^(]+)~',
				preg_replace('~class Driver.*\n\t}~sU', '', file_get_contents(__DIR__ . "/adminer/drivers/mysql.inc.php")),
				$matches
			); //! respect context (extension, class)
			$functions = array_combine($matches[1], $matches[0]);
			$requires = array(
				"copy" => array("copy_tables"),
				"database" => array("create_database", "rename_database", "drop_databases"),
				"dump" => array("use_sql", "create_sql", "truncate_sql", "trigger_sql"),
				"kill" => array("kill_process", "connection_id", "max_connections"),
				"processlist" => array("process_list"),
				"routine" => array("routines", "routine", "routine_languages", "routine_id"),
				"scheme" => array("schemas", "get_schema", "set_schema"),
				"status" => array("show_status"),
				"table" => array("is_view"),
				"trigger" => array("triggers", "trigger", "trigger_options", "trigger_sql"),
				"type" => array("types", "type_values"),
				"variables" => array("show_variables"),
			);
			foreach ($requires as $support => $fns) {
				if (!Adminer\support($support)) {
					foreach ($fns as $fn) {
						unset($functions[$fn]);
					}
				}
			}
			unset($functions["__construct"], $functions["__destruct"], $functions["set_charset"]);
			foreach ($functions as $val) {
				if (!strpos($return, "$val(")) {
					fprintf(STDERR, "Missing $val in $driver\n");
				}
			}
		}
	}
	if (basename($match[2]) != "lang.inc.php" || !$_SESSION["lang"]) {
		if (basename($match[2]) == "lang.inc.php") {
			$return = str_replace('function lang($idf, $number = null) {', 'function lang($idf, $number = null) {
	if (is_string($idf)) { // compiled version uses numbers, string comes from a plugin
		// English translation is closest to the original identifiers //! pluralized translations are not found
		$pos = array_search($idf, get_translations("en")); //! this should be cached
		if ($pos !== false) {
			$idf = $pos;
		}
	}', $return, $count);
			if (!$count) {
				echo "lang() not found\n";
			}
		}
		$tokens = token_get_all($return); // to find out the last token
		return "?>\n$return" . (in_array($tokens[count($tokens) - 1][0], array(T_CLOSE_TAG, T_INLINE_HTML), true) ? "<?php" : "");
	} elseif (preg_match('~\s*(\$pos = (.+\n).+;)~sU', $return, $match2)) {
		// single language lang() is used for plural
		return "function get_lang() {
	return '$_SESSION[lang]';
}

function lang(\$translation, \$number = null) {
	if (is_array(\$translation)) {
		\$pos = $match2[2]\t\t\t: " . (preg_match("~\\\$LANG == '$_SESSION[lang]'.* \\? (.+)\n~U", $match2[1], $match3) ? $match3[1] : "1") . '
		);
		$translation = $translation[$pos];
	}
	$translation = str_replace("%d", "%s", $translation);
	$number = format_number($number);
	return sprintf($translation, $number);
}
';
	} else {
		echo "lang() \$pos not found\n";
	}
}

function lzw_compress($string) {
	// compression
	$dictionary = array_flip(range("\0", "\xFF"));
	$word = "";
	$codes = array();
	for ($i=0; $i <= strlen($string); $i++) {
		$x = @$string[$i];
		if (strlen($x) && isset($dictionary[$word . $x])) {
			$word .= $x;
		} elseif ($i) {
			$codes[] = $dictionary[$word];
			$dictionary[$word . $x] = count($dictionary);
			$word = $x;
		}
	}
	// convert codes to binary string
	$dictionary_count = 256;
	$bits = 8; // ceil(log($dictionary_count, 2))
	$return = "";
	$rest = 0;
	$rest_length = 0;
	foreach ($codes as $code) {
		$rest = ($rest << $bits) + $code;
		$rest_length += $bits;
		$dictionary_count++;
		if ($dictionary_count >> $bits) {
			$bits++;
		}
		while ($rest_length > 7) {
			$rest_length -= 8;
			$return .= chr($rest >> $rest_length);
			$rest &= (1 << $rest_length) - 1;
		}
	}
	return $return . ($rest_length ? chr($rest << (8 - $rest_length)) : "");
}

function put_file_lang($match) {
	global $lang_ids, $project, $langs;
	if ($_SESSION["lang"]) {
		return "";
	}
	$return = "";
	foreach ($langs as $lang => $val) {
		include __DIR__ . "/adminer/lang/$lang.inc.php"; // assign $translations
		$translation_ids = array_flip($lang_ids); // default translation
		foreach ($translations as $key => $val) {
			if ($val !== null) {
				$translation_ids[$lang_ids[$key]] = implode("\t", (array) $val);
			}
		}
		$return .= '
		case "' . $lang . '": $compressed = "' . add_quo_slashes(lzw_compress(implode("\n", $translation_ids))) . '"; break;';
	}
	$translations_version = crc32($return);
	return '$translations = $_SESSION["translations"];
if ($_SESSION["translations_version"] != ' . $translations_version . ') {
	$translations = array();
	$_SESSION["translations_version"] = ' . $translations_version . ';
}

function get_translations($lang) {
	switch ($lang) {' . $return . '
	}
	$translations = array();
	foreach (explode("\n", lzw_decompress($compressed)) as $val) {
		$translations[] = (strpos($val, "\t") ? explode("\t", $val) : $val);
	}
	return $translations;
}

if (!$translations) {
	$translations = get_translations($LANG);
	$_SESSION["translations"] = $translations;
}
';
}

function short_identifier($number, $chars) {
	$return = '';
	while ($number >= 0) {
		$return .= $chars[$number % strlen($chars)];
		$number = floor($number / strlen($chars)) - 1;
	}
	return $return;
}

// based on http://latrine.dgx.cz/jak-zredukovat-php-skripty
function php_shrink($input) {
	global $VERSION;
	$special_variables = array_flip(array('$this', '$GLOBALS', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_SERVER', '$http_response_header', '$php_errormsg'));
	$short_variables = array();
	$shortening = true;
	$tokens = token_get_all($input);

	// remove unnecessary { }
	//! change also `while () { if () {;} }` to `while () if () ;` but be careful about `if () { if () { } } else { }
	$shorten = 0;
	$opening = -1;
	foreach ($tokens as $i => $token) {
		if (in_array($token[0], array(T_IF, T_ELSE, T_ELSEIF, T_WHILE, T_DO, T_FOR, T_FOREACH), true)) {
			$shorten = ($token[0] == T_FOR ? 4 : 2);
			$opening = -1;
		} elseif (in_array($token[0], array(T_SWITCH, T_FUNCTION, T_CLASS, T_CLOSE_TAG), true)) {
			$shorten = 0;
		} elseif ($token === ';') {
			$shorten--;
		} elseif ($token === '{') {
			if ($opening < 0) {
				$opening = $i;
			} elseif ($shorten > 1) {
				$shorten = 0;
			}
		} elseif ($token === '}' && $opening >= 0 && $shorten == 1) {
			unset($tokens[$opening]);
			unset($tokens[$i]);
			$shorten = 0;
			$opening = -1;
		}
	}
	$tokens = array_values($tokens);

	foreach ($tokens as $i => $token) {
		if ($token[0] === T_VARIABLE && !isset($special_variables[$token[1]])) {
			$short_variables[$token[1]]++;
		}
	}

	arsort($short_variables);
	$chars = implode(range('a', 'z')) . '_' . implode(range('A', 'Z'));
	// preserve variable names between versions if possible
	$short_variables2 = array_splice($short_variables, strlen($chars));
	ksort($short_variables);
	ksort($short_variables2);
	$short_variables += $short_variables2;
	foreach (array_keys($short_variables) as $number => $key) {
		$short_variables[$key] = short_identifier($number, $chars); // could use also numbers and \x7f-\xff
	}

	$set = array_flip(preg_split('//', '!"#$%&\'()*+,-./:;<=>?@[]^`{|}'));
	$space = '';
	$output = '';
	$in_echo = false;
	$doc_comment = false; // include only first /**
	for (reset($tokens); list($i, $token) = each($tokens);) {
		if (!is_array($token)) {
			$token = array(0, $token);
		}
		if (
			$tokens[$i+2][0] === T_CLOSE_TAG && $tokens[$i+3][0] === T_INLINE_HTML && $tokens[$i+4][0] === T_OPEN_TAG
			&& strlen(add_apo_slashes($tokens[$i+3][1])) < strlen($tokens[$i+3][1]) + 3
		) {
			$tokens[$i+2] = array(T_ECHO, 'echo');
			$tokens[$i+3] = array(T_CONSTANT_ENCAPSED_STRING, "'" . add_apo_slashes($tokens[$i+3][1]) . "'");
			$tokens[$i+4] = array(0, ';');
		}
		if ($token[0] == T_COMMENT || $token[0] == T_WHITESPACE || ($token[0] == T_DOC_COMMENT && $doc_comment)) {
			$space = "\n";
		} else {
			if ($token[0] == T_DOC_COMMENT) {
				$doc_comment = true;
				$token[1] = substr_replace($token[1], "* @version $VERSION\n", -2, 0);
			}
			if ($token[0] == T_VAR || $token[0] == T_PUBLIC || $token[0] == T_PROTECTED || $token[0] == T_PRIVATE) {
				if ($token[0] == T_PUBLIC && $tokens[$i+2][1][0] == '$') {
					$token[1] = 'var';
				}
				$shortening = false;
			} elseif (!$shortening) {
				if ($token[1] == ';') {
					$shortening = true;
				}
			} elseif ($token[0] == T_ECHO) {
				$in_echo = true;
			} elseif ($token[1] == ';' && $in_echo) {
				if ($tokens[$i+1][0] === T_WHITESPACE && $tokens[$i+2][0] === T_ECHO) {
					next($tokens);
					$i++;
				}
				if ($tokens[$i+1][0] === T_ECHO) {
					// join two consecutive echos
					next($tokens);
					$token[1] = ','; // '.' would conflict with "a".1+2 and would use more memory //! remove ',' and "," but not $var","
				} else {
					$in_echo = false;
				}
			} elseif ($token[0] === T_VARIABLE && !isset($special_variables[$token[1]])) {
				$token[1] = '$' . $short_variables[$token[1]];
			}
			if (isset($set[substr($output, -1)]) || isset($set[$token[1][0]])) {
				$space = '';
			}
			$output .= $space . $token[1];
			$space = '';
		}
	}
	return $output;
}

function minify_css($file) {
	return lzw_compress(preg_replace('~\s*([:;{},])\s*~', '\1', preg_replace('~/\*.*\*/~sU', '', $file)));
}

function minify_js($file) {
	if (function_exists('jsShrink')) {
		$file = jsShrink($file);
	}
	return lzw_compress($file);
}

function compile_file($match) {
	global $project;
	$file = "";
	list(, $filenames, $callback) = $match;
	if ($filenames != "") {
		foreach (preg_split('~;\s*~', $filenames) as $filename) {
			$file .= file_get_contents(__DIR__ . "/$project/$filename");
		}
	}
	if ($callback) {
		$file = call_user_func($callback, $file);
	}
	return '"' . add_quo_slashes($file) . '"';
}

if (!function_exists("each")) {
	function each(&$arr) {
		$key = key($arr);
		next($arr);
		return $key === null ? false : array($key, $arr[$key]);
	}
}

function min_version() {
	return true;
}

function number_type() {
	return '';
}

function ini_bool() {
	return true;
}

$project = "adminer";
if ($_SERVER["argv"][1] == "editor") {
	$project = "editor";
	array_shift($_SERVER["argv"]);
}

$driver = "";
$driver_path = "/adminer/drivers/" . $_SERVER["argv"][1] . ".inc.php";
if (!file_exists(__DIR__ . $driver_path)) {
	$driver_path = "/plugins/drivers/" . $_SERVER["argv"][1] . ".php";
}
if (file_exists(__DIR__ . $driver_path)) {
	$driver = $_SERVER["argv"][1];
	array_shift($_SERVER["argv"]);
}

unset($_COOKIE["adminer_lang"]);
$_SESSION["lang"] = $_SERVER["argv"][1]; // Adminer functions read language from session
include __DIR__ . "/adminer/include/lang.inc.php";
if (isset($langs[$_SESSION["lang"]])) {
	include __DIR__ . "/adminer/lang/$_SESSION[lang].inc.php";
	array_shift($_SERVER["argv"]);
}

if ($_SERVER["argv"][1]) {
	echo "Usage: php compile.php [editor] [driver] [lang]\n";
	echo "Purpose: Compile adminer[-driver][-lang].php or editor[-driver][-lang].php.\n";
	exit(1);
}

include __DIR__ . "/adminer/include/pdo.inc.php";
include __DIR__ . "/adminer/include/driver.inc.php";
$features = array("check", "call" => "routine", "dump", "event", "privileges", "procedure" => "routine", "processlist", "routine", "scheme", "sequence", "status", "trigger", "type", "user" => "privileges", "variables", "view");
$lang_ids = array(); // global variable simplifies usage in a callback function
$file = file_get_contents(__DIR__ . "/$project/index.php");
if ($driver) {
	$_GET[$driver] = true; // to load the driver
	include_once __DIR__ . $driver_path;
	foreach ($features as $key => $feature) {
		if (!Adminer\support($feature)) {
			if (!is_int($key)) {
				$feature = $key;
			}
			$file = str_replace("} elseif (isset(\$_GET[\"$feature\"])) {\n\tinclude \"./$feature.inc.php\";\n", "", $file);
		}
	}
	if (!Adminer\support("routine")) {
		$file = str_replace("if (isset(\$_GET[\"callf\"])) {\n\t\$_GET[\"call\"] = \$_GET[\"callf\"];\n}\nif (isset(\$_GET[\"function\"])) {\n\t\$_GET[\"procedure\"] = \$_GET[\"function\"];\n}\n", "", $file);
	}
}
$file = preg_replace_callback('~\b(include|require) "([^"]*)";~', 'put_file', $file);
$file = str_replace('include "../adminer/include/coverage.inc.php";', '', $file);
if ($driver) {
	if (preg_match('~^/plugins/~', $driver_path)) {
		$file = preg_replace('((include "..)/adminer/drivers/mysql.inc.php)', "\\1$driver_path", $file);
	}
	$file = preg_replace('(include "../adminer/drivers/(?!' . preg_quote($driver) . '\.).*\s*)', '', $file);
}
$file = preg_replace_callback('~\b(include|require) "([^"]*)";~', 'put_file', $file); // bootstrap.inc.php
if ($driver) {
	foreach ($features as $feature) {
		if (!Adminer\support($feature)) {
			$file = preg_replace("((\t*)" . preg_quote('if (support("' . $feature . '")') . ".*?\n\\1\\}( else)?)s", '', $file);
		}
	}
	if (count($drivers) == 1) {
		$file = str_replace('html_select("auth[driver]", $drivers, DRIVER, "loginDriver(this);")', "\"<input type='hidden' name='auth[driver]' value='" . ($driver == "mysql" ? "server" : $driver) . "'>" . reset($drivers) . "\"", $file, $count);
		if (!$count) {
			echo "auth[driver] form field not found\n";
		}
		$file = str_replace(" . script(\"qs('#username').form['auth[driver]'].onchange();\")", "", $file);
	}
	$file = preg_replace('(;\s*../externals/jush/modules/jush-(?!textarea\.|txt\.|js\.|' . preg_quote($driver == "mysql" ? "sql" : $driver) . '\.)[^.]+.js)', '', $file);
	$file = preg_replace_callback('~doc_link\(array\((.*)\)\)~sU', function ($match) use ($driver) {
		list(, $links) = $match;
		$links = preg_replace("~'(?!(" . ($driver == "mysql" ? "sql|mariadb" : $driver) . ")')[^']*' => [^,]*,?~", '', $links);
		return (trim($links) ? "doc_link(array($links))" : "''");
	}, $file);
	//! strip doc_link() definition
}
if ($project == "editor") {
	$file = preg_replace('~;.\.\/externals/jush/jush\.css~', '', $file);
	$file = preg_replace('~compile_file\(\'\.\./(externals/jush/modules/jush\.js|adminer/static/[^.]+\.gif)[^)]+\)~', "''", $file);
}
$file = preg_replace_callback("~lang\\('((?:[^\\\\']+|\\\\.)*)'([,)])~s", 'lang_ids', $file);
$file = preg_replace_callback('~\b(include|require) "([^"]*\$LANG.inc.php)";~', 'put_file_lang', $file);
$file = str_replace("\r", "", $file);
if ($_SESSION["lang"]) {
	// single language version
	$file = preg_replace_callback("~(<\\?php\\s*echo )?lang\\('((?:[^\\\\']+|\\\\.)*)'([,)])(;\\s*\\?>)?~s", 'remove_lang', $file);
	$file = str_replace("switch_lang();", "", $file);
	$file = str_replace('<?php echo $LANG; ?>', $_SESSION["lang"], $file);
}
$file = str_replace('<?php echo script_src("static/editing.js"); ?>' . "\n", "", $file);
$file = preg_replace('~\s+echo script_src\("\.\./externals/jush/modules/jush-(textarea|txt|js|" \. JUSH \. ")\.js"\);~', '', $file);
$file = str_replace('<link rel="stylesheet" type="text/css" href="../externals/jush/jush.css">' . "\n", "", $file);
$file = preg_replace_callback("~compile_file\\('([^']+)'(?:, '([^']*)')?\\)~", 'compile_file', $file); // integrate static files
$replace = 'preg_replace("~\\\\\\\\?.*~", "", ME) . "?file=\1&version=' . $VERSION . '"';
$file = preg_replace('~\.\./adminer/static/(default\.css|favicon\.ico)~', '<?php echo h(' . $replace . '); ?>', $file);
$file = preg_replace('~"\.\./adminer/static/(functions\.js)"~', $replace, $file);
$file = preg_replace('~\.\./adminer/static/([^\'"]*)~', '" . h(' . $replace . ') . "', $file);
$file = preg_replace('~"\.\./externals/jush/modules/(jush\.js)"~', $replace, $file);
$file = preg_replace("~<\\?php\\s*\\?>\n?|\\?>\n?<\\?php~", '', $file);
$file = php_shrink($file);

$filename = $project . (preg_match('~-dev$~', $VERSION) ? "" : "-$VERSION") . ($driver ? "-$driver" : "") . ($_SESSION["lang"] ? "-$_SESSION[lang]" : "") . ".php";
file_put_contents($filename, $file);
echo "$filename created (" . strlen($file) . " B).\n";
