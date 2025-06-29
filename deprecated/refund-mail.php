<?php

    require('blz2bic.php');

    const VERIFICATION_FOLDER = '/var/www/refund-verification/';

    // This sends the actual refund request to the finance team including all details and attachments
    // See https://www.w3schools.in/php/examples/send-email-with-attachment
    function sendRefundRequest($details) {
        $subject = '=?UTF-8?B?' . base64_encode('FSinfo Rückerstattungsantrag von ' . $details['name']) . '?=';
        $formattedBirthdate = date('d.m.Y', strtotime($details["birthdate"]));
        $formattedIban = preg_replace('/(.{4})/', '$1 ', $details["iban"]);
        $formattedTaxId = preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{3})$/', '$1 $2 $3 $4', $details["taxId"]);
        $formattedAddress = str_replace("\n", ", ", $details["address"]);
        $formattedReason = str_replace("\n", ", ", $details["reason"]);
        $bar = $details["no-receipts"] ? "ja" : "nein";
        $znw = $details["no-receipts"] ? "" : "und Zahlungsnachweise ";
        $plain_message = <<<EOD
    Hallo liebe Finanzer,

    ich habe Geld für die FSinfo ausgelegt und möchte das nun gerne zurück.

    Angaben zu mir:
    Name: {$details["name"]}
    E-Mail: {$details["email"]}
    Geburtsdatum: {$details["birthdate"]}
    Adresse: {$formattedAddress}
    IBAN: {$formattedIban}
    Steuer-ID: {$formattedTaxId}

    Angaben zur Rückerstattung:
    Betrag: {$details["amount"]} €
    Bar bezahlt: {$bar}
    Grund: {$formattedReason}

    Rechnungen {$znw}sind angehängt.

    Viele Grüße,
    {$details["name"]}
    EOD;
        $attachment_dir = dirname($details["attachments"][0]);
        $blz = substr($details["iban"], 4, 8);
        $formFields = [
            "name" => encodeFdfUtf16($details["name"]),
            "dob" => $formattedBirthdate,
            "address" => encodeFdfUtf16($details["address"]),
            "iban" => $formattedIban,
            "bic" => BLZ2BIC[$blz] ?? '',
            "tin" => $formattedTaxId,
            "value" => $details["amount"],
            "use" => encodeFdfUtf16($details["reason"])
        ];
        // TODO: BIC
        $fdf = file_get_contents('refund.fdf');
        foreach ($formFields as $key => $value) {
            $fdf = str_replace('<' . $key . '>', $value, $fdf);
        }
        file_put_contents($attachment_dir . '/refund.fdf', $fdf);
        shell_exec("pdftk refund.pdf fill_form $attachment_dir/refund.fdf output $attachment_dir/refund.pdf");
        rename($attachment_dir . '/refund.pdf', $attachment_dir . '/Rückerstattung.pdf');
        unlink($attachment_dir . '/refund.fdf');
        $details["attachments"][] = $attachment_dir . '/Rückerstattung.pdf';
        $boundary = uniqid();
        $message = "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($plain_message));
        foreach($details["attachments"] as $attachment) {
            $filename = basename($attachment);
            $filename = '=?UTF-8?B?' . base64_encode($filename) . '?=';
            $message .= "--$boundary\r\n";
            $message .= "Content-Type: application/octet-stream; name=\"$filename\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
            $message .= chunk_split(base64_encode(file_get_contents($attachment)))."\r\n";
        }
        $message .= "--".$boundary."--";
        $headers = [
            "MIME-Version: 1.0",
            "Content-Type: multipart/mixed; boundary=\"$boundary\"",
            "From: =?UTF-8?B?" . base64_encode('FSinfo Rückerstattungsantrag') . "?= <refund@zimvm.fsinfo.fim.uni-passau.de>", 
            "Reply-To: =?UTF-8?B?" . base64_encode($details["name"]) . "?= <" . $details["email"] . ">"
        ];
        mail("finanzen@fsinfo.fim.uni-passau.de", $subject, $message, implode("\r\n", $headers));
        $dirs = [];
        foreach ($details["attachments"] as $attachment) {
            if (file_exists($attachment)) {
                unlink($attachment);
            }
            if (!in_array(dirname($attachment), $dirs)) {
                $dirs[] = dirname($attachment);
            }
        }
        foreach ($dirs as $dir) {
            if (count(scandir($dir)) === 2) {
                rmdir($dir);
            }
        }
        echo("Dein Rückerstattungsantrag wurde an das FSinfo Finanzteam weitergeleitet.");
        exit;
    }

    function encodeFdfUtf16($str) {
        $utf16 = "\xFE\xFF" . mb_convert_encoding($str, 'UTF-16BE', 'UTF-8');
        $escaped = '';
        for ($i = 0; $i < strlen($utf16); $i++) {
            $escaped .= sprintf('\\%03o', ord($utf16[$i]));
        }
        return $escaped;
    }


    function process_attachments($attachmentDir, &$details) {
        if(!is_dir($attachmentDir)) {
            mkdir($attachmentDir);
        }
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!empty($_FILES['invoice']['name'][0])) {
            foreach ($_FILES['invoice']['tmp_name'] as $key => $tmpName) {
                $fileName = basename($_FILES['invoice']['name'][$key]);
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileExtension, $allowedExtensions)) {
                    echo("Ungültiges Dateiformat für Rechnungen. Erlaubt sind nur PDF, JPG, JPEG und PNG.");
                    exit;
                }
                $targetFile = $attachmentDir . '/' . $fileName;
                if (!move_uploaded_file($tmpName, $targetFile)) {
                    echo("Fehler beim Hochladen der Rechnungen.");
                    exit;
                }
                $details['attachments'][] = $targetFile;
            }
        }

        if (!empty($_FILES['paymentProof']['name'][0])) {
            foreach ($_FILES['paymentProof']['tmp_name'] as $key => $tmpName) {
                $fileName = basename($_FILES['paymentProof']['name'][$key]);
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileExtension, $allowedExtensions)) {
                    echo("Ungültiges Dateiformat für Zahlungsnachweise. Erlaubt sind nur PDF, JPG, JPEG und PNG.");
                    exit;
                }
                $targetFile = $attachmentDir . '/' . $fileName;
                if (!move_uploaded_file($tmpName, $targetFile)) {
                    echo("Fehler beim Hochladen der Zahlungsnachweise.");
                    exit;
                }
                $details['attachments'][] = $targetFile;
            }
        }
    }

    // If the user clicks the verification link in the email, he gets here
    if(isset($_GET["verify-token"])) {
        $token = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET["verify-token"]);
        $file = VERIFICATION_FOLDER . $token . '.json';
        if(!file_exists($file)) {
            echo("Der Token ist ungültig oder abgelaufen.");
            exit;
        }
        $details = json_decode(file_get_contents($file), true);
        unlink($file);
        sendRefundRequest($details);
    }
    // If the user submits the form, he gets here
    if(isset($_POST['fsinfo-member']) && isset($_POST['amount']) && isset($_POST['reason'])) {
        $amount = $_POST['amount'];
        $reason = str_replace("\r\n", "\n", $_POST['reason']);
        if (!preg_match('/^\d+(\,\d{1,2})?$/', $amount) || strlen($amount) > 10) {
            echo("Der Betrag ist ungültig oder zu lang. Bitte gib einen gültigen Betrag ein.");
            exit;
        }
        if (!preg_match('/^[a-zA-Z0-9äöüÄÖÜß\s,\-\.!?]+$/', $reason) || strlen($reason) > 255) {
            echo("Der Grund enthält ungültige Zeichen oder ist zu lang.");
            exit;
        }
        if($_POST['fsinfo-member'] == 'ja' && isset($_POST['fsinfoUsername']) && isset($_POST['fsinfoPassword']) && isset($_POST['addressbook-complete'])) {
            // If the user is a FSinfo member, we can retrieve his details from the LDAP server
            $fsinfoUsername = $_POST['fsinfoUsername'];
            $fsinfoPassword = $_POST['fsinfoPassword'];
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $fsinfoUsername) || strlen($fsinfoUsername) > 50) {
                echo("Die FSinfo Kennung ist ungültig oder zu lang.");
                exit;
            }
            if (strlen($fsinfoPassword) > 255) {
                echo("Das Passwort zu lang.");
                exit;
            }
            $fsinfoUsernameDn = ldap_escape($fsinfoUsername, '', LDAP_ESCAPE_DN);
            $fsinfoUsernameFilter = ldap_escape($fsinfoUsername, '', LDAP_ESCAPE_FILTER);
            $ldapConnection = ldap_connect("localhost");
            if (!$ldapConnection) {
                echo("Verbindung zum LDAP-Server fehlgeschlagen.");
                exit;
            }
            ldap_set_option($ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3);
            $userDn = "uid={$fsinfoUsernameDn},ou=users,dc=fsinfo,dc=fim,dc=uni-passau,dc=de";
            if (!@ldap_bind($ldapConnection, $userDn, $fsinfoPassword)) {
                echo("Anmeldung am LDAP-Server fehlgeschlagen. Bitte überprüfe deine FSinfo-Kennung und dein Passwort.");
                exit;
            }
            $entries = ldap_get_entries($ldapConnection, ldap_search($ldapConnection, $userDn, "(uid={$fsinfoUsernameFilter})"));
            if ($entries["count"] === 0) {
                echo("Benutzerobjekt konnte nicht gefunden werden.");
                exit;
            }
            $birthday = $entries[0]['fsinfobirthdaydate'][0] ?? '';
            $details = [
                'email' => $entries[0]['email'][0] ?? '',
                'name' => $entries[0]['cn'][0] ?? '',
                'birthdate' => $birthday ? substr($birthday, 0, 4) . '-' . substr($birthday, 4, 2) . '-' . substr($birthday, 6, 2) : '',
                'address' => str_replace('$', "\n", $entries[0]['postaladdress'][0] ?? ''),
                'iban' => str_replace(' ', '', $entries[0]['fsinfoiban'][0] ?? ''),
                'taxId' => str_replace(' ', '', $entries[0]['fsinfotaxid'][0] ?? ''),
                'amount' => $amount,
                'no-receipts' => isset($_POST['no-receipts']),
                'reason' => $reason,
                'attachments' => []
            ];
            if (empty($details['birthdate']) || empty($details['iban']) || empty($details['taxId']) || empty($details['address'])) {
                echo("Im LDAP/Adressbuch fehlen Informationen zu dir (Geburtsdatum, IBAN, Steuer-ID, Adresse).");
                exit;
            }
            $attachmentDir = VERIFICATION_FOLDER . bin2hex(random_bytes(16));
            process_attachments($attachmentDir, $details);
            ldap_unbind($ldapConnection);
            sendRefundRequest($details);
        } else if($_POST['fsinfo-member'] == 'nein' && isset($_POST['email']) && isset($_POST['name']) && isset($_POST['birthdate']) && isset($_POST['address']) && isset($_POST['iban']) && isset($_POST['taxId'])) {
            // Otherwise we need to ask for the details
            $email = $_POST['email'];
            $name = $_POST['name'];
            $birthdate = $_POST['birthdate'];
            $address = str_replace("\r\n", "\n", $_POST['address']);
            $iban = $_POST['iban'];
            $taxId = $_POST['taxId'];
            if (!preg_match('/^.+@.+\..+$/', $email) || strlen($email) > 255) {
                echo("Die E-Mail-Adresse ist ungültig oder zu lang.");
                exit;
            }
            if (!preg_match('/^[a-zA-ZäöüÄÖÜß\s\-]+$/', $name) || strlen($name) > 100) {
                echo("Der Name enthält ungültige Zeichen oder ist zu lang.");
                exit;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
                echo("Das Geburtsdatum ist ungültig. Bitte verwende das Format JJJJ-MM-TT.");
                exit;
            }
            if (!preg_match('/^[a-zA-Z0-9äöüÄÖÜß\s,\-\.]+$/', $address) || strlen($address) > 255) {
                echo("Die Adresse enthält ungültige Zeichen oder ist zu lang.");
                exit;
            }
            if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban) || strlen($iban) > 34) {
                echo("Die IBAN ist ungültig oder zu lang.");
                exit;
            }
            if (!preg_match('/^\d{11}$/', $taxId) || strlen($taxId) > 11) {
                echo("Die Steuer-ID ist ungültig oder zu lang. Sie muss genau 11 Ziffern enthalten.");
                exit;
            }
            $details = [
                'email' => $email,
                'name' => $name,
                'birthdate' => $birthdate,
                'address' => $address,
                'iban' => $iban,
                'taxId' => $taxId,
                'amount' => $amount,
                'no-receipts' => isset($_POST['no-receipts']),
                'reason' => $reason,
                'attachments' => []
            ];
            // If the user is not a FSinfo member, we need to verify his email address and identity first
            // Therefore we save the details in a temporary file and send a verification email
            $verificationToken = bin2hex(random_bytes(16));
            $attachmentDir = VERIFICATION_FOLDER . $verificationToken;
            $verificationLink = 'https://fsinfo.fim.uni-passau.de/refund?verify-token=' . $verificationToken;
            process_attachments($attachmentDir, $details);
            file_put_contents(VERIFICATION_FOLDER . $verificationToken . '.json', json_encode($details));
            $subject = '=?UTF-8?B?' . base64_encode('FSinfo Rückerstattungsantrag') . '?=';
            $message = "Hallo,\n\nfür deine E-Mail-Adresse wurde soeben versucht einen Rückerstattungsantrag beim Finanzteam der Fachschaft Info einzureichen.\n\nWenn du das gewesen bist, klicke bitte auf den folgenden Link, um deinen Rückerstattungsantrag zu bestätigen: $verificationLink\nDieser Link ist für eine Stunde gültig.\n\nWenn du das nicht warst, ignoriere diese E-Mail bitte einfach.\n\nViele Grüße,\nFSinfo Finanzteam";
            $headers = "Content-Type: text/plain; charset=UTF-8\r\nFrom: =?UTF-8?B?" . base64_encode('FSinfo Rückerstattungsantrag') . "?= <refund@zimvm.fsinfo.fim.uni-passau.de>\r\n";
            mail($email, $subject, $message, $headers);
            echo("Da du keine FSinfo Kennung hast musst du zuerst deine E-Mail-Adresse bestätigen, bevor dein Antrag weitergeleitet werden kann.<br>Bitte schaue in deinem E-Mail Postfach nach einer E-Mail mit dem Betreff \"FSinfo Rückerstattungsantrag\" und klicke auf den Link in der E-Mail.");
            exit;
        }
    }
