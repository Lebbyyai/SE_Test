<?php

require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

echo "=== TESTING FLEXIBLE NAME MATCHING ===\n\n";

$tests = [
    'Muslimin Banto Ontong',  // Full name with middle
    'Muslimin Ontong',         // Without middle
    'muslimin banto ontong',   // Lowercase
    'MUSLIMIN BANTO ONTONG',   // Uppercase
    '  Muslimin   Banto   Ontong  ', // Extra spaces
    'Dr. John Michael Smith',  // Another adviser
    'Dr. John Smith',          // Without middle
];

foreach ($tests as $testName) {
    echo "Testing: '{$testName}'\n";
    $user = User::findByFullName($testName, 'Adviser')->first();

    if ($user) {
        echo "  ✅ FOUND: {$user->full_name} ({$user->email})\n";
    } else {
        echo "  ❌ NOT FOUND\n";
    }
    echo "\n";
}

echo "=== TEST COMPLETE ===\n";
