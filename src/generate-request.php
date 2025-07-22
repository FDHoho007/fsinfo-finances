<?php

require_once("lib/config.php");
require_once("lib/firefly.php");

function pdftk($input, $operation, $output) {
    $input = array_map('escapeshellarg', is_array($input) ? $input : [$input]);
    shell_exec("pdftk " . implode(" ", $input) . " $operation output " . escapeshellarg($output));
}

function encode_fdf_utf16($str) {
    $utf16 = "\xFE\xFF" . mb_convert_encoding($str, 'UTF-16BE', 'UTF-8');
    $escaped = '';
    for ($i = 0; $i < strlen($utf16); $i++) {
        $escaped .= sprintf('\\%03o', ord($utf16[$i]));
    }
    return $escaped;
}

function set_fdf_value($fdfContent, $key, $value) {
    $value = encode_fdf_utf16($value);
    $pattern = '/^\/T \(' . preg_quote($key, '/') . '\)$/m';
    if (preg_match($pattern, $fdfContent, $matches, PREG_OFFSET_CAPTURE)) {
        $lineStart = $matches[0][1];
        $lineEnd = strpos($fdfContent, "\n", strpos($fdfContent, "\n", $lineStart)+1);
        if ($lineEnd === false) $lineEnd = strlen($fdfContent);
        $fdfContent = substr_replace($fdfContent, $matches[0][0] . "\n/V ($value)", $lineStart, $lineEnd - $lineStart);
    }
    return $fdfContent;
}

function delete_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $filePath = "$dir/$file";
        if (is_dir($filePath)) {
            delete_directory($filePath);
        } else {
            unlink($filePath);
        }
    }
    rmdir($dir);
}

$oauth2Client = new FireflyIIIOAuth2Client(FIREFLY_URL, OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, OAUTH_REDIRECT_URI);

if (!isset($_GET['code']) && !isset($_POST["access_token"])) {
    if(isset($_GET["id"])) {
        $oauth2Client->authorize($_GET["id"]);
    } else {
        $oauth2Client->authorize();
    }
}

if(isset($_POST["access_token"])) {
    $accessToken = $_POST["access_token"];
} else if(isset($_GET['code'])) {
    $accessToken = $oauth2Client->token($_GET['code']);
    if(isset($_GET["state"])) {
        $id = $_GET["state"];
    }
}

if (empty($accessToken)) {
    $oauth2Client->authorize();
}

$fireflyClient = new FireflyIIIClient(FIREFLY_URL, $accessToken);

if(isset($_POST["id"])) {
    $id = $_POST["id"];
}
if(isset($id)) {
    $transaction = $fireflyClient->get("transactions/$id")["data"]["attributes"]["transactions"][0];
    if($transaction["type"] !== "withdrawal") {
        throw new Exception('Selected transaction is not an expense.');
    }
    if($transaction["source_id"] !== FIREFLY_ACCOUNT_ID) {
        throw new Exception('Selected transaction is not from the primary Firefly account.');
    }
    $tmpDir = sys_get_temp_dir() . '/refund_' . uniqid();
    if (!mkdir($tmpDir, 0700, true)) {
        throw new Exception('Failed to create temporary directory: ' . $tmpDir);
    }
    $attachments = ["$tmpDir/refund.pdf"];
    if($transaction["has_attachments"]) {
        foreach($fireflyClient->get("transactions/$id/attachments")["data"] as $attachment) {
            $ch = curl_init($attachment["attributes"]["download_url"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpcode === 200) {
                $filePath = $tmpDir . '/' . $attachment["attributes"]["filename"];
                file_put_contents($filePath, $response);
                $attachments[] = $filePath;
            } else {
                throw new Exception('Error fetching attachment: ' . $downloadUrl);
                delete_directory($tmpDir);
            }
        }
    }

    $fireflyClient->makeRequest('PUT', "transactions/$id", ["transactions" => [["tags" => array_merge($transaction["tags"], [FIREFLY_SUBMITTED_TAG])]]], false);
    $accountAttributes = $fireflyClient->get("accounts/" . $transaction["destination_id"])["data"]["attributes"];
    $accountAttributes = $fireflyClient->getAccountAttributes($accountAttributes, FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES);
    $transactionAttributes = array_merge([
        "amount" => number_format(floatval($transaction["amount"]), 2, ',', ''),
        "notes" => str_replace(", ", "\n", isset($transaction["notes"]) ? $transaction["notes"] : ""),
        "description" => (empty($transaction["category_name"]) ? "" : $transaction["category_name"] . " - ") . $transaction["description"],
        "date" => date("d.m.Y", strtotime($transaction["date"])),
    ], $accountAttributes);

    $templateFile = "pdf-templates/" . PDF_TEMPLATES[null];
    foreach($transaction["tags"] as $tag) {
        if(array_key_exists($tag, PDF_TEMPLATES)) {
            $templateFile = "pdf-templates/" . PDF_TEMPLATES[$tag];
            break;
        }
    }
    pdftk($templateFile, "generate_fdf", "$tmpDir/refund.fdf");
    $fdfContent = file_get_contents($tmpDir . '/refund.fdf');
    foreach($transactionAttributes as $key => $value) {
        $fdfContent = set_fdf_value($fdfContent, $key, $value);
    }
    file_put_contents($tmpDir . '/refund.fdf', $fdfContent);
    pdftk($templateFile, "fill_form " . escapeshellarg("$tmpDir/refund.fdf"), "$tmpDir/refund.pdf");

    $pdfFile = "$tmpDir/refund-combined.pdf";
    pdftk($templateFile, "dump_data", "$tmpDir/refund.txt");
    pdftk($attachments, "cat", "$tmpDir/refund-combined-no-data.pdf");
    pdftk("$tmpDir/refund-combined-no-data.pdf", "update_info " . escapeshellarg("$tmpDir/refund.txt"), $pdfFile);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="refund-request-' . $id . '.pdf"');
    header('Content-Length: ' . filesize($pdfFile));
    header('Cache-Control: private');
    flush();
    readfile($pdfFile);
    flush();

    delete_directory($tmpDir);
} else { ?>

<!doctype html>
<html>

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Refund Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>

</head>

<body>

<div class="container d-flex justify-content-center align-items-center" style="min-height: 100vh;">
    <div class="card p-4 shadow" style="min-width: 350px;">
        <h3 class="mb-3 text-center">Please select a transaction</h3>
        <form method="post">
            <div class="mb-3">
                <label for="id" class="form-label">Select a transaction to generate a refund request for:</label>
                <select class="form-select" id="id" name="id" required>
                    <option value="" disabled selected></option>
                    <?php
                        $transactions = $fireflyClient->get("transactions?type=expense")["data"];
                        foreach($transactions as $transaction) {
                            $attributes = $transaction["attributes"]["transactions"][0];
                            if(!in_array(FIREFLY_SUBMITTED_TAG, $attributes["tags"]) && !in_array(FIREFLY_PROCESSED_TAG, $attributes["tags"])) {
                                echo("<option value=\"" . $transaction["id"] . "\">#" . $transaction["id"] . " - " . $attributes["description"] . " - " . floatval($attributes["amount"]) . $attributes["currency_symbol"] . "</option>\n");
                            }
                        }
                    ?>
                </select>
            </div>
            <input type="hidden" name="access_token" value="<?php echo htmlspecialchars($accessToken); ?>">
            <div class="mb-3">
                <div class="alert alert-info" role="alert">
                    Only transactions, that have not been submitted are considered.
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Continue</button>
        </form>
    </div>
</div>

</body>

</html>

<?php }