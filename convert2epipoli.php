<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'http://10.11.14.128/',
    'headers' => ['Content-Type' => 'application/json'],
    'timeout'  => 60.0,
]);

// costanti
// -----------------------------------------------------------
$sede = '0600';

$timeZone = new DateTimeZone('Europe/Rome');

$dataInizio = new DateTime('2021-01-07', $timeZone);
$dataFine = new DateTime('2021-01-07', $timeZone);

$codiceTranscodificaSede = [
	'0600' => '0600',
	'0700' => '0700'
];

$codiceCampagnaSconto = '10501';
$codicePromozioniSconto = [
    '3' => '990011425', //3-MENU KIDS 2020
    '4' => '990011426', //4-MENU HAMBURGER 2020
    '5' => '990011427', //5-MENU CLASSICO 2020
    '8' => '990011428', //8-MENU PRIMO 2020
    '12' => '990011429', //12-MENU SPECIALE 2020
    '13' => '990011430', //13-MENU GOURMET 2020
    '14' => '990011431', //14-MENU SECONDO DI CARNE 2020
    '15' => '990011432', //15-MENU SECONDO DI PESCE 2020
    '11' => '990011437' //11-MENU KIDS 2020
];

$codiceCampagnaPunti = '10485';
$codicePromozionePunti = '990011267';

