import requests

class Firefly_III_Client:

    def __init__(self, host: str, access_token: str):
        self.host = host
        self.access_token = access_token

    def _request(self, method: str, endpoint: str, data=None):
        url = f"{self.host}/{endpoint}"
        headers = {
            "Authorization": f"Bearer {self.access_token}",
            "Content-Type": "application/json"
        }
        response = requests.request(method, url, headers=headers, json=data)
        return response.json() if response.status_code == 200 else None
    
    def get_tag_transactions(self, tag):
        return self._request("GET", f"tags/{tag}/transactions")
    
    def create_transaction(self, data):
        body = {"apply_rules": True, "fire_webhooks": True, "transactions": [data]}
        return self._request("POST", "transactions", body)

    def update_transaction(self, transaction_id, data, apply_rules=True, fire_webhooks=True):
        body = {"apply_rules": apply_rules, "fire_webhooks": fire_webhooks, "transactions": [data]}
        return self._request("PUT", f"transactions/{transaction_id}", body)
    
    def create_transaction_link(self, data):
        return self._request("POST", "transaction-links", data)

    def search_accounts(self, type, query):
        return self._request("GET", f"search/accounts?type={type}&query={query}&field=name")
    
    def create_account(self, data):
        return self._request("POST", "accounts", data)