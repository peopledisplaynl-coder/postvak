# Installatie op shared hosting (alleen FTP)

Er zijn twee manieren:

- **A. Met de release-zip en de installatiewizard** (aanbevolen, je hoeft niets te bouwen): hieronder.
- **B. Zelf bouwen en uploaden met `npm run deploy`**: voor wie aan Postvak zelf werkt, vanaf "Wat je op je eigen computer nodig hebt".

## A. Release-zip met installatiewizard

1. Download `postvak-x.y.z.zip` van de nieuwste release op GitHub en pak hem uit. Je krijgt de mappen `public/` en `app/`.
2. Maak in het hostingpaneel het (sub)domein aan, zet SSL aan, controleer PHP 8.2+ en maak een lege MySQL-database met gebruiker.
3. Upload de **inhoud** van `public/` naar de documentmap van het (sub)domein (let op dat `.htaccess` meegaat)
   en de map `app/` **naast** de documentmap, bijvoorbeeld `httpdocs/` en `app/` in dezelfde map.
   Staat `app/` ergens anders, pas dan het pad aan in `public/api/app-path.php`.
4. Open `https://jouw-domein/install.php`, controleer dat alles groen is, vul database, adres en mailserver in en klik op **Installeren**.
   De wizard maakt de tabellen aan, schrijft `app/config/config.php` en verwijdert zichzelf.
   **Bewaar een kopie van `config.php`**: daarin staat `app_key`, nodig om de opgeslagen wachtwoorden te lezen.
5. Stel de cronjob in (zie stap 6 hieronder, met `app/cron.php`) en log in met je mailbox.

Bijwerken: upload de nieuwe `public/` en `app/` over de oude heen, behalve `install.php`, en houd `app/config/config.php`.
Database-aanpassingen gebeuren vanzelf bij het eerste bezoek na de update.

## B. Zelf bouwen en uploaden

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

## 6. Cronjob (voor meldingen bij nieuwe mail)

Postvak controleert elke paar minuten of er nieuwe mail is en stuurt dan een pushmelding naar de
apparaten waarop meldingen aan staan. Dat doet `cron.php` in de programmamap.

**Plesk:** Geplande taken → Taak toevoegen → *PHP-script uitvoeren* → kies
`postvak-app/cron.php` (de map uit `REMOTE_APPDIR`), dezelfde PHP-versie als de website, en
bij *Cron-stijl* `*/5 * * * *`. Klik op *Nu uitvoeren* om te testen; onder **Beheer → Overzicht**
zie je daarna wanneer de taak voor het laatst draaide.

**DirectAdmin / cPanel:** `php /pad/naar/postvak-app/cron.php`, elke 5 minuten.

**Alleen een URL mogelijk?** Zet in `config.php` een lange willekeurige `cron_key` en laat
`https://jouw-postvak/api/cron?key=DIE-SLEUTEL` elke 5 minuten aanroepen.

Meldingen zet je per apparaat aan onder **Instellingen → Meldingen**. Op iPhone/iPad werkt dat
alleen als Postvak op het beginscherm staat (Deel → Zet op beginscherm).

## 7. Agenda en contacten op je telefoon

Onder **Instellingen → Agenda en taken** staan het serveradres en je gebruikersnaam, en maak je per apparaat een
app-wachtwoord aan. Daar staat ook hoe je het instelt op iPhone, Android (DAVx5), macOS en Thunderbird; contacten gaan via dezelfde server (CardDAV).
Vindt een telefoon de server niet, controleer dan of `.htaccess` in de documentmap staat: die geeft het wachtwoord
door aan PHP en stuurt `/.well-known/caldav` door naar `/dav/`.

## Problemen

| Melding | Oplossing |
|---|---|
| `Dependencies missing` | `vendor` is niet geüpload. Draai `npm run deploy` zonder `--skip-vendor`. |
| `Postvak is not installed yet` | Open `/install.php` (release-zip) of draai één keer `npm run deploy -- --with-config`. |
| `Could not connect to the mail server` | Klopt `default_servers`? Probeer de servernaam van de host in plaats van `mail.<domein>`. |
| 404 op `/api/...` | `.htaccess` ontbreekt of `mod_rewrite` staat uit. Controleer of `.htaccess` in de documentmap staat (verborgen bestanden tonen in je FTP-programma). |
| Wit scherm | Is de frontend gebouwd? `npm run build` zet de bestanden in `backend/public`. |

**Ik ben mijn telefoon én herstelcodes kwijt (tweestapsverificatie).** Een beheerder kan 2FA uitzetten
onder Beheer → Gebruikers. Ben je zelf de enige beheerder, voer dan in phpMyAdmin uit:
`UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_recovery = NULL WHERE login_email = 'jij@voorbeeld.nl';`
