# x2bsky

X to Bluesky cross-poster

## Setup

1. Copy `.env.example` to `.env` and configure:
```bash
cp .env.example .env
```

2. Install dependencies:
```bash
composer install
```

3. Ensure directories exist:
```bash
mkdir -p data logs
chmod 600 .env
chmod 700 data logs
```

## API Configuration

Required environment variables:

### Bluesky
- `BSKY_HANDLE` - Your Bluesky handle (e.g., `watakushi.desuwa.org`)
- `BSKY_APP_PASSWORD` - App password from Bluesky settings

### X API
- `X_CONSUMER_KEY` - X API consumer key
- `X_SECRET_KEY` - X API secret key
- `X_BEARER_TOKEN` - X API bearer token
- `X_ACCESS_TOKEN` - X API access token
- `X_ACCESS_TOKEN_SECRET` - X API access token secret
- `X_USER_ID` - Your X user ID

### Redis (optional)
- `REDIS_HOST` - Redis host
- `REDIS_PORT` - Redis port
- `REDIS_REQUIREPASS` - Redis password

### Security
- `SECRET_KEY` - Random 32+ character string for session security
- `ADMIN_PASSWORD` - Dashboard login password (default: `x2bsky_admin`)

## Running

### Web Dashboard
Point Nginx to the project root directory.

### Worker (background processing)
```bash
php worker.php
```

### Cron (scheduled sync)
Add to crontab:
```crontab
*/5 * * * * /www/wwwroot/x2bsky.desuwa.org/cron.sh
```

## Features

- Manual sync with configurable post count
- Automatic deduplication
- Long post splitting for X Premium users
- Media download and compression
- Thread creation on Bluesky
- Redis-backed job queue with database fallback
- Exponential backoff retry (max 5 attempts)
- Real-time progress via AJAX polling