?>
<!DOCTYPE html>
<html lang="de">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FSinfo Rückerstattung</title>
    <meta name="author" content="Fabian Dietrich">
    <meta name="description" content="Rückerstattungsantrag für Auslagen an die Fachschaft für Informatik und Mathematik">
    <meta name="robots" content="noindex, nofollow">

    <style>

        body {
            font-family: Arial, sans-serif;
        }

        div {
            margin: 20px 0;
        }
        
    </style>

</head>

<body>

    <header>
        <h1>Rückerstattungsantrag</h1>
    </header>
    <main>
        <form method="post" enctype="multipart/form-data">
            <h2>Angaben zur Person</h2>

            <div>
                <label>Hast du eine FSinfo Kennung:</label>
                <input type="radio" id="fsinfo-yes" name="fsinfo-member" value="ja" checked>
                <label for="fsinfo-yes">Ja</label>
                <input type="radio" id="fsinfo-no" name="fsinfo-member" value="nein">
                <label for="fsinfo-no">Nein</label>
            </div>

            <div id="fsinfo-yes-section">
                <label for="fsinfoUsername">FSinfo Kennung:</label>
                <input type="text" id="fsinfoUsername" name="fsinfoUsername" maxlength="50" style="width: 150px;" required>
                <br>
                <label for="fsinfoPassword">FSinfo Passwort:</label>
                <input type="password" id="fsinfoPassword" name="fsinfoPassword" maxlength="255" style="width: 150px;" required>
                <br>
                <input type="checkbox" id="addressbook-complete" name="addressbook-complete" required>
                <label for="addressbook-complete">Meine Angaben (Name, Geburtstag, Adresse, IBAN, Steuer-ID) im <a target="_blank" href="https://fsinfo.fim.uni-passau.de/addressbook/">Addressbuch</a> sind vollständig und aktuell.</label>
            </div>

            <div id="fsinfo-no-section">
                <table>
                    <tr>
                        <td><label for="email">E-Mail-Adresse:</label></td>
                        <td><input type="email" id="email" name="email" maxlength="255" required></td>
                    </tr>
                    <tr>
                        <td><label for="name">vollständiger Name:</label></td>
                        <td><input type="text" id="name" name="name" pattern="^[a-zA-ZäöüÄÖÜß\s\-]+$" maxlength="100" required></td>
                    </tr>
                    <tr>
                        <td><label for="birthdate">Geburtsdatum:</label></td>
                        <td><input type="date" id="birthdate" name="birthdate" required></td>
                    </tr>
                    <tr>
                        <td><label for="address">Adresse:</label></td>
                        <td><textarea id="address" name="address" rows="3" maxlength="255" pattern="^[a-zA-Z0-9äöüÄÖÜß\s,\-\.]+$" required></textarea></td>
                    </tr>
                    <tr>
                        <td><label for="iban">IBAN:</label></td>
                        <td><input type="text" id="iban" name="iban" pattern="^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$" maxlength="34" required></td>
                    </tr>
                    <tr>
                        <td><label for="taxId">Steuer-ID:</label></td>
                        <td><input type="text" id="taxId" name="taxId" pattern="^\d{11}$" maxlength="11" required></td>
                    </tr>
                </table>
                <br>
                Diese Angaben werden von der Finanzabteilung der Uni benötigt, um die Rückerstattung zu veranlassen und um den gesetzlichen Anforderungen zu genügen.
            </div>

            <h2>Angaben zur Rückerstattung</h2>

            <div>
                <table>
                    <tr>
                        <td><label for="amount">Ausgelegter Betrag:</label></td>
                        <td><input type="text" id="amount" name="amount" pattern="^\d+(\,\d{1,2})?$" maxlength="10" style="width: 60px;" required> €</td>
                    </tr>
                    <tr>
                        <td><label for="reason">Wofür hast du Geld ausgelegt:</label></td>
                        <td><textarea id="reason" name="reason" rows="3" maxlength="255" pattern="^[a-zA-Z0-9äöüÄÖÜß\s,\-\.!?]+$" required></textarea></td>
                    </tr>
                </table>
            </div>

            <h2>Belege</h2>

            <div>
                Zur Bearbeitung deines Antrags benötigt die Finanzabteilung der Uni alle zugehörigen Rechnungen und Zahlungsnachweise.<br>
                Als Zahlungsnachweise gelten z.B. geschwärzte Kontoauszüge oder PayPal Auszüge.<br>
                <br>
                <input type="checkbox" id="no-receipts" name="no-receipts">
                <label for="no-receipts">Ich habe den Betrag bar bezahlt.</label>
                <table>
                    <tr>
                        <td><label for="invoice">Rechnungen/Belege:</label></td>
                        <td><input type="file" id="invoice" name="invoice[]" accept=".pdf,.jpg,.jpeg,.png" multiple required></td>
                    </tr>
                    <tr id="show-with-receipts">
                        <td><label for="paymentProof">Zahlungsnachweise:</label></td>
                        <td><input type="file" id="paymentProof" name="paymentProof[]" accept=".pdf,.jpg,.jpeg,.png" multiple required></td>
                    </tr>
                </table>
            </div>

            <button type="submit">Antrag einreichen</button>
        </form>
    </main>

    <footer>
        <p>Bei Fragen oder Problemen bitte <a href="mailto:admins@fsinfo.fim.uni-passau.de">admins@fsinfo.fim.uni-passau.de</a> kontaktieren.</p>
    </footer>

    <script>
        function showFSinfoSection() {
            document.getElementById('fsinfo-yes-section').style.display = 'block';
            document.getElementById('fsinfo-no-section').style.display = 'none';
            document.getElementById('fsinfoUsername').required = true;
            document.getElementById('fsinfoPassword').required = true;
            document.getElementById('addressbook-complete').required = true;
            document.getElementById('email').required = false;
            document.getElementById('name').required = false;
            document.getElementById('birthdate').required = false;
            document.getElementById('address').required = false;
            document.getElementById('iban').required = false;
            document.getElementById('taxId').required = false;
        }

        function showExternalSection() {
            document.getElementById('fsinfo-yes-section').style.display = 'none';
            document.getElementById('fsinfo-no-section').style.display = 'block';
            document.getElementById('fsinfoUsername').required = false;
            document.getElementById('fsinfoPassword').required = false;
            document.getElementById('addressbook-complete').required = false;
            document.getElementById('email').required = true;
            document.getElementById('name').required = true;
            document.getElementById('birthdate').required = true;
            document.getElementById('address').required = true;
            document.getElementById('iban').required = true;
            document.getElementById('taxId').required = true;
        }

        document.getElementById('fsinfo-yes').addEventListener('change', function() {
            showFSinfoSection();
        });

        document.getElementById('fsinfo-no').addEventListener('change', function() {
            showExternalSection();
        });

        document.getElementById('no-receipts').addEventListener('change', function() {
            if (this.checked) {
                document.getElementById('show-with-receipts').style.display = 'none';
                document.getElementById('paymentProof').required = false;
            } else {
                document.getElementById('show-with-receipts').style.display = '';
                document.getElementById('paymentProof').required = true;
            }
        });

        if (document.getElementById('fsinfo-yes').checked) {
            showFSinfoSection();
        } else {
            showExternalSection();
        }
    </script>

</body>

</html>