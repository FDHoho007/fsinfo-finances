<?php

require_once("config.php");
require_once("firefly.php");

$fireflyClient = new FireflyIIIClient(FIREFLY_URL, FIREFLY_API_KEY);

foreach ($fireflyClient->get("tags/" . FIREFLY_UNCONFIRMED_TAG . "/transactions")["data"] as $transaction) {
    $transactionDate = new DateTime($transaction["attributes"]["date"]);
    $twoWeeksAgo = new DateTime('-2 weeks');
    if ($transactionDate < $twoWeeksAgo) {
        $fireflyClient->makeRequest('DELETE', "transactions/" . $transaction["id"]);
    }
}