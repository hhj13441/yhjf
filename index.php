<?php
// 确保在文件最开头启动会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 包含配置文件
require_once 'config.php';

// --- 安全标头 ---
// 防止MIME类型嗅探
header('X-Content-Type-Options: nosniff');
// 防止点击劫持
header('X-Frame-Options: DENY');
// 内容安全策略 (CSP) - 仅允许从 'self' 和特定 CDN 加载资源
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com https://unpkg.com https://code.jquery.com; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com;");

// 设置默认时区
date_default_timezone_set('Asia/Shanghai');

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0); // 不向用户显示错误
ini_set('log_errors', 1); // 记录错误
ini_set('error_log', __DIR__ . '/php_errors.log'); // 错误日志文件

// 初始化数据库
function initDatabase($dbPath) {
    try {
        $db = new SQLite3($dbPath);
        $db->enableExceptions(true);
        
        // 创建消息表
        $db->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY,
                content TEXT NOT NULL,
                password TEXT,
                created_at INTEGER NOT NULL,
                expire_at INTEGER NOT NULL,
                viewed INTEGER DEFAULT 0
            )
        ");
        // 创建索引
        $db->exec("CREATE INDEX IF NOT EXISTS idx_expire_at ON messages(expire_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_viewed ON messages(viewed)");
        
        return $db;
    } catch (Exception $e) {
        error_log('Database initialization error: ' . $e->getMessage());
        throw new Exception('无法初始化数据库');
    }
}

// 获取数据库连接
function getDatabase() {
    global $CONFIG;
    static $db = null;
    if ($db === null) {
        $db = initDatabase($CONFIG['db_path']);
    }
    return $db;
}

// 统一JSON响应函数
function sendJsonResponse($success, $message = '', $data = []) {
    header('Content-Type: application/json');
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 加密函数
function encrypt($data, $key) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

// 解密函数
function decrypt($data, $key) {
    $data = base64_decode($data);
    $iv = substr($data, 0, openssl_cipher_iv_length('aes-256-cbc'));
    $data = substr($data, openssl_cipher_iv_length('aes-256-cbc'));
    return openssl_decrypt($data, 'aes-256-cbc', $key, 0, $iv);
}

// 获取当前URL
function getCurrentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    return $protocol . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
}

// 清理过期消息
function cleanupOldMessages() {
    global $CONFIG;
    if (rand(1, 100) > $CONFIG['cleanup_chance']) {
        return;
    }
    
    try {
        $db = getDatabase();
        $stmt = $db->prepare("DELETE FROM messages WHERE expire_at < ?");
        $stmt->bindValue(1, time(), SQLITE3_INTEGER);
        $stmt->execute();
        
        // 清理已查看的消息（超过1小时）
        $stmt = $db->prepare("DELETE FROM messages WHERE viewed = 1 AND created_at < ?");
        $stmt->bindValue(1, time() - 3600, SQLITE3_INTEGER);
        $stmt->execute();
        
    } catch (Exception $e) {
        error_log('Cleanup error: ' . $e->getMessage());
    }
}

/**
 * [重构] 创建并存储消息
 */
function createMessage($content, $password, $expire_hours) {
    global $CONFIG;

    if (empty($content)) {
        throw new Exception('消息内容不能为空');
    }

    if (strlen($content) > $CONFIG['max_size']) {
        throw new Exception('消息过长，最多允许' . $CONFIG['max_size'] . '个字符');
    }

    // 处理特殊字符
    $content = htmlspecialchars_decode($content);
    
    // [优化] 使用加密安全的随机字节生成ID
    $id = bin2hex(random_bytes(16));
    
    // 准备存储数据
    $encryptedContent = encrypt($content, $CONFIG['encrypt_key']);
    $createdAt = time();
    $expireAt = $createdAt + ($expire_hours * 3600);
    $hashedPassword = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
    
    // 存储消息到数据库
    $db = getDatabase();
    $stmt = $db->prepare("
        INSERT INTO messages (id, content, password, created_at, expire_at, viewed)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    $stmt->bindValue(1, $id, SQLITE3_TEXT);
    $stmt->bindValue(2, $encryptedContent, SQLITE3_TEXT);
    $stmt->bindValue(3, $hashedPassword, SQLITE3_TEXT);
    $stmt->bindValue(4, $createdAt, SQLITE3_INTEGER);
    $stmt->bindValue(5, $expireAt, SQLITE3_INTEGER);
    
    if (!$stmt->execute()) {
        throw new Exception('无法保存消息到数据库');
    }

    // 生成访问链接
    $url = getCurrentUrl() . '?id=' . $id;

    return [
        'url' => $url,
        'expire_time' => date('Y-m-d H:i:s', $expireAt),
        'generation_time' => date('Y-m-d H:i:s', $createdAt)
    ];
}

// [重构] 验证 CSRF 令牌
function verifyCsrfToken() {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new Exception('无效的请求，请刷新页面重试');
    }
    // 令牌用过一次后就销毁
    unset($_SESSION['csrf_token']);
}


