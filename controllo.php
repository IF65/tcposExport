<?php

@ini_set('memory_limit','8192M');

$text = file_get_contents('/Users/if65/Desktop/DC/dc.txt');
$dc = explode("\r\n", $text);

$result = [];
foreach($dc as $row) {
	if (preg_match('/^(\d{4}).{5}(\d{6}).{8}(\d{4}).{4}:S:1.{24}(.{5}).{5}(\d{7})(\d{2})$/', $row, $matches)) {
		$id = $matches[1] . $matches[2] . $matches[3];
		$quantita = (int)$matches[4];
		$importo = (float)($matches[5] . '.' . $matches[6]);
		$totale = $quantita*$importo;

		if (key_exists($id, $result)) {
			$result[$id]['S'] += $totale;
		} else {
			$result[$id]['S'] = $totale;
		}
	}

	if (preg_match('/^(\d{4}).{5}(\d{6}).{8}(\d{4}).{4}:F:1.{34}(\d{7})(\d{2})$/', $row, $matches)) {
		$id = $matches[1] . $matches[2] . $matches[3];
		$totale = (float)($matches[4] . '.' . $matches[5]);

		$result[$id]['F'] = $totale;
	}
}

print_r($result);