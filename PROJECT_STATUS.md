# x2bsky 项目文档

## 项目概述

X to Bluesky cross-poster - 将 X (Twitter) 帖子同步到 Bluesky 的工具。
未来计划：支持同步到个人网站，实现信息主权。

## 技术栈

- **后端**: 纯 PHP 8.4+ (无框架)
- **数据库**: MySQL 10.11 (MariaDB)
- **队列**: Redis (有数据库降级方案)
- **前端**: Tailwind CSS + Alpine.js
- **部署**: VPS (www 用户运行)

## 目录结构

```
x2bsky/
├── index.php              # Dashboard 主页
├── login.php              # 登录页
├── logout.php             # 登出
├── archive.php            # Timeline/Archive 页面（原 history.php）
├── fetch.php              # Fetch & Sync 页面
├── post.php               # 帖子详情页
├── settings.php           # 设置页
├── api/                   # API 端点
│   ├── fetch.php          # 拉取 X 帖子
│   ├── sync.php           # 同步选中帖子到 Bluesky
│   ├── delete_post.php    # 删除帖子
│   ├── load_media.php     # 加载媒体（base64）
│   ├── progress.php       # 进度查询
│   └── logs.php           # 日志查询
├── src/
│   ├── Config.php         # 配置加载（.env）
│   ├── Database.php       # 数据库连接
│   ├── Logger.php         # 日志
│   ├── Auth.php           # 认证
│   ├── Settings.php       # 设置管理
│   ├── Utils.php          # 工具函数
│   ├── Api/
│   │   ├── XApiClient.php       # X API 封装
│   │   ├── BlueskyClient.php    # Bluesky API 封装
│   │   └── TextProcessor.php    # 文本处理（长帖分割）
│   ├── Media/
│   │   └── MediaProcessor.php   # 媒体处理
│   ├── Queue/
│   │   └── QueueManager.php     # 队列管理（Redis/MySQL）
│   └── Worker/
│       └── SyncEngine.php       # 同步引擎（后台worker）
├── worker.php              # 后台 worker 脚本
├── cron.php               # 定时任务脚本
├── data/                  # 数据目录
│   ├── bsky_session.json  # Bluesky 会话
│   └── .password_hash     # 密码哈希
└── logs/                  # 日志目录
    ├── worker.log
    └── worker_error.log
```

## 数据库表结构 (MySQL)

### posts - 统一帖子表
存储所有发布过的内容（一次创作，多平台发布）