// --- 请求处理逻辑 ---

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

    try {
        // [安全] 验证 CSRF 令牌
        verifyCsrfToken();

        // 获取并清理输入
        $content = trim($_POST['content'] ?? '');
        $password = trim($_POST['msg_password'] ?? '');
        $expire_hours = max(1, min(720, intval($_POST['expire_hours'] ?? 24)));

        // [重构] 调用核心创建函数
        $resultData = createMessage($content, $password, $expire_hours);

        // 返回成功响应
        sendJsonResponse(true, '安全链接已生成', $resultData);

    } catch (Exception $e) {
        error_log('AJAX Error: ' . $e->getMessage());
        // [安全] 重新生成 CSRF 令牌
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $data = ['new_token' => $_SESSION['csrf_token']];
        sendJsonResponse(false, '服务器内部错误: ' . $e->getMessage(), $data);
    }
}

// 处理消息查看请求
if (isset($_GET['id'])) {
    handleViewRequest();
    exit;
}

// 处理表单提交 (非AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePostRequest();
    exit;
}

// 显示主页面
displayHomePage();


// --- 核心功能函数 ---

// 处理消息查看请求
function handleViewRequest() {
    global $CONFIG;

    // [优化] 安全过滤ID (适配 32 位的 hex ID)
    $id = preg_replace('/[^a-f0-9]/', '', $_GET['id']);
    if (strlen($id) !== 32) {
        displayError('无效的消息ID');
        return;
    }

    try {
        $db = getDatabase();
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND viewed = 0");
        $stmt->bindValue(1, $id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $message = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$message) {
            displayError('消息不存在或已被查看');
            return;
        }

        // 检查是否过期
        if ($message['expire_at'] < time()) {
            // 删除过期消息
            $stmt = $db->prepare("DELETE FROM messages WHERE id = ?");
            $stmt->bindValue(1, $id, SQLITE3_TEXT);
            $stmt->execute();
            
            displayError('消息已过期');
            return;
        }

        // 检查密码
        if (!empty($message['password']) && !isset($_POST['password'])) {
            displayPasswordForm($id);
            return;
        }

        if (!empty($message['password']) && !password_verify($_POST['password'], $message['password'])) {
            displayError('密码错误');
            displayPasswordForm($id);
            return;
        }

        // 解密内容
        $content = decrypt($message['content'], $CONFIG['encrypt_key']);
        
        // 标记消息为已查看 (!!! 关键：在显示前标记)
        $stmt = $db->prepare("UPDATE messages SET viewed = 1 WHERE id = ?");
        $stmt->bindValue(1, $id, SQLITE3_TEXT);
        $stmt->execute();

        // 显示消息
        displayMessage($content);
        
        // 随机清理旧消息
        cleanupOldMessages();

    } catch (Exception $e) {
        error_log('View message error: ' . $e->getMessage());
        displayError('服务器内部错误');
    }
}

// 处理表单提交（非AJAX）
function handlePostRequest() {
    try {
        // [安全] 验证 CSRF 令牌
        verifyCsrfToken();

        $content = trim($_POST['content'] ?? '');
        $password = trim($_POST['msg_password'] ?? '');
        $expire_hours = max(1, min(720, intval($_POST['expire_hours'] ?? 24)));
        
        // [重构] 调用核心创建函数
        $resultData = createMessage($content, $password, $expire_hours);

        // 显示成功页面 (使用 PRG 模式)
        displaySuccess($resultData);

    } catch (Exception $e) {
        error_log('Post request error: ' . $e->getMessage());
        displayError('服务器内部错误: ' . $e->getMessage());
    }
}

// 显示成功页面 (PRG: Post-Redirect-Get)
function displaySuccess($resultData) {
    // 将结果存入 session flash message
    $_SESSION['result'] = [
        'success' => true,
        'message' => '安全链接已生成',
        'data' => $resultData
    ];
    // 重定向回主页
    header('Location: ' . getCurrentUrl());
    exit;
}


