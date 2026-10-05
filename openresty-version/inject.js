(function () {
  "use strict";

  var M = {
    "github.com": "github.yourdomain.com",
    "api.github.com": "api.github.yourdomain.com",
    "gist.github.com": "gist.github.yourdomain.com",
    "github.githubassets.com": "assets.github.yourdomain.com",
    "raw.githubusercontent.com": "raw.github.yourdomain.com",
    "avatars.githubusercontent.com": "avatars.github.yourdomain.com",
    "objects.githubusercontent.com": "objects.github.yourdomain.com",
    "user-images.githubusercontent.com": "user-images.github.yourdomain.com"
  };

  function map(u) {
    if (typeof u !== "string") return u;
    try {
      var a = new URL(u, location.href);
      if (M[a.hostname]) {
        a.hostname = M[a.hostname];
        return a.href;
      }
      for (var k in M) {
        if (!Object.prototype.hasOwnProperty.call(M, k)) continue;
        var s = "." + k;
        if (a.hostname.length > s.length && a.hostname.slice(-s.length) === s) {
          a.hostname = M[k];
          return a.href;
        }
      }
    } catch (e) { }
    return u;
  }
  var of = window.fetch;
  if (of) {
    window.fetch = function (input, init) {
      try {
        if (typeof input === "string") {
          input = map(input);
        } else if (input && input.url && typeof Request !== "undefined" && input instanceof Request) {
          var nu = map(input.url);
          if (nu !== input.url) input = new Request(nu, input);
        }
      } catch (e) { }
      return of.call(this, input, init);
    };
  }

  var oo = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (m, u, a, b, c) {
    return oo.call(this, m, map(u), a, b, c);
  };

  var ows = window.WebSocket;
  if (ows) {
    window.WebSocket = function (u, p) { return new ows(map(u), p); };
  }

  var oes = window.EventSource;
  if (oes) {
    window.EventSource = function (u, c) { return new oes(map(u), c); };
  }
  document.addEventListener("DOMContentLoaded", function () {
    var nodes = document.querySelectorAll("a[href],img[src],script[src],link[href],source[src],iframe[src]");
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      var attr = n.hasAttribute("href") ? "href" : "src";
      var cur = n.getAttribute(attr);
      var mapped = map(cur);
      if (mapped !== cur) n.setAttribute(attr, mapped);
    }
  });
})();
