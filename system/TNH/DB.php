<?php
/*
 * The MIT License

Copyright (c) 2007-2010 Tom Lancaster, Xemzi Ltd. tom@newhanoian.com

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

 *
 */
namespace TNH;
abstract class DB {
	protected $data = array();
	protected static $fields = array();
	static $table;
	protected static $nullable = array();
	protected $creator;
	private $updater;
	protected $translated_fields = array();
	public $retrieved = false;
	static $klass;
	public static $datefields = array('created_on', 'updated_on', 'created', 'date', 'modified_on');
	const noCache = false;
	static $after_create = array();
	static $after_delete = array();
	static $after_edit = array();
	public static $memcached_expire_time = MEMCACHED_EXPIRE_TIME;

	function __construct($args = array()) {

		if ($args) {
			foreach ($args as $k => $v) {
				$key = strtolower($k);
				$this->$key = $v;
			}
		}

	}


	/**
	 * Static method to return an object. If the object is cacheable and cached it is returned from cache.
	 * @param int $id
	 * @return Ambiguous|boolean
	 */


	
	public static function load($id) {
		global $memcache;
		$object = null;
		$type = get_called_class();
		if ($GLOBALS['USE_MEMCACHED'] === true) {

			if ($object = $memcache->get($type . '#' . $id) && $object && $object->id) {
				return $object;
			} else {

				$object = new $type(array('id' => $id));
				$object->retrieve();
				//$object->populateObject();
				$memcache->add($type . '#' . $id, $object, false, static::$memcached_expire_time);
			}
		} else {
			$object = new $type(array('id' => $id));
			$object->retrieve();
			
		}
		if ($object->id) {
			return $object;
		} else {
			return false;
		}
	}

	


	/**
	 * Magic getter function. Returns instance variables or translated versions of them.
	 * @param string $member
	 * @return Ambiguous|boolean|Ambigous <string, multitype:>|Ambiguous
	 */
	public function __get($member) {
		$klass = get_class($this);
		if (in_array($member, array_keys($this->translated_fields))) {

			if (isset($this->translated_fields[$member]) && $value = $this->translated_fields[$member]->get($GLOBALS['lang'])) {

				return $value;
			} elseif ((in_array($member, $klass::$fields) && isset($this->data[$member])) ||
					  (in_array($member . '_en_us', $klass::$fields) && isset($this->data[$member . '_en_us']))) {

				$value = '';
				if (in_array($member, $klass::$fields) && isset($this->data[$member])) {
					$value = $this->data[$member];
				} elseif (in_array($member . '_en_us', $klass::$fields) && isset($this->data[$member . '_en_us'])) {
					$value = $this->data[$member . '_en_us'];
				} else {
					return false;
				}
				if ($value) {
					return $value;
				}



			} else {
				//er("nhdb::__get - no tf get, no fallback. Field is $member\n", 3, er);
			}
		}

		if (in_array($member, $klass::$fields)) {
			//er("$member is a field\n", 3, er);
			if (isset($this->data[strtolower($member)])) {
				return $this->data[strtolower($member)];
			} else {
				return false;
			}


		} else {
			if (in_array($member, get_class_methods($this))) {
				return $this->$member();
			} else {
				return false;
			}
		}
		return false;
	}

	/**
	 * Magic setter function
	 * @param string $member
	 * @param mixed $value
	 */
	public function __set($member, $value) {
		$klass = get_class($this);
		if (in_array($member, $klass::$fields)) {
			$this->data[$member] = $value;
		} elseif (in_array($member, get_class_methods($this))) {
			return $this->$member($value);
		} else {
			$this->$member = $value;
		}
		if (in_array($member, array_keys($this->translated_fields))) {
			//er("$member is in tf\n", 3, er);
			if (is_object($this->translated_fields[$member])) {
				//er("tf is object\n", 3, er);
				$this->translated_fields[$member]->set($GLOBALS['lang'],$value);
			} else {
				//er("making new tc, even though we don't have an id yet\n", 3, er);
				$this->translated_fields[$member] = new translation_collection($this->table(), $member,$this->id);
				$this->translated_fields[$member]->set($GLOBALS['lang'],$value);
			}
		}
	}

