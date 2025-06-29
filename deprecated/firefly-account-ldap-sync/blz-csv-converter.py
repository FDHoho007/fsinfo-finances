# Get blz-aktuell-csv-data.csv from https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/bankleitzahlen/download-bankleitzahlen-602592

with open('blz-aktuell-csv-data.csv', 'r', encoding='iso-8859-1') as f1, open('bank.py', 'w', encoding='utf-8') as f2:
    lines = f1.read().splitlines()
    f2.write("BICs = {\n")
    for line in lines[1:]:
        parts = line.split(";")
        key = parts[0].strip()
        value = parts[7].strip()
        if value == "":
            value = "\"\""
        f2.write(f'    {key}: {value},\n')
    f2.write("}\n")