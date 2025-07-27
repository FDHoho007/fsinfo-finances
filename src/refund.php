<?php

    require_once("lib/config.php");
    require_once("lib/firefly.php");
    require_once("lib/account-sync.php");

    $fireflyClient = new FireflyIIIClient(FIREFLY_URL, FIREFLY_API_KEY);
    const LDAP_ATTRIBUTES = ["givenName", "sn", "fsinfoIBAN", "fsinfoBirthdayDate", "postalAddress", "fsinfoTaxId"];

    function validateString($value) {
        return preg_match('/^[a-zA-Z0-9äöüÄÖÜß\s,\-\.!?]{1,255}$/', $value);
    }

    function validateNumber($value) {
        return preg_match('/^\d{1,10}((\,|\.)\d{1,2})?$/', $value);
    }

    function validateEmail($value) {
        return preg_match('/^.+@.+\..+$/', $value) && strlen($value) <= 255;
    }

    function validateIBAN($value) {
        return preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $value);
    }

    function validateTaxId($value) {
        return preg_match('/^\d{11}$/', $value);
    }

    function validateDate($value) {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $filePath = "$dir/$file";
            if (is_dir($filePath)) {
                deleteDirectory($filePath);
            } else {
                unlink($filePath);
            }
        }
        rmdir($dir);
    }

    function getLDAPUserdata($username, $password) {
        $ldapconn = ldap_connect(LDAP_HOST);
        if (!$ldapconn) {
            return false;
        }
        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
        $bind = @ldap_bind($ldapconn, "uid=" . ldap_escape($username, "", LDAP_ESCAPE_DN) . "," . LDAP_USER_BASE_DN, $password);
        if (!$bind) {
            return false;
        }
        $search = ldap_search($ldapconn, LDAP_USER_BASE_DN, "(uid=" . ldap_escape($username, "", LDAP_ESCAPE_FILTER) . ")", array_merge(["email"], LDAP_ATTRIBUTES));
        if(!$search) {
            return false;
        }
        $entries = ldap_get_entries($ldapconn, $search);
        return $entries["count"] > 0 ? $entries[0] : false;
    }

    function submitRequest() {
        global $fireflyClient;
        $requiredFields = ["fsinfo-username", "fsinfo-password", "person-email", "person-name", "person-iban", "person-dob", "person-taxid", "person-address", "refund-category", "refund-description", "refund-amount", "refund-notes"];
        foreach($requiredFields as $field) {
            if(!isset($_POST[$field])) {
                echo("Bitte füllen Sie alle erforderlichen Felder aus.");
                exit;
            }
        }
        $prefilledFields = [];
        foreach($requiredFields as $field) {
            $prefilledFields[$field] = $_POST[$field];
        }
        $hasFSAccount = true;
        foreach($requiredFields as $field) {
            if(str_starts_with($field, "person-") && !empty($_POST[$field])) {
                $hasFSAccount = false;
                break;
            }
        }
        if($hasFSAccount) {
            $user = getLDAPUserdata($_POST["fsinfo-username"], $_POST["fsinfo-password"]);
            if(!$user) {
                return [2, $prefilledFields];
            }
            foreach(LDAP_ATTRIBUTES as $attr) {
                if(empty($user[strtolower($attr)][0])) {
                    return [3, $prefilledFields];
                }
            }
            $name = $user["givenname"][0] . " " . $user["sn"][0];
            $email = $user["email"][0];
            $syncableUser = getSyncableUser($user);
            $account = $fireflyClient->get("search/accounts?field=name&type=expense&query=" . urlencode($user["sn"][0] . ", " . $user["givenname"][0]));
            if(sizeof($account["data"]) == 0 || $account["data"][0]["attributes"]["name"] != $user["sn"][0] . ", " . $user["givenname"][0]
                 || diffAccount($syncableUser, getSyncableAccount($fireflyClient, $account["data"][0])) != 0) {
                syncAccounts($fireflyClient, [$syncableUser]);
            }
            if(sizeof($account["data"]) == 0) {
                $account = $fireflyClient->get("search/accounts?field=name&type=expense&query=" . urlencode($user["sn"][0] . ", " . $user["givenname"][0]));
            }
            $destination = $account["data"][0]["id"];
        } else {
            $email = $_POST["person-email"];
            $name = $_POST["person-name"];
            $iban = str_replace(" ", "", $_POST["person-iban"]);
            $dob = $_POST["person-dob"];
            $taxId = str_replace(" ", "", $_POST["person-taxid"]);
            $address = str_replace("\r\n", "\n", $_POST["person-address"]);
            if(!validateEmail($email) || !validateString($name) || !validateIBAN($iban) || !validateDate($dob) || !validateTaxId($taxId) || !validateString($address)) {
                return [4, $prefilledFields];
            }
            $name = explode(" ", $name);
            $name = $name[1] . ", " . $name[0] . " (ext.)";
            $partner = [
                "name" => $name,
                "email" => $email,
                "iban" => $iban,
                "dob" => $dob,
                "tin" => $taxId,
                "address" => $address
            ];
            $destination = "-1";
            $requiresMailVerification = $email;
            // TODO: Remove when ext. users working
            return [4, $prefilledFields];
        }
        $category = $_POST["refund-category"];
        $description = $_POST["refund-description"];
        $amount = $_POST["refund-amount"];
        $notes = $_POST["refund-notes"];
        try {
            $fireflyClient->get("categories/$category");
        } catch (Exception $e) {
            return [5, $prefilledFields];
        }
        if(!validateString($description)) {
            return [5, $prefilledFields];
        }
        if (!validateNumber($amount)) {
            return [6, $prefilledFields];
        }
        if(!validateString($notes) && $notes !== "") {
            return [8, $prefilledFields];
        }
        $transaction = [
            "type" => "withdrawal",
            "amount" => floatval(str_replace(",", ".", $amount)),
            "description" => htmlspecialchars($description),
            "notes" => htmlspecialchars($notes),
            "date" => date("c"),
            "category_id" => $category,
            "source_id" => FIREFLY_ACCOUNT_ID,
            "destination_id" => $destination,
            "tags" => [FIREFLY_UNCONFIRMED_TAG]
        ];
        $requestId = uniqid("refund-request-");
        $transactionDir = __DIR__ . '/tmp/' . $requestId;
        if(!is_dir($transactionDir)) {
            mkdir($transactionDir);
        }
        $attachments = [];
        foreach(["refund-invoice", "refund-payment-proof"] as $type) {
            if (!empty($_FILES[$type]['name'][0])) {
                foreach ($_FILES[$type]['tmp_name'] as $key => $tmpName) {
                    $fileName = basename($_FILES[$type]['name'][$key]);
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    if (!in_array($fileExtension, ['pdf', 'jpg', 'jpeg', 'png'])) {
                        deleteDirectory($transactionDir);
                        return [7, $prefilledFields];
                    }
                    $targetFile = $transactionDir . '/' . $fileName;
                    if (!move_uploaded_file($tmpName, $targetFile)) {
                        deleteDirectory($transactionDir);
                        return [7, $prefilledFields];
                    }
                    $attachments[] = $targetFile;
                }
            }
        }
        if(isset($requiresMailVerification)) {
            file_put_contents($transactionDir . '/request.json', json_encode($transaction));
            file_put_contents($transactionDir . '/partner.json', json_encode($$partner));
            $verificationLink = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "?verify=" . $requestId;
            $subject = '=?UTF-8?B?' . base64_encode('FSinfo Rückerstattungsantrag') . '?=';
            $message = "Hallo,\n\nfür deine E-Mail-Adresse wurde soeben versucht einen Rückerstattungsantrag in Höhe von " . $transaction["amount"] . "€ beim Finanzteam der Fachschaft Info einzureichen.\n\nWenn du das gewesen bist, klicke bitte auf den folgenden Link, um deinen Rückerstattungsantrag zu bestätigen: $verificationLink\nDieser Link ist für eine Stunde gültig.\n\nWenn du das nicht warst, ignoriere diese E-Mail bitte einfach.\n\nViele Grüße,\nFSinfo Finanzteam";
            $headers = "Content-Type: text/plain; charset=UTF-8\r\nFrom: =?UTF-8?B?" . base64_encode('FSinfo Rückerstattungsantrag') . "?= <refund@zimvm.fsinfo.fim.uni-passau.de>\r\n";
            mail($email, $subject, $message, $headers);
            return [10, []];
        } else {
            createTransaction($fireflyClient, $transactionDir, $transaction, $attachments, $name, $email);
            return [9, []];
        }
    }

    function createTransaction($fireflyClient, $transactionDir, $transaction, $attachments, $name, $email) {
        $ffTransaction = $fireflyClient->makeRequest('POST', 'transactions', ["apply_rules" => true, "fire_webhooks" => true, "transactions" => [$transaction]], true);
        foreach($attachments as $attachment) {
            $ffAttachment = $fireflyClient->makeRequest('POST', 'attachments', [
                "filename" => basename($attachment),
                "attachable_type" => "TransactionJournal",
                "attachable_id" => $ffTransaction["data"]["id"],
            ], true);
            $fireflyClient->makeRequest('POST', 'attachments/' . $ffAttachment["data"]["id"] . '/upload', file_get_contents($attachment), true, true);
        }
        $message = "Liebe Finanzer,\n\n$name hat soeben einen Rückerstattungsantrag gestellt.\n" .
            "Diesen könnt ihr hier einsehen: " . FIREFLY_URL . "/transactions/show/" . $ffTransaction["data"]["id"] . "\n" .
            "Sofern diese Zahlung so vereinbart ist, entfernt bitte den Tag \"unbestätigt\" von der Transaktion, da diese sonst in zwei Wochen gelöscht wird.\n\n" .
            "Viele Grüße,\nRückerstattungsformular";
        $headers = [
            "From: =?UTF-8?B?" . base64_encode($name) . "?= <refund@zimvm.fsinfo.fim.uni-passau.de>", 
            "Reply-To: =?UTF-8?B?" . base64_encode($name) . "?= <" . $email . ">"
        ];
        mail("finanzen@fsinfo.fim.uni-passau.de", '=?UTF-8?B?' . base64_encode('Rückerstattungsantrag von ' . $name) . '?=', $message, implode("\r\n", $headers));
        deleteDirectory($transactionDir);
    }

    if(isset($_GET["verify"])) {
        $requestId = $_GET["verify"];
        if (!preg_match('/^refund-request-[a-f0-9]{13}$/', $requestId)) {
            die("Invalid verify parameter.");
        }
        $transactionDir = __DIR__ . '/tmp/' . $requestId;
        if(!is_dir($transactionDir)) {
            echo("Dieser Link ist ungültig oder bereits abgelaufen.");
            exit;
        }
        $transaction = json_decode(file_get_contents($transactionDir . '/request.json'), true);
        $partner = json_decode(file_get_contents($transactionDir . '/partner.json'), true);
        if(empty($transaction) || empty($partner)) {
            echo("Ungültige Verifizierungsanfrage.");
            exit;
        }
        $attachments = [];
        foreach (scandir($transactionDir) as $file) {
            $filePath = $transactionDir . '/' . $file;
            if (is_file($filePath) && !str_ends_with($file, '.json')) {
                $attachments[] = $filePath;
            }
        }
        $account = $fireflyClient->get("search/accounts?field=name&type=expense&query=" . urlencode($partner["name"]));
        if(sizeof($account["data"]) == 0) {
            createAccount($partner);
            $account = $fireflyClient->get("search/accounts?field=name&type=expense&query=" . urlencode($partner["name"]));
            $transaction["destination_id"] = $account["data"][0]["id"];
        } else {
            if(diffAccount($partner, getSyncableAccount($account)) != 0) {
                $view = 9;
            } else {
                $transaction["destination_id"] = $account["data"][0]["id"];
            }
        }
        if(!isset($view)) {
            createTransaction($fireflyClient, $transactionDir, $transaction, $attachments, $partner["name"], $partner["email"]);
            $view = 9;
        }
    } else if(isset($_POST["action"])) {
        if($_POST["action"] == "checkAddressbookData" && isset($_POST["username"]) && isset($_POST["password"])) {
            $user = getLDAPUserdata($_POST["username"], $_POST["password"]);
            echo(json_encode([
                "credentialsCorrect" => $user !== false,
                "addressbookData" => array_map(function($attr) use ($user) {
                        return $user && !empty($user[strtolower($attr)][0]);
                    }, LDAP_ATTRIBUTES)
            ]));
            exit;
        } else if($_POST["action"] == "submit") {
            [$view, $prefilledFields] = submitRequest();
        }
    }

