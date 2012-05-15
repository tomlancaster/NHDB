<?php

require_once('Safe.php');

class tnh {
	public $ui;
	public $un;
	public $error;
	public $userObj;
	public $siteObj;
	public $cityObj;
	private static $wholeServer = array();
	public static $lang = array('en_US' => 'English',
						 		'fr_FR' => 'Français',
								'vi_VN' => 'Tieng Viet',
								'zh_TW' => '繁體中文',
								'zh_CN' => '中文');



	function __construct ($tnh_user_cookie = '') {

		if ($tnh_user_cookie != '') {
			list($user_id,$hash) = explode(':', $tnh_user_cookie);
			if ($user = user::load($user_id)) {
				if ($ban = $user->ban()) {
					return false;
				}
				if ($hash == crypt(md5($user_id),$user->crypted_password)) {
					$user->last_login = 'now()';

					$user->last_ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
					$user->save();
					$this->userObj = $user;
					if (isset($_COOKIES['tnh_user'])) {
						if ($this->userObj->agreed_tos != '1') {
							if (strpos(tnh::php_self(),'agree_tos') || strpos(tnh::php_self(), 'system') || strpos(tnh::php_self(), 'extramenuitems') || strpos(tnh::php_self(), 'notification') || strpos(tnh::php_self(), 'num_unread')) {
								return true;
							} else {
								tnh::mredir(_('Please check the box to agree to our Terms of Service before continuing'),'/user/agree_tos');
							}
						}
						if (!isset($_COOKIES['tnh_username'])) {
							setcookie('tnh_username', $user->username, time() + 60*60*24*28, '/', COOKIE_DOMAIN, 0);
						}
						return true;
					}
				} else {
					trigger_error('[tnh::tnh] - cookie pass does not match db', E_USER_NOTICE);
					$this->userObj = new user();
					return false;
				}
			} else {
				throw new Exception("Bad User Id in Cookie");
			}
		} else {
			unset($_COOKIE['tnh_user']);
		}
		return true;
	}

	public static function lap($location) {
		if (USE_TIMER === true) {
			error_log("@@@ - elapsed time at $location: " . (tnh::microtime_float() - $GLOBALS['lap']) . "s  @@@\n", 3, ERROR_LOG);
			$GLOBALS['lap'] = tnh::microtime_float();
		}
	}

	public static function total_time($location) {
		if (USE_TIMER === true) {
			error_log("@@@ - total time at $location: " . (tnh::microtime_float() - $GLOBALS['ts']) . "s  @@@\n", 3, ERROR_LOG);
		}
	}


	public static function eredir($error, $dest = '') {
		if (!$dest) {
			if (isset($_GET['next_page']) && preg_match('/[\/a-z_0-9\.]+/', $_GET['next_page'])) {
				$dest = $_GET['next_page'];
			} elseif (isset($_SESSION['last_page'])) {
				$dest = $_SESSION['last_page'];
			} else {
				$dest = '/';
			}
		}
		$_SESSION['last_error'] = $error;
		//		$dest = tnh::wholeServer($GLOBALS['site_id']) . '/' . $dest;
		header("Location: " . $dest);
		die();
	}

	public static function mredir($message, $dest = '') {

		if (!$dest) {
			if (isset($_GET['next_page']) && preg_match('/[\/a-z_0-9\.]+/', $_GET['next_page'])) {
				$dest = $_GET['next_page'];
			} elseif (isset($_SESSION['last_page'])) {
				$dest = $_SESSION['last_page'];
			} else {
				$dest = '/';
			}
		}
		$_SESSION['last_message'] = $message;
		//$dest = 'http://' .tnh::wholeServer($GLOBALS['site_id']) . '/' . $dest;
		
		header("Location: " . $dest);
		exit();
	}

	public static function estop($response,$dest='',$cont=false) {
		if ($GLOBALS['inAJAX']) die(json_encode($response));
		if ($cont) {
			$_SESSION['last_error'] = $response['internal']['description'];
		} else {
			tnh::eredir($response['internal']['description'],$dest);
		}
	}

	public static function mstop($response,$dest='',$cont=false) {
		if ($GLOBALS['inAJAX']) die(json_encode($response));
		if ($cont) {
			$_SESSION['last_message'] = $response['internal']['description'];
		} else {
			tnh::mredir($response['internal']['description'],$dest);
		}
	}

	public static function headerdie($status,$message='') {
		header("HTTP/1.0 ".$status);
		die($message);
	}

