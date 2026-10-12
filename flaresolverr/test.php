<?php
// SPDX-License-Identifier: MIT
// Run against the actual pinned HTTP agent, without a browser or internet.

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php test.php /path/to/fivefilters\n");
    exit(1);
}
$root = rtrim($argv[1], '/');
require_once $root.'/libraries/humble-http-agent/HumbleHttpAgent.php';
require_once $root.'/libraries/humble-http-agent/FlareSolverrHttpAgent.php';
require_once $root.'/libraries/simplepie/autoloader.php';

class FixtureFlareSolverrAgent extends FlareSolverrHttpAgent
{
    public $payloads = array();
    public $fixture;
    public function endpoint() { return $this->flaresolverrUrl; }
    protected function postJson($payload)
    {
        $this->payloads[] = json_decode($payload, true);
        return $this->fixture;
    }
}

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    $checks++;
}
function rejected($callback, $message)
{
    try {
        $callback();
    } catch (FlareSolverrException $e) {
        check(strpos($e->getMessage(), $message) !== false, 'error explains '.$message);
        return;
    }
    throw new RuntimeException('FAIL: expected FlareSolverrException: '.$message);
}
function new_agent($fixture)
{
    $agent = new FixtureFlareSolverrAgent(null, HumbleHttpAgent::METHOD_FILE_GET_CONTENTS);
    $agent->fixture = $fixture;
    return $agent;
}

putenv('FTR_FLARESOLVERR_URL=http://solver:8191/');
putenv('FTR_FLARESOLVERR_TIMEOUT=');
$article = 'https://example.org/article';
$html = '<html><body><p>Rendered article with café.</p></body></html>';
$success = array('status' => 'ok', 'solution' => array(
    'status' => 200,
    'url' => 'https://example.org/final',
    'response' => $html,
    'headers' => array('content-encoding' => 'gzip', 'content-length' => '10', 'content-type' => 'text/html; charset=ISO-8859-1')
));
$agent = new_agent(json_encode($success));
check($agent->endpoint() === 'http://solver:8191/v1', 'base endpoint normalized');
check($agent->parallelSupport() === false, 'browser requests are sequential');
$agent->fetchAll(array($article, $article));
check(count($agent->payloads) === 1, 'duplicate URLs fetched once');
check($agent->payloads[0] === array('cmd' => 'request.get', 'url' => $article, 'maxTimeout' => 60000), 'documented request payload');
$response = $agent->get($article);
check($response['body'] === $html, 'rendered UTF-8 HTML passed through');
check($response['effective_url'] === 'https://example.org/final', 'final URL retained');
check($response['status_code'] === 200, 'status retained');
check($response['headers'] === "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=UTF-8\r\n", 'status line retained without wire compression and charset headers');
check(count($agent->payloads) === 1, 'get reuses fetched response');
$agent->get($article, true);
$agent->get($article);
check(count($agent->payloads) === 2, 'removed response can be fetched again');
$agent->get('https://example.org/page-2');
check(count($agent->payloads) === 3, 'additional pages use the same downloader');

putenv('FTR_FLARESOLVERR_URL=http://solver:8191/v1/');
putenv('FTR_FLARESOLVERR_TIMEOUT=45000');
$agent = new_agent(json_encode($success));
check($agent->endpoint() === 'http://solver:8191/v1', 'explicit /v1 not duplicated');
$agent->rewriteUrls = array('example.org' => array('/old' => '/new'));
$agent->get('https://example.org/old#section');
check($agent->payloads[0]['url'] === 'https://example.org/new', 'URL rewrites and fragment removal preserved');
check($agent->payloads[0]['maxTimeout'] === 45000, 'configured timeout used');

