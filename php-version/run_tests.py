#!/usr/bin/env python3
"""GitHub Proxy v2.4 — Python 等价测试"""
import re
import sys

PROXY = "https://github.012339.xyz"

HOSTS = [
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
]
HOST_ALT = '|'.join(re.escape(h) for h in HOSTS)
HTML_PAT = rf'(?<![:/a-zA-Z0-9])(https?://)({HOST_ALT})(/[^"\'\s<>\\)]*)'
CSS_PAT  = r'url\(\s*["\']?(https?://[^"\'\s)]+)["\']?\s*\)'
JS_PAT   = rf'(["\'])(https?://({HOST_ALT})/[^"\']{{0,2000}})\1'

def rewrite_html(s):
    matches = list(re.finditer(HTML_PAT, s))
    result = s
    for m in reversed(matches):
        result = result[:m.start()] + PROXY + '/' + m.group(0) + result[m.end():]
    return result

def rewrite_css(s):
    def repl(m):
        from urllib.parse import urlparse
        url = m.group(1)
        host = urlparse(url).hostname or ''
        if any(host == h or host.endswith('.' + h) for h in HOSTS):
            return f'url("{PROXY}/{url}")'
        return m.group(0)
    return re.sub(CSS_PAT, repl, s, flags=re.I)

def rewrite_js(s):
    def repl(m):
        return m.group(1) + PROXY + '/' + m.group(2) + m.group(1)
    return re.sub(JS_PAT, repl, s)

def check(name, inp, should_contain, should_not_contain=None):
    result = rewrite_html(inp)
    if 'url(' in result.lower():
        result = rewrite_css(result)
    if '"https://api.' in inp or "'https://api." in inp:
        result = rewrite_js(result)

    print(f"── {name}")
    print(f"  In:  {inp[:100]}")
    print(f"  Out: {result[:130]}")

    ok = True
    for needle in should_contain:
        if needle not in result:
            print(f"  ❌ Missing: {needle}"); ok = False
    for needle in (should_not_contain or []):
        if needle and needle in result:
            print(f"  ❌ Should not contain: {needle}"); ok = False

    for m in re.finditer(r'ssets/', result):
        pos = m.start()
        if pos > 0 and result[pos - 1] != 'a':
            print(f"  ❌ REAL ssets/ truncation!"); ok = False

    if re.search(r'https://[^/\s"\']+/https://[^/\s"\']+/https://', result):
        print(f"  ❌ Triple https!"); ok = False
    if 'github.012339.xyz/https://github.012339.xyz' in result:
        print(f"  ❌ Double proxy!"); ok = False

    print(f"  {'✅ PASS' if ok else '❌ FAIL'}\n")
    return ok

# ═══ 测试用例 ═══
tests = [
    ("HTML script src",
     '<script src="https://github.githubassets.com/assets/main-abc123.js"></script>',
     ['https://github.012339.xyz/https://github.githubassets.com/assets/main-abc123.js"']),
    ("Avatar image",
     '<img src="https://avatars.githubusercontent.com/u/1?v=4" alt="a">',
     ['https://github.012339.xyz/https://avatars.githubusercontent.com/u/1?v=4"']),
    ("CSS url() double-quoted",
     'background: url("https://github.githubassets.com/images/bg.png");',
     ['url("https://github.012339.xyz/https://github.githubassets.com/images/bg.png")']),
    ("CSS url() unquoted",
     'src: url(https://github.githubassets.com/font.woff2);',
     ['url(https://github.012339.xyz/https://github.githubassets.com/font.woff2)']),
    ("Already proxied (no double wrap)",
     '<script src="https://github.012339.xyz/https://github.githubassets.com/assets/x.js"></script>',
     ['https://github.012339.xyz/https://github.githubassets.com/assets/x.js"'],
     ['github.012339.xyz/https://github.012339.xyz']),
    ("Relative path",
     '<script src="/assets/app.js"></script>',
     ['src="/assets/app.js"'], ['github.012339.xyz/assets/app.js']),
    ("External URL",
     '<a href="https://google.com/search">G</a>',
     ['href="https://google.com/search"'], ['github.012339.xyz']),
    ("JS double-quoted",
     'var url = "https://api.github.com/repos/foo/bar";',
     ['"https://github.012339.xyz/https://api.github.com/repos/foo/bar"']),
    ("JS single-quoted",
     "var u = 'https://raw.githubusercontent.com/foo/bar/main/README.md';",
     ["'https://github.012339.xyz/https://raw.githubusercontent.com/foo/bar/main/README.md'"]),
    ("Multiple URLs",
     '<html><head><link href="https://github.githubassets.com/a.css"><script src="https://github.githubassets.com/b.js"></script></head><body><img src="https://avatars.githubusercontent.com/u/1"></body></html>',
     ['href="https://github.012339.xyz/https://github.githubassets.com/a.css"',
      'src="https://github.012339.xyz/https://github.githubassets.com/b.js"',
      'src="https://github.012339.xyz/https://avatars.githubusercontent.com/u/1"']),
    ("JSON API",
     '{"url":"https://api.github.com/repos/microsoft/vscode","html_url":"https://github.com/microsoft/vscode"}',
     ['"url":"https://github.012339.xyz/https://api.github.com/repos/microsoft/vscode"',
      '"html_url":"https://github.012339.xyz/https://github.com/microsoft/vscode"']),
    ("Link with integrity",
     '<link rel="stylesheet" href="https://github.githubassets.com/primer.css" integrity="sha384-abc">',
     ['href="https://github.012339.xyz/https://github.githubassets.com/primer.css"']),
    ("Meta og:image",
     '<meta property="og:image" content="https://github.githubassets.com/og.png">',
     ['content="https://github.012339.xyz/https://github.githubassets.com/og.png"']),
]

