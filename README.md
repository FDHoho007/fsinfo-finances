# FSinfo Finances

This collection of tools was developed for the [FSinfo @ University of Passau](https://fsinfo.fim.uni-passau.de) to manage their finances. It integrates into CEUS provided by the university as well as into a [Firefly III](https://firefly-iii.org) instance.

All tools expect a config.py with credentials.

## CEUS Importer

This repository contains a CEUS importer, that exports finance statements from CEUS using selenium and then imports them into Firefly III if not already imported. It also tries to find pending transactions in Firefly III that now also appear in CEUS and then connects them.
The CEUS importer requires the following apt packages: `apt install python3-requests python3-bs4 python3-selenium`

The importer also expects an existing asset account as well as both a default expense and a default revenue account. Those need to be set via the config.
The importer will also automatically assign budgets to transactions depending on their title and subtitle. The respective budget ids for those combinations need to be set via the config.

Since this tool requires access to CEUS (probably via a ZIM account) it should only run in a secure environment of the owner of the ZIM account used.

## Usage

There are two types of users: Members of the Finance team and members of the student council.

Members of the student council might pas something for the student council. In order to get their money back, they can fill out a refund request (refund.php).
This will collect all information required for the finance team. If a user has an FSinfo account, their personal information can be read from the LDAP directory.
Otherwise the user has to provide this information in the form and verify his email address before submitting. When a request is submitted an unconfirmed transaction is created in Firefly III.
Unconfirmed transactions are marked with a tag and deleted after two weeks, if the tag has not been removed. The transaction include all the information from the form including attachments.

Members of the finance team receive an email notification whenever a new refund request has been submitted. They should then manually check whether the transaction was agreed upon and if so, remove the unconfirmed tag.
They can modify or enrich transaction information like setting a category. To submit this request to the university finance department they can generate a pdf at generate-request.php and print it. This will automatically add the submitted tag. The CEUS importer will add a payed tag when the transaction appears in CEUS.
