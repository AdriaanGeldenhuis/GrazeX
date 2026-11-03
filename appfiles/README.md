# GrazeX Android-app

'n Native Android-app (Kotlin) wat die GrazeX-webwerf (`https://www.grazex.co.za`) in 'n WebView wys. Daarby doen dit wat 'n webwerf nie self kan nie:

- **Push-kennisgewings:**
  - Kliënte kry kennisgewings vir hul bestellings: betaling ontvang, gereed, op pad, gekanselleer, finale faktuur, betaalherinnering, POP afgekeur.
  - Livestock-alerts kom ook as kennisgewing.
  - Personeel kry kennisgewings vir nuwe bestellings, POP's, terugbetalings, navrae en skrapversoeke, volgens hul permissies en afdeling.
  - 'n Tik op 'n kennisgewing maak die regte bladsy oop.
- **Bly aangemeld:** "Keep me logged in" is in die app by verstek aan. Jy bly 60 dae aangemeld, ook nadat die app toegemaak is.
- **Aflaaie:** fakture, kwitansies, POP's en die privaatheid-aflaai word in **Downloads/GrazeX** gestoor (Android 10+) en dadelik oopgemaak. Op Android 8–9 bly die lêer in die app se eie lêers (dit word ook dadelik oopgemaak).
- **Oplaai:** 'n POP kan uit lêers of die galery gekies word, of met die kamera afgeneem word.
- **Skakels:**
  - Skakels na grazex.co.za (in e-posse, WhatsApp, ens.) maak die app oop (App Links). `grazex://app/<pad>` werk ook.
  - Foon-, e-pos-, WhatsApp- en kaartskakels maak die regte app oop.
  - Yoco se kaartbetaling (en die bank se 3-D Secure-blad) bly in die app.
- **Navigasie:**
  - Die terugknoppie maak eers 'n oop dialoog, die mandjie of die menu toe, en gaan dan terug.
  - Trek af om te herlaai, behalwe op checkout- en betaalblaaie.
- **Ander:**
  - 'n "You're offline"-skerm met "Try again".
  - Die app volg die foon se teksgrootte.
  - Volskerm-video.
  - "Use my location" by aflewering.

Die webwerf herken die app aan `GrazeXApp/<weergawe>` in die user agent. Dit verander net hoe die site lyk en wat by verstek aan is; dit gee nooit ekstra regte nie.

**Wat nooit in git kom nie** (sien `.gitignore`):
- `android/app/google-services.json`
- `android/keystore.properties`
- `*.jks` / `*.keystore`
- die Firebase service-account-JSON

Die `appfiles/`-gids word nie na die bediener gedeploy nie.

---

## 1. Bou en toets

**Android Studio** (nuutste weergawe): *File → Open* → kies `appfiles/android` → wag vir die Gradle-sync → ▶ Run.

**Opdragreël** (JDK 17 of nuwer, Android SDK 36):

```bash
cd appfiles/android
```

```bash
./gradlew assembleDebug testDebugUnitTest
```

Die APK is dan `app/build/outputs/apk/debug/app-debug.apk`.

**Teen 'n toetsbediener op jou rekenaar** (die emulator sien jou rekenaar as `10.0.2.2`). Begin die bediener in die projekwortel:

```bash
php -S 0.0.0.0:8080 tests/dev-router.php
```

Installeer dan die app in `appfiles/android`:

```bash
./gradlew installDebug -Pgrazex.baseUrl=http://10.0.2.2:8080
```

Die debug-bou laat gewone `http` net na `10.0.2.2` / `localhost` toe. Die release-bou laat net `https` toe.

**Weergawes:**
- Android Gradle Plugin 8.13, Kotlin 2.3, compile/target SDK 36, min SDK 26 (Android 8.0), Java 17, Gradle 8.14.6 (wrapper, met SHA-256-kontrole).
- Die nuutste AndroidX `core` (1.19+) vereis compileSdk 37 en AGP 9.1. Dit is 'n aparte opgradering.
- Elke nuwe weergawe vir die Play Store: verhoog `versionCode` (en `versionName`) in `app/build.gradle.kts`.

---

## 2. Release-sleutel (eenmalig — rugsteun dit!)

1. Maak die sleutel in `appfiles/android`:
   ```bash
   keytool -genkeypair -v -keystore grazex-upload.jks -keyalg RSA -keysize 4096 -validity 10000 -alias grazex
   ```