	/**
	 * Save function. Updates the cache if necessary. Also fills in meta data about times, users and locations if necessary
	 * @param boolean $cache
	 * @param integer $city_id
	 * @param integer $site_id
	 * @param integer $country_id
	 * @return boolean
	 */
	public function save($cache = true, $city_id = null, $site_id = null, $country_id = null) {
		
		global $tnh, $memcache;
		// validate
		$klass = get_class($this);
		if ($klass::noCache === true) {
			$cache = true;
		}
		$res = $this->validate();
		if ($res !== true) {
			return $res;
		}
		foreach ($this->data as $k => $v) {
			if (!$v && in_array($k, $klass::$nullable)) {
				$this->$k = 'null';
			}
		}
		if ( in_array('modified_by', $klass::$fields) ) {
			if ($tnh->userObj->id) {
				$this->modified_by = $tnh->userObj->id;
			} else {
				$this->modified_by = 0;
			}
		}

		if ( in_array('modified_on', $klass::$fields) ) {

			$this->modified_on = 'now()';

		}
		
		if ( in_array('date', $klass::$fields) ) {

			$this->date = 'now()';

		}

		if ( in_array('updated_on', $klass::$fields) ) {

			$this->updated_on = 'now()';
		} else if (in_array('updated', $klass::$fields) && (!$this->updated || $this->updated == 'null')) {
			$this->updated = 'now()';
		}

		if ( in_array('updated_by', $klass::$fields) ) {
			if ($tnh->userObj->id) {
				$this->updated_by = $tnh->userObj->id;
			} else {
				$this->updated_by = 0;
			}
		}
	
		if (in_array('city_id', $klass::$fields)) {
			if ($city_id) {
				$this->city_id = $city_id;
			} 
			if (!$this->city_id) {
				$this->city_id = $GLOBALS['city_id'];
			}
		}
	
		if (in_array('site_id', $klass::$fields) && !$this->site_id) {
			if ($site_id) {
				$this->site_id = $site_id;
			} else {
				if (method_exists($this, 'city')) {
					$this->site_id = $this->city()->site()->id;
				} else {
					$this->site_id = $GLOBALS['site_id'];
				}
			}
		}
		
		if (in_array('country_id', $klass::$fields) && !$this->country_id) {
			if ($country_id) {
				$this->country_id = $country_id;
			} else {
				if (method_exists($this, 'site')) {
					$this->country_id = $this->site()->country_id;
				} else {
					$this->country_id = $GLOBALS['country_id'];
				}
			}
		}
		
		if ( $this->id ) {
			$original = clone $this;
			if (in_array('created_on', $klass::$fields)) {
				unset($this->data['created_on']);
			}
			if (in_array('date', $klass::$fields)) {
				unset($this->data['date']);
			}
			if (in_array('created', $klass::$fields)) {
				unset($this->data['created']);
			}




			if ( $res = tep_db_perform($this->table(), $this->data, 'update', " id = {$this->id}") ) {
				// do translations

				foreach ($this->translated_fields as $tf) {
					$tf->save($this->id);
				}
				$this->retrieve();


				if ($GLOBALS['USE_MEMCACHED'] === true && $cache === true) {
					try {

						if (!$memcache->replace($klass . '#' . $this->id, $this, MEMCACHED_EXPIRE_TIME)) {
							//er("unable to add $klass {$this->id}\n", 3, er);
						} else {
							//er("successfully added $klass {$this->id}\n", 3, er);
						}

					} catch (MemCachedException $e) {
					}
				}

				if (get_class($this) != 'entity' && method_exists($this,'entitz')) {
					//er("saving entitz\n", 3, er);
					$this->entitz()->save();
				}
				// perform after_edit callbacks
				foreach ($klass::$after_edit as $ae) {
					er("calling after_edit function $ae");
					$this->{$ae}($original);
				}
				return true;
			} else {
				return false;
			}
		} else {
			if ( in_array('created_on', $klass::$fields ) && !$this->created_on) {
				$this->created_on = 'now()';
			} else if ( in_array('created', $klass::$fields) && !$this->created ) {
				$this->created = 'now()';
			}
			if ( in_array('lang', $klass::$fields) && !$this->lang) {
				$this->lang = $GLOBALS['lang'];
			}
			if ( in_array('created_by', $klass::$fields) ) {
				if (isset($tnh) && $tnh->userObj->id) {
					$this->created_by = $tnh->userObj->id;
				} else {
					$this->created_by = 0;
				}
			}


			if ($res = tep_db_perform($this->table(), $this->data)) {
				$this->id = tep_db_insert_id($res);
				// do translations
				//				er("tf: " . print_r($this->translated_fields,true), 3, er);
				foreach ($this->translated_fields as $tf) {
					if ($tf) {
						$tf->save($this->id);
					}
				}
				// perform after_create callbacks
				foreach ($klass::$after_create as $ac) {
					er("calling after_create function $ac");
					$this->{$ac}();
				}
				return true;

			} else {
				return false;
			}
		}
	}

