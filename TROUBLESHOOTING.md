# x2bsky 排错指南与经验总结

本文档记录 x2bsky 开发过程中的重要技术发现和常见问题解决方案。

## Bluesky API 关键发现

### 1. 回复/线程的 CID 要求

**问题描述：**
创建线程时，第二条及后续帖子需要设置 `reply` 字段来指定回复关系。

**错误信息：**
```
Invalid app.bsky.feed.post record: Expected object value type (got "at://...") at $.record.reply.root
```
或
```
Missing required key "cid" at $.record.reply.root
```

**原因分析：**
Bluesky API 要求 `reply.root` 和 `reply.parent` 必须是对象，包含 `uri` 和 `cid` 两个字段：
```json
{
  "reply": {
    "parent": {"uri": "at://did:plc:xxx/app.bsky.feed.post/yyy", "cid": "bafyrei..."},
    "root": {"uri": "at://did:plc:xxx/app.bsky.feed.post/zzz", "cid": "bafyrei..."}
  }
}
```

**解决方案：**
`createPost()` 方法的 `$replyTo` 参数接受两种格式：
- 字符串：仅 URI，用于简单回复
- 数组：带 URI 和 CID 的完整对象，用于线程创建

线程创建时必须捕获并使用返回的 CID：
```php
$parentUri = null;
$parentCid = null;
$rootUri = null;
$rootCid = null;

foreach ($segments as $segment) {
    $replyRef = null;
    if ($parentUri) {
        $replyRef = [
            'parent' => ['uri' => $parentUri, 'cid' => $parentCid],
            'root' => ['uri' => $rootUri, 'cid' => $rootCid],
        ];
    }

    $result = $bsky->createPost($text, $embed, $replyRef);

    if ($result) {
        $uri = $result['uri'];
        $cid = $result['cid'];
        // 保存用于下一轮
        if ($isFirst) {
            $rootUri = $uri;
            $rootCid = $cid;
        }
        $parentUri = $uri;
        $parentCid = $cid;
    }
}
```

### 2. Blob 上传格式

**重要：**
Bluesky 的 blob 上传**必须使用 binary 方式**，不能使用 multipart/form-data。

错误的上传方式会导致：
```
Expected "image/*" (got "multipart/form-data")
```

正确的实现（在 `BlueskyClient.php` 的 `uploadBlob()` 中）：
```php
$headers = [
    'Authorization: Bearer ' . $this->accessToken,
    'Content-Type: ' . $mimeType,
    'Content-Length: ' . strlen($fileData),
];

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $fileData,  // 直接传二进制数据
    ]
]);
```

### 3. createdAt 时间戳格式

Bluesky 要求 RFC 3339 格式的 UTC 时间戳：
```php
$createdAt = gmdate('Y-m-d\TH:i:s.v\Z');
// 示例: 2026-04-25T21:15:00.000Z
```

---

## X API 关键发现

### 1. Bearer Token URL 编码

**问题描述：**
X API 返回 401 Unauthorized，但 Token 看起来正确。

**原因分析：**
Bearer Token 中包含特殊字符（`+`, `/`, `=`），需要 URL 编码存储在 `.env` 文件中。

**解决方案：**
`.env` 文件中存储 URL 编码版本：
```
X_BEARER_TOKEN=your_url_encoded_bearer_token_here
```

XApiClient 发送请求时会自动 URL 解码。

### 2. RT 媒体获取

**发现：**
获取用户时间线时，RT 的媒体通过 `referenced_tweets.id` expansion 自动返回。

**验证结果：**
```
RT ID: 2047999247681994994
Media count: 3
- https://pbs.twimg.com/media/HGuZAXUbgAA-eWI.jpg
- https://pbs.twimg.com/media/HGuZAXBaIAAS2Qu.png
- https://pbs.twimg.com/media/HGuZAX9aAAA1Elb.png
```

当原推文有媒体时，RT 会自动包含媒体 URL，不需要额外 API 调用。

### 4. X Premium 长推文文本截断 (note_tweet)

**问题描述：**
X Premium 用户的长推文 (>280 字) 同步到 Bluesky 后只显示前几十个字，完整内容丢失。

**原因分析：**
X API v2 对长推文使用两个字段：
- `text` — 截断版本（280 字左右 + 短链接）
- `note_tweet.text` — 完整全文（Premium 用户可达数千字）

之前的代码只请求了 `text` 字段，未包含 `note_tweet`。

**解决方案：**
1. 在 `tweet.fields` 中添加 `note_tweet`
2. 处理 tweet 时优先使用 `note_tweet['text']`
3. RT 的引用原帖同样检查 `note_tweet.text`

```php
// 请求时
'tweet.fields' => '...,note_tweet'

// 处理时
$fullText = $tweet['note_tweet']['text'] ?? $tweet['text'];
```

**涉及文件：** `src/Api/XApiClient.php`, `src/Api/fetch.php`

### 5. RT 文本截断

**问题描述：**
转推同步后只有十几个字，原推完整内容丢失。

**原因分析：**
X API 返回的 RT tweet 的 `text` 字段只包含 "RT @user: 开头片段…"。
完整文本在 `includes.tweets` 中的引用原推（含 `note_tweet.text`）。

