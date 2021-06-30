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
$yesterday = $currentDate->sub(new DateInterval('P1D'));

$options = new GetOpt([
	Option::create('i', 'inizio', GetOpt::REQUIRED_ARGUMENT )
		->setDescription("Data inizio caricamento. (Default ".$yesterday->format('Y-m-d').").")
		->setDefaultValue($yesterday->format('Y-m-d'))->setValidation(function ($value) {
			return (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $value)) ? $value : '';
		}),
	Option::create('f', 'fine', GetOpt::OPTIONAL_ARGUMENT )
		->setDescription('Data fine caricamento. (Se mancante viene presa come data di fine la data d\'inizio).')->setValidation(function ($value) {
			return (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $value)) ? $value : '';
		}),
	Option::create('s', 'sede', GetOpt::REQUIRED_ARGUMENT )
		->setDescription('Sede da caricare.')->setValidation(function ($value) {
			return (preg_match('/^\d{4}$/', $value)) ? $value : '';
		}),
	Option::create('d', 'debug', GetOpt::NO_ARGUMENT )
		->setDescription('Imposta modalità debug.')
]);

try {
	$options->process();
} catch (Missing $exception) {
	throw $exception;
}

$debug = false;
if ($options->getOption('d') != null) {
	$debug = true;
}

$dataInizio = new DateTime($options->getOption('i'), $timeZone);
if ($options->getOption('f') != null) {
	$dataFine = new DateTime($options->getOption('f'), $timeZone);
} else {
	$dataFine = new DateTime($options->getOption('i'), $timeZone);
}
$sede = $options->getOption('s');


/**
 * Creo il client per leggere i dati da Buffalo
 */
$clientBfl = new Client([
	'base_uri' => 'http://10.11.14.74/',
	'headers' => ['Content-Type' => 'application/json'],
	'timeout' => 60.0,
]);

/**
 * Creo il cliente per leggere i dati dalla VM dei report
 */
$client = new Client([
	'base_uri' => 'http://10.11.14.128/',
	'headers' => ['Content-Type' => 'application/json'],
	'timeout' => 60.0,
]);

$codiciIva = [
	'9931' => 0,
	'0400' => 1,
	'1000' => 2,
	'2200' => 3,
	'0500' => 4,
	'9100' => 5,
	'9300' => 6,
	'7400' => 7
];

$aliquoteIva = [
	0 => 0,
	1 => 4,
	2 => 10,
	3 => 22,
	4 => 5,
	5 => 0,
	6 => 0,
	7 => 0
];

