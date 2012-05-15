<?php
namespace TNH;
abstract class Image extends TNH\DB {
	private $venue;
	static $user_column = 'created_by';
	static $table = 'images';
	public $srcType = 'spots';
	static $klass = 'image';
	
	public $srcBase = array('all'=>DIR_WS_VENUEIMG_MED,'list'=>DIR_WS_VENUEIMG_MED,'show'=>DIR_WS_VENUEIMG_CROP,'thumb'=>DIR_WS_VENUEIMG_MED_SQUARE);
	protected static $fields = array('id', 'entity_id', 'created_on', 'mimetype', 'caption', 'alt', 'filebytes', 'sizeratio',
							  'created_by', 'priority','include_in_slideshow', 'owner_image', 'is_logo', 'tarid');
	protected static $nullable = array('tarid', 'caption', 'alt', 'priority');
	static $after_create = array('add_activity');
	protected $transformations = array(
									   array('name' => 'Medium Square', 'longside' => IMG_MED_X, 'square' => true, 'destdir' => DIR_IMG_MED_SQUARE),
									   array('name' => 'Large', 'longside' => IMG_LARGE_X, 'square' => false, 'destdir' => DIR_IMG_LARGE),
									   array('name' => 'Crop', 'longside' => IMG_MAX_X, 'square' => false, 'destdir' => DIR_IMG_CROP),
									   array('name' => 'Medium','longside' => IMG_MED_X, 'square' => false, 'destdir' => DIR_IMG_MED),
									   array('name' => 'Small Square','longside' => IMG_SMALL_X, 'square' => true, 'destdir' => DIR_IMG_SMALL_SQUARE),
									   array('name' => 'Small', 'longside' => IMG_SMALL_X, 'square' => false, 'destdir' => DIR_IMG_SMALL),
									   array('name' => 'Medium Small', 'longside' => IMG_MEDSMALL_X, 'square' => false, 'destdir' => DIR_IMG_MEDSMALL),
									   array('name' => 'Icon', 'longside' => IMG_LOGO_X, 'square' => true, 'destdir' => DIR_IMG_LOGO, 'expand' => true));
									   
   /*
    * after_create callbacks
    */
									   
	
	/**
	 * after_create callback to create an associated activity for this image
	 */
	public function add_activity() {
		$klass = get_called_class();
		$act = new activity(array('activity_type_id' => 7, 
									'actor_id' => $this->{$klass::$user_column}, 
									'actor_type' => 'user',
									'activity_time' => $this->created_on, 
									'privacy_level' => 0,
									'object_id' => $this->id,
									'object_type' => $klass,
									'indirect_object_id' => $this->getRelatedThingId(),
									'indirect_object_type' => $this->entity()->type,
									'context' => 'web'));
		
		$act->save();
	}
	
	public function getRelatedThingId() {
		return $this->entity()->subentid;
	}
	
	public function entity() {
		return entity::load($this->entity_id);
	}

	public static function sql_for_where($where,$select='',$orderby='id desc') {
		if ($select !== '') $select = ','.$select;
		return "select id$select from images where $where order by $orderby";
	}

	public static function images_for_venue($entity_id) {
		$sql = self::sql_for_where("entity_id = '{$entity_id}'",'','priority asc, id');
		$res = tep_db_query($sql);
		$results = $rows = array();
		while ($row = tep_db_fetch_array($res)) {
			$rows[] = $row;
		}
		tep_db_free_result($res);
		foreach ($rows as $row) {
			if ($im = image::load($row['id'])) {
				$results[] = $im;
			}
		}
		return $results;
	}
	
	public function internalURL() {
		return 'http://' . tnh::wholeServer($GLOBALS['site_id']) . $this->srcBase['all'] . $this->filename();
	}
	
	public function internalLink($linkText = null) {
		if (!$linkText) {
			$linkText = _('photo');
		}
		return '<a href="' . $this->internalURL() . '">' . $linkText . '</a>';
	}

	public static function images_for_user($user_id, $results_wanted = 'all', $offset = 0, $orderby = 'id desc') {
		$sql = self::sql_for_where("created_by = '{$user_id}'",'',$orderby);
		$res = tep_db_query($sql);
		$results = $rows = array();
		while ($row = tep_db_fetch_array($res)) {
			$row[] = $row;
		}
		tep_db_free_result($res);
		foreach ($rows as $row) {
			if ($im = image::load($row['id'])) {
				$results[] = $im;
			}
		}
		return $results;
	}

