<?php

class bsv_backup_restore_new {
	private $Package;
	/**
	 * array of ini disable functions
	 *
	 * @var array
	 */
	private static $iniDisableFuncs = null;


	public function __construct($package) {
		$this->Package = $package;
		$this->mysqlDump('');
	}

	private function mysqlDump( $exePath ) {
		global $wpdb;
		require_once( DUPLICATOR_PLUGIN_PATH . 'classes/utilities/class.u.shell.php' );

		$host           = explode( ':', DB_HOST );
		$host           = reset( $host );
		$port           = strpos( DB_HOST, ':' ) ? end( explode( ':', DB_HOST ) ) : '';
		$name           = DB_NAME;
		$mysqlcompat_on = isset( $this->Compatible ) && strlen( $this->Compatible );

		//Build command
		$cmd = escapeshellarg( $exePath );
		$cmd .= ' --no-create-db';
		$cmd .= ' --single-transaction';
		$cmd .= ' --hex-blob';
		$cmd .= ' --skip-add-drop-table';
		$cmd .= ' --routines';
		$cmd .= ' --quote-names';
		$cmd .= ' --skip-comments';
		$cmd .= ' --skip-set-charset';
		$cmd .= ' --allow-keywords';

		//Compatibility mode
		if ( $mysqlcompat_on ) {
			DUP_Log::Info( "COMPATIBLE: [{$this->Compatible}]" );
			$cmd .= " --compatible={$this->Compatible}";
		}

		//Filter tables
		$res        = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
		$tables     = array();
		$baseTables = array();
		foreach ( $res as $row ) {
			if ( self::isTableExists( $row[0] ) ) {
				$tables[] = $row[0];
				if ( 'BASE TABLE' == $row[1] ) {
					$baseTables[] = $row[0];
				}
			}
		}
		$filterTables = isset( $this->FilterTables ) ? explode( ',', $this->FilterTables ) : null;
		$tblAllCount  = count( $tables );

		foreach ( $tables as $table ) {
			if ( in_array( $table, $baseTables ) ) {
				$row_count = $GLOBALS['wpdb']->get_var( "SELECT Count(*) FROM `{$table}`" );
				$rewrite_table_as = $this->rewriteTableNameAs( $table );
//				$this->Package->Database->info->tableWiseRowCounts[ $rewrite_table_as ] = $row_count;
			}
		}
		//$tblFilterOn  = ($this->FilterOn) ? 'ON' : 'OFF';

		if ( is_array( $filterTables ) && $this->FilterOn ) {
			foreach ( $tables as $key => $val ) {
				if ( in_array( $tables[ $key ], $filterTables ) ) {
					$cmd .= " --ignore-table={$name}.{$tables[$key]} ";
					unset( $tables[ $key ] );
				}
			}
		}

		$cmd .= ' -u ' . escapeshellarg( DB_USER );
		$cmd .= ( DB_PASSWORD ) ?
			' -p' . self::escapeshellargWindowsSupport( DB_PASSWORD ) : '';

		$cmd .= ' -h ' . escapeshellarg( $host );
		$cmd .= ( ! empty( $port ) && is_numeric( $port ) ) ?
			' -P ' . $port : '';

		$isPopenEnabled = self::isPopenEnabled();

		if ( ! $isPopenEnabled ) {
			$cmd .= ' -r ' . escapeshellarg( $this->dbStorePath );
		}

		$cmd .= ' ' . escapeshellarg( DB_NAME );
		$cmd .= ' 2>&1';

		if ( $isPopenEnabled ) {
			$needToRewrite = false;
			foreach ( $tables as $tableName ) {
				$rewriteTableAs = $this->rewriteTableNameAs( $tableName );
				if ( $tableName != $rewriteTableAs ) {
					$needToRewrite = true;
					break;
				}
			}

			if ( $needToRewrite ) {
				$findReplaceTableNames = array(); // orignal table name => rewrite table name

				foreach ( $tables as $tableName ) {
					$rewriteTableAs = $this->rewriteTableNameAs( $tableName );
					if ( $tableName != $rewriteTableAs ) {
						$findReplaceTableNames[ $tableName ] = $rewriteTableAs;
					}
				}
			}

			$firstLine = '';
			DUP_LOG::trace( "Executing mysql dump command by popen: $cmd" );
			$handle = popen( $cmd, "r" );
			if ( $handle ) {
				$sql_header = "/* DUPLICATOR-LITE (MYSQL-DUMP BUILD MODE) MYSQL SCRIPT CREATED ON : " . @date( "Y-m-d H:i:s" ) . " */\n\n";
				file_put_contents( $this->dbStorePath, $sql_header, FILE_APPEND );
				while ( ! feof( $handle ) ) {
					$line = fgets( $handle ); //get ony one line
					if ( $line ) {
						if ( empty( $firstLine ) ) {
							$firstLine = $line;
							if ( false !== stripos( $line, 'Using a password on the command line interface can be insecure' ) ) {
								continue;
							}
						}

						if ( $needToRewrite ) {
							$replaceCount = 1;

							if ( preg_match( '/CREATE TABLE `(.*?)`/', $line, $matches ) ) {
								$tableName = $matches[1];
								if ( isset( $findReplaceTableNames[ $tableName ] ) ) {
									$rewriteTableAs = $findReplaceTableNames[ $tableName ];
									$line           = str_replace( 'CREATE TABLE `' . $tableName . '`', 'CREATE TABLE `' . $rewriteTableAs . '`', $line, $replaceCount );
								}
							} elseif ( preg_match( '/INSERT INTO `(.*?)`/', $line, $matches ) ) {
								$tableName = $matches[1];
								if ( isset( $findReplaceTableNames[ $tableName ] ) ) {
									$rewriteTableAs = $findReplaceTableNames[ $tableName ];
									$line           = str_replace( 'INSERT INTO `' . $tableName . '`', 'INSERT INTO `' . $rewriteTableAs . '`', $line, $replaceCount );
								}
							} elseif ( preg_match( '/LOCK TABLES `(.*?)`/', $line, $matches ) ) {
								$tableName = $matches[1];
								if ( isset( $findReplaceTableNames[ $tableName ] ) ) {
									$rewriteTableAs = $findReplaceTableNames[ $tableName ];
									$line           = str_replace( 'LOCK TABLES `' . $tableName . '`', 'LOCK TABLES `' . $rewriteTableAs . '`', $line, $replaceCount );
								}
							}
						}

						file_put_contents( $this->dbStorePath, $line, FILE_APPEND );
						$output = "Ran from {$exePath}";
					}
				}
				$ret = pclose( $handle );
			} else {
				$output = '';
			}

			// Password bug > 5.6 (@see http://bugs.mysql.com/bug.php?id=66546)
			if ( empty( $output ) && trim( $firstLine ) === 'Warning: Using a password on the command line interface can be insecure.' ) {
				$output = '';
			}
		} else {
			DUP_LOG::trace( "Executing mysql dump command $cmd" );
			$output = shell_exec( $cmd );

			// Password bug > 5.6 (@see http://bugs.mysql.com/bug.php?id=66546)
			if ( trim( $output ) === 'Warning: Using a password on the command line interface can be insecure.' ) {
				$output = '';
			}
			$output = ( strlen( $output ) ) ? $output : "Ran from {$exePath}";

			$tblCreateCount = count( $tables );
			$tblFilterCount = $tblAllCount - $tblCreateCount;

			//DEBUG
			//DUP_Log::Info("COMMAND: {$cmd}");
			DUP_Log::Info( "FILTERED: [{$this->FilterTables}]" );
			DUP_Log::Info( "RESPONSE: {$output}" );
			DUP_Log::Info( "TABLES: total:{$tblAllCount} | filtered:{$tblFilterCount} | create:{$tblCreateCount}" );
		}

		$sql_footer = "\n\n/* Duplicator WordPress Timestamp: " . date( "Y-m-d H:i:s" ) . "*/\n";
		$sql_footer .= "/* " . DUPLICATOR_DB_EOF_MARKER . " */\n";
		file_put_contents( $this->dbStorePath, $sql_footer, FILE_APPEND );

		return ( $output ) ? false : true;
	}

