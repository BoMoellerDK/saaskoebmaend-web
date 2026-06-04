<?php
// Konfiguration
$rss_url   = 'https://anchor.fm/s/10eb99934/podcast/rss';
$site_url  = 'https://xn--saaskbmnd-m3a9q.dk/'; // saaskøbmænd.dk – din kanoniske base-URL (punycode)
$short_url = 'https://saaskoebmaend.dk';      // ASCII-domæne til korte del-links (uden æ/ø/å)

// Valgfri: manuelt cover art pr. episode (fx et Spotify-cover).
// OBS: Spotifys eget custom episode-cover kommer IKKE med i RSS-feedet – feedet
// indeholder kun ét billedfelt (itunes:image), som er det vi viser i forvejen.
// Vil du alligevel vise et andet billede på en bestemt episode, så angiv det her
// som  episodenummer => billed-URL  (afkommentér og udfyld efter behov):
$episode_image_overrides = [
    // 63 => 'https://eksempel.dk/mit-spotify-cover.jpg',
];

// Navn + beskrivelse på podcasten (bruges til SEO, schema.org og llms.txt)
$site_name = 'SaaS Købmænd';
$series_description = 'Hør SaaS Købmænd: ærlige samtaler med danske SaaS-iværksættere om forretning, vækst og livet som software-entreprenør.';

// Værterne (bruges til schema.org Person + llms.txt)
$hosts = [
    [
        'name'   => 'Anders Eiler',
        'url'    => 'https://anderseiler.com',
        'bio'    => 'SaaS-iværksætter og podcastvært. Står bag Herodesk.',
        'sameAs' => ['https://herodesk.io'],
    ],
    [
        'name'   => 'Bo Møller',
        'url'    => 'https://bandeja.org',
        'bio'    => 'Serieiværksætter med fokus på SaaS (Alunta, resOS, AnyHOA, PingPuffin m.fl.).',
        'sameAs' => ['https://alunta.com', 'https://resos.com', 'https://octoreports.com', 'https://pingpuffin.com'],
    ],
];

// Lyt-platforme (bruges til del-links + llms.txt)
$platforms = [
    'Apple Podcasts' => $short_url . '/apple',
    'Spotify'        => $short_url . '/spotify',
    'YouTube'        => $short_url . '/youtube',
];

// Kanoniske platform-profiler (til schema.org sameAs – binder podcastens identitet
// sammen på tværs af platforme, vigtigt for GEO/entity-genkendelse)
$platform_profiles = [
    'https://open.spotify.com/show/3PwjiFpVxnHuY3E6ARS8YN',
    'https://podcasts.apple.com/us/podcast/saas-k%C3%B8bm%C3%A6nd/id1810152143',
    'https://www.youtube.com/@saask%C3%B8bm%C3%A6nd',
];

