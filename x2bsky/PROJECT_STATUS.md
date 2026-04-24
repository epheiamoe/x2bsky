# x2bsky 项目文档

## 项目概述
X to Bluesky cross-poster - 将 X (Twitter) 帖子同步到 Bluesky 的工具。

## 技术栈
- **后端**: 纯 PHP 8+ (无框架)
- **数据库**: SQLite (可切换 PostgreSQL)
- **队列**: Redis (有数据库降级方案)
- **前端**: Tailwind CSS + Alpine.js
- **部署**: VPS (www 用户运行)

## 目录结构
```
x2bsky/
├── index.php          # Dashboard 主页
├── login.php          # 登录页
├── history.php         # 同步历史
├── settings.php        # 设置页
├── logout.php         # 登出
├── api/               # API 端点
│   ├── fetch.php      # 拉取 X 帖子
│   ├── sync.php       # 同步选中帖子到 Bluesky
│   ├── progress.php   # 进度查询
│   └── logs.php       # 日志查询
├── src/
│   ├── Config.php     # 配置加载
│   ├── Database.php   # 数据库连接
│   ├── Logger.php     # 日志
│   ├── Auth.php       # 认证
│   ├── Settings.php    # 设置管理
│   ├── Utils.php      # 工具函数
│   ├── Api/
│   │   ├── XApiClient.php      # X API 封装
│   │   ├── BlueskyClient.php   # Bluesky API 封装
│   │   └── TextProcessor.php    # 文本处理
│   ├── Media/
│   │   └── MediaProcessor.php   # 媒体处理
│   ├── Queue/
│   │   └── QueueManager.php     # 队列管理
│   └── Worker/
│       └── SyncEngine.php       # 同步引擎
├── worker.php          # 后台 worker 脚本
├── cron.php            # 定时任务脚本
├── data/               # 数据目录
│   ├── x2bsky.db      # SQLite 数据库
│   ├── bsky_session.json  # Bluesky 会话
│   └── .password_hash     # 密码哈希
└── logs/               # 日志目录
```

## 数据库表结构

### synced_posts - 已同步帖子
```sql
CREATE TABLE synced_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    x_post_id TEXT UNIQUE NOT NULL,
    x_post_url TEXT,
    text_hash TEXT NOT NULL,
    text_preview TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status TEXT DEFAULT "pending"
);
```

### fetched_posts - 拉取的帖子暂存
```sql
CREATE TABLE fetched_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    x_post_id TEXT UNIQUE NOT NULL,
    x_post_url TEXT,
    text TEXT NOT NULL,
    text_hash TEXT NOT NULL,
    is_retweet BOOLEAN DEFAULT FALSE,
    is_quote BOOLEAN DEFAULT FALSE,
    original_author TEXT,
    media_json TEXT,
    fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    synced BOOLEAN DEFAULT FALSE,
    synced_at DATETIME,
    synced_bsky_uri TEXT,
    skipped BOOLEAN DEFAULT FALSE,
    skipped_at DATETIME
);
```

### settings - 设置
```sql
CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### logs - 日志
```sql
CREATE TABLE logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id TEXT,
    level TEXT NOT NULL,
    message TEXT NOT NULL,
    context TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## 工作流程 (新设计)

### Step 1: Fetch (拉取)
```
用户点击 "Fetch Posts" → X API 获取 → 过滤回复 → 存入 fetched_posts
```

### Step 2: Sync (同步)
```
用户选择帖子 → 下载媒体 → 上传 Bluesky → 创建 post → 更新 fetched_posts.synced
```

### 关键设置
- `sync_include_rts` - 是否同步 RT (默认 false)
- `sync_include_quotes` - 是否同步引用 (默认 true)
- `sync_default_count` - 每次 Fetch 数量 (默认 20)

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
  "posts": [...]
}
```

### POST /api/sync.php
同步选中的帖子到 Bluesky

**参数:**
```json
{
  "post_ids": [1, 2, 3]  // fetched_posts 表的 ID
}
```

**返回:**
```json
{
  "success": true,
  "synced": 3,
  "failed": 0
}
```

## Bluesky 发布规则

### 文本处理
- 按 `\n\n` 分割段落
- 每段 ≤300 字
- 多段时添加 `(1/n)` 标注

### 媒体处理
- 图片: 直接从 pbs.twimg.com 下载
- 上传 blob 到 Bluesky
- 创建 embed

## X API 过滤规则

通过 `referenced_tweets.type` 判断:
- `replied_to` → **跳过** (不显示在列表)
- `retweeted` → 可选同步 (默认不勾选)
- `quoted` → 直接包含链接

## 定时同步 (Cron)

默认关闭，用户可开启。频率由 `cron_interval` 设置。

Cron 行为:
1. Fetch 帖子 (使用 `sync_count` 设置的数量)
2. 自动同步所有 (不经过用户选择)

## 安全

- `.env` 文件权限 600
- PHP Session 认证
- 密码 bcrypt 哈希存储
- CSRF 保护 (TODO)

## 已知问题

- fileinfo PHP 扩展未安装，导致 `mime_content_type()` 不可用
- Worker 后台处理有时不工作 (建议用 systemd 服务)

## 更新日志

### 2026-04-24 - v2 架构重构
- 新增 fetched_posts 表实现两步流程
- 用户可选择要同步的帖子
- 过滤回复 (referenced_tweets.type === 'replied_to')
- RT 默认不勾选，用户可选择
- Quote 直接包含链接

### 2026-04-24 - v1 初始版本
- 基础 X → Bluesky 同步
- 自动同步所有帖子
- 回复也被同步 (问题)
