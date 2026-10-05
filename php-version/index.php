define('PROXY_VERSION', '2.6');
define('AUTH_ENABLED', false);
define('AUTH_TOKEN',   'changeme');

define('PROXY_BASE', '');

define('BLOCK_ENABLED', true);
define('BLOCK_REDIRECTS', true);

function getBlockKeywords(): array {
    return [
        '/login',
        '/signin',
        '/session',
        '/authenticate',
        '/authorize',
        '/oauth',
        '/sso',
        '/signup',
        '/join',
        '/register',
        '/copilot',
        '/features/copilot',
        'copilot-chat',
        'copilot-cloud',
        '/pricing',
        '/plans',
        '/checkout',
        '/billing',
        '/account',
        '/settings/billing',
    ];
}

function getBlockHostKeywords(): array {
    return [
        'github.com/login',
        'github.com/signin',
        'github.com/signup',
        'github.com/join',
        'github.com/session',
        'github.com/authenticate',
        'github.com/authorize',
        'github.com/oauth',
        'github.com/sso',
        'github.com/copilot',
        'github.com/features/copilot',
        'github.com/pricing',
        'github.com/plans',
        'github.com/checkout',
        'github.com/billing',
        'github.com/account',
    ];
}

function getBlockSegments(): array {
    return [
        'login', 'signin', 'signup', 'join', 'register',
        'session', 'authenticate', 'authorize', 'oauth', 'sso',
        'copilot', 'pricing', 'plans', 'checkout', 'billing',
    ];
}

function getBlockCompoundSegments(): array {
    return ['copilot'];
}


$GLOBALS['gBuf']          = '';
$GLOBALS['gContentType']  = '';
$GLOBALS['gIsBinary']     = false;
$GLOBALS['gHeadersSent']  = false;
$GLOBALS['gProxyBase']    = '';
$GLOBALS['gInjected']     = false;   
$GLOBALS['gUpstreamCL']   = '';      
$GLOBALS['gMethod']       = 'GET';
$GLOBALS['gBlockedLoc']   = '';      
$GLOBALS['gReqOrigin']    = '';      

function getProxyBase(): string {
    if (defined('PROXY_BASE') && PROXY_BASE !== '') return rtrim(PROXY_BASE, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function dbg(string $msg): void {
    error_log('[proxy v' . PROXY_VERSION . '] ' . $msg);
}

function isBinaryCT(string $ct): bool {
    $ct = strtolower($ct);
    $kw = ['image/', 'video/', 'audio/', 'font/', 'application/octet-stream',
           'application/zip', 'application/x-gzip', 'application/pdf',
           'application/x-protobuf', 'application/wasm'];
    foreach ($kw as $k) { if (strpos($ct, $k) !== false) return true; }
    return false;
}

function rewriteHosts(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [
        'github.com', 'www.github.com',
        'raw.githubusercontent.com', 'gist.githubusercontent.com',
        'gist.github.com',
        'api.github.com', 'github.githubassets.com',
        'avatars.githubusercontent.com',
        'avatars0.githubusercontent.com', 'avatars1.githubusercontent.com',
        'avatars2.githubusercontent.com', 'avatars3.githubusercontent.com',
        'camo.githubusercontent.com', 'user-images.githubusercontent.com',
        'objects.githubusercontent.com', 'desktop.githubusercontent.com',
        'media.githubusercontent.com', 'private-user-images.githubusercontent.com',
        'assets-cdn.github.com',
        'github-cloud.s3.amazonaws.com',
    ];

    $extra = (string)getenv('GHPROXY_EXTRA_HOSTS');
    if ($extra !== '') {
        foreach (explode(',', $extra) as $h) {
            $h = strtolower(trim($h));
            if ($h !== '' && !in_array($h, $cache, true)) $cache[] = $h;
        }
    }
    return $cache;
}

function hostInWhitelist(string $host): bool {
    $host = strtolower($host);
    if ($host === '') return false;
    foreach (rewriteHosts() as $h) {
        if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) return true;
    }
    return false;
}

function shouldRewrite(string $url): bool {
    if (strpos($url, '://') === false) return false;
    return hostInWhitelist(parse_url($url, PHP_URL_HOST) ?: '');
}

function isWhitelistedUrl(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') return false;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return in_array($host, rewriteHosts(), true);
    }
    return hostInWhitelist($host);
}