// ===== Hjælpefunktioner =====
function dk_slugify($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $repl = ['æ'=>'ae','ø'=>'oe','å'=>'aa','é'=>'e','á'=>'a','ö'=>'o','ü'=>'u','ä'=>'a'];
    $str = strtr($str, $repl);
    $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $str = preg_replace('~[^a-z0-9]+~', '-', $str);
    $str = preg_replace('~-+~', '-', $str);
    $str = trim($str, '-');
    return $str ?: 'episode';
}
function short_title_for_slug($title, $max_words = 6, $max_len = 42) {
    $title = trim(preg_replace('/\s+/', ' ', $title));
    $words = preg_split('/\s+/', $title);
    $words = array_slice($words, 0, $max_words);
    $short = implode(' ', $words);
    if (mb_strlen($short, 'UTF-8') > $max_len) $short = mb_substr($short, 0, $max_len, 'UTF-8');
    return $short;
}
function get_audio_url_from_item($item) {
    if (isset($item->enclosure)) return (string)$item->enclosure['url'];
    if ($item->children('media', true)->content) {
        return (string)$item->children('media', true)->content->attributes()->url;
    }
    return null;
}
function extract_episode_number_from_title($title) {
    if (preg_match('/(?:^|\s)(?:episode|ep|#)\s*(\d+)\b/i', $title, $m)) return (int)$m[1];
    return null;
}
function parse_duration_seconds($item) {
    $itunes = $item->children('itunes', true);
    if (!empty($itunes->duration)) {
        $raw = trim((string)$itunes->duration);
        if (ctype_digit($raw)) return (int)$raw;
        $parts = explode(':', $raw);
        if (count($parts) === 3) {
            return ((int)$parts[0])*3600 + ((int)$parts[1])*60 + (int)$parts[2];
        } elseif (count($parts) === 2) {
            return ((int)$parts[0])*60 + (int)$parts[1];
        }
    }
    $media = $item->children('media', true);
    if ($media && $media->content) {
        $dur = $media->content->attributes()->duration ?? null;
        if ($dur !== null && ctype_digit((string)$dur)) return (int)$dur;
    }
    return null;
}
function format_duration($seconds) {
    if ($seconds === null) return null;
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) return sprintf('%d:%02d:%02d', $h, $m, $s);
    return sprintf('%d:%02d', $m, $s);
}
// Ren tekst-teaser fra (evt. HTML-)indhold: fjern tags, fold whitespace, klip til længde
function teaser($html, $limit, $ellipsis = true) {
    $text = strip_tags((string)$html);
    // Afkod entiteter (&quot; &amp; …) til ren tekst – ellers lækker de i llms.txt
    // og dobbelt-encodes (&amp;quot;) når output senere køres gennem htmlspecialchars.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
    return mb_substr($text, 0, $limit, 'UTF-8') . ($ellipsis ? '…' : '');
}
// ISO 8601-varighed (fx "PT1H2M3S") til schema.org timeRequired
function iso8601_duration($seconds) {
    if (!$seconds) return null;
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    $out = 'PT';
    if ($h) $out .= $h . 'H';
    if ($m) $out .= $m . 'M';
    if ($s) $out .= $s . 'S';
    return $out === 'PT' ? 'PT0S' : $out;
}
// Sanitér HTML fra RSS-feedet: tillad kun en whitelist af tags, fjern ALLE
// attributter undtagen sikre href'er på <a> (strip_tags fjerner tags, men ikke
// fx onclick="" eller href="javascript:" på tilladte tags – derfor DOM-rensning).
function sanitize_episode_html($html) {
    $html = (string)$html;
    if (trim($html) === '') return '';

    $allowed = ['p','br','strong','em','b','i','ul','ol','li','a','blockquote','h2','h3','h4','code','pre'];

    // Fallback hvis ext-dom mangler: render som ren tekst (aldrig rå HTML).
    if (!class_exists('DOMDocument')) {
        return nl2br(htmlspecialchars(trim(strip_tags($html)), ENT_QUOTES, 'UTF-8'));
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="__wrap">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    foreach (iterator_to_array($xpath->query('//*')) as $el) {
        if (!$el->parentNode) continue; // allerede fjernet som del af et droppet subtræ
        $tag = strtolower($el->nodeName);
        if ($tag === 'div' && $el->getAttribute('id') === '__wrap') continue; // wrapper

        if (!in_array($tag, $allowed, true)) {
            if (in_array($tag, ['script','style','template','noscript'], true)) {
                // Drop hele subtræet inkl. tekstindhold (ikke bare tagget)
                if ($el->parentNode) $el->parentNode->removeChild($el);
            } else {
                // Andre ikke-tilladte tags: pak indholdet ud (behold teksten)
                while ($el->firstChild) $el->parentNode->insertBefore($el->firstChild, $el);
                $el->parentNode->removeChild($el);
            }
            continue;
        }

        // Fjern alle attributter undtagen en sikker href på <a>
        for ($i = $el->attributes->length - 1; $i >= 0; $i--) {
            $attr = $el->attributes->item($i);
            $keep = $tag === 'a'
                && strtolower($attr->name) === 'href'
                && preg_match('#^(https?:|mailto:|/|\#)#i', trim($attr->value));
            if (!$keep) $el->removeAttribute($attr->name);
        }
        if ($tag === 'a' && $el->getAttribute('href') !== '') {
            $el->setAttribute('rel', 'noopener nofollow ugc');
            $el->setAttribute('target', '_blank');
        }
    }

    $wrap = $xpath->query('//*[@id="__wrap"]')->item(0);
    $out = '';
    if ($wrap) foreach ($wrap->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}
// Fjern tomme felter (null, '', []) rekursivt fra et JSON-LD-træ
function ld_clean($v) {
    if (!is_array($v)) return $v;
    $out = [];
    foreach ($v as $k => $val) {
        $val = ld_clean($val);
        if ($val === null || $val === '' || $val === []) continue;
        $out[$k] = $val;
    }
    return $out;
}

// Hent RSS med fil-cache: hurtig respons + sitet bliver ikke nede hvis feedet
// er langsomt/utilgængeligt (falder tilbage på sidste kendte version).
function fetch_rss_cached($rss_url, $ttl = 900) {
    $cache_file = sys_get_temp_dir() . '/saaskobmaend_rss_' . md5($rss_url) . '.xml';

    // 1) Frisk cache? Brug den.
    if (is_file($cache_file) && (time() - (int)@filemtime($cache_file) < $ttl)) {
        $xml = @file_get_contents($cache_file);
        if ($xml !== false) {
            $rss = @simplexml_load_string($xml);
            if ($rss) return $rss;
        }
    }

    // 2) Hent friskt feed (med timeout, så en langsom server ikke hænger sitet).
    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'user_agent' => 'saaskobmaend-web/1.0'],
        'https'=> ['timeout' => 8],
    ]);
    $xml = @file_get_contents($rss_url, false, $ctx);
    if ($xml !== false) {
        $rss = @simplexml_load_string($xml);
        if ($rss) {
            @file_put_contents($cache_file, $xml, LOCK_EX);
            return $rss;
        }
    }

    // 3) Hentning fejlede – fald tilbage på (evt. forældet) cache.
    if (is_file($cache_file)) {
        $xml = @file_get_contents($cache_file);
        if ($xml !== false) {
            $rss = @simplexml_load_string($xml);
            if ($rss) return $rss;
        }
    }
    return false;
}

// Episode thumbnail/cover-art (prioritér stort billede)
function get_episode_image_from_item($item, $fallback = '') {
    $itunes = $item->children('itunes', true);
    if ($itunes && isset($itunes->image)) {
        $attrs = $itunes->image->attributes();
        if ($attrs && !empty($attrs['href'])) return (string)$attrs['href'];
    }

    if (!empty($fallback)) return $fallback;

    $media = $item->children('media', true);
    if ($media && isset($media->thumbnail)) {
        $attrs = $media->thumbnail->attributes();
        if ($attrs && !empty($attrs['url'])) return (string)$attrs['url'];
    }

    return '';
}

// ===== Hent RSS (med cache) =====
$rss = fetch_rss_cached($rss_url, 900); // cache i 15 min
if (!$rss) { http_response_code(500); die('<h2>Kunne ikke hente podcast-feedet.</h2>'); }

// Podcast cover — preferér itunes:image (typisk stor) først
$cover_image = '';
$itunes_ch = $rss->channel->children('itunes', true);
if ($itunes_ch && isset($itunes_ch->image)) {
    $attrs = $itunes_ch->image->attributes();
    if ($attrs && !empty($attrs['href'])) $cover_image = (string)$attrs['href'];
}
if ($cover_image === '' && isset($rss->channel->image->url)) {
    $cover_image = (string)$rss->channel->image->url;
}

// ===== Byg episodes =====
$episodes = [];
$slug_to_index = [];
$idx = 0;

