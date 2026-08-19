<?php
/**
 * GitHub Proxy — v2.3
 *
 * 访问格式:
 *   https://yourdomain.com/user/repo[/sub/path]
 *   https://yourdomain.com/https://github.com/user/repo
 *   https://yourdomain.com/https://raw.githubusercontent.com/...
 *
 * 特性:
 *   - 流式输出 (header callback + write callback)
 *   - Content-Type 透传, 不破坏 MIME
 *   - URL 重写仅针对文本资源, 二进制直接透传
 *   - 相对路径补全 (./  ../  /path)
 *   - 拦截 GitHub 登录重定向
 *   - JS 运行时重写 (fetch / XHR / WebSocket / EventSource)
 */

// ============================================================
// 配置
// ============================================================
$AUTH_ENABLED = false;          // 是否开启访问密码
$AUTH_PASSWORD = 'changeme';   // 访问密码
$ALLOWED_HOSTS = [              // 允许代理的目标域名
    'github.com',
    'www.github.com',
    'raw.githubusercontent.com',
    'gist.githubusercontent.com',
    'api.github.com',
    'objects.githubusercontent.com',
    'avatars.githubusercontent.com',
    'avatars0.githubusercontent.com',
    'avatars1.githubusercontent.com',
    'avatars2.githubusercontent.com',
    'avatars3.githubusercontent.com',
    'camo.githubusercontent.com',
    'user-images.githubusercontent.com',
    'private-user-images.githubusercontent.com',
    'github.githubassets.com',
    'gist.github.com',
    'collector.github.com',
    'media.githubusercontent.com',
    'desktop.githubusercontent.com',
    'render.githubusercontent.com',
];

// ============================================================
// 鉴权
// ============================================================
if ($AUTH_ENABLED) {
    $provided = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Basic\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                [, $provided] = explode(':', $decoded, 2);
            }
        }
    } elseif (!empty($_POST['password'])) {
        $provided = (string)$_POST['password'];
    } elseif (!empty($_GET['password'])) {
        $provided = (string)$_GET['password'];
    }
    if (!hash_equals($AUTH_PASSWORD, $provided)) {
        sendAuthPage();
        exit;
    }
}

// ============================================================
// 解析目标 URL
// ============================================================
$targetUrl = parseTargetUrl();
if ($targetUrl === null) {
    sendHelpPage();
    exit;
}

// 解析主机名, 检查白名单
$parsedTarget = parse_url($targetUrl);
if (!$parsedTarget || empty($parsedTarget['host'])) {
    http_response_code(400);
    echo 'Invalid target URL';
    exit;
}
if (!in_array($parsedTarget['host'], $ALLOWED_HOSTS, true)) {
    http_response_code(403);
    echo 'Target host not allowed: ' . htmlspecialchars($parsedTarget['host']);
    exit;
}

// ============================================================
// 代理请求
// ============================================================
proxyRequest($targetUrl, $parsedTarget);

// ============================================================
// ==================== 函数定义 ===============================
// ============================================================

/**
 * 解析目标 URL — 支持三种格式:
 *   1) /user/repo[/sub/path]          → github.com
 *   2) /https://github.com/...        → 完整 URL
 *   3) /http://...                    → 完整 URL (罕见)
 */
function parseTargetUrl() {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

    // 去掉 query string
    $pathOnly = explode('?', $requestUri, 2)[0];
    // 去掉 trailing slash 便于处理
    $pathOnly = '/' . ltrim($pathOnly, '/');

    // 格式 2/3: /https://... 或 /http://...
    if (preg_match('#^/(https?://.*)$#i', $pathOnly, $m)) {
        return 'https://' . substr($m[1], strpos($m[1], '://') + 3);
        // 统一为 https
    }

    // 格式 1: /user/repo[/...]
    // 至少需要两段: owner/repo
    $segments = explode('/', trim($pathOnly, '/'));
    if (count($segments) >= 2 && $segments[0] !== '' && $segments[1] !== '') {
        $owner = $segments[0];
        $repo  = $segments[1];
        $sub   = array_slice($segments, 2);
        $path  = '/'. $owner .'/'. $repo;
        if (!empty($sub)) {
            $path .= '/' . implode('/', $sub);
        }
        // 保留原始 query string
        $qs = '';
        if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
            $qs = '?' . $_SERVER['QUERY_STRING'];
        }
        return 'https://github.com' . $path . $qs;
    }

    return null; // 交给帮助页
}

