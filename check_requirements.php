<?php
// check_requirements.php
// 帮助用户检查他们的服务器环境是否满足运行此应用所需的所有要求。

$all_ok = true;
$results = [];

// 1. 检查 PHP 版本
if (version_compare(PHP_VERSION, '7.2.0', '>=')) {
    $results[] = [
        'status' => 'ok',
        'title' => 'PHP 版本',
        'message' => '版本: ' . PHP_VERSION . ' (满足 >= 7.2.0)'
    ];
} else {
    $all_ok = false;
    $results[] = [
        'status' => 'error',
        'title' => 'PHP 版本',
        'message' => '版本: ' . PHP_VERSION . ' (不满足)',
        'fix' => '要求 PHP 7.2.0 或更高版本。请联系您的主机商升级 PHP。'
    ];
}

// 2. 检查 SQLite3 扩展
if (extension_loaded('sqlite3')) {
    $results[] = [
        'status' => 'ok',
        'title' => 'SQLite3 扩展',
        'message' => '已加载 (用于数据库)'
    ];
} else {
    $all_ok = false;
    $results[] = [
        'status' => 'error',
        'title' => 'SQLite3 扩展',
        'message' => '未加载',
        'fix' => '这是必需的扩展。请在您的 `php.ini` 中启用 `extension=sqlite3`，或联系主机商开启。'
    ];
}

// 3. 检查 OpenSSL 扩展
if (extension_loaded('openssl')) {
    $results[] = [
        'status' => 'ok',
        'title' => 'OpenSSL 扩展',
        'message' => '已加载 (用于加密消息)'
    ];
} else {
    $all_ok = false;
    $results[] = [
        'status' => 'error',
        'title' => 'OpenSSL 扩展',
        'message' => '未加载',
        'fix' => '这是必需的扩展。请在您的 `php.ini` 中启用 `extension=openssl`，或联系主机商开启。'
    ];
}

// 4. 检查 mbstring 扩展
if (extension_loaded('mbstring')) {
    $results[] = [
        'status' => 'ok',
        'title' => 'mbstring 扩展',
        'message' => '已加载 (推荐)'
    ];
} else {
    // 这不是一个致命错误，但最好有
    $results[] = [
        'status' => 'warning',
        'title' => 'mbstring 扩展',
        'message' => '未加载',
        'fix' => '这是推荐的扩展，用于更好地处理多字节字符。建议在 `php.ini` 中启用 `extension=mbstring`。'
    ];
}

// 5. 检查目录可写性
$dir = __DIR__;
if (is_writable($dir)) {
    $results[] = [
        'status' => 'ok',
        'title' => '目录可写',
        'message' => '当前目录 (' . $dir . ') 可写。',
        'fix' => '应用将能够在此目录中创建 `messages.db` 和 `php_errors.log`。'
    ];
} else {
    $all_ok = false;
    $results[] = [
        'status' => 'error',
        'title' => '目录可写',
        'message' => '当前目录 (' . $dir . ') 不可写。',
        'fix' => 'Web 服务器没有此目录的写入权限。请设置权限 (例如 `chmod 755 .` 或 `chown www-data .`)。'
    ];
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>阅后即焚 - 服务器环境检查</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; padding: 20px; max-width: 700px; margin: 20px auto; background: #f9f9f9; color: #333; }
        .container { background: #fff; border-radius: 8px; padding: 20px 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h1 { text-align: center; color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .check { margin-bottom: 15px; padding: 15px; border-radius: 5px; border: 1px solid #ddd; }
        .check-ok { background: #e6f7ed; border-color: #b7ebc9; }
        .check-warning { background: #fffbe6; border-color: #ffe58f; }
        .check-error { background: #fff1f0; border-color: #ffccc7; }
        .title { font-weight: bold; font-size: 1.1em; margin-bottom: 5px; }
        .ok .title { color: #1f7c3e; }
        .warning .title { color: #d48806; }
        .error .title { color: #a8071a; }
        .symbol { font-weight: bold; font-size: 1.2em; margin-right: 10px; }
        .ok { color: #52c41a; }
        .warning { color: #faad14; }
        .error { color: #f5222d; }
        .message { font-size: 0.95em; color: #444; }
        .fix { font-size: 0.9em; color: #555; margin-top: 8px; background: rgba(0,0,0,0.02); padding: 5px 8px; border-radius: 4px; }
        .summary { text-align: center; font-size: 1.3em; font-weight: bold; margin-top: 25px; padding: 15px; border-radius: 5px; }
        .summary-ok { background: #e6f7ed; color: #1f7c3e; }
        .summary-error { background: #fff1f0; color: #a8071a; }
        .footer { text-align: center; margin-top: 20px; font-size: 0.9em; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <h1>阅后即焚 - 服务器环境检查</h1>
        <p style="text-align:center; color: #666;">此脚本将检查您的服务器是否满足运行所需的所有要求。</p>

        <?php foreach ($results as $result): ?>
            <div class="check check-<?= $result['status'] ?>">
                <div class="title <?= $result['status'] ?>">
                    <span class="symbol">
                        <?= $result['status'] === 'ok' ? '✅' : ($result['status'] === 'warning' ? '⚠️' : '❌') ?>
                    </span>
                    <?= htmlspecialchars($result['title']) ?>
                </div>
                <div class="message"><?= htmlspecialchars($result['message']) ?></div>
                <?php if (isset($result['fix'])): ?>
                    <div class="fix"><strong>修复建议:</strong> <?= htmlspecialchars($result['fix']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($all_ok): ?>
            <div class="summary summary-ok">🎉 恭喜！您的服务器环境已准备就绪。</div>
            <p class="footer">您可以安全地删除此 `check_requirements.php` 文件，然后继续执行 `README.md` 中的下一步。</p>
        <?php else: ?>
            <div class="summary summary-error">😥 您的服务器环境未完全准备好。</div>
            <p class="footer">请修复上面标记为 ❌ 的项目，然后再次运行此检查。</div>
        <?php endif; ?>
    </div>
</body>
</html>