<?php
// SPDX-License-Identifier: MIT
// Exercise the real pinned feed converter; only the FlareSolverr transport is a fixture.
if ($argc !== 3 || !in_array($argv[2], array('feed', 'extract', 'inputhtml', 'error', 'error-json'), true)) {
    fwrite(STDERR, "Usage: php test-feed.php /path/to/fivefilters feed|extract|inputhtml|error|error-json\n");
    exit(1);
}
$root = rtrim($argv[1], '/');
$scenario = $argv[2];
$temp = sys_get_temp_dir().'/ftr-feed-test-'.uniqid();
function copy_fixture_tree($from, $to)
{
    if (!is_dir($to)) mkdir($to, 0777, true);
    foreach (scandir($from) as $name) {
        if ($name === '.' || $name === '..') continue;
        if (is_dir($from.'/'.$name)) copy_fixture_tree($from.'/'.$name, $to.'/'.$name);
        else copy($from.'/'.$name, $to.'/'.$name);
    }
}
function remove_fixture_tree($path)
{
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue;
        if (is_dir($path.'/'.$name)) remove_fixture_tree($path.'/'.$name);
        else unlink($path.'/'.$name);
    }
    rmdir($path);
}
mkdir($temp, 0777, true);
foreach (array('config.php', 'makefulltextfeed.php', 'makefulltextfeed-core.php', 'extract.php') as $name) copy($root.'/'.$name, $temp.'/'.$name);
copy_fixture_tree($root.'/libraries', $temp.'/libraries');
mkdir($temp.'/site_config/custom', 0777, true);
mkdir($temp.'/site_config/standard', 0777, true);
file_put_contents($temp.'/site_config/custom/example.org.txt', "title: //h1\nbody: //article\nautodetect_on_failure: no\n");
file_put_contents($temp.'/custom_config.php', <<<'PHP'
<?php
$options->caching = false;
$options->apc = false;
$options->rewrite_url = array();
$options->xss_filter = 'user';
PHP
);

$feedUrl = 'https://example.org/feed';
$articleUrls = array('https://example.org/one?a=1&b=2', 'https://example.org/two');
$articles = array(
    '<html lang="zh"><head><title>Article one</title></head><body><article><h1>Article one</h1><p>Full article one contains 中文 and café, beyond the feed summary.</p></article></body></html>',
    '<html lang="en"><head><title>Article two</title></head><body><article><h1>Article two</h1><p>Full article two contains the complete extracted text beyond its feed summary.</p></article></body></html>'
);
$rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>中国 A &amp; B</title><link>https://example.org/</link><description>Fixture feed</description>';
foreach ($articleUrls as $i => $articleUrl) {
    $rss .= '<item><title>Feed title '.($i + 1).'</title><link>'.htmlspecialchars($articleUrl, ENT_QUOTES, 'UTF-8').'</link><guid isPermaLink="false">item-'.($i + 1).'</guid><description>Short summary</description></item>';
}
$rss .= '</channel></rss>';
$wrapped = '<html><head><meta name="color-scheme" content="light dark"></head><body><pre>'.htmlspecialchars($rss, ENT_QUOTES, 'UTF-8').'</pre></body></html>';
$GLOBALS['ftr_fixture_bodies'] = array($feedUrl => $wrapped, $articleUrls[0] => $articles[0], $articleUrls[1] => $articles[1]);
$GLOBALS['ftr_fixture_requests'] = array();
$GLOBALS['ftr_fixture_error'] = $scenario === 'error' || $scenario === 'error-json';
$agentPath = $temp.'/libraries/humble-http-agent/FlareSolverrHttpAgent.php';
$agent = file_get_contents($agentPath);
$agent = str_replace('class FlareSolverrHttpAgent extends HumbleHttpAgent', 'class FixtureBaseAgent extends HumbleHttpAgent', $agent, $count);
if ($count !== 1) throw new RuntimeException('Could not install the transport fixture');
$agent .= <<<'PHP'

class FlareSolverrHttpAgent extends FixtureBaseAgent
{
    protected function postJson($payload)
    {
        $request = json_decode($payload, true);
        $GLOBALS['ftr_fixture_requests'][] = $request['url'];
        if ($GLOBALS['ftr_fixture_error']) return '{"status":"error","message":"Fixture challenge failed"}';
        if (!isset($GLOBALS['ftr_fixture_bodies'][$request['url']])) throw new RuntimeException('Unexpected fixture URL: '.$request['url']);
        return json_encode(array('status' => 'ok', 'solution' => array(
            'status' => 200, 'url' => $request['url'], 'response' => $GLOBALS['ftr_fixture_bodies'][$request['url']]
        )));
    }
}
PHP;
file_put_contents($agentPath, $agent);

