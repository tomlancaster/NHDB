<?php
define('DB_SERVER', 'localhost');
define('DB_SERVER_USERNAME', 'user');
define('DB_SERVER_PASSWORD', 'pass');
define('DB_DATABASE', 'database');
define('USE_PCONNECT', false);
$GLOBALS['USE_MEMCACHED'] = false;
$GLOBALS['MEMCACHED_PORT_1'] = 11212;
define('COOKIE_DOMAIN', '');
define('GMAPS_KEY', 'ABQIAAAAn3YdyE6Y37GeqSFXKNqdexTZuf6utpoc0JHgWCFek4Z7NEJe9BS1gTSjArjxaSeNvYY9CEH46xEr7w');
define('DIR_FS_RIZOOT', '/path/to/root/');
define('ERROR_LOG','/your/error/log/php_error.log');
define('DIR_FS_ROOT', DIR_FS_RIZOOT . '/www/');
define('USE_TIMER', false);

include(DIR_FS_RIZOOT . 'conf/common_conf.php');


?>
