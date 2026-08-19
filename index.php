<?php
/**
 * GitHub Proxy — v2.5 (Stable) — with signin/signup/copilot blocking
 *
 * 用法:
 *   /user/repo              → https://github.com/user/repo
 *   /user/repo/tree/main    → https://github.com/user/repo/tree/main
 *   /https://github.com/... → 完整 URL 透传
 *
 * 架构:
 *   - 全局变量 $gBuf 累积文本块
 *   - 每次收到数据，找最后一个 '>' 安全切割
 *   - 头部由 CURLOPT_HEADERFUNCTION 逐行转发
 *   - 主体由 CURLOPT_WRITEFUNCTION 逐块重写+输出
 *   - curl_exec 返回后，flush 剩余 buffer
 */

define('PROXY_VERSION', '2.5');
define('AUTH_ENABLED', false);
define('AUTH_TOKEN',   'changeme');

/* ═════════════════════════════════════════════
 * 阻止列表 — 禁止代理访问的路径关键字
 * 命中以下关键字的 URL 将被直接拦截拒绝
 * ═════════════════════════════════════════════ */
define('BLOCK_ENABLED', true);
define('BLOCK_REDIRECTS', true); // 是否拦截 GitHub 发起的登录/注册重定向

function getBlockKeywords(): array {
    return [
        // 登录相关
        '/login',
        '/signin',
        '/session',
        '/authenticate',
        '/authorize',
        '/oauth',
        '/sso',
        // 注册相关
        '/signup',
        '/join',
        '/register',
        // Copilot 相关
        '/copilot',
        '/features/copilot',
        'copilot-chat',
        'copilot-cloud',
        // 账户/付费相关
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

/* ═════════════════════════════════════════════
 * 全局状态（WRITEFUNCTION 闭包需要）
 * ═════════════════════════════════════════════ */
$GLOBALS['gBuf']         = '';
$GLOBALS['gContentType'] = '';
$GLOBALS['gIsBinary']    = false;
$GLOBALS['gHeadersSent'] = false;
$GLOBALS['gProxyBase']   = '';

/* ═════════════════════════════════════════════
 * 工具函数
 * ═════════════════════════════════════════════ */

function getProxyBase(): string {
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

/* ═════════════════════════════════════════════
 * 白名单 & URL 判定
 * ═════════════════════════════════════════════ */

function rewriteHosts(): array {
    return [
        'github.com', 'www.github.com',
        'raw.githubusercontent.com', 'gist.githubusercontent.com',
        'api.github.com', 'github.githubassets.com',
        'avatars.githubusercontent.com',
        'avatars0.githubusercontent.com', 'avatars1.githubusercontent.com',
        'avatars2.githubusercontent.com', 'avatars3.githubusercontent.com',
        'camo.githubusercontent.com', 'user-images.githubusercontent.com',
        'objects.githubusercontent.com', 'desktop.githubusercontent.com',
        'media.githubusercontent.com', 'assets-cdn.github.com',
        'github-cloud.s3.amazonaws.com',
    ];
}

function hostInWhitelist(string $host): bool {
    if (!$host) return false;
    foreach (rewriteHosts() as $h) {
        if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) return true;
    }
    return false;
}

function shouldRewrite(string $url): bool {
    if (strpos($url, '://') === false) return false;
    return hostInWhitelist(parse_url($url, PHP_URL_HOST) ?: '');
}

function makeProxyUrl(string $url, string $base): string {
    if (strpos($url, $base) === 0) return $url;
    return $base . '/' . $url;
}

/* ═════════════════════════════════════════════
 * URL 阻止检查 — 拦截 signin/signup/copilot
 * ═════════════════════════════════════════════ */

function isBlockedUrl(string $url): bool {
    if (!BLOCK_ENABLED) return false;
    if ($url === '') return false;

    $lower = strtolower($url);

    // 1) 主机+路径关键字匹配（词边界：后面必须跟 / ? # 或结尾）
    foreach (getBlockHostKeywords() as $kw) {
        if (preg_match('#' . preg_quote($kw, '#') . '(?=[/?#]|$)#', $lower)) return true;
    }

    // 2) 路径段匹配（处理短链格式 /user/repo/login 等）
    $path = parse_url($lower, PHP_URL_PATH) ?: '';
    if ($path !== '') {
        // 精确路径段匹配
        $segments = explode('/', trim($path, '/'));
        $blockSegs = ['login', 'signin', 'signup', 'join', 'register',
                      'session', 'authenticate', 'authorize', 'oauth',
                      'copilot', 'pricing', 'plans', 'checkout', 'billing'];
        // 仅对已知复合词做子串匹配（避免误伤 login.py 等合法路径）
        $compoundSegs = ['copilot'];
        foreach ($segments as $seg) {
            if (in_array(strtolower($seg), $blockSegs, true)) return true;
            // 仅对特定前缀做子串匹配（捕获 copilot-chat, copilot-cloud 等）
            foreach ($compoundSegs as $cs) {
                if (strpos($seg, $cs . '-') === 0 || strpos($seg, $cs . '_') === 0) return true;
            }
        }
    }

    // 3) 查询参数中包含 login/signup 提示
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

/* ═════════════════════════════════════════════
 * URL 重写 — HTML/通用
 * ═════════════════════════════════════════════ */

function rewriteHtmlUrls(string $html, string $proxyBase): string {
    $hosts  = rewriteHosts();
    $alt    = implode('|', array_map('preg_quote', $hosts));
    // 负向后顾: 前面不能是 : / 字母数字(避免双重代理)
    $pat    = '#(?<![:/a-zA-Z0-9])(https?://)(' . $alt . ')(/[^"\'\s<>\\)]*)#';

    if (!preg_match_all($pat, $html, $matches, PREG_OFFSET_CAPTURE)) return $html;

    $reps = [];
    foreach ($matches[0] as $i => $m) {
        $text   = $m[0];
        $off    = $m[1];
        $proto  = $matches[1][$i][0];
        $host   = $matches[2][$i][0];
        $path   = $matches[3][$i][0] ?? '';
        $orig   = $proto . $host . $path;
        $reps[] = ['s' => $off, 'e' => $off + strlen($text), 'r' => makeProxyUrl($orig, $proxyBase)];
    }

    // 从后往前，去重叠
    usort($reps, fn($a, $b) => $b['s'] - $a['s']);
    $out = $html; $lastEnd = PHP_INT_MAX;
    foreach ($reps as $r) {
        if ($r['s'] < $lastEnd) { $out = substr_replace($out, $r['r'], $r['s'], $r['e'] - $r['s']); $lastEnd = $r['s']; }
    }
    return $out;
}

/* ═════════════════════════════════════════════
 * URL 重写 — CSS url()
 * ═════════════════════════════════════════════ */

function rewriteCssUrls(string $css, string $proxyBase): string {
    return preg_replace_callback('#url\(\s*["\']?(https?://[^"\'\s)]+)["\']?\s*\)#i',
        function ($m) use ($proxyBase) {
            return shouldRewrite($m[1]) ? 'url("' . makeProxyUrl($m[1], $proxyBase) . '")' : $m[0];
        }, $css);
}

/* ═════════════════════════════════════════════
 * URL 重写 — JS 字符串（保守）
 * ═════════════════════════════════════════════ */

function rewriteJsUrls(string $js, string $proxyBase): string {
    $hosts = rewriteHosts();
    $alt   = implode('|', array_map('preg_quote', $hosts));
    $pat   = '#(["\'])(https?://(' . $alt . ')/[^"\']{0,2000})\1#';
    return preg_replace_callback($pat, function($m) use ($proxyBase) {
        return $m[1] . makeProxyUrl($m[2], $proxyBase) . $m[1];
    }, $js);
}

/* ═════════════════════════════════════════════
 * 安全 Buffer 处理
 * ═════════════════════════════════════════════ */

function flushSafeChunk(string $ct, string $proxyBase): void {
    $buf = &$GLOBALS['gBuf'];
    if ($buf === '') return;

    $bufLen = strlen($buf);
    $lastGt = strrpos($buf, '>');

    // 有安全边界 → 切割输出
    if ($lastGt !== false && $lastGt >= 10) {
        $chunk = substr($buf, 0, $lastGt + 1);
        $buf   = substr($buf, $lastGt + 1);
    }
    // 无 > 但 buffer 太大(>16KB) → 强制输出
    else if ($bufLen > 16384) {
        $chunk = $buf;
        $buf   = '';
    }
    // buffer 太小且没安全边界 → 攒着
    else {
        return;
    }

    $lower = strtolower($ct);
    if (strpos($lower, 'text/css') !== false) {
        $chunk = rewriteCssUrls($chunk, $proxyBase);
        $chunk = rewriteHtmlUrls($chunk, $proxyBase);
    } elseif (strpos($lower, 'javascript') !== false || strpos($lower, 'json') !== false) {
        $chunk = rewriteJsUrls($chunk, $proxyBase);
    } else {
        $chunk = rewriteHtmlUrls($chunk, $proxyBase);
    }

    echo $chunk;
    flush();
}

function flushAllRemaining(string $ct, string $proxyBase): void {
    $buf = &$GLOBALS['gBuf'];
    if ($buf === '') return;

    $lower = strtolower($ct);
    if (strpos($lower, 'text/css') !== false) {
        $buf = rewriteCssUrls($buf, $proxyBase);
        $buf = rewriteHtmlUrls($buf, $proxyBase);
    } elseif (strpos($lower, 'javascript') !== false || strpos($lower, 'json') !== false) {
        $buf = rewriteJsUrls($buf, $proxyBase);
    } else {
        $buf = rewriteHtmlUrls($buf, $proxyBase);
        if (strpos($lower, 'text/html') !== false) {
            // ★ 服务端 HTML 过滤：移除/禁用登录注册 Copilot 链接
            $buf = stripBlockedLinks($buf);
            $buf = injectProxyJs($buf, $proxyBase);
        }
    }

    echo $buf;
    flush();
    $buf = '';
}

/* ═════════════════════════════════════════════
 * HTML 过滤 — 移除/禁用登录注册 Copilot 链接
 * ═════════════════════════════════════════════ */

function stripBlockedLinks(string $html): string {
    if (!BLOCK_ENABLED) return $html;

    $blockSegs = ['login', 'signin', 'signup', 'join', 'register',
                  'copilot', 'pricing', 'plans', 'checkout', 'billing'];

    // 1) 处理 a[href] 标签
    $html = preg_replace_callback(
        '#<a\b([^>]*)\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))([^>]*)>#i',
        function ($m) use ($blockSegs) {
            $before = $m[1];
            $href   = $m[3] ?? $m[4] ?? $m[5] ?? '';
            $after  = $m[6] ?? '';
            $full   = $m[0];

            $lowerHref = strtolower($href);
            if ($lowerHref === '' || $lowerHref === '#' || $lowerHref === 'about:blank') {
                return $full;
            }

            // 检查是否命中阻止列表
            $hit = false;
            // 检查完整 host+path 关键字
            foreach (getBlockHostKeywords() as $kw) {
                if (strpos($lowerHref, $kw) !== false) { $hit = true; break; }
            }
            // 检查路径段
            if (!$hit) {
                $path = parse_url($lowerHref, PHP_URL_PATH) ?: $lowerHref;
                $segs = explode('/', trim($path, '/'));
                $compoundSegs = ['copilot'];
                foreach ($segs as $seg) {
                    if (in_array(strtolower($seg), $blockSegs, true)) { $hit = true; break; }
                    foreach ($compoundSegs as $cs) {
                        if (strpos($seg, $cs . '-') === 0 || strpos($seg, $cs . '_') === 0) { $hit = true; break 2; }
                    }
                }
            }

            if ($hit) {
                // 替换为 about:blank 并禁用点击
                return '<a' . $before . ' href="about:blank"' . $after
                     . ' onclick="return false;" style="opacity:0.4;pointer-events:none;"' . '>';
            }
            return $full;
        },
        $html
    );

    // 2) 处理 form[action] 标签
    $html = preg_replace_callback(
        '#<form\b([^>]*)\baction\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))([^>]*)>#i',
        function ($m) use ($blockSegs) {
            $before = $m[1];
            $action = $m[3] ?? $m[4] ?? $m[5] ?? '';
            $after  = $m[6] ?? '';
            $full   = $m[0];

            $lowerAction = strtolower($action);
            if ($lowerAction === '') return $full;

            $hit = false;
            foreach (getBlockHostKeywords() as $kw) {
                if (strpos($lowerAction, $kw) !== false) { $hit = true; break; }
            }
            if (!$hit) {
                $path = parse_url($lowerAction, PHP_URL_PATH) ?: $lowerAction;
                $segs = explode('/', trim($path, '/'));
                $compoundSegs = ['copilot'];
                foreach ($segs as $seg) {
                    if (in_array(strtolower($seg), $blockSegs, true)) { $hit = true; break; }
                    foreach ($compoundSegs as $cs) {
                        if (strpos($seg, $cs . '-') === 0 || strpos($seg, $cs . '_') === 0) { $hit = true; break 2; }
                    }
                }
            }

            if ($hit) {
                return '<form' . $before . ' action="about:blank"' . $after
                     . ' onsubmit="return false;"' . '>';
            }
            return $full;
        },
        $html
    );

    return $html;
}

/* ═════════════════════════════════════════════
 * JS 注入 — 拦截前端动态请求
 * ═════════════════════════════════════════════ */

function injectProxyJs(string $html, string $proxyBase): string {
    $hosts        = rewriteHosts();
    $hostsJson   = json_encode($hosts);
    $proxyBaseJs = json_encode($proxyBase);

    // 阻止关键字 JSON（前端用）
    $blockKw = json_encode(getBlockKeywords());
    $blockHostKw = json_encode(getBlockHostKeywords());

    $script = '<script>(function(){
var pb=' . $proxyBaseJs . ',hs=' . $hostsJson . ',bk=' . $blockKw . ',bhk=' . $blockHostKw . ';
function ok(u){try{var a=new URL(u);for(var i=0;i<hs.length;i++)if(a.hostname===hs[i]||a.hostname.endsWith("."+hs[i]))return true;}catch(e){}return false;}
function wp(u){return typeof u==="string"&&u.indexOf(pb)!==0&&ok(u)?pb+"/"+u:u;}
function isBlocked(u){if(typeof u!=="string")return false;var l=u.toLowerCase();for(var i=0;i<bhk.length;i++)if(l.indexOf(bhk[i])!==-1)return true;try{var a=new URL(u);var p=a.pathname.split("/");for(var j=0;j<p.length;j++){var s=p[j].toLowerCase();if(["login","signin","signup","join","register","session","authenticate","authorize","oauth","copilot","pricing","plans","checkout","billing"].indexOf(s)!==-1)return true;}}catch(e){}return false;}
function nu(u){return isBlocked(u)?"about:blank":u;}
document.addEventListener("DOMContentLoaded",function(){
var ls=document.querySelectorAll("a[href]");
for(var i=0;i<ls.length;i++){var h=ls[i].getAttribute("href");if(!h)continue;if(ok(h))ls[i].setAttribute("href",pb+"/"+h);if(isBlocked(h)){ls[i].setAttribute("href","about:blank");ls[i].setAttribute("onclick","return false;");ls[i].style.opacity="0.4";ls[i].style.pointerEvents="none";}}
var fms=document.querySelectorAll("form[action]");for(var k=0;k<fms.length;k++){var a=fms[k].getAttribute("action");if(a&&isBlocked(a))fms[k].setAttribute("action","about:blank");}
});
var of=window.fetch;if(of)window.fetch=function(i,c){if(typeof i==="string")i=nu(i);else if(i&&i.url)i.url=nu(i.url);return of.call(this,i,c);};
var oo=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u,a,b,c){return oo.call(this,m,nu(u),a,b,c);};
var ows=window.WebSocket;if(ows)window.WebSocket=function(u,p){return new ows(wp(u),p);};
document.addEventListener("submit",function(e){var f=e.target;if(f&&f.action&&ok(f.action))f.action=pb+"/"+f.action;if(f&&f.action&&isBlocked(f.action)){e.preventDefault();return false;}},true);
window.addEventListener("beforeunload",function(e){if(isBlocked(location.href)){e.preventDefault();return false;}});
})();</script>';

    $pos = stripos($html, '</head>');
    if ($pos !== false) return substr_replace($html, $script . '</head>', $pos, 7);

    $pos = stripos($html, '<body');
    if ($pos !== false) {
        $gt = strpos($html, '>', $pos);
        if ($gt !== false) return substr_replace($html, '>' . $script, $gt, 1);
    }
    return $script . $html;
}

