<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Browser-backed downloader for FiveFilters' feeds and article pages.

class FlareSolverrException extends RuntimeException {}

class FlareSolverrHttpAgent extends HumbleHttpAgent
{
    protected $flaresolverrUrl;
    protected $maxTimeout;

    public static function isEnabled($mode)
    {
        // Supplied article HTML never needs another download.
        if ($mode === 'simple' && isset($_REQUEST['inputhtml'])) return false;
        $configured = trim(getenv('FTR_FLARESOLVERR_URL')) !== '';
        if (isset($_REQUEST['flaresolverr'])) {
            $value = $_REQUEST['flaresolverr'];
            if ($value === '0' || $value === 0) return false;
            if ($value !== '1' && $value !== 1) {
                throw new FlareSolverrException('The flaresolverr parameter must be 0 or 1.');
            }
            if (!$configured) {
                throw new FlareSolverrException('FlareSolverr is not configured. Set FTR_FLARESOLVERR_URL on the server.');
            }
            return true;
        }
        // Preserve the existing Readable API behavior; feed conversion is opt-in.
        return $mode === 'simple' && $configured;
    }

    public function __construct($requestOptions = null, $method = null)
    {
        parent::__construct($requestOptions, $method);
        $base = rtrim(trim(getenv('FTR_FLARESOLVERR_URL')), '/');
        $parts = parse_url($base);
        if (!$this->validateUrl($base) || !$parts ||
            isset($parts['query']) || isset($parts['fragment']) ||
            isset($parts['user']) || isset($parts['pass'])) {
            throw new FlareSolverrException('FTR_FLARESOLVERR_URL must be an HTTP(S) base URL or /v1 endpoint.');
        }
        $this->flaresolverrUrl = substr($base, -3) === '/v1' ? $base : $base.'/v1';
        $timeout = trim(getenv('FTR_FLARESOLVERR_TIMEOUT'));
        if ($timeout === '') $timeout = '60000';
        if (!ctype_digit($timeout) || (float)$timeout < 1000 || (float)$timeout > 120000) {
            throw new FlareSolverrException('FTR_FLARESOLVERR_TIMEOUT must be 1000 to 120000 milliseconds.');
        }
        $this->maxTimeout = (int)$timeout;
    }

    public function parallelSupport()
    {
        return false;
    }

    public function fetchAllOnce(array $urls, $isRedirect = false)
    {
        if (!$isRedirect) $urls = array_unique($urls);
        foreach ($urls as $original => $url) {
            if (!$isRedirect) $original = $url;
            unset($this->redirectQueue[$original]);
            if (isset($this->requests[$original])) continue;

            $requestUrl = $this->convertIdn($this->rewriteUrls($url));
            if ($this->rewriteHashbangFragment) $requestUrl = $this->rewriteHashbangFragment($requestUrl);
            $requestUrl = $this->removeFragment($requestUrl);
            if (!$this->validateUrl($requestUrl)) {
                throw new FlareSolverrException('Invalid URL for FlareSolverr.');
            }
            $this->debug('Fetching with FlareSolverr: '.$requestUrl);
            $payload = json_encode(array(
                'cmd' => 'request.get',
                'url' => $requestUrl,
                'maxTimeout' => $this->maxTimeout
            ));
            $result = json_decode($this->postJson($payload), true);
            if (!is_array($result) || !isset($result['status']) || $result['status'] !== 'ok') {
                $message = is_array($result) && isset($result['message']) && is_string($result['message'])
                    ? $result['message'] : 'Invalid JSON response';
                throw new FlareSolverrException('FlareSolverr failed: '.$message);
            }
            if (!isset($result['solution']) || !is_array($result['solution'])) {
                throw new FlareSolverrException('FlareSolverr returned no solution.');
            }
            $solution = $result['solution'];
            $status = isset($solution['status']) ? filter_var($solution['status'], FILTER_VALIDATE_INT) : false;
            if ($status === false || $status < 200 || $status >= 300) {
                throw new FlareSolverrException('FlareSolverr HTTP status: '.($status === false ? 'missing' : $status));
            }
            if (!isset($solution['response']) || !is_string($solution['response']) || trim($solution['response']) === '') {
                throw new FlareSolverrException('FlareSolverr returned empty content.');
            }
            if (!isset($solution['url']) || !is_string($solution['url']) || !$this->validateUrl($solution['url'])) {
                throw new FlareSolverrException('FlareSolverr returned an invalid final URL.');
            }
            $this->debug('FlareSolverr HTTP status: '.$status.'; final URL: '.$solution['url']);
            $response = $this->normalizeResponse($solution['response']);
            $this->requests[$original] = array(
                'original_url' => $original,
                'effective_url' => $solution['url'],
                'status_code' => $status,
                // response is a rendered, decoded DOM, not the original wire body.
                // Original compression, length and charset headers would corrupt it.
                // SimplePie's feed adapter expects a complete HTTP status line.
                'headers' => 'HTTP/1.1 '.$status." OK\r\n".$response['headers'],
                'body' => $response['body'],
                'method' => 'GET'
            );
        }
    }

