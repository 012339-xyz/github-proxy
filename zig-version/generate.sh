#!/bin/bash

set -euo pipefail;

if [ -z $(which nginx) ]; then 
        echo "Cannot find nginx"
        exit -1;
fi

if [ -z $(which zig) ]; then 
        echo "Cannot find zig"
        exit -1;
fi

read -p "main domain: " main_domain;
read -p "listen port: " listen_port;
read -p "upstream port: (9002)" upstream_port
upstream_port="${upstream_port:-9002}";
read -p "assets domain: (assets.$main_domain)" assets_domain
assets_domain="${assets_domain:-assets.$main_domain}";
read -p "raw domain: (raw.$main_domain)" raw_domain
raw_domain="${raw_domain:-raw.$main_domain}";
read -p "gist domain: (gist.$main_domain)" gist_domain
gist_domain="${gist_domain:-gist.$main_domain}";
read -p "objects domain: (objects.$main_domain)" objects_domain
objects_domain="${objects_domain:-objects.$main_domain}";
read -p "avatars domain: (avatars.$main_domain)" avatars_domain
avatars_domain="${avatars_domain:-avatars.$main_domain}";
read -p "user-image domain: (user-images.$main_domain)" user_images_domain
user_images_domain="${user_images_domain:-user-images.$main_domain}";

cat >> proxy.conf << EOF 
proxy_cache_path /tmp/nginx_cache levels=1:2 keys_zone=proxy_cache:10m inactive=60m use_temp_path=off;
proxy_buffer_size 16k;
proxy_busy_buffers_size 64k;
proxy_buffers 4 32k;
server_tokens off;
server {
	listen [::]:443 ssl;
        http2 on;
        server_name $main_domain;
	ssl_certificate please/replace/me.pem;
	ssl_certificate_key please/replace/me.key;
	location / {
		proxy_pass http://127.0.0.1:$listen_port/;
		proxy_cache proxy_cache;
		proxy_cache_key \$uri\$is_args\$args;
		proxy_cache_valid 200 302 2m;
		proxy_cache_valid 404 1m;
		proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
		proxy_buffering on;
		proxy_set_header Accept-Encoding "";
		add_header content-security-policy "default-src 'none'; base-uri 'self'; child-src $main_domain $assets_domain; connect-src $main_domain $raw_domain $gist_domain $assets_domain $objects_domain github-cloud.s3.amazonaws.com; img-src $main_domain $avatars_domain $assets_domain; script-src $assets_domain; style-src 'unsafe-inline' $assets_domain; upgrade-insecure-requests";
	}

        	location = / {
		return 200;
	}
	location /login {
		return 404;
	}
	location /signup {
		return 404;
	}
	location /oauth {
		return 404;
	}
	location /sso {
		return 404;
	}
	location /sessions {
		return 404;
	}
	location /authorize {
		return 404;
	}
	location /authenticate {
		return 404;
	}
	location /join {
		return 404;
	}
	location /copilot {
		return 404;
	}
	location /enterprise {
		return 404;
	}
	location /pricing {
		return 404;
	}
	location /plan {
		return 404;
	}
	location /account {
		return 404;
	}
	location /settings {
		return 404;
	}
}

