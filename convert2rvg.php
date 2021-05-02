<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

// costanti
// -----------------------------------------------------------
if ($argc == 1) {
	$sede = '0501';
	$dataInizio = new DateTime('2021-04-20', new DateTimeZone('Europe/Rome'));
	$dataFine = new DateTime('2021-04-20', new DateTimeZone('Europe/Rome'));
} else {
	$sede = $argv[1];
	$dataInizio = new DateTime($argv[2], new DateTimeZone('Europe/Rome'));
	if ($argc > 3) {
		$dataFine = new DateTime($argv[3], new DateTimeZone('Europe/Rome'));
	} else {
		$dataFine = new DateTime($argv[2], new DateTimeZone('Europe/Rome'));
	}
}

$hostname = 'localhost';//"10.11.14.76";
$dbname = "archivi";
$user = "root";
$password = "mela";

// procedura
// -----------------------------------------------------------
$client = new Client([
	'base_uri' => 'http://10.11.14.128/',
	'headers' => ['Content-Type' => 'application/json'],
	'timeout'  => 60.0,
]);

$societa = '';
$negozio = '';
if (preg_match('/^(\d\d)(\d\d)$/', $sede, $matches)) {
    $societa = $matches[1];
    $negozio = $matches[2];
}

$data = clone $dataInizio;
while ($data <= $dataFine) {
	$anno = '';
	$mese = '';
	$giorno = '';
	if (preg_match('/^\d{2}(\d{2})-(\d{2})-(\d{2})$/', $data->format( 'Y-m-d' ), $matches)) {
		$anno = $matches[1];
		$mese = $matches[2];
		$giorno = $matches[3];
	}

	$response = $client->post('/eDatacollect/src/eDatacollect.php',
		['json' =>
			[
				'data' => $data->format( 'Y-m-d' ),
				'function' => 'creazioneDatacollectTcPos',
				'sede' => $sede
			]
		]
	);

	$rvg = [];
	if ($response->getStatusCode() == 200) {
		$dc = json_decode($response->getBody()->getContents(), true);
		if ( isset($dc) ) {
			$articleCodeList = [];
			foreach ($dc as $transaction) {
				foreach ($transaction['articles'] as $article) {
					$articleCodeList[] = $article['article_code'];
				}
			}
			$response = $client->post('/eDatacollect/src/eDatacollect.php',
				['json' =>
					[
						'function' => 'recuperaBarcode',
						'articoli' => $articleCodeList
					]
				]
			);
			if ($response->getStatusCode() == 200) {
				$barcodeList = json_decode($response->getBody()->getContents(), true);
			}

			foreach ($dc as $transaction) {
				$check_transaction_amount = 0;
				foreach ($transaction['articles'] as $article) {

					$articleCode = $article['article_code'];
					if (!preg_match('/^\d{7}$/', $articleCode)) {
						$articleCode = '9960009';
					}

					$articlePrice = (key_exists('article_price', $article)) ? $article['article_price'] : 0;
					$articleMenuAddition = (key_exists('price_article_menu_addition', $article)) ? $article['price_article_menu_addition'] : 0;
					$articleCatalogPriceUnit = (key_exists('article_catalog_price_unit', $article)) ? $article['article_catalog_price_unit'] : 0;

					$articlePrice = (key_exists('price', $article)) ? $article['price'] : 0;
					$articleMenuAddition = (key_exists('addition_article_price', $article)) ? $article['addition_article_price'] : 0;
					$articleCatalogPriceUnit = (key_exists('article_catalog_price_unit', $article)) ? $article['article_catalog_price_unit'] : 0;
					$articleQuantity = (key_exists('qty_weight', $article)) ? $article['qty_weight'] : 0;

					$prezzo = round( $articlePrice + $articleMenuAddition + $article['discount'] + $article['promotion_discount'], 2);
					$prezzoListino = round($articleCatalogPriceUnit * $articleQuantity, 2);
					$sconto = 0;
					if (round($prezzoListino - $prezzo, 2) and ($article['menu_id'] != null)) {
						$sconto = round($prezzoListino - $prezzo, 2);
					} else {
						$prezzoListino = $prezzo;
					}

					$inPromozione = false;
					if ($prezzoListino != $prezzo) {
						$inPromozione = true;
					}

					$temp = [
						'quantita' => $articleQuantity * 1,
						'quantitaOS' => ($inPromozione) ? $articleQuantity * 1 : 0,
						'venduto' => round($prezzo * 1, 2),
						'vendutoListino' => $prezzoListino * 1,
						'vendutoOS' => ($inPromozione) ? $prezzo * 1 : 0.00
					];

					if (key_exists($articleCode, $rvg)) {
						$new = [
							'quantita' => $temp['quantita'] + $rvg[$articleCode]['quantita'],
							'quantitaOS' => $temp['quantitaOS'] + $rvg[$articleCode]['quantitaOS'],
							'venduto' => round($temp['venduto'],2) + round($rvg[$articleCode]['venduto'], 2),
							'vendutoListino' => $temp['vendutoListino'] + $rvg[$articleCode]['vendutoListino'],
							'vendutoOS' => $temp['vendutoOS'] + $rvg[$articleCode]['vendutoOS']
						];
						$rvg[$articleCode] = $new;
					} else {
						$rvg[$articleCode] = $temp;
					}
				}
			}

			// scrittura diretta
			if (true) {
				try {
					$db = new PDO("mysql:host=$hostname", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

					// controllo che ci siano dei record nella giornata in caricamento. Asar va' caricato prima di TcPos
					$stmt = "   select ifnull(count(*),0) 
                        		from archivi.riepvegi
                        		where `RVG-DATA` = :data and `RVG-CODSOC` = :societa and `RVG-CODNEG` = :negozio";
					$h_count = $db->prepare($stmt);
					$h_count->execute([':data' => $data->format('Y-m-d'), ':societa' => $societa, ':negozio' => $negozio]);
					$count = (int)$h_count->fetchColumn();
					if ($count) {
						// controllo che non sia presente nella giornata/negozio il record di avvenuto caricamento
						$stmt = "   select ifnull(count(*),0) 
		                                from archivi.riepvegi
		                                where `RVG-CODICE` = :codice and `RVG-DATA` = :data and `RVG-CODSOC` = :societa and `RVG-CODNEG` = :negozio";
						$h_count = $db->prepare($stmt);
						$h_count->execute([':codice' => '0000000', ':data' => $data->format('Y-m-d'), ':societa' => $societa, ':negozio' => $negozio]);
						$count = (int)$h_count->fetchColumn();
						if (!$count) {
							$stmt = "	insert into archivi.riepvegi 
					                            (`RVG-CODSOC`,`RVG-CODNEG`,`RVG-CODICE`,`RVG-CODBARRE`,`RVG-DATA`,`RVG-AA`,`RVG-MM`,`RVG-GG`,
					                             `RVG-QTA-USC`,`RVG-QTA-USC-OS`,`RVG-SEGNO-TIPO-PREZZO`,`RVG-VAL-VEN-CASSE-E`,`RVG-VAL-VEN-CED-E`,
					                             `RVG-VAL-VEN-LOC-E`,`RVG-VAL-VEN-OS-E`,`RVG-FORZAPRE`)
					                    values
					                        (:societa,:negozio,:codice,:barcode,:data,:aa,:mm,:gg,:quantita,:quantitaOS,:tipoPrezzo,:venduto,:vendutoCED,:vendutoLOC,:vendutoOS, '0' );";
							$h_insert = $db->prepare($stmt);

							if (sizeof($dc)) {
								$stmt = "   select ifnull(count(*),0) 
                        				from archivi.riepvegi
                        				where 	`RVG-CODICE` = :codice and `RVG-CODBARRE` = :barcode and `RVG-DATA` = :data and 
                              					`RVG-CODSOC` = :societa and `RVG-CODNEG` = :negozio";
								$h_count = $db->prepare($stmt);

								$stmt = "   select * 
				                        from archivi.riepvegi 
				                        where `RVG-CODICE` = :codice and `RVG-CODBARRE` = :barcode and `RVG-DATA` = :data and 
				                              `RVG-CODSOC` = :societa and `RVG-CODNEG` = :negozio";
								$h_retrieve = $db->prepare($stmt);

								$stmt = "   update archivi.riepvegi set 
				                              `RVG-QTA-USC` = :quantita, `RVG-QTA-USC-OS` = :quantitaOS, `RVG-VAL-VEN-CASSE-E` = :venduto,`RVG-VAL-VEN-CED-E` = :vendutoCED,
				                              `RVG-VAL-VEN-LOC-E` = :vendutoLOC, `RVG-VAL-VEN-OS-E` = :vendutoOS
				                        where `RVG-CODICE` = :codice and `RVG-CODBARRE` = :barcode and `RVG-DATA` = :data and 
				                              `RVG-CODSOC` = :societa and `RVG-CODNEG` = :negozio";
								$h_update = $db->prepare($stmt);

								foreach ($rvg as $articleCode => $article) {
									$barcode = (key_exists($articleCode, $barcodeList)) ? $barcodeList[$articleCode] : '';
									if ($h_count->execute([':codice' => $articleCode, ':barcode' => $barcode, ':data' => $data->format('Y-m-d'), ':societa' => $societa, ':negozio' => $negozio])) {
										$count = (int)$h_count->fetchColumn();
										if (!$count) {
											$h_insert->execute([
												':societa' => $societa,
												':negozio' => $negozio,
												':codice' => $articleCode,
												':barcode' => $barcode,
												':data' => $data->format('Y-m-d'),
												':aa' => $anno,
												':mm' => $mese,
												':gg' => $giorno,
												':quantita' => $article['quantita'],
												':quantitaOS' => $article['quantitaOS'],
												':tipoPrezzo' => 'L',
												':venduto' => $article['venduto'],
												':vendutoCED' => $article['vendutoListino'],
												':vendutoLOC' => $article['vendutoListino'],
												':vendutoOS' => $article['vendutoOS']
											]);
										} else {
											$h_retrieve->execute([':codice' => $articleCode, ':barcode' => $barcode, ':data' => $data->format('Y-m-d'), ':societa' => $societa, ':negozio' => $negozio]);
											$result = $h_retrieve->fetch(PDO::FETCH_ASSOC);
											//print_r($result);

											$tmp_quantita = $article['quantita'] + $result['RVG-QTA-USC'];
											$tmp_quantitaOS = $article['quantitaOS'] + $result['RVG-QTA-USC-OS'];
											$tmp_venduto = $article['venduto'] + $result['RVG-VAL-VEN-CASSE-E'];
											$tmp_vendutoCED = $article['vendutoListino'] + $result['RVG-VAL-VEN-CED-E'];
											$tmp_vendutoLOC = $article['vendutoListino'] + $result['RVG-VAL-VEN-LOC-E'];
											$tmp_vendutoOS = $article['vendutoOS'] + $result['RVG-VAL-VEN-OS-E'];

											$h_update->execute([
												':quantita' => $article['quantita'] + $result['RVG-QTA-USC'],
												':quantitaOS' => $article['quantitaOS'] + $result['RVG-QTA-USC-OS'],
												':venduto' => $article['venduto'] + $result['RVG-VAL-VEN-CASSE-E'],
												':vendutoCED' => $article['vendutoListino'] + $result['RVG-VAL-VEN-CED-E'],
												':vendutoLOC' => $article['vendutoListino'] + $result['RVG-VAL-VEN-LOC-E'],
												':vendutoOS' => $article['vendutoOS'] + $result['RVG-VAL-VEN-OS-E'],
												':codice' => $articleCode,
												':barcode' => $barcode,
												':data' => $data->format('Y-m-d'),
												':societa' => $societa,
												':negozio' => $negozio
											]);
										}
									}
								}
							}

							// inserisco il record di chiusura della giornata
							// se arrivo qui vuol dire che tcpos ha risposto e quindi
							// anche se la giornata è vuota devo chiuderla
							$h_insert->execute([
								':societa' => $societa,
								':negozio' => $negozio,
								':codice' => '0000000',
								':barcode' => '',
								':data' => $data->format('Y-m-d'),
								':aa' => $anno,
								':mm' => $mese,
								':gg' => $giorno,
								':quantita' => 0,
								':quantitaOS' => 0,
								':tipoPrezzo' => 'L',
								':venduto' => 0.00,
								':vendutoCED' => 0.00,
								':vendutoLOC' => 0.00,
								':vendutoOS' => 0.00
							]);
						}
					}
				} catch (PDOException $e) {
					echo "Errore: " . $e->getMessage();
					die();
				}
			}

			// esportazione su file di testo
			if (false) {
				$rows = [];
				foreach ($rvg as $articleCode => $article) {
					$row = '';
					$row .= $societa . "\t";
					$row .= $negozio . "\t";
					$row .= $articleCode . "\t";
					$row .= ((key_exists($articleCode, $barcodeList)) ? $barcodeList[$articleCode] : '') . "\t";
					$row .= $data->format( 'Y-m-d' ) . "\t";
					$row .= $anno . "\t";
					$row .= $mese . "\t";
					$row .= $giorno . "\t";
					$row .= $article['quantita'] . "\t";
					$row .= $article['quantitaOS'] . "\t";
					$row .= "\t";
					$row .= "0.00" . "\t";
					$row .= "0.00" . "\t";
					$row .= "0.00" . "\t";
					$row .= "0.00" . "\t";
					$row .= "0.00" . "\t";
					$row .= 'L' . "\t"; // segno tipo prezzo
					$row .= '0' . "\t"; // forzaprezzo
					$row .= '' . "\t"; //segno
					$row .= '' . "\t";//segno
					$row .= '' . "\t";//segno
					$row .= '' . "\t";//segno
					$row .= "0.00" . "\t";//filler
					$row .= $article['venduto'] . "\t";
					$row .= $article['vendutoListino'] . "\t";
					$row .= $article['vendutoListino'] . "\t";
					$row .= $article['vendutoOS'] . "\t";
					$row .= "0.00" . "\t";
					$row .= '' . "\t";
					$row .= '' . "\t";
					$row .= '' . "\t";
					$row .= '' . "\t";
					$row .= '' . "\t";
					$row .= '' . "\t";
					$row .= '' . "\t";
					$row .= '';

					$rows[] = $row;
				}

				file_put_contents('/Users/if65/Desktop/testRvg.txt', implode("\n", $rows));
			}
		}
	}

	$data->add(new DateInterval('P1D'));
}