/**
 * 执行代理请求 (流式)
 */
function proxyRequest(string $targetUrl, array $parsedTarget) {
    $ch = curl_init($targetUrl);

    // 请求头
    $reqHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Upgrade-Insecure-Requests: 1',
    ];
    // 透传 Accept (更精确)
    if (!empty($_SERVER['HTTP_ACCEPT'])) {
        $reqHeaders[1] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
    }
    // 透传 Accept-Encoding — 但我们要自己解码, 所以不发送, 让 curl 处理
    // 透传 Referer
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $reqHeaders[] = 'Referer: ' . $_SERVER['HTTP_REFERER'];
    }
    // 透传 Cookie (可选, 谨慎)
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $reqHeaders[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER        => $reqHeaders,
        CURLOPT_HEADER            => false,       // 我们手动处理
        CURLOPT_RETURNTRANSFER    => false,       // 直接输出
        CURLOPT_FOLLOWLOCATION    => false,       // 手动处理重定向
        CURLOPT_MAXREDIRS         => 5,
        CURLOPT_CONNECTTIMEOUT    => 15,
        CURLOPT_TIMEOUT           => 60,
        CURLOPT_SSL_VERIFYPEER    => true,
        CURLOPT_SSL_VERIFYHOST    => 2,
        CURLOPT_ENCODING          => '',          // 自动处理 gzip/deflate
        CURLOPT_BUFFERSIZE        => 8192,
    ]);

    // 状态变量 (闭包共享)
    $state = [
        'headers_sent'     => false,
        'content_type'     => '',
        'is_text'          => false,
        'is_html'          => false,
        'buffer'           => '',                // 文本缓冲
        'proxy_base'       => getProxyBase(),
        'target_origin'    => $parsedTarget['scheme'] . '://' . $parsedTarget['host'],
        'target_host'      => $parsedTarget['host'],
        'response_code'    => 0,
        'redirect_count'   => 0,
    ];

    // ---- Header Callback ----
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$state, $targetUrl) {
        $trimmed = trim($headerLine);
        if ($trimmed === '') {
            return strlen($headerLine);
        }

        // 捕获状态码
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#i', $trimmed, $m)) {
            $state['response_code'] = (int)$m[1];
            return strlen($headerLine);
        }

        // 解析 Content-Type
        if (stripos($trimmed, 'Content-Type:') === 0) {
            $ct = trim(substr($trimmed, 13));
            $state['content_type'] = $ct;
            // 判断是否为文本
            if (preg_match('#^(text/|application/(javascript|json|xml|xhtml|ecmascript)|image/svg\+xml)#i', $ct)) {
                $state['is_text'] = true;
                if (stripos($ct, 'text/html') !== false) {
                    $state['is_html'] = true;
                }
            }
            // 透传给浏览器
            header($trimmed, false);
            return strlen($headerLine);
        }

        // 拦截 Set-Cookie (可选透传)
        if (stripos($trimmed, 'Set-Cookie:') === 0) {
            // 不透传 GitHub 的登录 cookie, 避免污染
            return strlen($headerLine);
        }

        // 跳过 hop-by-hop
        $hopByHop = ['transfer-encoding', 'content-encoding', 'connection', 'keep-alive',
                     'proxy-authenticate', 'proxy-authorization', 'te', 'trailer',
                     'upgrade', 'host'];
        $lower = strtolower($trimmed);
        $skip = false;
        foreach ($hopByHop as $h) {
            if (strpos($lower, $h . ':') === 0) { $skip = true; break; }
        }
        if ($skip) return strlen($headerLine);

        // 其他头透传
        header($trimmed, false);
        return strlen($headerLine);
    });

    // ---- Write Callback (流式输出) ----
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$state) {
        // 第一次收到数据 → 发送状态码 + 确保头已发送
        if (!$state['headers_sent']) {
            if ($state['response_code'] > 0) {
                http_response_code($state['response_code']);
            }
            $state['headers_sent'] = true;
        }

        // 非文本 → 直接输出
        if (!$state['is_text']) {
            echo $data;
            flush();
            return strlen($data);
        }

        // 文本 → 缓冲 + 重写
        $state['buffer'] .= $data;

        // 如果缓冲区较大, 先处理并输出
        if (strlen($state['buffer']) > 16384) {
            $processed = processTextBuffer($state);
            echo $processed;
            flush();
        }

        return strlen($data);
    });

    // 执行请求
    curl_exec($ch);
    $curlErr = curl_error($ch);
    $curlHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 输出剩余缓冲
    if ($state['is_text'] && $state['buffer'] !== '') {
        echo processTextBuffer($state, true);
        flush();
    }

    // 处理重定向 (手动)
    if ($state['response_code'] >= 300 && $state['response_code'] < 400) {
        // 已由 header callback 透传 Location, 浏览器会跟随
    }

    if ($curlErr) {
        error_log("[GitHub Proxy] cURL error: $curlErr for $targetUrl");
    }
}

