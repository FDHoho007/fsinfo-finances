import re
from datetime import datetime

from ceus import get_ceus_data
from firefly_iii_client import Firefly_III_Client

from config import (
    CEUS_CATEGORY_NAMES,
    FIREFLY_HOST,
    FIREFLY_ACCESS_TOKEN,
    FIREFLY_ACCOUNT_ID,
    FIREFLY_CHAPTER_TYPE,
    FIREFLY_CHAPTER_IDS,
    FIREFLY_DEFAULT_EXPENSE_ACCOUNT,
    FIREFLY_DEFAULT_REVENUE_ACCOUNT,
    FIREFLY_SUBMITTED_TAG,
    FIREFLY_PROCESSED_TAG,
    FORCE_UPDATE_FIELDS,
)

def hül_id2transaction_id(client, hül_id):
    transaction = client.get_tag_transactions("hül-nr-" + hül_id)
    if transaction is None:
        return None
    transaction = transaction['data'][0]['attributes']['transactions'][0]
    return transaction['id'] if 'id' in transaction else transaction['transaction_journal_id']

def create_transaction(client, item):
    amount = float(item["amount"])
    type = FIREFLY_CHAPTER_TYPE.get(item["title"], "deposit")
    if amount < 0:
        amount = -amount
        type = "deposit" if type == "withdrawal" else "withdrawal"
    tags = ["hül-nr-" + item["id"], FIREFLY_PROCESSED_TAG]
    if item["category"] != "--":
        tags.append(item["category"])

    type2 = "expense" if type == "withdrawal" else "revenue"
    if item["partner"].strip() == "":
        partner = FIREFLY_DEFAULT_EXPENSE_ACCOUNT if type == "withdrawal" else FIREFLY_DEFAULT_REVENUE_ACCOUNT
    else:
        account_search = client.search_accounts(type2, item["partner"])
        if account_search is not None and len(account_search['data']) > 0:
            for account in account_search['data']:
                if account['attributes']['active']:
                    partner = account['id']
                    break
        else:
            new_account = {
                "name": item["partner"],
                "type": type2,
                "currency_code": "EUR",
                "active": True,
            }
            partner = client.create_account(new_account)['data']['id']

    transaction = {
        "type": type,
        "tags": tags,
        "description": item["description"],
        "amount": amount,
        "date": item["date"],
        "book_date": item["date"],
        "budget_id": item["budget_id"],
        "internal_reference": item["internal_reference"],
        "source_id": FIREFLY_ACCOUNT_ID if type == "withdrawal" else partner,
        "destination_id": FIREFLY_ACCOUNT_ID if type == "deposit" else partner,
    }
    transaction = client.create_transaction(transaction)
    if item["ref_id"] != "0":
        transaction_link = {"link_type_id": 1, "inward_id": transaction['data']['id'], "outward_id": hül_id2transaction_id(client, item["ref_id"])}
        client.create_transaction_link(transaction_link)

def update_transaction(client, transaction, item):
    updated_transaction = {"tags": transaction['tags']}
    if len(FORCE_UPDATE_FIELDS) > 0 and not "gezahlt" in updated_transaction["tags"]:
        updated_transaction["tags"].append(FIREFLY_PROCESSED_TAG)
    for field in FORCE_UPDATE_FIELDS:
        if field in item:
            if field in ["description", "amount", "book_date", "budget_id", "internal_reference"]:
                updated_transaction[field] = item[field]
            elif field == "category" and not item[field] in updated_transaction["tags"]:
                updated_transaction["tags"].append(item[field])
    for key in updated_transaction.keys():
        if updated_transaction[key] != transaction[key]:
            client.update_transaction(transaction['id'], updated_transaction)
            break
    if "ref_id" in FORCE_UPDATE_FIELDS:
        transaction_link = client.get_transaction_links(transaction['transaction_journal_id'])
        if (not "ref_id" in item or item["ref_id"] == "0") and transaction_link is not None:
            client.delete_transaction_link(transaction_link['id'])
        elif "ref_id" in item and item["ref_id"] != "0":
            updated_transaction_link = {"link_type_id": 1, "inward_id": transaction['id'], "outward_id": hül_id2transaction_id(client, item["ref_id"])}
            if transaction_link is None:
                client.create_transaction_link(updated_transaction_link)
            else:
                transaction_link = transaction_link['data'][0]['attributes']
                for key in updated_transaction_link.keys():
                    if updated_transaction_link[key] != transaction_link[key]:
                        client.update_transaction_link(transaction_link['id'], updated_transaction_link)
                        break

def process_transaction(client, transaction, item):
    updated_transaction = {"tags": transaction['tags'], "budget_id": item["budget_id"], "book_date": item["date"], "internal_reference": item["internal_reference"]}
    updated_transaction["tags"].append("hül-nr-" + item["id"]) 
    updated_transaction["tags"].append(FIREFLY_PROCESSED_TAG)
    client.update_transaction(transaction['id'], updated_transaction)

def transaction_map_attributes(item):
    item["internal_reference"] = item["id"]
    item["amount"] = item["amount"].replace(",", "")
    item["budget_id"] = FIREFLY_CHAPTER_IDS.get(item["title"] + "/" + item["subtitle"], None)
    item["date"] = datetime.strptime(item["date"], "%d.%m.%Y").strftime("%Y-%m-%dT00:00")
    category_id = item["category"].split(", ")[0]
    if category_id in CEUS_CATEGORY_NAMES:
        item["category"] = category_id + "-" + CEUS_CATEGORY_NAMES[category_id]
    item["category"] = item["category"].replace(", ", "-").replace(".", "").replace(" ", "_")
    # MwST Rückerstattung erzeugen Transaktionen nach dem Muster "MwST ... Buch. Zahl Zahl Zahl Zahl"
    # Die Beschreibung kann entsprechend gekürzt werden
    # Aus der dritten Zahl kann die Ref.-HÜL-Nr. extrahiert werden
    match = re.search(r'Buch\. \d+ \d+ (\d+) \d+$', item["description"])
    if (item["_bukz"] == "UA0" or item["_bukz"] == "UG0") and item["ref_id"] == 0 and match:
        item["description"] = re.sub(r'Buch\..*$', '', item["description"]).strip()
        item["ref_id"] = match.group(1)

def skip_transaction(transaction):
    return transaction["_bukz"] == "G"

def main():
    data = get_ceus_data()
    client = Firefly_III_Client(FIREFLY_HOST, FIREFLY_ACCESS_TOKEN)
    for item in [t for t in data if not skip_transaction(t)]:
        transaction_map_attributes(item)
        transaction = client.get_tag_transactions("hül-nr-" + item["id"])
        if transaction is not None and len(FORCE_UPDATE_FIELDS) > 0: # Currently not used
            transaction = transaction['data'][0]['attributes']['transactions'][0]
            update_transaction(client, transaction, item)
        elif transaction is None:
            pending_transactions = client.get_tag_transactions(FIREFLY_SUBMITTED_TAG)
            found_pending_transaction = False
            for transaction in pending_transactions['data'] if pending_transactions is not None else []:
                transaction = transaction['attributes']['transactions'][0]
                if FIREFLY_PROCESSED_TAG not in transaction.get("tags", []) and transaction.get("amount") == item["amount"]:
                    process_transaction(client, transaction, item)
                    found_pending_transaction = True
            if not found_pending_transaction:
                create_transaction(client, item)

if __name__ == "__main__":
    main()