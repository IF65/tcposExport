<?php
@ini_set('memory_limit','8192M');

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GetOpt\GetOpt;
use GetOpt\Option;

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

$sede = (string)$options->getOption('s');

/**
 * costanti: ho aggiunto 0000 perché in alcuni casi esce in questo modo dai cash
 */
$codiciIva = ['0000' => 7, '9931' => 0, 	'0400' => 1, '1000' => 2, '2200' => 3, '0500' => 4, '9100' => 5, '9300' => 6, '7400' => 7];
$aliquoteIva = [0 => 0, 1 => 4, 2 => 10, 3 => 22, 4 => 5, 5 => 0, 6 => 0, 7 => 0];
$ip = ['0012' => '192.168.239.20', '0016' => '192.168.216.10', '0018' => '192.168.218.10'];

if (preg_match('/^001(?:2|6|8)/', $sede)) {

	$data = clone $dataInizio;
	while ($data <= $dataFine) {
		/**
		 * creo il client ftp per scaricare il file
		 */
		$connId = ftp_connect($ip[str_pad($sede, 4, "0", STR_PAD_LEFT)]);
		$loginResult = ftp_login($connId, 'manager', 'manager');

		if ((!$connId) || (!$loginResult)) {
			echo "FTP connection has failed!";
			echo "Attempted to connect to $ip[$sede] for user manager";
			exit;
		}

		$localPath = '/Users/if65/Desktop/DC/';
		$fileName = '';
		if (preg_match('/^\d\d(\d\d)-(\d\d)-(\d\d)$/', $data->format('Y-m-d'), $matches)) {
			$fileName = 'VC' . $matches[1] . $matches[2] . $matches[3];
			if (!$debug) {
				$localPath = '/dati/datacollect/20' . $matches[1] . $matches[2] . $matches[3] . '/';
			}
		}
		if (ftp_chdir($connId, '/cobol/dat')) {
			if (!ftp_get($connId, $localPath . $fileName . '.DAT', $fileName . '.DAT', $mode = FTP_BINARY, $offset = 0)) {
				exit;
			}
		}

		ftp_close($connId);

		/**
		 * creo il client rest
		 */
		$client = new Client([
			'base_uri' => 'http://10.11.14.128/',
			'headers' => ['Content-Type' => 'application/json'],
			'timeout' => 60.0,
		]);

		/**
		 * carico il file scaricato via ftp
		 */
		$text = file_get_contents($localPath . $fileName . '.DAT');
		$rows = explode("\r\n", $text);

		/**
		 * cerco i codici degli articoli usati nel datacollect per evitare di caricare
		 * integralmente l'anagrafica articoli.
		 */
		$elencoCodiciArticoloUtilizzati = [];
		foreach ($rows as $row) {
			if (preg_match('/^.{17}(?!000)\d{3}.{12}000000(\d{7}).*0$/', $row, $matches)) {
				$elencoCodiciArticoloUtilizzati[] = $matches[1];
			}
		}
		$elencoCodiciArticoloUtilizzati = array_values(array_unique($elencoCodiciArticoloUtilizzati));

		/**
		 * cerco i barcode degli articoli utilizzati nel datacollect
		 */
		$response = $client->post('/eDatacollect/src/eDatacollect.php',
			['json' =>
				[
					'function' => 'recuperaBarcode',
					'articoli' => $elencoCodiciArticoloUtilizzati
				]
			]
		);
		$elencoBarcode = [];
		if ($response->getStatusCode() == 200) {
			$elencoBarcode = json_decode($response->getBody()->getContents(), true);
		}

		/**
		 * cerco i reparti degli articoli utilizzati nel datacollect
		 */
		$response = $client->post('/eDatacollect/src/eDatacollect.php',
			['json' =>
				[
					'function' => 'recuperaReparto',
					'articoli' => $elencoCodiciArticoloUtilizzati
				]
			]
		);
		$elencoReparti = [];
		if ($response->getStatusCode() == 200) {
			$elencoReparti = json_decode($response->getBody()->getContents(), true);
		}

		/**
		 * caricamento datacollect
		 */
		$dc = [];
		$numeroFatturaCorrente = '';
		$elencoCodiciArticoloUtilizzati = [];
		foreach ($rows as $row) {
			if (preg_match('/^.(\d{2})(\d{7})(\d{7})000(F|A|B)(\d{4})(\d{2})(\d{2})...(.{11})(.{11})(.{11})(\d{4})(.{11})(\d{4})(.{11})(\d{4})(.{11})(\d{4})(.{11})(\d{4})(.{11})(\d{4})(.{11})(\d{4})(.{11})(\d{4})(.{11}).{18}(\d{5})(0|1|2)(.{9}).{4}(.{6})(.)(.).{20}(.)EURO0$/', $row, $matches)) {
				$sede = $matches[1];
				$numeroFattura = $matches[2];
				$codiceCliente = $matches[3];
				$tipoDocumento = $matches[4];
				$dataFattura = $matches[5] . '-' . $matches[6] . '-' . $matches[7];
				$totaleFattura = comp2num($matches[8]) / 100;
				$valoreScontoCliente = comp2num($matches[9]) / 100;
				$valoreScontoArticolo = comp2num($matches[10]) / 100;
				$totaleImposta = 0;
				$totaleImponibile = 0;
				$dettaglioImposta = [];
				for ($i = 0; $i < 8; $i++) {
					if ($matches[11 + $i * 2] != '0000') {
						$codiceIva = $matches[11 + $i * 2];
						$imponibile = round(comp2num($matches[12 + $i * 2]) / 100, 2);
						$imposta = round($imponibile * $aliquoteIva[$codiciIva[$codiceIva]] / 100, 2);
						$dettaglioImposta[$codiceIva] = [
							'imponibile' => $imponibile,
							'imposta' => $imposta
						];

						$totaleImponibile += $imponibile;
						$totaleImposta += $imposta;
					}
				}
				$numeroColli = (int)$matches[27];

				$dc[$numeroFattura] = [
					'sede' => $sede,
					'data' => $dataFattura,
					'tipo' => $tipoDocumento,
					'codiceCliente' => $codiceCliente,
					'importo' => round($totaleFattura, 2),
					'imponibile' => round($totaleImponibile, 2),
					'imposta' => round($totaleImposta, 2),
					'dettaglioImposta' => $dettaglioImposta,
					'colli' => $numeroColli,
					'vendite' => []
				];

				$numeroFatturaCorrente = $numeroFattura;
			}

			if (preg_match('/^.{17}(?!000)(\d{3}).{12}000000(\d{7})\d{3}(.{30})(\d{7})(\d{4})(\d{9}).{27}(.)(.)(.)(\d{8})(\d{4})(\d{5})(.{11}).{42}(.{15})(.{15})(.)(..).{19}(.)EURO0$/', $row, $matches)) {
				$progressivo = (int)$matches[1];
				$codiceArticolo = $matches[2];
				$descrizioneArticolo = $matches[3];

				$prezzoPerCartone = $matches[6] / 100;

				$codiceIva = $matches[11];
				$tipoIva = $codiciIva[$codiceIva];
				$aliquotaIva = $aliquoteIva[$tipoIva];
				$numeroColli = (int)$matches[12];

				$storno = (bool)($matches[7] == '1');

				$peso = 1;
				$pezziPerCartone = ((int)$matches[5] != 0) ? (int)$matches[5] : 1;
				$quantita = (($matches[4] / 100) * $pezziPerCartone != 0) ? ($matches[4] / 100) * $pezziPerCartone : 1;
				$articoloAPeso = false;
				if ($quantita - floor($quantita) != 0) {
					$peso = $quantita;
					$quantita = 1;
					$articoloAPeso = true;
				}

				$imponibile = comp2num($matches[13]) / 100;
				$imposta = round($imponibile * $aliquotaIva / 100, 2);
				$importo = $imponibile + $imposta;

				$reparto = (key_exists($codiceArticolo, $elencoReparti)) ? $elencoReparti[$codiceArticolo] : '0100';
				$barcode = (key_exists($codiceArticolo, $elencoBarcode)) ? $elencoBarcode[$codiceArticolo] : '';
				if ($articoloAPeso && strlen($barcode == 7)) {
					$barcode12 = str_pad($barcode, 12, '0', STR_PAD_RIGHT);
					$barcode = $barcode12 . get_ean_checkdigit($barcode12);
				}

				$dc[$numeroFatturaCorrente]['vendite'][] = [
					'progressivoVendita' => $progressivo,
					'codice' => $codiceArticolo,
					'barcode' => $barcode,
					'reparto' => $reparto,
					'descrizione' => $descrizioneArticolo,
					'quantita' => $quantita,
					'peso' => $peso,
					'articoloAPeso' => $articoloAPeso,
					'pezziPerCartone' => $pezziPerCartone,
					'prezzoPerCartone' => $prezzoPerCartone,
					'colli' => $numeroColli,
					'codiceIva' => $codiceIva,
					'tipoIva' => $tipoIva,
					'aliquotaIva' => $aliquotaIva,
					'imponibile' => $imponibile,
					'imposta' => $imposta,
					'importo' => $importo,
					'storno' => $storno
				];

				$elencoCodiciArticoloUtilizzati[] = $codiceArticolo;
			}
		}

		/**
		 * scrittura dati
		 */
		$righe = [];
		foreach ($dc as $numero => $transazione) {
			$numRec = 0;

			$sede = $transazione['sede'];
			$anno = '';
			$mese = '';
			$giorno = '';
			if (preg_match('/^20(\d{2})\-(\d{2})\-(\d{2})$/', $transazione['data'], $matches)) {
				$anno = $matches[1];
				$mese = $matches[2];
				$giorno = $matches[3];
			}
			$ora = '120000';

			/**
			 * intestazione della transazione
			 */
			$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:H:1%01d0:%04s:%\' 16s:00+00000+000000000',
				$sede,
				'001',
				"$anno$mese$giorno",
				$ora,
				substr($numero, -4),
				getCounter($numRec),
				($transazione['tipo'] == 'A') ? 5 : 0,
				'001',
				''
			);

			/**
			 * vendite
			 */
			foreach ($transazione['vendite'] as $vendita) {
				if ($vendita['storno']) {
					if ($vendita['articoloAPeso']) {
						$righe[] = sprintf('%04s:001:%06s:%06s:%04s:%03s:S:1%01d1:%04s:%\' 16s%+09.3f%+010d',
							$sede,
							"$anno$mese$giorno",
							$ora,
							substr($numero, -4),
							getCounter($numRec),
							7,
							$vendita['reparto'],
							$vendita['barcode'],
							abs($vendita['peso'] * -1),
							abs(round($vendita['importo'] / $vendita['quantita'] * 100, 0)) * -1
						);
					} else {
						$righe[] = sprintf('%04s:001:%06s:%06s:%04s:%03s:S:1%01d1:%04s:%\' 16s%+05d0010*%09d',
							$sede,
							"$anno$mese$giorno",
							$ora,
							substr($numero, -4),
							getCounter($numRec),
							7,
							$vendita['reparto'],
							$vendita['barcode'],
							abs($vendita['quantita']) * -1,
							abs(round($vendita['importo'] / $vendita['quantita'] * 100, 0))
						);
					}
				} else {
					if ($vendita['articoloAPeso']) {
						$righe[] = sprintf('%04s:001:%06s:%06s:%04s:%03s:S:1%01d1:%04s:%\' 16s%+09.3f%+010d',
							$sede,
							"$anno$mese$giorno",
							$ora,
							substr($numero, -4),
							getCounter($numRec),
							($transazione['tipo'] == 'A') ? 5 : 0,
							$vendita['reparto'],
							$vendita['barcode'],
							($transazione['importo'] < 0) ? $vendita['peso'] * -1 : $vendita['peso'],
							abs(round($vendita['importo'] / $vendita['quantita'] * 100, 0))
						);
					} else {
						$righe[] = sprintf('%04s:001:%06s:%06s:%04s:%03s:S:1%01d1:%04s:%\' 16s%+05d0010*%09d',
							$sede,
							"$anno$mese$giorno",
							$ora,
							substr($numero, -4),
							getCounter($numRec),
							($transazione['tipo'] == 'A') ? 5 : 0,
							$vendita['reparto'],
							$vendita['barcode'],
							($transazione['importo'] < 0) ? $vendita['quantita'] * -1 : $vendita['quantita'],
							abs(round($vendita['importo'] / $vendita['quantita'] * 100, 0))
						);
					}
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

			/**
			 * forme di pagamento
			 */
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

			/**
			 * iva dettagliata vendite
			 */
			foreach ($transazione['vendite'] as $vendita) {
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

			/**
			 * castelletto iva transazione
			 */
			ksort($transazione['dettaglioImposta'], SORT_NUMERIC);
			foreach ($transazione['dettaglioImposta'] as $codice => $rigaDettaglio) {
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:V:1%1d1:%04s:%\' 11s%04.1f%%:00%+06d%+010d',
					$sede,
					'001',
					"$anno$mese$giorno",
					$ora,
					substr($numero, -4),
					getCounter($numRec),
					$codiciIva[$codice],
					'001',
					'',
					$aliquoteIva[$codiciIva[$codice]],
					1,
					abs(round($rigaDettaglio['imponibile'] * 100, 0))
				);
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:V:1%1d0:%04s:%\' 11s%04.1f%%:00%+06d%+010d',
					$sede,
					'001',
					"$anno$mese$giorno",
					$ora,
					substr($numero, -4),
					getCounter($numRec),
					$codiciIva[$codice],
					'001',
					'',
					$aliquoteIva[$codiciIva[$codice]],
					1,
					abs(round($rigaDettaglio['imposta'] * 100, 0))
				);
			}

			/**
			 * piede della transazione
			 */
			$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:F:1%01d0:%04s:%\' 16s:00%+06d%+010d',
				$sede,
				'001',
				"$anno$mese$giorno",
				$ora,
				substr($numero, -4),
				getCounter($numRec),
				($transazione['tipo'] == 'A') ? 5 : 0,
				'001',
				'',
				($transazione['importo'] < 0) ? count($transazione['vendite']) * -1 : count($transazione['vendite']),
				round($transazione['importo'] * 100, 0)
			);
		}

		$fileName = str_pad($sede, 4, "0", STR_PAD_LEFT) . "_20$anno$mese$giorno" . '_' . "$anno$mese$giorno" . '_DC.TXT';
		$path = '/Users/if65/Desktop/DC/';
		if (!$debug) {
			$path = "/dati/datacollect/20$anno$mese$giorno/";
		}
		file_put_contents($path . $fileName, implode("\r\n", $righe));

		$data->add(new DateInterval('P1D'));
	}
}

function comp2num(string $packedNum): float
{
	/** definizione costanti */
	$negativo = ["}" => 0, "J" => 1, "K" => 2, "L" => 3, "M" => 4, "N" => 5, "O" => 6, "P" => 7, "Q" => 8, "R" => 9];
	$positivo = ["{" => 0, "A" => 1, "B" => 2, "C" => 3, "D" => 4, "E" => 5, "F" => 6, "G" => 7, "H" => 8, "I" => 9];

	if (preg_match("/^(\d*)(\}|J|K|L|M|N|O|P|Q|R)(.*)$/", $packedNum, $matches)) {
		return ($matches[1] . $negativo[$matches[2]] . $matches[3]) * -1;
	}

	if (preg_match("/^(\d*)(\{|A|B|C|D|E|F|G|H|I)(.*)$/", $packedNum, $matches)) {
		return ($matches[1] . $positivo[$matches[2]] . $matches[3]) * 1;
	}

	return 0;
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