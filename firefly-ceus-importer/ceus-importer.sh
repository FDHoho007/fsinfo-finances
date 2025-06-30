#!/bin/bash
export PATH=/usr/local/bin:/usr/bin:/bin
export GECKODRIVER_VERSION=0.36.0
cd /usr/src/app
exec python3 ceus-importer.py