// --- 视图函数 (HTML) ---

// 显示主页面
function displayHomePage() {
    // [安全] 生成 CSRF 令牌
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    // [修复] 检查是否有来自非 AJAX 提交的结果
    $flashResult = null;
    if (isset($_SESSION['result'])) {
        $flashResult = $_SESSION['result'];
        unset($_SESSION['result']);
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>阅后即焚</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://unpkg.com/jquery@3.7.1/dist/jquery.min.js"></script>
        <style>
            .status-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
            .status-valid { background-color: #10B981; }
            .status-expired { background-color: #EF4444; }
            .toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); padding: 12px 20px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; animation: fadeInOut 3s forwards; }
            .toast-success { background-color: #10B981; color: white; }
            .toast-error { background-color: #EF4444; color: white; }
            @keyframes fadeInOut {
                0% { opacity: 0; transform: translateX(-50%) translateY(-20px); }
                10% { opacity: 1; transform: translateX(-50%) translateY(0); }
                90% { opacity: 1; transform: translateX(-50%) translateY(0); }
                100% { opacity: 0; transform: translateX(-50%) translateY(-20px); }
            }
            .fade-in { animation: fadeIn 0.5s; }
            @keyframes fadeIn {
                from { opacity: 0; transform: scale(0.95); }
                to { opacity: 1; transform: scale(1); }
            }
            .copied { background-color: #bbf7d0 !important; transition: background 0.3s; }
            .url-display { word-break: break-all; overflow-wrap: break-word; white-space: pre-wrap; padding: 8px; font-size: 14px; line-height: 1.4; }
        </style>
    </head>
    <body class="bg-gray-100 min-h-screen font-sans text-gray-800 py-4 px-4 sm:px-6">
        <div class="max-w-md mx-auto">
            <div class="bg-white rounded-lg shadow p-4 mb-4">
                <h1 class="text-lg font-semibold text-center mb-4">阅后即焚</h1>
                
                <form id="message-form" class="space-y-3" method="POST">
                    <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">
                            消息内容 <span class="text-red-500">*</span>
                        </label>
                        <textarea 
                            name="content" 
                            id="message_content"
                            class="w-full px-3 py-2 border rounded-md focus:ring-1 focus:ring-green-500 focus:border-green-500"
                            placeholder="输入您要分享的秘密消息"
                            required
                            rows="5"
                        ></textarea>
                        <p class="text-xs text-gray-500 mt-1">最多允许<?= $GLOBALS['CONFIG']['max_size'] ?>个字符</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">
                            过期时间(小时) <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            name="expire_hours" 
                            id="expire_hours"
                            class="w-full px-3 py-2 border rounded-md focus:ring-1 focus:ring-green-500 focus:border-green-500"
                            value="24"
                            min="1"
                            max="720"
                            required
                        >
                        <p class="text-xs text-gray-500 mt-1">范围：1-720小时（30天）</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">
                            密码保护(可选)
                        </label>
                        <input 
                            type="password" 
                            name="msg_password" 
                            id="msg_password"
                            class="w-full px-3 py-2 border rounded-md focus:ring-1 focus:ring-green-500 focus:border-green-500"
                            placeholder="设置查看密码"
                        >
                    </div>
                    <button 
                        type="submit" 
                        id="submit-btn"
                        class="w-full bg-green-500 hover:bg-green-600 text-white py-2 px-4 rounded-md flex items-center justify-center transition-colors"
                    >
                        <div id="button-content">
                            生成安全链接
                        </div>
                    </button>
                </form>
            </div>
            
            <div id="result-card" class="bg-white rounded-lg shadow p-4 hidden">
                <div class="mb-4">
                    <p class="text-xs text-gray-500 text-center mb-1">安全链接</p>
                    <div class="bg-gray-50 p-2 rounded-md">
                        <div 
                            onclick="copyToClipboard(this, '安全链接')"
                            id="message-url"
                            class="font-mono text-center cursor-pointer hover:bg-gray-100 transition-colors url-display"
                        >
                            请先输入消息内容
                        </div>
                    </div>
                    <p class="text-xs text-center mt-1 text-green-500">
                        <span class="status-dot status-valid"></span>
                        有效期至: <span id="expiration-date"></span> UTC+8
                    </p>
                </div>
                <div id="generation-time" class="text-xs text-gray-500 mt-3 text-center">
                    生成于: <span id="generation-time-value"></span> UTC+8
                </div>
            </div>
            
            <div class="text-center text-gray-500 text-xs mt-4">
                <p>阅后即焚 &copy; <?= date('Y') ?></p>
                <p class="mt-1"><?= $_SERVER['HTTP_HOST'] ?></p>
            </div>
        </div>

        <script>
            // 显示消息提示
            function showToast(message, isSuccess = true) {
                const toast = $('<div>').addClass(`toast ${isSuccess ? 'toast-success' : 'toast-error'}`)
                                    .text(message);
                $('body').append(toast);
                setTimeout(() => { toast.remove(); }, 3000);
            }

            // 复制到剪贴板并高亮
            function copyToClipboard(element, type) {
                const $el = $(element);
                const text = $el.text().trim();
                if (text.includes('请先输入')) return;
                navigator.clipboard.writeText(text).then(() => {
                    showToast(`${type}已复制`);
                    $el.addClass('copied');
                    setTimeout(() => $el.removeClass('copied'), 600);
                }).catch(err => {
                    showToast(`复制失败: ${err}`, false);
                });
            }
            
            // [重构] 显示结果卡片
            function displayResult(data, message) {
                 $('#result-card').removeClass('hidden').addClass('fade-in');
                 setTimeout(() => $('#result-card').removeClass('fade-in'), 600);
                 $('#message-url').text(data.url);
                 $('#expiration-date').text(data.expire_time);
                 $('#generation-time-value').text(data.generation_time);
                 showToast(message);
            }
            
            // [重构] 更新 CSRF 令牌
            function updateCsrfToken(newToken) {
                if (newToken) {
                    $('#csrf_token').val(newToken);
                }
            }

            // 处理表单提交
            $('#message-form').on('submit', function(e) {
                // 仅在 JS 启用时阻止默认提交（即启用 AJAX）
                e.preventDefault();
                
                const $form = $(this);
                const $submitBtn = $('#submit-btn');
                
                $submitBtn.prop('disabled', true).addClass('opacity-60 cursor-not-allowed');
                $submitBtn.find('#button-content').html(`
                    <svg class="animate-spin h-5 w-5 mr-2 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>处理中...
                `);
                
                $.ajax({
                    url: '', // 提交到当前页面
                    method: 'POST',
                    data: $form.serialize(),
                    dataType: 'json',
                    beforeSend: function(xhr) {
                         xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    },
                    success: function(data) {
                        $submitBtn.prop('disabled', false).removeClass('opacity-60 cursor-not-allowed');
                        $submitBtn.find('#button-content').html('生成安全链接');
                        
                        if (data.success) {
                            displayResult(data.data, data.message);
                            // 成功后，刷新令牌
                            if (data.data.new_token) {
                                updateCsrfToken(data.data.new_token);
                            } else {
                                // 如果后端没有返回新令牌（例如在 createMessage 成功后），我们主动请求一个
                                // (或者在 createMessage 成功后也返回新令牌)
                                // 为简单起见，我们假设失败时会返回新令牌
                            }
                        } else {
                            showToast(data.message || '操作失败', false);
                            updateCsrfToken(data.data.new_token); // 失败时更新令牌
                        }
                    },
                    error: function(xhr, status, error) {
                        $submitBtn.prop('disabled', false).removeClass('opacity-60 cursor-not-allowed');
                        $submitBtn.find('#button-content').html('生成安全链接');
                        
                        let errorMsg = '请求失败';
                        try {
                            const response = JSON.parse(xhr.responseText);
                            errorMsg = response.message || errorMsg;
                            updateCsrfToken(response.data.new_token); // 错误时更新令牌
                        } catch (e) {
                            if (xhr.responseText.includes('<!DOCTYPE')) {
                                errorMsg = '服务器返回了错误页面，请检查服务器日志';
                            } else {
                                errorMsg = xhr.responseText || errorMsg;
                            }
                        }
                        showToast(errorMsg, false);
                        console.error('AJAX Error:', status, error, xhr.responseText);
                    }
                });
            });
            
            // 自动聚焦消息内容输入框
            $('#message_content').focus();
            
            // [修复] 检查来自非 AJAX (PRG) 的 flash message
            <?php if ($flashResult && $flashResult['success']): ?>
            $(document).ready(function() {
                const resultData = <?= json_encode($flashResult['data'], JSON_UNESCAPED_UNICODE) ?>;
                const message = <?= json_encode($flashResult['message'], JSON_UNESCAPED_UNICODE) ?>;
                displayResult(resultData, message);
            });
            <?php endif; ?>
        </script>
    </body>
    </html>
    <?php
}

// 显示消息
function displayMessage($content) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>秘密消息 - 阅后即焚</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <style>
            .message-box { background-color: #f3f4f6; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem; min-height: 4rem; word-break: break-word; white-space: pre-wrap; font-family: monospace; }
            .toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); padding: 12px 20px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; animation: fadeInOut 3s forwards; }
            .toast-success { background-color: #10B981; color: white; }
            .toast-error { background-color: #EF4444; color: white; }
        </style>
    </head>
    <body class="bg-gray-100 min-h-screen font-sans text-gray-800 py-4 px-4 sm:px-6">
        <div class="max-w-md mx-auto">
            <div class="bg-white rounded-lg shadow p-4 mb-4">
                <h1 class="text-lg font-semibold text-center mb-4">秘密消息</h1>
                <div id="message-content" class="message-box"><?= htmlspecialchars($content) ?></div>
                <button 
                    onclick="copyMessage()"
                    class="w-full bg-green-500 hover:bg-green-600 text-white py-2 px-4 rounded-md transition-colors"
                >
                    复制内容
                </button>
                <div class="mt-4 text-center text-sm text-red-500">
                    <span class="inline-block w-2 h-2 rounded-full bg-red-500 mr-1"></span>
                    此消息已被销毁，刷新页面将无法再次查看
                </div>
            </div>
        </div>
        <script>
            function showToast(message, isSuccess = true) {
                const toast = $('<div>').addClass(`toast ${isSuccess ? 'toast-success' : 'toast-error'}`)
                                      .text(message);
                $('body').append(toast);
                setTimeout(() => { toast.remove(); }, 3000);
            }
            function copyMessage() {
                const content = $('#message-content').text();
                navigator.clipboard.writeText(content).then(() => {
                    showToast('内容已复制');
                }).catch(err => {
                    showToast('复制失败: ' + err, false);
                });
            }
        </script>
    </body>
    </html>
    <?php
}

// 显示错误页面
function displayError($message) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>错误 - 阅后即焚</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://unpkg.com/jquery@3.7.1/dist/jquery.min.js"></script>
    </head>
    <body class="bg-gray-100 min-h-screen font-sans text-gray-800 py-4 px-4 sm:px-6">
        <div class="max-w-md mx-auto">
            <div class="bg-white rounded-lg shadow p-4 mb-4">
                <h1 class="text-lg font-semibold text-center mb-4">错误</h1>
                <div class="bg-red-50 text-red-700 p-4 rounded-md mb-4">
                    <?= htmlspecialchars($message) ?>
                </div>
                <a 
                    href="<?= htmlspecialchars(getCurrentUrl()) ?>" 
                    class="w-full block bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-md text-center transition-colors"
                >
                    返回
                </a>
            </div>
            <div class="text-center text-gray-500 text-xs mt-4">
                <p>阅后即焚 &copy; <?= date('Y') ?></p>
                <p class="mt-1"><?= $_SERVER['HTTP_HOST'] ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// 显示密码表单
function displayPasswordForm($id) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>需要密码 - 阅后即焚</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://unpkg.com/jquery@3.7.1/dist/jquery.min.js"></script>
    </head>
    <body class="bg-gray-100 min-h-screen font-sans text-gray-800 py-4 px-4 sm:px-6">
        <div class="max-w-md mx-auto">
            <div class="bg-white rounded-lg shadow p-4 mb-4">
                <h1 class="text-lg font-semibold text-center mb-4">需要密码</h1>
                <div class="bg-blue-50 text-blue-700 p-4 rounded-md mb-4">
                    此消息受密码保护，请输入密码查看
                </div>
                <form method="POST" action="?id=<?= htmlspecialchars($id) ?>" class="space-y-3">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">
                            密码 <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="password" 
                            name="password" 
                            class="w-full px-3 py-2 border rounded-md focus:ring-1 focus:ring-green-500 focus:border-green-500"
                            required
                        >
                    </div>
                    
                    <button 
                        type="submit" 
                        class="w-full bg-green-500 hover:bg-green-600 text-white py-2 px-4 rounded-md transition-colors"
                    >
                        查看消息
                    </button>
                </form>
            </div>
            <div class="text-center text-gray-500 text-xs mt-4">
                <p>阅后即焚 &copy; <?= date('Y') ?></p>
                <p class="mt-1"><?= $_SERVER['HTTP_HOST'] ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>