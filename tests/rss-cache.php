<?php
// Load the real application with an isolated, local feed and no YouTube calls.
$rss_test_dir = sys_get_temp_dir() . '/saaskoebmaend-rss-test-' . bin2hex(random_bytes(8));
mkdir($rss_test_dir);
putenv('SAASKOBMAEND_CACHE_NAMESPACE=' . basename($rss_test_dir));
putenv('SAASKOBMAEND_RSS_URL=file://' . $rss_test_dir . '/feed.xml');
putenv('SAASKOBMAEND_RSS_SNAPSHOT_FILE=' . $rss_test_dir . '/snapshot.xml');
putenv('SAASKOBMAEND_DISABLE_YOUTUBE=1');
copy(__DIR__ . '/fixtures/synthetic-rss.xml', $rss_test_dir . '/feed.xml');
$rss_test_cache = sys_get_temp_dir() . '/saaskobmaend_rss_' . md5(getenv('SAASKOBMAEND_RSS_URL') . '|' . getenv('SAASKOBMAEND_CACHE_NAMESPACE')) . '.xml';
register_shutdown_function(function () use ($rss_test_dir, $rss_test_cache) {
    foreach (glob($rss_test_dir . '/*') as $file) unlink($file);
    if (is_file($rss_test_cache)) unlink($rss_test_cache);
    rmdir($rss_test_dir);
});
ob_start();
require dirname(__DIR__) . '/public_html/index.php';
ob_end_clean();

$rss_test_checks = 0;
function rss_test_check($condition, $message) {
    global $rss_test_checks;
    $rss_test_checks++;
    if (!$condition) throw new RuntimeException($message);
}

// Check both seasons, the Mondays after clock changes, and server timezones.
foreach (['UTC', 'America/New_York'] as $server_zone) {
    date_default_timezone_set($server_zone);
    foreach (['2026-11-23', '2026-06-22', '2026-03-30', '2026-10-26'] as $monday) {
        foreach ([
            '06:00:00' => 900, '06:54:59' => 900, '06:55:00' => 60,
            '07:00:00' => 60, '07:14:59' => 60, '07:15:00' => 900, '08:00:00' => 900,
        ] as $clock => $expected) {
            $now = new DateTimeImmutable("$monday $clock", new DateTimeZone('Europe/Copenhagen'));
            rss_test_check(rss_cache_ttl($now->setTimezone(new DateTimeZone('UTC'))) === $expected, "Wrong TTL: $monday $clock / $server_zone");
        }
    }
    foreach (['2026-11-22', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28'] as $day) {
        rss_test_check(rss_cache_ttl(new DateTimeImmutable("$day 07:00:00", new DateTimeZone('Europe/Copenhagen'))) === 900, "Wrong weekday TTL: $day");
    }
}

$release_ttl = rss_cache_ttl(new DateTimeImmutable('2026-11-23T07:00:00+01:00'));
$normal_ttl = rss_cache_ttl(new DateTimeImmutable('2026-11-23T07:15:00+01:00'));
$feed_url = getenv('SAASKOBMAEND_RSS_URL');
$old_xml = '<rss><channel><title>Old</title></channel></rss>';
$new_xml = '<rss><channel><title>New</title></channel></rss>';
file_put_contents($rss_test_dir . '/feed.xml', $new_xml);

// A cache created before the release window must obey the shorter TTL now.
file_put_contents($rss_test_cache, $old_xml);
touch($rss_test_cache, time() - 120);
clearstatcache();
rss_test_check((string)fetch_rss_cached($feed_url, $normal_ttl)->channel->title === 'Old', 'Normal cache was discarded too early');
rss_test_check((string)fetch_rss_cached($feed_url, $release_ttl)->channel->title === 'New', 'Pre-window cache was not refreshed');

// Fresh data stays cached; expired data is fetched at both TTLs.
foreach ([$release_ttl, $normal_ttl] as $ttl) {
    file_put_contents($rss_test_cache, $old_xml);
    touch($rss_test_cache, time() - 10);
    clearstatcache();
    rss_test_check((string)fetch_rss_cached($feed_url, $ttl)->channel->title === 'Old', "Fresh cache ignored at TTL $ttl");
    touch($rss_test_cache, time() - $ttl - 1);
    clearstatcache();
    rss_test_check((string)fetch_rss_cached($feed_url, $ttl)->channel->title === 'New', "Expired cache not refreshed at TTL $ttl");
}

// Feed errors must retain stale-cache and persistent-snapshot fallbacks.
file_put_contents($rss_test_cache, $old_xml);
touch($rss_test_cache, time() - 120);
unlink($rss_test_dir . '/feed.xml');
clearstatcache();
rss_test_check((string)fetch_rss_cached($feed_url, $release_ttl)->channel->title === 'Old', 'Feed outage discarded stale cache');
file_put_contents($rss_test_dir . '/feed.xml', 'invalid XML');
rss_test_check((string)fetch_rss_cached($feed_url, $release_ttl)->channel->title === 'Old', 'Invalid feed discarded stale cache');
unlink($rss_test_cache);
clearstatcache();
rss_test_check(fetch_rss_cached($feed_url, $release_ttl, $rss_test_dir . '/snapshot.xml') !== false, 'Feed outage discarded snapshot fallback');
unlink($rss_test_cache);
clearstatcache();
rss_test_check(fetch_rss_cached($feed_url, $release_ttl) === false, 'Missing feed and fallbacks did not return false');

echo json_encode(['checks' => $rss_test_checks, 'failures' => []], JSON_PRETTY_PRINT) . "\n";