function makeProxyUrl(string $url, string $base): string {
    if (strpos($url, $base) === 0) return $url;
    return $base . '/' . $url;
}


function isBlockedUrl(string $url): bool {
    if (!BLOCK_ENABLED) return false;
    if ($url === '') return false;

    $lower = strtolower($url);

    foreach (getBlockHostKeywords() as $kw) {
        if (preg_match('~' . preg_quote($kw, '~') . '(?=[/?#]|$)~', $lower)) return true;
    }

    $path = parse_url($lower, PHP_URL_PATH) ?: '';
    if ($path !== '') {
        $segments    = explode('/', trim($path, '/'));
        $blockSegs   = getBlockSegments();
        $compoundSegs = getBlockCompoundSegments();
        foreach ($segments as $seg) {
            if (in_array($seg, $blockSegs, true)) return true;
            foreach ($compoundSegs as $cs) {
                if (strpos($seg, $cs . '-') === 0 || strpos($seg, $cs . '_') === 0) return true;
            }
        }
    }

    $query = parse_url($lower, PHP_URL_QUERY) ?: '';
    if ($query !== '') {
        if (preg_match('/(?:^|&)(?:return_to|redirect|next|target)=[^&]*\/(?:login|signin|signup|join)/i', $query)) {
            return true;
        }
    }

    return false;
}

