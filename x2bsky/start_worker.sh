#!/bin/bash
systemd-run --unit=x2bsky-worker /usr/bin/php /www/wwwroot/x2bsky.desuwa.org/worker.php
