<?php

//use Kunnu\Dropbox\Dropbox;
//use Kunnu\Dropbox\DropboxApp;
//use Kunnu\Dropbox\DropboxFile;
use \Dropbox as dbx;


class bsvDropboxUpload
{
    const AMOUNT_DEFAULT = 2;
    private $remote_path;
    private $file_path;
    private $file_name;
    private $amount_files;
    private $storage;
    private $dropbox;
    private $token,
        $projectFolder,
        $access_key,
        $secret;
    private $upload_count = 1;
    private $upload_info;

    public function __construct($args)
    {


        $storage = get_option('backupsavvy_child_storage', false);
//        $storage = get_option('backupsavvy_storage', false);

        $storage = maybe_unserialize($storage);
        $storage = maybe_unserialize($storage['storage']);
	    $storage = $storage->getStorage('dropbox');

        $this->token = $storage['token'];
        $this->projectFolder = $storage['folder'];
        $this->secret = $storage['secret'];
        $this->access_key = $storage['access_key'];

        $this->file_path = $args['file_path'] ? $args['file_path'] : '';
        $this->file_name = basename($this->file_path);
        $this->amount_files = empty($storage['amount']) ? self::AMOUNT_DEFAULT : $storage['amount'];
        $this->remote_path = $storage['folder'];

	    $this->dropbox = new DropboxClient( array(
		    'app_key'         => $this->access_key,
		    'app_secret'      => $this->secret,
		    'app_full_access' => false, // if the app has full or folder only access),
		    'en'
	    ) );
//
	    $this->dropbox->SetBearerToken( array(
		    't' => $this->token,
		    's' => ''
	    ) );

    }

    public function start() {

        if (is_file ($_SERVER['SERVER_NAME']  . ".processing")) {
            $time = file_get_contents($_SERVER['SERVER_NAME']  . ".processing");

            return false;
        }

//todo:: test an interupt .processing
//        file_put_contents ($_SERVER['SERVER_NAME'] . ".processing", time());

        $status = $this->upload();

//        @unlink ($_SERVER['SERVER_NAME']  . ".processing");
//todo:: finish sendMail on the server side
//        $this->sendMail();
        return $status;
    }


    private function upload() {
	    $time = date( 'd.m.Y h:i:s' );
	    $this->upload_info = $this->dropbox->UploadFile( $this->file_path );
//error_log('upload '.print_r($this->upload_info,1));
	    $log = $time . ' Uploaded ' . $this->file_path . " to Dropbox\n";
	    if ( ! isset( $this->upload_info->id ) ) {
		    $log = $time . ' Dropbox upload error ' . $this->file_path . "\n";
	    }

	    $this->writeLog( $log );

	    if(!$this->check_size()) {
//	    	error_log('upload dropbox repeated');
		    if ( $this->upload_count < 2 ) {
			    $this->upload_count = 2;
			    $this->upload();
		    } else {
		    	$this->writeLog($time . ' Dropbox upload size error ' . $this->file_path . "\n");
		    }
	    }

	    if ( isset( $this->upload_info->id ) ) {
		    //remove old backup files
		    $name        = $this->get_base_name();
		    $backup_list = $this->dropbox->Search( "/", $name );
		    $this->remove_more_amount( $backup_list );
	    }


	    return true;
    }

	/**
	 * compare size with original
	 */
    private function check_size() {
    	$original_size = filesize($this->file_path);
    	if($original_size != $this->upload_info->size)
    		return false;

    	return true;
    }

    public function get_meta() {

        $p = array(
            "recursive" => true,
            "include_media_info" => true,
            "include_deleted" => false,
            "include_has_explicit_shared_members" => true,
            "include_mounted_folders" => true
        );

        $backups = $this->dropbox->listFolder('/', $p)->getData();

        return $backups;
    }

    private function remove_more_amount($backup_list)
    {
        //    sort by time
	    usort($backup_list, array(&$this, 'cmp_groups'));

        foreach ($backup_list as $key => $backup) {
        	$max = $key + 1; // because dropbox don't find the last uploaded file
        	if($max >= $this->amount_files) {
        		$this->dropbox->delete($backup->path);
	        }
        }

        return true;
    }

    private function get_base_name()
    {
        $parts = explode('_', $this->upload_info->name);
        return $parts[0];
    }

    private static function cmp_groups($a, $b)
    {
	    $a_time = strtotime($a->server_modified);
	    $b_time = strtotime($b->server_modified);
        if ($a_time == $b_time) {
            return 0;
        }
        return ($a_time > $b_time) ? -1 : 1;
    }

    private function writeLog($string) {
        $path = plugin_dir_path( __FILE__ );

        $f = fopen($path.'/dropbox_log.log', 'a+');
        fwrite($f, $string);
        fclose($f);
    }
}