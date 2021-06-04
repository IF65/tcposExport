<?php

$debug = false;
if ($debug) {
	$errorFileName = '/Users/if65/Desktop/errori_globali.txt';
	$datacollectPath = '/Users/if65/Desktop/';
} else {
	$errorFileName = '/preparazione/errori_globali.txt';
	$datacollectPath = '/dati/datacollect/';
}

if (file_exists($errorFileName)) {
	$errorFile = file_get_contents($errorFileName);

	$errorCode = preg_match_all('/(?:(\d{17})|(\d{4}:\d{4}:20\d{6}.{8}\d{4}\s))/', $errorFile, $rows);

	$transactions = [];
	foreach ($rows[1] as $row) {
		$store = '';
		$ddate = '';
		$reg = '';
		$trans = '';
		if (preg_match('/(\d{4})(\d{3})(2\d{5})(\d{4})/', $row, $matches)) {
			$store = $matches[1];
			$ddate = $matches[3];
			$reg = $matches[2];
			$trans = $matches[4];
		}

		// sistemazione store
		if (preg_match('/^06(\d\d)$/', $store, $matches)) {
			$store = '36' . $matches[1];
		} elseif (preg_match('/01((?:51|52))$/', $store, $matches)) {
			$store = '31' . $matches[1];
		}

		if (preg_match('/^(?:01|02|04|05|31|36)/', $store)) {
			$transactions[] = ['store' => $store, 'ddate' => $ddate, 'reg' => $reg, 'trans' => $trans];
		}
	}

	foreach ($rows[2] as $row) {
		$store = '';
		$ddate = '';
		$reg = '';
		$trans = '';
		if (preg_match('/\d(\d{3}):(\d{4}):20(\d{6}).{8}(\d{4})\s/', $row, $matches)) {
			$store = $matches[4];
			$ddate = $matches[3];
			$reg = $matches[1];
			$trans = $matches[2];
		}

		// sistemazione store
		if (preg_match('/^06(\d\d)$/', $store, $matches)) {
			$store = '36' . $matches[1];
		} elseif (preg_match('/01((?:51|52))$/', $store, $matches)) {
			$store = '31' . $matches[1];
		}

		if (preg_match('/^(?:01|02|04|05|31|36)/', $store)) {
			$transactions[] = ['store' => $store, 'ddate' => $ddate, 'reg' => $reg, 'trans' => $trans];
		}
	}

	$errors = array_unique($transactions, SORT_REGULAR);;

	foreach ($errors as $error) {
		$datacollectFileName = $error['store'] . '_20' . $error['ddate'] . '_' . $error['ddate'] . '_DC';
		$datacollectFolderName = '20' . $error['ddate'];
		$reg = $error['reg'];
		$trans = $error['trans'];

		if ($datacollectFileName != '') {
			if (file_exists($datacollectPath . $datacollectFolderName . '/' . $datacollectFileName . '.TXT')) {
				$datacollect = file_get_contents($datacollectPath . $datacollectFolderName . '/' . $datacollectFileName . '.TXT');

				$dc = explode("\r\n", $datacollect);

				// individuo la prima e l'ultima vendita della transazione all'interno del datacollect
				$firstSale = 0;
				$lastSale = 0;
				$patternH = '^.{5}' . $reg . '.{15}' . $trans . '.*:S:1';
				$patternT = '^.{5}' . $reg . '.{15}' . $trans . '.*:T:1';

				for ($i = 0; $i < count($dc); $i++) {
					if (preg_match('/' . $patternH . '/', $dc[$i]) & !$firstSale) {
						$firstSale = $i;
					}

					if (preg_match('/' . $patternT . '/', $dc[$i]) & !$lastSale) {
						$lastSale = $i + 2;
					}
				}

				if ($lastSale > 0) {
					// estraggo la transazione senza toglierla dal datacollect
					$pattern = '^.{5}' . $reg . '.{15}' . $trans;
					$transaction = preg_grep('/' . $pattern . '/', $dc);

					// sistemo i contatori
					$transaction = array_values($transaction);

					$sales = [];
					$netAmount = [];
					for ($i = 0; $i < count($transaction); $i++) {
						// determino il totale scontrino
						$totaleScontrino = 0;
						if (preg_match('/:F:1.*(.{10})$/', $transaction[$i], $matches)) {
							$totaleScontrino = $matches[1] * 1;
						}

						// carico le vendite
						if (preg_match('/:S:1..:.{22}(\d{4})(.)/', $transaction[$i], $matches)) {
							$quantity = ($matches[2] == '.') ? 1 : $matches[1] * 1;
							if (preg_match('/:i:101:.{22}(\d{4})/', $transaction[$i + 2], $matches)) {
								$sales[$matches[1]] = [
									'quantity' => $quantity,
									'row1' => $transaction[$i],
									'row2' => $transaction[$i + 1],
									'row3' => $transaction[$i + 2]
								];
							}
						}

						// carico i netti dalle aliquote iva
						if (preg_match('/:v:100:.{8}(.{13}).{5}(\d{7})/', $transaction[$i], $matches)) {
							$barcode = $matches[1];
							$amount = $matches[2] * 1;
							if (preg_match('/:v:101:.{22}(\d{4})/', $transaction[$i + 1], $matches) && $amount) {
								$netAmount[$matches[1]] = ['barcode' => $barcode, 'amount' => $amount];
							}
						}
					}

					// ripulisco la transazione e rimetto a posto i contatori
					$transaction = array_values(preg_grep('/:(?:C|m|D|d||w|g|G|S|i):/', $transaction, PREG_GREP_INVERT));

					// verifico la presenza di delta
					$delta = 0;
					foreach ($netAmount as $id => $netValues) {
						if (key_exists($id, $sales)) {
							$importoUnitario = $netValues['amount'] / $sales[$id]['quantity'];
							if (floor($importoUnitario) != $importoUnitario) {
								$delta += $netValues['amount'] - floor($importoUnitario) * $sales[$id]['quantity'];
							}
						}
					}

					// ricostruisco lo scontrino con le vendite "nettificate"

					$newRows = [];
					foreach ($netAmount as $id => $netValues) {
						if (key_exists($id, $sales)) {
							$importoUnitario = floor($netValues['amount'] / $sales[$id]['quantity']);
							if ($sales[$id]['quantity'] == 1 && $delta) {
								$importoUnitario += $delta;
								$delta = 0;
							}
							$row1 = substr($sales[$id]['row1'], 0, 69) . sprintf('%09d', $importoUnitario);
							if (preg_match('/^(.{46})(?:998011|998012|977011).{7}(.*)$/', $row1, $matches)) {
								$row1 = $matches[1] . '             ' . $matches[2];
							}
							$newRows[] = $row1;
							$newRows[] = $sales[$id]['row2'];
							$newRows[] = $sales[$id]['row3'];
						} else {
							print_r($id);
						}
					}

					/**
					 * elimino le righe G di tipo 1 e 3 che vengono messe dopo i record T
					 */
					$newDC = [];
					$patternG = '^.{5}' . $reg . '.{15}' . $trans . '.*:G:1(?:1|3)';
					for ($i = 0; $i < count($dc); $i++) {
						if (! preg_match('/' . $patternG . '/', $dc[$i])) {
							$newDC[] = $dc[$i];
						}
					}
					unset($dc);
					$dc = $newDC;

					/**
					 * controllo che il totale dello scontrino coincida con il totale delle vendite. a volte è diverso perché ci
					 * sono gli sconti transazionali che in caso di quantità diversa da 1 rendono il totale venduto per barcode
					 * non divisibile senza resto. in questo caso agggiungo o tolgo la differenza su un'altra vendita con quantità 1.
					 */
					if ($totaleScontrino != 0) {
						$oldRows = array_values(array_splice($dc, $firstSale, $lastSale - $firstSale - 2, $newRows));
						$text = implode("\r\n", $dc);
					} else {
						$newDC = [];
						for ($i = 0; $i < count($dc); $i++) {
							if (!preg_match('/' . $pattern . '/', $dc[$i])) {
								$newDC[] = $dc[$i];
							}
						}
						$text = implode("\r\n", $newDC);
					}

					file_put_contents($datacollectPath . $datacollectFolderName . '/' . $datacollectFileName . '.TXT', $text);
				} else {
					print_r("$datacollectFileName\n");
				}
			}
		}
	}
}