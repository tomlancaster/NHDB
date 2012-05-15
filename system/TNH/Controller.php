<?php
namespace TNH;
abstract class Controller {
	public $showTitleInPage = true;
	public $pageTitle = 'At Home in Hanoi';
	public $extra_keywords = array();
	public $description;
	public $extra_meta_tags;
	public $robots = 'index,follow';
	public $stylesheets = array('reset', 'style', '960', 'layout', 'newstyle', 'jkmegamenu', 'header-main', 'jquery-ui-1.8.9.custom', 'header-dropdowns', 'logos','colorbox');
	public $javascripts_head_first = array();
	public $javascripts_head = array('cQueryCache.js','jquery.json-2.2.min.js','jquery.hotkeys-0.7.9.min.js','keyboard-shortcuts.js','jquery.colorbox-min.js','header_functions.js','header_toolbar.js','jquery.bgiframe.min.js','jquery.hoverIntent.minified.js', 'jquery.periodicalupdater.js');
	public $javascripts = array('util.js', 'header.js');
      
	public $onload = false;
	public $onunload = false;
	public $specialjs = false;
	public $specialjsfile = false;
	public $headrss = false;
	public $results_wanted = REVIEWS_WANTED;
	public $headstyles = ''; //will be thrown into <style/> tags in the header
	public $headscripts_before = ''; //will be thrown into <script/> tags in the header before $javascripts_head, but after $javascripts_head_first
	public $headscripts_after = ''; //will be thrown into <script/> tags in the header after $javascripts_head
	public $headerfile = 'header.php';
	public $footerfile = 'footer.php';
	public $showError = true;
	public $prototype;
	public $add_link;
	public $debugmode = false; //quickly debug with $this->debugmode
	public static $related_model = '';
	public $loadRand = '';
	private $locationSet = false;
	public $entity = null;
	
	public $canonicalURL = null;
	public $canonicalLang = null;
	public $subent = null;
	
	
	
