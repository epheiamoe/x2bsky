-- x2bsky Database Schema (MySQL 10.11 / MariaDB)
-- Generated: 2026-04-24

CREATE DATABASE IF NOT EXISTS x2bsky CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE x2bsky;

-- =====================================================
-- posts - 统一帖子表（一次创作，多平台发布）
-- =====================================================
CREATE TABLE `posts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `x_post_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'X 推文 ID',
  `x_post_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'X 推文链接',
  `x_author` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'X 作者（预留）',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '完整推文文本',
  `text_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'text 的 md5 哈希（去重用）',
  `is_retweet` tinyint(1) DEFAULT '0' COMMENT '是否为 RT',
  `is_quote` tinyint(1) DEFAULT '0' COMMENT '是否为引用推文',
  `original_author` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'RT 的原作者',
  `x_created_at` datetime DEFAULT NULL COMMENT 'X 上的发布时间',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '存入本地的时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_x_post_id` (`x_post_id`),
  KEY `idx_posts_x_created_at` (`x_created_at`),
  KEY `idx_posts_text_hash` (`text_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- post_media - 媒体表（每个帖子的媒体链接）
-- =====================================================
CREATE TABLE `post_media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL COMMENT '关联 posts.id',
  `platform` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'x / bluesky / website',
  `media_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image' COMMENT 'image / video / gif',
  `original_url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原始媒体 URL（X 为 pbs.twimg.com）',
  `local_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '本地存储路径（目前未使用）',
  `alt_text` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '替代文本',
  `width` int(11) DEFAULT NULL COMMENT '宽度',
  `height` int(11) DEFAULT NULL COMMENT '高度',
  PRIMARY KEY (`id`),
  KEY `idx_post_media_post_platform` (`post_id`,`platform`),
  CONSTRAINT `post_media_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- synced_destinations - 同步目标表（记录每个帖子同步到哪些平台）
-- =====================================================
CREATE TABLE `synced_destinations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL COMMENT '关联 posts.id',
  `platform` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bluesky / website',
  `platform_post_url` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '同步后的公开链接',
  `platform_post_uri` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'at:// URI（Bluesky 用）',
  `synced_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '同步时间',
  `status` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT 'pending' COMMENT 'pending / synced / failed',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `idx_synced_destinations_platform` (`platform`),
  KEY `idx_synced_destinations_synced_at` (`synced_at`),
  CONSTRAINT `synced_destinations_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- fetched_posts - Fetch 阶段临时存储
-- =====================================================
CREATE TABLE `fetched_posts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `x_post_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'X 推文 ID',
  `x_post_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'X 推文链接',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '完整推文文本',
  `text_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'text 的 md5 哈希',
  `is_retweet` tinyint(1) DEFAULT '0' COMMENT '是否为 RT',
  `is_quote` tinyint(1) DEFAULT '0' COMMENT '是否为引用推文',
  `original_author` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'RT 的原作者',
  `media_json` text COLLATE utf8mb4_unicode_ci COMMENT 'X API 返回的媒体 JSON',
  `x_created_at` datetime DEFAULT NULL COMMENT 'X 上的发布时间',
  `fetched_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Fetch 时间',
  `synced` tinyint(1) DEFAULT '0' COMMENT '是否已同步',
  `synced_at` datetime DEFAULT NULL COMMENT '同步时间',
  `synced_bsky_uri` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Bluesky URI',
  `skipped` tinyint(1) DEFAULT '0' COMMENT '是否跳过',
  `skipped_at` datetime DEFAULT NULL COMMENT '跳过时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_x_post_id` (`x_post_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- settings - 设置表
-- =====================================================
CREATE TABLE `settings` (
  `setting_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '设置键（因 key 是 MySQL 保留字改用 setting_key）',
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON 编码的值',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- logs - 日志表
-- =====================================================
CREATE TABLE `logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_id` varchar(64) DEFAULT NULL COMMENT '任务 ID',
  `level` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'debug / info / warning / error',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '日志消息',
  `context` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON 上下文数据',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_logs_level` (`level`),
  KEY `idx_logs_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 当前数据状态
-- =====================================================
-- posts: 12 条记录
-- post_media: 5 条记录
-- synced_destinations: 0 条记录（尚未同步任何帖子到新表）
-- fetched_posts: 12 条记录
-- settings: 6 条记录
-- logs: 17 条记录

-- =====================================================
-- 媒体存储说明
-- =====================================================
-- 注意：目前图片/视频只存储 URL，不存储到本地
-- - Fetch 时：X 媒体 URL（pbs.twimg.com）存入 post_media (platform='x')
-- - Sync 时：下载到 temp → 上传 Bluesky → temp 文件被删除
-- - local_path 字段目前全为 NULL