	/**
	 * Check given table is exist in real
	 *
	 * @param $table string Table name
	 * @return booleam
	 */
	private static function isTableExists($table)
	{
		// It will clear the $GLOBALS['wpdb']->last_error var
		$GLOBALS['wpdb']->flush();
		$sql = "SELECT 1 FROM ".esc_sql($table)." LIMIT 1;";
		$ret = $GLOBALS['wpdb']->get_var($sql);
		if (empty($GLOBALS['wpdb']->last_error))   return true;
		return false;
	}

	private function rewriteTableNameAs($table)
	{
		$table_prefix = $this->getTablePrefix();
		if (!isset($this->sameNameTableExists)) {
			global $wpdb;
			$this->sameNameTableExists = false;
			$all_tables = $wpdb->get_col("SHOW FULL TABLES WHERE Table_Type != 'VIEW'");
			foreach ($all_tables as $table_name) {
				if (strtolower($table_name) != $table_name && in_array(strtolower($table_name), $all_tables)) {
					$this->sameNameTableExists = true;
					break;
				}
			}
		}
		if (false === $this->sameNameTableExists && 0 === stripos($table, $table_prefix) && 0 !== strpos($table, $table_prefix)) {
			$post_fix = substr($table, strlen($table_prefix));
			$rewrite_table_name = $table_prefix.$post_fix;
		} else {
			$rewrite_table_name = $table;
		}
		return $rewrite_table_name;
	}

