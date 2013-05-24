<?php
/**
 * Manages database connections.
 * @author Rodrigo Dias <rodrigo.dias@serpro.gov.br>
 */

class Connection
{
	/**
	 * Returns a PDO object connecting to database, according to Zabbix frontend config file.
	 * @return PDO
	 */
	public static function GetDatabase()
	{
		$dbConf = self::_LoadZabbixConf();
		$pdoStr = sprintf('%s:host=%s;dbname=%s',
		strtolower($dbConf['TYPE']), $dbConf['SERVER'], $dbConf['DATABASE']);
		try {
			$dbh = new PDO($pdoStr, $dbConf['USER'], $dbConf['PASSWORD']);
			$dbh->exec('SET NAMES utf8');
		} catch(PDOException $e) {
			exit($e->getMessage());
		}
		return $dbh; // returns a PDO connection object
	}

	/**
	 * Runs a sprintf-like SQL query and exits script if an error occurs.
	 * @param PDO    Database connection handle.
	 * @param string SQL string with "?" marks to bindParam() calls.
	 * @param mixed  Variables to be replaced by bindParam().
	 * @return PDO::Statement
	 */
	public static function Query()
	{
		// Usage example:
		// $stmt = Query($dbh, 'SELECT * FROM person WHERE id = ? AND age = ?', 1, 32);
		$args = func_get_args();
		$stmt = $args[0]->prepare($args[1]);
		for($i = 2; $i < count($args); ++$i)
			$stmt->bindParam($i - 1, $args[$i]);
		if(!$stmt->execute()) {
			$err = $stmt->errorInfo();
			exit("SQL ERROR:\n{$stmt->queryString}\n$err[0] $err[2]");
		}
		return $stmt; // ready to call $stmt->fetch()
	}

	/**
	 * Returns the parsed Zabbix frontend config file.
	 * @return array
	 */
	private static function _LoadZabbixConf()
	{
		include(dirname(__FILE__).'/__conf.php'); // read from our conf file
		if(!is_readable($ZABBIX_CONF))
			exit("ERROR: Failed to read Zabbix conf, see __conf.php.\n");
		include($ZABBIX_CONF); // use variables declared in Zabbix frontend conf file
		if(!isset($DB))
			exit('ERROR: Zabbix conf looks wrong, see __conf.php.\n');
		$DB['API'] = $ZABBIX_API; // push API URL into return array
		return $DB; // Zabbix associative array with all database settings
	}
}
