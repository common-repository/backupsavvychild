<?php

class bsv_backup_restore {

	/**
	 * Table names of Tables in Database
	 */
	public $tables_to_dump = array();

	/**
	 * View names of Views in Database
	 */
	private $views_to_dump = array();


	/**
	 * The path where the backup file should be saved
	 *
	 * @string
	 * @access private
	 */
	private $path = '';

	/**
	 * The backup type, must be either complete, file or database
	 *
	 * @string
	 * @access private
	 */
	private $type = '';

	/**
	 * The filename of the backup file
	 *
	 * @string
	 * @access private
	 */
	private $archive_filename = '';

	/**
	 * The filename of the database dump
	 *
	 * @string
	 * @access private
	 */
	private $database_dump_filename = '';

	private $mysqli;
	private $sourcedir;
	private $handle;
	private $dumpfile;
	private $table_types = array();
	private $table_status = array();
	private $defaulthandle;
	private $dbclientflags;
	private $database;
	private $user;
	private $pass;
	private $dbcharset;




	/**
	 * Sets up the default properties
	 *
	 * @access public
	 */
	public function __construct( $args = array() ) {
		// Raise the memory limit and max_execution time
		@ini_set( 'memory_limit', '128M' );
		@set_time_limit( 0 );

		$default_args = array(
			'dbhost' 	    => DB_HOST,
			'dbname' 	    => DB_NAME,
			'dbuser' 	    => DB_USER,
			'dbpassword'    => DB_PASSWORD,
			'dbcharset'     => defined( 'DB_CHARSET' ) ? DB_CHARSET : '',
			'defaulthandle' => fopen( 'php://output', 'wb' ),
			'dumpfile' 	    => NULL,
			'sourcedir' 	    => '',
			'dbclientflags' => defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0,
			'compression'   => ''
		);

		$args = wp_parse_args( $args , $default_args );

		$this->host = $args['dbhost'];
		$this->database = $args['dbname'];
		$this->user = $args['dbuser'];
		$this->pass = $args['dbpassword'];
		$this->dbcharset = $args['dbcharset'];
		$this->dbclientflags = $args['dbclientflags'];
		$this->defaulthandle = $args['defaulthandle'];
		$this->dumpfile = $args['dumpfile'];
		$this->sourcedir = $args['sourcedir'];
		$this->dbport = NULL;
		$this->dbsocket = NULL;

		if ( strstr( $args[ 'dbhost' ], ':' ) ) {
			$hostparts = explode( ':', $args[ 'dbhost' ], 2 );
			$hostparts[ 0 ] = trim( $hostparts[ 0 ] );
			$hostparts[ 1 ] = trim( $hostparts[ 1 ] );
			if ( empty( $hostparts[ 0 ] ) )
				$this->host = NULL;
			else
				$this->host = $hostparts[ 0 ];
			if ( is_numeric( $hostparts[ 1 ] ) )
				$this->dbport = (int) $hostparts[ 1 ];
			else
				$this->dbsocket = $hostparts[ 1 ];
		}

//		$this->file_path = ($path) ? $path : dirname(__FILE__) ;

		$this->connect();
//		$this->set_charset();
		$this->open_file();

	}

