<?php
/**
 * Does all the tree daemon propagation job; intended to run on the command line.
 * @author Felipe Reis <felipe.reis@serpro.gov.br>
 * @author Rodrigo Dias <rodrigo.dias@serpro.gov.br>
 */

require('Connection.class.php');

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

function ExitError($msg)
{
	error_log($msg, 0); // also log to Apache or whatever is running
	exit($msg."\n");   // halt script
}

function CalcStatusByChildrenWeight($dbh, $serviceId, $sumWeight, $depth=0)
{
	// Returned status codes are:
	// 0:normal      3:average
	// 1:information 4:major
	// 2:alert       5:critical
	$stmt = Connection::Query($dbh, '
		SELECT *
		FROM service_threshold
		WHERE idservice = ?
	', $serviceId);
	$status = 0;
	if($row = $stmt->fetch(PDO::FETCH_NUM)) {
		for($i = 1; $i <= 6; ++$i) { // 1st column is idservice, followed by 6 thresholds
			if((double)$sumWeight >= (double)$row[$i])
				$status = $i - 1;
		}
	} else {
		ExitError("ERROR: Service $serviceId has no entry on service_threshold table.");
	}
	Debug("$serviceId has status $status with a sum weight of $sumWeight.", $depth);
	return $status;
}

function GetWeightOfStatus($dbh, $serviceId, $serviceStatus, $depth=0)
{
	// What's the weight of the given status?
	// Status codes ($serviceStatus parameter) are:
	// 0:normal      3:average
	// 1:information 4:major
	// 2:alert       5:critical
	$stmt = Connection::Query($dbh, '
		SELECT *
		FROM service_weight
		WHERE idservice = ?
	', $serviceId);
	$weight = 0;
	if($row = $stmt->fetch(PDO::FETCH_NUM)) {
		$weight = $row[$serviceStatus + 1]; // 1st column is idservice, followed by 6 weights
	} else {
		ExitError("ERROR: Service $serviceId has no entry on service_weight table.");
	}
	Debug("$serviceId has weight $weight with status $serviceStatus.", $depth);
	return $weight;
}

function ProcessNode($dbh, $serviceId, $serviceName, $serviceStatus, $depth=0)
{
	Debug("$serviceId ($serviceName) on ProcessNode.", $depth);

	$stmt = Connection::Query($dbh, '
		SELECT sl.servicedownid AS serviceid, s.name, s.status
		FROM services s
		INNER JOIN services_links sl ON sl.servicedownid = s.serviceid
		WHERE sl.serviceupid = ?
	', $serviceId); // all our child services

	$weight = 0.0;
	if($row = $stmt->fetch(PDO::FETCH_ASSOC)) { // we have children services
		do {
			$weight += ProcessNode($dbh, $row['serviceid'], $row['name'], $row['status'], $depth + 1);
		} while($row = $stmt->fetch(PDO::FETCH_ASSOC));
		$newStatus = CalcStatusByChildrenWeight($dbh, $serviceId, $weight, $depth);
		if($serviceStatus != $newStatus) { // our status has changed due to children weight propagation
			Connection::Query($dbh, '
				UPDATE services
				SET status = ?
				WHERE serviceid = ?
			', $newStatus, $serviceId);
		}
		$weight = GetWeightOfStatus($dbh, $serviceId, $newStatus, $depth);
	} else { // we're a leaf node
		Debug("$serviceId is a leaf node.", $depth);
		$weight = GetWeightOfStatus($dbh, $serviceId, $serviceStatus, $depth);
	}
	return $weight;
}

function UpdateServiceTree($dbh)
{
	Debug('Updating tree.');

	// Retrieve the root service nodes.
	$stmt = Connection::Query($dbh, '
		SELECT DISTINCT(serviceid), name, status
		FROM services AS s
		INNER JOIN services_links AS l ON s.serviceid = l.serviceupid
		WHERE NOT EXISTS (
			SELECT *
			FROM services_links
			WHERE servicedownid = s.serviceid
		)');

	if($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		do {
			ProcessNode($dbh, $row['serviceid'], $row['name'], $row['status']); // process each root node
		} while($row = $stmt->fetch(PDO::FETCH_ASSOC));
	} else {
		ExitError('ERROR: No root nodes were found.');
	}
}

//
// Start processing.
//

$dbh = Connection::GetDatabase();
UpdateServiceTree($dbh);