print("═" * 60)
print("  GitHub Proxy v2.4 — URL Rewrite Tests")
print("═" * 60 + "\n")

passed = failed = 0
for name, inp, sc, *rest in tests:
    snc = rest[0] if rest else None
    ok = check(name, inp, sc, snc)
    passed += ok; failed += not ok

# ═══ 流式模拟 ═══
print("═" * 60)
print("  Streaming (30-byte chunks)")
print("═" * 60 + "\n")

full = '<script src="https://github.githubassets.com/assets/411d7h3ji9l0.js"></script><img src="https://avatars.githubusercontent.com/u/1?v=4">'
buf = ""; outputs = []
for i, c in enumerate([full[j:j+30] for j in range(0, len(full), 30)]):
    buf += c
    lg = buf.rfind('>')
    if lg != -1 and lg >= 10:
        outputs.append(rewrite_html(buf[:lg+1]))
        buf = buf[lg+1:]
        print(f"  Chunk {i+1}: flush ({len(outputs[-1])}B)")
    else:
        print(f"  Chunk {i+1}: keep")

if buf:
    outputs.append(rewrite_html(buf))
    print(f"  Final: flush ({len(outputs[-1])}B)")

final = "".join(outputs)
print(f"\n  Result: {final}")
sok = True
if '411d7h3ji9l0.js' not in final: print("  ❌ JS filename lost!"); sok=False
if 'avatars.githubusercontent.com/u/1?v=4' not in final: print("  ❌ Avatar lost!"); sok=False
for m in re.finditer(r'ssets/', final):
    if m.start()>0 and final[m.start()-1]!='a': print("  ❌ Truncation!"); sok=False
if sok: print("  ✅ STREAMING PASSED"); passed+=1
else: failed+=1

# ═══ 边界 ═══
print("\n" + "═" * 60)
print("  Edge Cases")
print("═" * 60 + "\n")

edges = [
    ('URL紧接/>无空格',
     '<script src="https://github.githubassets.com/a.js"/></script>',
     'https://github.012339.xyz/https://github.githubassets.com/a.js"'),
    ('URL含&参数',
     '<img src="https://avatars.githubusercontent.com/u/1?v=4&s=80">',
     'https://github.012339.xyz/https://avatars.githubusercontent.com/u/1?v=4&s=80"'),
]
for name, inp, exp in edges:
    r = rewrite_html(inp)
    ok = exp in r
    print(f"  {'✅' if ok else '❌'} {name}: {r[:90]}")
    passed += ok; failed += not ok

print("\n" + "═" * 60)
print(f"  FINAL: {passed} passed, {failed} failed")
print("═" * 60)
print("\n  🎉 ALL TESTS PASSED!" if failed==0 else f"  ⚠️ {failed} failed")
sys.exit(0 if failed==0 else 1)
