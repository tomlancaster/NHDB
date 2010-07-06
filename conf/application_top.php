<?php

  /* ###################################
     #####################################
     #################################################################
     File to load necessary includes at the start of the application.
     #################################################################
  */

$GLOBALS['debug'] = true;
require_once('configure.php');

		

gc_enable();

ini_set('mbstring.language','UTF-8');
ini_set('mbstring.internal_encoding', 'UTF-8');
//ini_set('mbstring.http_input','UTF-8');
//ini_set('mbstring.http_output','UTF-8');
ini_set('mbstring.func_overload','7');
//$stimer = explode( ' ', microtime() );
//$stimer = $stimer[1] + $stimer[0];
// include the database functions
require_once(DIR_FS_FUNCTIONS . 'general.php');

require_once(DIR_FS_FUNCTIONS . 'database.php');


function __autoload($className) {
	if (file_exists(DIR_FS_CLASSES . $className . '.php')) {
		require_once(DIR_FS_CLASSES . $className . '.php');
	} else {
		error_log("AUTOLOAD: file $className.php does not exist\n", 3, ERROR_LOG);
	}
}

function destroy(&$var) {
	if (is_object($var)) $var->__destruct();
	unset($var);
}


tep_db_connect() or die('Unable to connect to database server!');
if (isset($_GET['cache']) && $_GET['cache'] === 'off') {
	$GLOBALS['USE_MEMCACHED'] = false;
}

if ($GLOBALS['USE_MEMCACHED'] === true) {
	// connect to memcached

	try {
		$memcache = new Memcache;
		$memcache->connect('localhost', $GLOBALS['MEMCACHED_PORT']);

	} catch (MemCachedException $me) {
		$GLOBALS['USE_MEMCACHED'] = false;
	}
	//$memcache->flush();
}



ini_set("url_rewriter.tags","");


if ($GLOBALS['app_context'] == 'web' ) {
	
	if ($GLOBALS['USE_MEMCACHED'] !== true) {
		er("not using memcached for session\n");
		ini_set('session.save_handler', 'files');
		ini_set('session.save_path', "/tmp/");
		session_start();
	} else {
		$session_save_path = "tcp://localhost:11211";
		ini_set('session.save_handler', 'memcache');
		ini_set('session.save_path', $session_save_path);
		session_start();
	}

}



$GLOBALS['tnh'] = $tnh;
if (isset($_POST)) {
	foreach ($_POST as $k => $v) {
		if ( mb_check_encoding($k, 'UTF-8') ) {
			if ( is_array($v) ) {
				foreach ($v as &$vp) {
					if (is_array($vp)) {
						foreach ($vp as &$vps) {
							if (is_array($vps)) {
								foreach ($vps as &$vpss) {
									if (!mb_check_encoding($vpss, 'UTF-8')) {
										error_log("invalid encoding in post of {$vp}", 3, ERROR_LOG);
										$vpss = '';
									}
								} 
							} else {
								if (!mb_check_encoding($vps, 'UTF-8')) {
									error_log("invalid encoding in post of {$vp}", 3, ERROR_LOG);
									$vps = '';
								}
							}
						}
					} else {
						if (!mb_check_encoding($vp, 'UTF-8')) {
							error_log("invalid encoding in post of {$vp}", 3, ERROR_LOG);
							$vp = '';
						}
					}
				} 
				
			
			} else if ( $v ) {
				if (!mb_check_encoding($v, 'UTF-8')) {
					error_log("invalid encoding in post of {$vp}", 3, ERROR_LOG);
					$v = '';
				}
			}
			$_POST[$k] = $v;
		} else {
			unset($_POST[$k]);
			error_log("invalid encoding in post of {$k} => {$v}", 3, ERROR_LOG);
		}
	}
}


$GLOBALS['message'] = $GLOBALS['error'] = $GLOBALS['srch_query'] = '';



date_default_timezone_set(NAMED_TIME_ZONE);

?>
