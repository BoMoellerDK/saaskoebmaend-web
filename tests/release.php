<?php
const BASE_URL = 'http://127.0.0.1:8877';

$checks = 0;
$failures = [];
$snapshot = @simplexml_load_file(dirname(__DIR__) . '/data/podcast-rss-fallback.xml');
$expected_episode_count = $snapshot ? count($snapshot->channel->item) : 0;

function check($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $message;
}

function request_path($path) {
    $context = stream_context_create(['http' => [
        'ignore_errors' => true,
        'timeout' => 10,
        'follow_location' => 0,
    ]]);
    $body = @file_get_contents(BASE_URL . $path, false, $context);
    $headers = isset($http_response_header) ? $http_response_header : [];
    $status = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $match)) $status = (int)$match[1];
    return ['status' => $status, 'headers' => $headers, 'body' => $body === false ? '' : $body];
}

function meta_property($html, $property) {
    if ($html === '') return '';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//meta[@property="' . $property . '"]/@content');
    return $nodes->length ? $nodes->item(0)->nodeValue : '';
}

$runtime_file = getenv('SAASKOBMAEND_YOUTUBE_RUNTIME_FILE');
check((bool)$runtime_file, 'Testserveren mangler en isoleret runtime-fil');
if ($runtime_file) {
    file_put_contents($runtime_file, json_encode([
        'channel_id' => 'UCEPkljgNMsbW3lNYZ-j6VHw',
        'updated_at' => '2000-01-01T00:00:00+00:00',
        'episodes' => [
            '10' => [
                'id' => 'TAlehw-gM-s',
                'title' => 'Forkert runtime-værdi',
                'thumbnail' => 'https://i.ytimg.com/vi/TAlehw-gM-s/maxresdefault.jpg',
                'views' => 0,
                'match' => 'runtime-test',
            ],
        ],
    ], JSON_PRETTY_PRINT));
}

$home = request_path('/');
check($home['status'] === 200, 'Forsiden svarer ikke 200');
check($expected_episode_count >= 72, 'RSS-snapshot indeholder færre end 72 episoder');
check(substr_count($home['body'], 'class="episode-card-v2"') === $expected_episode_count, 'Forsiden indeholder ikke alle RSS-episoder');
check(strpos($home['body'], 'id="episode-search"') !== false, 'Søgning mangler');
check(strpos($home['body'], '/assets/site-v2.js?v=1') !== false, 'JavaScript mangler');
check(strpos($home['body'], '</html>') !== false, 'HTML-outputtet er afkortet, sandsynligvis pga. en PHP-fatal');

$episode_72 = request_path('/episode/72-er-du-blevet-traet-af-dit');
check($episode_72['status'] === 200, 'Episode 72 svarer ikke 200');
check(meta_property($episode_72['body'], 'og:title') === 'Er du blevet træt af dit eget firma? Her er (den lovlige) løsning. – SaaS Købmænd', 'Episode 72 har ikke en fuld, meningsfuld OG-titel');
check(meta_property($episode_72['body'], 'og:image') === 'https://i.ytimg.com/vi/t0M0lAR4WcY/maxresdefault.jpg', 'Episode 72 har forkert OG-image');
check(meta_property($episode_72['body'], 'og:image:secure_url') === meta_property($episode_72['body'], 'og:image'), 'OG-image mangler secure_url');
check(meta_property($episode_72['body'], 'og:image:width') === '1280', 'Episode 72 har forkert OG-billedbredde');
check(meta_property($episode_72['body'], 'og:image:height') === '720', 'Episode 72 har forkert OG-billedhøjde');
check(strpos($episode_72['body'], 'VideoObject') !== false, 'Episode 72 mangler VideoObject-schema');
check(strpos($episode_72['body'], 'AudioObject') !== false, 'Episode 72 mangler AudioObject-schema');
check(strpos($episode_72['body'], '</html>') !== false, 'Episodesidens HTML er afkortet');

$episode_10 = request_path('/episode/10-update-paa-firmaerne-vi-ringer');
check($episode_10['status'] === 200, 'Episode 10 svarer ikke 200');
check(meta_property($episode_10['body'], 'og:image') === 'https://i.ytimg.com/vi/TAlehw-gM-s/sddefault.jpg', 'Seed-thumbnailen vinder ikke over runtime');
check(meta_property($episode_10['body'], 'og:image:width') === '640', 'SD-thumbnailen har forkert OG-billedbredde');
check(meta_property($episode_10['body'], 'og:image:height') === '480', 'SD-thumbnailen har forkert OG-billedhøjde');
check(strpos($episode_10['body'], 'TAlehw-gM-s/maxresdefault.jpg 1280w') === false, 'Den defekte maxres-thumbnail findes stadig i srcset');

$host = request_path('/vaert/anders-eiler');
check($host['status'] === 200 && strpos($host['body'], 'Anders Eiler') !== false, 'Værtssiden virker ikke');

$short = request_path('/e/72');
check($short['status'] === 301, 'Kortlinket giver ikke 301');
check((bool)array_filter($short['headers'], function ($header) {
    return stripos($header, 'Location: https://xn--saaskbmnd-m3a9q.dk/episode/72-') === 0;
}), 'Kortlinket mangler korrekt Location-header');

$sitemap = request_path('/sitemap.xml');
check($sitemap['status'] === 200, 'Sitemap svarer ikke 200');
$sitemap_urls = [];
preg_match_all('#<loc>([^<]+)</loc>#', $sitemap['body'], $sitemap_matches);
$sitemap_urls = $sitemap_matches[1];
$episode_urls = array_values(array_filter($sitemap_urls, function ($url) {
    return strpos($url, '/episode/') !== false;
}));
check(count($episode_urls) === $expected_episode_count, 'Sitemap indeholder ikke alle episodesider');
check(count($sitemap_urls) === $expected_episode_count + 3, 'Sitemap mangler forside eller værtssider');

foreach ($episode_urls as $episode_url) {
    $path = parse_url($episode_url, PHP_URL_PATH);
    $episode = request_path($path);
    $og_title = meta_property($episode['body'], 'og:title');
    $og_image = meta_property($episode['body'], 'og:image');
    check($episode['status'] === 200, $path . ' svarer ikke 200');
    check($og_title !== '' && strpos($og_title, 'SaaS Købmænd') !== false, $path . ' mangler meningsfuld OG-titel');
    check($og_image !== '' && strpos($og_image, 'https://') === 0, $path . ' mangler absolut OG-image');
    check(meta_property($episode['body'], 'og:image:secure_url') === $og_image, $path . ' mangler korrekt secure OG-image');
    check((int)meta_property($episode['body'], 'og:image:width') >= 480, $path . ' har ugyldig OG-billedbredde');
    check((int)meta_property($episode['body'], 'og:image:height') >= 360, $path . ' har ugyldig OG-billedhøjde');
    check(strpos($episode['body'], '</html>') !== false, $path . ' har afkortet HTML');
}

if ($runtime_file && is_file($runtime_file)) {
    $after_first_request = file_get_contents($runtime_file);
    usleep(1100000);
    request_path('/');
    $after_second_request = file_get_contents($runtime_file);
    check($after_first_request === $after_second_request, 'Runtime-kataloget omskrives ved uændrede requests');
    @unlink($runtime_file);
}

echo json_encode([
    'checks' => $checks,
    'episodes' => $expected_episode_count,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failures ? 1 : 0);