	/**
	 * Sets up bd connection
	 *
	 * @access private
	 */
	private function connect() {
		$this->mysqli = mysqli_init();
		if ( ! $this->mysqli ) {
			throw new backupSavvy_MySQLDump_Exception( __( 'Cannot init MySQLi database connection', 'wpbackitup' ) );
		}
		if ( ! $this->mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 5 ) ) {
			trigger_error( __( 'Setting of MySQLi connection timeout failed', 'wpbackitup' ) );
		}
		//connect to Database
		if ( ! $this->mysqli->real_connect( $this->host, $this->user, $this->pass, $this->database, $this->dbport, $this->dbsocket, $this->dbclientflags ) ) {
			throw new backupSavvy_MySQLDump_Exception( sprintf( __( 'Cannot connect to MySQL database %1$d: %2$s', 'wpbackitup' ), mysqli_connect_errno(), mysqli_connect_error() ) );
		}

	}

	/**
	 * set mysqli charset
	 *
	 * @access private
	 */
	private function set_charset() {
		if ( ! empty( $this->dbcharset ) && method_exists( $this->mysqli, 'set_charset' ) ) {
			$res = $this->mysqli->set_charset( $this->dbcharset );
			if ( ! $res ) {
				trigger_error( sprintf( _x( 'Cannot set DB charset to %s error: %s','Database Charset', 'backwpup' ), $this->dbcharset, $this->mysqli->error ), E_USER_WARNING );
			}

		}
	}

	private function open_file() {

		if ( $this->dumpfile ) {
			if ( substr( strtolower( $this->dumpfile ), -3 ) === '.gz' ) {
				if ( ! function_exists( 'gzencode' ) )
					throw new backupSavvy_MySQLDump_Exception( __( 'Functions for gz compression not available', 'backupsavvy' ) );
				$this->compression = 'gz';
				$this->handle = fopen( 'compress.zlib://' . $this->dumpfile, 'ab' );
			}  else {
				$this->compression = '';
				$this->handle = fopen( $this->dumpfile , 'ab' );
			}
		} else {
			$this->handle = $this->defaulthandle;
		}

		//check file handle
		if ( ! $this->handle ) {
			throw new backupSavvy_MySQLDump_Exception( __( 'Cannot open SQL backup file', 'backwpup' ) );
		}

	}


	/**
	 * Kick off a backup
	 *
	 * @access public
	 * @return bool
	 */
	public function backup() {


		$link = $this->mysqli;

//	    mysqli_set_charset( DB_CHARSET, $this->db );

		// Begin new backup of MySql
		$tables = mysqli_query($link, 'SHOW TABLES' );

		$sql_file  = "# BackupSavvy MySQL database backup\n";
		$sql_file .= "#\n";
		$sql_file .= "# Generated: " . date( 'l j. F Y H:i T' ) . "\n";
		$sql_file .= "# Hostname: " . $this->host . "\n";
		$sql_file .= "# Database: " . $this->sql_backquote( $this->database ) . "\n";
		$sql_file .= "# --------------------------------------------------------\n";

		for ( $i = 0; $i < mysqli_num_rows( $tables ); $i++ ) {
			mysqli_data_seek( $tables, $i );
			$f = mysqli_fetch_array( $tables );
			$curr_table = $f[0];
//			self::dump($curr_table);
			// Create the SQL statements
			$sql_file .= "# --------------------------------------------------------\n";
			$sql_file .= "# Table: " . $this->sql_backquote( $curr_table ) . "\n";
			$sql_file .= "# --------------------------------------------------------\n";

			$this->make_sql( $sql_file, $curr_table );

		}

		// Read the backup file into string then remove the file
//		$finalbackup = file_get_contents($this->database_dump_filename);
//		unlink($this->database_dump_filename);
//		return $finalbackup;

	}

	/**
	 * Reads the Database table in $table and creates
	 * SQL Statements for recreating structure and data
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	 *
	 * @access private
	 * @param string $sql_file
	 * @param string $table
	 */
	private function make_sql( $sql_file, $table ) {
		$link = $this->mysqli;
		// Add SQL statement to drop existing table
		$sql_file .= "\n";
		$sql_file .= "\n";
		$sql_file .= "#\n";
		$sql_file .= "# Delete any existing table " . $this->sql_backquote( $table ) . "\n";
		$sql_file .= "#\n";
		$sql_file .= "\n";
		$sql_file .= "DROP TABLE IF EXISTS " . $this->sql_backquote( $table ) . ";\n";

		/* Table Structure */

		// Comment in SQL-file
		$sql_file .= "\n";
		$sql_file .= "\n";
		$sql_file .= "#\n";
		$sql_file .= "# Table structure of table " . $this->sql_backquote( $table ) . "\n";
		$sql_file .= "#\n";
		$sql_file .= "\n";

		// Get table structure
		$query = 'SHOW CREATE TABLE ' . $this->sql_backquote( $table );

		$result = mysqli_query($link, $query );

		if ( $result ) {

			if ( mysqli_num_rows( $result ) > 0 ) {
				$sql_create_arr = mysqli_fetch_array( $result );
				$sql_file .= $sql_create_arr[1];
			}

			mysqli_free_result( $result );
			$sql_file .= ' ;';

		}


		/* Table Contents */

		// Get table contents
		$query = 'SELECT * FROM ' . $this->sql_backquote( $table );
		$result = mysqli_query($link, $query);

//			self::dump($result);

		if ( $result ) {
			$fields_cnt = mysqli_num_fields($result );
			$rows_cnt   = mysqli_num_rows( $result);
		}

		// Comment in SQL-file
		$sql_file .= "\n";
		$sql_file .= "\n";
		$sql_file .= "#\n";
		$sql_file .= "# Data contents of table " . $table . " (" . $rows_cnt . " records)\n";
		$sql_file .= "#\n";

		// Checks whether the field is an integer or not
		for ( $j = 0; $j < $fields_cnt; $j++ ) {
			error_log('$j '.$j);
			$table_info = mysqli_fetch_field_direct( $result, $j );
			error_log('$table_info '.print_r($table_info,1));

			$field_set[$j] = $this->sql_backquote( $table_info->name );
			$type = $table_info->type;

			//if ( $type === 'tinyint' || $type === 'smallint' || $type === 'mediumint' || $type === 'int' || $type === 'bigint'  || $type === 'timestamp')
			# Remove timestamp to avoid error while restore
			if ( $type === 'tinyint' || $type === 'smallint' || $type === 'mediumint' || $type === 'int' || $type === 'bigint')
				$field_num[$j] = true;

			else
				$field_num[$j] = false;

		}

		// Sets the scheme
		$entries = 'INSERT INTO ' . $this->sql_backquote( $table ) . ' VALUES (';
		$search   = array( '\x00', '\x0a', '\x0d', '\x1a' );  //\x08\\x09, not required
		$replace  = array( '\0', '\n', '\r', '\Z' );
		$current_row = 0;
		$batch_write = 0;

		while ( $row = mysqli_fetch_row( $result ) ) {

			$current_row++;

			// build the statement
			for ( $j = 0; $j < $fields_cnt; $j++ ) {

				if ( ! isset($row[$j] ) ) {
					$values[]     = 'NULL';

				} elseif ( $row[$j] === '0' || $row[$j] !== '' ) {

					// a number
					if ( $field_num[$j] )
						$values[] = $row[$j];

					else {
//								str_replace($search, $replace, $this->sql_addslashes($row[$j])
						$value = mysqli_real_escape_string($link, $row[$j]);
						$values[] = "'" . $value . "'";
					}

				} else {
					$values[] = "''";

				}

			}

			$sql_file .= " \n" . $entries . implode( ', ', $values ) . ") ;";

			// write the rows in batches of 100
			if ( $batch_write === 100 ) {
				$batch_write = 0;
				$this->write_sql( $sql_file );
				$sql_file = '';
			}

			$batch_write++;
			unset( $values );

		}

		mysqli_free_result( $result );

		// Create footer/closing comment in SQL-file
		$sql_file .= "\n";
		$sql_file .= "#\n";
		$sql_file .= "# End of data contents of table " . $table . "\n";
		$sql_file .= "# --------------------------------------------------------\n";
		$sql_file .= "\n";
//		self::dump($sql_file);
		$this->write_sql( $sql_file );

	}

	/**
	 * Write the SQL file
	 *
	 * @access private
	 * @param string $sql
	 * @return bool
	 */
	private function write_sql( $sql ) {
		if(!fwrite( $this->handle, $sql ))
			return false;

		return true;

//	    }

	}

	private function write_out($data) {
		$written = fwrite( $this->handle, $data );

		if ( ! $written )
			throw new backupSavvy_MySQLDump_Exception( __( 'Error while writing file!', 'backwpup' ) );
	}

	/**
	 * Add backquotes to tables and db-names in SQL queries. Taken from phpMyAdmin.
	 *
	 * @access private
	 * @param mixed $a_name
	 * @return string|array
	 */
	private function sql_backquote( $a_name ) {


		if ( ! empty( $a_name ) && $a_name !== '*' ) {

			if ( is_array( $a_name ) ) {

				$result = array();

				reset( $a_name );

				while ( list( $key, $val ) = each( $a_name ) )
					$result[$key] = '`' . $val . '`';

				return $result;

			} else {

				return '`' . $a_name . '`';

			}

		} else {

			return $a_name;

		}

	}


	private function trailingslashit($string) {
		return $this->untrailingslashit($string) . '/';
	}

	private function untrailingslashit($string) {
		return rtrim($string, '/');
	}

	/**
	 * Closes all confections on shutdown.
	 */
	public function __destruct() {

		//close MySQL connection
		$this->mysqli->close();
		//close file handle
		if ( is_resource( $this->handle ) )
			fclose( $this->handle );
	}

	/************************************ END *****************************/
}

class backupSavvy_MySQLDump_Exception extends Exception {

//	public function __construct($string = '') {
//		var_dump($string);
//	}
}
?>