	public static function rar_dump() {
		$args = func_get_args();
		ob_start();
		foreach ($args as $var) {
			var_dump(@$var);
		}
		$dumps = ob_get_contents();
		ob_end_clean();
		return $dumps;
	}

	public static function next_page($header = true) {
		if (isset($_SESSION['next_page'])) {
			$np = $_SESSION['next_page'];
			unset($_SESSION['next_page']);
		} elseif (isset($_SESSION['last_page'])) {
			$np = $_SESSION['last_page'];
			unset($_SESSION['last_page']);
		} elseif(isset($_SESSION['ultimate_page'])) {
			$np = $_SESSION['ultimate_page'];
		} else {
			$_SESSION['last_error'] = 'no next page set';
			$np = '/';
		}
		if ($header === false) {
			return $np;
		}
		header("Location: " . $np);
		die();
	}

	public static function last_page() {
		if (isset($_SESSION['last_page'])) {
			$lp = $_SESSION['last_page'];
			unset($_SESSION['last_page']);
			header("Location: " . $lp);
			die();
		} else {
			$_SESSION['last_error'] = 'no last page set';
			return false;
		}
	}

	public static function wholeServer($site_id) {
		$wholeServer = '';
		$subd = $GLOBALS['HOST_' . $site_id];
		$serverParts = array_reverse(explode('.',$subd));

		$tld = $serverParts[0];
		$domain = $serverParts[1];
		if ($serverParts[2] == 'internal') {
			$siteSub = $serverParts[3];
			$domain = "internal.$domain";
			
		} else {

			$siteSub = $serverParts[2];
			
		}
		
		if (!$site = site::load($site_id)) {
			error_log("unable to load site, presumably because no site_id. Environment is: " . print_r($_SERVER,true), 3, ERROR_LOG);
		
		}
		
		$wholeServer .= "$siteSub.$domain.$tld";
		return $wholeServer;
	}
	
	public static function currentURL() {
		$pageURL = (isset($_SERVER['HTTPS']) && $_SERVER["HTTPS"] == "on") ? "https://" : "http://";

	    $pageURL .= tnh::wholeServer($GLOBALS['site_id']) .$_SERVER["REQUEST_URI"];
		return $pageURL;
	}
	
	
	public static function pathForMod($modName) {
		$cityId = $GLOBALS['city_id'];
		$string = '/' . $GLOBALS['lang_short'];
		if (!in_array($modName, array('user', 'pms', 'userimage', 'images', 'bookmark', 'propertyimage'))) {
			$string .= '/c/' . $cityId;
		}
		$string .= '/' . $modName;
		return $string;
	}
		

	public static function get_include_contents($filename) {
		if (is_file($filename)) {
			ob_start();
			include $filename;
			$contents = ob_get_contents();
			ob_end_clean();
			return $contents;
		}
		return false;
	}

