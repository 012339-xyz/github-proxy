#!/bin/bash

set -euo pipefail;

if ! command -v nginx >/dev/null 2>&1; then
        echo "Cannot find nginx"
        exit 1;
fi

if ! command -v zig >/dev/null 2>&1; then
        echo "Cannot find zig"
        exit 1;
fi

read -p "main domain: " main_domain || true
if [ -z "${main_domain:-}" ]; then
        echo "main domain is required"
        exit 1
fi
read -p "assets domain: (assets.$main_domain)" assets_domain || true
assets_domain="${assets_domain:-assets.$main_domain}";
read -p "raw domain: (raw.$main_domain)" raw_domain || true
raw_domain="${raw_domain:-raw.$main_domain}";
read -p "gist domain: (gist.$main_domain)" gist_domain || true
gist_domain="${gist_domain:-gist.$main_domain}";
read -p "objects domain: (objects.$main_domain)" objects_domain || true
objects_domain="${objects_domain:-objects.$main_domain}";
read -p "avatars domain: (avatars.$main_domain)" avatars_domain || true
avatars_domain="${avatars_domain:-avatars.$main_domain}";
read -p "user-image domain: (user-images.$main_domain)" user_images_domain || true
user_images_domain="${user_images_domain:-user-images.$main_domain}";
read -p "api domain: (api.$main_domain)" api_domain || true
api_domain="${api_domain:-api.$main_domain}";

cat > proxy.conf << EOF 
proxy_cache_path /var/cache/nginx/ghproxy levels=1:2 keys_zone=proxy_cache:10m inactive=60m use_temp_path=off;
proxy_buffer_size 16k;
proxy_busy_buffers_size 64k;
proxy_buffers 4 32k;
server_tokens off;
proxy_ssl_server_name on;
server {
	listen 443 ssl;
	listen [::]:443 ssl;
        http2 on;
        server_name $main_domain;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass http://127.0.0.1:9001;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
		proxy_set_header Accept-Encoding "";
		add_header content-security-policy "default-src 'none'; base-uri 'self'; child-src $main_domain $assets_domain; connect-src $main_domain $api_domain $raw_domain $gist_domain $assets_domain $objects_domain github-cloud.s3.amazonaws.com; img-src $main_domain $avatars_domain $assets_domain; script-src 'unsafe-inline' $main_domain $assets_domain; style-src 'unsafe-inline' $assets_domain; upgrade-insecure-requests";
	}

    location = / {
		default_type text/html;
		return 200 '<!doctype html><meta charset="utf-8"><title>gh-proxy</title>';
	}
	location ~* ^/(login|signin|signup|join|register|session|sessions|oauth|sso|authorize|authenticate|copilot([-_][a-z0-9]+)?|features/copilot([-_][a-z0-9]+)?|pricing|plans?|account|settings|checkout|billing|enterprise)(/|\$) {
		return 404;
	}
}

server {
	listen 127.0.0.1:9002;
	location / {
		proxy_pass https://github.com/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
		proxy_set_header Accept-Encoding "";
	}
}

server {
	listen 443 ssl;
	listen [::]:443 ssl;
	server_name $assets_domain;
	http2 on;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass https://github.githubassets.com/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 10m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
	}
}
server {
	listen 443 ssl;
	listen [::]:443 ssl;
	server_name $raw_domain;
	http2 on;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass https://raw.githubusercontent.com/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
	}
}
server {
	listen 443 ssl;
	listen [::]:443 ssl;
	server_name $avatars_domain;
	http2 on;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass https://avatars.githubusercontent.com/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
	}
}
server {
	listen 443 ssl;
	listen [::]:443 ssl;
	server_name $objects_domain;
	http2 on;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass https://objects.githubusercontent.com/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
	}
}
server {
	listen 443 ssl;
	listen [::]:443 ssl;
	server_name $user_images_domain;
	http2 on;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass https://user-images.githubusercontent.com/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
	}
}
server {
	listen 443 ssl;
	listen [::]:443 ssl;
	server_name $gist_domain;
	http2 on;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass https://gist.github.com/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
	}
}
server {
	listen 443 ssl;
	listen [::]:443 ssl;
	server_name $api_domain;
	http2 on;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass https://api.github.com/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
		proxy_no_cache 1;
		proxy_cache_bypass 1;
	}
}
EOF

cat > config.zon << EOF
.{
    // https://yourdomain.com
    .main = "https://$main_domain",
    .assets = "https://$assets_domain",
    .raw = "https://$raw_domain",
    .objects = "https://$objects_domain",
    .avatars = "https://$avatars_domain",
    .gist = "https://$gist_domain",
    .user_images = "https://$user_images_domain",
    .api = "https://$api_domain",
    .banned_zon = "./banned.zon",

    // Proxy https://github.com
    // Format http://127.0.0.1:port
    .upstream = "http://127.0.0.1:9002",
    .port = 9001,
    .inject_js = true,
    .inject_js_path = "./inject.js",
}
EOF