	private function getTablePrefix() {
		global $wpdb;
		$table_prefix = (is_multisite() && !defined('MULTISITE')) ? $wpdb->base_prefix : $wpdb->get_blog_prefix(0);
		return $table_prefix;
	}


	/**
	 * Escape a string to be used as a shell argument with bypass support for Windows
	 *
	 * 	NOTES:
	 * 		Provides a way to support shell args on Windows OS and allows %,! on Windows command line
	 * 		Safe if input is know such as a defined constant and not from user input escape shellarg
	 * 		on Windows with turn %,! into spaces
	 *
	 * @return string
	 */
	public static function escapeshellargWindowsSupport($string)
	{
		if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
			if (strstr($string, '%') || strstr($string, '!')) {
				$result = '"'.str_replace('"', '', $string).'"';
				return $result;
			}
		}
		return escapeshellarg($string);
	}

	/**
	 *
	 * @return boolean
	 *
	 */
	public static function isPopenEnabled() {

		if (!self::isIniFunctionEnalbe('popen') || !self::isIniFunctionEnalbe('proc_open')) {
			$ret = false;
		} else {
			$ret = true;
		}

		$ret = apply_filters('duplicator_pro_is_popen_enabled', $ret);
		return $ret;
	}

	/**
	 * Check if function exists and isn't in ini disable_functions
	 *
	 * @param string $function_name
	 * @return bool
	 */
	public static function isIniFunctionEnalbe($function_name)
	{
		return function_exists($function_name) && !in_array($function_name, self::getIniDisableFuncs());
	}

	/**
	 * return ini disable functions array
	 *
	 * @return array
	 */
	public static function getIniDisableFuncs()
	{
		if (is_null(self::$iniDisableFuncs)) {
			$tmpFuncs				 = ini_get('disable_functions');
			$tmpFuncs				 = explode(',', $tmpFuncs);
			self::$iniDisableFuncs	 = array();
			foreach ($tmpFuncs as $cFunc) {
				self::$iniDisableFuncs[] = trim($cFunc);
			}
		}

		return self::$iniDisableFuncs;
	}

}
