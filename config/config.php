<?php
declare(strict_types=1);

function config_value(string $key, mixed $default = null): mixed {
    $value = getenv($key);
    return $value === false ? $default : $value;
}
