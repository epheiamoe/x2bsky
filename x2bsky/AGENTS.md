# x2bsky 维护指南 (AGENTS.md)

本文件供 AI Agent 维护项目时参考，包含重要技术细节和常见问题排查。

## 首次接触项目

### 快速了解
- 这是一个 X (Twitter) → Bluesky 同步工具
- PHP 8.4+ 无框架，使用 Tailwind CSS + Alpine.js
- 数据库：MySQL，队列：Redis（有 MySQL fallback）
- 部署在 VPS：`/www/wwwroot/x2bsky.desuwa.org/`

### 环境信息
```
SSH: myvps
项目目录: /www/wwwroot/x2bsky.desuwa.org/
本地目录: E:\Epheia\dev\apps\vps_serves\x2bsky\x2bsky\
```

### 首次修改流程
1. 修改本地文件（`E:\Epheia\dev\apps\vps_serves\x2bsky\x2bsky\`）
2. 使用 scp 上传到 VPS
3. 验证修改
4. 测试功能

### 常用上传命令
```bash
# 单文件
scp local/file.php myvps:/www/wwwroot/x2bsky.desuwa.org/path/file.php

# 批量上传
scp local/file1.php local/file2.php myvps:/www/wwwroot/x2bsky.desuwa.org/path/

# 上传整个 api 目录
scp -r local/api/* myvps:/www/wwwroot/x2bsky.desuwa.org/api/
```

## 关键代码位置

### Bluesky API 调用
- 文件: `src/Api/BlueskyClient.php`
- 重要方法:
  - `authenticate()` - 创建 session
  - `createPost()` - 创建帖子
  - `uploadBlob()` - 上传媒体（**必须用 binary 方式**）
  - `getLastError()` - 获取最后错误

### X API 调用
- 文件: `src/Api/XApiClient.php`
- 重要方法:
  - `fetchUserTweets()` - 获取用户推文
  - 自动过滤回复
  - 提取 `quoted_url`

### 文本处理
- 文件: `src/Api/TextProcessor.php`
- `splitForBluesky()` - 长帖分割（段落→句子）
- `addThreadNotation()` - 添加 (1/n) 标注

### 同步逻辑
- 文件: `api/sync.php`
- 处理流程: 获取帖子 → 下载媒体 → 上传 blob → 创建线程 → 更新数据库
- 读取设置: `thread_media_position`

### 设置管理
- 文件: `src/Settings.php`
- 数据库表: `settings`
- 页面: `settings.php`

## 常见问题

### Bluesky API 错误

#### "Expected string value type (got null) at $.repo"
- 原因: `$this->did` 为 null
- 排查: 检查 session 文件是否存在、路径是否正确
- 解决: BlueskyClient 使用绝对路径 `/www/wwwroot/x2bsky.desuwa.org/data/bsky_session.json`

#### "Expected "image/*" (got "multipart/form-data")"
- 原因: blob 上传使用了 multipart 格式
- 解决: 使用 binary 方式上传，参考 `src/Api/BlueskyClient.php` 的 `uploadBlob()`

#### "Referenced Mimetype does not match"
- 原因: 上传时指定了错误的 MIME type
- 排查: 检查 `getMimeType()` 是否正确根据扩展名返回 MIME

#### "Invalid app.bsky.feed.post record"
- 原因: createdAt 格式错误
- 解决: 使用 `gmdate('Y-m-d\TH:i:s.v\Z')` 生成 UTC 时间戳

### MySQL 错误

#### "You have an error in your SQL syntax... datetime("now")"
- 原因: 使用了 SQLite 语法
- 解决: 将 `datetime("now")` 替换为 `NOW()`
- 涉及: sync.php, Settings.php, QueueManager.php, SyncEngine.php

### X API 错误

#### t.co 短链接出现在同步后的帖子
- 原因: fetch.php 没有替换短链接
- 解决: 使用 `entities.urls` 中的 `expanded_url` 替换

#### "Post not found" when fetching referenced tweets
- 原因: deleted tweets 的 expansions 返回 404
- 解决: 不使用 expansions，直接用 `referenced_tweets` 字段

### 媒体处理

#### 图片上传失败
- 排查步骤:
  1. 检查图片 URL 是否可访问
  2. 检查 temp 目录是否有写入权限
  3. 检查 `mime_content_type()` 是否可用
  4. 检查 Bluesky blob 上传格式

#### 媒体显示问题
- 检查 `post_media` 表数据
- 检查前端 `load_media.php` API

#### Retweet 媒体不同步
- 原因: X API 返回的 retweet 本身没有 `attachments`，原帖的媒体在 `referenced_tweets` 中
- 解决: XApiClient.php 的 `expansions` 包含 `referenced_tweets.id`，会自动获取原帖媒体

#### X-only 帖子无法重新同步
- 原因: 帖子已存在于 `posts` 表但 `fetched_posts.synced=0` 或无 Bluesky 目标
- 解决: Archive 页面为这类帖子显示 "Sync to BSKY" 按钮，可重新触发同步

## 调试方法

### 查看 Worker 日志
```bash
ssh myvps "tail -100 /www/wwwroot/x2bsky.desuwa.org/logs/worker_error.log"
```

### 测试 Bluesky API
```php
// 在服务器上创建测试脚本
<?php
require_once "/www/wwwroot/x2bsky.desuwa.org/vendor/autoload.php";
\Config::init("/www/wwwroot/x2bsky.desuwa.org/.env");

$bsky = new \X2BSky\Api\BlueskyClient();
if ($bsky->authenticate()) {
    echo "Auth OK\n";
    $result = $bsky->createPost("Test");
    print_r($result);
}
```

### 测试 X API
```bash
ssh myvps 'curl -s -H "Authorization: Bearer $(cat /www/wwwroot/x2bsky.desuwa.org/.env | grep BEARER_TOKEN)" "https://api.twitter.com/2/users/xxx/tweets?max_results=5"'
```

### 数据库查询
```bash
# 查看未同步帖子
ssh myvps 'mysql -h 127.0.0.1 -P 3306 -u root -pYOUR_DB_PASS x2bsky -e "SELECT id, synced, synced_bsky_uri FROM fetched_posts WHERE synced=0 LIMIT 5;"'

# 查看设置
ssh myvps 'mysql -h 127.0.0.1 -P 3306 -u root -pYOUR_DB_PASS x2bsky -e "SELECT * FROM settings;"'
```

### Redis 队列检查
```bash
ssh myvps 'redis-cli -h 127.0.0.1 -p 6379 -a YOUR_REDIS_PASS KEYS "x2bsky:*"'
```

## 重启服务

### 重启 Worker
```bash
ssh myvps "pkill -f 'worker.php' ; cd /www/wwwroot/x2bsky.desuwa.org && nohup php worker.php > /dev/null 2>&1 &"
```

### 检查 Worker 状态
```bash
ssh myvps "ps aux | grep worker.php | grep -v grep"
```

## 代码规范

### PHP
- 使用 `declare(strict_types=1);`
- 命名空间: `X2BSky\...`
- 类名: PascalCase
- 方法名: camelCase
- 私有属性不直接暴露，使用 getter

### SQL
- 使用 MySQL 语法
- 时间函数用 `NOW()` 而非 `datetime("now")`
- 使用预处理语句防注入

### 前端
- Tailwind CSS 深色主题
- Alpine.js 用于交互
- 使用 `htmlspecialchars()` 防 XSS

## 文件修改 Checklist

修改任何文件后：
1. [ ] scp 上传到 VPS
2. [ ] 验证文件内容
3. [ ] 测试相关功能
4. [ ] 检查日志

## 敏感信息

### .env 文件位置
`/www/wwwroot/x2bsky.desuwa.org/.env`

### 包含内容
- 数据库凭据
- Bluesky 账号密码
- Redis 密码
- X API Bearer Token

### 注意事项
- 不要提交到 git
- 不要在日志中输出凭据
- 使用 `grep` 时注意不要匹配到敏感信息

## 联系人

项目所有者：依菲雅 (watakushi.desuwa.org)

Bluesky: https://bsky.app/profile/watakushi.desuwa.org