2. Skep `appfiles/android/keystore.properties` (nie in git nie):
   ```
   storeFile=grazex-upload.jks
   storePassword=…
   keyAlias=grazex
   keyPassword=…
   ```
3. Bou die release:
   ```bash
   ./gradlew bundleRelease
   ```
   Die resultaat is `app/build/outputs/bundle/release/app-release.aab`. Laai dit op na Play Console.
   Sonder `keystore.properties` stop die release-bou met 'n duidelike boodskap, eerder as om 'n ongetekende lêer te maak. Net vir 'n toetsbou: `-Pgrazex.allowUnsigned=true`.

**Rugsteun** `grazex-upload.jks` en die wagwoorde op twee plekke buite die rekenaar, byvoorbeeld 'n wagwoordbestuurder plus 'n USB in die kluis. Met Play App Signing kan Google 'n verlore upload-sleutel vervang, maar dit neem dae.

---

## 3. App Links (skakels maak die app oop)

1. Gaan na Play Console → *Test and release → App integrity → App signing* en kopieer die **SHA-256 certificate fingerprint** van die *App signing key*. Om self 'n APK te toets, kopieer ook dié van die *Upload key*.
2. Sit dit in die bediener se instellingslêer (`.env.php` / `config/env.php`):
   ```
   ANDROID_PACKAGE=za.co.grazex.app
   ANDROID_SHA256=AB:CD:…:EF,12:34:…:56
   ```
   Kommas skei meer as een sleutel; met of sonder dubbelpunte werk albei.
3. Kyk dat dit werk. Albei hierdie adresse moet die JSON wys, sonder 'n redirect:
   ```bash
   curl -sI https://www.grazex.co.za/.well-known/assetlinks.json
   ```
   ```bash
   curl -sI https://grazex.co.za/.well-known/assetlinks.json
   ```
4. Op 'n foon met die app geïnstalleer (via `adb`), laat die foon eers weer kontroleer:
   ```bash
   adb shell pm verify-app-links --re-verify za.co.grazex.app
   ```
   Wys dan die uitslag:
   ```bash
   adb shell pm get-app-links za.co.grazex.app
   ```
   Albei domeine moet `verified` wys.

---

## 4. Push-kennisgewings met Firebase (stap vir stap)

Sonder Firebase werk alles behalwe kennisgewings. Admin → Settings → System wys "App push notifications: off".

1. **Projek:** gaan na <https://console.firebase.google.com> → *Add project* → "GrazeX". Google Analytics is nie nodig nie.
2. **Android-app:** *Add app → Android*, met pakketnaam `za.co.grazex.app`. Laai `google-services.json` af en sit dit in **`appfiles/android/app/`**. Moet dit nooit commit nie.
3. **Bedienersleutel:**
   - Gaan na *Project settings → Service accounts → Generate new private key*. Jy kry 'n JSON-lêer.
   - Laai dit (met SFTP / Xneelo File Manager) op na **`storage/private/firebase-service-account.json`** op die bediener, en stel die permissies op **600**.
   - Dit gaan nooit in git of in 'n publieke gids nie.
   - 'n Ander plek is moontlik met `FIREBASE_CREDENTIALS=/pad/na/lêer.json` in die instellingslêer.
4. **Databasis:** migrasie `database/migrations/007_app_push.sql` moet in phpMyAdmin geplak wees. Dit word saam met hierdie fase gedoen, voor die merge.
5. **Bou die app weer** (nou met `google-services.json`) en installeer dit. Meld aan en druk **Allow** wanneer die foon vra om kennisgewings te wys. Die vraag kom een keer.
6. **Toets:** gaan na Admin → Settings → System, wat nou "App push notifications: on" moet wys. Druk dan *Send a test notification to my phones*.

**Hoe dit werk:**
- **Stuur:**
  - Die bediener stuur via die FCM HTTP v1-API, met 'n JWT wat met die service-account-sleutel onderteken is (geen ekstra biblioteke nie).
  - Kennisgewings gaan dadelik ná die bladsy uit. Cron en bladsylaaie probeer weer as Google besig is.
  - Hoogstens 20 per foon per uur.
