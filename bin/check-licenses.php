<?php

$lockFile = __DIR__ . '/../composer.lock';
if (!file_exists($lockFile)) {
    echo "composer.lock not found! Please run 'composer install' or 'composer update' first.\n";
    exit(1);
}

$lockData = json_decode(file_get_contents($lockFile), true);
if (!$lockData) {
    echo "Failed to parse composer.lock!\n";
    exit(1);
}

// Whitelist of approved permissive licenses compatible with closed-source commercial SaaS
$allowedLicenses = [
    'MIT',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'Apache-2.0',
    'ISC',
    'Unlicense',
    'WTFPL',
];

$violations = [];
$checkedCount = 0;

$packages = array_merge(
    $lockData['packages'] ?? [],
    $lockData['packages-dev'] ?? []
);

foreach ($packages as $package) {
    $name = $package['name'];
    $licenses = $package['license'] ?? [];
    
    if (empty($licenses)) {
        $violations[] = "$name (No license specified)";
        continue;
    }
    
    $checkedCount++;
    $isCompatible = false;
    
    foreach ($licenses as $license) {
        // Case-insensitive check against approved license terms
        if (in_array(strtoupper($license), array_map('strtoupper', $allowedLicenses))) {
            $isCompatible = true;
            break;
        }
    }
    
    if (!$isCompatible) {
        $violations[] = "$name (" . implode(', ', $licenses) . ")";
    }
}

if (!empty($violations)) {
    echo "\n\033[31m====================================================================\033[0m\n";
    echo "\033[31m❌ LICENSE VIOLATION DETECTED: Copyleft or Unapproved License Found!\033[0m\n";
    echo "\033[31m====================================================================\033[0m\n";
    echo "The following dependencies do not match the approved permissive licenses whitelist\n";
    echo "(Allowed: " . implode(', ', $allowedLicenses) . "):\n\n";
    foreach ($violations as $violation) {
        echo "  - $violation\n";
    }
    echo "\nTo preserve the ability to sell a closed-source commercial SaaS edition,\n";
    echo "please remove these packages or seek alternative, permissively-licensed libraries.\n";
    echo "\033[31m====================================================================\033[0m\n\n";
    exit(1);
}

echo "\033[32m✔ License Check Passed: All $checkedCount dependencies are permissively licensed (MIT/BSD/Apache).\033[0m\n";
exit(0);