**解决方案：**
从 `tweetMap[originalTweetId]` 读取完整文本，并单独保存 `_rt_author`：
```php
$tweet['_rt_author'] = $m[1];  // 从 RT 前缀提取
$tweet['text'] = $originalTweet['note_tweet']['text'] ?? $originalTweet['text'];
```

**涉及文件：** `src/Api/XApiClient.php`

**原则：**
- 获取自己账号内容：便宜
- 获取别人账号内容：昂贵

**策略：**
- Fetch 时：通过 expansions 附带获取媒体（免费）
- 禁止：额外调用 `getTweetById()` 获取原帖媒体
- Sync 时：直接从 `pbs.twimg.com` 下载（无需 API）

---

## 文本处理关键发现

### 1. 中文字符串长度

PHP 的 `mb_strlen()` 用于正确计算中文字符数。

### 2. 线程标注预留空间

Bluesky 帖子限制 300 字符，但 `(n/m)` 标注会占用空间。

**问题：**
文本分割时正好 300 字符，加上 `(1/2)` 后会超过限制。

**解决方案：**
`TextProcessor::splitForBluesky()` 预留 10 字符空间：
```php
$reservedSpace = 10;
$effectiveMax = max(50, $maxChars - $reservedSpace);
```

### 3. CJK 文本分割

当文本没有句末标点（`.!?。！？`）时，按字符数强制分割：
```php
if ($sentences === false || count($sentences) <= 1) {
    $chunks = self::chunkByLength($paragraph, $effectiveMax);
    // ...
}
```

---

## 调试技巧

### 1. 临时测试数据

创建测试帖子：
```php
$stmt = $pdo->prepare("
    INSERT INTO posts (x_post_id, text, text_hash, x_created_at)
    VALUES (?, ?, ?, NOW())
");
$stmt->execute([$xPostId, $text, md5($text)]);
$postId = $pdo->lastInsertId();
```

重置同步状态：
```php
$pdo->exec("UPDATE fetched_posts SET synced = 0 WHERE id = $id");
```

### 2. 验证 Bluesky 响应

创建帖子后检查返回：
```php
$result = $bsky->createPost($text, $embed, $replyTo);
print_r($result);
// 期望: ['uri' => 'at://...', 'cid' => 'bafyrei...', 'commit' => [...]]
```

### 3. 数据库状态检查

```sql
-- 查看同步状态
SELECT id, synced, synced_bsky_uri FROM fetched_posts WHERE synced = 0;

-- 查看 RT 媒体状态
SELECT p.id, p.original_author, COUNT(pm.id) as media_count
FROM posts p
LEFT JOIN post_media pm ON p.id = pm.post_id
WHERE p.is_retweet = 1
GROUP BY p.id;
```

---

## 经验教训

### 1. API 文档的重要性

很多问题源于没有仔细阅读 API 文档。Bluesky 的 `reply` 字段明确要求 `uri` 和 `cid`，但我们最初只传了 URI。

**建议：** 遇到 API 错误时，首先查阅官方文档。

### 2. 边界情况测试

创建了 949 字符的测试帖子才发现线程分割问题。

**建议：** 编写测试用例覆盖边界情况（正好 300 字符、多段落、特殊字符等）。

### 3. 分离问题

调试时分步骤验证：
1. 先验证文本分割
2. 再验证媒体上传
3. 最后验证线程创建

**建议：** 每步成功后逐步集成。

### 4. 保留调试日志

使用 Logger 记录关键信息：
```php
Logger::info('Created thread post', [
    'uri' => $uri,
    'cid' => $cid,
    'segment' => $i,
    'has_media' => !empty($embed)
]);
```

### 5. 数据库操作的幂等性

删除记录重新创建时，确保清理所有相关表（posts, fetched_posts, post_media, synced_destinations）。

---

## 常见错误速查

| 错误信息 | 原因 | 解决方案 |
|---------|------|---------|
| X 长推文同步后只显示几十字 | `note_tweet` 字段未请求 | 添加 `note_tweet` 到 `tweet.fields` |
| RT 文本只有十几个字 | 未读取引用原帖完整文本 | 从 `includes.tweets` 获取原帖 `note_tweet.text` |
| `Expected "image/*" (got "multipart/form-data")` | blob 上传格式错误 | 使用 binary 方式上传 |
| `Missing required key "cid"` | reply 对象缺少 CID | 传递完整的 uri+cid 对象 |
| `Expected object value type at $.record.reply.root` | reply 是字符串而非对象 | 传递数组格式的 reply |
| 401 Unauthorized (X API) | Bearer Token 未 URL 编码 | `.env` 中存储编码后的 Token |
| `Column not found: 'status'` | Queue 表缺少列 | 检查数据库 schema |
| `datetime("now")` | SQLite 语法用于 MySQL | 使用 `NOW()` |

---

## 相关文件

- `src/Api/BlueskyClient.php` - Bluesky API 调用
- `src/Api/XApiClient.php` - X API 调用
- `src/Api/TextProcessor.php` - 文本分割处理
- `api/sync.php` - 同步逻辑
- `api/fetch.php` - 获取推文