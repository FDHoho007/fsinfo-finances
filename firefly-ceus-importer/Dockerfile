FROM debian:12-slim

WORKDIR /usr/src/app

RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    python3-requests \
    python3-bs4 \
    python3-selenium \
    firefox-esr \
    wget \
    curl \
    ca-certificates \
    gnupg \
    unzip \
    cron \
    && rm -rf /var/lib/apt/lists/*

ENV GECKODRIVER_VERSION=0.36.0
RUN wget -q https://github.com/mozilla/geckodriver/releases/download/v$GECKODRIVER_VERSION/geckodriver-v$GECKODRIVER_VERSION-linux64.tar.gz && \
    tar -xzf geckodriver-v$GECKODRIVER_VERSION-linux64.tar.gz -C /usr/local/bin && \
    rm geckodriver-v$GECKODRIVER_VERSION-linux64.tar.gz

COPY firefly_iii_client.py ./
COPY ceus.py ./
COPY ceus-importer.py ./

# Add the cron job
RUN echo "0 * * * * python3 /usr/src/app/ceus-importer.py >> /var/log/cron.log 2>&1" > /etc/cron.d/ceus-cron \
    && chmod 0644 /etc/cron.d/ceus-cron \
    && crontab /etc/cron.d/ceus-cron

# Create log file
RUN touch /var/log/cron.log

ENV MOZ_HEADLESS=1

# Start cron and tail the log
CMD ["sh", "-c", "cron && tail -f /var/log/cron.log"]