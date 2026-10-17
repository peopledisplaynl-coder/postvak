# Installatie op shared hosting (alleen FTP)

Deze handleiding gaat uit van een host met DirectAdmin of cPanel (zoals Cloud86), PHP 8.2 of hoger,
MySQL/MariaDB en alleen FTP-toegang. Het bouwen gebeurt op je eigen computer.

## Wat je op je eigen computer nodig hebt

- **Node.js 20 of hoger**: <https://nodejs.org>
- **Composer**: <https://getcomposer.org/download/> (op Windows de installer `Composer-Setup.exe`)
- **PHP** op je computer is alleen nodig voor lokaal testen met een echte mailbox; voor bouwen en
  uploaden niet. Composer installeert wel een eigen PHP mee als je die nog niet hebt.

## 1. Subdomein en mappen

1. Maak in het hostingpaneel het subdomein aan, bijvoorbeeld `mail.jouwdomein.nl`, of gebruik het
   bestaande. Staat daar nu SnappyMail, zet die map dan eerst opzij (bijvoorbeeld hernoemen naar
   `mail-oud`), zodat je terug kunt als iets niet werkt.
2. Noteer de **documentmap** van het subdomein. Bij DirectAdmin is dat meestal
   `/domains/jouwdomein.nl/public_html/mail`.
3. Kies een map **buiten** `public_html` voor de programmacode, bijvoorbeeld
   `/domains/jouwdomein.nl/postvak-app`. Zo kan niemand config of code via de browser openen.
4. Zet SSL (Let's Encrypt) aan voor het subdomein.
5. Controleer in het paneel de PHP-versie (8.2 of hoger) en dat de extensies `sodium`, `pdo_mysql`,
   `mbstring`, `zip` en `fileinfo` aan staan.

## 2. Database

1. Maak in het paneel een MySQL-database en een gebruiker aan (bijvoorbeeld `jouw_postvak`).
2. Open phpMyAdmin, kies de database en importeer `backend/database/schema.sql`.

## 3. Configuratie

In de map `backend`:

```bash
composer install --no-dev --optimize-autoloader
copy config\config.example.php config\config.php      # Windows
# cp config/config.example.php config/config.php      # macOS/Linux
```

Vul `config/config.php` in:

- `app_url`: `https://mail.jouwdomein.nl`
- `app_key`: een geheime sleutel. Maak er een met
  `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`
  of laat Claude er een genereren. **Bewaar deze sleutel goed**: raak je hem kwijt, dan moeten alle
  gebruikers hun wachtwoorden opnieuw invoeren.
- `database`: de gegevens uit stap 2 (host is meestal `localhost`).
- `default_servers`: de IMAP- en SMTP-server van je host. Kijk in het paneel bij de
  e-mailinstellingen. Is het certificaat van `mail.jouwdomein.nl` niet geldig, gebruik dan de
  servernaam van de host (die staat ook bij de e-mailinstellingen).
- `allowed_login_domains`: vul je eigen domeinen in, zodat alleen die mailboxen kunnen inloggen.
  Dit kun je later ook op de beheerpagina aanpassen.
- `admins`: de adressen die de beheerpagina mogen openen, bijvoorbeeld `['ton@labee.eu']`.
  Laat je dit leeg, dan is de eerste gebruiker die ooit inlogde de beheerder.

## 4. Uploaden

In de hoofdmap van het project:

```bash
npm install
npm --prefix frontend install
copy .env.deploy.example .env.deploy     # en invullen
npm run deploy -- --with-config          # eerste keer, uploadt ook config.php
```

Daarna bij elke nieuwe versie:

```bash
npm run deploy                     # alles behalve config.php
npm run deploy -- --skip-vendor    # sneller, als de PHP-pakketten niet veranderd zijn
```

Met `npm run deploy -- --dry-run` zie je eerst wat er geüpload zou worden.

## 5. Controleren

1. Open `https://mail.jouwdomein.nl/api/health`. Je hoort `{"ok":true,...}` te zien.
2. Open `https://mail.jouwdomein.nl` en log in met een mailbox.
3. Op je telefoon: open de site en kies "Zet op beginscherm" (iPhone) of "App installeren" (Android).

## Problemen

| Melding | Oplossing |
|---|---|
| `Dependencies missing` | `vendor` is niet geüpload. Draai `npm run deploy` zonder `--skip-vendor`. |
| `config/config.php missing` | Draai één keer `npm run deploy -- --with-config`. |
| `Could not connect to the mail server` | Klopt `default_servers`? Probeer de servernaam van de host in plaats van `mail.<domein>`. |
| 404 op `/api/...` | `.htaccess` ontbreekt of `mod_rewrite` staat uit. Controleer of `.htaccess` in de documentmap staat (verborgen bestanden tonen in je FTP-programma). |
| Wit scherm | Is de frontend gebouwd? `npm run build` zet de bestanden in `backend/public`. |
