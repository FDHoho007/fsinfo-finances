<?php

class FireflyIIIClient
{
    private $baseUrl;
    private $apiKey;

    public function __construct($baseUrl, $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    public function makeRequest($method, $url, $data = null, $returnJson = true, $binary = false) {
        $ch = curl_init($this->baseUrl . '/api/v1/' . $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
        ];
        if ($data != null) {
            if ($binary) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                if (!array_filter($headers, fn($h) => stripos($h, 'Content-Type:') === 0)) {
                    $headers[] = 'Content-Type: application/octet-stream';
                }
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $headers[] = 'Content-Type: application/json';
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new Exception('Error fetching data from Firefly: ' . $response);
        }

        if($returnJson) {
            return json_decode($response, true);
        }
    }

    public function get($url, $returnJson = true) {
        return $this->makeRequest('GET', $url, null, $returnJson);
    }

    public function getAccountAttributes($account, $additionalAttributes) {
        $attributes = [
            "name" => $account["name"] ?? "",
            "iban" => empty($account["iban"]) ? "" : preg_replace('/(.{4})/', '$1 ', str_replace(" ", "", $account["iban"])),
            "bic" => $account["bic"] ?? ""
        ];
        foreach (explode("\n", $account["notes"] ?? "") as $line) {
            if (strpos($line, ":") === false) {
                continue;
            }
            list($key, $value) = explode(":", $line, 2);
            $key = trim($key);
            $value = str_replace(", ", "\n", trim($value));
            if (array_key_exists($key, $additionalAttributes)) {
                if(array_key_exists("format", $additionalAttributes[$key])) {
                    $value = $additionalAttributes[$key]["format"]($value);
                }
                $key = $additionalAttributes[$key]["key"];
            }
            $attributes[$key] = $value;
        }
        return $attributes;
    }
}

class FireflyIIIOAuth2Client {

    private $baseUrl;
    private $clientId;
    private $clientSecret;
    private $redirectUri;

    public function __construct($baseUrl, $clientId, $clientSecret, $redirectUri) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
    }

    public function authorize($state = null) {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
        ];
        if($state != null) {
            $params["state"] = $state;
        } 
        header('Location: ' . $this->baseUrl . '/oauth/authorize?' . http_build_query($params));
        exit;
    }

    public function token($code) {
        $ch = curl_init($this->baseUrl . '/oauth/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]));
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode === 200) {
            return json_decode($response, true)['access_token'];
        }
        return null;
    }
}