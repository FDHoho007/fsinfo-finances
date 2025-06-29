<?php

require_once("config.php");
require_once("firefly.php");
require_once("bic.php");

function getAccounts($fireflyClient) {
    $accounts = [];
    $page = 1;
    do {
        $response = $fireflyClient->get("accounts?type=expense&limit=100&page=" . $page++);
        $accounts = array_merge($accounts, $response["data"]);
    } while($response["meta"]["pagination"]["current_page"] < $response["meta"]["pagination"]["total_pages"]);
    $accounts = array_filter($accounts, function($account) {
        return $account["attributes"]["type"] === "expense" && $account["attributes"]["active"];
    });
    $accounts = array_map(function($account) use ($fireflyClient) {
        $attributes = $fireflyClient->getAccountAttributes($account["attributes"], FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES);
        return array_merge(["id" => $account["id"]], $attributes);
    }, $accounts);
    return $accounts;
}

function createAccount($fireflyClient, $user) {
    $notes = "";
    foreach (FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES as $key => $attribute) {
        if (isset($user[$attribute["key"]])) {
            $value = $user[$attribute["key"]];
            $notes .= "$key: $value\n";
        }
    }
    $data = [
        "name" => $user["name"],
        "type" => "expense",
        "currency_code" => "EUR",
        "iban" => $user["iban"],
        "bic" => iban2bic($user["iban"]),
        "account_number" => substr($user["iban"], 12),
        "notes" => $notes,
        "active" => true,
    ];
    return $fireflyClient->makeRequest("POST", "accounts", $data);
}

function deactivateAccount($fireflyClient, $account) {
    $iban = $account["iban"] ?? "";
    if(trim($iban) == "") {
        $iban = "N/A";
    }
    $fireflyClient->makeRequest('PUT', 'accounts/' . $account["id"], ["name" => $account["name"] . " ($iban)", "active" => false], false);
}

function diffAccount($user, $account) {
    if(str_replace(" ", "", $account["iban"]) != str_replace(" ", "", $user["iban"])) {
        return 1;
    } else {
        foreach(FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES as $attribute) {
            if(!isset($account[$attribute["key"]]) && !isset($user[$attribute["key"]])) {
                continue;
            } else if(isset($account[$attribute["key"]]) && !isset($user[$attribute["key"]])) {
                return 2;
            } else if(!isset($account[$attribute["key"]]) && isset($user[$attribute["key"]])) {
                return 2;
            } else if($account[$attribute["key"]] != $user[$attribute["key"]]) {
                return 2;
            }
        }
    }
    return 0;
}

function getLDAPUsers() {
    $users = [];
    $conn = ldap_connect(LDAP_HOST);
    if (!$conn) {
        throw new Exception("Could not connect to LDAP server: LDAP_HOST");
    }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    if (!ldap_bind($conn, LDAP_BIND_DN, LDAP_BIND_PASSWORD)) {
        throw new Exception("Could not bind to LDAP server with DN: " . LDAP_BIND_DN);
    }
    $ldapAttrs = array_values(array_map(function($attr) {
        return $attr["ldapKey"] ?? $attr;
    }, FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES));
    $result = ldap_search($conn, LDAP_USER_BASE_DN, "(" . LDAP_IBAN_ATTR . "=*)", array_merge(["sn", "givenName", LDAP_IBAN_ATTR], $ldapAttrs));
    if ($result === false) {
        throw new Exception("LDAP search failed: " . ldap_error($conn));
    }
    $entries = ldap_get_entries($conn, $result);
    if ($entries === false) {
        throw new Exception("Could not get LDAP entries: " . ldap_error($conn));
    }
    for ($i = 0; $i < $entries["count"]; $i++) {
        $entry = $entries[$i];
        $user = [
            "name" => sprintf("%s, %s", $entry["sn"][0], $entry["givenname"][0]),
            "iban" => str_replace(" ", "", $entry[strtolower(LDAP_IBAN_ATTR)][0]),
            "present" => false
        ];
        foreach (FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES as $attribute) {
            if (isset($entry[strtolower($attribute["ldapKey"])][0])) {
                $user[$attribute["key"]] = $entry[strtolower($attribute["ldapKey"])][0];
                if(isset($attribute["ldapFormat"])) {
                    $user[$attribute["key"]] = $attribute["ldapFormat"]($user[$attribute["key"]]);
                }
            }
        }
        $users[] = $user;
    }
    ldap_unbind($conn);
    return $users;
}

function syncAccounts($fireflyClient, $users) {
    $accounts = getAccounts($fireflyClient);
    foreach($users as $user) {
        foreach($accounts as $account) {
            if($account["name"] == $user["name"]) {
                $user["present"] = true;
                $diff = diffAccount($user, $account);
                if($diff == 1) {
                    deactivateAccount($fireflyClient, $account);
                    createAccount($fireflyClient, $user);
                } else if($diff == 2) {
                    $notes = "";
                    foreach (FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES as $key => $attribute) {
                        if (isset($user[$attribute["key"]])) {
                            $value = $user[$attribute["key"]];
                            $notes .= "$key: $value\n";
                        }
                    }
                    $fireflyClient->makeRequest('PUT', 'accounts/' . $account["id"], ["notes" => $notes], false);
                }
            }
        }
    }
    foreach($users as $user) {
        if(!isset($user["present"])) {
            createAccount($fireflyClient, $user);
        }
    }
}

if (php_sapi_name() === 'cli') {
    syncAccounts(new FireflyIIIClient(FIREFLY_URL, FIREFLY_API_KEY), getLDAPUsers());
}