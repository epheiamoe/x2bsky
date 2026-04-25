# x2bsky

X (Twitter) to Bluesky cross-poster - Archive your X posts to Bluesky with a beautiful dashboard.

![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?style=flat-square&logo=php)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

## Features

- **Manual & Automatic Sync** - Fetch posts from X API and sync to Bluesky on your schedule
- **Media Support** - Images are automatically downloaded, compressed, and uploaded to Bluesky
- **Long Post Handling** - Long posts are intelligently split across multiple Bluesky posts
- **Quote & Retweet Preservation** - Quote tweets include the referenced link, RTs show original author
- **Duplicate Detection** - Posts are deduplicated based on content hash
- **Job Queue** - Redis-backed queue with MySQL fallback for reliability
- **Dark Theme UI** - Beautiful Alpine.js + Tailwind CSS dashboard

## Requirements

- PHP 8.4+
- MySQL 5.7+ or MariaDB 10.3+
- Redis 6+ (optional, MySQL fallback available)
- Nginx
- Composer

## Quick Start

### 1. Clone the Repository

```bash
git clone https://github.com/yourusername/x2bsky.git
cd x2bsky
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

```bash
cp .env.example .env
# Edit .env with your credentials
```

### 4. Create Required Directories

```bash
mkdir -p data logs
chmod 700 data logs
chmod 600 .env
```

### 5. Set Up Database

Create a MySQL database:

```sql
CREATE DATABASE x2bsky CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'x2bsky'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON x2bsky.* TO 'x2bsky'@'localhost';
FLUSH PRIVILEGES;
```

The application will automatically create tables on first run.

### 6. Configure Web Server

Example Nginx configuration:

```nginx
server {
    listen 80;
    server_name x2bsky.yourdomain.com;
    root /var/www/x2bsky;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.env {
        deny all;
    }
}
```

### 7. Get API Credentials

#### Bluesky
1. Log into Bluesky at https://bsky.app
2. Go to Settings → App Passwords
3. Create a new app password

#### X (Twitter) API
1. Apply for X API access at https://developer.twitter.com
2. Create a project and app
3. Generate OAuth 1.0a credentials and Bearer token

### 8. Start

```bash
# Start the worker (background job processing)
php worker.php &

# Optional: Set up cron for automatic syncing
# Add to crontab: */5 * * * * /path/to/x2bsky/cron.sh
```

Access the dashboard at `https://x2bsky.yourdomain.com/`

## Configuration

All configuration is done via the `.env` file:

| Variable | Description | Required |
|----------|-------------|----------|
| `BSKY_HANDLE` | Your Bluesky handle | Yes |
| `BSKY_PASSWORD` | Bluesky app password | Yes |
| `X_CONSUMER_KEY` | X API consumer key | Yes |
| `X_SECRET_KEY` | X API secret key | Yes |
| `X_BEARER_TOKEN` | X API bearer token | Yes |
| `X_ACCESS_TOKEN` | X OAuth access token | Yes |
| `X_ACCESS_TOKEN_SECRET` | X OAuth access token secret | Yes |
| `X_USER_ID` | Your X user ID | Yes |
| `DB_HOST` | MySQL host | Yes |
| `DB_USER` | MySQL username | Yes |
| `DB_PASS` | MySQL password | Yes |
| `DB_NAME` | Database name | Yes |
| `REDIS_REQUIREPASS` | Redis password | No |
| `SECRET_KEY` | Random string for sessions | Yes |

## Usage

### Fetch & Sync Page
1. Click "Fetch Posts" to pull recent posts from X
2. Select posts to sync (or sync all)
3. Click "Sync to Bluesky"

### Archive Page
- View all archived posts
- See sync status (X only, Bluesky linked)
- Re-sync failed posts

### Settings Page
- Configure auto-sync intervals
- Set post counts
- Choose thread media position (first or last post)

## Architecture

```
x2bsky/
├── index.php          # Dashboard
├── fetch.php          # Fetch posts from X
├── archive.php        # Archive/timeline view
├── settings.php       # Configuration UI
├── api/               # API endpoints
│   ├── fetch.php      # Fetch posts API
│   ├── sync.php       # Sync to Bluesky API
│   └── ...
├── src/
│   ├── Api/
│   │   ├── BlueskyClient.php
│   │   ├── XApiClient.php
│   │   └── TextProcessor.php
│   ├── Database.php
│   ├── Settings.php
│   └── ...
├── worker.php         # Background job processor
└── cron.php           # Cron job entry point
```

## Troubleshooting

### Bluesky blob upload fails
Ensure you're uploading blobs as binary data, not multipart/form-data.

### X t.co links appearing in posts
Make sure X API credentials are correct and you have appropriate access levels.

### Worker not processing jobs
Check Redis connection or ensure MySQL fallback is configured.

## License

MIT License - see LICENSE file for details.

## Contributing

Contributions welcome! Please open an issue or PR.