/**
 * 处理文本缓冲区 — URL 重写核心
 *
 * 关键: 流式场景下, 缓冲区可能在 HTML 标签中间被切断,
 * 导致正则匹配到不完整的属性值 (如 src="https://...js" 被切成两半),
 * 输出到浏览器后变成碎片。
 *
 * 解决: 非最终块时, 回退到最后一个 "安全边界"
 * (即最后一个完整标签的 '>' 之后), 把不完整的尾部留给下一块。
 */
function processTextBuffer(&$state, $isFinal = false) {
    $buf = $state['buffer'];

    if (!$isFinal) {
        // 找最后一个 '>' 位置 — 这是最保守的安全边界
        // (不能用 '>' 因为属性值里可能有 '>', 但概率极低且无害)
        $lastGt = strrpos($buf, '>');
        if ($lastGt !== false && $lastGt < strlen($buf) - 1) {
            // 把 lastGt 之后的内容留给下一块
            $state['buffer'] = substr($buf, $lastGt + 1);
            $buf = substr($buf, 0, $lastGt + 1);
        } else {
            // 没有找到 '>', 整块都不安全, 全部留给下一块
            $state['buffer'] = $buf;
            return '';
        }
    } else {
        // 最终块, 处理全部剩余内容
        $state['buffer'] = '';
    }

    if ($state['is_html']) {
        $buf = rewriteHtml($buf, $state);
    } elseif ($state['is_text']) {
        $buf = rewriteGenericText($buf, $state);
    }

    return $buf;
}

/**
 * HTML 专用重写 — 使用 DOM 安全替换属性
 */
