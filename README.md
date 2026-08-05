# SaaS Købmænd – website

Website for podcasten **SaaS Købmænd**. Det lever på `saaskøbmænd.dk` (punycode
`xn--saaskbmnd-m3a9q.dk`) og er hostet på [Simply.com](https://www.simply.com).

Runtime-kravet er **PHP 7.4 eller nyere** med `mbstring`, `SimpleXML` og `DOM`.
Pull requests testes på både PHP 7.4 og 8.4, så nyere PHP-funktioner ikke
utilsigtet kan afkorte outputtet på produktion, og en senere opgradering er sikker.

Sitet er et enkelt PHP-script uden database eller CMS: episoder hentes fra
podcastens RSS-feed, caches og renderes til HTML.

## Sådan virker det

- **`public_html/index.php`** er hele sitet. Det:
  - henter RSS-feedet (`https://anchor.fm/s/10eb99934/podcast/rss`),
  - henter YouTubes offentlige kanal-feed og matcher nye videoer med episoder på titel/dato,
  - bygger en liste af episoder med titel, dato, varighed, beskrivelse, lyd-URL
    og cover-billede samt eventuel YouTube-video, thumbnail og visningstal,
  - laver pæne, æ/ø/å-fri "slugs" til hver episode (fx `63-den-bedste-til-...`),
  - renderer enten **forsiden** (liste over alle episoder), en **enkelt episode**,
    et **`sitemap.xml`**, eller en **404-side**.
- **`public_html/.htaccess`** sender alle URL'er gennem `index.php` (pæne URL'er
  uden `index.php`), undtagen rigtige filer/mapper.
- Episodebilledet pr. episode kommer fra feltet `itunes:image` i feedet.

### Ruter

| URL | Resultat |
|-----|----------|
| `/` | Forside med alle episoder |
| `/episode/{slug}` | Enkelt episode |
| `/vaert/anders-eiler` | Værts- og forfatterprofil for Anders Eiler |
| `/vaert/bo-moeller` | Værts- og forfatterprofil for Bo Møller |
| `/e/{nummer}` | Kort del-link → 301 til `/episode/{slug}` |
| `/sitemap.xml` | XML-sitemap (med billed-namespace) |
| `/llms.txt` | Dynamisk oversigt til AI-/GEO-crawlers (genereres fra feedet) |
| `/robots.txt` | Statisk fil – tillader søgemaskiner + AI-bots, linker til sitemap |
| `/favicon.svg` | Statisk SVG-favicon |
| alt andet | 404-side |

Gamle episode-URL'er bevares: det eksisterende slug-format er uændret, og hvis
en episodetitel senere ændres i RSS-feedet, bliver tidligere slugs med samme
episodenummer 301-redirectet til den aktuelle canonical URL. URL'er med en
afsluttende slash redirectes også til formen uden slash.

### SEO & GEO

- **RSS-feedet caches** i `sys_get_temp_dir()` i 15 min. Hver gyldig hentning
  gemmes også atomisk i `data/podcast-rss-fallback.xml`, så sitet kan starte igen
  med alle episoder, selv hvis Anchor er nede og system-cachen er blevet slettet.
  Deployment-workflowet forsøger desuden at opdatere snapshot-filen lige før
  upload og beholder den eksisterende version, hvis hentningen er ugyldig.
- **YouTube-kataloget er permanent.** `data/youtube-catalog-seed.json` indeholder
  et verificeret 72→72-match mellem alle nuværende RSS-episoder og deres
  hovedvideoer. Otte klip/uddrag på kanalen er bevidst fravalgt. Nye episoder
  matches automatisk på permanent katalog, eksakt titel, episodenummer og til
  sidst dato ±2 dage kombineret med titellighed. Kun nye, ikke-seedede matches
  gemmes i `data/youtube-catalog-runtime.json`, som ikke overskrives ved deployment.
- Hver side har **JSON-LD schema.org**: `PodcastSeries` på forsiden,
  `PodcastEpisode` + `AudioObject` + betinget `VideoObject` + `BreadcrumbList`
  på episode-sider, og `ProfilePage` + `Person` på værtsprofilerne.