- **Wie ontvang:**
  - 'n Foon kry net kennisgewings vir die aanmelding wat dit gekoppel het. By kliënte geld dit net as "Keep me logged in" aangemerk is (in die app by verstek aan). 'n Kort aanmelding op 'n geleende foon kry dus niks nie.
  - Uitteken, 'n nuwe wagwoord op 'n ander toestel, "log uit oral", 'n geblokkeerde kliënt of 'n gedeaktiveerde admin stop dit. Kennisgewings wat nog in die tou wag, word dan ook nie gestuur nie.
  - 'n Foon wat die app verwyder het, of waarop die app 60 dae lank nie oopgemaak is nie, word outomaties vergeet.
- **Privaatheid:**
  - Op 'n gesluite skerm wys die foon net "GrazeX"; die besonderhede verskyn eers ná ontsluiting.
  - Personeel-kennisgewings dra net die titel (bestelnommer, bedrag) en geen name, e-posadresse of boodskappe nie. Die skakel maak die besonderhede agter die admin-aanmelding oop.
  - As 'n foon van eienaar verander, word die vorige persoon se kennisgewings uitgevee.
- **Kanale:** die foon se instellings het drie kanale: *Orders and payments*, *New livestock* en *Staff*. Mense kan elkeen afskakel.

---

## 5. Play Store

- **Data safety-vorm:**
  - Rekening: naam, e-pos, telefoon.
  - Aankoopgeskiedenis.
  - Lêers wat opgelaai word (POP).
  - *Device or other IDs* (die push-token).
  - Ligging, opsioneel: net vir die afleweringspunt.
  - Alles word versleutel gestuur.
  - Niks word met derdes gedeel nie; Firebase en Yoco is diensverskaffers.
  - Gebruikers kan hul rekening laat skrap (Account → Privacy).
- **Rekening skrap (Play-vereiste vir apps met rekeninge):** gee ook 'n web-adres waar mense skrapping kan versoek, byvoorbeeld `https://www.grazex.co.za/account/profile.php` (aanmeld → *Delete my account*). Maak dit ook in die privaatheidsbeleid bekend, saam met die kontakblad vir wie nie meer kan aanmeld nie.
- **Privaatheidsbeleid:** voeg 'n sin by oor push-kennisgewings en oor "Keep me logged in" ('n koekie van 60 dae).
  - Die toesteltoken word gestoor tot jy uitteken, die app verwyder, of dit 60 dae nie gebruik nie.
  - Kennisgewings gaan via Google Firebase, wat buite Suid-Afrika verwerk word (POPIA a72).
  - Die beleid self is jou prokureur se werk.
- **"Minimum functionality":** Google keur soms apps af wat net 'n webwerf is. Noem in die beskrywing en vir die beoordelaars die native funksies: push-kennisgewings, aflaaie na Downloads, kamera-oplaai vir POP's, App Links en aangemeld bly.
- **Grafika:**
  - Die ikoon is `assets/img/brand/icon-512.png`.
  - 'n Feature-graphic van 1024 × 500 en skermkiekies van 'n foon is nog nodig.
- **Target SDK:** Google vereis elke jaar 'n nuwer target SDK. As Play Console waarsku, verhoog `targetSdk`/`compileSdk`.

---

## 6. Probleme oplos

| Probleem | Oplossing |
|---|---|
| Skakels maak die blaaier oop, nie die app nie | Stap 3: die vingerafdruk in `ANDROID_SHA256`, albei `curl`-toetse 200, en `--re-verify`. |
| Geen kennisgewings nie | Is Admin → Settings → System "on"? Is die app gebou mét `google-services.json`? Is kennisgewings in die foon se instellings aan? Druk die toetsknoppie. |
| "Push not sent" in `storage/logs/app-*.log` | `HTTP 401/403`: die service-account-lêer is verkeerd, of van 'n ander Firebase-projek. |
| Aflaai wys "Saved", maar niks maak oop nie | Installeer 'n PDF-leser. Die lêer is in Downloads/GrazeX (Android 10+). |
| Uitgeteken ná 'n paar dae | Was "Keep me logged in" aangemerk? 'n Nuwe wagwoord of "log out everywhere" teken ook die app uit. As dieselfde "onthou my"-koekie op twee toestelle gebruik word ('n kopie), eindig alle aanmeldings van die rekening uit veiligheid. |
| "This kind of file can't be saved" | Die app stoor net PDF's, foto's (JPEG/PNG/WebP), JSON en CSV. |

**Toetse:**
- `UrlPolicyTest` dek watter skakels in die app bly, betaalblaaie en deep links.
- `FileNamesTest` dek veilige lêername.
- Bedienerkant: `php tests/run.php` (afdelings "Phase 6: …").
