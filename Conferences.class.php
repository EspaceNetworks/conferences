<?php
// vim: set ai ts=4 sw=4 ft=php:
class Conferences extends \FreePBX_Helpers implements BMO {
	private $module = 'Conferences';
	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new Exception("Not given a FreePBX Object");
		}
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->astman = $this->FreePBX->astman;
	}

	public function doConfigPageInit($page) {
		$request = $_REQUEST;
		isset($request['action'])?$action = $request['action']:$action='';
		isset($request['view'])?$view = $request['view']:$view='form';
		//the extension we are currently displaying

		$account = isset($request['account']) ? $request['account'] : '';
		$extdisplay = isset($request['extdisplay']) && $request['extdisplay'] != '' ? $request['extdisplay'] : $account;

		$orig_account = isset($request['orig_account']) ? $request['orig_account'] : '';
		$music = isset($request['music']) ? $request['music'] : '';
		$users = isset($request['users']) ? $request['users'] : '0';

		//check if the extension is within range for this user
		if ($account != "" && !checkRange($account)){
			echo "<script>javascript:alert('"._("Warning! Extension")." $account "._("is not allowed for your account.")."');</script>";
		} else {

			//if submitting form, update database
			switch ($action) {
				case "add":
					$conflict_url = array();
					$usage_arr = framework_check_extension_usage($account);
					if (!empty($usage_arr)) {
						$conflict_url = framework_display_extension_usage_alert($usage_arr);
					} elseif ($this->addConference($account,$request['name'],$request['userpin'],$request['adminpin'],$request['options'],$request['joinmsg_id'],$music,$users,$request['adminpin']) !== false) {
						needreload();
					}
				break;
				case "delete":
					$this->deleteConference($extdisplay);
					needreload();
				break;
				case "edit":  //just delete and re-add
					//check to see if the room number has changed
					if ($orig_account != '' && $orig_account != $account) {
						$conflict_url = array();
						$usage_arr = framework_check_extension_usage($account);
						if (!empty($usage_arr)) {
							$conflict_url = framework_display_extension_usage_alert($usage_arr);
							break;
						} else {
							$this->deleteConference($orig_account);
							$request['extdisplay'] = $account;//redirect to the new ext
							$old = conferences_getdest($orig_account);
							$new = conferences_getdest($account);
							framework_change_destination($old[0], $new[0]);
						}
					} else {
						$this->deleteConference($account);
					}

					$this->addConference($account,$request['name'],$request['userpin'],$request['adminpin'],$request['options'],$request['joinmsg_id'],$music,$users,$request['language']);
					needreload();
				break;
			}
		}
	}

	public function getRightNav($request) {
		if(isset($request['view']) && $request['view'] == "form") {
			return load_view(__DIR__."/views/rnav.php",array());
		} else {
			return '';
		}
	}

	/**
	 * Search hook for global search
	 * @param  string $query   The query string
	 * @param  array $results Array of results (note: pass-by-ref)
	 */
	public function search($query, &$results) {
		if(!ctype_digit($query)) {
			$sql = "SELECT * FROM meetme WHERE description LIKE ?";
			$sth = $this->db->prepare($sql);
			$sth->execute(array("%".$query."%"));
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
			foreach($rows as $row) {
				$results[] = array("text" => $row['description'] . " (".$row['exten'].")", "type" => "get", "dest" => "?display=conferences&view=form&extdisplay=".$row['exten']);
			}
		} else {
			$sql = "SELECT * FROM meetme WHERE exten LIKE ?";
			$sth = $this->db->prepare($sql);
			$sth->execute(array("%".$query."%"));
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
			foreach($rows as $row) {
				$results[] = array("text" => _("Conference")." ".$row['exten'], "type" => "get", "dest" => "?display=conferences&view=form&extdisplay=".$row['exten']);
			}
		}
	}


	public function install() {

		$table = $this->FreePBX->Database->migrate("meetme");
		$cols = array(
			"exten" => array(
				"type" => "string",
				"length" => 50,
				"primaryKey" => true
			),
			"options" => array(
				"type" => "string",
				"length" => 15,
				"notnull" => false,
			),
			"userpin" => array(
				"type" => "string",
				"length" => 50,
				"notnull" => false,
			),
			"adminpin" => array(
				"type" => "string",
				"length" => 50,
				"notnull" => false,
			),
			"description" => array(
				"type" => "string",
				"length" => 50,
				"notnull" => false,
			),
			"joinmsg_id" => array(
				"type" => "integer",
				"notnull" => false,
			),
			"music" => array(
				"type" => "string",
				"length" => 80,
				"notnull" => false,
			),
			"users" => array(
				"type" => "smallint",
				"unsigned" => false,
				"default" => 0,
				"notnull" => false
			),
			"language" => array(
				"type" => "string",
				"length" => 10,
				"default" => "",
			),
		);
		$table->modify($cols);
		unset($table);

		//Migrate bad option
		$confs = $this->listConferences();
		if (!is_array($confs)) {
			return true;
		}
		$leaderleave = method_exists($this,"getConfig") && $this->getConfig("leaderleave");
		foreach($confs as $conf) {
			$conf = $this->getConference($conf[0]);
			$optselect = strpos($conf['options'], "i");
			if($optselect) {
				$conf['options'] = str_replace("i","I",$conf['options']);
			}
			if(!$leaderleave && strpos($conf['options'], "x") === false) {
				$conf['options'] .= 'x';
			}
			$this->updateConferenceSettingById($conf['exten'],'options',$conf['options']);
		}
		if(method_exists($this,"setConfig")) {
			$this->setConfig("leaderleave",true);
		}
	}
	public function uninstall() {

	}
	public function backup(){

	}
	public function restore($backup){

	}

	/**
	 * Update Conference Dial Plan Options
	 * @param {int} $room  The conference room to update
	 * @param {string} $key   The keyword of the setting to change
	 * @param {string} $value The value of the setting
	 */
	public function updateConferenceOptionById($room,$key,$value) {
		$o = $this->getConference($room);
		$key = explode('#',$key);
		$key = $key[1];
		$options = $o['options'];
		$len = strlen($options);
		if(empty($value) && strpos($options,$key) >= 0) {
			$options = str_replace($key,'',$options);
			if($len-1 != strlen($options)) {
				throw new Exception('Something Bad Happened');
			}
		} elseif(!empty($value) && strpos($options,$key) === false) {
			$options = $options . $key;
			if($len+1 != strlen($options)) {
				throw new Exception('Something Bad Happened');
			}
		}

		$options = count_chars($options, 3);

		$sql = 'UPDATE meetme SET options = ? WHERE exten = ?';
		$sth = $this->db->prepare($sql);
		$sth->execute(array($options,$room));
		$options = !is_null($options) ? $options : "";
		$this->astman->database_put('CONFERENCE/'.$room,'options',$options);
	}

	/**
	 * Update Conference Setting
	 * @param {int} $room  The conference room to update
	 * @param {string} $key   The keyword of the setting to change ("description","userpin","adminpin","options","joinmsg_id","music","users","language")
	 * @param {string} $value The value of the setting
	 */
	public function updateConferenceSettingById($room,$key,$value) {
		$valid = array("description","userpin","adminpin","options","joinmsg_id","music","users","language");
		if(!in_array($key,$valid)) {
			return false;
		}
		$sql = 'UPDATE meetme SET '.$key.' = ? WHERE exten = ?';
		$sth = $this->db->prepare($sql);
		$sth->execute(array($value,$room));
		if($key != 'description' && $key != 'joinmsg_id') {
			$value = !is_null($value) ? $value : "";
			$this->astman->database_put('CONFERENCE/'.$room,$key,$value);
		} elseif($key == 'joinmsg_id') {
			$recording = $this->FreePBX->Recordings->getFilenameById($value);
			$this->astman->database_put('CONFERENCE/'.$room,'joinmsg',(!empty($recording) ? $recording : ''));
		}
	}

	/**
	 * Add Conference Room
	 * @param {int} $room            The room number to create
	 * @param {string} $name            The description of the room
	 * @param {int} $userpin         The user Pin to login to the room
	 * @param {int} $adminpin        The admin pin for the room
	 * @param {string} $options         Options for the room
	 * @param {int} $joinmsg_id		The recording to play on join
	 * @param {string} $music        MOH to play on hold
	 * @param {int} $users
	 */
	public function addConference($room,$name,$userpin,$adminpin,$options,$joinmsg_id = NULL,$music = '',$users = 0,$language='') {
		$sql = "INSERT INTO meetme (exten,description,userpin,adminpin,options,joinmsg_id,music,users,language) values (?,?,?,?,?,?,?,?,?)";
		$sth = $this->db->prepare($sql);
		/* fixup joinmsg_id to be NULL, not an empty string */
		if ($joinmsg_id == '') {
			$joinmsg_id = NULL;
		}
		$sth->execute(array($room,$name,$userpin,$adminpin,$options,$joinmsg_id,$music,$users,$language));
		$language = !is_null($language) ? $language : "";
		$this->astman->database_put('CONFERENCE/'.$room,'language',$language);
		$userpin = !is_null($userpin) ? $userpin : "";
		$this->astman->database_put('CONFERENCE/'.$room,'userpin',$userpin);
		$adminpin = !is_null($adminpin) ? $adminpin : "";
		$this->astman->database_put('CONFERENCE/'.$room,'adminpin',$adminpin);
		$options = !is_null($options) ? $options : "";
		$this->astman->database_put('CONFERENCE/'.$room,'options',$options);
		$music = !is_null($music) ? $music : "";
		$this->astman->database_put('CONFERENCE/'.$room,'music',$music);
		$users = !is_null($users) ? $users : "";
		$this->astman->database_put('CONFERENCE/'.$room,'users',$users);
		$recording = $this->FreePBX->Recordings->getFilenameById($joinmsg_id);
		$this->astman->database_put('CONFERENCE/'.$room,'joinmsg',(!empty($recording) ? $recording : ''));
		return true;
	}

	/**
	 * Delete a Conference
	 * @param {int} $room The room number
	 */
	public function deleteConference($room) {
		$sql = "DELETE FROM meetme WHERE exten = ?";
		$sth = $this->db->prepare($sql);
		try {
			$sth->execute(array($room));
			$this->astman->database_deltree('CONFERENCE/'.$room);
		} catch(\Exception $e) {
			return false;
		}
		return true;
	}

	/**
	 * Gets a All information about a Conference
	 * @param {int} $room The room number
	 *
	 */
	public function getConference($room) {
		$sql = "SELECT exten,options,userpin,adminpin,description,language,joinmsg_id,music,users FROM meetme WHERE exten = ?";
		$sth = $this->db->prepare($sql);
		try {
			$sth->execute(array($room));
			$ret = $sth->fetch(PDO::FETCH_ASSOC);
			$asettings = $this->astman->database_show('CONFERENCE/'.$room);
			$ret = is_array($ret) ? $ret : array();
			foreach($ret as $key => $value) {
				if($key == 'description') {
					continue;
				} elseif($key == 'joinmsg_id') {
					$recording = $this->FreePBX->Recordings->getFilenameById($value);
					$this->astman->database_put('CONFERENCE/'.$room,'joinmsg',(!empty($recording) ? $recording : ''));
					continue;
				}
				if(!isset($asettings['/CONFERENCE/'.$room.'/'.$key])) {
					$value = !is_null($value) ? $value : "";
					$this->astman->database_put('CONFERENCE/'.$room,$key,$value);
				} elseif($asettings['/CONFERENCE/'.$room.'/'.$key] != $value) {
					$this->updateConferenceSettingById($room,$key,$asettings['/CONFERENCE/'.$room.'/'.$key]);
				}
			}
			//Divergent information, sync from the master which is Asterisk Manager
			foreach($asettings as $family => $value) {
				$parts = explode("/",$family);
				$key = $parts[3];
				if((!isset($ret[$key]) || $key == 'description') && $key != 'joinmsg') {
					$this->astman->database_del("CONFERENCE/".$room,$key);
				}
			}
		} catch(\Exception $e) {
			return false;
		}
		return $ret;
	}

	/**
	 * List all active conferences for the current logged in user
	 * @return array Array of conferences
	 */
	public function listConferences() {
		$sql = "SELECT exten,description FROM meetme ORDER BY exten";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$results = $sth->fetchAll(PDO::FETCH_ASSOC);
		foreach($results as $result){
			// check to see if we are in-range for the current AMP User.
			if (isset($result['exten']) && checkRange($result['exten'])){
				// return this item's dialplan destination, and the description
				$extens[] = array($result['exten'],$result['description']);
			}
		}
		if (isset($extens)) {
			return $extens;
		} else {
			return array();
		}
	}
	public function getActionBar($request){
		switch($request['display']){
			case 'conferences':
				$buttons = array(
					'submit' => array(
						'name' => 'submit',
						'id' => 'submit',
						'value' => _('Submit')
					),
					'reset' => array(
						'name' => 'reset',
						'id' => 'reset',
						'value' => _('Reset')
					),
					'delete' => array(
						'name' => 'delete',
						'id' => 'delete',
						'value' => _('Delete')
					)
				);
				break;
		}
		if (empty($request['extdisplay']) && empty($request['account'])) {
			unset($buttons['delete']);
		}
		if ($request['view'] != 'form') {
			unset($buttons);
		}
		return $buttons;
	}

	public function printExtensions(){
		$ret = array();
		$ret['title'] = _("Conferences");
		$featurecodes = \featurecodes_getAllFeaturesDetailed();
		$ret['textdesc'] = _('Conference');
		$ret['numdesc'] = _('Extension');
		$ret['items'] = array();
		foreach ($this->listConferences() as $conf) {
			$ret['items'][] = array($conf[1],$conf[0]);
		}
	return $ret;
	}
}
