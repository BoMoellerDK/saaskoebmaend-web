<?php
$base_url = getenv('SAASKOBMAEND_TEST_BASE_URL') ?: 'http://127.0.0.1:8878';
$runtime_file = getenv('SAASKOBMAEND_YOUTUBE_RUNTIME_FILE');
$expected_video_id = getenv('SAASKOBMAEND_EXPECTED_VIDEO_ID') ?: 'AbCdEfGhI_1';
$youtube_source = getenv('SAASKOBMAEND_EXPECTED_YOUTUBE_SOURCE') ?: 'Atom-feedet';
// Fixture-mappen har kun maxres for AbCdEfGhI_1, så fallback-kørslen tester,
// at en manglende højere opløsning bevarer hqdefault.
$expected_thumbnail = getenv('SAASKOBMAEND_EXPECTED_THUMBNAIL') ?: 'maxresdefault';
$expected_thumbnail_size = $expected_thumbnail === 'maxresdefault' ? ['1280', '720'] : ['480', '360'];
$expected_views_raw = getenv('SAASKOBMAEND_EXPECTED_YOUTUBE_VIEWS');
$expected_views = $expected_views_raw === false ? null : (int)$expected_views_raw;
$checks = 0;
$failures = [];

function synthetic_check($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $message;
}

function synthetic_request($path) {
    global $base_url;
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $body = @file_get_contents($base_url . $path, false, $context);
    $headers = isset($http_response_header) ? $http_response_header : [];
    $status = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $match)) $status = (int)$match[1];
    return ['status' => $status, 'body' => $body === false ? '' : $body];
}

function synthetic_meta($html, $property) {
    if ($html === '') return '';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//meta[@property="' . $property . '"]/@content');
    return $nodes->length ? $nodes->item(0)->nodeValue : '';
}

$home = synthetic_request('/');
synthetic_check($home['status'] === 200, 'Den syntetiske forside svarer ikke 200');
synthetic_check(substr_count($home['body'], 'class="episode-card-v2"') === 2, 'Den syntetiske RSS-fixture giver ikke to episoder');
synthetic_check(strpos($home['body'], 'data-video-id="' . $expected_video_id . '"') !== false, 'Episode 73 blev ikke matchet med ' . $youtube_source);

$episode_73_path = '';
if (preg_match('#href="(/episode/73-[^"]+)"#', $home['body'], $match)) $episode_73_path = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
synthetic_check($episode_73_path !== '', 'Episode 73 mangler en episodeside');

$episode_73 = synthetic_request($episode_73_path);
synthetic_check($episode_73['status'] === 200, 'Episode 73 svarer ikke 200');
synthetic_check(synthetic_meta($episode_73['body'], 'og:title') === 'Episode 73: Automatiseret testepisode om video – SaaS Købmænd', 'Episode 73 har forkert OG-titel');
synthetic_check(synthetic_meta($episode_73['body'], 'og:image') === 'https://i.ytimg.com/vi/' . $expected_video_id . '/' . $expected_thumbnail . '.jpg', 'Episode 73 bruger ikke ' . $expected_thumbnail . '-thumbnailen fra ' . $youtube_source);
synthetic_check(synthetic_meta($episode_73['body'], 'og:image:width') === $expected_thumbnail_size[0] && synthetic_meta($episode_73['body'], 'og:image:height') === $expected_thumbnail_size[1], 'Episode 73 har forkerte OG-dimensioner');
synthetic_check(strpos($episode_73['body'], 'VideoObject') !== false, 'Episode 73 mangler VideoObject-schema');

$episode_72_path = '';
if (preg_match('#href="(/episode/72-[^"]+)"#', $home['body'], $match)) $episode_72_path = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
synthetic_check($episode_72_path !== '', 'Den kuraterede episode 72 mangler en episodeside');
$episode_72 = synthetic_request($episode_72_path);
synthetic_check($episode_72['status'] === 200, 'Den kuraterede episode 72 svarer ikke 200');
synthetic_check(synthetic_meta($episode_72['body'], 'og:image') === 'https://i.ytimg.com/vi/t0M0lAR4WcY/maxresdefault.jpg', 'Atom-feedet overskriver seed-thumbnailen for episode 72');
synthetic_check(synthetic_meta($episode_72['body'], 'og:image:width') === '1280' && synthetic_meta($episode_72['body'], 'og:image:height') === '720', 'Episode 72 mistede seedets maxres-dimensioner');

synthetic_check($runtime_file && is_file($runtime_file), 'Det syntetiske runtime-katalog blev ikke gemt');
$runtime_before = $runtime_file && is_file($runtime_file) ? file_get_contents($runtime_file) : '';
$runtime_payload = json_decode($runtime_before, true);
$runtime_episodes = is_array($runtime_payload) && isset($runtime_payload['episodes']) ? $runtime_payload['episodes'] : [];
synthetic_check(array_keys($runtime_episodes) === [73], 'Runtime-kataloget indeholder andet end den nye episode 73');
synthetic_check(($runtime_episodes[73]['id'] ?? '') === $expected_video_id, 'Runtime-kataloget gemte forkert video for episode 73');
synthetic_check(($runtime_episodes[73]['thumbnail'] ?? '') === 'https://i.ytimg.com/vi/' . $expected_video_id . '/' . $expected_thumbnail . '.jpg', 'Runtime-kataloget gemte forkert thumbnail for episode 73');
if ($expected_views !== null) synthetic_check(($runtime_episodes[73]['views'] ?? 0) === $expected_views, 'Runtime-kataloget gemte forkert visningstal fra ' . $youtube_source);

usleep(1100000);
synthetic_request('/');
$runtime_after = $runtime_file && is_file($runtime_file) ? file_get_contents($runtime_file) : '';
synthetic_check($runtime_before === $runtime_after, 'Det syntetiske runtime-katalog omskrives uden ændringer');
if ($runtime_file && is_file($runtime_file)) @unlink($runtime_file);

echo json_encode([
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failures ? 1 : 0);
