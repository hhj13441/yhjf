# 阅后即焚 (Burn After Reading)

一个简单、轻量级、安全的 PHP 应用，用于创建“阅后即焚”的秘密消息。


## ✨ 特性

* **一次性查看**：链接只能被访问一次，查看后消息即销毁。
* **数据库加密**：消息内容使用 `AES-256-CBC` 加密存储。
* **密码保护**：可以为消息设置可选的查看密码 (密码已哈希存储)。
* **自动过期**：可以设置消息的过期时间（1 到 720 小时）。
* **零依赖**：后端仅需 PHP 和 `sqlite3`、`openssl` 扩展（大多数 PHP 环境标配）。
* **轻量级**：前端使用 Tailwind CSS (CDN) 和 jQuery (CDN)。
* **安全设计**：包含 CSRF 保护和安全标头 (CSP, X-Frame-Options)。
* **主题切换按钮** ：🌙☀️保护你的视力。
  

## 🚀 5 分钟快速部署指南 

### 步骤 1: 上传文件

1.  下载本项目的所有文件 (点击 "Code" -> "Download ZIP")。
2.  解压 ZIP 文件。
3.  使用主机的 "文件管理器" 或 FTP 工具，将**解压后的所有文件**上传到网站的根目录 (例如 `public_html` 或 `www` 目录)。

    *应该上传的文件包括:*
    * `index.php` (主应用)
    * `check_requirements.php` (环境检查器)
    * `config.php.example` (配置模板)
    * `.htaccess` (Apache 服务器安全配置)
    * `nginx.conf.example` (Nginx 配置示例，如果需要)
    * `README.md` (说明文件)

### 步骤 2: 运行环境检查

1.  在浏览器中，访问刚刚上传的 `check_requirements.php` 文件。
    * 例如: `http://your-domain.com/check_requirements.php`
2.  **查看结果:**
    * **如果所有检查都显示 `✅` (绿色):** 恭喜！的服务器已准备就绪。请继续步骤 3。
    * **如果出现 `❌` (红色) 错误:** 请阅读错误旁的“修复建议”。最常见的问题是 "目录不可写"，可能需要将目录权限设置为 `755`。如果遇到困难，请联系的主机商并向他们展示该页面的错误信息。

### 步骤 3: 创建并编辑 `config.php`

1.  在的服务器文件管理器中，将 `config.php.example` **重命名** (或复制) 为 `config.php`。
2.  **编辑** `config.php` 文件。
3.  会看到一行写着:
    `$encrypt_key = '!!INSECURE_REPLACE_THIS_WITH_YOUR_OWN_64_CHAR_HEX_KEY!!';`
4.  需要生成一个 64 位的安全密钥来替换它。
    * **如果有 SSH 权限:** 登录服务器并运行 `php -r 'echo bin2hex(random_bytes(32));'`，复制输出的字符串。
    * **如果没有 SSH 权限:** 在本地的电脑上（如果安装了 PHP）运行上述命令，或者使用一个可信的在线密钥生成器 (搜索 "64 character hex generator")。
5.  将生成的密钥粘贴到引号内。它看起来应该像这样: `$encrypt_key = 'f8a5c3e6b...[此处是的密钥]...d9e1b2f4a7c0';`

    **注意**：密钥没有特殊符号
7.  保存并关闭 `config.php` 文件。

### 步骤 4: 完成！

恭喜！部署已完成。

1.  现在访问的域名 `http://your-domain.com`，应该能看到“阅后即焚”的主页了。
2.  **(重要)** 出于安全考虑，请删除服务器上的 `check_requirements.php` 文件。

---

## 🔒 安全配置

### Apache

如果使用的是 Apache 服务器 (大多数共享主机都是)，`.htaccess` 文件已经为配置好了。它会自动阻止他人访问的 `config.php` 和 `messages.db` 数据库文件。

### Nginx

如果使用的是 Nginx (VPS 用户)，**必须**手动配置服务器以阻止对敏感文件的访问。请参考 `nginx.conf.example` 文件中的 `location` 块。

### 强烈推荐：使用 HTTPS

本项目不包含 HTTPS (SSL 证书)。请**务必**在的主机控制面板 (例如 cPanel, Plesk) 或通过 Certbot 为域名启用 HTTPS。这将确保的秘密消息在传输过程中也是加密的。