rejected(function() use ($success) { new_agent(json_encode($success))->get('file:///etc/passwd'); }, 'Invalid URL');
foreach (array('not a URL', 'file:///tmp', 'http://solver:8191/?x=y', 'http://solver:8191/#frag', 'http://user:pass@solver:8191/') as $badUrl) {
    putenv('FTR_FLARESOLVERR_URL='.$badUrl);
    rejected(function() use ($success) { new_agent(json_encode($success)); }, 'FTR_FLARESOLVERR_URL');
}
putenv('FTR_FLARESOLVERR_URL=http://solver:8191');
foreach (array('0', '-1', '60s', '999', '120001', '999999999999999999999999') as $badTimeout) {
    putenv('FTR_FLARESOLVERR_TIMEOUT='.$badTimeout);
    rejected(function() use ($success) { new_agent(json_encode($success)); }, 'FTR_FLARESOLVERR_TIMEOUT');
}
putenv('FTR_FLARESOLVERR_TIMEOUT=');
rejected(function() use ($article) { new_agent('not JSON')->get($article); }, 'Invalid JSON response');
rejected(function() use ($article) { new_agent('{"status":"error","message":"Challenge timed out"}')->get($article); }, 'Challenge timed out');
rejected(function() use ($article) { new_agent('{"status":"ok"}')->get($article); }, 'no solution');
foreach (array(403, 404, 503, null, 'invalid') as $badStatus) {
    $fixture = $success;
    $fixture['solution']['status'] = $badStatus;
    rejected(function() use ($fixture, $article) { new_agent(json_encode($fixture))->get($article); }, 'HTTP status');
}
$fixture = $success;
$fixture['solution']['response'] = '';
$agent = new_agent(json_encode($fixture));
rejected(function() use ($agent, $article) { $agent->get($article); }, 'empty content');
$agent->fixture = json_encode($success);
check($agent->get($article)['body'] === $html, 'failed downloads not retained in memory');
$fixture = $success;
$fixture['solution']['url'] = 'file:///etc/passwd';
rejected(function() use ($fixture, $article) { new_agent(json_encode($fixture))->get($article); }, 'invalid final URL');

// Feed conversion is opt-in; existing Readable extraction requests stay enabled.
$_REQUEST = array();
check(!FlareSolverrHttpAgent::isEnabled('full'), 'feed conversion is native by default');
check(FlareSolverrHttpAgent::isEnabled('simple'), 'existing extraction calls still use configured solver');
foreach (array('0', 0) as $value) {
    $_REQUEST = array('flaresolverr' => $value);
    check(!FlareSolverrHttpAgent::isEnabled('full') && !FlareSolverrHttpAgent::isEnabled('simple'), 'explicit zero selects native fetching');
}
foreach (array('1', 1) as $value) {
    $_REQUEST = array('flaresolverr' => $value);
    check(FlareSolverrHttpAgent::isEnabled('full') && FlareSolverrHttpAgent::isEnabled('simple'), 'explicit one selects browser fetching');
}
foreach (array('', 'yes', '2', array('1')) as $value) {
    $_REQUEST = array('flaresolverr' => $value);
    rejected(function() { FlareSolverrHttpAgent::isEnabled('full'); }, 'must be 0 or 1');
}
putenv('FTR_FLARESOLVERR_URL=');
$_REQUEST = array();
check(!FlareSolverrHttpAgent::isEnabled('simple'), 'unconfigured extraction uses native fetching');
$_REQUEST = array('flaresolverr' => '1');
rejected(function() { FlareSolverrHttpAgent::isEnabled('full'); }, 'not configured');
$_REQUEST['inputhtml'] = $html;
check(!FlareSolverrHttpAgent::isEnabled('simple'), 'supplied HTML does not need a configured browser');
putenv('FTR_FLARESOLVERR_URL=http://solver:8191');
$_REQUEST = array();