```sql
CREATE TABLE `posts` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    x_post_id VARCHAR(64) NOT NULL UNIQUE,
    x_post_url VARCHAR(512),
    x_author VARCHAR(128),
    text TEXT NOT NULL,
    text_hash VARCHAR(64) NOT NULL,
    is_retweet TINYINT(1) DEFAULT 0,
    is_quote TINYINT(1) DEFAULT 0,
    original_author VARCHAR(128),
    x_created_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### post_media - 媒体表
存储每个帖子的媒体链接（X 和 Bluesky 的都存）

```sql
CREATE TABLE `post_media` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    platform ENUM('x', 'bluesky', 'website') NOT NULL,
    media_type ENUM('image', 'video', 'gif') NOT NULL DEFAULT 'image',
    original_url VARCHAR(1024) NOT NULL,
    local_path VARCHAR(1024),
    alt_text VARCHAR(512),
    width INT,
    height INT
);
```

### synced_destinations - 同步目标表
记录每个帖子同步到哪些平台

```sql
CREATE TABLE `synced_destinations` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    platform ENUM('bluesky', 'website') NOT NULL,
    platform_post_url VARCHAR(1024),
    platform_post_uri VARCHAR(512),
    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'synced', 'failed') DEFAULT 'pending',
    error_message TEXT
);
```

### fetched_posts - Fetch 阶段临时存储
用于两步流程：Fetch → 用户选择 → Sync

```sql
CREATE TABLE `fetched_posts` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    x_post_id VARCHAR(64) NOT NULL UNIQUE,
    x_post_url VARCHAR(512),
    text TEXT NOT NULL,
    text_hash VARCHAR(64) NOT NULL,
    is_retweet TINYINT(1) DEFAULT 0,
    is_quote TINYINT(1) DEFAULT 0,
    original_author VARCHAR(128),
    quoted_url VARCHAR(1024),
    media_json TEXT,
    x_created_at DATETIME,
    fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    synced TINYINT(1) DEFAULT 0,
    synced_at DATETIME,
    synced_bsky_uri VARCHAR(512),
    skipped TINYINT(1) DEFAULT 0,
    skipped_at DATETIME
);
```

### settings - 设置
```sql
CREATE TABLE `settings` (
    setting_key VARCHAR(128) PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### logs - 日志
```sql
CREATE TABLE `logs` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(64),
    level VARCHAR(16) NOT NULL,
    message TEXT NOT NULL,
    context TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## 工作流程

### Step 1: Fetch (拉取)
1. 用户点击 "Fetch Posts"
2. X API 获取用户最近推文
3. 过滤回复（不显示）
4. t.co 短链接替换为 expanded_url
5. 存入 `posts` + `fetched_posts`

### Step 2: Sync (同步)
1. 用户选择要同步的帖子
2. 下载媒体到 temp
3. 上传到 Bluesky（blob）
4. 创建 post（支持线程）
5. 更新 `fetched_posts.synced`
6. 存入 `post_media` + `synced_destinations`

## 关键设置

| 设置名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| `cron_enabled` | bool | false | 是否开启自动同步 |
| `cron_interval` | int | 5 | 自动同步间隔（分钟） |
| `sync_count` | int | 10 | 每次同步数量 |
| `sync_include_rts` | bool | false | 是否同步 RT |
| `sync_include_quotes` | bool | true | 是否同步 Quote |
| `fetch_default_count` | int | 20 | 每次 Fetch 数量 |
| `thread_media_position` | string | 'last' | 长线程图片位置：'first' 或 'last' |
| `history_per_page` | int | 20 | Archive 每页帖子数 (5-50) |
| `history_max_pages` | int | 10 | Archive 最大分页页数 (1-100) |

## API 端点

### POST /api/fetch.php
拉取 X 帖子到本地

**参数:**
- `count` (int): 拉取数量 (5-100)

**返回:**
```json
{
  "success": true,
  "fetched": 15,
  "skipped": 2,
  "posts": [...]
}
```

### POST /api/sync.php
同步选中的帖子到 Bluesky

**参数:**
```json
{
  "post_ids": [1, 2, 3]
}
```

**返回:**
```json
{
  "success": true,
  "synced": 3,
  "failed": 0,
  "results": [
    {
      "id": 1,
      "status": "success",
      "uri": "at://did:plc:xxx/app.bsky.feed.post/xxx",
      "segments": 3,
      "thread_position": "last"
    }
  ]
}
```

## Bluesky 发布规则

### 文本处理
- 按 `\n\n` 分割段落
- 每段 ≤300 字（Bluesky 限制）
- 超过时 fallback 到句子分割（按 `.!?。！？` 断句）
- 多段时添加 ` (1/n)` 标注

### 媒体处理
- 图片: 直接从 pbs.twimg.com 下载到 temp
- 上传 blob 到 Bluesky（**必须用 binary 方式，不能用 multipart**）
- 创建 embed（images 类型）
- temp 文件上传后删除

### 长帖子线程
- 用户可在设置选择图片放在「第一帖」或「最后一帖」
- 每段文本末尾追加 ` (1/3)` 格式的进度标注

## X API 过滤规则

通过 `referenced_tweets.type` 判断:
- `replied_to` → 检查引用 tweet 的 `author_id`：如果是自己的 tweet（自回帖/线程续篇）则保留，回复他人则跳过
- `retweeted` → 可选同步 (默认不勾选)，从 `includes.tweets` 获取原帖完整文本（含 `note_tweet`）
- `quoted` → 直接包含链接，引用 URL 存在 `quoted_url` 字段，排除 `/photo/` 类媒体 URL

### note_tweet 字段
X Premium 长推文（>280 字）的完整文本存储在 `note_tweet.text`，普通 `text` 字段是截断版本。
`fetchUserTweets()` 和 `getUserTweets()` 都请求此字段并在处理时优先使用完整文本。

## VPS 部署信息

- **服务器**: `/www/wwwroot/x2bsky.desuwa.org/`
- **SSH 别名**: `myvps`
- **数据库**: MySQL @ 127.0.0.1:3306
- **Redis**: @ 127.0.0.1:6379
- **运行用户**: www
- **Worker**: `php worker.php` (后台运行)

### 常用命令
```bash
# 重启 worker
ssh myvps "pkill -f 'worker.php' ; cd /www/wwwroot/x2bsky.desuwa.org && nohup php worker.php > /dev/null 2>&1 &"

# 查看 worker 日志
ssh myvps "tail -50 /www/wwwroot/x2bsky.desuwa.org/logs/worker_error.log"

# 检查 Redis 队列
ssh myvps "redis-cli -h 127.0.0.1 -p 6379 -a <REDIS_PASS> KEYS '*'"

# MySQL 查询
ssh myvps "mysql -h 127.0.0.1 -P 3306 -u root -p<DB_PASS> x2bsky -e 'SELECT ...'"
```

## 安全

- `.env` 文件权限 600，PHP include
- PHP Session 认证
- 密码 bcrypt 哈希存储
- CSRF 保护 (TODO)

---

## 技术发现记录（重要）

### MySQL 语法 vs SQLite

**问题**: 代码中使用了 SQLite 的 `datetime("now")` 语法

**解决**: MySQL 使用 `NOW()` 或 `CURRENT_TIMESTAMP`

```php
// 错误 (SQLite)
datetime("now")
DATE_ADD(datetime("now"), INTERVAL ...)

// 正确 (MySQL)
NOW()
DATE_ADD(NOW(), INTERVAL ... SECOND)
```

**涉及文件**:
- `api/sync.php`
- `src/Settings.php`
- `src/Queue/QueueManager.php`
- `src/Worker/SyncEngine.php`

### Bluesky Blob 上传（重要）

**问题**: 使用 multipart/form-data 上传图片时，PDS 服务器错误地将 blob mimeType 检测为 `multipart/form-data`

**解决**: 使用 binary 方式上传，直接设置 `Content-Type: image/jpeg`

```php
// 错误 - multipart 上传
$body = "--{$boundary}\r\n";
$body .= "Content-Type: {$mimeType}\r\n";
$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"...\"\r\n\r\n";
$body .= $fileData . "\r\n";
$body .= "--{$boundary}--\r\n";
// Content-Type: multipart/form-data

// 正确 - binary 上传
$headers = [
    'Authorization: Bearer ' . $this->accessToken,
    'Content-Type: ' . $mimeType,  // 直接用 MIME type
    'Content-Length: ' . strlen($fileData),
];
$context = [
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $fileData,  // 直接二进制数据
    ]
];
```

**涉及文件**: `src/Api/BlueskyClient.php` 的 `uploadBlob()` 方法

### Bluesky createdAt 时间戳

**问题**: `date('c')` 生成带时区的时间字符串（如 `2026-04-24T18:51:22+08:00`），导致 App View 无法正确排序

**解决**: 使用 UTC 时间

```php
// 错误
'createdAt' => date('c')