	/**
	 * Pull from the database. Does some timezone magic. Also gets translations.
	 * @return boolean
	 */
	public function retrieve() {
		$klass = get_class($this);
		$fields = $klass::$fields;

		if ($current_date_fields = array_intersect($klass::$datefields, $fields)) {
			foreach ($current_date_fields as $cdf) {
				$fields[$cdf] = "convert_tz(" . $cdf . ", 'SYSTEM', '" . $GLOBALS['TIME_ZONE']  . "') as $cdf";
				//er("field in retrieve: " . $fields[$cdf] . "\n", 3, er);
			}
		}
		//echo number_format(memory_get_usage()) . "(after fields)\n";
		$klass = get_called_class();
		if ($this->id) {
			$sql = "select " . implode(',', $fields) . " from {$klass::$table} where id = '{$this->data['id']}'";
			$res = tep_db_query($sql);
			if ($row = tep_db_fetch_array($res)) {
				foreach ($row as $k => $v) {
					$this->data[strtolower($k)] = $v;
				}
			} else {
				return false;
			}
			//echo number_format(memory_get_usage()) . "(after assigning row to data)\n";
			tep_db_free_result($res);
			unset($res);
			destroy($row);
			destroy($k);
			destroy($v);
			
			$this->prepare_translations();

			return true;

		}
		return false;
	}

	
	/**
	 * Clears an object from cache
	 */
	public function unload() {
		global $memcache;
		$klass = get_class($this);
		if ($GLOBALS['USE_MEMCACHED'] === true) {
			try {
				$memcache->delete(get_class($this) . '#' . $this->id,0);
			} catch (MemCachedException $me) {
				er("memcached exception: " . $me->getMessage() . "\n");
			}

		}
	}

	
	/**
	 * Replaces a cached object
	 * @param object $object
	 * @return boolean
	 */
	public function cache_replace($object) {
		if ($GLOBALS['USE_MEMCACHED'] === true) {
			global $memcache;
			try {
				$memcache->replace(get_class($object) . '#' . $object->id, $object, MEMCACHED_EXPIRE_TIME);
			} catch (MemCachedException $me) {
				er("memcached exception: " . $me->getMessage() . "\n" . print_r($object,true));
			}
		}
		return true;
	}

	/**
	 * Soft, or hard deletes an object.
	 * @param integer $deleter
	 * @param string $reason
	 * @param boolean $sendPM
	 * @return boolean
	 */
	function delete($deleter = 0, $reason = '', $sendPM = true) {
		$klass = get_class($this);
		global $memcache, $tnh;
		
		if ($this->id) {

			if (in_array('deleted',$klass::$fields)) {

				$this->deleted = '1';
				if (in_array('deletion_reason', $klass::$fields)) {
					$this->deletion_reason = str_replace('[username]', $this->creator()->username, $reason);
				}
				if (in_array('deleted_by', $klass::$fields)) {
					$this->deleted_by = $deleter > 0 ? $deleter : $tnh->userObj->id;
					$this->deleted_on = 'now()';
				}
				$res = $this->save();
				if ($res !== true) return $res;
				
				er("got to here");
				// send message about deletion (if present):
				if ($reason && $sendPM) {
					
					$creator = $this->creator();
					$deletionPM = new pms(array('sender_user_id' => $tnh->userObj->id,
												'recipient_user_id' => $creator->id,
												'subject' => _("Content Deleted"),
												'message' => $this->deletion_reason
										 ));
					er("pm:" . print_r($deletionPM,true));
					$deletionPM->send();
				}
				
			} else {
				$sql = "delete from " . $this->table() . " where id = " . $this->id;
				$res = tep_db_query($sql);
			}
			
			if ($GLOBALS['USE_MEMCACHED'] === true) {
				try {
					$memcache->delete($klass . '#' . $this->id);
				} catch (MemCachedException $me) {
					error_log("unable to delete from memcached: " . $me->errorMessage());
				}
			}
			foreach ($klass::$after_delete as $ad) {
				er("calling after_delete function $ad");
				$this->{$ad}();
			}
			
			$this->data = array();
			return true;

		}

		return false;
	}
	
	
	/*
	 * after_delete callback to delete activities (if any)
	 */
	public function delete_activities() {
		$klass = get_class($this);
		$id = $this->id;
		$sql = "select a.id from activities a inner join activity_types at on a.activity_type_id = at.id where at.verb = 'delete' and ";
		$sql .= " (a.actor_id = $id and a.actor_type = '$klass') or (a.object_id = $id and a.object_type = '$klass') or (a.indirect_object_id = $id and a.indirect_object_type = '$klass')";
		$res = tep_db_query($sql);
		$ids = array();
		while ($row = tep_db_fetch_array($res)) {
			$ids[] = $row['id'];
		}
		error_log("deleting activities on_delete: " . print_r($ids,true), 3, ERROR_LOG);
		$sql = "delete from activities where id in (" . implode(',',$ids) . ")";
		$res = tep_db_query($sql);
	}

