<?php
namespace TNH;
class Translation_Collection {
	private $translations = array();
	private $original_table;
	private $original_column;
	private $original_id;

	/**
	 * Constructor. This attempts to find all matching translations and put them in our translations array, 
	 * keyed on their language_code
	 * @param string $original_table
	 * @param string $original_column
	 * @param integer $original_id
	 */
	public function __construct($original_table, $original_column, $original_id) {
		$translations = translation::getTranslations($original_table, $original_column, $original_id);
		$this->original_table = $original_table;
		$this->original_column = $original_column;
		$this->original_id = $original_id;
		foreach ($translations as $translation) {
			//er("constructing t coll for $original_table, $original_column, $original_id");
			$this->translations[$translation->language_code] = $translation;
		}
	}
	
	public function hasTranslations() {
		return sizeof($this->translations);
	}

	/**
	 * Attempt to get the language code specific string.
	 * Note I've punted the logic for determining which alternate translation to
	 * fall back on up into nhdb. Here we just return a string or an empty string.
	 * @param string $language_code
	 * @return string
	 */
	public function get($language_code) {
		if (!$this->translations) {
			return '';
		}
		if (isset($this->translations[$language_code]) && is_object($this->translations[$language_code])) { // retrieved already
			return $this->translations[$language_code]->translated_string;
		} else {
			//error_log("no translation for $language_code. string is: " . print_r($this->translations,true) . "\n", 3, ERROR_LOG);
			return '';
		}
		
	}

	public function set($language_code,$value) {
		//error_log("tc set: lang: $language_code val: $value\n", 3, ERROR_LOG);
		if (!isset($this->translations[$language_code])) {
			//er("no existing translation for $language_code");
			$this->translations[$language_code] = new Translation(array('original_table' => $this->original_table,
																	   'original_column' => $this->original_column,
																	   'original_id' => $this->original_id,
																	   'language_code' => $language_code));
		}
		$this->translations[$language_code]->translated_string = $value;
		//er("tc translations after set: " . print_r($this->translations,true));
		
	}

	public function save($original_id) {
		//er("in translation collection save. sizeof trans: " . sizeof($this->translations));
		foreach ($this->translations as $lang => $tran) {
			//er("saving lang $lang");
			
			$tran->original_id = $original_id;
			$tran->save(false);
		}
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