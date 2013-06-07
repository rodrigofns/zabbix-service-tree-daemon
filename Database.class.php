<?php
/**
 * Manages a Zabbix database connection.
 * @author Rodrigo Dias <rodrigo.dias@serpro.gov.br>
 */

class Database
{
	public $pdo = null;

	/**
	 * Creates the PDO object according to Zabbix frontend config file.
	 * @return PDO
	 */
	public function connect()
	{
		$dbConf = self::_LoadZabbixConf();
		$pdoStr = sprintf('%s:host=%s;dbname=%s',
		strtolower($dbConf['TYPE']), $dbConf['SERVER'], $dbConf['DATABASE']);
		try {
			$this->pdo = new PDO($pdoStr, $dbConf['USER'], $dbConf['PASSWORD']);
			$this->pdo->exec('SET NAMES utf8');
		} catch(PDOException $e) {
			exit($e->getMessage()); // halt script
		}
		return $this->pdo; // returns a PDO connection object
	}

	/**
	 * Runs a sprintf-like SQL query.
	 * @param string SQL string with "?" marks to bindParam() calls.
	 * @param mixed  Variables to be replaced by bindParam().
	 * @return PDO::Statement
	 * @throws Exception
	 */
	public function query()
	{
		// Usage example:
		// $stmt = $db->query('SELECT * FROM person WHERE id = ? AND age = ?', 1, 32);
		$args = func_get_args();
		$stmt = $this->pdo->prepare($args[0]);
		for($i = 1; $i < count($args); ++$i)
			$stmt->bindParam($i, $args[$i]); // param index is one-based
		if(!$stmt->execute()) {
			$err = $stmt->errorInfo();
			throw new Exception("SQL ERROR:\n{$stmt->queryString}\n$err[0] $err[2]");
		}
		return $stmt; // ready to call $stmt->fetch()
	}

	/**
	 * Checks whether a given table exists.
	 * @param string $tableName Name of table to be checked.
	 * @return boolean
	 */
	public function tableExists($tableName)
	{
		$stmt = $this->pdo->prepare('SELECT 1 FROM '.$tableName);
		if(!$stmt->execute())
			return false;
		return $stmt->fetch(PDO::FETCH_NUM) != false;
	}

	/**
	 * Retrieves the next ID of the given field; ID will be incremented on table.
	 * @param int    $nodeId    ID of node to be applied.
	 * @param string $tableName Name of table to be queried.
	 * @param string $fieldName Name of field.
	 * @return string
	 * @throws Exception
	 */
	public function getNextId($nodeId, $tableName, $fieldName)
	{
		$stmt = $this->query('
			SELECT nextid
			FROM ids
			WHERE nodeid = ?
				AND table_name = ?
				AND field_name = ?
		', $nodeId, $tableName, $fieldName); // http://fossies.org/dox/zabbix-2.0.6/DB_8php_source.html
		if($row = $stmt->fetch(PDO::FETCH_NUM)) {
			$yourId = $row[0];
			$this->query('
				UPDATE ids
				SET nextid = nextid + 1
				WHERE nodeid = ?
					AND table_name = ?
					AND field_name = ?
			', $nodeId, $tableName, $fieldName); // update the ID in the table to keep consistency
		} else {
			throw new Exception("ERROR: Failed to fetch next ID of $tableName\\$fieldName ($nodeId).\n");
		}
		return (string)bcadd($yourId, 1, 0);
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