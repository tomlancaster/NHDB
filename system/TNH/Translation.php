<?php
namespace TNH;
class Translation extends TNH\DB {
	protected static $fields = array('id', 'original_table', 'original_column', 'original_id', 'language_code', 
							  'translated_string', 'created_by', 'created_on', 'updated_by', 'updated_on');
	protected static $nullable = array('updated_by', 'updated_on');
	static $table = 'translations';
	static $klass = 'translation';
	protected $translated_fields = array();
	const noCache = true; // only cache translation in parent object

	/**
	 * Get all available translations for a table / column / id combination
	 * @param string $original_table
	 * @param string $original_column
	 * @param integer $original_id
	 * @return Ambigous <multitype:, translation>
	 */
	public static function getTranslations($original_table, $original_column, $original_id) {
		$sql = "select * from " . self::$table . " where original_table = '$original_table' and original_column = '$original_column' and original_id = '$original_id'";
		$res = tep_db_query($sql);
		$results = $rows = array();
		while ($row = tep_db_fetch_array($res)) {
			$rows[] = $row;
		}
		foreach ($rows as $row) {
			$translation = new translation($row);
			$results[] = $translation;
		}
		destroy($row);
		tep_db_free_result($res);
		unset($res);
		return $results;
	}
	
	
	public function has_id() {
		if (isset($this->data['id']) && $this->data['id'] > 0) {
			return true;
		} else {
			return false;
		}
	}


	public function retrieve() {
		$sql = "select id, translated_string from translations where original_table = '{$this->original_table}' and original_column = '{$this->original_column}' and original_id = '{$this->original_id}' and language_code = '{$this->language_code}'";
		$res = tep_db_query($sql);
		//		error_log("called translation::retrieve. original_table: {$this->original_table} lang code: {$this->language_code} original_column: {$this->original_column} original_id: {$this->original_id}\n",3, ERROR_LOG);
		$counter = 0;
		//$rows = array();
		while ($row = tep_db_fetch_array($res)) {
			$this->id = $row['id'];
			if ($this->translated_string == '') {
				
				$this->translated_string = $row['translated_string'];
			}
			$counter++;
			//$rows[] = $row;
		}
		
		tep_db_free_result($res);
		unset($res);
		destroy($row);
		
		if ($counter > 1) {
			//error_log("more than one ($counter) translation for {$this->translated_string}: " . print_r($rows,true) . "\n", 3, ERROR_LOG);
		} else if ($counter == 0) {
			return false;
		}
		return true;
	}

	public function save() {
		try {
		   parent::save();
		} catch (TNHException $e) {
			//error_log("exception on translation save:" . $e->getMessage() . "\n");
    		$temp = clone $this;
		    $this->retrieve(); // get id
		    //error_log("this id: " . $this->id . "\n", 3, ERROR_LOG);
		    foreach ($temp::$fields as $field) {
				if ($field != 'id') {	
					$this->$field = $temp->$field;
				}
		    }
		    destroy($temp);
		    $this->save();
		}
		return true;
	}

	public function validate() {
		return true;
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