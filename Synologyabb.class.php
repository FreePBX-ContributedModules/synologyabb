<?php
/**
 * 
 * License for all code of this FreePBX module can be found in the license file inside the module directory
 * @copyright 2021 Javier Pastor Garcia
 * 
 */
namespace FreePBX\modules;

include __DIR__."/vendor/autoload.php";

class Synologyabb extends \FreePBX_Helpers implements \BMO {
	
	const LOCK_DIR  = "/dev/shm/abbwrapper";
	const LOCK_FILE = "/dev/shm/abbwrapper/run.lock";
	const LOGS_FILE = "/dev/shm/abbwrapper/output.log";
	const INFO_FILE = "/dev/shm/abbwrapper/info.json";

	public static $default_agent_status_data = array(
        'server' => '',
        'user' => '',
        'lastbackup' => '',
        'nextbackup' => '',
        'server_status' => '',
        'portal' => '',
		'html' => '',
		'error' => ''
    );

	const STATUS_NULL			= -1;		// No state has been defined.
	const STATUS_IDLE 			= 110;		// (Idle) No copy has been made yet.
	const STATUS_IDLE_COMPLETED = 120;		// (Idle - Completed)
	const STATUS_IDLE_CANCEL	= 130;		// (Idle - Canceled)
	const STATUS_IDLE_FAILED	= 140;		// (Idel - Failed)
	
	const STATUS_BACKUP_RUN		= 300;		// (Backing up... - 8.31 MB / 9.57 MB (576.00 KB/s))
	const STATUS_NO_CONNECTION 	= 400;		// (No connection found) Not connected to the server

	const STATUS_ERR_DEV_REMOVED = 510; 	// (Error  - The current device has been removed from the server. Please contact your administrator for further assistance.) Equipo eliminado del servidor.

	const STATUS_UNKNOWN 		= 99990;	//99990 - unknown status
	const STATUS_IDLE_UNKNOWN	= 99991;	//99991 - Idel status unknown
	const STATUS_ERR_UNKNOWN	= 99992;	//99992 - error status unknown



	const ERROR_UNKNOWN 	= -2;
	const ERROR_NOT_DEFINED = -1;
	const ERROR_ALL_GOOD 	= 0;

	const ERROR_AGENT_NOT_INSTALLED 		= 501;
	const ERROR_AGENT_NOT_RETURN_INFO 		= 502;
	const ERROR_AGENT_ENDED_IN_ERROR 		= 503;
	const ERROR_AGENT_RETURN_UNCONTROLLED 	= 504;
	const ERROR_AGENT_IS_INSTALLING			= 505;

	const ERROR_AGENT_ALREADY_CONNECTED 	= 520;	// (Already connected)
	const ERROR_AGENT_NOT_ALREADY_CONNECTED = 521;	// (Not Already connected)

	const ERROR_AGENT_SERVER_CHECK 		= 550;

	const ERROR_AGENT_SERVER_AUTH_FAILED 			= 611;
	const ERROR_AGENT_SERVER_AUTH_FAILED_USER_PASS 	= 612;
	const ERROR_AGENT_SERVER_AUTH_FAILED_BAN_IP 	= 613;

	const ERROR_MISSING_ARGS = 650;

	const ERROR_HOOK_FILE_NOT_EXIST = 710;
	const ERROR_HOOK_FILE_EMTRY 	= 715;
	const ERROR_HOOK_FILE_TOEKN 	= 720;
	const ERROR_HOOK_RUN_TIMEOUT	= 725;

	const DEFAULT_PORT = 5510;	// Default port Active Backup for Business Server
	
	const SYNOLOGY_URL_ARCHIVE = 'https://archive.synology.com/download/Utility/ActiveBackupBusinessAgent';

	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->FreePBX 	= $freepbx;
		$this->db 		= $freepbx->Database;
		$this->config 	= $freepbx->Config;
		$this->logger 	= $freepbx->Logger()->getDriver('freepbx');
		
		$this->module_name 	=  join('', array_slice(explode('\\', get_class()), -1));
		
		$this->astspooldir 	= $this->config->get("ASTSPOOLDIR");
		$this->asttmpdir 	= $this->getAstSpoolDir() . "/tmp";

