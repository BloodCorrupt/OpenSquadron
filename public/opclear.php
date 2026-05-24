<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache Cleared!";
} else {
    echo "OPCache not enabled/supported.";
}