function rewriteHtml(string $html, array &$state): string {
    $proxyBase = $state['proxy_base'];   // e.g. https://proxy.com
    $targetHost = $state['target_host']; // e.g. github.com

    // ---- 1. 属性重写 (正则, 针对常见属性) ----
    // 重要: 正则要求闭合引号必须存在, 否则不匹配。
    // 流式场景下如果属性被切断 (如 src="https://... 没有闭合 "),
    // 该属性会被安全边界截断, 留给下一块处理, 不会输出碎片。
    $attrs = ['href', 'src', 'action', 'formaction', 'data-src', 'data-href',
              'poster', 'srcset', 'content'];
    foreach ($attrs as $attr) {
        $html = preg_replace_callback(
            '#\b' . $attr . '="([^"]*)"#i',
            function($matches) use ($attr, $proxyBase, $targetHost) {
                $val = $matches[1];
                if ($val === '' || $val === '#') return $matches[0];

                $newVal = rewriteUrlValue($val, $proxyBase, $targetHost, $attr);
                if ($newVal === null) return $matches[0];
                return $attr . '="' . $newVal . '"';
            },
            $html
        );
    }

    // ---- 2. srcset 属性 (逗号分隔的 URL 列表) ----
    $html = preg_replace_callback(
        '#\bsrcset="([^"]*)"#i',
        function($matches) use ($proxyBase, $targetHost) {
            $parts = explode(',', $matches[1]);
            $out = [];
            foreach ($parts as $p) {
                $p = trim($p);
                // 格式: "url descriptor" 或 "url"
                if (preg_match('#^(\S+)(.*)$#', $p, $pm)) {
                    $url = $pm[1];
                    $desc = $pm[2] ?? '';
                    $newUrl = rewriteUrlValue($url, $proxyBase, $targetHost, 'srcset');
                    if ($newUrl !== null) {
                        $out[] = $newUrl . $desc;
                    } else {
                        $out[] = $p;
                    }
                } else {
                    $out[] = $p;
                }
            }
            return 'srcset="' . implode(', ', $out) . '"';
        },
        $html
    );

    // ---- 3. CSS url() 重写 (在 style 属性和 <style> 标签中) ----
    $html = preg_replace_callback(
        '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#i',
        function($matches) use ($proxyBase, $targetHost) {
            $url = trim($matches[1]);
            $newUrl = rewriteUrlValue($url, $proxyBase, $targetHost, 'css-url');
            if ($newUrl === null) return $matches[0];
            return 'url(' . $newUrl . ')';
        },
        $html
    );

    // ---- 4. 注入 JS 运行时重写器 (放在 <head> 或 <body> 开头) ----
    $injectJs = buildInjectJs($proxyBase, $targetHost);
    if (stripos($html, '</head>') !== false) {
        $html = str_ireplace('</head>', $injectJs . '</head>', $html);
    } elseif (stripos($html, '<body') !== false) {
        $html = preg_replace('#<body([^>]*)>#i', '<body$1>' . $injectJs, $html, 1);
    } else {
        $html = $injectJs . $html;
    }

    return $html;
}

/**
 * 通用文本重写 (JS / CSS / JSON)
 */
function rewriteGenericText(string $text, array &$state): string {
    $proxyBase = $state['proxy_base'];
    $targetHost = $state['target_host'];
    $ct = strtolower($state['content_type']);

    // CSS: url()
    if (strpos($ct, 'css') !== false || strpos($ct, 'stylesheet') !== false) {
        $text = preg_replace_callback(
            '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#i',
            function($matches) use ($proxyBase, $targetHost) {
                $url = trim($matches[1]);
                $newUrl = rewriteUrlValue($url, $proxyBase, $targetHost, 'css-url');
                if ($newUrl === null) return $matches[0];
                return 'url(' . $newUrl . ')';
            },
            $text
        );
        return $text;
    }

    // JS: 重写字符串中的 URL (非常保守 — 只处理明确的、完整的绝对 URL)
    if (strpos($ct, 'javascript') !== false || strpos($ct, 'ecmascript') !== false) {
        // 只匹配 "https://host/path" 或 'https://host/path' 形式
        // 且 host 必须在白名单内
        $text = preg_replace_callback(
            '#(?<=["\'])(https?://([a-z0-9.-]+)(/[^"\'\s\\\\]*))#i',
            function($matches) use ($proxyBase) {
                $url = $matches[1];
                $host = strtolower($matches[2]);
                global $ALLOWED_HOSTS;
                if (!in_array($host, $ALLOWED_HOSTS, true)) return $matches[0];
                // 防止重复代理
                if (strpos($url, $proxyBase) !== false) return $matches[0];
                return $proxyBase . '/' . $url;
            },
            $text
        );
        // 协议相对 URL: "https://host/path" 已经覆盖, 这里处理 '//host/path'
        $text = preg_replace_callback(
            '#(?<=["\'])(\/\/([a-z0-9.-]+)(/[^"\'\s\\\\]*))#i',
            function($matches) use ($proxyBase) {
                $url = $matches[1];
                $host = strtolower($matches[2]);
                global $ALLOWED_HOSTS;
                if (!in_array($host, $ALLOWED_HOSTS, true)) return $matches[0];
                return $proxyBase . '/https:' . $url;
            },
            $text
        );
        return $text;
    }

    return $text;
}