// Legacy upstream notices on newer validation runtimes do not belong in RSS.
set_error_handler(function($level) { return $level === E_DEPRECATED || $level === E_USER_DEPRECATED; });
putenv('FTR_FLARESOLVERR_URL=http://solver:8191');
putenv('FTR_FLARESOLVERR_TIMEOUT=60000');
$_SERVER['HTTP_HOST'] = 'fivefilters.test';
$_SERVER['SERVER_PORT'] = '80';
$_POST = array();
if ($scenario === 'extract' || $scenario === 'inputhtml') {
    $_GET = array('url' => $articleUrls[0]);
    $_SERVER['SCRIPT_NAME'] = '/extract.php';
    if ($scenario === 'inputhtml') {
        putenv('FTR_FLARESOLVERR_URL=');
        $_GET['flaresolverr'] = '1';
        $_POST['inputhtml'] = $articles[0];
    }
    $entry = 'extract.php';
} else {
    // The form rewrites https:// as sec:// and sends hidden 0 before checked 1.
    parse_str('url='.urlencode('sec://example.org/feed').'&max=2&flaresolverr=0&flaresolverr=1', $_GET);
    if ($scenario === 'error-json') $_GET['format'] = 'json';
    $_SERVER['SCRIPT_NAME'] = '/makefulltextfeed.php';
    $entry = 'makefulltextfeed.php';
}
$checks = 0;
function fixture_check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    $checks++;
}
ob_start();
register_shutdown_function(function() use ($scenario, $temp, $articleUrls, $root) {
    $output = ob_get_clean();
    $status = http_response_code() ? http_response_code() : 200;
    chdir($root);
    try {
        if ($scenario === 'error' || $scenario === 'error-json') {
            fixture_check($status === 502, 'solver failure returns HTTP 502');
            fixture_check(strpos($output, 'Fixture challenge failed') !== false, 'solver failure is explained');
            if ($scenario === 'error-json') fixture_check(isset(json_decode($output, true)['error']), 'JSON mode returns a JSON error');
            else fixture_check(strpos($output, 'Feed conversion failed:') === 0, 'RSS mode returns an error instead of a successful feed');
        } elseif ($scenario === 'feed') {
            $doc = new DOMDocument();
            fixture_check($status === 200 && $doc->loadXML($output), 'converted feed is valid RSS');
            fixture_check($doc->getElementsByTagName('item')->length === 2, 'max items is preserved');
            fixture_check(strpos($output, 'Full article one') !== false && strpos($output, 'Full article two') !== false, 'both article pages are extracted');
            fixture_check(strpos($output, '中文') !== false && strpos($output, 'café') !== false, 'UTF-8 article text survives conversion');
            // The pinned converter passes SimplePie's escaped RSS title to its
            // feed writer unchanged. Browser fetching preserves that behavior.
            fixture_check($doc->getElementsByTagName('title')->item(0)->textContent === '中国 A &amp; B', 'feed title preserves UTF-8 and the native converter behavior');
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
            $self = $xpath->query('//atom:link[@rel="self"]')->item(0);
            fixture_check($self && strpos($self->getAttribute('href'), 'flaresolverr=1') !== false, 'subscription self URL retains browser fetching');
            fixture_check($GLOBALS['ftr_fixture_requests'] === array('https://example.org/feed', $articleUrls[0], $articleUrls[1]), 'feed and both articles use FlareSolverr');
        } else {
            $json = json_decode($output, true);
            fixture_check($status === 200 && is_array($json), 'extraction still returns JSON');
            fixture_check(isset($json['content']) && strpos($json['content'], 'Full article one') !== false, 'extraction still returns full article content');
            fixture_check(count($GLOBALS['ftr_fixture_requests']) === ($scenario === 'inputhtml' ? 0 : 1), 'existing extraction and supplied HTML use the expected downloader');
        }
        remove_fixture_tree($temp);
        echo 'Passed '.$GLOBALS['checks'].' '.$scenario." conversion checks.\n";
        // The real error entry point exits with 1. Keep that expected status,
        // and reserve 2 for a failed assertion so the build can distinguish them.
        exit($scenario === 'error' || $scenario === 'error-json' ? 1 : 0);
    } catch (Exception $e) {
        remove_fixture_tree($temp);
        fwrite(STDERR, $e->getMessage()."\nResponse: ".substr($output, 0, 1200)."\n");
        exit(2);
    }
});
chdir($temp);
require $temp.'/'.$entry;