// 正确
'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z')
```

### X API t.co 短链接

**问题**: X API 返回的 `text` 包含 t.co 短链接，需要替换为真实 URL

**解决**: 使用 `entities.urls` 中的 `expanded_url` 替换

```php
$text = $tweet['text'];
if (!empty($tweet['entities']['urls'])) {
    foreach ($tweet['entities']['urls'] as $url) {
        if (!empty($url['expanded_url']) && !empty($url['url'])) {
            $text = str_replace($url['url'], $url['expanded_url'], $text);
        }
    }
}
```

**涉及文件**: `api/fetch.php`

### X API referenced_tweets

**问题**: 使用 expansions 获取 referenced_tweets 时，deleted tweets 返回 404

**解决**: 不使用 expansions，只用 `tweet.fields` 获取 `referenced_tweets`

```php
// API 调用参数
'tweet.fields' => 'id,text,created_at,entities,attachments,referenced_tweets,...'
// 不使用 expansions

// 判断类型
$postType = $tweet['referenced_tweets'][0]['type'] ?? 'original';
```

### Bluesky Session 路径

**问题**: Worker 在 `/www/wwwroot/x2bsky.desuwa.org/src/Worker/` 运行，`dirname(__DIR__, 2)` 计算出的路径不正确

**解决**: 使用绝对路径

```php
// 错误 - 相对路径
$this->sessionFile = dirname(__DIR__, 2) . '/data/bsky_session.json';

// 正确 - 绝对路径
$this->sessionFile = '/www/wwwroot/x2bsky.desuwa.org/data/bsky_session.json';
```

### PHP mime_content_type 函数

**问题**: 服务器未安装 fileinfo 扩展或 `mime_content_type()` 函数不可用

**解决**: 使用 extension-based fallback

```php
private function getMimeType(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        // ...
    ];
    return $mimeTypes[$ext] ?? 'application/octet-stream';
}
```

### PHP namespace 内调用全局函数

**问题**: 在 namespace 内调用 `function_exists('mime_content_type')` 可能产生意外行为

**解决**: 使用 fallback 逻辑，不依赖该函数

## 更新日志

详见 [CHANGELOG.md](./CHANGELOG.md)（Keep a Changelog 格式）。

当前版本: **0.6.0**

### v0.6.0 (2026-04-27) — 长帖文完整同步
- X API `note_tweet` 字段支持：Premium 长推文完整文本获取
- RT 引用原帖完整文本获取（含 `note_tweet`）
- 自回帖（线程续篇）不再被跳过
- quoted_url 排除 /photo/ 媒体链接
- Bluesky `createThread()` 传递完整 `{uri, cid}` 对象
- Cron/worker 路径使用 `_full_text`
