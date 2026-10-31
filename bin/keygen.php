<?php
// Prints a new admin key and the sha256 to put in config.php as admin_key_sha256.
//   php bin/keygen.php
// No PHP locally? Same thing in PowerShell, from the organilog folder:
//   node -e "const k='olk_'+require('crypto').randomBytes(24).toString('hex');console.log(k);console.log(require('crypto').createHash('sha256').update(k).digest('hex'))"

$key = 'olk_' . bin2hex(random_bytes(24));
echo "key:    $key\n";
echo "sha256: " . hash('sha256', $key) . "\n";
echo "\nKeep the key in your password manager; put only the sha256 in config.php.\n";