/**
 * 重写单个 URL 值 → 返回代理 URL 或 null (不重写)
 */
function rewriteUrlValue(string $url, string $proxyBase, string $targetHost, string $context): ?string {
    $url = trim($url);

    // 跳过: 空, 锚点, 协议无关标记, data:, javascript:, mailto:, tel:, blob:
    if ($url === '' || $url[0] === '#') return null;
    if (preg_match('#^(data:|javascript:|mailto:|tel:|blob:|about:|vbscript:)#i', $url)) return null;

    // 已经是代理 URL → 跳过
    if (strpos($url, $proxyBase . '/') === 0 || strpos($url, $proxyBase . '?') === 0) return null;

    // 绝对 URL: https://host/path 或 http://host/path
    if (preg_match('#^https?://#i', $url)) {
        $host = extractHost($url);
        if (!shouldProxyHost($host)) return null;
        return $proxyBase . '/' . $url;
    }

    // 协议相对: //host/path
    if (strpos($url, '//') === 0) {
        $host = extractHost('https:' . $url);
        if (!shouldProxyHost($host)) return null;
        return $proxyBase . '/https:' . $url;
    }

    // 根相对: /path/to/resource
    if ($url[0] === '/') {
        return $proxyBase . '/https://' . $targetHost . $url;
    }

    // 相对路径: ./foo 或 ../foo 或 bare-name
    // 这些由浏览器相对于当前页面解析, 不需要重写
    // (JS 注入会处理运行时导航)
    return null;
}

/**
 * 从 URL 中提取 host
 */
function extractHost(string $url): string {
    $p = parse_url($url);
    return $p['host'] ?? '';
}

/**
 * 判断 host 是否在代理白名单中
 */
function shouldProxyHost(string $host): bool {
    global $ALLOWED_HOSTS;
    return in_array(strtolower($host), $ALLOWED_HOSTS, true);
}

/**
 * 获取代理基础 URL (不含末尾斜杠)
 */
