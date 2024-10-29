<?php
/**
 * Plugin Name: BackUpSavvyChild
 * Plugin URI: http://backupsavvy.com
 * Description: WordPress Backup Child Plugin
 * Author: BackupSavvy.com
 * Version: 1.0.2
 * Domain Path: /
 * Network: true
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0
*/
if (!defined('ABSPATH')) exit;

if (!defined('WPINC')) die;

if (!class_exists('BackupSavvyChild', false)) {


    class BackupSavvyChild
    {

        private static $instance = null;
//		private $api_key = '53t181Hf3Xe8f80we472aye7695167V9Q08a4c';
        private $source_dir;
        private $upload_result;

        public function __construct()
        {
			// disable updates to premium version
        	if($this->get_vars()->status == 'prem')
		        add_filter( 'site_transient_update_plugins', array(&$this, 'backupsavvychild_filter_plugin_updates') );

        	include_once 'inc/bsvHelperFunctions.php';
            include_once 'vendor/autoload.php';
            include_once 'inc/bsv_compressHandler.php';

            register_deactivation_hook(__FILE__, array(&$this, 'deactivation_hook'));

            add_action('rest_api_init', array(&$this, 'child_api_callback'));
            add_action('admin_menu', array(&$this, 'register_backup_savvy_child_custom_menu'), 10);

            //		$option = get_option('backupsavvy_child_secret', 0);

            $this->sql_dir = $_SERVER['DOCUMENT_ROOT'] . '/backupsavvy_sql';
            $this->source_dir = $_SERVER['DOCUMENT_ROOT'] . '/backupsavvy_dump';

            if (!file_exists($this->source_dir)) {
                mkdir($this->source_dir, 0755, true);
            }
            if (!file_exists($this->sql_dir)) {
                mkdir($this->sql_dir, 0755, true);
            }

            $old_path = $_SERVER['DOCUMENT_ROOT'] . '/wpbackitup_sql';
            if(file_exists($old_path)) {
				$manager = new bsvHelperFunctions();
				$manager->discovery($old_path);
				if($manager->files)
					foreach ($manager->files as $file) {
						if(!unlink($file))
							error_log('File '.$file.' can not be removed');

					}
				if($manager->directories)
					foreach ($manager->directories as $directory) {
						if (basename($directory) != '.' && basename($directory) != '..') {
							if(!rmdir($directory))
								error_log('Directory '.$directory.' can not be removed');
						}
					}
            }
        }

		/**
		 * @param $value
		 *
		 * @return mixed
		 */
        public function backupsavvychild_filter_plugin_updates( $value ) {
	        if( isset( $value->response['backupsavvy-child/backupsavvychild.php'] ) ) {
		        unset( $value->response['backupsavvy-child/backupsavvychild.php'] );
	        }

	        return $value;
        }
        public function register_backup_savvy_child_custom_menu()
        {
            add_menu_page('BackupSavvy Child', 'BackupSavvy Child', 'manage_options', 'backup-savvy-child-settings', array(&$this, 'settings_page'), 'none', '30.3');
//
        }

        public function settings_page()
        {
            include_once 'settings-page.php';
        }

        public function child_api_callback()
        {
            register_rest_route('backupsavvyapi', "addsite", array(
                'methods' => 'POST',
                'callback' => array(&$this, 'add_site')

            ));
            register_rest_route('backupsavvyapi', "backup", array(
                'methods' => 'POST',
                'callback' => array(&$this, 'add_backup')

            ));
            // separate add backup and upload
            register_rest_route('backupsavvyapi', "upload", array(
                'methods' => 'POST',
                'callback' => array(&$this, 'upload_backup')
            ));

            register_rest_route('backupsavvyapi', "settings", array(
                'methods' => 'POST',
                'callback' => array(&$this, 'update_settings')
            ));
        
        }


        public function update_settings($params = false)
        {
//			if($params['apikey'] != $this->api_key)
//				return array('status' => 'apikey');

	        $params = $params->get_params();

	        if(!$this->checkSecret($params))
		        return array('status' => 'secret');

            $res = null;
	        $status = 'success';
            if ($params['action'] == 'storage') {
                $data = $params['data'];
                switch ($data['connection']):
                    case 'ftp':
                        $res = $this->update_ftp($data);
                        break;
                    case 'sftp':
                        $res = $this->update_sftp($data);
                        break;
                    case 'dropbox':
                        $res = $this->update_dropbox($data);
                        break;
	                case 'google_drive':
	                	$res = $this->update_google_drive($data);
	                	break;
                    default:
                        $res = null;
                        $status = 'storage';
                endswitch;
            }

            return array('status' => $status);

        }

        private function update_ftp($data)
        {
            $data = serialize($data);
            update_option('backupsavvy_child_storage', $data);

            return true;
        }

        private function update_sftp($data)
        {
            return true;
        }

        private function update_dropbox($data)
        {
            $data = serialize($data);
            update_option('backupsavvy_child_storage', $data);

            return true;
        }

        private function update_google_drive($data) {
	        $data = serialize($data);
	        update_option('backupsavvy_child_storage', $data);

	        return true;
        }

        public function add_site($params = false)
        {
	        $params = $params->get_params();

	        if(!$this->checkSecret($params))
		        return array('status' => 'secret');


            return array('status' => 'success');
        }

        private function checkSecret($params) {

	        if(empty($params['secret']))
		        return false;

	        $secret = $params['secret'];

	        $site_s = get_option('backupsavvy_child_secret', 0);
	        $expected = md5($site_s.'.bcssvy');

	        if ($expected != $secret)
		        if(!$this->checkMainWpAccess($secret))
			        return false;

		    return true;
        }

        private function checkMainWpAccess($secret) {
        	$uniqueId = get_option( 'mainwp_child_uniqueId' );
	        $expected = md5('wnwp_'.$uniqueId.'.bcssvy');
	        if ( '' != $uniqueId )
	        	if($expected == $secret)
	        		return true;

	        $super_admins = get_super_admins();
	        foreach ($super_admins as $admin) {
		        $expected = md5('wnwp_'.$admin.'.bcssvy');
	        	if($expected == $secret)
	        		return true;
	        }

			return false;
        }

        public function add_backup($params = false)
        {
	         $params = $params->get_params(); // wp method

            if (!$params)
                return array('status' => 'params');

            if(!$this->checkSecret($params))
	            return array('status' => 'secret');
//			if($params['apikey'] != $this->api_key)
//				return array('status' => 'apikey');

            $backup_path = $this->create_backup();

            if (!$backup_path)
                return array('status' => 'backup_error');

            update_option('backupsavvy_child_last_backup', $backup_path);

            return array('status' => 'success', 'backup' => $backup_path);
        }


        private function create_backup()
        {
            global $wpdb;
            include_once 'inc/bsvBackupDb.php';
            include_once 'inc/bsv_backupFiles.php';

            $this->deleteOldBackups();

            try {
                $date = date("Y-m-d");
                $sql_dump = new bsv_backup_restore(array(
                	'dumpfile' => $this->sql_dir . '/sql_dump_' . $date . '.gz',
	                'sourcedir' => $this->sql_dir
                ));
	            $sql_dump->backup();

                unset($sql_dump);
            } catch (Exception $e) {
                error_log($e->getMessage());
                return false;
            }

            try {

                $backup = new bsvBackupFiles();
                $file_path = $backup->get_file_path();

            } catch (Exception $e) {
            	error_log($e->getMessage());
                return false;
            }

            return $file_path;
        }

        /* Remove the all old archives */
        private function deleteOldBackups()
        {
            $ts = time();
            $files = glob($this->source_dir . "/*");
            if ($files) {
                foreach ($files as $file) {

                    //			if ( $ts - filemtime( $file ) > $this->delay_delete ) {

                    unlink($file);

                    //			}

                }
            }
            $files_sql = glob($this->sql_dir . "/*");
            if ($files_sql) {
                foreach ($files_sql as $file) {

                    unlink($file);

                }
            }

        }

        public function upload_backup($params)
        {

//			if($params['apikey'] != $this->api_key)
//				return array('status' => 'apikey');
			 $params = $params->get_params();

	        if(!$this->checkSecret($params))
		        return array('status' => 'secret');

            $storage = get_option('backupsavvy_child_storage', false);

            if (!$storage)
                return array('status' => 'storage');

            $storage = maybe_unserialize($storage);

            $backup_path = get_option('backupsavvy_child_last_backup', false);

	        $storage_settings = '';
            if(isset($storage['storage'])) {
            	$storage_settings = maybe_unserialize($storage['storage']);
            }

            if (!$backup_path)
                return array('status' => 'backup_name');


            if (!file_exists($backup_path))
                return array('status' => $backup_path);

            if ($storage['connection'] == 'ftp')
                if (!$this->upload_ftp($backup_path))
                    return array('status' => 'upload_error');

            if($storage['connection'] == 'dropbox')
                if(!$this->upload_dropbox($backup_path))
                    return array('status' => 'upload_error');

	        if($storage['connection'] == 'google_drive')
	        	if(!$this->upload_google_drive($backup_path, $storage_settings))
			        return array(
			        	'status' => 'upload_error',
				        'result' => $this->upload_result
				        );

	        $backup_name = basename($backup_path);

			$result = array(
				'status' => 'success',
				'backup_name' => $backup_name,
				'result' => array(
					'fileId' => $this->upload_result->id
				) // google file id
			);

            return $result;
        }


        private function upload_ftp($file_path)
        {
            include_once 'inc/bsv_ftpUpload.php';

            $args = array(
                'file_path' => $file_path,
            );

            $ftp = new bsvFtpUpload($args);
            $result = $ftp->ftp_exec();
            if($result == 'size' || $result === false)
                return false;

            $this->upload_result = $result;

            return true;
        }

        private function get_vars() {
        	return (object) array(
        		'version' => '1.0.0',
		        'status' => 'prem',
		        'php' => '5.6'
	        );
        }

        private function upload_dropbox($file_path) {

	        ini_set("max_execution_time", "5000");
	        ini_set("max_input_time", "5000");
	        @ini_set( 'memory_limit', '1000M' );
	        @set_time_limit( 0 );
			if(!defined('BCSVDIR')) define('BCSVDIR', __DIR__);

	        include_once 'inc/DropboxClient.php';
            include_once 'inc/bsv_dropboxUpload.php';
            $args = array(
                'file_path' => $file_path,
            );
            
            $uploader = new bsvDropboxUpload($args);
            if(!$uploader->start())
                return false;

            return true;
        }

        private function upload_google_drive($file_path, $storage_settings) {
	        $vault = $storage_settings->getStorage('google_drive');

			require_once __DIR__ . '/vendor/autoload.php';
	        $client = new Google_Client();

	        $client->addScope(Google_Service_Drive::DRIVE);
	        $redirect_uri = $vault['redirect_uri'];
	        $client->setRedirectUri($redirect_uri);

	        $tokens['access_token'] = $vault['token'];
	        if(isset($vault['refresh_token'])) {
		        $tokens['refresh_token'] = $vault['refresh_token'];
		        $tokens['expires_in'] = $vault['expires_in'];
	        }

	        $client->setAccessToken($tokens);
	        $service = new Google_Service_Drive($client);
//
	        $file = new Google_Service_Drive_DriveFile();
	        $file->setName(basename( $file_path ));
	        $this->upload_result = $service->files->create(
		        $file,
		        array(
			        'data' => file_get_contents($file_path),
			        'mimeType' => 'application/octet-stream',
			        'uploadType' => 'media'
		        )
	        );

	        return true;

        }

        public function deactivation_hook()
        {
            delete_option('backupsavvy_child_secret');

            if (is_dir($this->source_dir)) {
                rmdir($this->source_dir);
            }
        }

        public static function generate_secret()
        {
            $site_name = get_bloginfo();

            return substr(md5($site_name . time() . rand(1, 100)), 0, 12);
        }

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self;
            }

            return self::$instance;
        }

    }

    class backupSavvyChild_Exception extends Exception
    {
    }

	if (!class_exists('storageSettings', false)) {
		class storageSettings {
			private $name;
			private $vaults = [];

			public function __construct( $name, array $args ) {
				$this->name            = $name;
				$args['name']          = $name;
				$this->vaults[ $name ] = $args;
			}

			public function getStorage( $name ) {
				return !empty($this->vaults[ $name ]) ? $this->vaults[ $name ] : false;
			}

			/**
			 * @param storageSettings $storage current storage
			 * @param storageSettings $new_storage new storage to add it
			 *
			 * @return storageSettings $storage
			 */
			public function addStorage( storageSettings $storage, $new_storage ) {

				$storage->vaults[ $this->name ] = $new_storage->getStorage( $this->name ); // array

				return $storage;

			}

			// will be removed storage from the saved storage object
			public function cleanStorage() {

			}

		}
	}

    add_action('plugins_loaded', array('BackupSavvyChild', 'get_instance'));

    function backupSavvyChild_activation_hook()
    {
        update_option('backupsavvy_child_secret', BackupSavvyChild::generate_secret());
    }

    register_activation_hook(__FILE__, 'backupSavvyChild_activation_hook');

}