	/**
	 * Function to undo a soft delete
	 * @param integer $restored_by
	 * @param string $reason
	 * @return boolean
	 */
	function restore($restored_by = 0, $reason = '') {
		$klass = get_class($this);
		global $memcache, $tnh;
		er("in delete");
		if ($this->id) {


			if (in_array('deleted',$klass::$fields)) {

				$this->deleted = '0';

				$this->restored_reason = str_replace('[username]', $this->creator()->username, strip_tags($reason));
				$this->restored_by = $restored_by > 0 ? $restored_by : $tnh->userObj->id;
				$this->restored_on = 'now()';
				if ($this->save() !== true) return false;

				// send message about restoration (if present):
				if ($this->restored_reason) {
					$RestoredPM = new pms(array('sender_user_id' => $tnh->userObj->id,
												'recipient_user_id' => $this->creator()->id,
												'subject' => _("Content Restored"),
												'message' => $this->restored_reason
										 ));
					er("pm:" . print_r($RestoredPM,true));
					$RestoredPM->send();
				}
			} else return false;

			return true;
		}

		return false;
	}

	
	/**
	 * Function to fetch multiple objects by SQL. The statement should return the ids of the object class you wish returned.
	 * @param string $sql
	 * @param string|integer $results_wanted
	 * @param integer $offset
	 * @return array of objects, integer 
	 */
	public static function fetch_many_by_sql($sql, $results_wanted = 'all', $offset = 0) {
		$klass = get_called_class();
		
		
		$res = tep_db_query($sql);
		$results = array();
		while ($row = tep_db_fetch_array($res)) {
			$results[] = $row;
		}
		tep_db_free_result($res);
		$rows = sizeof($results);
		if ($results_wanted != 'all') {
			$wanted_results = array_slice($results, $offset, $results_wanted);
		} else {
			$wanted_results = $results;
		}
		$objs = array();
		//er("wr: " . print_r($wanted_results,true));
		foreach ($wanted_results as $wr) {
			$g = $klass::load($wr['id']);
			if ($g) {
				$objs[] = $g;
			}


		}
		return array($objs, $rows);
	
	}
	
	
	/**
	 * Function to return a http link to this instance
	 * @return string
	 */
	public function internalLink() {
		error_log("internalLink not defined in " . get_called_class());
		return '';
	}

	
	/**
	 * Returns the count of rows returned from the where clause specified.
	 * @param unknown_type $where_array_or_string
	 * @param unknown_type $results_wanted
	 * @param unknown_type $offset
	 * @param unknown_type $orderby
	 * @param unknown_type $direction
	 * @param unknown_type $city_id
	 * @return Ambiguous
	 */
	public static function count_where($where_array_or_string, $results_wanted = 'all', $offset = 0, $orderby = '', $direction = 'asc', $city_id = null) {
		if (!$city_id) {
			$city_id = $GLOBALS['city_id'];
		}
		$klass = get_called_class();
		$sql = "select count(id) as total from {$klass::$table}";
		
		// do where clause
		if (is_array($where_array_or_string)) $where_array = $where_array_or_string;
		elseif (is_string($where_array_or_string)) $where = ' WHERE '.$where_array_or_string;
		
		$wherePieces = array();
		if (@$where_array) {
			foreach ($where_array as $field => $value) {
				if (in_array($field, $klass::$fields)) {
					$wherePieces[] = "$field = '" . tep_esc($value) . "'";
				}
			}
			$where = " WHERE ";
			if ($wherePieces) {
				$where .= implode(' AND ', $wherePieces);
			} else {
				$where .= "1 = 1";
			}
		}
		$sql .= $where;
		if ($city_id && in_array('city_id', $klass::$fields) && is_numeric($city_id)) {
			$sql .= " AND city_id = $city_id";
		}
		if ($orderby && in_array($orderby, $klass::$fields)) {
			$sql .= " ORDER BY $orderby $direction";
		}
		$res = tep_db_query($sql);
		$row = tep_db_fetch_array($res);
		return $row['total'];
	}




