<?php

const FIREFLY_URL = 'https://fsinfo.fim.uni-passau.de/firefly';
const FIREFLY_API_KEY = '';
const OAUTH_CLIENT_ID = '2';
const OAUTH_CLIENT_SECRET = '';
const OAUTH_REDIRECT_URI = 'https://fsinfo.fim.uni-passau.de/finances/generate-request.php';
const LDAP_HOST = 'ldap://localhost';
const LDAP_USER_BASE_DN = 'ou=users,dc=fsinfo,dc=fim,dc=uni-passau,dc=de';
const LDAP_BIND_DN = 'uid=firefly,ou=serviceAccounts,dc=fsinfo,dc=fim,dc=uni-passau,dc=de';
const LDAP_BIND_PASSWORD = '';
const LDAP_IBAN_ATTR = 'fsinfoIBAN';
const FIREFLY_ACCOUNT_ID = '1';
const FIREFLY_SUBMITTED_TAG = 'eingereicht';
const FIREFLY_PROCESSED_TAG = 'bezahlt';
const FIREFLY_ACCOUNT_ADDITIONAL_ATTRIBUTES = [
    "Adresse" => [
        "key" => "address",
        "ldapKey" => "postalAddress",
        "ldapFormat" => "ldapFormatAddress",
    ],
    "Geburtsdatum" => [
        "key" => "dob",
        "ldapKey" => "fsinfoBirthdayDate",
        "ldapFormat" => "ldapFormatBirthdayDate"
    ],
    "Steuer-ID" => [
        "key" => "tin",
        "ldapKey" => "fsinfoTaxId",
        "format" => "formatTIN"
    ],
];
const PDF_TEMPLATES = [
    "finanzamt" => "refund-finanzamt.pdf",       # This template applies to the tag "finanzamt"
    "c7" => "refund-aufwandsentschädigung.pdf",  # This template applies to the category with id 7
    null => "refund.pdf"                         # This is the default template
];

function formatTIN($value) {
    return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{3})$/', '$1 $2 $3 $4', str_replace(" ", "", $value));
}

function ldapFormatAddress($value) {
    $parts = explode("$", $value);
    if (count($parts) < 3) {
        return $value;
    }
    return $parts[0] . ", " . $parts[1] . " " . $parts[2];
}

function ldapFormatBirthdayDate($value) {
    return preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$3.$2.$1', $value);
}