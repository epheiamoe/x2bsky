<?php
$hash = password_hash('x2bsky_admin', PASSWORD_DEFAULT);
file_put_contents(__DIR__ . '/data/.password_hash', $hash);
chmod(__DIR__ . '/data/.password_hash', 0600);
echo "Password hash created\n";