	public static function fetch_where($where_array_or_string, $results_wanted = 'all', $offset = 0, $orderby = '', $direction = 'asc', $city_id = null) {
		if ($city_id == null) {
			$city_id = @$GLOBALS['city_id'];
		}
		$klass = get_called_class();
		$sql = "select id from {$klass::$table}";
		// do where clause
		$where_array = array();
		if (!$where_array_or_string ) {
			$where_array_or_string = '1 = 1';
		}
		if (is_array($where_array_or_string)) {
			$where_array = $where_array_or_string;
		} elseif (is_string($where_array_or_string)) {
			$where = ' WHERE '.$where_array_or_string;
		} else {
			$where = ' WHERE 1=1';
		}
		
		$wherePieces = array();
		if ($where_array) {
			foreach ($where_array as $field => $value) {
				if (in_array($field, $klass::$fields)) {
					if (is_array($value)) {
						$wherePieces[] = "$field IN ('" . implode("','", $value) . "')";
					} else {
						if (in_array(substr($value,0,1),array('>','<','!'))) {
							$operator = substr($value,0,1);
							if ($operator == '!') {
								$operator = '!=';
							}
							$value = ltrim($value,'><!');
						} else {
							$operator = '=';
						}
						$wherePieces[] = "$field " . $operator .  " '" . tep_esc($value) . "'";
					}
				}
			}
			$where = ' WHERE ';
			if ($wherePieces) {
				$where .= implode(' AND ', $wherePieces);
			} else {
				$where .= '1 = 1';
			}
		}
		$sql .= $where;
		if ($city_id && in_array('city_id', $klass::$fields) && is_numeric($city_id)) {
			$sql .= " AND city_id = $city_id";
		}
		if (in_array('deleted', $klass::$fields) && !isset($klass::$include_deleted_in_fetch)) {
			$sql .= " AND deleted != '1'";
		}
		if ($orderby && in_array($orderby, $klass::$fields)) {
			$sql .= " ORDER BY $orderby $direction";
		}
		$res = tep_db_query($sql);
		$ids = array();
		while ($row = tep_db_fetch_array($res)	) {
			$ids[] = $row['id'];
		}
		$rows = sizeof($ids);
		if ($results_wanted == 'all') {
			$results_wanted = $rows;
		}

		$results = array();
		foreach (array_slice($ids, $offset, $results_wanted) as $id) {
			if ($object = $klass::load($id)) {
				$results[] = $object;
			}
		}
		return array($results, $rows);
	}

	public static function fetch_all($results_wanted = 'all', $offset = 0, $orderby = '', $direction = 'asc', $city_id = null) {
		$klass = get_called_class();
		return $klass::fetch_where(null, $results_wanted, $offset, $orderby, $direction, $city_id);
	}


	public function retrieve_rel_by_fk($fk_name) {
		$klass = get_class($this);
		$foreign_class = $klass::$fks_classes[$fk_name];
		if (!$foreign_class) {
			throw new TNHException("Could not find class for foreign key $fk_name");
		}
		$obj = $foreign_class::load($this->$fk_name);
		return $obj;
	}

	public function update_attributes($args) {
		$klass = get_class($this);
		foreach ($klass::$fields as $f) {
			if ($f != 'id' && in_array($f, array_keys($args))) {
				$this->$f = str_replace("\\r\\n", "\n", $args[$f]);
			}
		}
	}
	/**
	 * returns a user object corresponding to the user who created this object
	 * @return user
	 */
	public function creator() {
		if (!$this->creator) {
			$uid = ($this->created_by) ? $this->created_by : @$this->user_id;
			if ($u = user::load($uid)) {
				$this->creator = $u;
			}
		}
		return $this->creator;
	}

	/**
	 * Returns a user object corresponding to the user who updated this object
	 * @return user
	 */
	public function updater() {
		if (!$this->updater) {
			$u = user::load($this->updated_by);
			if ($u) {
				$this->updater = $u;
			}
		}
		return $this->updater;
	}
	
