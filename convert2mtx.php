<?php

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

$barcodeMenu = [
	'1' => '9770110000016',
	'2' => '9770110000023',
	'3' => '9770110000030', //3-MENU KIDS 2020
	'4' => '9770110000047', //4-MENU HAMBURGER 2020
	'5' => '9770110000054', //5-MENU CLASSICO 2020
	'6' => '9770110000061',
	'7' => '9770110000078',
	'8' => '9770110000085', //8-MENU PRIMO 2020
	'9' => '9770110000092',
	'10' => '9770110000108',
	'11' => '9770110000115',
	'12' => '9770110000122', //12-MERENDONA DI NATALE
	'13' => '9770110000139', //13-CIOCCOLATA + PANETTONE
	'14' => '9770110000146', //14-CIOCCOLATA + PANDORO
	'15' => '9770110000153', //15-CIOCCOLATA + SACHER
	'16' => '9770110000160', //16-CIOCCOLATA + TORTA MELE
	'17' => '9770110000177', //17-MENU BIMBI NATALE NATURALE
	'18' => '9770110000184', //18-MENU BIMBI NATALE FRIZZANTE
	'19' => '9770110000191', //19-MENU EQUILIBRIO - 3
	'20' => '9770110000207',
];

$transcodificaSede = [
	'0500' => '0501',
	'6001' => '0201',
	'6002' => '0155',
	'6003' => '0142',
	'6004' => '0203',
	'6005' => '0148',
	'6006' => '0132',
	'6007' => '0115',
	'6009' => '0204'
];

$menuValidi = ['1','2','3', '4', '5','6','7','8','9','10','11', '12', '13', '14', '15','16','17','18','19','20'];