// Chromium's escaped XML view must become XML, preserving entities and CDATA.
$feedUrl = 'https://example.org/feed';
$content = '<p>中文 café &amp; literal &lt;span&gt;</p>';
$rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>中国 A &amp; B &lt;C&gt;</title><item><link>https://example.org/?a=1&amp;b=2</link><content:encoded><![CDATA['.$content.']]></content:encoded></item></channel></rss>';
$wrapped = '<html><head><meta name="color-scheme" content="light dark"></head><body><pre>'.htmlspecialchars($rss, ENT_QUOTES, 'UTF-8').'</pre></body></html>';
foreach (array($rss, $wrapped, str_replace('light dark">', 'light dark"/>', $wrapped), str_replace('encoding="UTF-8"', 'encoding="ISO-8859-1"', $rss)) as $body) {
    $fixture = $success;
    $fixture['solution']['response'] = $body;
    $response = new_agent(json_encode($fixture))->get($feedUrl);
    check($response['headers'] === "HTTP/1.1 200 OK\r\nContent-Type: application/xml; charset=UTF-8\r\n", 'feed responses have XML headers');
    $httpParser = new SimplePie_HTTP_Parser($response['headers']."\r\n\r\n");
    check($httpParser->parse() && $httpParser->status_code === 200, 'SimplePie accepts the synthetic HTTP response');
    $xml = new DOMDocument();
    check($xml->loadXML($response['body']), 'feed response is valid XML');
    check($xml->getElementsByTagName('title')->item(0)->textContent === '中国 A & B <C>', 'Chinese and XML entities preserved');
    check($xml->getElementsByTagName('link')->item(0)->textContent === 'https://example.org/?a=1&b=2', 'feed link query preserved');
    check($xml->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/content/', 'encoded')->item(0)->textContent === $content, 'article CDATA preserved exactly');
}
foreach (array('<feed xmlns="http://www.w3.org/2005/Atom"><title>Atom</title></feed>', '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/>') as $body) {
    $fixture = $success;
    $fixture['solution']['response'] = $body;
    check(new_agent(json_encode($fixture))->get($feedUrl)['headers'] === "HTTP/1.1 200 OK\r\nContent-Type: application/xml; charset=UTF-8\r\n", 'Atom and RSS 1.0 feeds supported');
}
$fixture = $success;
$fixture['solution']['response'] = '<html><body><h1>Article</h1><pre>'.htmlspecialchars($rss, ENT_QUOTES, 'UTF-8').'</pre></body></html>';
check(new_agent(json_encode($fixture))->get($article)['body'] === $fixture['solution']['response'], 'article code samples stay HTML');

// The web form places the new option with the other checkboxes.
ob_start();
require $root.'/index.php';
$form = ob_get_clean();
$doc = new DOMDocument();
@$doc->loadHTML($form);
$xpath = new DOMXPath($doc);
$checkbox = $xpath->query('//input[@id="flaresolverr"]');
check($checkbox->length === 1 && $checkbox->item(0)->getAttribute('type') === 'checkbox', 'one FlareSolverr checkbox rendered');
check($checkbox->item(0)->getAttribute('name') === 'flaresolverr' && $checkbox->item(0)->getAttribute('value') === '1', 'checked form sends browser parameter');
check(!$checkbox->item(0)->hasAttribute('checked') && !$checkbox->item(0)->hasAttribute('disabled'), 'configured checkbox starts unchecked and enabled');
check($xpath->query('//input[@type="hidden" and @name="flaresolverr" and @value="0"]')->length === 1, 'unchecked form explicitly sends native parameter');
check(strpos($form, 'for="flaresolverr"') > strpos($form, 'for="debug"'), 'checkbox follows Debug');
check($xpath->query('//form[@id="form"]')->item(0)->getAttribute('action') === 'makefulltextfeed.php', 'form generates full feeds');
putenv('FTR_FLARESOLVERR_URL=');
ob_start();
require $root.'/index.php';
$form = ob_get_clean();
@$doc->loadHTML($form);
check($doc->getElementById('flaresolverr')->hasAttribute('disabled'), 'unconfigured checkbox is disabled');

echo 'Passed '.$checks." FlareSolverr checks.\n";