	/**
	 * returns the iso601 language code (2) of the original language of this object (or 'en' if unknown)
	 * @return iso601(2) string
	 */
	public function canonicalLang() {
		$lang = '';
		
		global $langs_accepted;
		
		if ($this->creator() && $this->creator()->primary_lang) {
			$lang = $langs_accepted[$this->creator()->primary_lang];
		}
		if (!$lang) {
			$lang = 'en';
		}
		
		return $lang;
	}

	/**
	 * Returns a user object corresponding to the user who modified this object
	 * @return user
	 */
	public function modifier() {
		if (!$this->modifier) {
			$this->modifier = user::load($this->modified_by);
		}
		return $this->modifier;
	}

	/* crazy workaround for the fact that 'self' does not follow inherited context in PHP < 5.3 */
	// FIXME not needed any more??
	protected function table() {
		return static::$table;
	}



	public function setTranslation($lang, $field, $value) {
		$klass = get_class($this);
		$this->prepare_translations();
		if (!$this->translated_fields[$field]) {
			return false;
		}
		$this->translated_fields[$field]->set($lang,$value);
		return $this->translated_fields[$field]->save($this->id);
	}

	public function getTranslation($lang, $field) {
		$klass = get_class($this);
		return $this->translated_fields[$field]->get($lang);
	}

	public function prepare_translations() {
		$klass = get_class($this);
		foreach (array_keys($this->translated_fields) as $tf) {
			$this->translated_fields[$tf] = new translation_collection($klass::$table, $tf, $this->id);
		}
		return true;
	}



    public function setUpTranslations($langCode = 'en_US') {

		foreach ($this->translated_fields as $k => $v) {
			$trans = new translation(array('original_table' => $this->table(),
										   'original_column' => $k,
										   'original_id' => $this->id,
										   'language_code' => $langCode,
										   'translated_string' => $this->data[$k]));
			if (!$trans->save()) {
				er("unable to add translation\n");
			}
		}
	}

	protected function selfAsArray() {
		$selfArray = array();
		foreach ($this as $k => $v) {
			$selfArray[$k] = $v;
		}
		return $selfArray;
	}

	/* need this because php < 5.3 doesn't garbage collect circular references */
    public function __destruct() {
    	/*
        foreach ($this as $index => $value) {
			if (is_object($this->$index)) {
				$this->$index->__destruct();
			}
			unset($this->$index);
		}
		*/
    }



	/**
	 * performs validation on the members of the object and returns errors or true
	 * @return boolean|string
	 */
	public function validate() {
		er("please install validation for " . get_class($this) . "\n");
		return true;
	}


	/* Sync Functions - Not ready for OSS yet */