	static function tnhFriendlyDate($mysqldate) {

		if ((!is_string($mysqldate) && !is_numeric($mysqldate)) || !$mysqldate) {
			error_log('Invalid $mysqldate! tnhFriendlyDate was asked to friendlyify $mysqldate='.tnh::rar_dump($mysqldate), 3, ERROR_LOG);
			return _('at an unknown time');
		} elseif ($mysqldate == 'now()') {
			error_log('Invalid $mysqldate! tnhFriendlyDate was asked to friendlyify $mysqldate='.tnh::rar_dump($mysqldate), 3, ERROR_LOG);
			return _('just now');
		}

		$now = time();
		$then = strtotime($mysqldate);
		$difference = $now - $then;
		if ($difference < 60*10) {
			return _('just now');
		} else if ( $difference < 60 * 45 ) {
			return _('a little while ago');
		} else if ($difference < 60 * 60 * 2) {
			return _('an hour or so ago');
		}
		$hours = round($difference / (60*60),0);
		if ($hours < 24) {
			return sprintf(_('about %d hours ago'),$hours);
		}
		$days = round($difference / (60*60*24),0);
		if ($days == 1) {
			return sprintf(_('about %d day ago'),$days);
		}
		if ($days < 7) {
			return sprintf(_('about %d days ago'),$days);
		}
		$weeks = round($difference / (60*60*24*7),0);
		if ($weeks == 1) {
			return sprintf(_('about %d week ago'), $weeks);
		} else if ($weeks < 5) {
			return sprintf(_('about %d weeks ago'), $weeks);
		}
		$months = round($difference / (60*60*24*31),0);
		if ($months == 1) {
			return sprintf(_('about %d month ago'),$months);
		} else {
			return sprintf(_('about %d months ago'),$months);
		}
	}
	static function tnhDate($mysqldate) {
		return date( 'n/j/Y ', strtotime($mysqldate)) . _('at') . date(' g:i a', strtotime($mysqldate) );
	}
	static function tnhDateOnly($mysqldate) {
		return strftime('%A, %B %e', strtotime($mysqldate));
	}
	static function tnhDateOnlyYear($mysqldate) {
		return date('l F jS, Y', strtotime($mysqldate));
	}
	static function tnhDOW($mysqldate) {
		return date('D', strtotime($mysqldate));
	}
	static function time_convert($time,$type){
		$time_hour=intval(substr($time,0,2));
		$time_minute=intval(substr($time,3,2));
		$time_seconds=intval(substr($time,6,2));
		if($type == 1):
			// 12 Hour Format with uppercase AM-PM
			$time=date("g:i A", mktime($time_hour,$time_minute,$time_seconds));
	elseif($type == 2):
		// 12 Hour Format with lowercase am-pm
		$time=date("g:i a", mktime($time_hour,$time_minute,$time_seconds));
	elseif($type == 3):
		// 24 Hour Format
		$time=date("H:i", mktime($time_hour,$time_minute,$time_seconds));
	elseif($type == 4):
		// Swatch Internet time 000 through 999
		$time=date("B", mktime($time_hour,$time_minute,$time_seconds));
	elseif($type == 5):
		// 9:30:23 PM
		$time=date("g:i:s A", mktime($time_hour,$time_minute,$time_seconds));
	elseif($type == 6):
		// 9:30 PM with timezone, EX: EST, MDT
		$time=date("g:i A T", mktime($time_hour,$time_minute,$time_seconds));
	elseif($type == 7):
		// Different to Greenwich(GMT) time in hours
		$time=date("O", mktime($time_hour,$time_minute,$time_seconds));
		endif;
		return $time;
	}
	
	
// given a latitude and longitude in degrees (40.123123,-72.234234) and a distance in miles
	// calculates a bounding box with corners $distance_in_km away from the point specified.
	// returns $min_lat,$max_lat,$min_lon,$max_lon 
	public static function getBoundingBox($lat,$lon,$rad) {
	
		$R = 6371; // of earth in km
	
		// first-cut bounding box (in degrees)
		$maxLat = $lat + rad2deg($rad/$R);
		$minLat = $lat - rad2deg($rad/$R);
		// compensate for degrees longitude getting smaller with increasing latitude
		$maxLon = $lon + rad2deg($rad/$R/cos(deg2rad($lat)));
		$minLon = $lon - rad2deg($rad/$R/cos(deg2rad($lat)));
		
		
		return array($minLat,$maxLat,$minLon,$maxLon);
	}
	
	public static function log_memory_usage($message = "Memory usage") {
		
		$mem_usage = memory_get_usage ( true );
		$string = '';
		if ($mem_usage < 1024)
			$string =  $mem_usage . " bytes";
		elseif ($mem_usage < 1048576)
			$string = round ( $mem_usage / 1024, 2 ) . " kilobytes";
		else
			$string = round ( $mem_usage / 1048576, 2 ) . " megabytes";
		
		error_log("$message : $string\n", 3, ERROR_LOG);
    
	}
	
	public static function log_memory_peak_usage($message = "Peak Memory usage") {
		
		$mem_usage = memory_get_peak_usage ( true );
		$string = '';
		if ($mem_usage < 1024)
			$string =  $mem_usage . " bytes";
		elseif ($mem_usage < 1048576)
			$string = round ( $mem_usage / 1024, 2 ) . " kilobytes";
		else
			$string = round ( $mem_usage / 1048576, 2 ) . " megabytes";
		
		error_log("$message : $string\n", 3, ERROR_LOG);
    
	}

	public static function city() {
		if (is_array($GLOBALS['city_id'])) {
			$city_id = $GLOBALS['city_id'][0];
		} else {
			$city_id = intval($GLOBALS['city_id']);
		}
		$city = new city(array('id' => $city_id));
		$city->retrieve();
		return $city;
	}


	static function textareaformat ($rawtext) {
		if ( !$rawtext ) {
			//trigger_error("[tnh::textareaformat] - no text to format", E_USER_WARNING);
			return false;
		}
		$output = preg_replace("/\\n/", "<br/>", $rawtext);
		return $output;
	}
	static function textareap ($rawtext) {
		if ( !$rawtext ) {
			//trigger_error("[tnh::textareap] - no text to format", E_USER_WARNING);
			return false;
		}
		$output = preg_replace("/(\\n)+/", "</p><p>", $rawtext);
		$output = '<p>' . $output . '</p>';
		$output = preg_replace("/(<p>)+/", '<p>', $output);
		$output = preg_replace("/(<\/p>)+/", '</p>', $output);
		return $output;
	}

