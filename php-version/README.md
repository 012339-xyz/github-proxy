# GitHub Proxy

由Deepseek老师编写的一个轻量、安全的 GitHub 反向代理，支持短格式访问和完整 URL 透传。

## 功能特性

- ✅ **短格式**: `域名/user/repo` → 自动补全为 `github.com/user/repo`
- ✅ **完整 URL**: `域名/https://github.com/...` → 原样代理
- ✅ **流式输出**: 大文件不爆内存，边收边发
- ✅ **MIME 正确**: Content-Type 实时透传，JS/CSS 不再报 MIME 错误
- ✅ **安全边界切割**: 只在 `>` 标签闭合处切割，绝不截断 URL
- ✅ **防套娃**: 已代理 URL 不会二次包装
- ✅ **登录拦截**: 自动检测并阻止 GitHub 登录跳转
- ✅ **前端拦截**: 注入 JS 重写 fetch / XHR / WebSocket / form
- ✅ **二进制透传**: 图片/字体/压缩包零修改直传

## 部署

### Apache

1. 上传 `index.php` 和 `.htaccess` 到网站根目录
2. 确保 Apache 开启 `mod_rewrite`
3. 确保 PHP 开启 cURL 扩展

### Nginx

1. 上传 `index.php` 到网站根目录
2. 参考 `nginx.conf.example` 配置
3. **关键**: 设置 `fastcgi_buffering off;` 以启用流式输出
4. 重载 Nginx: `nginx -s reload`

## 使用

```
https://yourdomain.com/microsoft/vscode
https://yourdomain.com/microsoft/vscode/tree/main
https://yourdomain.com/https://raw.githubusercontent.com/user/repo/main/file.txt
https://yourdomain.com/https://api.github.com/repos/microsoft/vscode
```

## 配置

编辑 `index.php` 顶部常量:

```php
define('AUTH_ENABLED', false);   // 设为 true 开启 token 验证
define('AUTH_TOKEN',   'changeme'); // 自定义 token
```

开启后访问需加 `?token=changeme` 参数。