foreach ($rss->channel->item as $item) {
    $title = (string)$item->title;
    $pub_ts = strtotime((string)$item->pubDate);
    $date_iso = $pub_ts ? date('Y-m-d', $pub_ts) : '';
    $date_iso_full = $pub_ts ? date('c', $pub_ts) : ''; // fuld ISO8601 til OG/schema
    $date_human = $pub_ts ? date('d.m.Y', $pub_ts) : '';
    $desc_raw = (string)$item->description;

    $content_ns = $item->children('http://purl.org/rss/1.0/modules/content/');
    $content_encoded = isset($content_ns->encoded) ? (string)$content_ns->encoded : '';

    $audio_url = get_audio_url_from_item($item);
    $ep_no = extract_episode_number_from_title($title);

    $duration_seconds = parse_duration_seconds($item);
    $duration_label = format_duration($duration_seconds);

    $ep_image = get_episode_image_from_item($item, $cover_image);

    $short_title = short_title_for_slug($title);
    $base_slug = $ep_no ? ($ep_no . '-' . $short_title) : $short_title;
    $slug = dk_slugify($base_slug);

    $original_slug = $slug; $dupe = 2;
    while (isset($slug_to_index[$slug])) { $slug = $original_slug . '-' . $dupe; $dupe++; }

    $episodes[] = [
        'title'       => $title,
        'slug'        => $slug,
        'date_human'  => $date_human,
        'date_iso'    => $date_iso,
        'date_iso_full' => $date_iso_full,
        'content'     => $content_encoded ?: $desc_raw,
        'audio_url'   => $audio_url,
        'ep_no'       => $ep_no,
        'duration_s'  => $duration_seconds,
        'duration'    => $duration_label,
        'image'       => $ep_image,
        'idx'         => $idx,
    ];
    $slug_to_index[$slug] = $idx;
    $idx++;
}
$total = count($episodes);

// Fallback for ep_no (nyeste først -> højeste nr) og konsistent slug
for ($i = 0; $i < $total; $i++) {
    if ($episodes[$i]['ep_no'] === null) {
        $episodes[$i]['ep_no'] = $total - $i;
        $short_title = short_title_for_slug($episodes[$i]['title']);
        $new_slug = dk_slugify($episodes[$i]['ep_no'] . '-' . $short_title);
        if (!isset($slug_to_index[$new_slug])) {
            unset($slug_to_index[$episodes[$i]['slug']]);
            $episodes[$i]['slug'] = $new_slug;
            $slug_to_index[$new_slug] = $i;
        }
    }
}

// Anvend evt. manuelle billed-overrides (efter episodenumre er endeligt sat)
foreach ($episodes as &$ep_ref) {
    if (isset($episode_image_overrides[(int)$ep_ref['ep_no']])) {
        $ep_ref['image'] = $episode_image_overrides[(int)$ep_ref['ep_no']];
    }
}
unset($ep_ref);

// ===== Routing =====
$request_uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$path = rtrim($request_uri, '/');

// --- SITEMAP ---
if (preg_match('#^/sitemap\.xml$#', $request_uri)) {
    header('Content-Type: application/xml; charset=UTF-8');
    $base = rtrim($site_url, '/');
    $latest_iso = '';
    foreach ($episodes as $ep) {
        if ($ep['date_iso'] && $ep['date_iso'] > $latest_iso) $latest_iso = $ep['date_iso'];
    }
    if ($latest_iso === '') $latest_iso = date('Y-m-d');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' .
         ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($base . '/', ENT_XML1) . '</loc>' . "\n";
    echo '    <lastmod>' . htmlspecialchars($latest_iso) . '</lastmod>' . "\n";
    echo '    <changefreq>weekly</changefreq>' . "\n";
    echo '    <priority>1.0</priority>' . "\n";
    echo '  </url>' . "\n";

    foreach ($episodes as $ep) {
        $loc = $base . '/episode/' . rawurlencode($ep['slug']);
        $lastmod = $ep['date_iso'] ?: $latest_iso;
        echo '  <url>' . "\n";
        echo '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . "\n";
        echo '    <lastmod>' . htmlspecialchars($lastmod) . '</lastmod>' . "\n";
        echo '    <changefreq>monthly</changefreq>' . "\n";
        echo '    <priority>0.8</priority>' . "\n";
        if (!empty($ep['image'])) {
            echo '    <image:image>' . "\n";
            echo '      <image:loc>' . htmlspecialchars($ep['image'], ENT_XML1) . '</image:loc>' . "\n";
            echo '      <image:title>' . htmlspecialchars($ep['title'], ENT_XML1) . '</image:title>' . "\n";
            echo '    </image:image>' . "\n";
        }
        echo '  </url>' . "\n";
    }
    echo '</urlset>';
    exit;
}

// --- LLMS.TXT (GEO / AI-crawlers) ---
if (preg_match('#^/llms\.txt$#', $request_uri)) {
    header('Content-Type: text/plain; charset=UTF-8');
    $base = rtrim($site_url, '/');
    $L = [];
    $L[] = '# ' . $site_name;
    $L[] = '';
    $L[] = '> ' . $series_description;
    $L[] = '';
    $L[] = 'SaaS Købmænd er en dansk podcast om SaaS, iværksætteri og forretning. '
         . 'Nye episoder udkommer (næsten) hver mandag. Sproget er dansk.';
    $L[] = '';
    $L[] = '## Værter';
    foreach ($hosts as $h) {
        $L[] = '- ' . $h['name'] . ' — ' . $h['bio'] . ' ' . $h['url'];
    }
    $L[] = '';
    $L[] = '## Episoder';
    foreach ($episodes as $ep) {
        $loc = $base . '/episode/' . rawurlencode($ep['slug']);
        // Titel skal ikke kunne bryde markdown-linket: erstat []-tegn og fold whitespace
        $safe_title = trim(preg_replace('/\s+/', ' ', strtr($ep['title'], ['[' => '(', ']' => ')'])));
        $date_part = $ep['date_iso'] ? ' (' . $ep['date_iso'] . ')' : '';
        $L[] = '- [Episode ' . (int)$ep['ep_no'] . ': ' . $safe_title . '](' . $loc . ')' . $date_part . ': ' . teaser($ep['content'], 160);
    }
    $L[] = '';
    $L[] = '## Lyt';
    foreach ($platforms as $name => $url) {
        $L[] = '- ' . $name . ': ' . $url;
    }
    $L[] = '';
    echo implode("\n", $L);
    exit;
}

