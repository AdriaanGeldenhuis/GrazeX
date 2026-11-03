# GrazeX — Ranch & Feeds

'n Volledige webwerf met twee winkels en 'n admin-portaal:

| Area | URL | Wat dit doen |
|---|---|---|
| Gateway | `/` | Filmiese tuisblad wat na Feeds en Ranch verdeel |
| Feeds | `/feeds/` | Voerwinkel: soek, filters, groottes, specials, grootmaatpryse, sakrekenaar, afleweringstoetser |
| Ranch | `/ranch/` | Lewendehawe-bord, lotblaaie (weeggeskiedenis, gesondheid), reservering met deposito, waarskuwings |
| Checkout | `/feeds/checkout.php`, `/ranch/checkout.php` | Afhaal-gleuwe, afleweringsones, Yoco / EFT / betaal by afhaal |
| Betalings | `/payments/` | Yoco-betaalblad, terugkeerblad, webhook |
| Klantportaal | `/account/` | Bestellings met tydlyn, fakture (PDF), POP-oplaai, herbestel, adresse, waarskuwings, POPIA |
| Inligting | `/pages/` | About, Terms, Privacy, Returns, Delivery, Contact |
| Admin | `/admin/` | Alles bestuur — bestellings, betalings, produkte, lotte, voorraad, kliënte, inhoud, verslae, instellings |

**Tegnologie:** PHP 8.2+ (teiken 8.4), MariaDB/MySQL, vanilla JS + `fetch`, eie CSS. Die enigste biblioteke is PHPMailer en Dompdf, en die GitHub-deploy installeer hulle outomaties.

---

## Lêerboom (opsomming)

```
/                     index.php (gateway) · 404.php · robots.php · sitemap.php · manifest.webmanifest · sw.js · .htaccess
assets/               css/core.css, store.css, checkout.css, landing.css · js/core.js, cart.js, checkout.js, landing.js · fonts/ · img/brand/
config/               app.php · env.example.php · env.php (NIE in git nie) · lang/en.php
includes/             bootstrap.php, db.php, session.php, csrf.php, auth.php, admin_auth.php, rate_limit.php, validator.php, uploads.php, totp.php, view.php …
  services/           Order, Stock, Document, Pdf, Payment(Yoco), Cart, Pricing, Catalog, Livestock, Delivery, Mail, Notification
  layout/ partials/   gedeelde HTML-stukke
feeds/ ranch/ account/ payments/ pages/ admin/ setup/   ← elke area: *.php · css/ · js/ · api/*.php
templates/pdf/        document.php (faktuur, kwitansie, kredietnota, pick list, Document of Identification …)
cron/run.php          een cron-taak vir alles (Xneelo: elke 2 uur)
database/             install.sql (skema + seed) · demo.sql (opsionele voorbeelddata) · migrations/
tests/                run.php (enjin- + API-toetse) · js/ (admin-JS-toetse) · http/smoke.sh (na-deploy-toets) · dev-router.php (plaaslike bediener)
appfiles/             Android-app (Kotlin, Android Studio) — word NIE gedeploy nie. Sien appfiles/README.md
assetlinks.php        /.well-known/assetlinks.json vir die app se App Links (ANDROID_PACKAGE / ANDROID_SHA256)
```

**Android-app:** die bou, die release-sleutel, App Links, en Firebase-push stap vir stap staan in [`appfiles/README.md`](appfiles/README.md).

---

## Opstel op Xneelo (eerste keer)

1. **PHP-weergawe:** konsoleH → *PHP Config* → PHP **8.3** of nuwer (8.3, 8.4 en 8.5 word ondersteun).
2. **Databasis:** *Manage MySQL* → *Add*. Skryf die naam, gebruiker, wagwoord en host neer. Die host lyk soos `sqlNN.jnbN.host-h.net`. Op 'n **managed server** (`dediNNN.cpt1.host-h.net`) gebruik `DB_HOST=localhost`. As die bedienernaam nie bereikbaar is nie, probeer die app self `localhost`.
3. **SQL:** phpMyAdmin → kies die databasis → *SQL*-oortjie → plak die hele `database/install.sql` → *Go*.
   - Wil jy eers met voorbeeldprodukte en -lotte speel? Plak daarna ook `database/demo.sql`. Verwyder dit voor jy live gaan.
4. **Deploy:** merge na `main` en GitHub stuur alles na Xneelo (met PHPMailer en Dompdf), maar net as die toetse slaag.
   - Opsioneel, maar aanbeveel: voeg die GitHub-secret **`SSH_KNOWN_HOSTS`** by (die uitvoer van `ssh-keyscan -p POORT JOU-BEDIENER`). Dan stuur die deploy net na jóú bediener.
   - Die deploy vee nooit self lêers op die bediener uit nie. Lêers wat weg moet (bv. ou toetslêers), sit jy in `deploy/remove-from-server.txt`.