		$this->ABBCliVersionMin = "2.2.0-2070"; // Minimum version supported
	}

	public function chownFreepbx() {
		$files = array(
			array(
				'type' => 'execdir',
				'path' => __DIR__."/hooks",
				'perms' => 0755
			),
		);
		return $files;
	}

	public function getAstSpoolDir() {
		return $this->astspooldir; 
	}

	public function getAstTmpDir() {
		return $this->asttmpdir;
	}

	public function getABBCliPath() {
		return $this->config->get('SYNOLOGYABFBABBCLI');
	}

	public function getHookFilename($hookname, $hooktoken) {
		$return = $this->getAstTmpDir() . "/synology-cli";
		if (! empty($hookname))
		{
			$return .= "-" . $hookname;
		}
		if (! empty($hooktoken))
		{
			$return .= "-" . $hooktoken;
		}
		$return .= ".hook";
		return $return;
	}

	public function runHook($hookname, $params = false) {
		// Runs a new style Syadmin hook
		if (!file_exists("/etc/incron.d/sysadmin")) {
			throw new \Exception("Sysadmin RPM not up to date, or not a known OS.");
		}

		$basedir = $this->getAstSpoolDir()."/incron";
		if (!is_dir($basedir)) {
			throw new \Exception("$basedir is not a directory");
		}
		
		// Does our hook actually exist?
		if (!file_exists(__DIR__."/hooks/$hookname")) {
			throw new \Exception("Hook $hookname doesn't exist");
		}
		// So this is the hook I want to run
		
		$filename = sprintf("%s/%s.%s", "$basedir", strtolower($this->module_name), $hookname);
		if (file_exists($filename)) {
			throw new \Exception("Hook $hookname is already running");
		}

		// If we have a modern sysadmin_rpm, we can put the params
		// INSIDE the hook file, rather than as part of the filename
		if (file_exists("/etc/sysadmin_contents_max")) {
			$fh = fopen("/etc/sysadmin_contents_max", "r");
			if ($fh) {
				$max = (int) fgets($fh);
				fclose($fh);
			}
		} else {
			$max = false;
		}

		if ($max > 65535 || $max < 128) {
			$max = false;
		}

		// Do I have any params?
		$contents = "";
		if ($params) {
			// Oh. I do. If it's an array, json encode and base64
			if (is_array($params)) {
				$b = base64_encode(gzcompress(json_encode($params)));
				// Note we derp the base64, changing / to _, because this may be used as a filepath.
				if ($max) {
					if (strlen($b) > $max) {
						throw new \Exception("Contents too big for current sysadmin-rpm. This is possibly a bug!");
					}
					$contents = $b;
					$filename .= ".CONTENTS";
				} else {
					$filename .= ".".str_replace('/', '_', $b);
					if (strlen($filename) > 200) {
						throw new \Exception("Too much data, and old sysadmin rpm. Please run 'yum update'");
					}
				}
			} elseif (is_object($params)) {
				throw new \Exception("Can't pass objects to hooks");
			} else {
				// Cast it to a string if it's anything else, and then make sure
				// it doesn't have any spaces.
				$filename .= ".".preg_replace("/[[:blank:]]+/", "", (string) $params);
			}
		}

		$fh = fopen($filename, "w+");
		if ($fh === false) {
			// WTF, unable to create file?
			throw new \Exception("Unable to create hook trigger '$filename'");
		}

		// Put our contents there, if there are any.
		fwrite($fh, $contents);

		// As soon as we close it, incron does its thing.
		fclose($fh);

		// Wait for up to 10 seconds and make sure it's been deleted.
		$maxloops = 20;
		$deleted = false;
		while ($maxloops--) {
			if (!file_exists($filename)) {
				$deleted = true;
				break;
			}
			usleep(500000);
		}

		if (!$deleted) {
			throw new \Exception("Hook file '$filename' was not picked up by Incron after 10 seconds. Is it not running?");
		}
		return true;
	}

	private function runHookCheck($hook_file, $hook_run, $hook_params = array(), $decode = true, $timeout = 30) {
		$error_code = self::ERROR_NOT_DEFINED;
		$hook_info 	= null;
		$hook_token = uniqid('hook');
		$file 		= $this->getHookFilename($hook_file, $hook_token);

		$hook_params['hook_file'] 	= $hook_file;
		$hook_params['hook_token'] 	= $hook_token;

		$this->runHook($hook_run, $hook_params);

		$hookTimeOut = true;
		if ($timeout == null || $timeout < 0)
		{
			// We wait 10 seconds to see if the file with the data is created and if the status change to RUN or END
			$maxloops 		 = 10 * 4; 
			$sleeploop 		 = 250000;
			$status_continue = array("RUN", "END");
		}
		else
		{
			// We wait for the number of seconds (default 30) that we specify to see if the file with the data is created and if the status changes to END
			$maxloops 		 = $timeout * 4;
			$sleeploop 		 = 250000;
			$status_continue = array("END");
		}
		while ($maxloops--)
		{
			if (file_exists($file))
			{
				$decode_info = $this->readFileHook($file, true);
				if ( !empty($decode_info) && !empty($decode_info['hook']))
				{
					$info_status = isset($decode_info['hook']['status']) ? $decode_info['hook']['status'] : '';
					$info_hook 	 = isset($decode_info['hook']['token'])  ? $decode_info['hook']['token'] : '';
					if ( $hook_token == $info_hook && in_array($info_status, $status_continue))
					{
						$hookTimeOut = false;
						break;
					}
				}
			}
			usleep($sleeploop);
		}

		if ($hookTimeOut)
		{
			$error_code = self::ERROR_HOOK_RUN_TIMEOUT;
		}
		else
		{
			if(! file_exists($file))
			{
				$error_code = self::ERROR_HOOK_FILE_NOT_EXIST;
			}
			else
			{
				$linesfilehook = file_get_contents($file);
				unlink($file);
	
				if (trim($linesfilehook) == false)
				{
					$error_code = self::ERROR_HOOK_FILE_EMTRY;
				}
				else
				{
					$hook_info = @json_decode($linesfilehook, true);
					if ($hook_token != $hook_info['hook']['token'])
					{
						$error_code = self::ERROR_HOOK_FILE_TOEKN;
					}
					else
					{
						$error_code = ($hook_info['error']['code'] === self::ERROR_ALL_GOOD ? self::ERROR_ALL_GOOD : $hook_info['error']['code']);
						if (! $decode)
						{
							$hook_info = $linesfilehook;
						}
					}
				}
			}
		}

		return array(
			'hook_file' 	=> $hook_file,
			'hook_run' 		=> $hook_run,
			'hook_token'	=> $hook_token,
			'hook_data'		=> $hook_info,
			'hook_timeout' 	=> $timeout,
			'file' 			=> $file,
			'decode'		=> $decode,
			'error' 		=> $error_code,
		);
	}

	public function writeFileHook($file, $data, $encode = true) {
		if (trim($file) == false) {
			return false;
		}
		file_put_contents($file, ($encode == true ? json_encode($data) : $data));
		chown($file, 'asterisk');
		return true;
	}

	public function readFileHook($file, $decode = true) {
		$return = "";
		if (trim($file) != false)
		{
			$return = file_get_contents($file);
			if ($decode == true)
			{
				$return = @json_decode($return, true);
			}
		}
		return $return;
	}

	public function install() {
		outn(_("Upgrading configs.."));
		$set = array();
		$set['value'] = '/usr/bin/abb-cli';
		$set['defaultval'] =& $set['value'];
		$set['readonly'] = 0;
		$set['hidden'] = 0;
		$set['level'] = 0;
		$set['module'] = $this->module_name; //Fix needed FREEPBX-22756
		$set['category'] = 'Synology Active Backup for Business';
		$set['emptyok'] = 1;
		$set['name'] = _('Path for abb-cli');
		$set['description'] = _('The default path to abb-cli. overwrite as needed.');
		$set['type'] = CONF_TYPE_TEXT;
		$this->config->define_conf_setting('SYNOLOGYABFBABBCLI', $set, true);
		out(_("Done!"));
	}
	public function uninstall() {}
	
	public function backup() {}
	public function restore($backup) {}
	
	
	public function doConfigPageInit($page) {}
	
	public function getActionBar($request) {}
	
	public function getRightNav($request) {}

	public function dashboardService() {
		$status = array(
			'title' => _('Synology Active Backup'),
			'order' => 3,
		);
		$data = $this->getAgentStatus(true, false, false);

		if ($data['error']['code'] === self::ERROR_ALL_GOOD)
		{
			$status_code = $data['info_status']['code'];
			$status_msg  = $data['info_status']['msg'];
		}
		else
		{
			$status_code = $data['error']['code'];
			$status_msg  = $data['error']['msg'];
		}

		$AlertGlyphIcon = null;
		switch($status_code)
		{
			case self::STATUS_IDLE_COMPLETED:
				$AlertGlyphIcon = $this->genStatusIcon('completed', $status_msg);
				break;
			case self::STATUS_BACKUP_RUN:
				$AlertGlyphIcon = $this->genStatusIcon('run', sprintf("%s - %s", $status_msg, $data['info_status']['progress']['all']));
				break;
			case self::STATUS_IDLE:
			case self::STATUS_IDLE_CANCEL:
			case self::STATUS_IDLE_FAILED:
				$AlertGlyphIcon = $this->genStatusIcon('warning', $status_msg);
				break;
			default:
				$AlertGlyphIcon = $this->genStatusIcon('error', $status_msg);
				break;
		}

		$status = array_merge($status, $AlertGlyphIcon);
		return array($status);
	}

	private function genStatusIcon($type, $msg)
	{
		$list_types  = array(
			'completed' => array(
				'type' 	=> 'ok',
				'class' => "glyphicon-floppy-saved text-success",
			),
			'run' => array(
				'type' 	=> 'info',
				'class' => "glyphicon-export text-info",	// glyphicon-floppy-open
			),
		);
		
		$data_return = array();
		if (! array_key_exists($type, $list_types))
		{
			$data_return = $this->Dashboard()->genStatusIcon($type, $msg);
		}
		else
		{
			$data_return = array(
				'type' 		  => empty($list_types[$type]['type']) ? $type : $list_types[$type]['type'],
				"tooltip" 	  => htmlentities(\ForceUTF8\Encoding::fixUTF8($msg), ENT_QUOTES,"UTF-8"),
				"glyph-class" => $list_types[$type]['class'],
			);
		}
		return $data_return;
	}


	public function showPage($page, $params = array())
	{
		$page = trim($page);
		$page_show = '';
		$data = array(
			"syno" 		=> $this,
			'request'	=> $_REQUEST,
			'page'		=> $page,
		);
		$data = array_merge($data, $params);
		
		switch ($page)
		{
			case "":
				$page_show = 'main';
				break;

			default:
				$page_show = $page;
		}

		if (! empty($page_show))
		{
			//clean up possible things that don't have to be here
			$filename = strtolower(str_ireplace(array('..','\\','/'), "", $page_show));

			$page_path = sprintf("%s/views/%s.php", __DIR__, $filename);
			if (! file_exists($page_path))
			{
				$page_show = '';
			}
			else
			{
				$data_return = load_view($page_path, $data);
			}
		}
		
		if (empty($page_show))
		{
			$data_return = sprintf(_("Page Not Found (%s)!!!!"), $page);
		}
		return $data_return;
	}

	public function ajaxRequest($req, &$setting) {
		// ** Allow remote consultation with Postman **
		// ********************************************
		// $setting['authenticate'] = false;
		// $setting['allowremote'] = true;
		// return true;
		// ********************************************
		switch($req)
		{
			case "getagentversion":
			case "getagentversiononline":
			case "getagentstatus":
			case "setagentcreateconnection":
			case "setagentreconnect":
			case "setagentlogout":
			case "runautoinstall":
			case "runautoinstallstatus":
				return true;
				break;

			default:
				return false;
		}
		return false;
	}

	public function ajaxHandler() {
		$command = $this->getReq("command", "");
		$data_return = false;
		switch ($command)
		{
			case 'runautoinstall':
				$data_return = array("status" => true, "data" => $this->runAutoInstallAgent());
				break;

			case 'runautoinstallstatus':
				$data_return = array("status" => true, "data" => $this->runAutoInstallAgent(true));
				break;

			case 'getagentversiononline':
				$data_return = array("status" => true, "data" => $this->getAgentVersionOnline(false));
				break;

			case 'getagentversion':
				$data_return = array("status" => true, "data" => $this->getAgentVersion(true, false));
				break;

			case 'getagentstatus':
				$status_info = $this->getAgentStatus();
				$status_info['agent_version'] = $this->getAgentVersion(true, false);

				$data_return = array("status" => true, "data" => $status_info);
				break;
			case 'setagentcreateconnection':
				$agent_server 	= $this->getReq("ABBServer", "");
				$agent_username = $this->getReq("ABBUser", "");
				$agent_password = $this->getReq("ABBPassword", "");
				$return_status = $this->setAgentConnection($agent_server, $agent_username, $agent_password);

				$data_return = array("status" => true, "data" => $return_status);
				break;

			case 'setagentreconnect':
				$data_return = array("status" => true, "data" => $this->setAgentReConnect());
				break;

			case 'setagentlogout':
				$agent_username = $this->getReq("ABBUser", "");
				$agent_password = $this->getReq("ABBPassword", "");

				$data_return = array("status" => true, "data" => $this->setAgentLogOut($agent_username, $agent_password));
				break;

			default:
				$data_return = array("status" => false, "message" => _("Command not found!"), "command" => $command);
		}
		return $data_return;
	}
	
	private function parseUnitConvert($data)
	{
		return ((int) filter_var($data, FILTER_SANITIZE_NUMBER_INT) == 0 ? 0 : $data);
	}

	public function isOSCompatibilityAutoInstall()
	{
		// return true; //Testing
		// System Updates are only usable on FreePBX Distro style machines
		$su = new \FreePBX\Builtin\SystemUpdates();
		return $su->canDoSystemUpdates() ? true : false;
	}

	public function isAgentInstalled() {
		return file_exists($this->getABBCliPath());
	}

	public function isAgentVersionOk() {
		$version_minimal = $this->ABBCliVersionMin;
		$version_installed = $this->getAgentVersion(true);
		return version_compare($version_minimal, $version_installed['full'], '<=');
	}

	public function getAgentStatusDefault() {
		return self::$default_agent_status_data;
	}

	public function getAgentStatus($return_error = true, $force = false, $gen_html = true)
	{
		if ($force == true)	// We force to refresh the status data
		{
			$this->setAgentReConnect();
			usleep(500000);
		}

		$hook 		= $this->runHookCheck("status", "get-cli-status");
		$error_code = $hook['error'];
		$return 	= $this->getAgentStatusDefault();
		$t_html 	= array(
			'force' => false,
			'body'  => "",
			'args'  => array(),
		);
		$error_code_array = null;
		$status_code 	  = self::STATUS_NULL;

		if ($error_code === self::ERROR_ALL_GOOD)
		{
			$hook_data  = $hook['hook_data']['data'];
			$t_info = array();

			$hook_data['lastbackup_date'] = \DateTime::createFromFormat('Y-m-d H:i', $hook_data['lastbackup']);
			$hook_data['nextbackup_date'] = \DateTime::createFromFormat('Y-m-d H:i', $hook_data['nextbackup']);

			$t_status_info = preg_split('/[-]+/', $hook_data['server_status']);
			$t_status_info = array_map('trim', $t_status_info);	//Trim All Elements Array

			$t_status_info_type = trim($t_status_info[0], chr(194) . chr(160));
			$t_status_info_msg 	= @$t_status_info[1];

			switch (strtolower($t_status_info_type))
			{
				case strtolower("Idle"):
					if ($t_status_info_msg == "")
					{
						//MSG: Idle
						$status_code = self::STATUS_IDLE;	
					}
					else
					{
						//set generic unknown error
						$status_code = self::STATUS_IDLE_UNKNOWN;

						$t_list_idle = array(
							//MSG: Idle - Completed
							self::STATUS_IDLE_COMPLETED => strtolower("Completed"),
	
							//MSG: Idle - Canceled
							self::STATUS_IDLE_CANCEL => strtolower("Canceled"),

							//MSG: Idle - Failed
							self::STATUS_IDLE_FAILED => strtolower("Failed"),
						);

						//We check if it is any of the errors that we have controlled
						foreach ($t_list_idle as $key => $val)
						{
							if ( strpos(strtolower($t_status_info_msg), $val) !== false )
							{
								$status_code = $key;
								break;
							}
						}
						unset($t_list_idle);
					}
					break;

				case strtolower("Error"):
					$t_list_errors = array(
                        //MSG: Error  - The current device has been removed from the server. Please contact your administrator for further assistance.
                        self::STATUS_ERR_DEV_REMOVED => strtolower("The current device has been removed from the server"),
                    );

                    //set generic unknown error
                    $status_code = self::STATUS_ERR_UNKNOWN;
                    
                    //We check if it is any of the errors that we have controlled
                    foreach ($t_list_errors as $key => $val)
                    {
                        if ( strpos(strtolower($t_status_info_msg), $val) !== false )
                        {
                            $status_code = $key;
                            break;
                        }
                    }
					unset($t_list_errors);
					break;

				case strtolower("Backing up..."):		// Backing up... - 8.31 MB / 9.57 MB (576.00 KB/s)
					$status_code =  self::STATUS_BACKUP_RUN;

					$t_status_info['progress'] = preg_split('/[\(\)]+/', $t_status_info_msg);
					$t_status_info['progress'] = array_map('trim', $t_status_info['progress']);

					$t_status_info['progressdata'] = preg_split('/[\/]+/', $t_status_info['progress'][0]);
					$t_status_info['progressdata'] = array_map('trim', $t_status_info['progressdata']);

					$t_status_info['dataparsed'] = array(
						'send' 		 => \ByteUnits\parse($this->parseUnitConvert($t_status_info['progressdata'][0]))->numberOfBytes(),
						'total' 	 => \ByteUnits\parse($this->parseUnitConvert($t_status_info['progressdata'][1]))->numberOfBytes(),
						'percentage' => 0,
					);
					if ($t_status_info['dataparsed']['total'] != 0)
					{
						$t_status_info['dataparsed']['percentage'] = round((100 / $t_status_info['dataparsed']['total']) * $t_status_info['dataparsed']['send']);		
					}
					$t_info['progress'] = array(
						'all' 	=> $t_status_info_msg,
						'send' 	=> $t_status_info['dataparsed']['send'],
						'total' => $t_status_info['dataparsed']['total'],
						'speed' => $t_status_info['progress'][1],
						'percentage' => $t_status_info['dataparsed']['percentage'],
					);
					break;

				case strtolower("No connection found"):
					$status_code =  self::STATUS_NO_CONNECTION;
					break;

				default:
					$status_code = self::STATUS_UNKNOWN;
					break;
			}

			if (! is_array($status_code))
			{
				$status_code = $this->getStatusMsgByCode($status_code, true);
			}
			$hook_data['info_status'] = array_merge($status_code, $t_info);
			if ($status_code['code'] >= self::STATUS_UNKNOWN )
			{
				$this->logger->warning( sprintf("%s->%s - Code (%s): Status not controlled [%s]!", $this->module_name, __FUNCTION__, $status_code['code'], $hook_data['server_status']));	
			}

			switch (strtolower($t_status_info_type))
			{
				case strtolower("Backing up..."):
					$t_html['force'] = true;
				case strtolower("Error"):
				case strtolower("Idle"):
					$t_html['body']  = "main.body.info";
					$t_html['args']  = array(
						'info' 		  => $hook_data,
						'status' 	  => $status_code,
						'status_type' => strtolower($t_status_info_type)
					);
					break;

				case strtolower("No connection found"):
					$t_html['body'] = "main.body.login";
					break;

				default:
					$t_html['body'] = "main.body.error";
			}

			$return = $hook_data;
		}
		elseif ($error_code === self::ERROR_AGENT_NOT_INSTALLED || $error_code === self::ERROR_AGENT_IS_INSTALLING)
		{
			$t_html['body'] = "main.steps.install";
			$t_html['args'] = array(
				'runing_installation' => $error_code === self::ERROR_AGENT_IS_INSTALLING ? true : false,
				'allow_auto_install'  => $this->isOSCompatibilityAutoInstall(),
			);
		}
		else
		{
			$t_html['body'] = "main.body.error";
		}

		$error_code_array = $this->getErrorMsgByErrorCode($error_code, true);
		if ($gen_html)
		{
			if (! empty($t_html['body']))
			{
				$t_html['args_default'] = array(
					'error_code'  => $error_code,
					'error_info'  => $error_code_array,
					'status_info' => $status_code,
				);
				$return['html'] = array(
					'force' => $t_html['force'],
					'body' 	=> $this->showPage($t_html['body'], array_merge($t_html['args_default'], $t_html['args'])),
				);
			}
		}
		if ($return_error)
		{
			$return['error'] = $error_code_array;
		}

		return $return;
	}

	public function getAgentVersion($return_array = false, $return_error = true)
	{	
		$hook 		= $this->runHookCheck("version", "get-cli-version");
		$error_code = $hook['error'];
		$return 	= "0.0.0-0";

		if ($error_code === self::ERROR_ALL_GOOD)
		{
			$hook_data  = $hook['hook_data']['data'];
			$app_ver 	= $hook_data['version'];
			if (! empty($app_ver))
			{
				$return = $app_ver;
			}
		}

		if ($return_array)
		{
			$app_ver_array = explode(".", str_replace("-", ".",  $return));
			$return = array(
				'major' => $app_ver_array[0],
				'minor' => $app_ver_array[1],
				'patch' => $app_ver_array[2],
				'build' => $app_ver_array[3],
				'full' => $return,
			);
		}

		if ($return_array && $return_error)
		{
			$return['error'] = $this->getErrorMsgByErrorCode($error_code, true);
		}
		return $return;
	}


	public function setAgentConnection($server, $user, $pass)
	{
		$return 	 = array();
		$hook_params = array(
			"server" 	=> $server,
			"username" 	=> $user,
			"password" 	=> $pass,
		);
		$hook_params = array_map('trim', $hook_params);
		$hook 		 = $this->runHookCheck("createconnection", "set-cli-create-connection", $hook_params);
		$error_code  = $hook['error'];

		$return['error'] = $this->getErrorMsgByErrorCode($error_code, true);
		return $return;
	}

	public function setAgentReConnect()
	{
		$return = array();
		$hook 	= $this->runHookCheck("reconnect", "set-cli-reconnect");

		$return['error'] = $this->getErrorMsgByErrorCode($hook['error'], true);
		return $return;
	}

	public function runAutoInstallAgent($readonly = false)
	{
		$return = array();
		$hook_params = array(
			"readonly" 	=> $readonly,
		);
		$hook 		= $this->runHookCheck("autoinstall", "run-install-agent", $hook_params, true, "-1");
		$error_code = $hook['error'];

		if ($error_code === self::ERROR_ALL_GOOD)
		{
			$return['info'] = $hook['hook_data']['data'];
		}
		else
		{
			$return['info'] = $this->AutoInstallReadInfo();
			$return['info']['out'] = $this->AutoInstallReadOut();
		}
		$return['error'] = $this->getErrorMsgByErrorCode($error_code, true);
		return $return;
	}

	public function setAgentLogOut($user, $pass)
	{
		$return 	 = array();
		$hook_params = array(
			"username" 	=> $user,
			"password" 	=> $pass,
		);
		$hook_params = array_map('trim', $hook_params);

		$hook 		= $this->runHookCheck("logout", "set-cli-logout", $hook_params);
		$error_code = $hook['error'];

		$return['error'] = $this->getErrorMsgByErrorCode($error_code, true);
		return $return;
	}


	public function getStatusMsgByCode($status_code, $return_array = false)
	{
		$msg = "";
		switch($status_code)
		{
			case self::STATUS_BACKUP_RUN:
				$msg = _("Backup in Progress...");
				break;

			case self::STATUS_IDLE_CANCEL:
				$msg = _("Canceled");
				break;

			case self::STATUS_IDLE_COMPLETED:
				$msg = _("Completed");
				break;

			case self::STATUS_IDLE:
				$msg = _("Pending First Copy");
				break;
			
			case self::STATUS_IDLE_FAILED:
				$msg = _("Failed");
				break;

			case self::STATUS_NO_CONNECTION:
				$msg = _("No Connection");
				break;

			case self::STATUS_ERR_DEV_REMOVED:
				$msg = _("Device Removed From Server");
				break;

			case self::STATUS_UNKNOWN:
			case self::STATUS_IDLE_UNKNOWN:
			case self::STATUS_ERR_UNKNOWN:
				$msg = _("Status Unknown");
				break;

			default:
				$msg = sprintf(_("The status code (%s) is not controlled!"), $status_code);
		}

		return ($return_array ? array( 'code' => $status_code, 'msg' => $msg ) : $msg);
	}

	public function getErrorMsgByErrorCode($error_code, $return_array = false, $msg_alternative = null)
	{
		$msg = "";
		switch($error_code)
		{
			case self::ERROR_NOT_DEFINED: //-1
				$msg = _("Nothing has been defined yet.");
				break;

			case self::ERROR_ALL_GOOD: //0
				$msg = _("No mistake, everything ok.");
				break;

			case self::ERROR_AGENT_NOT_INSTALLED: //501
				$msg = _("Synology Agent not Installed!");
				break;

			case self::ERROR_AGENT_NOT_RETURN_INFO: //502
				$msg = _("Synology Agent not return info!");
				break;

			case self::ERROR_AGENT_ENDED_IN_ERROR: //503
				$msg = _("Synology Agent ended in error!");
				break;

			case self::ERROR_AGENT_RETURN_UNCONTROLLED: //504
				$msg = _("Synology Agent returned uncontrolled information!");
				break;
			
			case self::ERROR_AGENT_IS_INSTALLING: //505
				$msg = _("Synology Agent Is Installing...");
				break;

			case self::ERROR_AGENT_SERVER_CHECK: //5501
				$msg = _("The server is not available!");
				break;

			case self::ERROR_AGENT_ALREADY_CONNECTED: //520
				$msg = _("Synology Agent Already connected!");
				break;
			
			case self::ERROR_AGENT_NOT_ALREADY_CONNECTED: //521
				$msg = _("Synology Agent Not Already Connected!");
				break;

			case self::ERROR_AGENT_SERVER_AUTH_FAILED: //511
				$msg = _("The server returned an authentication failed error!");
				break;


			case self::ERROR_AGENT_SERVER_AUTH_FAILED_USER_PASS: //512
				$msg = _("The username or password you entered is incorrect!");
				break;

			case self::ERROR_AGENT_SERVER_AUTH_FAILED_BAN_IP: //513
				$msg = _("This IP address has been blocked because it has reached the maximum number of failed login attempts allowed within a specific time period!");
				break;

			case self::ERROR_HOOK_FILE_NOT_EXIST: //610
				$msg = _("The file that returns the hook information does not exist!");
				break;

			case self::ERROR_HOOK_FILE_EMTRY: //615
				$msg = _("Hook file is empty!");
				break;

			case self::ERROR_HOOK_FILE_TOEKN: //620
				$msg = _("Hook token is invalid!");
				break;
			
			case self::ERROR_HOOK_RUN_TIMEOUT:
				$msg = _("Hook run exceeded tiemout!");
				break;

			case self::ERROR_MISSING_ARGS:
				$msg = _("Missing Arguments!");
				break;

			case self::ERROR_UNKNOWN: //-2
			default:
				$msg =  sprintf(_("Unknown error (%s)!"), $error_code);
				$error_code = self::ERROR_UNKNOWN;
				break;
		}
		if (! is_null($msg_alternative)) { $msg = $msg_alternative; }

		return ($return_array ? array( 'code' => $error_code, 'msg' => $msg ) : $msg);
	}

	
	public function checkServer($host, $port = null, $wait = 1) 
	{
		if ( is_null($port) ) { $port = self::DEFAULT_PORT; }
    	$fp = @fsockopen($host, $port, $errCode, $errStr, $wait);
		if ($fp)
		{
			fclose($fp);
			$return_date = true;
		}
		else
		{
			$return_date = array('code' => $errCode, 'msg' => $errStr);
		}
		return $return_date;
	}


	public function getAgentVersionOnline($order_ascending = false, $only_last_version = false)
	{
		$url = self::SYNOLOGY_URL_ARCHIVE;

		$regexp = '#<a href="/download/Utility/ActiveBackupBusinessAgent/(.+?)" rel="noreferrer noopener">#s';
		// <a href="/download/Utility/ActiveBackupBusinessAgent/2.4.1-2321" rel="noreferrer noopener">2.4.1-2321</a>
 
		$html = $this->get_url_contents($url);
		preg_match_all($regexp, $html, $match);
		$match = $match[1];

		$ver = array();
		foreach ($match as $k => $v)
		{
			$url = sprintf('https://global.download.synology.com/download/Utility/ActiveBackupBusinessAgent/%1$s/Linux/x86_64/Synology Active Backup for Business Agent-%1$s-x64-rpm.zip', $v);
			$ver[$v] = $url;
		}

		if ($only_last_version)
		{
			krsort($ver);
			$return = empty($ver) ? array() : array_slice($ver, 0, 1);;
		}
		else
		{
			if ($order_ascending) { ksort($ver); }
			else 				  { krsort($ver); }
			$return = $ver;
		}
		return $return;
	}

	private function get_url_contents($url)
	{
		$crl = curl_init();
		
		//				   Mozilla/5.0 (Linux x86_64; rv:103.0) Gecko/20100101 Firefox/103.0
		//				   Mozilla/5.0 (X11; Fedora; Linux x86_64; rv:103.0) Gecko/20100101 Firefox/103.0
		//                 Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.6) Gecko/20100625 Firefox/3.6.6 ( .NET CLR 3.5.30729)
		$curl_useragent = "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1";
		$curl_timeout = '3.5';
		
		curl_setopt($crl, CURLOPT_USERAGENT, $curl_useragent);
		curl_setopt($crl, CURLOPT_URL, $url);
		curl_setopt($crl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($crl, CURLOPT_CONNECTTIMEOUT, $curl_timeout);
		curl_setopt($crl, CURLOPT_FAILONERROR, true);
		curl_setopt($crl, CURLOPT_TIMEOUT, $curl_timeout);
		curl_setopt($crl, CURLOPT_FOLLOWLOCATION, true);
	
		$ret = trim(curl_exec($crl));
		if (curl_error($crl))
		{
			$error_curl_msg = sprintf(_("Error Curl: %s"), curl_error($crl));
			$this->AutoInstallWriteOut($error_curl_msg);
			// dbug($error_curl_msg);
		}
	
		//if debug is turned on, return the error number if the page fails.
		if ($ret === false) {
			$ret = '';
		}
		//something in curl is causing a return of "1" if the page being called is valid, but completely empty.
		//to get rid of this, I'm doing a nasty hack of just killing results of "1".
		if ($ret == '1') {
			$ret = '';
		}
		curl_close($crl);
		
		return $ret;
	}

	
	public function AutoInstallSaveInfo($data)
    {
		$json_file = self::INFO_FILE;
        $json_data = json_encode($data);
        $return_data = @file_put_contents($json_file, $json_data);
        if (! $return_data)
		{
			$errMsgArr = error_get_last();
			$errMsg = sprintf(_("Error in process SaveInfo. Error Type [%s] in file [%s:%s], message: %s"), $errMsgArr['type'], $errMsgArr['file'], $errMsgArr['line'], $errMsgArr['message']);
			$this->AutoInstallWriteOut($errMsg);
            // dbug($errMsg);
        }
        return $return_data;
    }

    public function AutoInstallReadInfo()
    {
		$data_return = array();
		$json_file = self::INFO_FILE;
		if (file_exists($json_file))
		{
			$data_return = json_decode(file_get_contents($json_file), true);
		}
		return $data_return;
    }

	public function AutoInstallDelInfo()
	{
		$json_file = self::INFO_FILE;
		$data_retur = true;	
		if (file_exists($json_file))
		{
			if (! unlink($json_file) )
			{
				$data_retur = false;
			}
		}
		return $data_retur;
	}

	public function AutoInstallWriteOut($data, $newline = true, $append = true)
    {
		$file_log = self::LOGS_FILE;
        if ($newline)
        {
            $data = trim($data) . PHP_EOL;
        }
		$flag = 0;
		if ($append) {
			$flag = FILE_APPEND;
		}
        $write_ok = @file_put_contents($file_log, $data, $flag);
        if (! $write_ok) {
            dbug(error_get_last());
        }
    }
	public function AutoInstallReadOut($only_msg = false)
	{
		$file_log = self::LOGS_FILE;
		$return_data = array();
		if (file_exists($file_log))
		{
			$lines = file($file_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			if ($only_msg)
			{
				$return_data = $lines;
			}
			else
			{
				$return_data = array_map(function($value, $i)
				{
					return(array(
						"line" => $i,
						"msg" => $value
					));
				}, $lines, range(1, count($lines)));
			}
		}
		return $return_data;
	}

	public function AutoInstallSetStatus($new_status, &$info)
	{
		$info['status'] = $new_status;
        $this->AutoInstallSaveInfo($info);
	}

	public function isAutoInstallRunning()
	{
		return file_exists(self::LOCK_FILE);
	}

	public function AutoInstallCreateLockDir()
	{
		$data_return = true;
		$lock_dir  = self::LOCK_DIR;
		if (! file_exists($lock_dir) && ! mkdir($lock_dir))
        {
			$data_return = false;
        }
		return $data_return;
	}

	public function AutoInstallLock()
	{
		$file_lock  = self::LOCK_FILE;
		return touch($file_lock);
	}

	public function AutoInstallUnlock()
	{
		$file_lock  = self::LOCK_FILE;
		$data_retur = true;
		
		if (file_exists($file_lock))
		{
			if (! unlink($file_lock) )
			{
				$data_retur = false;
			}
		}
		return $data_retur;
	}

	public function AutoInstallDelOutLog()
	{
		$file_log  = self::LOGS_FILE;
		$data_retur = true;
		
		if (file_exists($file_log))
		{
			if (! unlink($file_log) )
			{
				$data_retur = false;
			}
		}
		return $data_retur;
	}

	public function AutoInstallDelDirTemp($dir, &$exception)
	{
		$return_data = true;
		if (! empty($dir) && file_exists($dir) && is_dir($dir))
		{
			$fsObject = new \Symfony\Component\Filesystem\Filesystem();
			try
			{
				$fsObject->remove($dir);
			}
			catch (\Symfony\Component\Filesystem\Exception\IOExceptionInterface $exception)
			{
				$return_data = false;
			}
		}
		else
		{
			$return_data = false;
		}
		return $return_data;
	}

}