?>
<!DOCTYPE html>
<html lang="de">

    <head>

        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>FSinfo Rückerstattungsantrag</title>
        <meta name="author" content="Fabian Dietrich">
        <meta name="description" content="FSinfo Rückerstattungsantrag">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

        <style>

            input[name="view"] {
                display: none;
            }

            .view {
                display: none;
                text-align: center;
            }

            .buttons {
                margin-top: 15px;
            }

            .buttons label {
                margin: 0 5px;
            }

            #view3-radio:checked ~ .row nav #step-1,
            #view4-radio:checked ~ .row nav #step-1,
            #view5-radio:checked ~ .row nav #step-1,
            #view5-radio:checked ~ .row nav #step-2,
            #view6-radio:checked ~ .row nav #step-1,
            #view6-radio:checked ~ .row nav #step-2,
            #view7-radio:checked ~ .row nav #step-1,
            #view7-radio:checked ~ .row nav #step-2,
            #view7-radio:checked ~ .row nav #step-3,
            #view8-radio:checked ~ .row nav #step-1,
            #view8-radio:checked ~ .row nav #step-2,
            #view8-radio:checked ~ .row nav #step-3,
            #view8-radio:checked ~ .row nav #step-4,
            #view9-radio:checked ~ .row nav #step-1,
            #view9-radio:checked ~ .row nav #step-2,
            #view9-radio:checked ~ .row nav #step-3,
            #view9-radio:checked ~ .row nav #step-4,
            #view9-radio:checked ~ .row nav #step-5,
            #view10-radio:checked ~ .row nav #step-1,
            #view10-radio:checked ~ .row nav #step-2,
            #view10-radio:checked ~ .row nav #step-3,
            #view10-radio:checked ~ .row nav #step-4,
            #view10-radio:checked ~ .row nav #step-5 {
                color: #367e38 !important;
            }

            #view1-radio:checked ~ .row #view1,
            #view2-radio:checked ~ .row #view2,
            #view3-radio:checked ~ .row #view3,
            #view4-radio:checked ~ .row #view4,
            #view5-radio:checked ~ .row #view5,
            #view6-radio:checked ~ .row #view6,
            #view7-radio:checked ~ .row #view7,
            #view8-radio:checked ~ .row #view8,
            #view9-radio:checked ~ .row #view9,
            #view10-radio:checked ~ .row #view10 {
                display: block;
            }

            #view6:has(#refund-cash:checked) ~ #view7 p:nth-child(2),
            #view6:has(#refund-cash:checked) ~ #view7 .row:nth-child(4) {
                display: none;
            }

            <?php if(isset($view) && $view == 3) { ?>
                #addressbookCheck li {
                    color: red;
                }
            <?php } ?>

        </style>

        <script>
            let submitClicked = false;
            function checkSubmitButton(e) {
                if (!submitClicked) {
                    let currentView = 1;
                    document.getElementsByName("view").forEach((radio) => {
                        if (radio.checked) {
                            currentView = parseInt(radio.id.replace("view", "").replace("-radio", ""));
                        }
                    });
                    if (currentView < 9) {
                        document.getElementById(`view${currentView + 1}-radio`).checked = true;
                        e.preventDefault();
                        return false;
                    }
                }
                submitClicked = false;
                return true;
            }
            function checkAddressbookData() {
                const username = encodeURIComponent(document.getElementById("fsinfo-username").value);
                const password = encodeURIComponent(document.getElementById("fsinfo-password").value);

                fetch("refund.php", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=checkAddressbookData&username=${username}&password=${password}`
                })
                .then(response => response.json())
                .then(data => {
                    if(!data.credentialsCorrect) {
                        document.getElementById("invalidCredentials").style.display = "block";
                        document.getElementById("view2-radio").checked = true;
                        return;
                    }
                    document.getElementById("invalidCredentials").style.display = "none";
                    let li = document.querySelectorAll("#addressbookCheck li");
                    for(let i = 0; i < data.addressbookData.length; i++) {
                        li[i].style.color = data.addressbookData[i] ? "green" : "red";
                    }
                })
                .catch(error => {
                    console.error('Fehler:', error);
                    alert("Ein Fehler ist aufgetreten. Siehe Konsole für Details.");
                });
            }
        </script>

    </head>

    <body>

        <form class="container mt-5 border rounded p-3" method="post" enctype="multipart/form-data">

            <input type="hidden" name="action" value="submit">
            <?php 
                if(!isset($view) || $view < 1 || $view > 10) {
                    $view = 1;
                }
                for($i = 1; $i <= 10; $i++) {
                    echo("<input type=\"radio\" name=\"view\" id=\"view$i-radio\"" . ($view == $i ? " checked" : "") . ">");
                }
            ?>

            <div class="row">
                <div class="col p-0 border-bottom">
                    <h1 class="text-center mb-4">FSinfo Rückerstattungsantrag</h1>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 p-0 border-end p-3">
                    <nav>
                        <div id="step-1"><i class="bi bi-check-circle"></i> Anmeldung</div>
                        <div id="step-2"><i class="bi bi-check-circle"></i> Angaben zur Person</div>
                        <div id="step-3"><i class="bi bi-check-circle"></i> Angaben zur Rückerstattung</div>
                        <div id="step-4"><i class="bi bi-check-circle"></i> Belege anhängen</div>
                        <div id="step-5"><i class="bi bi-check-circle"></i> Rückerstattung einreichen</div>
                    </nav>
                </div>

                <div id="view1" class="view col-md-8 p-0 p-3">
                    <p>
                        Willkommen beim Rückerstattungsformular der FSinfo!<br>
                        Wenn du Geld für die FSinfo ausgelegt hast, kannst du hier deine Belege einreichen und damit eine Rückerstattung beantragen.<br><br>
                        Du kannst mehrere zusammenhängende Belege in einem Antrag einreichen, z.B. wenn du für eine Veranstaltung mehrere Ausgaben hattest.<br><br>
                        Hast du bereits einen FSinfo Account?
                    </p>
                    <div class="buttons">
                        <label for="view4-radio" class="btn btn-danger">Nein</label>
                        <label for="view2-radio" class="btn btn-success">Ja</label>
                    </div>
                </div>

                <div id="view2" class="view col-md-8 p-0 p-3">
                    <p>
                        Da du bereits einen FSinfo Account hast, können wir deine Zahlungsinformationen direkt aus dem <a target="_blank" href="https://fsinfo.fim.uni-passau.de/addressbook/">Adressbuch</a> auslesen.<br>
                        Dazu musst du dich mit deinem FSinfo Account anmelden.
                    </p>
                    <div id="invalidCredentials" class="alert alert-danger"<?php if($view != 2) echo(" style=\"display: none;\""); ?>>
                        Die eingegebenen Zugangsdaten sind ungültig. Bitte überprüfe deinen Benutzernamen und dein Passwort.
                    </div>
                    <div class="row justify-content-center">
                        <div class="col-md-5 mb-3 text-start">
                            <label for="fsinfo-username" class="form-label">FSinfo Benutzername:</label>
                            <input type="text" class="form-control" id="fsinfo-username" name="fsinfo-username"
                                value="<?php echo isset($prefilledFields['fsinfo-username']) ? htmlspecialchars($prefilledFields['fsinfo-username']) : ''; ?>">
                        </div>
                    </div>
                    <div class="row justify-content-center">
                        <div class="col-md-5 mb-3 text-start">
                            <label for="fsinfo-password" class="form-label">FSinfo Passwort:</label>
                            <input type="password" class="form-control" id="fsinfo-password" name="fsinfo-password"
                                value="<?php echo isset($prefilledFields['fsinfo-password']) ? htmlspecialchars($prefilledFields['fsinfo-password']) : ''; ?>">
                        </div>
                    </div>
                    <div class="buttons">
                        <label for="view1-radio" class="btn btn-secondary">Zurück</label>
                        <label for="view3-radio" class="btn btn-success">Weiter</label>
                    </div>
                </div>

                <div id="view3" class="view col-md-8 p-0 p-3">
                    <p>
                        Bevor du deinen Antrag einreichen kannst, musst du sicherstellen, dass alle benötigten Daten im <a target="_blank" href="https://fsinfo.fim.uni-passau.de/addressbook/">Adressbuch</a> hinterlegt und aktuell sind.
                        In Ausnahmefällen werden zusätzliche Informationen benötigt. Ein Finanzer wird dich darüber informieren, ob es sich bei deinem Antrag um eine solche Ausnahme handelt.
                    </p>
                    <div id="addressbookCheck" class="row justify-content-center mb-4">
                        <div class="col-md-5">
                            <h5>Für jeden Antrag erforderlich</h5>
                            <ul class="list-group">
                                <li class="list-group-item">Vorname</li>
                                <li class="list-group-item">Nachname</li>
                                <li class="list-group-item">IBAN</li>
                            </ul>
                        </div>
                        <div class="col-md-5">
                            <h5>Nur in Ausnahmen erforderlich</h5>
                            <ul class="list-group">
                                <li class="list-group-item">Geburtsdatum</li>
                                <li class="list-group-item">Adresse</li>
                                <li class="list-group-item">Steuer-ID</li>
                            </ul>
                        </div>
                    </div>
                    <p>
                        Du hast nun die Möglichkeit mit JavaScript zu überprüfen, welche deiner Daten bereits im Adressbuch hinterlegt sind.<br>
                        <button type="button" class="btn btn-primary" onclick="checkAddressbookData();">Mit JavaScript überprüfen</button>
                    </p>
                    <div class="buttons">
                        <label for="view1-radio" class="btn btn-secondary">Zurück</label>
                        <label for="view5-radio" class="btn btn-success">Weiter</label>
                    </div>
                </div>

                <div id="view4" class="view col-md-8 p-0 p-3">
                    <p>
                        Für deine Rückerstattung werden eine Reihe an persönlichen Informationen benötigt. Da du keinen FSinfo Account hast, musst du diese Informationen hier manuell eingeben.<br>
                        In Ausnahmefällen werden zusätzliche Informationen benötigt. Ein Finanzer wird dich darüber informieren, ob es sich bei deinem Antrag um eine solche Ausnahme handelt.
                    </p>
                    <?php if($view == 4) { ?>
                        <div class="alert alert-danger" role="alert">
                            Hinweis: Die eingegebenen persönlichen Daten sind ungültig oder entsprechen nicht dem erlaubten Format. Bitte überprüfe deine Eingaben und versuche es erneut.
                        </div>
                    <?php } ?>
                    <div class="row justify-content-center mb-4">
                        <div class="col-md-5">
                            <h5>Für jeden Antrag erforderlich</h5>
                            <div class="row justify-content-center">
                                <div class="col-md-8 mb-3 text-start">
                                    <label for="person-email" class="form-label">E-Mail-Adresse:</label>
                                    <input type="email" class="form-control" id="person-email" name="person-email"
                                        maxlength="255"
                                        pattern="^.+@.+\..+$"
                                        value="<?php echo isset($prefilledFields['person-email']) ? htmlspecialchars($prefilledFields['person-email']) : ''; ?>">
                                </div>
                                <div class="col-md-8 mb-3 text-start">
                                    <label for="person-name" class="form-label">Vor- und Nachname:</label>
                                    <input type="text" class="form-control" id="person-name" name="person-name"
                                        maxlength="255"
                                        pattern="^[a-zA-ZäöüÄÖÜß\s,\-\.!?]{1,255}$"
                                        value="<?php echo isset($prefilledFields['person-name']) ? htmlspecialchars($prefilledFields['person-name']) : ''; ?>">
                                </div>
                                <div class="col-md-8 mb-3 text-start">
                                    <label for="person-iban" class="form-label">IBAN:</label>
                                    <input type="text" class="form-control" id="person-iban" name="person-iban"
                                        maxlength="34"
                                        pattern="^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$"
                                        value="<?php echo isset($prefilledFields['person-iban']) ? htmlspecialchars($prefilledFields['person-iban']) : ''; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <h5>Nur in Ausnahmen erforderlich</h5>
                            <div class="row justify-content-center">
                                <div class="col-md-8 mb-3 text-start">
                                    <label for="person-dob" class="form-label">Geburtsdatum:</label>
                                    <input type="date" class="form-control" id="person-dob" name="person-dob"
                                        value="<?php echo isset($prefilledFields['person-dob']) ? htmlspecialchars($prefilledFields['person-dob']) : ''; ?>">
                                </div>
                                <div class="col-md-8 mb-3 text-start">
                                    <label for="person-taxid" class="form-label">Steuer-ID:</label>
                                    <input type="number" class="form-control" id="person-taxid" name="person-taxid"
                                        max="99999999999"
                                        value="<?php echo isset($prefilledFields['person-taxid']) ? htmlspecialchars($prefilledFields['person-taxid']) : ''; ?>">
                                </div>
                                <div class="col-md-8 mb-3 text-start">
                                    <label for="person-address" class="form-label">Adresse:</label>
                                    <textarea class="form-control" id="person-address" name="person-address" rows="2"
                                        maxlength="255"
                                        pattern="^[a-zA-Z0-9äöüÄÖÜß\s,\-\.!?]{1,255}$"
                                        ><?php echo isset($prefilledFields['person-address']) ? htmlspecialchars($prefilledFields['person-address']) : ''; ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="buttons">
                        <label for="view1-radio" class="btn btn-secondary">Zurück</label>
                        <label for="view5-radio" class="btn btn-success">Weiter</label>
                    </div>
                </div>

                <div id="view5" class="view col-md-8 p-0 p-3">
                    <p>
                        Bitte gib an, wofür du Geld ausgelegt hast. Gib bitte außerdem mit an, ob deine Auslage zu einer Veranstaltung gehört.
                    </p>
                    <?php if($view == 5) { ?>
                        <div class="alert alert-danger" role="alert">
                            Hinweis: Die ausgewählte Veranstaltung oder der Verwendungszweck sind ungültig oder entsprechen nicht dem erlaubten Format. Bitte überprüfe deine Auswahl und Beschreibung und versuche es erneut.
                        </div>
                    <?php } ?>
                    <div class="row justify-content-center">
                        <div class="col-md-5 mb-3 text-start">
                            <label for="refund-category" class="form-label">Veranstaltung:</label>
                            <select class="form-select" id="refund-category" name="refund-category">
                                <option value=""<?php echo empty($prefilledFields['refund-category']) ? ' selected' : ''; ?>>keine</option>
                                <?php 
                                    foreach($fireflyClient->get("categories")["data"] as $category) {
                                        if(!str_contains($category["attributes"]["notes"], "private")) {
                                            $selected = (isset($prefilledFields['refund-category']) && $prefilledFields['refund-category'] == $category["id"]) ? ' selected' : '';
                                            echo("<option value=\"" . $category["id"] . "\"$selected>" . htmlspecialchars($category["attributes"]["name"]) . "</option>");
                                        }
                                    }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="row justify-content-center">
                        <div class="col-md-5 mb-3 text-start">
                            <label for="refund-description" class="form-label">Verwendungszweck:</label>
                            <textarea class="form-control" id="refund-description" name="refund-description" pattern="^[a-zA-Z0-9äöüÄÖÜß\s,\-\.!?]+$" maxlength="255"><?php echo isset($prefilledFields['refund-description']) ? htmlspecialchars($prefilledFields['refund-description']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div class="buttons">
                        <label for="view1-radio" class="btn btn-secondary">Zurück</label>
                        <label for="view6-radio" class="btn btn-success">Weiter</label>
                    </div>
                </div>

                <div id="view6" class="view col-md-8 p-0 p-3">
                    <p>
                        Bitte gib an, wie viel Geld du ausgelegt hast und ob du es bar bezahlt hast.
                    </p>
                    <?php if($view == 6) { ?>
                        <div class="alert alert-danger" role="alert">
                            Hinweis: Der ausgelegte Betrag ist ungültig oder entspricht nicht dem erlaubten Format. Bitte überprüfe deine Eingabe und versuche es erneut.
                        </div>
                    <?php } ?>
                    <div class="row justify-content-center">
                        <div class="col-md-5 text-start">
                            <label for="refund-amount" class="form-label">Ausgelegter Betrag (in EUR):</label>
                            <input type="number" class="form-control" id="refund-amount" name="refund-amount" step="0.01" min="0.01" max="9999999.99"
                                value="<?php echo isset($prefilledFields['refund-amount']) ? htmlspecialchars($prefilledFields['refund-amount']) : ''; ?>">
                        </div>
                    </div>
                    <div class="row justify-content-center">
                        <div class="col-md-5 mb-3 text-start">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="refund-cash" name="refund-cash" value="true"
                                    <?php echo !empty($prefilledFields['refund-cash']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="refund-cash">Bar bezahlt</label>
                            </div>
                        </div>
                    </div>
                    <div class="buttons">
                        <label for="view5-radio" class="btn btn-secondary">Zurück</label>
                        <label for="view7-radio" class="btn btn-success">Weiter</label>
                    </div>
                </div>

                <div id="view7" class="view col-md-8 p-0 p-3">
                    <p>
                        Bitte lade alle Belege/Rechnungen/Kassenzettel hoch, die du für deine Auslage hast.
                    </p>
                    <p>
                        Da du das Geld nicht in bar ausgelegt hast, benötigt die Finanzabteilung zusätzlich einen Zahlungsnachweis, um sicherzustellen, dass du das Geld auch ausgelegt hast.
                        Ein typischer Zahlungsnachweis ist z.B. ein Einzelkontoauszug oder ein geschwärzter Kontoauszug, auf dem die Zahlung (und im Idealfall deine Bankverbindung) zu sehen ist.
                    </p>
                    <div class="row justify-content-center">
                        <div class="col-md-5 mb-3 text-start">
                            <label for="refund-invoice" class="form-label">Belege/Rechnungen/Kassenzettel</label>
                            <input class="form-control" type="file" id="refund-invoice" name="refund-invoice[]" accept=".pdf,.jpg,.jpeg,.png" multiple>
                        </div>
                    </div>
                    <div class="row justify-content-center">
                        <div class="col-md-5 mb-3 text-start">
                            <label for="refund-payment-proof" class="form-label">Zahlungsnachweise</label>
                            <input class="form-control" type="file" id="refund-payment-proof" name="refund-payment-proof[]" accept=".pdf,.jpg,.jpeg,.png" multiple>
                        </div>
                    </div>
                    <div class="buttons">
                        <label for="view6-radio" class="btn btn-secondary">Zurück</label>
                        <label for="view8-radio" class="btn btn-success">Weiter</label>
                    </div>
                </div>

                <div id="view8" class="view col-md-8 p-0 p-3">
                    <p>
                        <b>Wie es weitergeht:</b><br>
                        Nachdem du deinen Antrag eingereicht hast, werden die Fachschaftsfinanzer darüber informiert. Diese prüfen dann zeitnah deinen Antrag und die Belege.
                        Sollte es zu Unstimmigkeiten kommen, werden sie dich kontaktieren. Andernfalls wird dein Antrag weitergeleitet an die Finanzabteilung der Uni.<br>
                        Erfahrungsgemäß solltest du innerhalb von 4 Wochen dein Geld zurück erhalten. Ist das nicht der Fall, kannst du dich gerne bei den Fachschaftsfinanzern melden.<br>
                    </p>
                    <?php if($view == 8) { ?>
                        <div class="alert alert-danger" role="alert">
                            Hinweis: Die eingegebenen Notizen sind zu lang oder entsprechen nicht dem erlaubten Format. Dies sollte eigentlich nicht möglich sein. Bitte überprüfe deine Eingabe und versuche es erneut.
                        </div>
                    <?php } ?>
                    <div class="row justify-content-center">
                        <div class="col-md-5 mb-3 text-start">
                            <label for="refund-notes" class="form-label">Notizen/Weitere Infos für die Finanzer:</label>
                            <textarea class="form-control" id="refund-notes" name="refund-notes" pattern="^[a-zA-Z0-9äöüÄÖÜß\s,\-\.!?]+$" maxlength="255"><?php echo isset($prefilledFields['refund-notes']) ? htmlspecialchars($prefilledFields['refund-notes']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div class="buttons">
                        <label for="view7-radio" class="btn btn-secondary">Zurück</label>
                        <button type="button" class="btn btn-success" onclick="document.getElementsByTagName('form')[0].submit();">Einreichen</button>
                    </div>
                </div>

                <div id="view9" class="view col-md-8 p-0 p-3">
                    <p>
                        Dein Antrag wurde erfolgreich eingereicht!
                    </p>
                    <div class="buttons">
                        <label for="view1-radio" class="btn btn-secondary">Zurück zur Startseite</label>
                    </div>
                </div>

                <div id="view10" class="view col-md-8 p-0 p-3">
                    <p>
                        Da du keine FSinfo Kennung hast musst du zuerst deine E-Mail-Adresse bestätigen, bevor dein Antrag weitergeleitet werden kann.<br>
                        Bitte schaue in deinem E-Mail Postfach nach einer E-Mail mit dem Betreff "FSinfo Rückerstattungsantrag" und klicke auf den Link in der E-Mail.
                    </p>
                    <div class="buttons">
                        <label for="view1-radio" class="btn btn-secondary">Zurück zur Startseite</label>
                    </div>
                </div>

                <div id="view11" class="view col-md-8 p-0 p-3">
                    <p>
                        Unter deinem Namen wurde bereits ein Antrag eingereicht. Dieser hat allerdings unterschiedliche Bank- bzw. Personaldaten.<br>
                        Dieser Konflikt kann nicht automatisch gelöst werden, daher wird dein Antrag nicht eingereicht. Bitte wende dich an einen Fachschaftsfinanzer!
                    </p>
                    <div class="buttons">
                        <label for="view1-radio" class="btn btn-secondary">Zurück zur Startseite</label>
                    </div>
                </div>

            </div>

            <div class="row">
                <div class="col p-0 border-top">
                    <footer class="text-center mt-3">
                        Bei Fragen oder Problemen bitte <a href="mailto:admins@fsinfo.fim.uni-passau.de">admins@fsinfo.fim.uni-passau.de</a> kontaktieren.
                    </footer>
                </div>
            </div>

        </form>

    </body>

</html>