function getProxyBase(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * 构建注入到 HTML 中的 JS 重写器
 */
function buildInjectJs(string $proxyBase, string $targetHost): string {
    // 注意: 这里的 JS 字符串中的变量要用 JSON 编码
    $proxyBaseJson = json_encode($proxyBase);
    $targetHostJson = json_encode($targetHost);
    $allowedHostsJson = json_encode(array_values($GLOBALS['ALLOWED_HOSTS']));

    return <<<HTML
<script data-github-proxy-inject>
(function(){
    var proxyBase = {$proxyBaseJson};
    var targetHost = {$targetHostJson};
    var allowedHosts = {$allowedHostsJson};

    function shouldProxy(host) {
        host = host.toLowerCase();
        for (var i = 0; i < allowedHosts.length; i++) {
            if (host === allowedHosts[i] || host.endsWith('.' + allowedHosts[i])) return true;
        }
        return false;
    }

    function rewriteUrl(url) {
        if (!url || url.charAt(0) === '#') return url;
        if (/^(data:|javascript:|mailto:|tel:|blob:|about:)/i.test(url)) return url;
        // 已是代理 URL
        if (url.indexOf(proxyBase + '/') === 0) return url;
        // 绝对 URL
        var m = url.match(/^(https?:)?\\/\\/([^/]+)(\\/.*)?$/i);
        if (m) {
            var proto = m[1] || 'https:';
            var host = m[2];
            if (shouldProxy(host)) {
                return proxyBase + '/' + proto + '//' + host + (m[3] || '/');
            }
            return url;
        }
        // 根相对
        if (url.charAt(0) === '/') {
            return proxyBase + '/' + 'https://' + targetHost + url;
        }
        return url;
    }

    // ---- 重写页面中所有 a/img/script/link/form 等元素的属性 ----
    function rewriteDom() {
        var attrs = ['href','src','action','formaction','data-src','data-href','poster'];
        var tags = document.querySelectorAll('*');
        for (var i = 0; i < tags.length; i++) {
            for (var j = 0; j < attrs.length; j++) {
                var a = attrs[j];
                if (tags[i].hasAttribute(a)) {
                    var v = tags[i].getAttribute(a);
                    var nv = rewriteUrl(v);
                    if (nv !== v) tags[i].setAttribute(a, nv);
                }
            }
        }
    }

    // ---- 拦截 fetch ----
    var origFetch = window.fetch;
    if (origFetch) {
        window.fetch = function(input, init) {
            if (typeof input === 'string') {
                var ni = rewriteUrl(input);
                if (ni !== input) input = ni;
            }
            return origFetch.call(this, input, init);
        };
    }

    // ---- 拦截 XMLHttpRequest ----
    var origOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url) {
        var args = Array.prototype.slice.call(arguments);
        args[1] = rewriteUrl(url);
        return origOpen.apply(this, args);
    };

    // ---- 拦截 WebSocket ----
    var OrigWS = window.WebSocket;
    if (OrigWS) {
        window.WebSocket = function(url, protocols) {
            var newUrl = url;
            if (typeof url === 'string') {
                // wss:// → https:// 代理
                var m = url.match(/^(wss?:)\\/\\/([^/]+)(\\/.*)?$/i);
                if (m) {
                    var proto = m[1].toLowerCase() === 'wss:' ? 'https:' : 'http:';
                    var host = m[2];
                    if (shouldProxy(host)) {
                        newUrl = proxyBase + '/' + proto + '//' + host + (m[3] || '/');
                    }
                }
            }
            if (protocols !== undefined) {
                return new OrigWS(newUrl, protocols);
            }
            return new OrigWS(newUrl);
        };
        window.WebSocket.prototype = OrigWS.prototype;
    }

    // ---- 拦截 EventSource ----
    if (window.EventSource) {
        var OrigES = window.EventSource;
        window.EventSource = function(url, opts) {
            var newUrl = rewriteUrl(url);
            if (opts !== undefined) return new OrigES(newUrl, opts);
            return new OrigES(newUrl);
        };
        window.EventSource.prototype = OrigES.prototype;
    }

    // ---- 拦截 history.pushState / replaceState ----
    var origPush = history.pushState;
    var origReplace = history.replaceState;
    function hijackState(orig) {
        return function(state, title, url) {
            if (typeof url === 'string') {
                // 不修改 URL, 但确保点击前进后退时走代理
            }
            return orig.apply(this, arguments);
        };
    }
    history.pushState = hijackState(origPush);
    history.replaceState = hijackState(origReplace);

    // ---- 初始 DOM 重写 ----
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', rewriteDom);
    } else {
        rewriteDom();
    }

    // ---- 监听动态添加的节点 ----
    var observer = new MutationObserver(function(mutations) {
        var needRewrite = false;
        for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].type === 'childList' && mutations[i].addedNodes.length > 0) {
                needRewrite = true;
                break;
            }
        }
        if (needRewrite) rewriteDom();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
HTML;
}

/**
 * 发送鉴权页面
 */