// --- Single / Forside / 404 ---
$is_single = false; $requested_slug = null; $is_404 = false;

if ($path === '' || $path === '/') {
    // forside
} elseif (preg_match('#^/e/(\d+)$#', $path, $m)) {
    // Kort del-link: /e/63 -> 301 til den fulde episode-URL
    $wanted = (int)$m[1];
    foreach ($episodes as $ep) {
        if ((int)$ep['ep_no'] === $wanted) {
            header('Location: ' . rtrim($site_url, '/') . '/episode/' . rawurlencode($ep['slug']), true, 301);
            exit;
        }
    }
    http_response_code(404);
    $is_404 = true;
} elseif (preg_match('#^/episode/([a-z0-9\-]+)$#', $path, $m)) {
    $requested_slug = $m[1];
    if (isset($slug_to_index[$requested_slug])) {
        $is_single = true;
    } else {
        http_response_code(404);
        $is_404 = true;
    }
} else {
    http_response_code(404);
    $is_404 = true;
}

// ===== SEO / OG =====
$page_title = "SaaS Købmænd Podcast – Alle episoder";
$page_description = $series_description;
$page_url = rtrim($site_url, '/') . '/';
$og_image = $cover_image;

$single = null; $prev_link = null; $prev_label = null; $next_link = null; $next_label = null;

if ($is_single) {
    $i = $slug_to_index[$requested_slug];
    $single = $episodes[$i];

    $page_title = $single['title'] . " – SaaS Købmænd";
    $page_description = teaser($single['content'], 200);
    $page_url = rtrim($site_url, '/') . '/episode/' . rawurlencode($single['slug']);

    $og_image = !empty($single['image']) ? $single['image'] : $cover_image;

    $curr_no = (int)$single['ep_no'];
    $lower = null; $higher = null;
    foreach ($episodes as $ep) {
        if ((int)$ep['ep_no'] < $curr_no) {
            if ($lower === null || (int)$ep['ep_no'] > (int)$lower['ep_no']) $lower = $ep;
        } elseif ((int)$ep['ep_no'] > $curr_no) {
            if ($higher === null || (int)$ep['ep_no'] < (int)$higher['ep_no']) $higher = $ep;
        }
    }
    if ($lower) { $prev_link  = '/episode/' . htmlspecialchars($lower['slug']);  $prev_label = '← Episode ' . (int)$lower['ep_no']; }
    if ($higher){ $next_link  = '/episode/' . htmlspecialchars($higher['slug']); $next_label = 'Episode ' . (int)$higher['ep_no'] . ' →'; }
} elseif ($is_404) {
    $page_title = "404 – Siden findes ikke · SaaS Købmænd";
    $page_description = "Ups! Den side findes ikke. Måske leder du efter en af vores podcast-episoder?";
    // $page_url bevares som forsiden (default) – en 404 skal ikke kanonisere til sig selv.
}

// ===== JSON-LD (schema.org) =====
$ld_base = rtrim($site_url, '/');
$ld_persons = [];
foreach ($hosts as $h) {
    $p = ['@type' => 'Person', 'name' => $h['name'], 'url' => $h['url']];
    if (!empty($h['sameAs'])) $p['sameAs'] = $h['sameAs'];
    $ld_persons[] = $p;
}

$ld_graph = [];
if ($is_single && $single) {
    $episode_node = [
        '@type'         => 'PodcastEpisode',
        'url'           => $page_url,
        'name'          => $single['title'],
        'datePublished' => $single['date_iso_full'] ?: ($single['date_iso'] ?: null),
        'episodeNumber' => (int)$single['ep_no'],
        'timeRequired'  => iso8601_duration($single['duration_s']),
        'description'   => $page_description,
        'image'         => $og_image ?: null,
        'inLanguage'    => 'da-DK',
        'partOfSeries'  => [
            '@type' => 'PodcastSeries',
            'name'  => $site_name,
            'url'   => $ld_base . '/',
        ],
        'author'        => $ld_persons,
    ];
    if (!empty($single['audio_url'])) {
        $episode_node['associatedMedia'] = [
            '@type'          => 'AudioObject',
            'contentUrl'     => $single['audio_url'],
            'encodingFormat' => 'audio/mpeg',
        ];
    }
    $ld_graph[] = $episode_node;
    $ld_graph[] = [
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Forside', 'item' => $ld_base . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Episode ' . (int)$single['ep_no'], 'item' => $page_url],
        ],
    ];
} elseif (!$is_404) {
    $ld_graph[] = [
        '@type'       => 'PodcastSeries',
        'name'        => $site_name,
        'url'         => $ld_base . '/',
        'description' => $series_description,
        'inLanguage'  => 'da-DK',
        'image'       => $cover_image ?: null,
        'webFeed'     => $rss_url,
        'sameAs'      => $platform_profiles,
        'author'      => $ld_persons,
    ];
}