server {
	listen 127.0.0.1:$upstream_port;
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
EOF

cat >> config.zon << EOF
.{
    // https://yourdomain.com
    .main = "https://$main_domain",
    .assets = "https://$assets_domain",
    .raw = "https://$raw_domain",
    .objects = "https://$objects_domain",
    .avatars = "https://$avatars_domain",
    .gist = "https://$gist_domain",
    .user_images = "https://$user_images_domain",
    .banned_zon = "./banned.zon",

    // Proxy https://github.com
    // Format http://127.0.0.1:port
    .upstream = "http://127.0.0.1:$upstream_port",
    .port = $listen_port,
    .inject_js = true,
    .inject_js_path = "./inject.js",
}
EOF

cat >> inject.js << EOF
(function () {
        let mapping = {
                "github.com": "$main_domain",
                "raw.githubusercontent.com": "$raw_domain",
                "github.githubassets.com": "$assets_domain",
                "objects.githubusercontent.com": "$objects_domain",
                "avatars.githubusercontent.com": "$avatars_domain",
                "gist.github.com": "$gist_domain",
                "user-images.githubusercontent.com": "$user_images_domain",
        };
        let banned_path = [
                // Format: /login
                "/",
                "/login",
                "/signup",
                "/oauth",
                "/sso",
                "/authorize",
                "/authenticate",
                "/join",
                "/copilot",
                "/enterprise",
                "/pricing",
                "/plan",
                "/account",
                "/settings/profile",
                "/settings/billing",
        ];
        function isBanned(url) {
                let parsed_url = URL.parse(url);
                var path = null;
                if (parsed_url != null) {
                        path = parsed_url.pathname;
                } else {
                        path = url;
                }
                if (banned_path.includes(path)) {
                        return true;
                } else {
                        return false;
                }
        }
        function toMapped(url) {
                let parsed_url = URL.parse(url);
                var host = null;
                if (parsed_url != null) {
                        host = parsed_url.host;
                }
                if (host != null) {
                        if (mapping[host] != null) {
                                parsed_url.hash = mapping[host];
                        }
                        return parsed_url.toString();
                } else {
                        return url;
                }
        }
        document.addEventListener("DOMContentLoaded", function () {
                let hrefs = document.querySelectorAll("a[href]");
                for (i = 0; i < hrefs.length; i++) {
                        let href = hrefs[i].getAttribute("href");
                        if (!href) {
                                continue;
                        }
                        if (isBanned(href)) {
                                hrefs[i].setAttribute("href", "about:blank");
                                hrefs[i].setAttribute("onclick", "return false");
                                hrefs[i].computedStyleMap.opacity = "0.4";
                                hrefs[i].computedStyleMap.pointerEvents = "none";
                        } else {
                                hrefs[i].setAttribute("href", toMapped(href));
                        }
                        let actions = document.querySelectorAll("form[action]");
                        for (i = 0; i < actions.length; i++) {
                                let action = actions[i].getAttribute("action");
                                if (action && isBanned(action)) {
                                        action[i].setAttribute("action", "about:blank");
                                }
                        }
                }
                let wfetch = window.fetch;
                if (wfetch) {
                        window.fetch = function (wrequest, args) {
                                if (typeof (wrequest) == "string") {
                                        if (isBanned(wrequest)) {
                                                wrequest = "about:blank";
                                        }
                                        wrequest = toMapped(wrequest);
                                } else if (wrequest && path.url) {
                                        if (isBanned(wrequest.url)) {
                                                wrequest.url = "about:blank";
                                        }
                                        wrequest.url = toMapped(wrequest.url);
                                }
                                return wfetch.call(this, wrequest, args);
                        }
                }

                let xopen = XMLHttpRequest.prototype.open;
                XMLHttpRequest.prototype.open = function (method, url, a, b, c) {
                        if (isBanned(url)) {
                                url = "about:blank";
                        }
                        url = toMapped(url);
                        return xopen.call(this, method, url, a, b, c);
                }
                let wsocket = window.WebSocket;
                if (wsocket) {
                        window.WebSocket = function (url, protocol) {
                                if (isBanned(url)) {
                                        url = "about:blank";
                                }
                                return new wsocket(toMapped(url), protocol);
                        }
                }
                document.addEventListener("submit", function (event) {
                        let target = event.target;
                        if (target && target.action && isBanned(target.action)) {
                                event.preventDefault();
                                return false;
                        }
                }, true);
                window.addEventListener("beforeunload", function (event) {
                        if (isBanned(this.location.href)) {
                                event.preventDefault();
                                return false;
                        }
                })
        });
})();
EOF

cat >> banned.zon << EOF 
.{
    // Format: /login
    "/",
    "/login",
    "/signup",
    "/oauth",
    "/sso",
    "/authorize",
    "/authenticate",
    "/join",
    "/copilot",
    "/enterprise",
    "/pricing",
    "/plan",
    "/account",
    "/settings/profile",
    "/settings/billing",
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