- Komplet **Open Graph + Twitter Card** (inkl. `og:audio` og
  `article:published_time` på episoder).
- Synlig **brødkrummesti** og **"Flere episoder"** for intern linkbuilding.
- Værter, podcast-beskrivelse og platform-links sættes ét sted øverst i
  `index.php` (`$hosts`, `$site_name`, `$series_description`, `$platforms`) og
  genbruges i schema.org og `llms.txt`.
- Værtsportrætter ligger lokalt i `public_html/assets/hosts/`, og værternes egne
  websites og LinkedIn-profiler bruges både synligt og som `Person.sameAs`.

## Domæner

Sitet bruger flere domæner. Hver mappe i repoet svarer til en mappe på Simply's FTP:

| Mappe | Rolle |
|-------|-------|
| `public_html/` | Selve sitet (vises på `saaskøbmænd.dk`) |
| `saaskoebmaend.dk/` | **Del-domæne med korte ASCII-links** (se nedenfor) |
| `saaskobmaend.com/`, `saaskobmaend.dk/`, `saaskobmand.dk/`, `saaskoebmaend.com/` | 301-redirect til hovedsitet |

### Korte del-links (uden æ/ø/å)

Til deling på fx LinkedIn bruges ASCII-domænet **`saaskoebmaend.dk`**:

- `saaskoebmaend.dk/e/63` → den enkelte episode (slår nummeret op på hovedsitet)
- `saaskoebmaend.dk/spotify` → Spotify
- `saaskoebmaend.dk/apple` → Apple Podcasts
- `saaskoebmaend.dk/youtube` → YouTube

Disse styres af `saaskoebmaend.dk/.htaccess`. Vil du bruge et andet ASCII-domæne
som del-domæne, så flyt reglerne dertil og ret `$short_url` øverst i `index.php`.

På hver episode-side vises det korte link med en "Kopiér link"-knap.

## Cover art

Feedet indeholder kun ét billedfelt pr. episode (`itunes:image`). Når en episode
kan matches med YouTube, bruger sitet automatisk YouTubes 16:9-thumbnail til
video, episodekort og sociale previews. Hvis der ikke findes en YouTube-video,
vises RSS-coveret i en særlig lyd-fallback, og lydafspilleren fungerer uændret.
Spotifys eget custom episode-cover eksporteres **ikke** til RSS. Et bestemt
billede kan fortsat vælges med `$episode_image_overrides` øverst i `index.php`
(`episodenummer => billed-URL`).

## Logo og ikoner

Det primære mærke er et sammenflettet sort **S** og orange **K**. Den præcise
SVG ligger i `public_html/assets/logo-mark.svg` og bruges i headeren.
`public_html/favicon.svg` er samme mærke til moderne browsere; derudover findes
en 32×32 ICO/PNG-fallback, et 180×180 Apple touch-ikon og 192/512 PNG-ikoner til
`site.webmanifest`. Favicon- og headergeometrien er dermed den samme på tværs af
browserfane, bogmærker, hjemmeskærm og selve websitet.

## Deployment

Push til `main` → GitHub Actions uploader automatisk til Simply via FTPS
(`.github/workflows/deploy.yml`). Workflowet kræver tre secrets i GitHub under
**Settings → Secrets and variables → Actions**:

- `FTP_SERVER` – FTP-host fra Simply
- `FTP_USERNAME` – FTP-brugernavn
- `FTP_PASSWORD` – FTP-kodeord

Repoets rod spejles til FTP-roden. `.git`, `.github`, `.context`, `tests` og
`README.md` uploades ikke. Workflowet kan også køres manuelt fra **Actions**-fanen.

Den permanente regressionstest kan køres lokalt med `bash tests/run-release.sh`.
Den kontrollerer bl.a. komplet HTML-output, alle RSS-episoder, redirects,
værtssider, sitemap, episode-specifikke Open Graph-data og katalog-prioritet.
En syntetisk episode 73 tester desuden Atom-match, thumbnailbeskyttelse og runtime.

## Analytics

Sitet loader en OctoReports-tracking-pixel. Plausible er fjernet.