5. **Instellings-lêer:** maak **`.env.php`** of **`.env`** in die website-root (`public_html`). Die app lees enige van hierdie lêers:
   - `.env.php` — PHP: `define('DB_HOST', '…');`, of `$host = '…';`, of `return ['DB_HOST' => '…'];`
   - `.env` — `KEY=VALUE`-reëls (sien **`.env.example`** vir al die sleutels)
   - `config/env.php` — die oorspronklike GrazeX-formaat
   - **Benodig:** `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_URL`, `APP_KEY`, `SETUP_TOKEN`
   - **`APP_KEY`** is die site se geheime sleutel (dit teken wagwoord-herstel-skakels en enkripteer die Yoco-webhook-geheim). Maak een met random.org/strings: lengte 64, syfers + letters. Stel dit een keer en verander dit nooit weer nie.
   - **`SETUP_TOKEN`** is 'n eenmalige wagwoord wat jy self kies (12+ karakters). Jy tik dit net een keer op `/setup/` om die eienaar-rekening te skep.
   - **Opsioneel:** `MAIL_*` (SMTP: `smtp.jou-domein`, poort 465 SSL, gebruikersnaam = volle e-posadres), `YOCO_MODE`, `YOCO_TEST_SECRET_KEY`, `YOCO_LIVE_SECRET_KEY`
   - Lêers wat met 'n punt begin, word deur `.htaccess` van die web af geblokkeer, en die deploy oorskryf hulle nooit nie.
6. **Eienaar-rekening:** gaan na `https://jou-domein/setup/`. Die bladsy toets die bediener, en dan skep jy die eienaar met die `setup_token`. Die bladsy sluit homself daarna.
7. **Admin → Settings:**
   - Vul by *GrazeX Feeds business* en *GrazeX Ranch business* die regsnaam, reg.-nr, BTW, adres en **bankbesonderhede** in.
   - Vul by *General* die telefoon, WhatsApp, adres, Google Maps-skakel en ure in.
8. **Admin → Lists & setup:**
   - afleweringsones en dorpe
   - afhaalpunte en tydgleuwe
   - geslote dae
   - kategorieë en rasse
9. **Yoco:** *Settings → Orders & payments → Connect Yoco webhook*. Doen dit in toetsmodus, toets 'n betaling, sit dan `mode => 'live'` en die `sk_live_…`-sleutel in `env.php`, en klik weer *Connect*.
   - Yoco gee eers live-sleutels ná domeinverifikasie in die Yoco-app.
10. **Cron:** konsoleH → *Cronjob Manager* → elke 2 uur:
    `/usr/bin/php-wrapper /usr/www/users/JOUGEBRUIKER/cron/run.php`
11. **E-pos-aflewering:** maak seker die domein het SPF (`include:spf.host-h.net`), DKIM (Xneelo) en 'n DMARC-rekord.
12. **SSL:** Let's Encrypt is by Xneelo ingesluit, en `.htaccess` dwing HTTPS af.

### Kry jy 'n fout?
- **Voor** die eienaar-rekening bestaan: maak **`https://jou-domein/setup/`** oop. Dit sê in gewone taal wat skort.
  - Sodra die eienaar bestaan, sluit `/setup/` homself (lêer `storage/private/setup.lock`) en wys dit niks meer nie, ook nie as die databasis af is nie.
  - Die slot word vanself opgehef as die databasis weer leeg ingevoer is (geen eienaar nie).
- **Ná** opstelling: kyk na die rooi banier in die admin (ontbrekende instellings) en na `storage/logs/app-DATUM.log`.
- Die setup-blad (voor opstelling) gee boodskappe soos:
  - "settings file not found"
  - "database is empty — paste install.sql"
  - "the database refused the username or password"
  - "APP_KEY missing"
- Elke foutbladsy wys 'n **Reference**-kode. Dieselfde kode staan in `storage/logs/app-DATUM.log` saam met die presiese fout.
- As die databasis nie bereikbaar of nog leeg is nie, sien besoekers 'n vriendelike "Back soon"-bladsy in plaas van 'n fout.

### Voor jy live gaan
- [ ] `APP_DEBUG=false` (of `app_debug => false`)
- [ ] Demo-data verwyder (of produkte/lotte vervang met jou eie)
- [ ] Terms, Privacy en Returns deur jou **prokureur** nagegaan (Admin → Lists & setup → Pages)
- [ ] BTW-status en die nulkoers-instelling deur jou **rekenmeester** bevestig
- [ ] Die formaat van die Document of Identification (Stock Theft Act) bevestig
- [ ] 2FA aan vir elke admin (Admin → My profile), en die 8 herstelkodes veilig gestoor (dit wys net een keer)
- [ ] Geen rooi "Settings file incomplete"-banier in die admin nie (APP_KEY en APP_URL is gestel)
- [ ] 'n Toetsbestelling met elke betaalmetode gedoen, en die e-posse ontvang
- [ ] `bash tests/http/smoke.sh https://jou-domein` wys alles groen

---

## Voorkoms: agtergronde en kleure