$data = clone $dataInizio;
while ($data <= $dataFine) {

	// procedura
	// -----------------------------------------------------------
	$societa = '';
	$negozio = '';
	if (preg_match('/^(\d\d)(\d\d)$/', $sede, $matches)) {
		$societa = $matches[1];
		$negozio = $matches[2];
	}

	$anno = '';
	$mese = '';
	$giorno = '';
	if (preg_match('/^\d{2}(\d{2})-(\d{2})-(\d{2})$/', $data->format('Y-m-d'), $matches)) {
		$anno = $matches[1];
		$mese = $matches[2];
		$giorno = $matches[3];
	}

	/**
	 * carico le righe fatture dei negozi cash leggendole da Buffalo
	 */
	$response = $clientBfl->post('dwh',
		['json' =>
			[
				'data' => $data->format('Y-m-d'),
				'function' => 'caricamentoCash',
				'sede' => $sede
			]
		]
	);
	if ($response->getStatusCode() == 200) {
		$body = $response->getBody()->getContents();
		$vendite = json_decode($body, true);

		if (substr($sede, 0, 2) == '01') {
			$sede = '00' . substr($sede, 2);
		}

		$dc = [];
		$totaliTransazioni = [];
		$elencoArticoli = [];
		foreach ($vendite as $vendita) {
			$tipoIva = (key_exists($vendita['tipoIva'], $codiciIva)) ? $codiciIva[$vendita['tipoIva']] : 3;
			$imponibile = (float)$vendita['imponibile'];
			$imposta = (float)$vendita['imposta'];
			$importo = (float)$vendita['importo'];

			$peso = 1;
			$pezziPerCartone = (int)$vendita['pezziPerCartone'];
			$quantita = (float)$vendita['quantita'] * ($pezziPerCartone != 0) ? $pezziPerCartone : 1;
			$articoloAPeso = false;
			if ($quantita - floor($quantita) != 0) {
				$peso = $quantita;
				$quantita = 1;
				$articoloAPeso = true;
			}

			$dc[$vendita['numero']]['righe'][] = [
				'codice' => $vendita['codice'],
				'tipoIva' => $tipoIva,
				'aliquotaIva' => (float)$vendita['aliquotaIva'],
				'quantita' => $quantita,
				'peso' => $peso,
				'articoloAPeso' => $articoloAPeso,
				'imponibile' => $imponibile,
				'imposta' => $imposta,
				'importo' => $importo
			];

			/** totali presenti sulla fattura (arrivano direttamente da buffalo)*/
			if (! key_exists('imponibile', $dc[$vendita['numero']])) {
				$dc[$vendita['numero']]['imponibile'] = (float)$vendita['totaleImponibile'];
				$dc[$vendita['numero']]['imposta'] = (float)$vendita['totaleImposta'];
				$dc[$vendita['numero']]['importo'] = (float)$vendita['totaleFattura'];
			}

			/** totali calcolati dalle vendite*/
			if (key_exists('imponibileCalcolatoDaVendite', $dc[$vendita['numero']])) {
				$dc[$vendita['numero']]['imponibileCalcolatoDaVendite'] += $imponibile;
				$dc[$vendita['numero']]['impostaCalcolatoDaVendite'] += $imposta;
				$dc[$vendita['numero']]['importoCalcolatoDaVendite'] += $importo;
			} else {
				$dc[$vendita['numero']]['imponibileCalcolatoDaVendite'] = $imponibile;
				$dc[$vendita['numero']]['impostaCalcolatoDaVendite'] = $imposta;
				$dc[$vendita['numero']]['importoCalcolatoDaVendite'] = $importo;
			}

			$elencoArticoli[] = (string)$vendita['codice'];
		}

		foreach($dc as $numero => $transazione){
			$delta = round($transazione['importoCalcolatoDaVendite'] - $transazione['importo'], 2);
			if ( $delta) {
				$dc[$numero]['righe'][0]['imponibile'] -= $delta;
				$dc[$numero]['righe'][0]['importo'] -= $delta;
			}
		}


		/**
		 * calcolo il castelletto iva dopo aver corretto le differenze
		 */
		foreach($dc as $numero => $transazione) {
			foreach ($dc[$numero]['righe'] as $index => $vendita) {
				if (key_exists('dettaglioImposta', $dc[$numero])) {
					if (key_exists($vendita['tipoIva'], $dc[$numero]['dettaglioImposta'])) {
						$dc[$numero]['dettaglioImposta'][$vendita['tipoIva']]['imponibile'] += $vendita['imponibile'];
						$dc[$numero]['dettaglioImposta'][$vendita['tipoIva']]['imposta'] += $vendita['imposta'];
					} else {
						$dc[$numero]['dettaglioImposta'][$vendita['tipoIva']]['imponibile'] = $vendita['imponibile'];
						$dc[$numero]['dettaglioImposta'][$vendita['tipoIva']]['imposta'] = $vendita['imposta'];
					}
				} else {
					$dc[$numero]['dettaglioImposta'][$vendita['tipoIva']] = [
						'imponibile' => $vendita['imponibile'],
						'imposta' => $vendita['imposta']
					];
				}
			}
		}

		/**
		 * deduplico i codici articolo ed elimino i "buchi" nell'array
		 */
		$elencoArticoli = array_values(array_unique($elencoArticoli));


		/**
		 * cerco i barcode dei codici articolo utilizzati nel datacollect
		 */
		$response = $client->post('/eDatacollect/src/eDatacollect.php',
			['json' =>
				[
					'function' => 'recuperaBarcode',
					'articoli' => $elencoArticoli
				]
			]
		);
		$elencoBarcode = [];
		if ($response->getStatusCode() == 200) {
			$elencoBarcode = json_decode($response->getBody()->getContents(), true);
		}


		/**
		 * cerco il reparto degli articoli utilizzati nel datacollect
		 */
		$response = $client->post('/eDatacollect/src/eDatacollect.php',
			['json' =>
				[
					'function' => 'recuperaReparto',
					'articoli' => $elencoArticoli
				]
			]
		);
		$elencoReparti = [];
		if ($response->getStatusCode() == 200) {
			$elencoReparti = json_decode($response->getBody()->getContents(), true);
		}

		$righe = [];
		foreach ($dc as $numero => $transazione) {
			$numRec = 0;

			$ora = '120000';

			$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:H:1%01d0:%04s:%\' 16s:00+00000+000000000',
				$sede,
				'001',
				"$anno$mese$giorno",
				$ora,
				substr($numero, -4),
				getCounter($numRec),
				($transazione['importo'] < 0) ? 5 : 0,
				'001',
				''
			);

			$vendite = [];
			$progressivoVendita = 1;
			foreach ($transazione['righe'] as $vendita) {
				$vendita['reparto'] = (key_exists($vendita['codice'], $elencoReparti)) ? $elencoReparti[$vendita['codice']] : '0100';
				$vendita['barcode'] = (key_exists($vendita['codice'], $elencoBarcode)) ? $elencoBarcode[$vendita['codice']] : '';
				if ($vendita['articoloAPeso'] && strlen($vendita['barcode']) == 7) {
					$barcode12 = str_pad($vendita['barcode'], 12, '0', STR_PAD_RIGHT);
					$vendita['barcode'] = $barcode12 . get_ean_checkdigit($barcode12);
				}
				$vendita['progressivoVendita'] = $progressivoVendita++;

				$vendite[] = $vendita;
			}

			foreach ($vendite as $vendita) {

				if ($vendita['articoloAPeso']) {
					$righe[] = sprintf('%04s:001:%06s:%06s:%04s:%03s:S:1%01d1:%04s:%\' 16s%+09.3f+%09d',
						$sede,
						"$anno$mese$giorno",
						$ora,
						substr($numero, -4),
						getCounter($numRec),
						($transazione['importo'] < 0) ? 5 : 0,
						$vendita['reparto'],
						$vendita['barcode'],
						($transazione['importo'] < 0) ? $vendita['peso'] * -1 : $vendita['peso'],
						abs(round($vendita['importo'] / $vendita['quantita'] * 100, 0)) * -1
					);
				} else {
					$righe[] = sprintf('%04s:001:%06s:%06s:%04s:%03s:S:1%01d1:%04s:%\' 16s%+05d0010*%09d',
						$sede,
						"$anno$mese$giorno",
						$ora,
						substr($numero, -4),
						getCounter($numRec),
						($transazione['importo'] < 0) ? 5 : 0,
						$vendita['reparto'],
						$vendita['barcode'],
						($transazione['importo'] < 0) ? $vendita['quantita'] * -1 : $vendita['quantita'],
						abs(round($vendita['importo'] / $vendita['quantita'] * 100, 0))
					);
				}

				$righe[] = sprintf('%04s:001:%06s:%06s:%04s:%03s:i:100:%04s:%\' 16s:%011d3000000',
					$sede,
					"$anno$mese$giorno",
					$ora,
					substr($numero, -4),
					getCounter($numRec),
					'001',
					$vendita['barcode'],
					$vendita['tipoIva']
				);

				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:i:101:%04s:%\' 16s:%04d00000000000000',
					$sede,
					'001',
					"$anno$mese$giorno",
					$ora,
					substr($numero, -4),
					getCounter($numRec),
					'001',
					$vendita['barcode'],
					$vendita['progressivoVendita']
				);
			}

			// forme di pagamento
			$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:T:11%1d:%04s:%\' 16s:%02s%+06d%+010d',
				$sede,
				'001',
				"$anno$mese$giorno",
				$ora,
				substr($numero, -4),
				getCounter($numRec),
				'01',
				'001',
				'',
				'01',
				1,
				round($transazione['importo'] * 100, 0)
			);

			foreach ($vendite as $vendita) {
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:v:100:%04s:%\' 16s%+05d%07d%07d',
					$sede,
					'001',
					"$anno$mese$giorno",
					$ora,
					substr($numero, -4),
					getCounter($numRec),
					'001',
					$vendita['barcode'],
					($transazione['importo'] < 0) ? 1 : ($vendita['importo'] > 0) ? 1 : -1,
					abs($vendita['importo'] * 100),
					abs($vendita['imposta'] * 100),
				);
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:v:101:%04s:%\' 16s:%04d%\'014s',
					$sede,
					'001',
					"$anno$mese$giorno",
					$ora,
					substr($numero, -4),
					getCounter($numRec),
					'001',
					$vendita['barcode'],
					$vendita['progressivoVendita'],
					''
				);
			}

			ksort($transazione['dettaglioImposta'], SORT_NUMERIC);
			foreach ($transazione['dettaglioImposta'] as $codice => $imposta) {
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:V:1%1d1:%04s:%\' 11s%04.1f%%:00%+06d%+010d',
					$sede,
					'001',
					"$anno$mese$giorno",
					$ora,
					substr($numero, -4),
					getCounter($numRec),
					$codice,
					'001',
					'',
					$aliquoteIva[$codice],
					1,
					abs(round($imposta['imponibile'] * 100, 0))
				);
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:V:1%1d0:%04s:%\' 11s%04.1f%%:00%+06d%+010d',
					$sede,
					'001',
					"$anno$mese$giorno",
					$ora,
					substr($numero, -4),
					getCounter($numRec),
					$codice,
					'001',
					'',
					$aliquoteIva[$codice],
					1,
					abs(round($imposta['imposta'] * 100, 0))
				);
			}

			$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:F:1%01d0:%04s:%\' 16s:00%+06d%+010d',
				$sede,
				'001',
				"$anno$mese$giorno",
				$ora,
				substr($numero, -4),
				getCounter($numRec),
				($transazione['importo'] < 0) ? 5 : 0,
				'001',
				'',
				($transazione['importo'] < 0) ? count($transazione['righe']) * -1 : count($transazione['righe']),
				round($transazione['importo'] * 100, 0)
			);
		}

		// esportazione su file di testo
		$fileName = $sede . "_20$anno$mese$giorno" . '_' . "$anno$mese$giorno" . '_DC.TXT';

		$path = '/Users/if65/Desktop/DC/';
		if (!$debug) {
			$path = "/dati/datacollect/20$anno$mese$giorno/";
		}
		file_put_contents($path . $fileName, implode("\r\n", $righe));
	}

	$data->add(new DateInterval('P1D'));

}

function get_ean_checkdigit($ean12, $full = false){

	$ean12 =(string)$ean12;
	// 1. Sommo le posizioni dispari
	$even_sum = $ean12{1} + $ean12{3} + $ean12{5} + $ean12{7} + $ean12{9} + $ean12{11};
	// 2. le moltiplico x 3
	$even_sum_three = $even_sum * 3;
	// 3. Sommo le posizioni pari
	$odd_sum = $ean12{0} + $ean12{2} + $ean12{4} + $ean12{6} + $ean12{8} + $ean12{10};
	// 4. Sommo i parziali precedenti
	$total_sum = $even_sum_three + $odd_sum;
	// 5. Il check digit è il numero più piccolo sottomultiplo di 10
	$next_ten = (ceil($total_sum/10))*10;
	$check_digit = $next_ten - $total_sum;

	if($full==true) { // Ritorna tutto l'ean
		return $ean12.$check_digit;
	}
	else { // Ritorna solo il check-digit
		return $check_digit;
	}
}

function getCounter(int &$numRec): int {
	++$numRec;
	if ($numRec > 999) {
		$numRec = 1;
	}
	return $numRec;
};