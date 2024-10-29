<?php

use altayalp\FtpClient\Servers\FtpServer;
use altayalp\FtpClient\FileFactory;

class bsvFtpUpload
{
    const AMOUNT_DEFAULT = 2;
    private $file_size;
    private $chunks;
    private $remote_path;
    private $file_path;
    private $file_name;
    private $amount_files;
    private $storage;
    private $server;

    public function __construct($args)
    {

        try {
            $storage = get_option('backupsavvy_child_storage', false);
            if (!$storage)
                throw new Exception('No storage settings!');
        } catch (Exception $e) {
            $e->getMessage();
        }

	    $settings = maybe_unserialize($storage);
	    $storage = maybe_unserialize($settings['storage']);
	    $this->storage = $storage->getStorage('ftp');

        $this->file_path = $args['file_path'];
        $this->file_name = basename($this->file_path);
        $this->amount_files = empty($settings['amount']) ? self::AMOUNT_DEFAULT : $settings['amount'];
        $this->remote_path = $this->storage['dir'];

    }

    public static function dump($dump)
    {
        echo '<pre>' . print_r($dump, true) . '</pre>';
    }

    private function get_server()
    {
        if (!$this->storage)
            return false;

        if (!$this->server) {
            $server = new FtpServer($this->storage['host']);
            $server->login($this->storage['login'], $this->storage['pass']);

            if (empty($this->storage['active'])) {
                $server->turnPassive();
            }

            $this->server = $server;
        }

        return $this->server;
    }


    public function ftp_exec()
    {

        $server = $this->get_server();

        $file = FileFactory::build($server);

        $result = $file->upload($this->file_path, $this->remote_path . '/' . $this->file_name);

        if (!$this->cmp_sizes($this->file_path, $this->remote_path . '/' . $this->file_name))
            return 'size';

        if (!$this->remove_more_amount())
            return false;

        return $result;
    }

    // sort archives by time
    private static function cmp_groups($a, $b)
    {
        if ($a["time"] == $b["time"]) {
            return 0;
        }
        return ($a["time"] > $b["time"]) ? -1 : 1;
    }

    private function get_base_name($file_name)
    {
        $parts = explode('_', $file_name);
        return $parts[0];
    }


    private function remove_more_amount()
    {

        $mask = '/' . $this->get_base_name($this->file_name) . '*';

        $file = fileFactory::build($this->server);

        $list = $file->ls($this->storage['dir'] . $mask);

        foreach ($list as $key => $file_name) {
            $groups[$this->get_base_name($file_name)][] = array(
                'file' => $file_name,
                'size' => $file->getSize($file_name),
                'time' => $file->getLastMod($file_name),
            );
        }

        //    sort by time
        foreach ($groups as $key => $group) {
            usort($groups[$key], array(&$this, 'cmp_groups'));
        }

        foreach ($groups as $key => $group) {
            foreach ($group as $max => $site) {
                if ($max >= $this->amount_files) {
                    if (!$file->rm($site['file'])) {
                        error_log('remove error ' . $site['file']);
                        return false;
                    }
                    unset($groups[$key][$max]);
                }
            }
        }

        return true;
    }

    private function cmp_sizes($local, $remote)
    {

        $file = FileFactory::build($this->server);

        $remote_size = $file->getSize($remote);
        $local_size = filesize($local);

        if ($remote_size != $local_size)
            return false;

        return true;
    }