cat > inject.js << EOF
(function () {
        let mapping = {
                "github.com": "$main_domain",
                "api.github.com": "$api_domain",
                "raw.githubusercontent.com": "$raw_domain",
                "github.githubassets.com": "$assets_domain",
                "objects.githubusercontent.com": "$objects_domain",
                "avatars.githubusercontent.com": "$avatars_domain",
                "gist.github.com": "$gist_domain",
                "user-images.githubusercontent.com": "$user_images_domain",
        };
        let banned_path = [
                "/login",
                "/signin",
                "/signup",
                "/join",
                "/register",
                "/session",
                "/sessions",
                "/oauth",
                "/sso",
                "/authorize",
                "/authenticate",
                "/copilot*",
                "/features/copilot*",
                "/pricing",
                "/plan",
                "/plans",
                "/account",
                "/settings",
                "/checkout",
                "/billing",
                "/enterprise",
        ];
        function pathBanned(path) {
                let p = path || "/";
                if (p.length > 1 && p.endsWith("/")) {
                        p = p.slice(0, -1);
                }
                let lp = p.toLowerCase();
                for (let i = 0; i < banned_path.length; i++) {
                        let b = banned_path[i].toLowerCase();
                        if (b.endsWith("*")) {
                                if (lp.startsWith(b.slice(0, -1))) return true;
                        } else if (lp === b || lp.startsWith(b + "/")) {
                                return true;
                        }
                }
                return false;
        }
        function isBanned(url) {
                try {
                        return pathBanned(new URL(url, location.href).pathname);
                } catch (e) {
                        return false;
                }
        }
        function toMapped(url) {
                let parsed_url;
                try {
                        parsed_url = new URL(url, location.href);
                } catch (e) {
                        return url;
                }
                let mapped = mapping[parsed_url.hostname.toLowerCase()];
                if (mapped) {
                        parsed_url.host = mapped;
                }
                return parsed_url.toString();
        }
        function applyDomRewrites() {
                let hrefs = document.querySelectorAll("a[href]");
                for (let i = 0; i < hrefs.length; i++) {
                        let href = hrefs[i].getAttribute("href");
                        if (!href) {
                                continue;
                        }
                        if (isBanned(href)) {
                                hrefs[i].setAttribute("href", "about:blank");
                                hrefs[i].setAttribute("onclick", "return false");
                                hrefs[i].style.opacity = "0.4";
                                hrefs[i].style.pointerEvents = "none";
                        } else {
                                hrefs[i].setAttribute("href", toMapped(href));
                        }
                }
                let forms = document.querySelectorAll("form[action]");
                for (let i = 0; i < forms.length; i++) {
                        let action = forms[i].getAttribute("action");
                        if (!action) {
                                continue;
                        }
                        if (isBanned(action)) {
                                forms[i].setAttribute("action", "about:blank");
                                forms[i].setAttribute("onsubmit", "return false");
                        } else {
                                forms[i].setAttribute("action", toMapped(action));
                        }
                }
        }
        if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", applyDomRewrites);
        } else {
                applyDomRewrites();
        }
        let wfetch = window.fetch;
        if (wfetch) {
                window.fetch = function (wrequest, args) {
                        if (wrequest instanceof Request) {
                                if (isBanned(wrequest.url)) {
                                        return wfetch.call(this, new Request("about:blank", wrequest), args);
                                }
                                let mapped = toMapped(wrequest.url);
                                if (mapped !== wrequest.url) {
                                        // Request.url 只读：用映射后的地址重建请求
                                        wrequest = new Request(mapped, wrequest);
                                }
                        } else if (typeof wrequest === "string" || wrequest instanceof URL) {
                                let u = String(wrequest);
                                if (isBanned(u)) {
                                        return wfetch.call(this, "about:blank", args);
                                }
                                wrequest = toMapped(u);
                        }
                        return wfetch.call(this, wrequest, args);
                };
        }
        let xopen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function (method, url, a, b, c) {
                if (isBanned(url)) {
                        return xopen.call(this, method, "about:blank", a, b, c);
                }
                return xopen.call(this, method, toMapped(url), a, b, c);
        };
        let wsocket = window.WebSocket;
        if (wsocket) {
                window.WebSocket = function (url, protocol) {
                        if (isBanned(url)) {
                                return new wsocket("about:blank", protocol);
                        }
                        return new wsocket(toMapped(url), protocol);
                };
        }
        document.addEventListener("submit", function (event) {
                let target = event.target;
                if (target && target.action && isBanned(target.action)) {
                        event.preventDefault();
                        return false;
                }
        }, true);
        self.setInterval(function () {
                let el = document.getElementsByClassName("session-authentication")[0];
                if (el && el.parentNode) {
                        el.remove();
                }
        }, 1000);
})();
EOF

[ -f banned.zon ] || cat > banned.zon << EOF 
.{
    "/login",
    "/signup",
    "/oauth",
    "/sso",
    "/authorize",
    "/authenticate",
    "/copilot*",
    "/features/copilot*",
    "/pricing",
    "/plan",
    "/plans",
    "/account",
    "/settings",
    "/checkout",
    "/billing",
    "/enterprise",
}
EOF

zig build --release=fast;
cp ./zig-out/bin/proxy ./;

echo "Configuration generated!";
echo "Please move the generated proxy.conf into your nginx configure folder and restart your nginx.";
echo "And setup the systemd script or runit script to atomatically start the proxy program.";
echo "NOTICE: You should keep config.zon and the proxy program in the same folder, and the banned.zon is accessible to the proxy program.";
echo "NOTICE: It is recommended to use absoulte path in config.zon for banned_zon entry."
echo "NOTICE: Every time you changed the banned.zon, please restart the proxy program.";
if [ "$EUID" -ne 0 ]; then
        echo "NOTICE: /var/cache/nginx/ghproxy (proxy_cache_path) must exist and be writable by nginx; run: sudo mkdir -p /var/cache/nginx/ghproxy && sudo chown nginx:nginx /var/cache/nginx/ghproxy (adjust owner to the nginx user).";
fi
