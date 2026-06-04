# SaaS Købmænd – website

Website for podcasten **SaaS Købmænd**. Det lever på `saaskøbmænd.dk` (punycode
`xn--saaskbmnd-m3a9q.dk`) og er hostet på [Simply.com](https://www.simply.com).

Sitet er et enkelt PHP-script uden database eller CMS: alle episoder hentes live
fra podcastens RSS-feed ved hvert sidevisning og renderes til HTML.

## Sådan virker det

- **`public_html/index.php`** er hele sitet. Det:
  - henter RSS-feedet (`https://anchor.fm/s/10eb99934/podcast/rss`),
  - bygger en liste af episoder med titel, dato, varighed, beskrivelse, lyd-URL
    og cover-billede,
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
| `/e/{nummer}` | Kort del-link → 301 til `/episode/{slug}` |
| `/sitemap.xml` | XML-sitemap (med billed-namespace) |
| `/llms.txt` | Dynamisk oversigt til AI-/GEO-crawlers (genereres fra feedet) |
| `/robots.txt` | Statisk fil – tillader søgemaskiner + AI-bots, linker til sitemap |
| `/favicon.svg` | Statisk SVG-favicon |
| alt andet | 404-side |

### SEO & GEO

- **RSS-feedet caches** i `sys_get_temp_dir()` i 15 min. Hvis Anchor er nede eller
  langsom, serverer sitet sidste kendte version i stedet for at fejle.
- Hver side har **JSON-LD schema.org**: `PodcastSeries` på forsiden,
  `PodcastEpisode` + `AudioObject` + `BreadcrumbList` på episode-sider,
  samt `Person` for værterne.
- Komplet **Open Graph + Twitter Card** (inkl. `og:audio` og
  `article:published_time` på episoder).
- Synlig **brødkrummesti** og **"Flere episoder"** for intern linkbuilding.
- Værter, podcast-beskrivelse og platform-links sættes ét sted øverst i
  `index.php` (`$hosts`, `$site_name`, `$series_description`, `$platforms`) og
  genbruges i schema.org og `llms.txt`.

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

Feedet indeholder kun ét billedfelt pr. episode (`itunes:image`), som er det
sitet viser. Spotifys eget custom episode-cover eksporteres **ikke** til RSS, så
det kan ikke hentes automatisk. Vil du alligevel vise et bestemt billede på en
episode, kan du udfylde `$episode_image_overrides` øverst i `index.php`
(`episodenummer => billed-URL`).

## Deployment

Push til `main` → GitHub Actions uploader automatisk til Simply via FTPS
(`.github/workflows/deploy.yml`). Workflowet kræver tre secrets i GitHub under
**Settings → Secrets and variables → Actions**:

- `FTP_SERVER` – FTP-host fra Simply
- `FTP_USERNAME` – FTP-brugernavn
- `FTP_PASSWORD` – FTP-kodeord

Repoets rod spejles til FTP-roden. `.git`, `.github`, `.context` og `.md`-filer
uploades ikke. Workflowet kan også køres manuelt fra **Actions**-fanen.

## Analytics

Sitet loader [Plausible](https://plausible.io) og en OctoReports-tracking-pixel.
