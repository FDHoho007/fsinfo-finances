import requests, re, ldap

from bank import BICs

from config import (
    FIREFLY_HOST,
    FIREFLY_ACCESS_TOKEN,
    LDAP_HOST,
    LDAP_USER_BASE_DN,
    LDAP_BIND_DN,
    LDAP_BIND_PASSWORD,
    LDAP_IBAN_ATTR,
)

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
        return response.json() if response.status_code == 200 and data is None else None
    
    def get_accounts(self):
        data = []
        page = 1
        pages_left = True
        while pages_left:
            response = self._request("GET", f"accounts?type=expense&limit=100&page={page}")
            data.extend(response["data"])
            pages_left = response["meta"]["pagination"]["current_page"] < response["meta"]["pagination"]["total_pages"]
            page += 1
        return data
    
    def deactivate_account(self, account):
        iban = account["attributes"]["iban"]
        if iban.strip() == "":
            iban = "N/A"
        return self._request("PUT", f'accounts/{account["id"]}', {"name": f'{account["attributes"]["name"]} ({iban})', "active": False})

    def create_account(self, user):
        data = {
            "name": user["name"],
            "type": "expense",
            "currency_code": "EUR",
            "iban": re.sub(r"(.{4})", r"\1 ", user["iban"]),
            "bic": iban2bic(user["iban"]),
            "account_number": user["iban"][12:],
            "active": True,
        }
        return self._request("POST", "accounts", data)

def get_ldap_users():
    conn = ldap.initialize(LDAP_HOST)
    conn.simple_bind_s(LDAP_BIND_DN, LDAP_BIND_PASSWORD)
    result = conn.search_s(
        LDAP_USER_BASE_DN,
        ldap.SCOPE_SUBTREE,
        f"({LDAP_IBAN_ATTR}=*)",
        ["sn", "givenName", LDAP_IBAN_ATTR]
    )
    users = []
    for dn, entry in result:
        if not entry:
            continue
        user = {
            "name": f"{entry.get('sn', [b''])[0].decode()}, {entry.get('givenName', [b''])[0].decode()}",
            "iban": entry.get(LDAP_IBAN_ATTR, [b""])[0].decode().replace(" ", ""),
            "present": False
        }
        users.append(user)
    conn.unbind_s()
    return users

def iban2bic(iban):
    return BICs.get(iban[4:12], "")

def main():
    client = Firefly_III_Client(FIREFLY_HOST, FIREFLY_ACCESS_TOKEN)
    accounts = client.get_accounts()
    users = get_ldap_users()
    for user in users:
        for account in accounts:
            if account["attributes"]["name"] == user["name"] and account["attributes"]["active"]:
                user["present"] = True
                if account["attributes"]["iban"].replace(" ", "") != user["iban"]:
                    client.deactivate_account(account)
                    client.create_account(user)
    for user in [x for x in users if not x["present"]]:
        client.create_account(user)

if __name__ == "__main__":
    main()