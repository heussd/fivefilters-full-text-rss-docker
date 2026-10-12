<?php
// SPDX-License-Identifier: MIT
// Apply small integration points to the pinned FiveFilters source, failing
// the build if upstream code changes rather than silently installing no hook.

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php install.php /path/to/fivefilters\n");
    exit(1);
}
$root = rtrim($argv[1], '/');
$mainPath = $root.'/makefulltextfeed.php';
$corePath = $root.'/makefulltextfeed-core.php';
$indexPath = $root.'/index.php';
$main = file_get_contents($mainPath);
$index = file_get_contents($indexPath);
if (file_exists($corePath)) throw new RuntimeException('FlareSolverr integration is already installed.');

function replace_once($source, $from, $to)
{
    if (substr_count($source, $from) !== 1) {
        throw new RuntimeException('FiveFilters integration point changed: '.$from);
    }
    return str_replace($from, $to, $source);
}

$agentHook = <<<'PHP'
if ($use_flaresolverr) {
        require_once dirname(__FILE__).'/libraries/humble-http-agent/FlareSolverrHttpAgent.php';
        $http = new FlareSolverrHttpAgent($_req_options);
    } else {
        $http = new HumbleHttpAgent($_req_options);
    }
PHP;
$cacheHook = <<<'PHP'
if ($use_flaresolverr) {
        // Browser results must not reuse earlier native-download failures.
        $cache_id = md5('flaresolverr-v1:'.$cache_id);
    }
    $check_cache = true;
PHP;
$main = replace_once($main, '$http = new HumbleHttpAgent($_req_options);', $agentHook);
$main = replace_once($main, '$check_cache = true;', $cacheHook);
$main = replace_once($main, '// Check for cached copy', <<<'PHP'
// Select browser or native fetching before choosing the result cache.
//////////////////////////////////
require_once dirname(__FILE__).'/libraries/humble-http-agent/FlareSolverrHttpAgent.php';
$use_flaresolverr = FlareSolverrHttpAgent::isEnabled(_FF_FTR_MODE);

//////////////////////////////////
// Check for cached copy
PHP
);
$main = replace_once($main, "\tif (isset(\$_GET['proxy'])) \$self .= '&proxy='.urlencode(\$_GET['proxy']);", <<<'PHP'
	if (isset($_GET['proxy'])) $self .= '&proxy='.urlencode($_GET['proxy']);
	if (isset($_GET['flaresolverr'])) $self .= '&flaresolverr='.((string)$_GET['flaresolverr'] === '1' ? '1' : '0');
PHP
);
$formHook = <<<'PHP'
	<div class="control-group" style="margin-top: -15px;">
	<label class="control-label" for="flaresolverr">FlareSolverr</label>
	<div class="controls">
	<input type="hidden" name="flaresolverr" value="0" />
	<input type="checkbox" name="flaresolverr" value="1" id="flaresolverr" style="margin-top: 7px;" title="Use FlareSolverr to fetch the feed and article pages"<?php if (trim(getenv('FTR_FLARESOLVERR_URL')) === '') echo ' disabled="disabled"'; ?> />
	<?php if (trim(getenv('FTR_FLARESOLVERR_URL')) === '') { ?><span class="help-inline">FlareSolverr is not configured on this server.</span><?php } ?>
	</div>
	</div>

PHP;
$index = replace_once($index, "\t</fieldset>\n\t<div class=\"form-actions\">", $formHook."\t</fieldset>\n\t<div class=\"form-actions\">");
if (!copy(__DIR__.'/FlareSolverrHttpAgent.php', $root.'/libraries/humble-http-agent/FlareSolverrHttpAgent.php') ||
    file_put_contents($corePath, $main) === false ||
    !copy(__DIR__.'/makefulltextfeed.php', $mainPath) ||
    file_put_contents($indexPath, $index) === false) {
    throw new RuntimeException('Could not install FlareSolverr integration.');
}
echo "FlareSolverr integration installed.\n";
