# 🧾 PayPal Transaction Fetcher (Symfony Console Command)

This tool provides two Symfony console commands to **fetch PayPal transactions**, **enrich them with payer information**, and **export them as CSV files**.  
It supports both **interactive CLI usage** and **automated cronjob execution**.

---

## 🚀 Features

- Authenticates with PayPal REST API (Live or Sandbox mode)
- Fetches all transactions for a specific date
- Converts UTC timestamps to Berlin time
- Extracts payer names, emails, and financial details
- Generates a clean CSV report (semicolon-separated)
- Two operation modes:
    - **Interactive mode** – prompts for date, shows output table
    - **Cron mode** – runs automatically for yesterday’s date, no user interaction
- Environment-based configuration for live and sandbox credentials

---

## 🧰 Requirements

- PHP 8.1+
- Symfony 6+ (Console & HttpClient components)
- Valid PayPal REST API credentials (Live and/or Sandbox)
- Write access to a local storage directory for CSV export

## 🚀 Deploying to Production
1. Configure Environment Variables
   In production, use .env.local or system environment variables.
   Example configuration:<br><br>
   MODUS=LIVE<br>
   LIVE_API_ID=AbCdEf1234567890<br>
   LIVE_API_SECRET=YourSecretHere<br>
   LIVE_API_URL=https://api-m.paypal.com<br>
   STOREFOLDER=/var/www/paypal_reports/<br><br>
   Make sure the folder exists and is writable by the web server or cron user:
   sudo mkdir -p /var/www/paypal_reports
   sudo chown www-data:www-data /var/www/paypal_reports
   sudo chmod 775 /var/www/paypal_reports
2. Test Command Manually
   Run once manually to confirm connectivity and permissions:
   php bin/console paypal:connect
   Check if the CSV file was created under STOREFOLDER.
3. Setup Cronjob
   Edit the cron configuration for your web server user (often www-data):
   sudo crontab -u www-data -e
   Add:
   30 2 * * * /usr/bin/php /var/www/yourproject/bin/console paypal:connect:cron >> /var/log/paypal_cron.log 2>&1
   This runs every night at 02:30 and writes logs to /var/log/paypal_cron.log.
   Check logs:
   tail -f /var/log/paypal_cron.log