function blockResponse(string $url, string $reason = ''): void {
    dbg("BLOCK {$reason}: {$url}");
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeReason = htmlspecialchars($reason ?: 'policy', ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>访问被阻止</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;background:#0d1117;color:#c9d1d9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.c{max-width:560px;width:100%;text-align:center}
.icon{font-size:48px;margin-bottom:16px}
h1{font-size:24px;color:#f85149;margin-bottom:12px}
p{font-size:14px;color:#8b949e;line-height:1.7;margin-bottom:8px}
.url{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:12px;margin:16px 0;font-family:"SF Mono",Consolas,monospace;font-size:12px;color:#f0883e;word-break:break-all}
a{color:#58a6ff;text-decoration:none}
a:hover{text-decoration:underline}
</style></head><body><div class="c">
<div class="icon">🚫</div>
<h1>访问被阻止</h1>
<p>该代理已配置为阻止访问 GitHub 登录、注册及 Copilot 相关页面。</p>
<p class="url">{$safeUrl}</p>
<p>原因: <code>{$safeReason}</code></p>
<p><a href="/">← 返回代理首页</a></p>
</div></body></html>
HTML;
}

function rewriteHtmlUrls(string $html, string $proxyBase): string {
    $hosts  = rewriteHosts();
    $alt    = implode('|', array_map('preg_quote', $hosts));
    $pat    = '~(?<![:/a-zA-Z0-9])(https?://)(' . $alt . ')(:\d{1,5})?(/[^"\'\s<>\\)]*)?(?=["\'\s<>():,?#]|$)~';

    if (!preg_match_all($pat, $html, $matches, PREG_OFFSET_CAPTURE)) return $html;

    $reps = [];
    foreach ($matches[0] as $i => $m) {
        $text   = $m[0];
        $off    = $m[1];
        if ($off < 0) continue;
        $proto  = $matches[1][$i][0];
        $host   = $matches[2][$i][0];
        $port   = $matches[3][$i][0] ?? '';
        $path   = $matches[4][$i][0] ?? '';
        $orig   = $proto . $host . $port . $path;
        if (!shouldRewrite($orig)) continue;
        $reps[] = ['s' => $off, 'e' => $off + strlen($text), 'r' => makeProxyUrl($orig, $proxyBase)];
    }
    if (!$reps) return $html;

    usort($reps, fn($a, $b) => $b['s'] - $a['s']);
    $out = $html; $lastEnd = PHP_INT_MAX;
    foreach ($reps as $r) {
        if ($r['s'] < $lastEnd) { $out = substr_replace($out, $r['r'], $r['s'], $r['e'] - $r['s']); $lastEnd = $r['s']; }
    }
    return $out;
}


function rewriteCssUrls(string $css, string $proxyBase): string {
    return preg_replace_callback('#url\(\s*["\']?(https?://[^"\'\s)]+)["\']?\s*\)#i',
        function ($m) use ($proxyBase) {
            return shouldRewrite($m[1]) ? 'url("' . makeProxyUrl($m[1], $proxyBase) . '")' : $m[0];
        }, $css);
}

function rewriteJsUrls(string $js, string $proxyBase): string {
    $hosts = rewriteHosts();
    $alt   = implode('|', array_map('preg_quote', $hosts));
    $pat   = '#(["\'])(https?://(' . $alt . ')(:\d{1,5})?/[^"\']{0,2000})\1#';
    return preg_replace_callback($pat, function($m) use ($proxyBase) {
        return $m[1] . makeProxyUrl($m[2], $proxyBase) . $m[1];
    }, $js);
}

function contentMode(string $ct): string {
    $ct = strtolower($ct);
    if (strpos($ct, 'text/html') !== false || strpos($ct, 'application/xhtml+xml') !== false) return 'html';
    if (strpos($ct, 'text/css') !== false) return 'css';
    if (strpos($ct, 'javascript') !== false || strpos($ct, 'json') !== false) return 'js';
    return 'none';
}

function applyRewrite(string $chunk, string $mode, string $proxyBase): string {
    switch ($mode) {
        case 'css':
            $chunk = rewriteCssUrls($chunk, $proxyBase);
            return rewriteHtmlUrls($chunk, $proxyBase);
        case 'js':
            return rewriteJsUrls($chunk, $proxyBase);
        case 'html':
            $chunk = rewriteHtmlUrls($chunk, $proxyBase);
            $chunk = stripBlockedLinks($chunk);
            return $chunk;
        default:
            return $chunk;
    }
}

function flushSafeChunk(string $ct, string $proxyBase): void {
    $buf = &$GLOBALS['gBuf'];
    if ($buf === '') return;

    $mode = contentMode($ct);

    if ($mode === 'html') $buf = injectIntoStream($buf, $proxyBase, false);

    $bufLen = strlen($buf);
    $lastGt = strrpos($buf, '>');

    if ($lastGt !== false && $lastGt >= 10) {
        $chunk = substr($buf, 0, $lastGt + 1);
        $buf   = substr($buf, $lastGt + 1);
    } elseif ($bufLen > 16384) {
        $cut = false;
        foreach (["\n", "\r", "\t", ' '] as $ws) {
            $p = strrpos($buf, $ws);
            if ($p !== false && ($cut === false || $p > $cut)) $cut = $p;
        }
        if ($cut !== false && $cut >= 1024) {
            $chunk = substr($buf, 0, $cut + 1);
            $buf   = substr($buf, $cut + 1);
        } elseif ($bufLen > 65536) {
            $chunk = $buf;
            $buf   = '';
        } else {
            return;
        }
    } else {
        return;
    }

    echo applyRewrite($chunk, $mode, $proxyBase);
    flush();
}

function flushAllRemaining(string $ct, string $proxyBase): void {
    $buf = &$GLOBALS['gBuf'];
    $mode = contentMode($ct);

    if ($buf !== '') {
        if ($mode === 'html') $buf = injectIntoStream($buf, $proxyBase, true);
        $out = applyRewrite($buf, $mode, $proxyBase);
        $buf = '';
        echo $out;
        flush();
    }
}

function stripBlockedLinks(string $html): string {
    if (!BLOCK_ENABLED) return $html;

    $html = preg_replace_callback(
        '#<a\b([^>]*)\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))([^>]*)>#i',
        function ($m) {
            $before = $m[1];
            $href   = $m[3] ?? $m[4] ?? $m[5] ?? '';
            $after  = $m[6] ?? '';
            $full   = $m[0];

            if ($href === '' || $href === '#' || $href === 'about:blank') return $full;

            if (isBlockedUrl($href)) {
                return '<a' . $before . ' href="about:blank"' . $after
                     . ' onclick="return false;" style="opacity:0.4;pointer-events:none;"' . '>';
            }
            return $full;
        },
        $html
    );

    $html = preg_replace_callback(
        '#<form\b([^>]*)\baction\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))([^>]*)>#i',
        function ($m) {
            $before = $m[1];
            $action = $m[3] ?? $m[4] ?? $m[5] ?? '';
            $after  = $m[6] ?? '';
            $full   = $m[0];

            if ($action === '') return $full;

            if (isBlockedUrl($action)) {
                return '<form' . $before . ' action="about:blank"' . $after
                     . ' onsubmit="return false;"' . '>';
            }
            return $full;
        },
        $html
    );

    return $html;
}

function getInjectScript(string $proxyBase): string {
    $hostsJson   = json_encode(array_values(rewriteHosts()));
    $proxyBaseJs = json_encode($proxyBase);
    $segsJson    = json_encode(array_values(getBlockSegments()));
    $compJson    = json_encode(array_values(getBlockCompoundSegments()));
    $blockHostKw = json_encode(array_values(getBlockHostKeywords()));

    return '<script>(function(){
var pb=' . $proxyBaseJs . ',hs=' . $hostsJson . ',ss=' . $segsJson . ',cs=' . $compJson . ',bhk=' . $blockHostKw . ';
function esc(s){return s.replace(/[.*+?^${}()|[\]\\]/g,"\\$&");}
function ok(u){try{var a=new URL(u);for(var i=0;i<hs.length;i++)if(a.hostname===hs[i]||a.hostname.endsWith("."+hs[i]))return true;}catch(e){}return false;}
function wp(u){return typeof u!=="string"||u.indexOf(pb)===0||!ok(u)?u:pb+"/"+u;}
function isBlocked(u){
if(typeof u!=="string")return false;var l=u.toLowerCase();
for(var i=0;i<bhk.length;i++){if(new RegExp(esc(bhk[i])+"(?=[/?#]|$)").test(l))return true;}
var p=l;try{p=new URL(l,location.href).pathname;}catch(e){p=l.split(/[?#]/)[0];}
var seg=p.split("/");
for(var j=0;j<seg.length;j++){var s=seg[j];
if(ss.indexOf(s)!==-1)return true;
for(var k=0;k<cs.length;k++){if(s.indexOf(cs[k]+"-")===0||s.indexOf(cs[k]+"_")===0)return true;}}
return false;}
function rt(u){return isBlocked(u)?"about:blank":wp(u);}
document.addEventListener("DOMContentLoaded",function(){
var ls=document.querySelectorAll("a[href]");
for(var i=0;i<ls.length;i++){var h=ls[i].getAttribute("href");if(!h)continue;if(ok(h))ls[i].setAttribute("href",pb+"/"+h);if(isBlocked(h)){ls[i].setAttribute("href","about:blank");ls[i].setAttribute("onclick","return false;");ls[i].style.opacity="0.4";ls[i].style.pointerEvents="none";}}
var fms=document.querySelectorAll("form[action]");for(var k=0;k<fms.length;k++){var a=fms[k].getAttribute("action");if(a&&isBlocked(a))fms[k].setAttribute("action","about:blank");}
});
var of=window.fetch;if(of)window.fetch=function(i,c){try{
if(typeof i==="string")i=rt(i);
else if(i&&i.url){var u=rt(i.url);if(u!==i.url&&typeof Request!=="undefined")i=new Request(u,i);}
}catch(e){}return of.call(this,i,c);};
var oo=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u,a,b,c){return oo.call(this,m,rt(u),a,b,c);};
var ows=window.WebSocket;if(ows)window.WebSocket=function(u,p){if(isBlocked(u))throw new Error("blocked by proxy");return new ows(u,p);};
document.addEventListener("submit",function(e){var f=e.target;if(f&&f.action&&ok(f.action))f.action=pb+"/"+f.action;if(f&&f.action&&isBlocked(f.action)){e.preventDefault();return false;}},true);
window.addEventListener("beforeunload",function(e){if(isBlocked(location.href)){e.preventDefault();return false;}});
})();</script>';
}

function injectIntoStream(string $html, string $proxyBase, bool $final): string {
    if (!empty($GLOBALS['gInjected'])) return $html;

    $script = getInjectScript($proxyBase);

    if (preg_match('~<(head|body)(\s[^>]*)?>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $gt = strpos($html, '>', $m[0][1]);
        if ($gt !== false) {
            $GLOBALS['gInjected'] = 1;
            return substr($html, 0, $gt + 1) . $script . substr($html, $gt + 1);
        }
    }

    if ($final) {
        $pos = strripos($html, '</html>');
        if ($pos !== false) {
            $GLOBALS['gInjected'] = 1;
            return substr($html, 0, $pos) . $script . substr($html, $pos);
        }
        $GLOBALS['gInjected'] = 1;
        return $script . $html;
    }
    return $html;
}

function injectProxyJs(string $html, string $proxyBase): string {
    $GLOBALS['gInjected'] = false;
    return injectIntoStream($html, $proxyBase, true);
}


function parseRequest(): array {
    $raw = $_SERVER['REQUEST_URI'] ?? '/';

    $q     = strpos($raw, '?');
    $query = '';
    if ($q !== false) {
        $query = substr($raw, $q + 1);
        $raw   = substr($raw, 0, $q);
    }
    $uri = rawurldecode($raw);

    if ($query !== '') {
        $parts = [];
        foreach (explode('&', $query) as $kv) {
            if ($kv === '' ) continue;
            $k = explode('=', $kv, 2)[0];
            if (strtolower(rawurldecode($k)) === 'token') continue;
            $parts[] = $kv;
        }
        $query = implode('&', $parts);
    }

    if (preg_match('#^/(https?://.+)$#i', $uri, $m)) {
        return ['url' => $m[1] . ($query !== '' ? '?' . $query : ''), 'format' => 'full', 'query' => $query];
    }
    if (preg_match('#^/([a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+(?:/.*)?)$#', $uri, $m)) {
        return ['url' => 'https://github.com/' . $m[1] . ($query !== '' ? '?' . $query : ''), 'format' => 'short', 'query' => $query];
    }
    return ['url' => '', 'format' => 'help', 'query' => $query];
}

function resolveLocation(string $value, string $origin): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('~^https?://~i', $value)) return $value;
    if (strpos($value, '//') === 0) {
        $scheme = parse_url($origin, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $value;
    }
    if ($origin === '') return $value;
    return ($value[0] === '/') ? rtrim($origin, '/') . $value : rtrim($origin, '/') . '/' . $value;
}

function blockRedirectResponse(string $loc): void {
    http_response_code(403);
    header_remove('Location');
    header('Content-Type: text/html; charset=utf-8');
    $safeLoc = htmlspecialchars($loc, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>重定向被阻止</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;background:#0d1117;color:#c9d1d9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.c{max-width:560px;width:100%;text-align:center}
.icon{font-size:48px;margin-bottom:16px}
h1{font-size:24px;color:#f85149;margin-bottom:12px}
p{font-size:14px;color:#8b949e;line-height:1.7;margin-bottom:8px}
.url{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:12px;margin:16px 0;font-family:"SF Mono",Consolas,monospace;font-size:12px;color:#f0883e;word-break:break-all}
a{color:#58a6ff;text-decoration:none}
a:hover{text-decoration:underline}
</style></head><body><div class="c">
<div class="icon">🚫</div>
<h1>重定向被阻止</h1>
<p>该跳转指向登录/注册/Copilot 页面或代理白名单之外的主机，已被拦截。</p>
<p class="url">{$safeLoc}</p>
<p><a href="javascript:history.back()">← 返回上一页</a> | <a href="/">返回代理首页</a></p>
</div></body></html>
HTML;
}

function proxyRequest(string $url, string $proxyBase): void {
    dbg("→ $url");

    $GLOBALS['gBuf']         = '';
    $GLOBALS['gContentType'] = '';
    $GLOBALS['gIsBinary']    = false;
    $GLOBALS['gHeadersSent'] = false;
    $GLOBALS['gProxyBase']   = $proxyBase;
    $GLOBALS['gInjected']    = false;
    $GLOBALS['gUpstreamCL']  = '';
    $GLOBALS['gBlockedLoc']  = '';
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: 'https'));
    $host   = strtolower((string)parse_url($url, PHP_URL_HOST));
    $port   = (int)parse_url($url, PHP_URL_PORT);
    $GLOBALS['gReqOrigin']   = $scheme . '://' . $host . ($port ? ':' . $port : '');

    $ch = curl_init($url);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $GLOBALS['gMethod'] = $method;

    $reqHeaders = [
        'user-agent' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'accept' => 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'accept-language' => 'Accept-Language: en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
        'accept-encoding' => 'Accept-Encoding: identity',
        'cache-control' => 'Cache-Control: no-cache',
        'expect' => 'Expect:',   // 禁用 100-continue
    ];
    $clientHdrs = [
        'content-type'      => $_SERVER['CONTENT_TYPE'] ?? '',
        'accept'            => $_SERVER['HTTP_ACCEPT'] ?? '',
        'authorization'     => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        'if-none-match'     => $_SERVER['HTTP_IF_NONE_MATCH'] ?? '',
        'if-modified-since' => $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '',
        'x-requested-with'  => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '',
    ];
    foreach ($clientHdrs as $k => $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        if ($k === 'accept' && $v === '*/*') continue;
        $reqHeaders[$k] = ucwords($k, '-') . ': ' . $v;
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => array_values($reqHeaders),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADERFUNCTION => function($ch, $line) use ($proxyBase) {
            $t = rtrim($line, "\r\n");
            if ($t === '') return strlen($line);

            if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $t, $m)) {
                http_response_code((int)$m[1]);
                return strlen($line);
            }

            $colon = strpos($t, ':');
            if ($colon === false) return strlen($line);
            $name  = trim(substr($t, 0, $colon));
            $value = trim(substr($t, $colon + 1));
            $lower = strtolower($name);

            static $skipMap = null;
            if ($skipMap === null) $skipMap = array_flip([
                'transfer-encoding', 'content-encoding', 'connection', 'keep-alive',
                'strict-transport-security', 'content-security-policy', 'x-frame-options',
                'access-control-allow-origin', 'access-control-allow-credentials',
                'set-cookie', 'vary',
            ]);
            if (isset($skipMap[$lower])) return strlen($line);

            if ($lower === 'content-length') {
                $GLOBALS['gUpstreamCL'] = $value;
                if ($GLOBALS['gMethod'] !== 'HEAD') return strlen($line);
            } elseif ($lower === 'location') {
                $resolved = resolveLocation($value, $GLOBALS['gReqOrigin']);
                if (isWhitelistedUrl($resolved)) {
                    if (BLOCK_ENABLED && BLOCK_REDIRECTS && isBlockedUrl($resolved)) {
                        $GLOBALS['gBlockedLoc'] = $resolved;      // 稍后 403
                        return strlen($line);
                    }
                    $value = makeProxyUrl($resolved, $proxyBase);
                } else {
                    $GLOBALS['gBlockedLoc'] = $resolved;
                    return strlen($line);
                }
            } elseif ($lower === 'link') {
                $value = preg_replace_callback('#<(https?://[^>]+)>#',
                    fn($m) => shouldRewrite($m[1]) ? '<' . makeProxyUrl($m[1], $proxyBase) . '>' : $m[0],
                    $value);
            }

            header($name . ': ' . $value, false);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function($ch, $data) use ($proxyBase) {
            if ($GLOBALS['gContentType'] === '') {
                $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'text/html';
                $GLOBALS['gContentType'] = $ct;
                $GLOBALS['gIsBinary']    = isBinaryCT($ct);
                if (!headers_sent()) {
                    header('Content-Type: ' . $ct, true);
                    if ($GLOBALS['gIsBinary'] && $GLOBALS['gUpstreamCL'] !== '') {
                        header('Content-Length: ' . $GLOBALS['gUpstreamCL'], true);
                    }
                }
            }

            if ($GLOBALS['gIsBinary']) {
                echo $data; flush();
                return strlen($data);
            }

            $GLOBALS['gBuf'] .= $data;

            if (strlen($GLOBALS['gBuf']) >= 4096) {
                flushSafeChunk($GLOBALS['gContentType'], $proxyBase);
            }

            return strlen($data);
        },
        CURLOPT_TIMEOUT         => 0,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME  => 30,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => 0,
    ]);

    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    } elseif ($method !== 'GET') {
        $body = file_get_contents('php://input');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    curl_exec($ch);

    $errno = curl_errno($ch);
    if ($errno) {
        $err = curl_error($ch);
        dbg("cURL error: $err");
        curl_close($ch);
        if (!headers_sent()) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Proxy Error: $err";
            return;
        }
        dbg("response truncated mid-body: $err");
        return;
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 300 && $code < 400 && $GLOBALS['gBlockedLoc'] !== '') {
        dbg("BLOCK redirect → {$GLOBALS['gBlockedLoc']}");
        blockRedirectResponse($GLOBALS['gBlockedLoc']);
        return;
    }

    flushAllRemaining($GLOBALS['gContentType'], $proxyBase);
}

function helpPage(string $base): void {
    header('Content-Type: text/html; charset=utf-8');
    $v = PROXY_VERSION;
    echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>GitHub Proxy</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;background:#0d1117;color:#c9d1d9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.c{max-width:700px;width:100%}
h1{font-size:30px;margin-bottom:6px;color:#58a6ff}
.tag{display:inline-block;background:#238636;color:#fff;padding:2px 10px;border-radius:12px;font-size:12px;margin-bottom:28px}
h2{font-size:18px;margin:22px 0 10px;color:#f0f6fc}
.code{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:14px;margin:6px 0;font-family:"SF Mono",Consolas,monospace;font-size:13px;color:#a5f3fc;word-break:break-all;line-height:1.7}
.code a{color:#a5f3fc;text-decoration:none}
.code a:hover{text-decoration:underline}
.note{background:#1c2128;border-left:3px solid #f0883e;padding:12px 16px;margin:16px 0;border-radius:0 6px 6px 0;font-size:13px;color:#8b949e}
.note code{background:#0d1117;padding:2px 6px;border-radius:3px;color:#f0883e}
</style></head><body><div class="c">
<h1>🚀 GitHub Proxy</h1><span class="tag">v{$v}</span>
<p>轻量、安全的 GitHub 反向代理，绕过网络限制，加速资源访问。</p>
<h2>📖 使用方式</h2>
<div class="code">https://yourdomain.com/user/repo</div>
<div class="code">https://yourdomain.com/https://github.com/user/repo</div>
<h2>✨ 示例</h2>
<div class="code"><a href="{$base}/microsoft/vscode">{$base}/microsoft/vscode</a></div>
<div class="code"><a href="{$base}/https://raw.githubusercontent.com/microsoft/vscode/main/README.md">{$base}/https://raw.githubusercontent.com/microsoft/vscode/main/README.md</a></div>
<h2>⚙️ 特性</h2>
<p>• 流式输出，支持大文件<br>• MIME 类型正确透传<br>• 自动拦截登录/注册/Copilot 跳转<br>• 服务端 + 前端双重阻止 signin/signup/copilot<br>• HTML/CSS/JS URL 重写<br>• 前端 fetch/XHR/WebSocket 拦截<br>• 安全边界切割，不截断标签</p>
<div class="note">💡 将 <code>yourdomain.com</code> 替换为你的实际域名即可。</div>
</div></body></html>
HTML;
}

function main(): void {
    header_remove('X-Powered-By');

    if (AUTH_ENABLED) {
        $tok = (string)($_GET['token'] ?? '');
        $fromQuery = $tok !== '';
        if (!$fromQuery) $tok = (string)($_COOKIE['gp_token'] ?? '');

        if ($tok === '' || !hash_equals(AUTH_TOKEN, $tok)) {
            http_response_code(401); header('Content-Type: text/plain; charset=utf-8');
            echo 'Unauthorized. 请添加 ?token=xxx 到 URL。'; return;
        }
        if ($fromQuery) {
            setcookie('gp_token', $tok, [
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
        }
    }

    $base   = getProxyBase();
    $parsed = parseRequest();

    if ($parsed['format'] === 'help') { helpPage($base); return; }

    if (!isWhitelistedUrl($parsed['url'])) {
        dbg("DENY non-whitelisted: {$parsed['url']}");
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden: only whitelisted GitHub hosts can be proxied.\n";
        return;
    }

    if (BLOCK_ENABLED && isBlockedUrl($parsed['url'])) {
        blockResponse($parsed['url'], 'blocked: signin/signup/copilot');
        return;
    }

    proxyRequest($parsed['url'], $base);
}

if (!isset($GLOBALS['testing'])) {
    main();
}
