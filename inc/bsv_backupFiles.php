<?php

/**
 * Check if the library already exists
 */

$bsv_classes = array(
  'Archive',
  'Zip',
  'Tar',
  'FileInfo',
);
foreach ($bsv_classes as $class_name) {
  if (class_exists('splitbrain\\PHPArchive\\' . $class_name)) {
    $bsv_not_load = 1;
  }
}

/**
 * Including the modified library splitbrain\PHPArchive
 *  https://github.com/splitbrain/php-archive
 */
if (!isset($bsv_not_load)) {
  include_once 'compressInc/FileInfoException.php';
  include_once 'compressInc/FileInfo.php';
  include_once 'compressInc/ArchiveCorruptedException.php';
  include_once 'compressInc/ArchiveIllegalCompressionException.php';
  include_once 'compressInc/ArchiveIOException.php';
  include_once 'compressInc/Archive.php';
  include_once 'compressInc/Tar.php';
  include_once 'compressInc/Zip.php';
}



class bsvBackupFiles {

	private $allfiles = array();
	private $exclude = array();
	private $source_dir;
	private $dump_path;
	private $dump_dir;
	private $delay_delete; // time in seconds when archives will be deleted
	private $filezip;
	private $ext = '';
	private $options = FALSE;


	public function __construct() {

		@ini_set( 'memory_limit', '128M' );
		ignore_user_abort(true);
		@set_time_limit( 0 );


		$site_url = $this->get_url( 'base' );

		$site_url = str_replace('www.', '', $site_url);


		$this->source_dir = $_SERVER['DOCUMENT_ROOT'];
		$this->dump_dir = 'backupsavvy_dump';
		$this->dump_path = $_SERVER['DOCUMENT_ROOT'] . '/'.$this->dump_dir;

		if ( ! file_exists( $this->dump_path ) ) {
			mkdir( $this->dump_path, 0755, TRUE );
		}

		$this->delay_delete = 35 * 24 * 3600; // the time when rhe backups will be removing after it

		// todo: add site's unique id to backup file name


		$method = $this->get_host_option('method');

		$end = '.zip';
		if ( $method == 'tar' ) {
			$end = '.tar';
		}
		if ( $method == 'tarGz' ) {
			$end = '.tgz';
		}
		if ( $method == 'bz2' ) {
			$end = '.bz2';
		}

		$this->filezip = $site_url.'_'.date( "Ymd_H_i" ) . $end;

		$this->create_backup();


	}


	private function create_backup() {

		if ( file_exists( $this->dump_path . "/" . $this->filezip.$this->ext ) ) {
			return;
		}

		$method = $this->get_host_option('method');
		if(!$method)
			return;

		// excluded directories
		$exclude_d = $this->get_host_option('exclude_d');
		if ( ! $exclude_d ) {
			$exclude_dirs = array($this->dump_path);
		} else {
			$exclude_d = explode(',', $exclude_d);
			foreach ( $exclude_d as $key => $exclude_dir ) {
				$exclude_dir = trim($exclude_dir);
				$exclude_dirs[] = $_SERVER['DOCUMENT_ROOT'] . '/'.$exclude_dir;
			}
			$exclude_dirs[] = $this->dump_path;
		}

		// excluded files
		$exclude_f = $this->get_host_option('exclude_f');
		$exclude_files = array();
		if($exclude_f) {
			$exclude_f = explode(',', $exclude_f);
			foreach ( $exclude_f as $key => $exclude_file ) {
				$exclude_file = trim($exclude_file);
				$exclude_files[] = $_SERVER['DOCUMENT_ROOT'] . '/'.$exclude_file;
			}
		}

		$compr = $this->get_host_option('compr');
		$args = array(
			'source_path'   => $this->source_dir,
			'archive_path'  => $this->dump_path,
			'dump_dir'      => $this->dump_dir,
			'file_name'     => $this->filezip,
			'method'        => $method,
			'exclude_dirs'  => $exclude_dirs,
			'exclude_files' => $exclude_files,
			'compr'         => $compr
		);

		new bsv_compressHandler( $args );
	}

	private function get_host_option($type) {

		if ( ! $this->options ) {
			$options = get_option( 'backupsavvy_child_storage', FALSE );
			$this->options = $options;
		}

		if(!$this->options)
			return false;

		$options = unserialize($this->options);

		if(!isset($options[$type]))
			return false;

		return $options[$type];

	}


	function get_url( $base = FALSE ) {


		if ( $base ) {
			return $_SERVER['SERVER_NAME'];
		}


		return sprintf(

			"%s://%s%s",

			isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',

			$_SERVER['SERVER_NAME'],

			$_SERVER['REQUEST_URI']

		);

	}


	public function get_file_path() {
		return $this->dump_path . '/' . $this->filezip;

	}


}