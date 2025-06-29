from bs4 import BeautifulSoup
from selenium import webdriver
from selenium.webdriver.firefox.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

from config import (
    CEUS_USERNAME,
    CEUS_PASSWORD,
    LOGIN_URL,
    STATEMENT_URL,
    CEUS_COLUMN_MAP
)

def transform_column_name(name):
    if name in CEUS_COLUMN_MAP:
        return CEUS_COLUMN_MAP[name]
    return "_" + name.lower().replace(' ', '_').replace('-', '_').replace('.', '_').replace('/', '_').replace('(', '').replace(')', '')

def parse_ceus_data(html):
    rows = BeautifulSoup(html, 'html.parser').find_all('table')[-1].find_all('tr')
    col_names = []
    for col in rows[0].find_all('td'):
        for _ in range(int(col.get('colspan', 1))):
            col_names.append(transform_column_name(col.get_text(strip=True)))
    data = []
    row_span = {}
    for row in rows[1:]:
        col_data = {}
        cols = row.find_all('td')
        j = 0
        for i in range(len(col_names)):
            name = col_names[i]
            if i in row_span:
                col_data[name] = row_span[i]['value']
                row_span[i]['count'] -= 1
                if row_span[i]['count'] <= 0:
                    del row_span[i]
            else:
                col = cols[j]
                if name in col_data:
                    col_data[name] += ', ' + col.get_text(strip=True)
                else:
                    col_data[name] = col.get_text(strip=True)
                if col.get('rowspan') and int(col.get('rowspan')) > 1:
                    row_span[i] = {'value': col_data[name], 'count': int(col.get('rowspan')) - 1}
                j += 1
        data.append(col_data)
    return data

def get_ceus_data():
    options = Options()
    options.headless = True
    with webdriver.Firefox(options=options) as driver:
        driver.set_window_size(1920, 3000)
        # Login via Shibboleth
        driver.get(LOGIN_URL)
        WebDriverWait(driver, 20).until(EC.presence_of_element_located((By.NAME, "j_username")))
        driver.find_element(By.NAME, "j_username").send_keys(CEUS_USERNAME)
        driver.find_element(By.NAME, "j_password").send_keys(CEUS_PASSWORD)
        driver.find_element(By.NAME, "_eventId_proceed").click()
        # Wait for CEUS homepage
        WebDriverWait(driver, 20).until(EC.presence_of_element_located((By.ID, "projects_ProjectsStyle")))
        # Request account statement for current year
        driver.get(STATEMENT_URL)
        WebDriverWait(driver, 20).until(EC.presence_of_element_located((By.ID, "id_mstr58")))
        driver.find_element(By.ID, "id_mstr58").click()
        WebDriverWait(driver, 20).until(EC.presence_of_element_located((By.CLASS_NAME, "mstrmojo-DocLayout")))
        return parse_ceus_data(driver.page_source)