/* ═════════════════════════════════════════════
 * 请求路由
 * ═════════════════════════════════════════════ */

function parseRequest(): array {
    $uri = urldecode($_SERVER['REQUEST_URI'] ?? '/');
    $q   = strpos($uri, '?');
    if ($q !== false) $uri = substr($uri, 0, $q);

    if (preg_match('#^/(https?://.+)$#i', $uri, $m)) {
        return ['url' => $m[1], 'format' => 'full'];
    }
    if (preg_match('#^/([a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+(?:/.*)?)$#', $uri, $m)) {
        return ['url' => 'https://github.com/' . $m[1], 'format' => 'short'];
    }
    return ['url' => '', 'format' => 'help'];
}

/* ═════════════════════════════════════════════
 * 核心: 执行代理请求
 * ═════════════════════════════════════════════ */

function proxyRequest(string $url, string $proxyBase): void {
    dbg("→ $url");

    $GLOBALS['gBuf']         = '';
    $GLOBALS['gContentType'] = '';
    $GLOBALS['gIsBinary']    = false;
    $GLOBALS['gHeadersSent'] = false;
    $GLOBALS['gProxyBase']   = $proxyBase;

    $ch = curl_init($url);

    $reqHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
        'Cache-Control: no-cache',
    ];
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $reqHeaders[] = 'X-Requested-With: ' . $_SERVER['HTTP_X_REQUESTED_WITH'];
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $reqHeaders,
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
                'transfer-encoding', 'content-encoding', 'content-length',
                'connection', 'keep-alive', 'strict-transport-security',
                'content-security-policy', 'x-frame-options',
                'access-control-allow-origin', 'access-control-allow-credentials',
                'set-cookie', 'vary',
            ]);
            if (isset($skipMap[$lower])) return strlen($line);

            if ($lower === 'location' && shouldRewrite($value)) {
                $value = makeProxyUrl($value, $proxyBase);
            }
            if ($lower === 'link') {
                $value = preg_replace_callback('#<(https?://[^>]+)>#',
                    fn($m) => '<' . makeProxyUrl($m[1], $proxyBase) . '>', $value);
            }

            header($name . ': ' . $value, false);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function($ch, $data) use ($proxyBase) {
            if ($GLOBALS['gContentType'] === '') {
                $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'text/html';
                $GLOBALS['gContentType'] = $ct;
                $GLOBALS['gIsBinary']    = isBinaryCT($ct);
                if (!headers_sent()) header('Content-Type: ' . $ct, true);
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
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => 0,
    ]);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $body = file_get_contents('php://input');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 重定向处理
    if ($code >= 300 && $code < 400) {
        $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if ($loc) {
            // ★ 阻止登录/注册/Copilot 重定向
            if (BLOCK_REDIRECTS && isBlockedUrl($loc)) {
                dbg("BLOCK redirect → $loc");
                curl_close($ch);
                http_response_code(403);
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
<p>GitHub 试图将你重定向到登录/注册/Copilot 页面，已被代理拦截。</p>
<p class="url">{$safeLoc}</p>
<p><a href="javascript:history.back()">← 返回上一页</a> | <a href="/">返回代理首页</a></p>
</div></body></html>
HTML;
                return;
            }

            $locHost = parse_url($loc, PHP_URL_HOST) ?: '';
            $locPath = parse_url($loc, PHP_URL_PATH) ?: '';
            if (strpos($locHost, 'login') !== false ||
                strpos($locPath, '/login') !== false ||
                strpos($loc, 'authenticate') !== false) {
                dbg("BLOCK login redirect → $loc");
                curl_close($ch);
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'GitHub redirected to login', 'location' => $loc]);
                return;
            }
            if (shouldRewrite($loc)) {
                $pu = makeProxyUrl($loc, $proxyBase);
                dbg("REDIRECT $loc → $pu");
                curl_close($ch);
                http_response_code($code);
                header('Location: ' . $pu);
                return;
            }
        }
    }

    if (curl_errno($ch)) {
        $err = curl_error($ch);
        dbg("cURL error: $err");
        curl_close($ch);
        if (!headers_sent()) { http_response_code(502); header('Content-Type: text/plain'); }
        echo "Proxy Error: $err";
        return;
    }

    curl_close($ch);

    // ★ 关键: flush 剩余 buffer
    flushAllRemaining($GLOBALS['gContentType'], $proxyBase);
}

/* ═════════════════════════════════════════════
 * 帮助页面
 * ═════════════════════════════════════════════ */

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

/* ═════════════════════════════════════════════
 * 入口
 * ═════════════════════════════════════════════ */

function main(): void {
    if (AUTH_ENABLED) {
        $tok = $_GET['token'] ?? '';
        if ($tok !== AUTH_TOKEN) {
            http_response_code(401); header('Content-Type: text/plain; charset=utf-8');
            echo 'Unauthorized. 请添加 ?token=xxx 到 URL。'; return;
        }
    }

    $base   = getProxyBase();
    $parsed = parseRequest();

    if ($parsed['format'] === 'help') { helpPage($base); return; }

    // ★ 阻止 signin / signup / copilot 等 URL
    if (BLOCK_ENABLED && isBlockedUrl($parsed['url'])) {
        blockResponse($parsed['url'], 'blocked: signin/signup/copilot');
        return;
    }

    if (!filter_var($parsed['url'], FILTER_VALIDATE_URL)) {
        http_response_code(400); header('Content-Type: text/plain');
        echo 'Invalid URL: ' . $parsed['url']; return;
    }

    proxyRequest($parsed['url'], $base);
}

if (!isset($GLOBALS['testing'])) {
    main();
}