    protected function feedXml($body)
    {
        // FlareSolverr's JSON contains rendered UTF-8 text, including XML views.
        $xml = preg_replace('~^\s*<\?xml\b.*?\?>~s', '<?xml version="1.0" encoding="UTF-8"?>', $body, 1);
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $valid = $doc->loadXML($xml, LIBXML_NONET);
        $isFeed = $valid && $doc->documentElement &&
            in_array($doc->documentElement->localName, array('rss', 'feed', 'RDF'), true);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $isFeed ? $xml : false;
    }

    protected function normalizeResponse($body)
    {
        $xml = $this->feedXml($body);
        if ($xml === false && stripos($body, '<pre') !== false) {
            // Chromium displays RSS/Atom as escaped XML inside a single <pre>.
            $previous = libxml_use_internal_errors(true);
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->loadHTML('<?xml encoding="UTF-8"?>'.$body, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $bodyNode = $doc->getElementsByTagName('body')->item(0);
            $pre = null;
            $singlePre = true;
            if ($bodyNode) {
                foreach ($bodyNode->childNodes as $node) {
                    if ($node->nodeType === XML_ELEMENT_NODE) {
                        if ($node->nodeName !== 'pre' || $pre !== null) {
                            $singlePre = false;
                            break;
                        }
                        $pre = $node;
                    } elseif ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) !== '') {
                        $singlePre = false;
                        break;
                    }
                }
            }
            if ($singlePre && $pre !== null) {
                // textContent decodes the HTML wrapper once, preserving XML entities.
                $xml = $this->feedXml($pre->textContent);
            }
        }
        return array(
            'headers' => $xml === false ? "Content-Type: text/html; charset=UTF-8\r\n" : "Content-Type: application/xml; charset=UTF-8\r\n",
            'body' => $xml === false ? $body : $xml
        );
    }

    protected function postJson($payload)
    {
        $timeoutSeconds = (int)ceil($this->maxTimeout / 1000) + 10;
        if (function_exists('curl_init')) {
            $handle = curl_init($this->flaresolverrUrl);
            curl_setopt_array($handle, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $timeoutSeconds
            ));
            $body = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($body === false) {
                throw new FlareSolverrException('Cannot reach FlareSolverr: '.$error);
            }
        } else {
            $context = stream_context_create(array('http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0
            )));
            $body = @file_get_contents($this->flaresolverrUrl, false, $context);
            if ($body === false) throw new FlareSolverrException('Cannot reach FlareSolverr.');
            $status = 0;
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('!^HTTP/\S+\s+(\d{3})!', $header, $match)) $status = (int)$match[1];
                }
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new FlareSolverrException('FlareSolverr API HTTP status: '.$status);
        }
        return $body;
    }
}
