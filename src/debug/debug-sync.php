<?php

require_once("../lib/account-sync.php");

if(isset($_POST['username']) && isset($_POST["password"]) && $_POST["password"] == DEBUG_PASSWORD) {
    $mismatch = false;
    $username = trim($_POST['username']);
    $firefly = new FireflyIIIClient(FIREFLY_URL, FIREFLY_API_KEY);
    $accounts = getAccounts($firefly);
    $ldapUsers = getLDAPUsers();
    foreach($ldapUsers as $user) {
        if($user["name"] == $username) {
            $ldapUser = $user;
            break;
        }
    }
    if(isset($ldapUser)) {
        $ldapUser["bic"] = iban2bic($ldapUser["iban"]);
        $message = "User found: " . json_encode($ldapUser, JSON_PRETTY_PRINT);
        foreach($accounts as $account) {
            if($account["name"] == $ldapUser["name"]) {
                $account["iban"] = str_replace(" ", "", $account["iban"]);
                foreach(FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES as $attribute) {
                    $key = $attribute["key"];
                    if(isset($account[$key])) {
                        $account[$key] = str_replace("\n", ", ", $account[$key]);
                    }
                }
                $message .= "<br>Account Object: " . json_encode($account, JSON_PRETTY_PRINT);
                if($ldapUser["iban"] != $account["iban"]) {
                    $mismatch = true;
                    $message .= '<br><span style="color:red;">IBAN mismatch!</span>';
                }
                if($ldapUser["bic"] != $account["bic"]) {
                    $mismatch = true;
                    $message .= '<br><span style="color:red;">BIC mismatch!</span>';
                }
                foreach(FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES as $attribute) {
                    $key = $attribute["key"];
                    if((!isset($ldapUser[$key]) && isset($account[$key])) || (isset($ldapUser[$key]) && !isset($account[$key])) || (isset($ldapUser[$key]) && $ldapUser[$key] != $account[$key])) {
                        $mismatch = true;
                        $message .= "<br><span style=\"color:red;\">Attribute mismatch for $key!</span>";
                    }
                }
                if(!$mismatch) {
                    $message .= '<br><span style="color:green;">No mismatches found!</span>';
                }
            }
        }
    } else {
        $message = "User not found: " . htmlspecialchars($username);
    }
}

?>
<!DOCTYPE html>
<html>

<head>

    <meta charset="UTF-8">
    <title>Sync User manually</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body class="bg-light">

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h4 class="card-title mb-4 text-center">Sync User manually</h4>
                        <?php if (isset($message)): ?>
                            <div class="alert alert-info"><?= $message ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="mb-3">
                                <label for="username" class="form-label">Last Name, First Name</label>
                                <input type="text" class="form-control" id="username" name="username" required autofocus
                                    value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Debug Password</label>
                                <input type="password" class="form-control" id="password" name="password" 
                                value="<?= isset($_POST['password']) ? htmlspecialchars($_POST['password']) : '' ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Get User</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>

</html>