# GitHub Proxy v2.3

由Deekseek老师编写的
轻量、安全的 GitHub 反向代理，支持短链和完整 URL 两种访问方式。

## 访问格式

| 格式 | 示例 |
|---|---|
| 短格式 | `https://yourdomain.com/microsoft/vscode` |
| 短格式 + 子路径 | `https://yourdomain.com/microsoft/vscode/tree/main` |
| 完整 URL | `https://yourdomain.com/https://github.com/microsoft/vscode` |
| Raw 文件 | `https://yourdomain.com/https://raw.githubusercontent.com/user/repo/main/file.txt` |
| API | `https://yourdomain.com/https://api.github.com/repos/microsoft/vscode` |

## 部署

### Apache
将 `index.php` 和 `.htaccess` 放在网站根目录。

### Nginx
将 `index.php` 放在网站根目录，参考 `nginx.conf.example` 配置。

### 配置项 (index.php 顶部)
- `$AUTH_ENABLED` — 是否开启密码保护 (默认 false)
- `$AUTH_PASSWORD` — 访问密码
- `$ALLOWED_HOSTS` — 允许代理的目标域名白名单

## 特性

- ✅ 流式输出 (不缓冲整个响应，支持大文件)
- ✅ Content-Type 透传 (MIME 类型正确，JS/CSS/图片不混淆)
- ✅ URL 重写: 属性 / CSS url() / JS 字符串 / srcset
- ✅ 安全边界截断 (流式场景下不会破坏 HTML 标签)
- ✅ JS 运行时重写 (fetch / XHR / WebSocket / EventSource)
- ✅ 拦截 GitHub 登录重定向
- ✅ 短格式 `/user/repo` 自动补全为 `github.com`
- ✅ 相对路径 (./ ../ /path) 正确处理

## 要求

- PHP 7.4+
- cURL 扩展
- Apache mod_rewrite 或 Nginx rewrite
