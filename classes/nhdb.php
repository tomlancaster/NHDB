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

abstract class nhdb {
	protected $data = array();
	protected static $fields = array();
	static $table;
	protected static $nullable = array();
	private $creator;
	private $updater;
	protected $translated_fields = array();
	public $retrieved = false;
	static $klass;
	public static $datefields = array('created_on', 'updated_on', 'created', 'date', 'modified_on');


	function __construct($args = array()) {
		
		if ($args) {
			foreach ($args as $k => $v) {
				$key = strtolower($k);
				$this->$key = $v;
			}
		}
		
	}


	public static function load($id) {
		
		global $memcache;
		
		$klass = get_called_class();

		
		if ($GLOBALS['USE_MEMCACHED'] === true) {

			if ($object = $memcache->get($klass . '#' . $id)) {
				return $object;
			} else {

				$object = new $klass(array('id' => $id));
				if ($object->retrieve()) {
					$memcache->add($klass . '#' . $id, $object, false, MEMCACHED_EXPIRE_TIME);
					return $object;
				}
			}
		} else {
			$object = new $klass(array('id' => $id));
			
			if ($object->retrieve()) {
				return $object;
			}
		}
		return false;
	}


	public function __get($member) {
		$klass = get_class($this);
		if (in_array($member, array_keys($this->translated_fields))) {

			if (isset($this->translated_fields[$member]) && $value = $this->translated_fields[$member]->get($GLOBALS['lang'])) {
				
				return $value;
			} elseif ((in_array($member, $klass::$fields) && isset($this->data[$member])) ||
					  (in_array($member . '_en_US', $klass::$fields) && isset($this->data[$member . '_en_us']))) {
				
				$value = '';
				if (in_array($member, $klass::$fields) && isset($this->data[$member])) {
					$value = $this->data[$member];
				} elseif (in_array($member . '_en_US', $klass::$fields) && isset($this->data[$member . '_en_us'])) {
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

	public function save($cache = true) {

		global $tnh, $memcache;
		// validate
		$klass = get_class($this);
		er("klass is $klass");
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
		
		if ( $this->id ) {

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
				return true;

			} else {
				return false;
			}
		}
	}
	
	public function retrieve() {
		$klass = get_class($this);
		$fields = $klass::$fields;

		if ($current_date_fields = array_intersect($klass::$datefields, $fields)) {
			foreach ($current_date_fields as $cdf) {
				$fields[$cdf] = "convert_tz(" . $cdf . ", 'SYSTEM', '" . TIME_ZONE  . "') as $cdf";
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
			}
			//echo number_format(memory_get_usage()) . "(after assigning row to data)\n";
			tep_db_free_result($res);
			unset($res);
			destroy($row);
			destroy($k);
			destroy($v);
			/*
			if ($GLOBALS['USE_MEMCACHED'] == true) {
				$this->populateObject();
			}
			*/
			//tnh::lap("before prepare_translations");
			//$this->prepare_translations();
			//tnh::lap("after prepare_translations");

			return true;

		}
		return false;
	}

	/* use this to clear the memcached cache */
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

	function delete($deleter = 0, $reason = '') {
		$klass = get_class($this);
		global $memcache, $tnh;
		er("in delete");
		if ($this->id) {

			
			if (in_array('deleted',$klass::$fields)) {
				
				$this->deleted = '1';
				$this->deletion_reason = strip_tags($reason);
				$this->deleted_by = $deleter > 0 ? $deleter : $tnh->userObj->id;
				$this->deleted_on = 'now()';
				$this->save();
				
			} else {
				$sql = "delete from " . $this->table() . " where id = " . $this->id;
				$res = tep_db_query($sql);
			}
			if ($GLOBALS['USE_MEMCACHED'] === true) {
				try {
					$memcache->delete($klass . '#' . $this->id);
				} catch (Exception $me) {}
			}
			$this->data = array();
			return true;

		}

		return false;
	}
	
	public static function retrieve_many_for_fk($fk_value_ary, $results_wanted, $offset) {
		$keys = array_keys($fk_value_ary);
		$values = array_values($fk_value_ary);
		
		er("values: " . print_r($values,true));
		$sql = sprintf("select id from %s where %s = '%d'", self::$table, $keys[0], $values[0]);
		er("sql: $sql\n");
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
			$klasss = self::$klass;
			$object = $klasss::load($id);
			$results[] = $object;
		}
		
		return array($results, $rows);	
	}
	
	public static function fetch_where($where_array, $results_wanted = 'all', $offset = 0, $orderby = '', $direction = 'asc', $city_id = null) {
		if (!$city_id) {
			$city_id = $GLOBALS['city_id'];
		}
		$klass = get_called_class();
		$sql = sprintf("select id from %s", $klass::$table);
		// do where clause
		$pairArray = array();
		foreach ($where_array as $field => $value) {
			if (in_array($field, $klass::$fields)) {
				$pairArray[] = "$field = '" . tep_esc($value) . "'";
			}
		}
		$sql .= " WHERE ";
		if ($pairArray) {
			$sql .= explode(' AND ', $pairArray); 
		} else {
			$sql .= "1 = 1";
		}
		if ($city_id && in_array('city_id', $klass::$fields) && is_numeric($city_id)) {
			$sql .= " AND city_id = $city_id";
		}
		if ($orderby && in_array($orderby, $klass::$fields)) {
			$sql .= " ORDER BY $orderby $direction";
		}
		er("sql: $sql\n");
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
			
			$object = $klass::load($id);
			$results[] = $object;
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
			throw new Exception("Could not find class for foreign key $fk_name");
		}
		$obj = $foreign_class::load($this->$fk_name);
		return $obj;
	}
	
	public function update_attributes($args) {
		$klass = get_class($this);
		foreach ($klass::$fields as $f) {
			if ($f != 'id' && in_array($f, array_keys($args))) {
				$this->$f = str_replace("\\r\\n", "\n", tep_esc($args[$f]));
			}
		}
	}
	public function creator() {
		if (!$this->creator) {
			$u = new user(array('id' => $this->created_by));
			$u->retrieve();
			$this->creator = $u;
		}
		return $this->creator;
	}

	public function updater() {
		if (!$this->updater) {
			$u = new user(array('id' => $this->updated_by));
			$u->retrieve();
			$this->updater = $u;
		}
		return $this->updater;
	}
	
	public function modifier() {
		if (!$this->modifier) {
			$this->modifier = user::load($this->modified_by);
		}
		return $this->modifier;
	}
	
	/* crazy workaround for the fact that 'self' does not follow inherited context in PHP < 5.3 */
	// FIXME not needed any more??
	protected function table() {
		$vars = get_class_vars(get_class($this));
		return $vars['table'];
	}




	public function populateObject() {
		return true;
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
        foreach ($this as $index => $value) {
			if (is_object($this->$index)) {
				$this->$index->__destruct();
			}
			unset($this->$index);
		}
    }



	public function validate() {
		er("please install validation for " . get_class($this) . "\n");
		return true;
	}







	public function getData() {
		return $this->data;
	}



  }
?>