	/* use this for parsing text on the way in */
	public static function hs($string, $linkify = false) {
		$safe = new HTML_Safe();
		$returnText = '';
		if ($linkify) {
			
			$returnText = preg_replace( '/(?!<\S)(\w+:\/\/[^<>\s]+\w)(?!\S)/i', '<a href="$1" target="_blank">$1</a>', $safe->parse(stripslashes($string)));
			//$returnText = preg_replace( '/(?!<\S)@(\w+\w)(?!\S)/i', '@<a href="http://twitter.com/$1" target="_blank">$1</a>',$safe->parse(stripslashes($string)) );
		} else {
			$returnText = $safe->parse(stripslashes($string));
		}
		return $returnText;
	}
	

	
	
	function tag_thing ( $entityid, $tag_array = array(), $replace = false) {
		$this->error = '';
		$error = array();
		if ( $entityid == '' ) {
			$error[] = 'no entity id present';
		}

		foreach ( $tag_array as $tag ) {
			//error_log(strtolower(trim($tag)), 3, ERROR_LOG);
			if ( !preg_match("/^[a-z0-9]*$/", strtolower(trim($tag))) ) {
				$error[] = 'tags may only have letters and numbers in them';
			}
			if ( strtolower(trim($tag)) == '' ) {
				$error[] = 'empty tag';
			}
		}
		$uid = $this->userObj->id;
		if ( $uid == '' ) {
			$error[] = 'no user id - please login';
		}
		if ( sizeof($error) > 0 ) {
			$this->error = $error;
			return false;
		}
		if ($replace == true) {
			tag::del_all_tags_from_thing($entityid);
		}
		foreach ( $tag_array as $tag ) {
			$t = new tag(array('tag' => strtolower(trim($tag)), 'user_id' => $uid, 'entity_id' => $entityid));
			if ( !$t->add() ) {
				return false;
			}
		}
		return true;
	}
	// method to add a favorite
	function favorite($entityid) {
		$error = array();
		if ( $entityid == '' ) {
			$error[] = 'no entity id present';
		}

		$uid = $this->userObj->id;
		if ( $uid == '' ) {
			return false;
		}
		if ( sizeof($error) > 0 ) {
			$this->error = $error;
			return false;
		}
		$fav = new favorite($uid, $entityid);
		//error_log("adding favorite", 3, ERROR_LOG);
		if ( $fav->add() ) {
			return true;
		} else {
			return false;
		}
	}


	static function dateCmp($a,$b) {
		if ($a->created_on == $b->created_on) {
			return $a->id < $b->id ? +1 : -1;
		}
		return $a->created_on < $b->created_on ? +1 : -1;
	}
	// to work around the PHP_SELF / PATH_INFO bug {
	static function php_self () {
		$return = '';
		return $_SERVER['REQUEST_URI'];
		if ( isset($_SERVER['PATH_INFO']) ) {
			$return = preg_replace('/\.php$/', '', $_SERVER['SCRIPT_NAME']) . $_SERVER['PATH_INFO'];
		} else {
			$return = preg_replace('/\.php$/', '', $_SERVER['PHP_SELF']);
		}
		return $return;
	}

