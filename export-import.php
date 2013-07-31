<?php
/**
 * Export/import the tree structure; intended to run on the command line.
 * @author Rodrigo Dias <rodrigo.dias@serpro.gov.br>
 */

require('Database.class.php');

// Note: if, when running from command line, you're having output messages like:
//  "PHP Deprecated: Comments starting with '#' are deprecated (...)"
//  you must edit the files and replace the '#' comments by ';'.

$debug = true; // enables debug messages


function Debug($msg, $depth=0)
{
	global $debug;
	if($debug)
		echo date('Y-m-d H:i:s').str_repeat('  ', $depth).' '.$msg."\n";
}

class Export
{
	private static function _ExportNode(Database $db, $serviceId, $depth=0)
	{
		Debug("$serviceId on ExportNode.", $depth);

		// Retrieve all information about this service node.
		try {
			$stmt = $db->query('
				SELECT s.serviceid, s.name, s.status, s.algorithm, s.showsla, s.goodsla, s.sortorder,
					si.idicon,
					sw.weight_normal, sw.weight_information, sw.weight_alert,
					sw.weight_average, sw.weight_major, sw.weight_critical,
					st.threshold_normal, st.threshold_information, st.threshold_alert,
					st.threshold_average, st.threshold_major, st.threshold_critical
				FROM services s
				LEFT JOIN service_icon si ON si.idservice = s.serviceid
				INNER JOIN service_weight sw ON sw.idservice = s.serviceid
				INNER JOIN service_threshold st ON st.idservice = s.serviceid
				WHERE s.serviceid = ?
			', $serviceId);
		} catch(Exception $e) {
			die('ERROR: '.$e->getMessage()."\n");
		}

		$node = null;
		if($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$node = (object)array(
				//'serviceid' => $row['serviceid'],
				'name'      => $row['name'],
				'status'    => (int)$row['status'],
				'algorithm' => (int)$row['algorithm'],
				'showsla'   => (int)$row['showsla'],
				'goodsla'   => (double)$row['goodsla'],
				'sortorder' => (int)$row['sortorder'],
				//'icon'      => $row['idicon'],
				'weight'    => (object)array(
					'normal'      => (double)$row['weight_normal'],
					'information' => (double)$row['weight_information'],
					'alert'       => (double)$row['weight_alert'],
					'average'     => (double)$row['weight_average'],
					'major'       => (double)$row['weight_major'],
					'critical'    => (double)$row['weight_critical']
				),
				'threshold' => (object)array(
					'normal'      => (double)$row['threshold_normal'],
					'information' => (double)$row['threshold_information'],
					'alert'       => (double)$row['threshold_alert'],
					'average'     => (double)$row['threshold_average'],
					'major'       => (double)$row['threshold_major'],
					'critical'    => (double)$row['threshold_critical']
				),
				'children'  => array()
			);
		} else {
			$stmt = $db->query('SELECT name FROM services WHERE serviceid = ?', $serviceId);
			$row = $stmt->fetch(PDO::FETCH_NUM);
			die("ERROR: Service \"$row[0]\" ($serviceId) has no entry on service_weight table.\n");
		}

		// Retrieve all child services of current service node.
		try {
			$stmt = $db->query('
				SELECT sl.servicedownid AS serviceid, s.name, s.status
				FROM services s
				INNER JOIN services_links sl ON sl.servicedownid = s.serviceid
				WHERE sl.serviceupid = ?
			', $serviceId);
		} catch(Exception $e) {
			die('ERROR: '.$e->getMessage()."\n");
		}

		if($row = $stmt->fetch(PDO::FETCH_ASSOC)) { // we have children services
			do {
				$node->children[] = self::_ExportNode($db, $row['serviceid'], $depth + 1);
			} while($row = $stmt->fetch(PDO::FETCH_ASSOC));
		} else { // we're a leaf node
			Debug("$serviceId is a leaf node.", $depth);
		}

		return $node;
	}

	public static function ExportServiceTree(Database $db)
	{
		Debug('Exporting tree.');

		// Retrieve the root service nodes.
		try {
			$stmt = $db->query('
				SELECT DISTINCT(serviceid), name, status
				FROM services AS s
				INNER JOIN services_links AS l ON s.serviceid = l.serviceupid
				WHERE NOT EXISTS (
					SELECT *
					FROM services_links
					WHERE servicedownid = s.serviceid
				)');
		} catch(Exception $e) {
			die('ERROR: '.$e->getMessage()."\n");
		}

		$services = array();
		if($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			do {
				$services[] = self::_ExportNode($db, $row['serviceid']); // process each root node
			} while($row = $stmt->fetch(PDO::FETCH_ASSOC));
		} else {
			die("ERROR: No root nodes were found.\n");
		}
		return $services;
	}
}

class Import
{
	private static function _CheckSingleTable(Database $db, $tableName, $creationSql)
	{
		if(!$db->tableExists($tableName)) {
			try {
				$db->query($creationSql);
			} catch(Exception $e) {
				die('ERROR: '.$e->getMessage()."\n");
			}
			Debug("Table $tableName created.");
		} else {
			Debug("Table $tableName already exists.");
		}
	}

	private static function _CheckNewTables(Database $db)
	{
		self::_CheckSingleTable($db, 'service_threshold', '
			CREATE TABLE service_threshold (
				idservice             BIGINT(20) UNSIGNED NOT NULL,
				threshold_normal      DOUBLE PRECISION DEFAULT NULL,
				threshold_information DOUBLE PRECISION DEFAULT NULL,
				threshold_alert       DOUBLE PRECISION DEFAULT NULL,
				threshold_average     DOUBLE PRECISION DEFAULT NULL,
				threshold_major       DOUBLE PRECISION DEFAULT NULL,
				threshold_critical    DOUBLE PRECISION DEFAULT NULL,
				PRIMARY KEY (idservice)
			)
		');
		self::_CheckSingleTable($db, 'service_weight', '
			CREATE TABLE service_weight (
				idservice          BIGINT(20) UNSIGNED NOT NULL,
				weight_normal      DOUBLE PRECISION DEFAULT NULL,
				weight_information DOUBLE PRECISION DEFAULT NULL,
				weight_alert       DOUBLE PRECISION DEFAULT NULL,
				weight_average     DOUBLE PRECISION DEFAULT NULL,
				weight_major       DOUBLE PRECISION DEFAULT NULL,
				weight_critical    DOUBLE PRECISION DEFAULT NULL,
				PRIMARY KEY (idservice)
			)
		');
		self::_CheckSingleTable($db, 'service_icon', '
			CREATE TABLE service_icon (
				idservice BIGINT(20) UNSIGNED NOT NULL,
				idicon    BIGINT(20) UNSIGNED NOT NULL,
				PRIMARY KEY (idservice)
			)
		');
	}

	private static $addedNodes = array();
	private static function _Rollback(Database $db)
	{
		echo "Oops, something wrong, let's rollback...\n";
		try {
			foreach(self::$addedNodes as $sid) {
				$db->query('DELETE FROM service_threshold WHERE idservice = ?', $sid);
				$db->query('DELETE FROM service_weight WHERE idservice = ?', $sid);
				$db->query('DELETE FROM services_links WHERE serviceupid = ? OR servicedownid = ?', $sid, $sid);
				$db->query('DELETE FROM services_times WHERE serviceid = ?', $sid);
				$db->query('DELETE FROM services WHERE serviceid = ?', $sid);
				Debug("Service $sid deleted.");
			}
		} catch(Exception $e) {
			die('ERROR: '.$e->getMessage()."\n");
		}
	}

	private static function _GetDistributedNodePrefix($db)
	{
		try {
			$stmt = $db->query("
				SELECT MIN(nodeid)
				FROM ids
				WHERE table_name = 'services'
					AND field_name = 'serviceid'
			");
			if($row = $stmt->fetch(PDO::FETCH_NUM)) {
				return $row[0]; // 0 to 999
			} else {
				self::_Rollback($db);
				die("ERROR: failed to retrieve distributed node prefix.\n");
			}
		} catch(Exception $e) {
			self::_Rollback($db);
			die('ERROR: '.$e->getMessage()."\n");
		}
	}

	private static function _ImportNode(Database $db, $service, $depth=0)
	{
		Debug("$service->name on ImportNode.", $depth);

		// Create the service itself.
		$prefixId = self::_GetDistributedNodePrefix($db);
		try {
			$serviceId = $db->getNextId($prefixId, 'services', 'serviceid');
			$db->query('
				INSERT INTO services
					(serviceid, name, status, algorithm, showsla, goodsla, sortorder)
				VALUES
					(?, ?, ?, ?, ?, ?, ?)
			', $serviceId,
				$service->name, $service->status, $service->algorithm,
				$service->showsla, $service->goodsla, $service->sortorder);
		} catch(Exception $e) {
			self::_Rollback($db);
			die('ERROR: '.$e->getMessage()."\n");
		}
		self::$addedNodes[] = $serviceId; // append to our rollback array
		Debug("Node $service->name created as $serviceId.", $depth);

		// Insert threshold and weight values.
		try {
			$db->query('
				INSERT INTO service_threshold
					(idservice,
					threshold_normal, threshold_information, threshold_alert,
					threshold_average, threshold_major, threshold_critical)
				VALUES
					(?, ?, ?, ?, ?, ?, ?)
			', $serviceId,
					$service->threshold->normal, $service->threshold->information, $service->threshold->alert,
					$service->threshold->average, $service->threshold->major, $service->threshold->critical);

			$db->query('
				INSERT INTO service_weight
					(idservice,
					weight_normal, weight_information, weight_alert,
					weight_average, weight_major, weight_critical)
				VALUES
					(?, ?, ?, ?, ?, ?, ?)
			', $serviceId,
					$service->weight->normal, $service->weight->information, $service->weight->alert,
					$service->weight->average, $service->weight->major, $service->weight->critical);
		} catch(Exception $e) {
			self::_Rollback($db);
			die('ERROR: '.$e->getMessage()."\n");
		}

		// Create each child service and its relationship.
		foreach($service->children as $child) {
			$childId = self::_ImportNode($db, $child, $depth + 1); // import each child
			Debug("Making $serviceId parent of $childId.", $depth);
			try {
				$linkId = $db->getNextId(substr($serviceId, 0, 3), 'services_links', 'linkid');
				$db->query('
					INSERT INTO services_links
						(linkid, serviceupid, servicedownid, soft)
					VALUES
						(?, ?, ?, 0)
				', $linkId, $serviceId, $childId); // create parent-child relationship
			} catch(Exception $e) {
				self::_Rollback($db);
				die('ERROR: '.$e->getMessage()."\n");
			}
		}

		return $serviceId; // ID of newly created service
	}

	public static function ImportServiceTree(Database $db, $filename)
	{
		self::_CheckNewTables($db);
		$blob = @file_get_contents($filename);
		if($blob === false)
			die("ERROR: could not read from $filename .\n");
		$nodes = json_decode($blob);
		foreach($nodes as $service)
			self::_ImportNode($db, $service); // process each root nodes
	}
}


//
// Start processing.
//

if(!isset($argv[2])) {
	die("*\n".
		"* HOW TO USE THIS SCRIPT:\n".
		"* Export tree structure to a file:\n".
		"*   php export-import.php -e /var/tmp/file.txt\n".
		"* Import tree from a file:\n".
		"*   php export-import.php -i /var/tmp/file.txt\n".
		"*\n");
}

$db = new Database();
$db->connect();

if($argv[1] == '-e') { // export
	echo "Exporting tree to $argv[2] ...\n";
	$json = json_encode(Export::ExportServiceTree($db));
	if(@file_put_contents($argv[2], $json, LOCK_EX) === false)
		die("ERROR: could not write to file, file_put_contents() failed.\n");
} else if($argv[1] == '-i') { // import
	echo "Importing tree from $argv[2] ...\n";
	Import::ImportServiceTree($db, $argv[2]);
}
echo "Finished.\n";