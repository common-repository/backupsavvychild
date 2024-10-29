<?php
/**
 * Archiving of the files tar.gz, zip, tar
 *  @method tarGz, tar, zip
 */

use splitbrain\PHPArchive\Tar;

class bsv_compressHandler {

	private $source,
			$archive_path,
			$file_name,
			$usePhar,
			$method,
			$dump_dir;
	private $exclude_dirs = array();
	private $exclude_files = array();
	/**
	 * @var array for a zip archive creation from folder
	 */
	private $allfiles = array();

	/**
	 * @var bool gz compression for tar.gz method
	 */
	private $tar_gz = false;

	/**
	 * @var bool bz2 compression for bz2 method
	 */
	private $tar_bz = false;

	public function __construct($args) {

		if(!isset($args['source_path']))
			wp_die();

		$this->source = $args['source_path'];
		$this->archive_path = $args['archive_path'];
		$this->file_name = !empty($args['file_name']) ? $args['file_name'] : '';
		$this->method = isset($args['method']) ? $args['method'] : false;
		$this->exclude_dirs = $args['exclude_dirs'];
		$this->exclude_files = $args['exclude_files'];
		$this->usePhar = $args['compr'] == 'phar' ? true : false;
		$this->dump_dir = $args['dump_dir'];


		$this->archive_path = str_replace('\\\\', '\\', $this->archive_path);

		if($this->method == 'tarGz') {
			if(!$this->file_name)
				$this->file_name = 'backup.tar';
			$this->tar_gz = true;
			$this->exportTarGz();
		}
		if($this->method == 'tar') {
			if(!$this->file_name)
				$this->file_name = 'backup.tar';
			$this->exportTarGz();
		}
		if($this->method == 'bz2') {
			if(!$this->file_name)
				$this->file_name = 'backup.tar';
			$this->tar_bz = true;
			$this->exportTarGz();
		}
		if($this->method == 'zip') {
			if(!$this->file_name)
				$this->file_name = 'backup.zip';
			$this->exportZip();
		}
		if(!$this->method)
			throw new compressHandlerException( 'No compression method found' );
	}


	private function exportTarGz() {

		if(file_exists($this->archive_path.'/'.$this->file_name))
			return false;


		@ini_set( 'memory_limit', '512M' );
		ignore_user_abort(true);
		@set_time_limit( 0 );

		if($this->usePhar) {
			try {

				$trackErrors = ini_get( 'track_errors' );
				ini_set( 'track_errors', 1 );

					if ( $this->tar_gz || $this->tar_bz ) {
						$phar = new PharData( $this->archive_path . '/temp.tar' );
						if ( ! $phar ) {
							$msg = $php_errormsg;
							error_log( 'not phar ' . print_r( $msg, TRUE ) );
							ini_set( 'track_errors', $trackErrors );
						}
					} else {
						$phar = new PharData( $this->archive_path . '/' . $this->file_name );
					}

					// ADD FILES TO backup.tar FILE
					if ( is_dir( $this->source ) ) {
						$exclude = '/^(?!(.*'.$this->dump_dir.'))(.*)$/i';
						if ( ! $phar->buildFromDirectory( $this->source, $exclude ) ) {
							$msg = $php_errormsg;
							error_log( 'not builded ' . print_r( $msg, TRUE ) );
							ini_set( 'track_errors', $trackErrors );
							wp_die();
						}

//						error_log( 'tar created' );

					} else {
						$phar->addFile( $this->source );
					}

//					$this->needFreeMemory( $this->archive_path . '/' . $this->file_name * 8 );

					if ( $this->tar_gz ) {
					if(!$phar->compress( Phar::GZ )) {

						$msg = $php_errormsg;
						error_log('not builded '.print_r($msg,true));
						wp_die();
					}
					rename($this->archive_path . '/temp.tar.gz', $this->archive_path . '/' . $this->file_name);
					unlink($this->archive_path . '/temp.tar');
					}

					if ( $this->tar_bz ) {
						$phar->compress( Phar::BZ2 );
						rename( $this->archive_path . '/temp.tar.bz2', $this->archive_path . '/' . $this->file_name );
						unlink( $this->archive_path . '/temp.tar' );
					}

				} catch ( Exception $e ) {
	//			throw new compressHandlerException( $e->getMessage() );
					error_log( print_r( 'error export' . $e->getMessage() ) );

			}
		} else {

			$tar = new Tar();
			$tar->create($this->archive_path . "/" . $this->file_name);

			$this->recoursiveDir($_SERVER['DOCUMENT_ROOT']);
			if($this->allfiles)
				foreach ( $this->allfiles as $file ) {
					$tar->addFile($file);
				}
			$tar->close();

		}

	}