$json_ld = null;
if ($ld_graph) {
    $doc = (count($ld_graph) === 1)
        ? array_merge(['@context' => 'https://schema.org'], $ld_graph[0])
        : ['@context' => 'https://schema.org', '@graph' => $ld_graph];
    // Slashes escapes IKKE-unescaped, så "</script>" i data ikke kan bryde ud af <script>.
    $json_ld = json_encode(ld_clean($doc), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// ===== View =====
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <?php if ($is_404): ?>
    <meta name="robots" content="noindex">
    <?php else: ?>
    <link rel="canonical" href="<?= htmlspecialchars($page_url) ?>">
    <?php endif; ?>

    <?php if ($is_single && $prev_link): ?><link rel="prev" href="<?= $prev_link ?>"><?php endif; ?>
    <?php if ($is_single && $next_link): ?><link rel="next" href="<?= $next_link ?>"><?php endif; ?>

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <?php if ($cover_image): ?>
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($cover_image) ?>">
    <?php endif; ?>
    <meta name="theme-color" content="#2546af">

    <!-- Podcast-feed (gør RSS-feedet synligt for feed-læsere og podcast-crawlere) -->
    <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($site_name) ?>" href="<?= htmlspecialchars($rss_url) ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="<?= $is_single ? 'article' : 'website' ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($site_name) ?>">
    <meta property="og:locale" content="da_DK">
    <meta property="og:url" content="<?= htmlspecialchars($page_url) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>">
    <?php if (!empty($og_image)): ?>
      <meta property="og:image" content="<?= htmlspecialchars($og_image) ?>">
      <meta property="og:image:alt" content="<?= htmlspecialchars($page_title) ?>">
    <?php endif; ?>
    <?php if ($is_single && $single): ?>
      <?php $pub_og = $single['date_iso_full'] ?: $single['date_iso']; ?>
      <?php if (!empty($pub_og)): ?><meta property="article:published_time" content="<?= htmlspecialchars($pub_og) ?>"><?php endif; ?>
      <?php if (!empty($single['audio_url'])): ?><meta property="og:audio" content="<?= htmlspecialchars($single['audio_url']) ?>"><meta property="og:audio:type" content="audio/mpeg"><?php endif; ?>
    <?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <?php if (!empty($og_image)): ?><meta name="twitter:image" content="<?= htmlspecialchars($og_image) ?>"><?php endif; ?>

    <?php if ($json_ld): ?>
    <script type="application/ld+json">
<?= $json_ld ?>
</script>
    <?php endif; ?>

    <!-- Plausible -->
    <script defer data-domain="saaskøbmænd.dk" src="https://plausible.io/js/script.outbound-links.js"></script>

    <style>
        html, body { background:#fff; color:#111; font-family:'Inter', Arial, sans-serif; margin:0; padding:0; }
        .container { max-width:680px; margin:40px auto; padding:24px; }

        /* Forside-cover */
        .podcast-cover { width:160px; height:160px; object-fit:cover; border-radius:18px; box-shadow:0 4px 24px rgba(0,0,0,0.07); display:block; margin:0 auto 18px; }

        /* ✅ Links (forsiden) – tilbage til oprindelig styling */
        .links { text-align:center; margin-bottom:34px; }
        .plink { display:inline-block; margin:0 6px; padding:8px 18px; border-radius:999px; border:1px solid #eee; background:#fafafd; color:#111; text-decoration:none; font-size:1rem; transition:background .18s; font-weight:500; }
        .plink:hover { background:#f3f3f9; }

        /* Episode-cover på single (fuld bredde) */
        .episode-hero-wrap { display:flex; justify-content:flex-start; margin:14px 0 12px; }
        .episode-hero {
            width:100%;
            aspect-ratio:1 / 1;
            object-fit:cover;
            border-radius:20px;
            box-shadow:0 10px 40px rgba(0,0,0,0.10);
            border:1px solid rgba(0,0,0,0.06);
            background:#fff;
            display:block;
        }

        h1 { font-size:2.3rem; margin-bottom:6px; font-weight:600; letter-spacing:-1px; text-align:center; }
        .desc { color:#555; font-size:1.1rem; margin-bottom:32px; text-align:center; }

        /* Episode-listen: hele kortet klikbart */
        .episode { margin:0; padding:0; border-bottom:1px solid #eee; }
        .episode:last-child { border-bottom:none; }
        .episode-card { display:block; padding:18px 14px; text-decoration:none; color:inherit; transition: transform .15s ease, box-shadow .15s ease, background .15s ease; border-radius:12px; margin:6px -6px; }
        .episode-card:hover { background:#fafbff; box-shadow:0 6px 24px rgba(30,60,150,0.07); transform: translateY(-1px); }
        .episode-title { font-size:1.18rem; font-weight:600; margin:0 0 6px; }
        .episode-date { color:#888; font-size:.96rem; margin-bottom:6px; }
        .teaser { margin-top:2px; color:#333; font-size:1.0rem; }

        /* Single */
        .single h1 { text-align:left; font-size:1.9rem; }
        .back { display:inline-block; margin-bottom:14px; text-decoration:none; color:#2546af; }
        .back:hover { text-decoration:underline; }

        /* Brødkrummesti */
        .crumbs { font-size:.92rem; color:#888; margin-bottom:12px; }
        .crumbs a { color:#2546af; text-decoration:none; }
        .crumbs a:hover { text-decoration:underline; }
        .crumbs span { margin:0 2px; }

        /* Relaterede episoder */
        .related { margin-top:34px; }
        .related h2 { font-size:1.15rem; font-weight:600; margin:0 0 12px; }
        .related-item { display:flex; align-items:baseline; gap:10px; padding:10px 12px; margin:6px 0; border:1px solid #eee; border-radius:12px; text-decoration:none; color:inherit; transition:background .15s ease, border-color .15s ease; }
        .related-item:hover { background:#fafbff; border-color:#d4dcff; }
        .related-no { flex:0 0 auto; font-weight:600; color:#2546af; font-size:.92rem; }
        .related-title { color:#222; font-size:1rem; }
        .single .meta { color:#666; margin-bottom:6px; }
        .single .content { margin-top:12px; color:#333; font-size:1.03rem; line-height:1.55; }

        /* Del-link (kort URL) */
        .share-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:8px 0 4px; color:#666; font-size:.95rem; }
        .share-url { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; background:#f4f6ff; border:1px solid #d3e2ff; border-radius:8px; padding:4px 10px; color:#2546af; }
        .copy-btn { cursor:pointer; border:1px solid #d3e2ff; background:#fff; color:#2546af; border-radius:8px; padding:4px 10px; font-size:.9rem; font-weight:600; transition:background .15s ease; }
        .copy-btn:hover { background:#eef2ff; }

        /* Player + platform links på én linje */
        .player-row{
          display:flex;
          align-items:center;
          gap:12px;
          margin:12px 0 12px;
          padding:10px 12px;
          border:1px solid #e5e7eb;
          background:#fafbff;
          border-radius:16px;
          box-shadow:0 1px 10px rgba(30,60,150,0.05);
        }
        .player-row audio{
          flex:1;
          width:100%;
          height:34px;
        }
        .platform-links{
          display:flex;
          gap:8px;
          flex-wrap:nowrap;
        }
        .pill{
          display:inline-flex;
          align-items:center;
          gap:8px;
          padding:8px 12px;
          border-radius:999px;
          border:1px solid #e5e7eb;
          background:#fff;
          color:#111;
          text-decoration:none;
          font-weight:600;
          font-size:.95rem;
          line-height:1;
          transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
          box-shadow:0 1px 6px rgba(0,0,0,0.04);
        }
        .pill:hover{
          background:#f3f6ff;
          transform: translateY(-1px);
        }
        .pill .dot{
          width:9px;
          height:9px;
          border-radius:99px;
          background:#2546af;
          display:inline-block;
          box-shadow:0 0 0 3px rgba(37,70,175,0.10);
        }

        /* Nav-knapper 50/50 */
        .nav-ep { display:flex; gap:10px; margin-top:22px; }
        .nav-btn { flex:1; display:block; text-decoration:none; padding:12px 14px; border-radius:12px; border:1px solid #e5e7eb; background:#f9fafb; color:#2546af; font-weight:500; transition: background .15s ease, border-color .15s ease; }
        .nav-btn:hover { background:#eef2ff; border-color:#d4dcff; }
        .nav-btn.left { text-align:left; }
        .nav-btn.right { text-align:right; }

        /* CTA og Bio */
        .newsletter-cta { width:100%; margin:32px 0 18px; padding:14px 0 7px; border-radius:16px; background:#f4f6ff; border:1px solid #d3e2ff; box-shadow:0 1px 8px rgba(80,100,200,0.04); text-align:center; }
        .cta-title { font-size:1.17rem; font-weight:600; color:#2546af; margin-bottom:10px; }
        .cta-buttons { margin-bottom:7px; }
        .cta-btn { display:inline-block; margin:0 10px 7px; padding:9px 20px; background:#2546af; color:#fff; border:none; border-radius:999px; font-size:1rem; font-weight:500; text-decoration:none; transition:background .17s; box-shadow:0 1px 5px rgba(80,100,200,0.03); }
        .cta-btn:hover { background:#112477; }
        .cta-desc { color:#325; font-size:1.01rem; margin-top:5px; opacity:.88; }

        .hosts-bio { margin:52px auto 24px; padding:22px 18px 10px; max-width:660px; border-radius:14px; background:#fafbfc; border:1px solid #eee; font-size:1.06rem; color:#444; box-shadow:0 1px 8px rgba(20,30,60,0.04); display:flex; flex-wrap:wrap; gap:30px; justify-content:space-between; }
        .host { flex:1 1 260px; min-width:200px; margin-bottom:8px; }
        .host-name { font-weight:600; font-size:1.12rem; margin-bottom:3px; color:#222; }
        .host-desc a { color:#2a77ff; text-decoration:none; border-bottom:1px dotted #b0d1ff; transition:border-color .2s; }
        .host-desc a:hover { border-bottom:1px solid #2a77ff; }

        /* 404 side */
        .http404 { text-align:center; padding:8px 0 2px; }
        .http404 h1 { font-size:2.1rem; }
        .oops { font-size:4.2rem; line-height:1; margin:10px 0 0; letter-spacing:-2px; }
        .joke { color:#444; font-size:1.08rem; margin:10px auto 18px; max-width:540px; }
        .home-btn { display:inline-block; margin-top:6px; padding:10px 18px; border-radius:12px; border:1px solid #e5e7eb; background:#f9fafb; text-decoration:none; color:#2546af; font-weight:500; }
        .home-btn:hover { background:#eef2ff; border-color:#d4dcff; }
        .suggest { margin-top:20px; color:#666; }
        .suggest-list a { display:inline-block; margin:6px 6px 0 0; padding:6px 10px; border-radius:999px; border:1px solid #eee; text-decoration:none; color:#333; }
        .suggest-list a:hover { background:#fafbff; }

        @media (max-width:700px){
            .container { padding:12px; }
            .podcast-cover { width:110px; height:110px; }
            h1 { font-size:1.5rem; }
            .hosts-bio { flex-direction:column; gap:18px; padding:14px 6px 7px; }
            .newsletter-cta { padding:7px 0 4px; }
            .cta-title { font-size:1.04rem; }
            .cta-btn { padding:7px 12px; font-size:.98rem; }

            .player-row{ flex-direction:column; align-items:stretch; }
            .platform-links{ flex-wrap:wrap; justify-content:flex-start; }

            .episode-hero{ height:240px; }
        }
    </style>

<!-- OctoReports Tracking -->
<script>!function(k,u){function E(s){return encodeURIComponent(s||"")}var q=new URLSearchParams(location.search),G=n=>q.get(n)||"",D=/iPad|Tablet|Android(?!.*Mobile)/i.test(navigator.userAgent)?2:/Mobi|Android.+Mobile|iPhone/i.test(navigator.userAgent)?1:3,N=(performance.getEntriesByType&&performance.getEntriesByType("navigation")[0])||{},P=location.pathname+location.search;P.length>1800&&(P=P.slice(0,1790)+"…");var Q="?k="+E(k)+"&h="+E(location.hostname)+"&p="+E(P)+"&r="+E(document.referrer||"")+"&pt="+E((document.title||"").slice(0,300))+"&us="+E(G("utm_source"))+"&um="+E(G("utm_medium"))+"&uc="+E(G("utm_campaign"))+"&ut="+E(G("utm_term"))+"&uu="+E(G("utm_content"))+"&dv="+D+"&tf="+(N.responseStart&&N.requestStart?Math.round(N.responseStart-N.requestStart):"")+"&dc="+(N.domContentLoadedEventEnd?Math.round(N.domContentLoadedEventEnd-(N.startTime||0)):"")+"&ld="+(N.loadEventEnd?Math.round(N.loadEventEnd-(N.startTime||0)):"")+"&t="+Date.now();(new Image).src=u+Q}("26f4922196b86eddde8fc78b553fd927457448a8","https://track.octoreports.com/track.php");</script>

</head>
<body>
<div class="container <?= ($is_single ? 'single' : '') ?>">

    <?php if (!$is_single && !$is_404): ?>
        <?php if (!empty($cover_image)): ?>
            <img src="<?= htmlspecialchars($cover_image) ?>" class="podcast-cover" width="160" height="160" decoding="async" alt="SaaS Købmænd podcast cover">
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($is_single && !$is_404): ?>
        <nav class="crumbs" aria-label="Brødkrummesti">
            <a href="/">Forside</a>
            <span aria-hidden="true">›</span>
            <span>Episode <?= (int)$single['ep_no'] ?></span>
        </nav>
        <h1><?= htmlspecialchars($single['title']) ?></h1>
        <div class="meta">
            <?= htmlspecialchars($single['date_human']) ?> · Episode <?= (int)$single['ep_no'] ?>
            <?php if (!empty($single['duration'])): ?> · ⏱ <?= htmlspecialchars($single['duration']) ?><?php endif; ?>
        </div>

        <?php $share_link = rtrim($short_url, '/') . '/e/' . (int)$single['ep_no']; ?>
        <div class="share-row">
            <span>Del:</span>
            <span class="share-url" id="share-url"><?= htmlspecialchars(preg_replace('#^https?://#', '', $share_link)) ?></span>
            <button type="button" class="copy-btn" data-link="<?= htmlspecialchars($share_link) ?>"
                    onclick="copyShareLink(this)">Kopiér link</button>
        </div>

        <div class="player-row">
          <?php if (!empty($single['audio_url'])): ?>
              <audio controls preload="none">
                  <source src="<?= htmlspecialchars($single['audio_url']) ?>" type="audio/mpeg">
                  Din browser understøtter ikke afspilning.
              </audio>
          <?php else: ?>
              <div style="flex:1;color:#666;">Ingen audio fundet i feedet.</div>
          <?php endif; ?>

          <div class="platform-links" aria-label="Lyt på platforme">
            <?php foreach ($platforms as $name => $url): ?>
            <a class="pill" href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener">
              <span class="dot" aria-hidden="true"></span> <?= htmlspecialchars(strtok($name, ' ')) ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php $hero = !empty($single['image']) ? $single['image'] : $cover_image; ?>
        <?php if (!empty($hero)): ?>
          <div class="episode-hero-wrap">
            <img src="<?= htmlspecialchars($hero) ?>" class="episode-hero" loading="lazy" decoding="async" width="600" height="600" alt="Cover for episode <?= (int)$single['ep_no'] ?>: <?= htmlspecialchars($single['title']) ?>">
          </div>
        <?php endif; ?>

        <div class="content">
            <?php
              $has_html = $single['content'] !== '' && $single['content'] !== strip_tags($single['content']);
              if ($has_html) {
                  echo sanitize_episode_html($single['content']);
              } else {
                  // Ren tekst: afkod evt. entiteter først, så de ikke dobbelt-encodes
                  $plain = html_entity_decode(trim($single['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                  echo nl2br(htmlspecialchars($plain));
              }
            ?>
        </div>

        <div class="nav-ep">
            <?php if ($prev_link): ?>
              <a class="nav-btn left" href="<?= $prev_link ?>"><?= htmlspecialchars($prev_label) ?></a>
            <?php else: ?>
              <span class="nav-btn left" style="opacity:.5; pointer-events:none;">Ingen tidligere</span>
            <?php endif; ?>
            <?php if ($next_link): ?>
              <a class="nav-btn right" href="<?= $next_link ?>"><?= htmlspecialchars($next_label) ?></a>
            <?php else: ?>
              <span class="nav-btn right" style="opacity:.5; pointer-events:none; text-align:right;">Ingen næste</span>
            <?php endif; ?>
        </div>

        <?php
          // Relaterede episoder: de nyeste andre episoder (god intern linkbuilding)
          $related = [];
          foreach ($episodes as $rep) {
              if ((int)$rep['ep_no'] === (int)$single['ep_no']) continue;
              $related[] = $rep;
              if (count($related) >= 4) break;
          }
        ?>
        <?php if ($related): ?>
        <section class="related" aria-label="Flere episoder">
            <h2>Flere episoder</h2>
            <?php foreach ($related as $rep): ?>
            <a class="related-item" href="<?= '/episode/' . htmlspecialchars($rep['slug']) ?>">
                <span class="related-no">Ep <?= (int)$rep['ep_no'] ?></span>
                <span class="related-title"><?= htmlspecialchars($rep['title']) ?></span>
            </a>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <!-- CTA -->
        <div class="newsletter-cta">
          <div class="cta-title">Tilmeld dig værternes nyhedsbreve om SaaS & forretning</div>
          <div class="cta-buttons">
            <a class="cta-btn" href="https://anderseiler.com" target="_blank" rel="noopener">Anders' nyhedsbrev</a>
            <a class="cta-btn" href="https://confirmsubscription.com/h/t/6839F4FAFC2AB8F0" target="_blank" rel="noopener">Bo's nyhedsbrev</a>
          </div>
          <div class="cta-desc">Begge nyhedsbreve handler om SaaS, iværksætteri og forretning – direkte fra værterne bag podcasten.</div>
        </div>

        <!-- Bio -->
        <div class="hosts-bio">
          <div class="host">
            <div class="host-name">Anders Eiler</div>
            <div class="host-desc">Anders Eiler er SaaS-iværksætter og podcastvært. Han står bag <a href="https://herodesk.io" target="_blank" rel="noopener">Herodesk</a> og har sin egen side på <a href="https://anderseiler.com" target="_blank" rel="noopener">anderseiler.com</a>.<br/><br/><br/><a href="https://app.pingpuffin.com/status/index.php?s=8vvWAEH3Uv">Driftsinformation</a>.</div>
          </div>
          <div class="host">
            <div class="host-name">Bo Møller</div>
            <div class="host-desc">Bo Møller er serieiværksætter med fokus på SaaS. Han driver <a href="https://alunta.com" target="_blank">Alunta</a>, <a href="https://idguard.dk" target="_blank">idguard.dk</a>, <a href="https://anyhoa.com" target="_blank" rel="noopener">AnyHOA</a>, <a href="https://resos.com" target="_blank" rel="noopener">resOS</a>, <a href="https://pingpuffin.com" target="_blank" rel="noopener">PingPuffin</a>, <a href="https://octoreports.com" target="_blank" rel="noopener">Octoreports</a>, <a href="https://morningscore.io" target="_blank" rel="noopener">Morningscore</a> og <a href="https://boligforeningsweb.dk" target="_blank" rel="noopener">Boligforeningsweb</a>. Læs mere på <a href="https://bandeja.org" target="_blank" rel="noopener">bandeja.org</a>.</div>
          </div>
        </div>

    <?php elseif ($is_404): ?>
        <div class="http404">
            <div class="oops">404</div>
            <h1>Siden findes ikke</h1>
            <p class="joke">
                Det ligner en klassisk SaaS-fejl: <em>Feature not found</em> 🙈<br>
                Maybe it’s on the <strong>Enterprise plan</strong>?
            </p>
            <a class="home-btn" href="/">← Til forsiden</a>
            <div class="suggest">Eller prøv en af de nyeste episoder:</div>
            <div class="suggest-list">
                <?php
                $max = min(5, count($episodes));
                for ($i = 0; $i < $max; $i++):
                    $ep = $episodes[$i];
                ?>
                    <a href="<?= '/episode/' . htmlspecialchars($ep['slug']) ?>">Ep <?= (int)$ep['ep_no'] ?>: <?= htmlspecialchars(mb_substr($ep['title'], 0, 40, 'UTF-8')) ?><?= (mb_strlen($ep['title'],'UTF-8')>40 ? '…' : '') ?></a>
                <?php endfor; ?>
            </div>
        </div>

    <?php else: ?>
        <h1>SaaS Købmænd Podcast</h1>
        <div class="desc">Alle episoder fra SaaS Købmænd. Udkommer (næsten) hver mandag.</div>

        <!-- ✅ Forside-links tilbage til præcis original markup (ingen inline style) -->
        <div class="links">
            <?php foreach ($platforms as $name => $url): ?>
            <a class="plink" href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($name) ?></a>
            <?php endforeach; ?>
        </div>

        <?php foreach ($episodes as $ep): ?>
        <div class="episode">
            <a class="episode-card" href="<?= '/episode/' . htmlspecialchars($ep['slug']) ?>">
                <h2 class="episode-title"><?= htmlspecialchars($ep['title']) ?></h2>
                <div class="episode-date">
                    Episode <?= (int)$ep['ep_no'] ?> · <?= htmlspecialchars($ep['date_human']) ?>
                    <?php if (!empty($ep['duration'])): ?> · ⏱ <?= htmlspecialchars($ep['duration']) ?><?php endif; ?>
                </div>
                <div class="teaser">
                    <?= htmlspecialchars(teaser($ep['content'], 110)) ?>
                </div>
            </a>
        </div>
        <?php endforeach; ?>

        <!-- CTA -->
        <div class="newsletter-cta">
          <div class="cta-title">Tilmeld dig værternes nyhedsbreve om SaaS & forretning</div>
          <div class="cta-buttons">
            <a class="cta-btn" href="https://anderseiler.com" target="_blank" rel="noopener">Anders' nyhedsbrev</a>
            <a class="cta-btn" href="https://confirmsubscription.com/h/t/6839F4FAFC2AB8F0" target="_blank" rel="noopener">Bo's nyhedsbrev</a>
          </div>
          <div class="cta-desc">Begge nyhedsbreve handler om SaaS, iværksætteri og forretning – direkte fra værterne bag podcasten.</div>
        </div>

        <!-- Bio -->
        <div class="hosts-bio">
          <div class="host">
            <div class="host-name">Anders Eiler</div>
            <div class="host-desc">Anders Eiler er SaaS-iværksætter og podcastvært. Han står bag <a href="https://herodesk.io" target="_blank" rel="noopener">Herodesk</a> og har sin egen side på <a href="https://anderseiler.com" target="_blank" rel="noopener">anderseiler.com</a>.<br/><br/><br/><a href="https://app.pingpuffin.com/status/index.php?s=8vvWAEH3Uv">Driftsinformation</a>.</div>
          </div>
          <div class="host">
            <div class="host-name">Bo Møller</div>
            <div class="host-desc">Bo Møller er serieiværksætter med fokus på SaaS. Han driver <a href="https://alunta.com" target="_blank">Alunta</a>, <a href="https://idguard.dk" target="_blank">idguard.dk</a>, <a href="https://anyhoa.com" target="_blank" rel="noopener">AnyHOA</a>, <a href="https://resos.com" target="_blank" rel="noopener">resOS</a>, <a href="https://pingpuffin.com" target="_blank" rel="noopener">PingPuffin</a>, <a href="https://octoreports.com" target="_blank" rel="noopener">Octoreports</a>, <a href="https://morningscore.io" target="_blank" rel="noopener">Morningscore</a> og <a href="https://boligforeningsweb.dk" target="_blank" rel="noopener">Boligforeningsweb</a>. Læs mere på <a href="https://bandeja.org" target="_blank" rel="noopener">bandeja.org</a>.</div>
          </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Kopiér del-link. Bruger Clipboard API i secure context (HTTPS), ellers
// en execCommand-fallback, så knappen også virker uden HTTPS.
function copyShareLink(btn){
  var link = btn.getAttribute('data-link');
  function done(){
    var t = btn.textContent;
    btn.textContent = 'Kopieret ✓';
    setTimeout(function(){ btn.textContent = t; }, 1500);
  }
  function fallback(){
    var ta = document.createElement('textarea');
    ta.value = link; ta.setAttribute('readonly','');
    ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch(e){}
    document.body.removeChild(ta);
    if (ok) done();
  }
  if (navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(link).then(done, fallback);
  } else {
    fallback();
  }
}
</script>
</body>
</html>
