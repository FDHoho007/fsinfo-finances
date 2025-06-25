# FSinfo Finances

This collection of tools was developed for the [FSinfo @ University of Passau](https://fsinfo.fim.uni-passau.de) to manage their finances. It integrates into CEUS provided by the university as well as into a [Firefly III](https://firefly-iii.org) instance.

All tools expect a config.py with credentials.

## CEUS Importer

This repository contains a CEUS importer, that exports finance statements from CEUS using selenium and then imports them into Firefly III if not already imported. It also tries to find pending transactions in Firefly III that now also appear in CEUS and then connects them.
The CEUS importer requires the following apt packages: `apt install python3-requests python3-bs4 python3-selenium`

The importer also expects an existing asset account as well as both a default expense and a default revenue account. Those need to be set via the config.
The importer will also automatically assign budgets to transactions depending on their title and subtitle. The respective budget ids for those combinations need to be set via the config.

Since this tool requires access to CEUS (probably via a ZIM account) it should only run in a secure environment of the owner of the ZIM account used.

## Account LDAP Sync

This tools syncs expense accounts from a LDAP directory. In order to generate a BIC from an IBAN this tool needs a bank.py file. This file can be generated using blz-csv-converter.py.