	public function numprops($proptype_id) {
		return prop::num_props_for_thing(image::$table,$this->id,$proptype_id,false);
	}

	public function add_prop($proptype_id, $user_id) {
		// check for ownership
		$this->retrieve();
		if ( $this->created_by == intval($user_id) ) {
			return false;
		}
		$added = prop::add_prop(image::$table, $this->id, $proptype_id, $user_id);
		return ($added) ? $this->numprops($proptype_id) : false;
	}

	function add($pic_array = array()) {
		if ( sizeof($pic_array) > 0 ) {
			// we have an image - insert it

			$res = $this->validate($pic_array);
			if ($res !== true) {
				if ($res) {
					return $res;
				} else {
					return false;
				}
			}
			if ( $pic_array['error'] == 'UPLOAD_ERR_OK' ) {
				list($width_orig, $height_orig) = getimagesize($pic_array['tmp_name']);
				$ratio_orig = $width_orig/$height_orig;
				$this->mimetype = $pic_array['type'];
				$this->filebytes = $pic_array['size'];
				$this->sizeratio = $ratio_orig;

				$error = '';
				if (parent::save($pic_array) !== true ) {
					return _("Couldn't write to db");
				}

				foreach ($this->transformations as $transform) {
					if ($transform['name'] == 'Crop' && $width_orig < $transform['longside'] && $height_orig < $transform['longside']) {
						$this->transmogrify($pic_array['tmp_name'], false, max($width_orig, $height_orig), $transform['destdir'], false);
					} else if (!$this->transmogrify($pic_array['tmp_name'], $transform['square'], $transform['longside'], $transform['destdir'], isset($transform['expand']) ? $transform['expand'] : false)) {
						$error[] = 'Couldn\'t perform transformation: ' . $transform['name'];
					}
				}
				if (!$error) {
					return true;
				} else {
					$this->delete();
					return $error;
				}
			} else {
				// image not uploaded
				if ( $pic_array['error'] == 2 ) {
					return _('File too big');
				} else {
					return _("Unknown problem:") .  $pic_array['error'];
				}
				return false;
			}
		} else {
			return _('Image couldn\'t be uploaded: ') . $pic_array['error'];

			return false; // need to bubble up error
		}
	}

	public function rebuild($force = false, $transformationNames = array()) {
		$error = array();
		if (!file_exists(DIR_IMG_CROP . $this->filename())) {
			echo "No Crop File From Which to Rebuild";
		}
		// we have a crop file from which to transform, proceed
		foreach ($this->transformations as $transform) {
			if ($transform['name'] != 'Crop') {
				if (($transformationNames && in_array($transform['name'], $transformationNames)) || !$transformationNames) {
					if (!file_exists($transform['destdir'] . $this->filename()) || $force) {
						echo "missing file (or force): " . $transform['destdir'] . $this->filename() . "\n";
	
						if (!$this->transmogrify(DIR_IMG_CROP . $this->filename(), $transform['square'], $transform['longside'], $transform['destdir'], isset($transform['expand']) ? $transform['expand'] : false)) {
							$error[] = 'Couldn\'t perform transformation: ' . $transform['name'];
						}
					}
				}
			}
		}
		if ($error) {
			return $error;
		} else {
			return true;
		}
	}

	public function transmogrify($img_file, $square = false, $longside, $destdir, $expand = false) {
		if ( $square ) {
			if ( $expand ) {
				return $this->makesquareexpand($img_file, $longside, $destdir);
			} else {
				return $this->makesquare($img_file, $longside, $destdir);
			}
		} else {
			return $this->resize($img_file, $longside, $destdir);
		}
	}

