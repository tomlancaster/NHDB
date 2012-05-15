<?php
namespace TNH;
class Admin_Controller extends TNH\Controller {

	public $pageTitle = 'Admin Area';
	public $stylesheets = array('screen', 'plugin', 'custom');
	//public $javascripts_head_first = array('jquery.1.4.2.min.js');
	public $javascripts_head = array('cQueryCache.js','jquery.json-2.2.min.js','jquery.hotkeys-0.7.9.min.js','keyboard-shortcuts.js','jquery.colorbox-min.js','header_functions.js','jquery.hoverIntent.minified.js');
	public $javascripts = array('jquery.periodicalupdater.js','util.js', 'effects.js', 'header.js', 'widgets.js');

	
	public function __construct() {
		global $tnh;
		if (!$tnh->userObj->id) {
			if (isset($_GET['crypted_password']) && isset($_GET['username'])) {
				if ($user = user::cryptlogin($_GET['username'], $_GET['crypted_password'])) {
					$tnh->userObj = $user;
				}
			}
		}
		if (!$tnh->userObj->is_local_anything_admin()) {
			tnh::eredir(sprintf(NOT_PERMITTED,_('to enter this area')),'/');
		}
		$this->setLocation();
	}



	protected function header() {
		global $tnh;
		include(DIR_FS_INCLUDES . 'admin_header.php');
		return true;
	}

	protected function footer() {
		include(DIR_FS_INCLUDES . 'admin_footer.php');
		return true;
	}

	

}

?>