	public function __construct() {
		$this->loadRand = rand();
		if (!isset($_GET['show']) && !isset($_GET['edit'])) {
			/* this is not an entity view */
			$this->setLocation();
		}
		$this->debugmode = isset($_GET['debug']) || $this->debugmode;
		if (isset($_GET['killdebug'])) {
			setcookie("debug","0",time()-3600,"/");
			$this->debugmode = false;
			list($uri,$query) = explode("?",($_SERVER['HTTPS'] == 'on' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
			header("Location: ".$uri);
		}
		if ($this->debugmode) setcookie("debug","1",time()+3600*24,"/");
		
		header("X-UA-Compatible: chrome=1");
	}
	
	public function homePath() {
		global $tnh;
		$string = '/' . $GLOBALS['lang_short'] ;
		
		if (static::$related_model) {
			$string .= '/' . static::$related_model . '/home';
		}
		return $string;
	}
	
	public function setLocation() {
		if ($this->locationSet == true) {
			return;
		}
		// now determine which site / city we are.
		global $tnh;
		
		$sites = site::sites();
		$cities = array();
		if ($GLOBALS['app_context'] == 'cli') {
			$tnh->siteObj = $sites[0];
			$cities = $siteObj->cities();
			$tnh->cityObj = $cities[0];
		} else {
			/* we're on an entity page. It's the responsibility of the show methods to set the entity before calling setLocation() */
			if ($this->entity) {
				$tnh->cityObj = $this->entity->city();
				$tnh->siteObj = $this->entity->site();
			}
			
			/* determine city from params */
			if (isset($_GET['cid'])) {
				$tnh->cityObj = city::load(intval($_GET['cid']));
				if ($tnh->cityObj) {
					$tnh->siteObj = $tnh->cityObj->site();
				}
			}
			
			/* user is logged in. Has previously set a city */
			if (!$tnh->cityObj && $tnh->userObj->id) {
				if ($city_id = $tnh->userObj->get_pref('city_id')) {
					if ($tnh->cityObj = city::load($city_id)) {
						$tnh->siteObj = $tnh->cityObj->site();
					}
				}
			}
			
			/* user has a pre-existing cookie */
			if (!$tnh->cityObj && isset($_COOKIE['xz_city'])) {
				$tnh->cityObj = city::load(intval($_COOKIE['xz_city']));
				$tnh->siteObj = $tnh->cityObj->site();
			} 
			
			/* figure out site from url (this is going away) */
			@list($siteName, $domain,$tld) = explode('.', $_SERVER['HTTP_HOST']);
			if ($siteName == 'tnh') {
				$urlSiteObj = site::load(2);
			} else {
				foreach ($sites as $site) {
					
					if ($siteName == $site->url_form) {
							$urlSiteObj = $site;
							break;
					}
				}
			}
			
			if ($urlSiteObj->id != $tnh->siteObj->id) {
				/* do wut? trust the URL! */
				$tnh->siteObj = $urlSiteObj;
				$allowableCity = false;
				foreach ($tnh->siteObj->cities() as $testCity) {
					if ($tnh->cityObj->id == $testCity->id) {
						$allowableCity = true;
					}
				}
				if (!$allowableCity) {
					$tnh->cityObj = null;
				}
			}
			
			
			/* no city set -> choose first (default) */
			if (!$tnh->cityObj) {
				if (!is_object($tnh->siteObj)) {
					error_log("no site obj: " . print_r($_SERVER,true), 3, ERROR_LOG);
				}
				$siteCities = $tnh->siteObj->cities();
				$tnh->cityObj = $siteCities[0];
			}
			
		}
		
		
		if (!$tnh->siteObj || !$tnh->cityObj) {
			die("could not determine site or city");
		} 
		setcookie('xz_city', $tnh->cityObj->id, time()+60*60*24*365,'/', COOKIE_DOMAIN);
		
		$GLOBALS['site_id'] = $tnh->siteObj->id;
		$GLOBALS['city_id'] = $tnh->cityObj->id;
		$GLOBALS['country_id'] = $tnh->siteObj->country_id;
		$tnh->countryObj = $tnh->siteObj->country();
		$tnh->cityObj = $tnh->cityObj;
		$tnh->siteObj = $tnh->siteObj;
		if ($tnh->userObj->id) {
			$tnh->userObj->set_pref('site_id', $tnh->siteObj->id);
		}
		
		if ($tnh->userObj->id) {
			$tnh->userObj->set_pref('city_id', $tnh->cityObj->id);
		}
				
		
		// include the appropriate site configuration file
		include(DIR_FS_CONF . 'site_' . $tnh->siteObj->id . '.php');
		if (!isset($_GET['admin'])) {
			$this->stylesheets[] = 'site_' . $GLOBALS['site_id'];
		}
		date_default_timezone_set($GLOBALS['NAMED_TIME_ZONE']);
		$this->locationSet = true;
	}

	protected function header() {
		if ($GLOBALS['inAJAX']) {
			return;
		}
		global $tnh, $accepted_langs;
		$this->canonicalLang = null;
		if ($this->entity) {
			$this->canonicalLang = $this->entity->canonicalLang();
		}
		if (!$this->canonicalLang) {
			$this->canonicalLang = 'en';
		}
		if ($this->subent) {
			$this->canonicalURL = $this->subent->canonicalURL();
		} else if ($this->entity) {
			$this->canonicalURL = $this->entity->canonicalURL();
		}
		if (!$this->canonicalURL) {
			$host = 'http://' . tnh::wholeServer($GLOBALS['site_id']);
			list(, $caller) = debug_backtrace(false);
			if (preg_match('/home/', $caller['function'])) {
				$this->canonicalURL = $host . '/en/';
				if (!preg_match('/user/', $caller['class']) && !preg_match('/image/' , $caller['class'])) { // user is city-independent 
					$this->canonicalURL .= 'c/' . $GLOBALS['city_id'] . '/';
				}
				$this->canonicalURL .= $GLOBALS['module'] . '/home';
			} else if (preg_match('/list/', $caller['function'])) {
				$this->canonicalURL = $host . '/en/';
				if (!preg_match('/user/', $caller['class']) && !preg_match('/image/' , $caller['class'])) { // user is city-independent 
					$this->canonicalURL .= 'c/' . $GLOBALS['city_id'] . '/';
				}
				$this->canonicalURL .= $GLOBALS['module'] . '/list';
			} else {
				error_log("no canonical url for " . $caller['function'] . " in class " . $caller['class'] . "\n", 3, ERROR_LOG);
			}
			if ($this->canonicalURL) {
				if (isset($_GET['type'])) {
					$this->canonicalURL .= '?type=' . $_GET['type'];
				}
			}
		}
		include(DIR_FS_INCLUDES . $this->headerfile);
		//flush();
		/*
		global $memcache;
		if ($GLOBALS['USE_MEMCACHED'] === true) {
			if ($cachedP = $memcache->get("header#" . $GLOBALS['city_id'] . '_' . $GLOBALS['lang'])) {
				echo $cachedP;
			} else {
				ob_start();

				include(DIR_FS_INCLUDES . 'page_header.php');

				$memcache->add("header#" . $GLOBALS['site_id'] . '_' . $GLOBALS['lang'], ob_get_contents(), false, 36000);
				ob_end_flush();
			}
		} else {
			include(DIR_FS_INCLUDES . 'page_header.php');

		}
		*/


		return true;
	}

	protected function footer() {
		if ($GLOBALS['inAJAX']) {
			return;
		}
		include(DIR_FS_INCLUDES . $this->footerfile);
		return true;

		global $memcache;
		if ($GLOBALS['USE_MEMCACHED'] === true) {
			if ($cachedP = $memcache->get("footer#" . $GLOBALS['city_id'] . '_' . $GLOBALS['lang'])) {
				echo $cachedP;
			} else {
				ob_start();

				include(DIR_FS_INCLUDES . $this->footerfile);

				$memcache->add("footer#" . $GLOBALS['site_id'] . '_' . $GLOBALS['lang'], ob_get_contents(), false, 36000);
				ob_end_flush();
			}
		} else {
			include(DIR_FS_INCLUDES . $this->footerfile);
		}

		return true;
	}


	public function delete(array $params, array $post) {
		global $tnh;
		$mod = $GLOBALS['module'];
		$deleted_thing = $params['deleted_thing'];
		$deleted_content = $params['deleted_content'];
		if (!$deleted_thing) {
			tnh::eredir(sprintf(NOT_RETRIEVE,_('that deleted_thing')));
		}
		if ($post) {
			if (call_user_func(array($tnh->userObj, 'permitted_to_delete_' . $mod), $deleted_thing)) {
				$deleted_thing->delete($tnh->userObj->id, isset($post['deletion_reason']) ? $post['deletion_reason'] : '');
				tnh::mredir(sprintf(DELETED, $mod));
			} else {
				tnh::eredir(sprintf(NOT_PERMITTED,sprintf(_('to delete %s'), $mod)));
			}
		}
		list($reasons, $numreasons) = deletion_reason::fetch_where(array('item_type' => ucfirst($GLOBALS['module']), 'site_id' => $GLOBALS['site_id']));
		$this->pageTitle = sprintf(_('Delete %s'),substr(stripslashes($deleted_content),0,100) . '...');
		$this->header();
		include(DIR_FS_INCLUDES . 'delete.php');
		$this->footer();
	}





	public function modal_bail() {
		$this->headerfile = 'header_modal.php';
		$this->footerfile = 'footer_modal.php';
		$this->onload = 'parent.location.reload();';
		$this->showError = false;
		include(DIR_FS_INCLUDES . $this->headerfile);
		include(DIR_FS_INCLUDES . $this->footerfile);
		exit;
	}


	public static function return_http_error($status_code = 500, $error_array) {
		header("HTTP/1.1 500 Internal Server Error");
		echo implode('<br/>', $error_array);
		ob_end_flush();
		exit;
	}

	public static function messageText($message,$type="highlight",$id="",$style="") {
		return '
			<div id="'.$id.'" class="ui-widget" style="'.$style.'">
				<div class="ui-state-'.$type.' ui-corner-all" style="margin-top: 20px; margin-bottom: 20px; padding: 0 .7em;">
					<p style="margin: 1em 0px;"><span class="ui-icon ui-icon-info" style="float: left; margin-right: .3em;"></span>
					'.$message.'
					<span class="ui-icon ui-icon-close" style="float: right; margin-left: .3em;"></span> </p>
				</div>
			</div>
			';
			//
			//
	}
	
	public function formSecretMatches($post, $method) {
		$form_secret = $post['form_secret'];
		if(isset($_SESSION[$method])) {
			if(strcasecmp($form_secret,$_SESSION[$method])===0) {
				/*Put your form submission code here
				After processing the form data,
				unset the secret key from the session
				*/
				
				return true;
	 		} else {
				er('Invalid secret key' . ' session value is ' . $_SESSION[$method] . ' and post value is ' . $post['form_secret']);
				return false;
			}
		} else {
			//Secret key missing
			er('Form data has already been processed!');
			return false;
		}
		
	}
	
	public function resetFormSecret($method) {
		unset($_SESSION[$method]);
	}
	
	public function setNewFormSecret($method) {
		$secret = md5(uniqid(rand(), true));
		er("setting new secret. method is $method and secret is $secret");
		$_SESSION[$method] = $secret;	
	}


    public function sync($params,$post) {
		global $tnh;

		$lastSyncDev = @$post['lastSync'];

		$deviceNow = @$post['deviceNow'];
		/* server = device + offset;
		   device = server - offset
		*/
		$offset = time() - $deviceNow;
		$lastSyncServer = $lastSyncDev + $offset;
		$deviceFavs = json_decode(stripslashes(@$post['devicePayload']));
		error_log("devicePayload: " . print_r($deviceFavs,true), 3, ERROR_LOG);
		$curClass = get_called_class();
		$relModel = $curClass::$related_model;
		//error_log("related model: $relModel\n", 3, ERROR_LOG);
		$retObjs = $relModel::sync($lastSyncServer, $offset, $tnh->userObj, $deviceFavs);
		error_log("size of retObjs: " . sizeof($retObjs) . "\n", 3, ERROR_LOG);
		header("Content-Type: text/json");
		echo json_encode($retObjs);
		ob_end_flush();
		exit();
	}

}