$data = clone $dataInizio;
while ($data <= $dataFine) {

// procedura
// -----------------------------------------------------------
    $societa = '';
    $negozio = '';
    if (preg_match( '/^(\d\d)(\d\d)$/', $sede, $matches )) {
        $societa = $matches[1];
        $negozio = $matches[2];
    }

    $anno = '';
    $mese = '';
    $giorno = '';
    if (preg_match( '/^\d{2}(\d{2})-(\d{2})-(\d{2})$/',  $data->format( 'Y-m-d' ), $matches )) {
        $anno = $matches[1];
        $mese = $matches[2];
        $giorno = $matches[3];
    }

    $response = $client->post( '/eDatacollect/src/eDatacollect.php',
        ['json' =>
            [
                'data' =>  $data->format( 'Y-m-d' ),
                'function' => 'creazioneDatacollectTcPos',
                'sede' => $sede
            ]
        ]
    );

    if ($response->getStatusCode() == 200) {
        $dc = json_decode( $response->getBody()->getContents(), true );

        $articleCodeList = [];
        foreach ($dc as $transaction) {
            foreach ($transaction['articles'] as $article) {
                $articleCodeList[] = $article['article_code'];
            }
        }
        $response = $client->post( '/eDatacollect/src/eDatacollect.php',
            ['json' =>
                [
                    'function' => 'recuperaBarcode',
                    'articoli' => $articleCodeList
                ]
            ]
        );

        $barcodeList = [];
        if ($response->getStatusCode() == 200) {
            $barcodeList = json_decode( $response->getBody()->getContents(), true );
        }

        $response = $client->post( '/eDatacollect/src/eDatacollect.php',
            ['json' =>
                [
                    'function' => 'recuperaReparto',
                    'articoli' => $articleCodeList
                ]
            ]
        );

        $departmentList = [];
        if ($response->getStatusCode() == 200) {
            $departmentList = json_decode( $response->getBody()->getContents(), true );
        }

        $righe = [];
        $numRec = 0;
        foreach ($dc as $transaction) {

            $ora = '';
            if (preg_match( '/^.{10}T(\d{2}):(\d{2}):(\d{2})$/', $transaction['trans_date'], $matches )) {
                $ora = $matches[1] . $matches[2];
            }
            $cardNum = '';
            if ($transaction['card_num'] != null) {
                $cardNum = $transaction['card_num'];
            }
            $righe[] = sprintf( '%08s%08d%-5s004%04d%04d%06d%08d%04s%13s%1s%45s',
                "20$anno$mese$giorno",
                ++$numRec,
                $codiceTranscodificaSede[$sede],
                $transaction['trans_num'],
                $transaction['till_code'],
                $transaction['operator_code'],
                "20$anno$mese$giorno",
                $ora,
                $cardNum,
                0,
                ''
            );

	        $totaleBuoniPasto = 0;
            foreach ($transaction['articles'] as $article) {
                if (preg_match( '/^\d{7}$/', $article['article_code'] )) {

                    if ($article['article_code'] == '0000-0001' or $article['article_code'] == '0000-0001') {
                        $totaleBuoniPasto += round( $article['article_price'], 2 );
                    }

                    $prezzo = round( $article['article_price'] + $article['price_article_menu_addition'] + $article['discount'], 2 );
                    $prezzoListino = round( $article['article_catalog_price_unit'] * $article['quantity'], 2 );
                    $sconto = 0;
                    if (round( $prezzoListino - $prezzo, 2 ) and ($article['menu_id'] != null)) {
                        $sconto = round( $prezzoListino - $prezzo, 2 );
                    } else {
                        $prezzoListino = $prezzo;
                    }

                    $inPromozione = false;
                    if ($prezzoListino != $prezzo) {
                        $inPromozione = true;
                    }

                    // riga vendita
                    $righe[] = sprintf( '%08s%08s%-5s1001%13s%1s%4s%09d%1d%09d%9s%9s%02d%-10s%13s%1d   ',
                        "20$anno$mese$giorno",
                        ++$numRec,
                        $codiceTranscodificaSede[$sede],
                        (key_exists( $article['article_code'], $barcodeList )) ? $barcodeList[$article['article_code']] : '',
                        'N',
                        (key_exists( $article['article_code'], $departmentList )) ? $departmentList[$article['article_code']] : '0100',
                        round( round( $prezzoListino, 2 ) * 100, 0 ),
                        0,
                        round( $article['quantity'] * 1000, 0 ),
                        '',
                        '',
                        0,
                        $article['article_code'],
                        '',
                        0
                    );

                    if ($inPromozione) {
                        $righe[] = sprintf( '%08s%08s%-5s1013%13s%1s%4s%09d%1d%09d%-9s%9s%02d%-10s%13s%1d   ',
                            "20$anno$mese$giorno",
                            ++$numRec,
                            $codiceTranscodificaSede[$sede],
                            (key_exists( $article['article_code'], $barcodeList )) ? $barcodeList[$article['article_code']] : '',
                            'N',
                            (key_exists( $article['article_code'], $departmentList )) ? $departmentList[$article['article_code']] : '0100',
                            round( $sconto * 100, 0 ),
                            0,
                            round( 0, 0 ),
                            $codiceCampagnaSconto,
                            ($article['menu_id'] != null) ? $codicePromozioniSconto[$article['menu_id']] : '',
                            0,
                            '',
                            '',
                            0
                        );
                    }
                }
            }

            // punti transazione
            foreach ($transaction['points'] as $point) {
                $righe[] = sprintf( '%08s%08s%-5s1077%18s%09d%1d%09d%-9s%9s%02d%-10s%13s%1d',
                    "20$anno$mese$giorno",
                    ++$numRec,
                    $codiceTranscodificaSede[$sede],
                    '',
                    round( $point['points_gained'] - $point['points_used'], 0 ),
                    0,
                    0,
                    $codiceCampagnaPunti,
                    $codicePromozionePunti,
                    0,
                    '',
                    '',
                    0
                );
            }

            // chiusura transazione
            $righe[] = sprintf( '%08s%08s%-5s1020%18s%09d%1d%09d%9s%9s%02d%-10s%13s%1d   ', "20$anno$mese$giorno",
                ++$numRec,
                $codiceTranscodificaSede[$sede],
                '',
                round( ($transaction['total_amount'] - $totaleBuoniPasto) * 100, 0 ),
                0,
                0,
                '',
                '',
                0,
                '',
                '',
                0
            );

        }

        // esportazione su file di testo
        if (true) {
            $fileName = "DC20$anno$mese$giorno" . '0' . $codiceTranscodificaSede[$sede] . '001.DAT';
            file_put_contents( '/Users/if65/Desktop/DCEpipoli/' . $fileName, implode( "\n", $righe ) );

	        $fileName = "DC20$anno$mese$giorno" . '0' . $codiceTranscodificaSede[$sede] . '001.CTL';
	        file_put_contents( '/Users/if65/Desktop/DCEpipoli/' . $fileName, '' );
        }
    }

    $data->add(new DateInterval('P1D'));
}