- **Elke blad het 'n agtergrondtoneel** (`assets/img/bg/`): tuisblad, Feeds, Ranch, My account, en die inligtingsblaaie. Die blad se inhoud dryf as "glas" daaroor.
- **Eie foto's:** Admin → *Site content* → oortjie **Page backgrounds**. Een foto per deel: Home page, GrazeX Feeds, GrazeX Ranch, My account, Info & contact pages. Kies 'n breë landskapfoto (minstens 1920 × 1080) en klik *Save backgrounds*.
  - Die blad maak die foto vanself 'n bietjie donkerder sodat teks leesbaar bly. *Remove* sit die oorspronklike toneel terug.
  - Op 'n foon wys net die middel van die foto. Stel die foto se fokuspunt (◎) in Admin → *Media* om te kies watter deel wys.
  - Let wel: *Home page — Ranch/Feeds card photo* (op die *Home page*-oortjie) is net die twee kaarte in die middel van die tuisblad, nie die agtergrond nie.
- **Kleure:** Feeds is oranje, Ranch is groen (die skakelaar bo in die header en die knoppies volg dit).
- **Logo:** die GrazeX-logo bo-aan gaan altyd na die tuisblad.

---

## Toetslys (handmatig, ná deploy)

**Feeds**
- [ ] Soek, filters en die sortering werk
- [ ] Vlakprys verander by 10+ sakke
- [ ] Die sakrekenaar gee die regte aantal sakke
- [ ] Die afleweringstoetser herken jou dorp

**Ranch**
- [ ] Die lotblad wys die gewig-grafiek, gesondheidsrekords en deposito
- [ ] "Notify me" stuur 'n e-pos wanneer jy *Notify subscribers* in die admin klik

**Checkout met EFT**
- [ ] Die bestelling word geskep en voorraad trek af (Admin → Inventory → Ledger)
- [ ] Die pro-forma-PDF kom per e-pos
- [ ] Die POP-oplaai verskyn in Admin → Payments
- [ ] *Approve* gee 'n faktuur, kwitansie en e-pos

**Ander betaalmetodes**
- [ ] **Yoco:** betaal met die toetskaart → die terugkeerblad wys "Payment received", en die bestelling is *Paid*
- [ ] **Betaal by afhaal:** *Record payment* in die admin gee 'n kwitansie en faktuur, dan *Mark completed*

**Ranch-reservering**
- [ ] Reserveer → deposito → *Weigh & finalise* (tik die werklike gewig in — die veld is leeg tot jy weeg) → die finale faktuur wys daardie kg
- [ ] *Mark completed* is eers beskikbaar ná *Mark collected* (die Document of Identification word dan uitgereik)
- [ ] *Mark collected* gee die Document of Identification
- [ ] Laat 'n EFT-bestelling verval: na die houtyd word voorraad outomaties terug op die rak gesit

**Admin-roetines**
- [ ] *Cancel order* sit voorraad terug en maak 'n kredietnota as dit reeds gefaktureer was
- [ ] *Refund…* op 'n voltooide bestelling (net eienaar/bestuurder) gee 'n kredietnota; voorraad gaan net terug as jy "goods came back" merk
- [ ] Users → *Save permissions* hou Manager en Staff se regte presies soos gemerk
- [ ] Inventory: *Receive stock* en *Stock take* werk en word in die ledger aangeteken
- [ ] Content: verander 'n hero-teks en sien dit dadelik op die site
- [ ] Op die foon is die admin se onderste navigasie bruikbaar

---

## Plaaslik ontwikkel

```bash
cp config/env.example.php config/env.php   # vul plaaslike DB in, app_debug => true, app_url => http://127.0.0.1:8080
composer install
php -S 127.0.0.1:8080 tests/dev-router.php
php tests/run.php                           # enjin- + API-toetse op 'n wegwerp-databasis (<db>_test)
node tests/js/formToObject.test.mjs         # admin-vorms se JS
```

Die toetse het nodig:
- MariaDB 10.11 (of MySQL 8) en 'n DB-gebruiker wat `CREATE DATABASE` mag doen.
- 'n Toets-webhook-geheim in jou instellings: `YOCO_TEST_WEBHOOK_SECRET=whsec_…` (enige base64-waarde werk plaaslik).
- Die PHP-uitbreidings `curl` en `gd`. Die API-toetse begin self 'n `php -S`-bediener teen die toetsdatabasis.

Elke pull request en elke deploy hardloop dieselfde toetse op GitHub (`.github/workflows/ci.yml`). As 'n toets faal, gaan niks live nie.

## Databasis-veranderinge later
Nuwe veranderinge kom as genommerde lêers in `database/migrations/` (bv. `003_money_fixes.sql`). Sien `database/migrations/README.txt`.
- Kyk eers watter reeds geloop het: `SELECT * FROM schema_migrations;` (bestaan die tabel nie, begin by `002`).
- Plak elke ontbrekende lêer in volgorde in phpMyAdmin, **voordat** jy die PR merge wat dit nodig het. Elke merge deploy dadelik.
- Elke lêer is veilig om twee keer te hardloop.
- `install.sql` is net vir 'n nuwe, leë databasis en bevat reeds al die migrasies.
