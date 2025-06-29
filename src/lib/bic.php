<?php

$BICs = [];

$html = file_get_contents('https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/bankleitzahlen/download-bankleitzahlen-602592');
if ($html !== false) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//li[2]//a[contains(@class, 'linklist__link--blocklist')]");
    if ($nodes->length > 0) {
        $href = $nodes->item(0)->getAttribute('href');
        $csvContent = file_get_contents("https://www.bundesbank.de$href");
        foreach (explode("\n", $csvContent) as $line) {
            $parts = explode(";", $line);
            if (count($parts) < 8) {
                continue;
            }
            $value = trim($parts[7], " \t\n\r\0\x0B\"");
            if (empty($value)) {
                continue;
            }
            $BICs[trim($parts[0], " \t\n\r\0\x0B\"")] = $value;
        }
    }
    libxml_clear_errors();
}

function iban2bic($iban) {
    global $BICs;
    $iban = str_replace(" ", "", $iban);
    if (strlen($iban) < 8) {
        return "";
    }
    $bankCode = substr($iban, 4, 8);
    if (array_key_exists($bankCode, $BICs)) {
        return $BICs[$bankCode];
    }
    return "";
}