	private function resize($orig_filename, $longside, $destDir ) {
		global $tnh;
		list($width_orig, $height_orig) = getimagesize($orig_filename);
		//echo "image size: $width_orig / $height_orig - $orig_filename - longside: $longside\n";
		$ratio_orig = $width_orig/$height_orig;

		if ($ratio_orig > 1) { // original width is greater than height - landscape
			$newHeight = $longside/$ratio_orig;
			$newWidth = $longside;
		} else { // portrait, or square
			$newWidth = $longside*$ratio_orig;
			$newHeight = $longside;
		}

		// Resample
		$image = ''; $extension = '';
		$image_p = @imagecreatetruecolor($newWidth, $newHeight);
		if ( $this->mimetype == 'image/gif' ) {
			$extension = '.gif';
			if (!$image = @imagecreatefromgif($orig_filename)) {
				return _("couldn't create from temp file gif");
			}
		} else if ($this->mimetype == 'image/jpeg' || $this->mimetype == 'image/pjpeg' || $this->mimetype == 'image/jpg' ) {
			$extension = '.jpg';
			if (!$image = @imagecreatefromjpeg($orig_filename)) {
				return _("couldn't create image from temp file jpeg");
			}
		} else if ($this->mimetype == 'image/png' ) {
			$extension = '.png';
			if (!$image = @imagecreatefrompng($orig_filename)) {
				return _("couldn't create image from temp file png");
			}
		} else {
			return _('Image type not supported. Please upload only Gifs, Jpegs or Pngs');
		}
		
		// preserve transparency
		if($this->mimetype == "image/gif" or $this->mimetype == "image/png"){
			imagecolortransparent($image_p, imagecolorallocatealpha($image_p, 0, 0, 0, 127));
			imagealphablending($image_p, false);
			imagesavealpha($image_p, true);
		}
		imagecopyresampled($image_p, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width_orig, $height_orig);
		// write image
		$name = $destDir . $this->id . $extension;
		switch($extension){
			
			case '.gif': imagegif($image_p, $name); break;
			case '.jpg': imagejpeg($image_p, $name); break;
			case '.png': imagepng($image_p, $name); break;
		}
	
		return true;
	}

	public function extension() {
		if (!$this->mimetype) {
			return false;
		}
		if ( $this->mimetype === 'image/gif' ) {
			return '.gif';
		} else if ($this->mimetype == 'image/jpeg' || $this->mimetype == 'image/pjpeg' || $this->mimetype == 'image/jpg') {
			return '.jpg';
		} else if ($this->mimetype == 'image/png') {
			return '.png';
		} else {
			return false;
		}
	}
	public function filename() {
		return $this->id . $this->extension();
	}
	private function makesquare($orig_filename, $longside, $destDir) {
		global $tnh;
		list($width_orig, $height_orig) = getimagesize($orig_filename);

		$ratio_orig = $width_orig/$height_orig;

		if (1 > $ratio_orig) {
			$src_x = 0;
			$src_y = ($height_orig - $width_orig) / 2;
			$new_height_orig = ($width_orig);
			$new_width_orig = $width_orig;
		} else {
			$src_y = 0;
			$src_x = ($width_orig - $height_orig) / 2;
			$new_width_orig = ($height_orig);
			$new_height_orig = $height_orig;
		}
		// Resample
		$image = ''; $extension = '';

		$image_p = @imagecreatetruecolor($longside, $longside);
		if ( $this->mimetype == 'image/gif' ) {
			$extension = '.gif';
			if (!$image = @imagecreatefromgif($orig_filename)) {
				return _("couldn't create from temp file gif");
			}
		} else if ($this->mimetype == 'image/jpeg' || $this->mimetype == 'image/pjpeg' || $this->mimetype == 'image/jpg' ) {
			$extension = '.jpg';
			if (!$image = @imagecreatefromjpeg($orig_filename)) {
				return _("couldn't create image from temp file jpeg");
			}
		} else if ($this->mimetype == 'image/png' ) {
			$extension = '.png';
			if (!$image = @imagecreatefrompng($orig_filename)) {
				return _("couldn't create image from temp file png");
			}
		} else {
			return _('Image type not supported. Please upload only Gifs, Jpegs or Pngs');
		}
		imagecopyresampled($image_p, $image, 0, 0, $src_x, $src_y, $longside, $longside, $new_width_orig, $new_height_orig);
		// write image
		$name = $destDir . $this->id . $extension;
		if (!imagejpeg($image_p,$name,100)) {
			return _('Couldn\'t write image to filesystem');
		}
		return true;
	}