    private function upload_ftp_while($storage)
    {
        $trackErrors = ini_get('track_errors');
        ini_set('track_errors', 1);

        $ftp_server = $storage['host'];
        $ftp_port = $storage['port'];
        $ftp_user = $storage['login'];
        $ftp_pass = $storage['pass'];

        $file = $this->file_path;//tobe uploaded
        $remote_file = $storage['dir'] . '/' . basename($file);

        $id = ftp_connect($ftp_server, $ftp_port);

        $login_result = ftp_login($id, $ftp_user, $ftp_pass);

        $localfile = fopen($file, 'rb');
        $i = 0;
        unlink($_SERVER['DOCUMENT_ROOT'] . '/wpbackitup_sql/tmp_ftp_upload.bin');
//		var_dump($localfile);
        if ($login_result)
            ftp_pasv($id, true);
        else
            error_log('not loged in');

        while ($i < $this->file_size) {
//			$tmpfile = fopen($_SERVER['DOCUMENT_ROOT'].'/wpbackitup_sql/tmp_ftp_upload.bin','ab'); // open local
            $tmpfile = fopen($_SERVER['DOCUMENT_ROOT'] . '/wpbackitup_sql/tmp_ftp_upload.bin', 'w'); // open local
            fwrite($tmpfile, fread($localfile, $this->chunks));
            fclose($tmpfile);

            if (!ftp_put($id, $this->remote_path . '/tmp_ftp_upload.tar.gz', $_SERVER['DOCUMENT_ROOT'] . '/wpbackitup_sql/tmp_ftp_upload.bin', FTP_BINARY, $i)) {
                $msg = $php_errormsg;
                ini_set('track_errors', $trackErrors);
                error_log(print_r($msg, true));
                error_log('error');
            }
            $i += $this->chunks;
            // Remember to put $i as last argument above

//			$progress = (100 * round( ($i += $this->chunks)  / $this->file_size, 2 ));
//			file_put_contents('ftp_progress.txt', "Progress: {$progress}%");
        }
        ini_set('track_errors', $trackErrors);
        fclose($localfile);
        unlink('ftp_progress.txt');
        unlink('tmp_ftp_upload.bin');

        return true;
    }

    private function upload_ftp($storage)
    {

        $ftp_server = $storage['host'];
        $ftp_port = $storage['port'];
        $ftp_user = $storage['login'];
        $ftp_pass = $storage['pass'];

        $file = $this->file_path;//tobe uploaded
        $remote_file = basename($file); // path on remote server

        // set up basic connection
        $id = ftp_connect($ftp_server, $ftp_port);

        $login_result = ftp_login($id, $ftp_user, $ftp_pass);

        $trackErrors = ini_get('track_errors');
        ini_set('track_errors', 1);
        if ($login_result) {
            ftp_set_option($id, FTP_USEPASVADDRESS, true);
            ftp_pasv($id, true);

            $current_ftp_dir = trailingslashit(ftp_pwd($id));
            ftp_chdir($id, $this->remote_path);
            $current_ftp_dir = trailingslashit(ftp_pwd($id));

            $localfile = $_SERVER['DOCUMENT_ROOT'] . '/wpbackitup_sql/bizplanhub.tar.gz';
            if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/wpbackitup_sql/bizplanhub.tar.gz'))
                error_log('file doesnt exitsts!');

            $files_list = ftp_nlist($id, $storage['dir']);
//			$info = ftp_systype($id);

            $fp = fopen($_SERVER['DOCUMENT_ROOT'] . '/wpbackitup_sql/bizplanhub.tar.gz', 'rb');
//			error_log(print_r($info,true));
            ftp_set_option($id, FTP_USEPASVADDRESS, true);
            ftp_pasv($id, true);
            // upload a file
            if (!ftp_put($id, $remote_file, $_SERVER['DOCUMENT_ROOT'] . '/wpbackitup_sql/bizplanhub.tar.gz', FTP_BINARY)) {
                // Exception "There was a problem while uploading $file\n";
                $msg = $php_errormsg;
                ini_set('track_errors', $trackErrors);
//				throw new Exception($msg);
                error_log('track_errors_msg: ' . print_r($msg, true));
            }
            $msg = $php_errormsg;
            ini_set('track_errors', $trackErrors);
//			throw new Exception($msg);
//			error_log(print_r($msg,true));
            fclose($fp);
        } else {
            ini_set('track_errors', $trackErrors);
        }
        // close the connection
        ftp_close($id);

        return true;
    }

}