	/**
	 *
	 * Increase automatically the memory that is needed
	 *
	 * @param int|string $memneed of the needed memory
	 */
	public function needFreeMemory( $memneed ) {

		//need memory
		$needmemory = @memory_get_usage( true ) + self::convert_hr_to_bytes( $memneed );
		// increase Memory
		if ( $needmemory > self::convert_hr_to_bytes( ini_get( 'memory_limit' ) ) ) {
			$newmemory = round( $needmemory / 1024 / 1024 ) + 1 . 'M';
			if ( $needmemory >= 1073741824 ) {
				$newmemory = round( $needmemory / 1024 / 1024 / 1024 ) . 'G';
			}
			@ini_set( 'memory_limit', $newmemory );
		}
	}

	public static function convert_hr_to_bytes( $size ) {

		$size  = strtolower( $size );
		$bytes = (int) $size;
		if ( strpos( $size, 'k' ) !== false ) {
			$bytes = intval( $size ) * 1024;
		} elseif ( strpos( $size, 'm' ) !== false ) {
			$bytes = intval( $size ) * 1024 * 1024;
		} elseif ( strpos( $size, 'g' ) !== false ) {
			$bytes = intval( $size ) * 1024 * 1024 * 1024;
		}

		return $bytes;
	}


	private function exportZip() {
		if(!class_exists('ZipArchive'))
			throw new compressHandlerException("Class ZipArchive doesn't exists");

		$zip = new ZipArchive();

		if ( $zip->open( $this->archive_path . "/" . $this->file_name, ZipArchive::CREATE ) === TRUE ) {
			if ( is_dir( $this->source ) ) {

				$this->recoursiveDir( $this->source );

			} else {

				$this->allfiles[] = $this->source;

			} // add file to the finish array

			$root = str_replace('\\\\', '\\', $_SERVER['DOCUMENT_ROOT']);
			$root = str_replace('//', '/', $_SERVER['DOCUMENT_ROOT']);
			$root = str_replace('\\', '/', $root);

			$dirs = explode( '/', $root );
			$dir = end( $dirs );


			foreach ( $this->allfiles as $file ) {

				$dirname = dirname( $file );
				$dirname = stristr( $dirname, $dir );

				$name_within = trailingslashit( $dirname ) . sanitize_file_name( basename( $file ) );

				$zip->addFile( $file, $name_within );

			}

		} else {
//			throw new compressHandlerException('Can not open zip archive');
//			error_log('Can not open zip archive');
		}
	}

	/* creating array of the all files */
	private function recoursiveDir( $dir ) {

		if ( $files = glob( $dir . "/{,.}*", GLOB_BRACE ) ) {

			foreach ( $files as $file ) {
				$b_name = basename( $file );
				if ( ( $b_name == "." ) || ( $b_name == ".." ) ) {
					continue;
				}

				if ( is_dir( $file ) ) {
					if(!in_array($file, $this->exclude_dirs))
						$this->recoursiveDir( $file );
				} else {
					if(!in_array($file, $this->exclude_files))
						$this->allfiles[] = str_replace( '//', '/', $file );
				}

			}

		}

	}

}

class compressHandlerException extends backupSavvyChild_Exception {

}