$client = new Client([
	'base_uri' => 'http://10.11.14.128/',
	'headers' => ['Content-Type' => 'application/json'],
	'timeout' => 60.0,
]);

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

	$response = $client->post('/eDatacollect/src/eDatacollect.php',
		['json' =>
			[
				'data' => $data->format('Y-m-d'),
				'function' => 'creazioneDatacollectTcPos',
				'sede' => $transcodificaSede[$sede]
			]
		]
	);

	if ($response->getStatusCode() == 200) {
		//$wj = $response->getBody()->getContents();
		$dc = json_decode($response->getBody()->getContents(), true);

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

		$barcodeList = [];
		if ($response->getStatusCode() == 200) {
			$barcodeList = json_decode($response->getBody()->getContents(), true);
		}

		$response = $client->post('/eDatacollect/src/eDatacollect.php',
			['json' =>
				[
					'function' => 'recuperaReparto',
					'articoli' => $articleCodeList
				]
			]
		);

		$departmentList = [];
		if ($response->getStatusCode() == 200) {
			$departmentList = json_decode($response->getBody()->getContents(), true);
		}

		$till = array_column($dc, 'till_code');
		$trans = array_column($dc, 'trans_num');
		array_multisort($till, SORT_ASC, $trans, SORT_ASC, $dc);

		$righe = [];
		foreach ($dc as $transaction) {
			$numRec = 0;

			$ora = '';
			if (preg_match('/^.{10}T(\d{2}):(\d{2}):(\d{2})$/', $transaction['trans_date'], $matches)) {
				$ora = $matches[1] . $matches[2] . $matches[3];
			}
			$cardNum = '';
			if ($transaction['card_num'] != null) {
				$cardNum = $transaction['card_num'];
			}
			$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:H:1%01d0:%04s:%\' 16s:00+00000+000000000',
				$sede,
				$transaction['till_code'],
				"$anno$mese$giorno",
				$ora,
				substr($transaction['trans_num'], -4),
				++$numRec,
				($transaction['total_amount'] < 0) ? 5 : 0,
				$transaction['till_code'],
				''
			);

			if ($cardNum != '') {
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:k:100:0013:%\' 16s:%\' 18s',
					$sede,
					$transaction['till_code'],
					"$anno$mese$giorno",
					$ora,
					substr($transaction['trans_num'], -4),
					++$numRec,
					$cardNum,
					''
				);
			}

			$sales = [];
			$saleNum = 1;
			foreach ($transaction['articles'] as $hash_code => $sale) {
				$sale['department'] = '0001';
				if (key_exists($sale['article_code'], $departmentList)) {
					$sale['department'] = $departmentList[$sale['article_code']];
				}

				$sale['article_barcode'] = '';
				if (key_exists($sale['article_code'], $barcodeList)) {
					$sale['article_barcode'] = $barcodeList[$sale['article_code']];
				}

				$sale['sale_number'] = $saleNum++;

				$sale['prezzo'] = round($sale['price'] /*+ $sale['promotion_discount']*/ + $sale['addition_article_price'], 2);
				$sale['prezzoListino'] = round(($sale['pricelevel_unit_price'] * $sale['qty_weight']) + $sale['addition_article_price'], 2);
				if ($sale['menu_id'] != 0) {
					if (!in_array($sale['menu_id'], $menuValidi)) {
						$sale['menu_id'] = 5;
					}
					$sale['menu_barcode'] = $barcodeMenu[$sale['menu_id']];
				} else {
					$sale['menu_barcode'] = '';
					$sale['prezzoListino'] = $sale['prezzo'];
				}
				$sale['sconto'] = round($sale['prezzoListino'] - $sale['prezzo'], 2);

				$sales[$hash_code] = $sale;
			}

			/*if (key_exists('menus', $transaction)) {
				foreach($transaction['menus'] as $menu_id => $menu) {
					$menuTotaleVenduto = 0;
					foreach($menu['articles'] as $article) {
						$menuTotaleVenduto += $sales[$article]['prezzoListino'];
					}
					$sommaQuote = 0;
					foreach($menu['articles'] as $article) {
						$sales[$article]['menu_quota'] = round($sales[$article]['prezzoListino'] / $menuTotaleVenduto * $menu['price'], 2);
						$sommaQuote += $sales[$article]['menu_quota'];
					}
					$delta = round($menu['price'] - $sommaQuote, 2);
				}
			}*/

			foreach ($sales as $sale) {
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:S:1%01d1:%04s:%\' 16s%+05d0010*%09d',
					$sede,
					$transaction['till_code'],
					"$anno$mese$giorno",
					$ora,
					substr($transaction['trans_num'], -4),
					++$numRec,
					($transaction['total_amount'] < 0) ? 5 : 0,
					$sale['department'],
					$sale['article_barcode'],
					$sale['qty_weight'],
					round(abs($sale['prezzoListino'] * 100 / $sale['qty_weight']), 0)
				);

				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:i:100:%04s:%\' 16s:%011d3000000',
					$sede,
					$transaction['till_code'],
					"$anno$mese$giorno",
					$ora,
					substr($transaction['trans_num'], -4),
					++$numRec,
					$transaction['till_code'],
					$sale['article_barcode'],
					$sale['vat_code']
				);

				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:i:101:%04s:%\' 16s:%04d00000000000000',
					$sede,
					$transaction['till_code'],
					"$anno$mese$giorno",
					$ora,
					substr($transaction['trans_num'], -4),
					++$numRec,
					$transaction['till_code'],
					$sale['article_barcode'],
					$sale['sale_number']
				);
			}

			// ripartizione menu
			//0121:001:210408:145901:1721:165:d:100:0420:P0:8053017190136+00010010*000000159
			//0121:001:210408:145901:1721:166:D:196:0000: 0:1:           :00+00000-000000250
			//0121:001:210408:145901:1721:167:w:100:0000:   9872174100258+00010000-000000250
			if (key_exists('menus', $transaction)) {
				foreach ($transaction['menus'] as $menu) {
					if ($menu['price'] != 0) {
						foreach ($menu['articles'] as $article) {
							$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:d:100:%04s:%\' 16s%+05d0010*%09d',
								$sede,
								$transaction['till_code'],
								"$anno$mese$giorno",
								$ora,
								substr($transaction['trans_num'], -4),
								++$numRec,
								$sales[$article]['department'],
								$sales[$article]['article_barcode'],
								$sales[$article]['qty_weight'],
								round(abs($sales[$article]['prezzoListino'] * 100 / $sales[$article]['qty_weight']), 0)
							);
						}
						$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:D:196:0000: 0:1:           :00+00000%+010d',
							$sede,
							$transaction['till_code'],
							"$anno$mese$giorno",
							$ora,
							substr($transaction['trans_num'], -4),
							++$numRec,
							round($menu['price'] * -100, 0)
						);
						$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:w:100:0000:   %13s+00010000%+010d',
							$sede,
							$transaction['till_code'],
							"$anno$mese$giorno",
							$ora,
							substr($transaction['trans_num'], -4),
							++$numRec,
							($sale['menu_barcode'] == '') ? '9770110000054' : $sale['menu_barcode'],
							round($menu['price'] * -100, 0)
						);
					}
				}
			}

			$totaleScontiTransazionali = 0;

			//promozioni
			//0694:001:210410:095213:8777:033:D:197:0000: 0:0:           :00+00000-000000200
			//0694:001:210410:095213:8777:034:w:100:0000:   9882179302007+00010000-000000200
			if (key_exists('discounts', $transaction)) {
				foreach ($transaction['promotions'] as $promotion) {
					$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:D:197:0000: 0:0:           :00+00000%+010d',
						$sede,
						$transaction['till_code'],
						"$anno$mese$giorno",
						$ora,
						substr($transaction['trans_num'], -4),
						++$numRec,
						round($promotion['amount'] * 100, 0)
					);
					$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:w:100:0000:   %13s+00010000%+010d',
						$sede,
						$transaction['till_code'],
						"$anno$mese$giorno",
						$ora,
						substr($transaction['trans_num'], -4),
						++$numRec,
						'9882179302007', // da cambiare
						round($promotion['amount'] * 100, 0)
					);
					$totaleScontiTransazionali += abs($promotion['amount']);
				}
			}

			//arrotondamenti
			//0694:001:210410:095213:8777:033:D:197:0000: 0:0:           :00+00000-000000200
			//0694:001:210410:095213:8777:034:w:100:0000:   9882179302007+00010000-000000200
			if (key_exists('discounts', $transaction)) {
				foreach ($transaction['discounts'] as $discount) {
					if ($discount['discount_id'] == "6" and $discount['amount'] != 0) {
						$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:D:197:0000: 0:0:           :00+00000%+010d',
							$sede,
							$transaction['till_code'],
							"$anno$mese$giorno",
							$ora,
							substr($transaction['trans_num'], -4),
							++$numRec,
							round($discount['amount'] * 100, 0)
						);
						$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:w:100:0000:   %13s+00010000%+010d',
							$sede,
							$transaction['till_code'],
							"$anno$mese$giorno",
							$ora,
							substr($transaction['trans_num'], -4),
							++$numRec,
							'9882179302007', // da cambiare
							round($discount['amount'] * 100, 0)
						);
						$totaleScontiTransazionali += abs($discount['amount']);
					}
				}
			}

			//sconto dipendenti
			//0170:001:210408:112110:5628:012:D:198:0000: 1:0:           :00+00000-000000086
			//0170:001:210408:112110:5628:013:m:101:  00:0061-50012D8
			if (key_exists('discounts', $transaction)) {
				foreach ($transaction['discounts'] as $discount) {
					if ($discount['discount_id'] == "5" and $discount['amount'] != 0) {
						$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:D:198:0000: 1:0:           :00+00000%+010d',
							$sede,
							$transaction['till_code'],
							"$anno$mese$giorno",
							$ora,
							substr($transaction['trans_num'], -4),
							++$numRec,
							round($discount['amount'] * 100, 0)
						);
						$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:m:101:  %02s:0061-%07d%\' 23s',
							$sede,
							$transaction['till_code'],
							"$anno$mese$giorno",
							$ora,
							substr($transaction['trans_num'], -4),
							++$numRec,
							'',
							99,
							''
						);
						$totaleScontiTransazionali += abs($discount['amount']);
					}
				}
			}

			// forme di pagamento
			if (key_exists('payments', $transaction)) {
				foreach ($transaction['payments'] as $payment) {
					$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:T:11%1d:%04s:%\' 16s:%02s%+06d%+010d',
						$sede,
						$transaction['till_code'],
						"$anno$mese$giorno",
						$ora,
						substr($transaction['trans_num'], -4),
						++$numRec,
						$payment['payment_type'],
						$transaction['till_code'],
						substr($payment['credit_card_num'], -16),
						$payment['payment_code'],
						1,
						round($payment['payment_amount'] * 100, 0)
					);
				}
			}

			if ($cardNum != '' && $transaction['total_amount'] >= 6) {
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:G:121:0000: 1:%\' 13s:00%+06d%+010d',
					$sede,
					$transaction['till_code'],
					"$anno$mese$giorno",
					$ora,
					substr($transaction['trans_num'], -4),
					++$numRec,
					'',
					(($transaction['total_amount'] - 5) > 0) ? floor($transaction['total_amount'] - 5) : 0,
					round($transaction['total_amount'] * 100, 0)
				);
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:m:101:  %02s:0034-%07d%\' 23s',
					$sede,
					$transaction['till_code'],
					"$anno$mese$giorno",
					$ora,
					substr($transaction['trans_num'], -4),
					++$numRec,
					'',
					10,
					''
				);
			}

			if ($totaleScontiTransazionali) {
				$totaleDaVendite = 0;
				$maxIndex = 0;
				$maxValue = 0;
				foreach ($sales as $index => $sale) {
					$price = abs(round($sale['prezzoListino'] - $sale['sconto'], 2));
					if ($maxValue < $price) {
						$maxValue = $price;
						$maxIndex = $index;
					}
					$totaleDaVendite += $price;
				}
				foreach ($sales as $index => $sale) {
					$price = abs(round($sale['prezzoListino'] - $sale['sconto'], 2));
					$sconto_transazionale = $price/$totaleDaVendite*$totaleScontiTransazionali;
					$totaleScontiTransazionali -= round($sconto_transazionale,2);
					$sales[$index]['scontoTransazionale'] = round($sconto_transazionale,2);
				}
				$sales[$maxIndex]['scontoTransazionale'] = $sales[$maxIndex]['scontoTransazionale'] + $totaleScontiTransazionali;
			}
			foreach ($sales as $sale) {
				$scontoTransazionale = 0;
				if (key_exists('scontoTransazionale', $sale)) {
					$scontoTransazionale = $sale['scontoTransazionale'];
				}
				$price = round(($sale['prezzoListino'] - $sale['sconto'] - $scontoTransazionale) * 100, 0);
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:v:100:%04s:%\' 16s%+05d%07d%07d',
					$sede,
					$transaction['till_code'],
					"$anno$mese$giorno",
					$ora,
					substr($transaction['trans_num'], -4),
					++$numRec,
					$transaction['till_code'],
					$sale['article_barcode'],
					($transaction['total_amount'] < 0) ? 1 : (($price > 0) ? 1 : -1),
					abs($price),
					abs(round($price - round($price / ($sale['vat_percent'] + 100) * 100, 0), 0))
				);
				$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:v:101:%04s:%\' 16s:%04d%\'014s',
					$sede,
					$transaction['till_code'],
					"$anno$mese$giorno",
					$ora,
					substr($transaction['trans_num'], -4),
					++$numRec,
					$transaction['till_code'],
					$sale['article_barcode'],
					$sale['sale_number'],
					''
				);
			}

			if (key_exists('transaction_vat', $transaction)) {
				foreach ($transaction['transaction_vat'] as $vat) {
					$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:V:1%1d1:%04s:%\' 11s%04.1f%%:00%+06d%+010d',
						$sede,
						$transaction['till_code'],
						"$anno$mese$giorno",
						$ora,
						substr($transaction['trans_num'], -4),
						++$numRec,
						$vat['vat_code'],
						$transaction['till_code'],
						'',
						$vat['vat_percent'],
						1,
						round($vat['net_amount'] * 100, 0)
					);
					$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:V:1%1d0:%04s:%\' 11s%04.1f%%:00%+06d%+010d',
						$sede,
						$transaction['till_code'],
						"$anno$mese$giorno",
						$ora,
						substr($transaction['trans_num'], -4),
						++$numRec,
						$vat['vat_code'],
						$transaction['till_code'],
						'',
						$vat['vat_percent'],
						1,
						round($vat['vat_amount'] * 100, 0)
					);
				}
			}

			$righe[] = sprintf('%04s:%03s:%06s:%06s:%04s:%03s:F:1%01d0:%04s:%\' 16s:00%+06d%+010d',
				$sede,
				$transaction['till_code'],
				"$anno$mese$giorno",
				$ora,
				substr($transaction['trans_num'], -4),
				++$numRec,
				($transaction['total_amount'] < 0) ? 5 : 0,
				$transaction['till_code'],
				$cardNum,
				count($transaction['articles']),
				round($transaction['total_amount'] * 100, 0)
			);
		}

		// esportazione su file di testo
		$fileName = $sede . "_20$anno$mese$giorno" . '_' . "$anno$mese$giorno" . '_DC.TXT';

		$path = '/Users/if65/Desktop/DC/';
		if (! $debug) {
			$path = "/dati/datacollect/20$anno$mese$giorno/";
		}
		file_put_contents( $path . $fileName, implode("\r\n", $righe));
	}

	$data->add(new DateInterval('P1D'));
}


