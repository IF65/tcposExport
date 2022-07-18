<?php
@ini_set('memory_limit','8192M');

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GetOpt\GetOpt;
use GetOpt\Option;

// costanti
// -----------------------------------------------------------
$timeZone = new DateTimeZone('Europe/Rome');
$currentDate = (new DateTime('now', $timeZone));

$path = '/Users/if65/Desktop/';
//$path = 'C:\FILES_ITR\\';

/**
 * Creo il client per leggere gli incassi dal servizio sulla VM delle quadrature
 */
$client = new Client([
	'base_uri' => 'http://10.11.14.128/',
	'headers' => ['Content-Type' => 'application/json'],
	'timeout' => 30.0,
]);

/**
 * carico gli incassi in tempo reale e li scrivo su file
 */
try {
	$response = $client->post('/eDatacollect/src/eDatacollect.php',
		['json' =>
			[
				'function' => 'incassiInTempoReale',
				'data' => $currentDate->format('Y-m-d')
			]
		]
	);
	if ($response->getStatusCode() == 200) {
		$incassi = json_decode($response->getBody()->getContents(), true);

		$text = '';
		foreach ($incassi as $incasso) {
			$text .= sprintf("%sT00:00:00%04s%012.2f%06d\n", $incasso['ddate'], $incasso['store'], (float)$incasso['totalamount'], (int)$incasso['customerCount']);
		}

		$fileName = 'itr_' . (new DateTime('now', $timeZone))->getTimestamp();
		file_put_contents("$path$fileName.txt", $text);
		file_put_contents("$path$fileName.ctl", "");
	}
} catch (PDOException $e) {
	echo "Errore: " . $e->getMessage();
	die();
}


try {
	$db = new PDO("mysql:host=10.11.14.248", 'root', 'mela', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

	// controllo che ci siano dei record nella giornata in caricamento. Asar va' caricato prima di TcPos
	$stmt = "	select 
       				store, 
       				ddate, 
       				case when totalamount <> 0 then totalamount else salesamount end totalamount, 
       				customercount,
	       			totalhours,
	       			closed
				from mtx.control 
				where ddate >= date_sub(current_date(), interval 2 week) and ddate < current_date() 
				union
				select 
				       '0500' store, 
				       ddate, 
				       sum(totaltaxableamount) totalamount, 
				       count(distinct reg, trans) customercount, 
				       0 totalhours, 
				       0 closed
				from mtx.sales where store = '0501' and reg >= '021' and reg <= '049' and ddate >= date_sub(current_date(), interval 2 week) and ddate < current_date() 
				group by 1,2";
	$h_query = $db->prepare($stmt);
	$h_query->execute();
	$rows = $h_query->fetchAll(\PDO::FETCH_ASSOC);
	if (count($rows)) {

		$text = '';
		foreach($rows as $row) {
			$text .= sprintf("%sT00:00:00%04s%012.2f%06d%06.1f%01d\n",
				$row['ddate'],
				$row['store'],
				(float)$row['totalamount'],
				(int)$row['customercount'],
				(float)$row['totalhours'],
				(int)$row['closed']
			);
		}

		$fileName =  'quad_' . (new DateTime('now', $timeZone))->getTimestamp();
		file_put_contents("$path$fileName.txt", $text );
		file_put_contents("$path$fileName.ctl", "" );
	}
} catch (PDOException $e) {
	echo "Errore: " . $e->getMessage();
	die();
}