	static function is_legal_characters($string) {
		return true;
		$p = '/^(
			 [\x09\x0A\x0D\x20-\x7E]            # ASCII
		 | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
		 |  \xE0[\xA0-\xBF][\x80-\xBF]        # excluding overlongs
		 | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
		 |  \xED[\x80-\x9F][\x80-\xBF]        # excluding surrogates
		 |  \xF0[\x90-\xBF][\x80-\xBF]{2}     # planes 1-3
		 | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
		 |  \xF4[\x80-\x8F][\x80-\xBF]{2}     # plane 16
		)*$/x';
		return preg_match($p, $string);
	}

	public static function is_vn_alphanumeric($string) {
		$p = '/[aAàÀảẢãÃáÁạẠăĂằẰẳẲẵẴắẮặẶâÂầẦẩẨẫẪấẤậẬbBcCdDđĐeEèÈẻẺẽẼéÉẹẸêÊềỀểỂễỄếẾệỆfFgGhHiIìÌỉỈĩĨíÍịỊjJkKlLmMnNoOòÒỏỎõÕóÓọỌôÔồỒổỔỗỖốỐộỘơƠờỜởỞỡỠớỚợỢpPqQrRsStTuUùÙủỦũŨúÚụỤưƯừỪửỬữỮứỨựỰvVwWxXyYỳỲỷỶỹỸýÝỵỴzZ0-9]/u';
		return preg_match($p, $string);
	}

	public static function latinize_vn($string) {
		//a
		$string = preg_replace('/[àảãáạăằẳẵắặâầẩẫấậ]/u', 'a', $string);
		$string = preg_replace('/[ÀẢÃÁẠĂẰẲẴẮẶÂẦẨẪẤẬ]/u', 'A', $string);
		// e
		$string = preg_replace('/[èẻẽéẹêềểễếệ]/u', 'e', $string);
		$string = preg_replace('/[ÈẺẼÉẸÊỀỂỄẾỆ]/u', 'E', $string);
		// i
		$string = preg_replace('/[ìỉĩíị]/u', 'i', $string);
		$string = preg_replace('/[ÌỈĨÍỊ]/u', 'I', $string);
		// o
		$string = preg_replace('/[òỏõóọôồổỗốộơờởỡớợ]/u', 'o', $string);
		$string = preg_replace('/[ÒỎÕÓỌÔỒỔỖỐỘƠỜỞỠỚỢ]/u', 'O', $string);
		// u
		$string = preg_replace('/[ùủũúụưừửữứự]/u', 'u', $string);
		$string = preg_replace('/[ÙỦŨÚỤƯỪỬỮỨỰ]/u', 'U', $string);
		// y
		$string = preg_replace('/[ỳỷỹýỵ]/u', 'y', $string);
		$string = preg_replace('/[ỲỶỸÝỴ]/u', 'y', $string);
		// d
		$string = preg_replace('/[đ]/u', 'd', $string);
		$string = preg_replace('/[Đ]/u', 'D', $string);
		return $string;
	}

	static function isvalidemail($Addr) {
		$p = '/^[a-z0-9!#$%&*+-=?^_`{|}~]+(\.[a-z0-9!#$%&*+-=?^_`{|}~]+)*';
		$p.= '@([-a-z0-9]+\.)+([a-z]{2,3}';
		$p.= '|info|arpa|aero|coop|name|museum|asia|travel|travel)$/ix';
		return preg_match($p, $Addr);
	}
	static function isvaliddate($date) {
		$p = '/\d{4}[-\/]\d{2}[-\/]\d{2}/';
		return preg_match($p, $date);
	}
	static function microtime_float() {
		return array_sum(explode(' ',microtime()));
	}

	static function validate_tag_input($tags) {
		$p = '/(([a-zA-z0-9]){2,}(,\s*|$))+/';
		if (preg_match($p, $tags) && preg_match('/^[a-zA-Z0-9,\s]+$/', $tags) ) {
			return 1;
		} else {
			return 0;
		}
	}
	static function strBytes($str)
	{
		// STRINGS ARE EXPECTED TO BE IN ASCII OR UTF-8 FORMAT

		// Number of characters in string
		$strlen_var = strlen($str);

		// string bytes counter
		$d = 0;

		/*
		 * Iterate over every character in the string,
		 * escaping with a slash or encoding to UTF-8 where necessary
		 */
		for ($c = 0; $c < $strlen_var; ++$c) {

			$ord_var_c = ord($str{$d});

			switch (true) {
			case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)):
				// characters U-00000000 - U-0000007F (same as ASCII)
				$d++;
				break;

			case (($ord_var_c & 0xE0) == 0xC0):
				// characters U-00000080 - U-000007FF, mask 110XXXXX
				// see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
				$d+=2;
				break;

			case (($ord_var_c & 0xF0) == 0xE0):
				// characters U-00000800 - U-0000FFFF, mask 1110XXXX
				// see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
				$d+=3;
				break;

			case (($ord_var_c & 0xF8) == 0xF0):
				// characters U-00010000 - U-001FFFFF, mask 11110XXX
				// see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
				$d+=4;
				break;

			case (($ord_var_c & 0xFC) == 0xF8):
				// characters U-00200000 - U-03FFFFFF, mask 111110XX
				// see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
				$d+=5;
				break;

			case (($ord_var_c & 0xFE) == 0xFC):
				// characters U-04000000 - U-7FFFFFFF, mask 1111110X
				// see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
				$d+=6;
				break;
			default:
				$d++;
			}
		}

		return $d;
	}
	static function validate_24h_time($time) {
		$p = '/^[0-2]*[0-9]{1}:[0-5]{1}[0-9]{1}(:[0-5]{1}[0-9]{1})?$/';
		return preg_match($p,$time);
	}


	static function add_querystring_var($url, $key, $value) {
		$url = preg_replace('/(.*)(\?|&)' . $key . '=[^&]+?(&)(.*)/i', '$1$2$4', $url . '&');
		$url = substr($url, 0, -1);
		if (strpos($url, '?') === false) {
			return ($url . '?' . $key . '=' . $value);
		} else {
			return ($url . '&' . $key . '=' . $value);
		}
	}

	static function php_multisort($data,$keys){



		// List As Columns
		$cols = array();
		foreach ($data as $key => $row) {
			foreach ($keys as $k){
				$cols[$k['key']][$key] = $row[$k['key']];
			}
		}
		// List original keys
		$idkeys=array_keys($data);
		// Sort Expression
		$i=0;
		$sort = '';
		foreach ($keys as $k){
			if($i>0){$sort.=',';}
			$sort.='$cols[\''.$k['key'].'\']';
			if($k['sort']){$sort.=',SORT_'.strtoupper($k['sort']);}
			if($k['type']){$sort.=',SORT_'.strtoupper($k['type']);}
			$i++;
		}
		$sort.=',$idkeys';
		// Sort Funct

		$sort='array_multisort('.$sort.');';
		eval($sort);
		// Rebuild Full Array
		foreach($idkeys as $idkey){
			$result[$idkey]=$data[$idkey];
		}
		return $result;
	}

	/**
	 * Truncates text.
	 *
	 * Cuts a string to the length of $length and replaces the last characters
	 * with the ending if the text is longer than length.
	 *
	 * @param string  $text String to truncate.
	 * @param integer $length Length of returned string, including ellipsis.
	 * @param string  $ending Ending to be appended to the trimmed string.
	 * @param boolean $exact If false, $text will not be cut mid-word
	 * @param boolean $considerHtml If true, HTML tags would be handled correctly
	 * @return string Trimmed string.
	 */
	public static function truncate($text, $length = 100, $ending = '...', $exact = true, $considerHtml = false) {
		if ($considerHtml) {
			// if the plain text is shorter than the maximum length, return the whole text
			if (mb_strlen(preg_replace('/<.*?>/', '', $text)) <= $length) {
				return $text;
			}
			// splits all html-tags to scanable lines
			preg_match_all('/(<.+?>)?([^<>]*)/s', $text, $lines, PREG_SET_ORDER);
			$total_length = mb_strlen($ending);
			$open_tags = array();
			$truncate = '';
			foreach ($lines as $line_matchings) {
				// if there is any html-tag in this line, handle it and add it (uncounted) to the output
				if (!empty($line_matchings[1])) {
					// if it's an "empty element" with or without xhtml-conform closing slash (f.e. <br/>)
					if (preg_match('/^<(\s*.+?\/\s*|\s*(img|br|input|hr|area|base|basefont|col|frame|isindex|link|meta|param)(\s.+?)?)>$/is', $line_matchings[1])) {
						// do nothing
						// if tag is a closing tag (f.e. </b>)
					} else if (preg_match('/^<\s*\/([^\s]+?)\s*>$/s', $line_matchings[1], $tag_matchings)) {
						// delete tag from $open_tags list
						$pos = array_search($tag_matchings[1], $open_tags);
						if ($pos !== false) {
							unset($open_tags[$pos]);
						}
						// if tag is an opening tag (f.e. <b>)
					} else if (preg_match('/^<\s*([^\s>!]+).*?>$/s', $line_matchings[1], $tag_matchings)) {
						// add tag to the beginning of $open_tags list
						array_unshift($open_tags, mb_strtolower($tag_matchings[1]));
					}
					// add html-tag to $truncate'd text
					$truncate .= $line_matchings[1];
				}
				// calculate the length of the plain text part of the line; handle entities as one character
				$content_length = mb_strlen(preg_replace('/&[0-9a-z]{2,8};|&#[0-9]{1,7};|[0-9a-f]{1,6};/i', ' ', $line_matchings[2]));
				if ($total_length+$content_length> $length) {
					// the number of characters which are left
					$left = $length - $total_length;
					$entities_length = 0;
					// search for html entities
					if (preg_match_all('/&[0-9a-z]{2,8};|&#[0-9]{1,7};|[0-9a-f]{1,6};/i', $line_matchings[2], $entities, PREG_OFFSET_CAPTURE)) {
						// calculate the real length of all entities in the legal range
						foreach ($entities[0] as $entity) {
							if ($entity[1]+1-$entities_length <= $left) {
								$left--;
								$entities_length += strlen($entity[0]);
							} else {
								// no more characters left
								break;
							}
						}
					}
					$truncate .= mb_substr($line_matchings[2], 0, $left+$entities_length);
					// maximum lenght is reached, so get off the loop
					break;
				} else {
					$truncate .= $line_matchings[2];
					$total_length += $content_length;
				}
				// if the maximum length is reached, get off the loop
				if($total_length>= $length) {
					break;
				}
			}
		} else {
			if (strlen($text) <= $length) {
				return $text;
			} else {
				$truncate = mb_substr($text, 0, $length - strlen($ending));
			}
		}
		// if the words shouldn't be cut in the middle...
		if (!$exact) {
			// ...search the last occurance of a space...
			$spacepos = strrpos($truncate, ' ');
			if (isset($spacepos)) {
				// ...and cut the text in this position
				$truncate = mb_substr($truncate, 0, $spacepos);
			}
		}
		// add the defined ending to the text
		$truncate .= $ending;
		if($considerHtml) {
			// close all unclosed html-tags
			foreach ($open_tags as $tag) {
				$truncate .= '</' . $tag . '>';
			}
		}
		return $truncate;
	}

	/**
	 * Generates a Universally Unique IDentifier, version 4.
	 *
	 * RFC 4122 (http://www.ietf.org/rfc/rfc4122.txt) defines a special type of Globally
	 * Unique IDentifiers (GUID), as well as several methods for producing them. One
	 * such method, described in section 4.4, is based on truly random or pseudo-random
	 * number generators, and is therefore implementable in a language like PHP.
	 *
	 * We choose to produce pseudo-random numbers with the Mersenne Twister, and to always
	 * limit single generated numbers to 16 bits (ie. the decimal value 65535). That is
	 * because, even on 32-bit systems, PHP's RAND_MAX will often be the maximum *signed*
	 * value, with only the equivalent of 31 significant bits. Producing two 16-bit random
	 * numbers to make up a 32-bit one is less efficient, but guarantees that all 32 bits
	 * are random.
	 *
	 * The algorithm for version 4 UUIDs (ie. those based on random number generators)
	 * states that all 128 bits separated into the various fields (32 bits, 16 bits, 16 bits,
	 * 8 bits and 8 bits, 48 bits) should be random, except : (a) the version number should
	 * be the last 4 bits in the 3rd field, and (b) bits 6 and 7 of the 4th field should
	 * be 01. We try to conform to that definition as efficiently as possible, generating
	 * smaller values where possible, and minimizing the number of base conversions.
	 *
	 * @copyright   Copyright (c) CFD Labs, 2006. This function may be used freely for
	 *              any purpose ; it is distributed without any form of warranty whatsoever.
	 * @author      David Holmes <dholmes@cfdsoftware.net>
	 *
	 * @return  string  A UUID, made up of 32 hex digits and 4 hyphens.
	 */

	public static function uuid() {

		// The field names refer to RFC 4122 section 4.1.2

		return sprintf('%04x%04x-%04x-%03x4-%04x-%04x%04x%04x',
					   mt_rand(0, 65535), mt_rand(0, 65535), // 32 bits for "time_low"
					   mt_rand(0, 65535), // 16 bits for "time_mid"
					   mt_rand(0, 4095),  // 12 bits before the 0100 of (version) 4 for "time_hi_and_version"
					   bindec(substr_replace(sprintf('%016b', mt_rand(0, 65535)), '01', 6, 2)),
					   // 8 bits, the last two of which (positions 6 and 7) are 01, for "clk_seq_hi_res"
					   // (hence, the 2nd hex digit after the 3rd hyphen can only be 1, 5, 9 or d)
					   // 8 bits for "clk_seq_low"
					   mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535) // 48 bits for "node"
					   );
	}

	public static function extensionByMimeType($mime_type) {
		$extension = '';
		if ( $mime_type == 'image/gif' ) {
			$extension = '.gif';
		} else if ($mime_type == 'image/jpeg' || $mime_type == 'image/pjpeg' || $mime_type == 'image/jpg' ) {
			$extension = '.jpg';
		} else if ($mime_type == 'image/png' ) {
			$extension = '.png';
		} else {
			return false;
		}
	}

   /**
    *
    * Convert an object to an array
    *
    * @param    object  $object The object to convert
    * @reeturn      array
    *
    */
    public static function objectToArray( $object )
    {
        if( !is_object( $object ) && !is_array( $object ) )
        {
            return $object;
        }
        if( is_object( $object ) )
        {
            $object = get_object_vars( $object );
        }
        return array_map( array('tnh', 'objectToArray'), $object );
    }

	public static function showAd($slot_id) {
		
		$adcampaign = adcampaign::getAdCampaignForSlotAndSite($slot_id, $GLOBALS['site_id']);
		return self::getAdStringForAdCampaign($adcampaign);
	}
	
	public static function showContextualAd($cat_id = null, $spotSearch = null, $v = null) {
		$adcampaign = '';
		if ($v) {
			$adcampaign = adcampaign::getContextualAdCampaignForVenue($v->id);
		} else if ($cat_id) {
			$cat = venuecategory::load($cat_id);
			
			$venues = $cat->getFeaturedContextualVenue($GLOBALS['city_id'], 2);
			if ($venues) {
				$venueIds = array();
				foreach ($venues as $venue) {
					$venueIds[] = $venue->id;
				}
				$adcampaign = adcampaign::getContextualAdCampaignForVenueIds($venueIds,2);
			}
		} 
		$vids = array();
		if ($spotSearch) {
			$vids = $spotSearch->spotIds;
		}
		if (!$adcampaign && $vids) {
			$adcampaign = adcampaign::getContextualAdCampaignForVenueIds($vids, 2);
		}
		$string = '';
		if ($adcampaign) {
			$string = self::getAdStringForAdCampaign($adcampaign);
		} else {
			$string = 'unpaid ad goes here';
		}
		return $string;
	}
	
	public static function getAdStringForAdCampaign($adcampaign) {
		$string = '';
		if (!$adcampaign) {
			return;
		}
		if (!is_array($adcampaign)) {
			$adcampaign = array($adcampaign);
		}
		if ($adcampaign) {
			$i = 0;
			foreach ($adcampaign as $ac) {
				if (!$ac) {
					continue;
				}
				$ad = $ac->ad();
				if ($i > 0) {
					$string .= '<br/><br/>';
				}
				$string .= '<a rel="nofollow" href="/adredir.php?a=' . $ac->id . '&amp;d=' . $ad->url . '" target="_new" title="' . tnh::hs($ad->alt) . '">';
				$string .= '<img src="' . DIR_WS_ADS . $ad->filename . '" width="' . $ad->width . '" height="' . $ad->height . '" alt="' . ($ad->alt ? $ad->alt : $ad->name) . '" /></a>';
				$i++;
			}
			$string .= '<br/><a href="/about/business-guide-advertising">' . sprintf(_('Advertise with %s'), $GLOBALS['site_name_short']) . '</a>';
		}
		return $string;
	}

	public static function jsEscape($string) {


	$js_escape = array("\r" => '\r',
	"\n" => '\n',
"\t" => '\t',
	  "'"  => "\\'",
'\\' => '\\\\');

          return strtr($string, $js_escape);
	}


	public static function calculate_median($arr) {
		$count = count($arr); //total numbers in array
		$middlevel = floor(($count-1)/2); // find the middle value, or the lowest middle value
		if($count % 2) { // odd number, middle is the median
			$median = $arr[$middlevel];
		} else { // even number, calculate avg of 2 medians
			$low = $arr[$middlevel];
			$high = $arr[$middlevel+1];
			$median = (($low+$high)/2);
		}
		return $median;
	}

	public static function prune_outliers($dataArray) {
		$dataArray = sort($dataArray);
		$aryCount = count($dataArray);
		$median = tnh::calculate_median($dataArray);

		$qOne = tnh::calculate_median(array_filter($dataArray, function($element) {
					if ($element < $median) {
						return true;
					}
					return false;
				}));
		echo "qOne: $qOne\n";
		$qThree = tnh::calculate_median(array_filter($dataArray, function($element) {
					if ($element > $median) {
						return true;
					}
					return false;
				}));
		echo "qThree: $qThree\n";
		return array_filter($dataArray, function($element) {
				if ($element < $qThree && $element > $qOne) {
					return true;
				}
				return false;
			});
	}

	function __destruct() {
		//echo number_format(memory_get_usage()) . "(beginning of destruct for object: " . get_class($this) . ")\n";
		foreach ($this as $index => $value) {
			if (is_object($this->$index)) {
				$this->$index->__destruct();
			}
			unset($this->$index);
		}
		//echo number_format(memory_get_usage()) . "(end of destruct for object: " . get_class($this) . ")\n";
	}


}
?>
