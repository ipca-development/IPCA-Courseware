<?php

function cw_setting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = [];
    $ck = $key;
    if (isset($cache[$ck])) return $cache[$ck];

    $st = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
    $st->execute([$key]);
    $v = (string)($st->fetchColumn() ?: $default);
    $cache[$ck] = $v;
    return $v;
}