	private function makesquareexpand($orig_filename, $longside, $destDir) {
		list($width_orig, $height_orig) = getimagesize($orig_filename);

		$ratio_orig = $width_orig/$height_orig;

		if ($ratio_orig > 1) { // original width is greater than height - landscape
			$newHeight = $longside/$ratio_orig;
			$newWidth = $longside;
		} else { // portrait, or square
			$newWidth = $longside*$ratio_orig;
			$newHeight = $longside;
		}

		// Resample
		$image = ''; $extension = '';
		$image_p = @imagecreatetruecolor($longside,$longside);
		$white = imagecolorallocate($image_p,255,255,255);
		imagefill($image_p,0,0,$white);
		if ( $this->mimetype == 'image/gif' ) {
			$extension = '.gif';
			if (!$image = @imagecreatefromgif($orig_filename)) {
				return _("couldn't create from temp file gif");
			}
		} else if ($this->mimetype == 'image/jpeg' || $this->mimetype == 'image/pjpeg' || $this->mimetype == 'image/jpg' ) {
			$extension = '.jpg';
			if (!$image = @imagecreatefromjpeg($orig_filename)) {
				return _("couldn't create image from temp file jpeg");
			}
		} else if ($this->mimetype == 'image/png' ) {
			$extension = '.png';
			if (!$image = @imagecreatefrompng($orig_filename)) {
				return _("couldn't create image from temp file png");
			}
		} else {
			return _('Image type not supported. Please upload only Gifs, Jpegs or Pngs');
		}
		imagecopyresampled($image_p, $image, ($longside-$newWidth)/2, ($longside-$newHeight)/2, 0, 0, $newWidth, $newHeight, $width_orig, $height_orig);
		// write image
		$name = $destDir . $this->id . $extension;
		if (!imagejpeg($image_p,$name,100)) {
			return _('Couldn\'t write image to filesystem');
		}
		return true;
	}


	function delete () {
		global $tnh;
		if ( !$this->id ) {
			return false;
		}
		$this->retrieve();
		$extension = $this->extension();
		foreach ($this->transformations as $t) {
			$file = $t['destdir'] . $this->id . $extension;
			if ( file_exists($file) ) {
				if (!unlink($file)) {
					$error[] = "Couldn't unlink $file";
					return false;
				}
			}
		}
		if ( !tep_db_query("delete from {$this->table} where id = '$this->id'") ) {
			$error[] = "Couldn't delete from db";
			return false;
		}
		return true;
	}


	function venuename() {
		if ( !$this->entity_id ) {
			return false;
		}
		$res = tep_db_query("select venuename from venues where entity_id = '{$this->entity_id}'");
		$r = tep_db_fetch_array($res);
		return $r['venuename'];
	}

	public function thing(){
		return $this->venue();
	}

	public function venue() {
		if (!$this->entity_id) return false;
		if ( !$this->venue ) {
			$sql = "select id from venues where entity_id = {$this->entity_id}";
			$res = tep_db_query($sql);
			$row = tep_db_fetch_array($res);
			tep_db_free_result($res);
			$v = new venue($row);
			if ($v->retrieve()) {
				$this->venue = $v;
			} else {
				return false;
			}
		}
		return $this->venue;
	}
	public static function find_all($conditions = '') {
		$klass = get_called_class();
		foreach ($klass::$fields as $field) {
			$Fields[] = strtolower($field);
		}

		$sql = "select " . implode(',', $Fields) . " from " . $klass::$table;
		if ( $conditions != '' ) {
			$sql .= " where {$conditions}";
		}
		$res = tep_db_query($sql);
		$results = array();
		while ($row = tep_db_fetch_array($res)) {
			$results[] = new $klass($row);
		}
		tep_db_free_result($res);
		return $results;

	}


	public function validate($pic_array = null) {
		$validationError = array();
		if(!tnh::is_legal_characters($this->caption)) {
			$validationError[] = sprintf(ILLEGAL_CHARS,_('caption'));
		}
		if ( isset($this->priority) && !is_numeric($this->priority) ) {
			$validationError[] = sprintf(NUMERIC,_('priority'));
		}
		if ($validationError) {
			return $validationError;
		} else {
			return true;
		}
	}
  }

class nullimage {
	public $id = 'blank';
	public $filename = 'blank.png';
	public $caption = 'no photo available';
	public $sizeratio = 1;

	public function filename() {
		return $this->filename;
	}

}
?>