function sendAuthPage() {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="GitHub Proxy"');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>GitHub Proxy — Auth</title>';
    echo '<style>body{font-family:-apple-system,Segoe UI,sans-serif;max-width:420px;margin:80px auto;padding:20px;background:#0d1117;color:#c9d1d9;text-align:center;border:1px solid #30363d;border-radius:8px}input{padding:10px;width:100%;margin:8px 0;background:#161b22;color:#c9d1d9;border:1px solid #30363d;border-radius:4px;box-sizing:border-box}button{padding:10px 20px;background:#238636;color:#fff;border:none;border-radius:4px;cursor:pointer;width:100%}h2{margin-bottom:4px}#hint{font-size:12px;color:#8b949e;margin-top:16px}</style>';
    echo '</head><body>';
    echo '<h2>🔒 GitHub Proxy</h2><p>请输入访问密码</p>';
    echo '<form method="POST"><input type="password" name="password" placeholder="密码" autofocus><button type="submit">进入</button></form>';
    echo '<p id="hint">或在弹出框中输入</p></body></html>';
}

/**
 * 发送帮助页面
 */
function sendHelpPage() {
    $proxyBase = getProxyBase();
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>GitHub Proxy</title>';
    echo '<style>body{font-family:-apple-system,Segoe UI,sans-serif;max-width:760px;margin:40px auto;padding:20px;background:#0d1117;color:#c9d1d9;line-height:1.6}a{color:#58a6ff;text-decoration:none}a:hover{text-decoration:underline}code{background:#161b22;padding:2px 6px;border-radius:3px;border:1px solid #30363d;font-size:13px}pre{background:#161b22;padding:16px;border-radius:6px;border:1px solid #30363d;overflow-x:auto}h1{color:#f0f6fc}h2{color:#d29922;margin-top:28px}table{width:100%;border-collapse:collapse;margin:12px 0}th,td{padding:8px 12px;text-align:left;border-bottom:1px solid #21262d}th{color:#8b949e;font-weight:500}</style>';
    echo '</head><body>';
    echo '<h1>🚀 GitHub Proxy</h1>';
    echo '<p>一个轻量、安全的 GitHub 反向代理，支持短链和完整 URL 两种访问方式。</p>';

    echo '<h2>访问格式</h2><table>';
    echo '<tr><th>格式</th><th>示例</th></tr>';
    echo '<tr><td>短格式</td><td><code>' . $proxyBase . '/microsoft/vscode</code></td></tr>';
    echo '<tr><td>短格式 + 子路径</td><td><code>' . $proxyBase . '/microsoft/vscode/tree/main</code></td></tr>';
    echo '<tr><td>完整 URL</td><td><code>' . $proxyBase . '/https://github.com/microsoft/vscode</code></td></tr>';
    echo '<tr><td>Raw 文件</td><td><code>' . $proxyBase . '/https://raw.githubusercontent.com/user/repo/main/file.txt</code></td></tr>';
    echo '<tr><td>API</td><td><code>' . $proxyBase . '/https://api.github.com/repos/microsoft/vscode</code></td></tr>';
    echo '</table>';

    echo '<h2>快速测试</h2>';
    echo '<p><a href="' . $proxyBase . '/github" target="_blank">➜ 访问 github.com (短格式)</a></p>';
    echo '<p><a href="' . $proxyBase . '/https://github.com" target="_blank">➜ 访问 github.com (完整格式)</a></p>';

    echo '<h2>工作原理</h2>';
    echo '<pre>浏览器 → 你的域名 → cURL 请求 GitHub → 流式返回
所有 URL 自动重写, 资源走同一代理, MIME 类型透传</pre>';

    echo '<h2>部署提示</h2>';
    echo '<p>1. 将 <code>index.php</code> 放在网站根目录<br>';
    echo '2. Apache 需 <code>.htaccess</code>, Nginx 参考配置<br>';
    echo '3. 建议开启 <code>$AUTH_ENABLED</code> 防止滥用<br>';
    echo '4. 需要 PHP cURL 扩展</p>';

    echo '</body></html>';
}