	public static function sync($lastSyncTimeServer, $offset, $userObj, $devicePayload = array(), $predicate = '', $sql = '') {
		/*
		 server = device + offset;
		 device = server - offset 
		*/
		
		$res = tep_db_query("select now()");
		$row = tep_db_fetch_array($res);
		//er("mysql time:" . print_r($row,true));
		error_log("last sync server:" . date("Y-m-d H:i:s", $lastSyncTimeServer) . "\n", 3, ERROR_LOG);
		error_log("offset: $offset\n", 3, ERROR_LOG);
		$klass = get_called_class();
		
		$changedDevObjs = $deletedDevObjs = $newDevObjs = array();
		if (is_array($devicePayload))  {
			foreach ($devicePayload as $deviceObject) {
				if (!is_object($deviceObject)) {
					$deviceObject = arrayToObject($deviceObject);
				}
				//error_log("deviceObject: " . print_r($deviceObject, true), 3, ERROR_LOG);
				if ((isset($deviceObject->deleted) && $deviceObject->deleted == 1) || (isset($deviceObject->status_code) && $deviceObject->status_code == 10)) {
					//er("this is a deleted $klass");
					if (!$deviceObject->web_id || $deviceObject->web_id <= 0) {
						// get the id
						er("deleted: web_id is 0 or not set. looking for corresponding object by guid");
						$potentialObj = $klass::load($deviceObject->device_guid);
						if ($potentialObj->id) {
							//er("deleted: loaded successfully");
							//er("deleted: potential obj:" . print_r($potentialObj,true));
							$deviceObject->id = $potentialObj->id;
							//er("dev id: " . $deviceObject->id . " - adding to deletedDevObjs");
							$deletedDevObjs[] = $deviceObject;
						} else {
							er("deleted: could not find corresponding object for guid " . $deviceObject->device_guid);
							//nothing. it's not on the web so we just throw it out.
						}
					} else {
						//er("deleted obj has web_id");
						$deletedDevObjs[] = $deviceObject;
					}
				
				} elseif ((isset($deviceObject->web_id) && intval($deviceObject->web_id) > 0)
							|| (isset($deviceObject->id) && intval($deviceObject->id) > 0) 
							) {
					
					$changedDevObjs[] = $deviceObject;
				} else {
					if ($deviceObject->device_guid) {
						// load via guid
						//er("else: loading via guid");
						$potentialObj = $klass::load($deviceObject->device_guid);
						if (intval($potentialObj->id) > 0) {
							//er("else: loaded successfully. pot obj is:" . print_r($potentialObj,true));
							$deviceObject->id = $potentialObj->id;
							//er("else: dev id: " . $deviceObject->id);
							$changedDevObjs[] = $deviceObject;
						} else {
							//er("failed to look up object; adding to new objs");
							$newDevObjs[] = $deviceObject;
						}
					} else {
						// the rest we assume are new
						$newDevObjs[] = $deviceObject;
					}
				}
			}
		}
		

		
		/* apply deletions */
		//er("deleted Objects:" . print_r($deletedDevObjs,true));
		foreach ($deletedDevObjs as $deletedDevObj) {
			$deletedDevObj->id = $deletedDevObj->web_id;
			$deletedCastObj = new $klass(objectToArray($deletedDevObj));
			//error_log("cast object: " . print_r($deletedCastObj,true), 3, ERROR_LOG);
			if ($userObj->can_delete($deletedCastObj)) {
				//er("deleting object: " . print_r($deletedCastObj,true));
				//$deletedCastObj->id = $deletedDevObj->web_id;
				$deletedCastObj->delete($userObj->id, "deleted on mobile device");
			}
		}
		unset($deletedDevObjs, $deletedCastObj, $deletedDevObj);
		
		/* do changes - this is in a separate method so it can be overridden */
		$klass::sync_changes($changedDevObjs, $offset, $userObj, $klass);

		/* add new objects */
		$klass::sync_additions($newDevObjs, $offset, $userObj, $klass);

		$returnObjs = $klass::sync_return($lastSyncTimeServer, $offset, $sql, $predicate, $klass);
		
		//er("klass is $klass");
		//error_log("return objs: " . print_r($returnObjs,true), 3, ERROR_LOG);
		return $returnObjs;
			
	}
	
	
	public static function sync_changes($changedDevObjs, $offset, $userObj, $klass) {
		
		/* apply changes */
		//er("in sync_changes; klass is $klass");
		foreach ($changedDevObjs as $changedDevObj) {
			$serverComparisonObj = '';
			$realId = (isset($changedDevObj->web_id)) ? $changedDevObj->web_id : $changedDevObj->id;
			if (!$realId) {
				$realId = $changedDevObj->device_guid;
			}
			$serverComparisonObj = $klass::load($realId);
			if (is_object($serverComparisonObj)) {
				if ($userObj->can_change($serverComparisonObj)) {
					//er("timezone is: " . date_default_timezone_get());
					//er("cdo modified: " . $changedDevObj->modified_on . " comparison mod: " . (strtotime($serverComparisonObj->modified_on) - $offset) . " in english:" . $serverComparisonObj->modified_on);
					if ($changedDevObj->modified_on >= (strtotime($serverComparisonObj->modified_on . ' UTC') - $offset)) {
						$updateArray = objectToArray($changedDevObj);
						$serverComparisonObj->update_attributes_mobile($updateArray, $offset);
						//er("server comparison obj is:" . print_r($serverComparisonObj,true));
						$serverComparisonObj->save();
						//$changedCastObj = new $klass(objectToArray($changedDevObj));
						//$changedCastObj->save();
					}
				} else {
					error_log(sprintf("user %s not allowed to edit object:", $userObj->username) . print_r($serverComparisonObj,true), 3, ERROR_LOG);
				}
			} else {
				er("unable to load object of class $klass using id $realId");
			}
		}
		unset($changedDevObjs, $changedDevObj, $serverComparisonObj);
	}
	
