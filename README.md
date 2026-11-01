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
tests/                run.php (56 enjin-toetse) · dev-router.php (plaaslike bediener)
```

---

## Opstel op Xneelo (eerste keer)

1. **PHP-weergawe:** konsoleH → *PHP Config* → kies **PHP 8.4** (8.3 werk ook).
2. **Databasis:** *Manage MySQL* → *Add*. Skryf die naam, gebruiker, wagwoord en host neer. Die host lyk soos `sqlNN.jnbN.host-h.net`.
3. **SQL:** phpMyAdmin → kies die databasis → *SQL*-oortjie → plak die hele `database/install.sql` → *Go*.
   - Wil jy eers met voorbeeldprodukte en -lotte speel? Plak daarna ook `database/demo.sql`. Verwyder dit voor jy live gaan.
4. **Deploy:** merge na `main` en GitHub stuur alles na Xneelo (met PHPMailer en Dompdf).
5. **`config/env.php`:** kopieer `config/env.example.php` na `config/env.php` op die bediener (via SFTP of die File Manager) en vul dit in:
   - `app_url` (bv. `https://www.grazex.co.za`)
   - `app_key`: 64 lukrake hex-karakters. Genereer dit met `php -r "echo bin2hex(random_bytes(32));"`
   - `setup_token`: enige lang geheime woord
   - `db` en `mail` (SMTP: `smtp.jou-domein` poort 465 SSL; die gebruikersnaam is die volle e-posadres)
   - `yoco`: begin met `mode => 'test'` en jou `sk_test_…`-sleutel
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

### Voor jy live gaan
- [ ] `app_debug => false` in `env.php`
- [ ] Demo-data verwyder (of produkte/lotte vervang met jou eie)
- [ ] Terms, Privacy en Returns deur jou **prokureur** nagegaan (Admin → Lists & setup → Pages)
- [ ] BTW-status en die nulkoers-instelling deur jou **rekenmeester** bevestig
- [ ] Die formaat van die Document of Identification (Stock Theft Act) bevestig
- [ ] 2FA aan vir elke admin (Admin → My profile)
- [ ] 'n Toetsbestelling met elke betaalmetode gedoen, en die e-posse ontvang

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
- [ ] Reserveer → deposito → *Weigh & finalise* → finale faktuur
- [ ] *Mark collected* gee die Document of Identification
- [ ] Laat 'n EFT-bestelling verval: na die houtyd word voorraad outomaties terug op die rak gesit

**Admin-roetines**
- [ ] *Cancel order* sit voorraad terug en maak 'n kredietnota as dit reeds gefaktureer was
- [ ] Inventory: *Receive stock* en *Stock take* werk en word in die ledger aangeteken
- [ ] Content: verander 'n hero-teks en sien dit dadelik op die site
- [ ] Op die foon is die admin se onderste navigasie bruikbaar

---

## Plaaslik ontwikkel

```bash
cp config/env.example.php config/env.php   # vul plaaslike DB in, app_debug => true, app_url => http://127.0.0.1:8080
composer install
php -S 127.0.0.1:8080 tests/dev-router.php
php tests/run.php                           # 56 enjin-toetse op 'n wegwerp-databasis
```

## Databasis-veranderinge later
Nuwe veranderinge kom as genommerde lêers in `database/migrations/` (bv. `002_add_x.sql`). Plak hulle in volgorde in phpMyAdmin. `install.sql` is net vir 'n nuwe, leë databasis.