	public static function sync_additions($newDevObjs, $offset, $userObj, $klass) {
		if ($userObj->can_create($klass)) {
			foreach ($newDevObjs as $newDevObj) {
				$newDevObj->created_on = $newDevObj->modified_on;
				
				$newObj = new $klass();
				$newObj->update_attributes_mobile(objectToArray($newDevObj), $offset);
				//er(print_r($newObj,true));
				$newObj->modified_on = 'now()';
				$result = $newObj->save();
				if ($result !== true) {
					error_log("error while saving new $klass from device: " . print_r($result,true) . "\n", 3, ERROR_LOG);
				}
				unset($result);
			}
		}
		unset($newDevObjs, $newDevObj, $newObj);
	}
	
	public static function sync_return($lastSyncTimeServer, $offset, $sql = '', $predicate = '', $klass) {
		/* get objects to return */
		if (!$sql) {
			$sql = sprintf("select id from %s where %s >= '%s'", $klass::$table, in_array('modified_on', $klass::$fields) ? 'modified_on' : 'created_on', date('Y-m-d H:i:s', $lastSyncTimeServer));
			if ($predicate) {
				$sql .= " and $predicate";
			}
		}
		$res = tep_db_query($sql);
		$returnObjs = array();
		while ($row = tep_db_fetch_array($res)) {
			$returnObjs[] = $klass::load($row['id'])->getData();
		}
		//er("return objects (before adjustment): " . print_r($returnObjs,true));
		unset($res, $sql, $row);
		return $klass::adjust_for_return_to_device($returnObjs, $offset);
	}
	
	public static function adjust_for_return_to_device($aryArgAry, $offset) {
		$returnAry = array();
		foreach ($aryArgAry as $argAry) {
			foreach ($argAry as $key => $val) {
				if ($val && !is_numeric($val)) {
					$argAry[$key] = stripslashes($val);
				}
			}
			
			$argAry['modified_on'] = (strtotime(@$argAry['modified_on']) - $offset);
			if (isset($argAry['id']) && !isset($argAry['web_id'])) {
				$argAry['web_id'] = $argAry['id'];
			}
			foreach (array('created_on') 
							as $theDateField) {
				if (array_key_exists($theDateField, $argAry)) {
					if ($argAry[$theDateField] > 0) {
						$argAry[$theDateField] = strtotime($argAry[$theDateField]);
					} else {
						$argAry[$theDateField] = -1;
					}
				}
			}
			$argAry['pkey'] = ""; //IMPORTANT - leave this here or the device crashes
			unset($argAry['id']);
			$returnAry[] = $argAry;
		}
		unset ($aryArgAry, $argAry);
		return $returnAry;
	}

	
	public function update_attributes_mobile($args, $offset) {
		// need to apply offset and translate dates to mysql format
		/* device = server - offset */
		/* server = device + offset */
		$klass = get_class($this);
		//("in update atts mobile. klass is $klass");
		foreach ($klass::$fields as $f) {
			
			if ($f != 'id' && in_array($f, array_keys($args))) {
			
				$newVal = $args[$f];
				//er("f is $f");
				if (in_array($f, nhdb::$datefields)) { 
					//apply skew
					//er("$f is a date field");
					$newVal = $newVal + $offset;
					// convert to mysql
					$newVal = date("Y-m-d H:i:s", $newVal);
					//er("new date is $newVal");
					$this->$f = $newVal;
					//er("this after new dates:" . print_r($this,true));
				} else if ( in_array($f, nhdb::$dateFieldsConstant)) {
					$newVal = date("Y-m-d H:i:s", $newVal);
					//er("new date is $newVal");
					$this->$f = $newVal;
					
				} elseif (is_string($newVal)) {
					$this->$f = str_replace("\\r\\n", "\n", tep_esc($newVal));
				} else {
					$this->$f = $newVal;
				}
				if ($f == 'spec_section_id') {
					if ($args[$f] <= 0) {
						$this->$f = 'null';
					}
				}
			}
			
		}
		if (in_array('project_id', $klass::$fields)) {
			$this->project_id = $GLOBALS['project_id'];
		}
	}

	/**
	 * returns the age of this object in months (intervals of 30 days, actually)
	 * @return boolean|number
	 */
	public function age_in_months() {
		$klass = get_called_class();
		if (in_array('created_on', $klass::$fields)) {
			$date = $this->created_on;
		} else if (in_array('created', $klass::$fields)) {
			$date = $this->created;
		} else {
			return false;
		}
		
		$daysOld = (time()  - strtotime($date)) / (60*60*24);
		//er("days old: $daysOld");
		return ($daysOld / 30);
	}



	public function getData() {
		return $this->data;
	}



  }
?>
