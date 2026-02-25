<?php
set_time_limit(0);
ini_set('memory_limit', '512M');

/* =========================
   🔹 Process All SQL Files
   ========================= */

$files = glob("*.sql");

if (empty($files)) {
    die("Koi SQL file nahi mili.");
}

foreach ($files as $inputFile) {

    // Agar file already s se start ho rahi hai to skip kare
    if (stripos($inputFile, 's') === 0) {
        echo "⏭ Skipped (Already Processed): $inputFile <br>";
        continue;
    }

    $outputFile = 's' . $inputFile;

    $in  = fopen($inputFile, 'r');
    $out = fopen($outputFile, 'w');

    if (!$in || !$out) {
        echo "❌ Error in file: $inputFile <br>";
        continue;
    }

    $insideTrigger = false;

    while (($line = fgets($in)) !== false) {

        $trim = trim($line);

        // 🔴 Trigger detect
        if (stripos($trim, 'CREATE TRIGGER') !== false) {
            $insideTrigger = true;

            if (preg_match('/END\s*;$/i', $trim)) {
                $insideTrigger = false;
            }
            continue;
        }

        // 🔴 Trigger block ke andar
        if ($insideTrigger) {
            if (preg_match('/END\s*;$/i', $trim)) {
                $insideTrigger = false;
            }
            continue;
        }

        // 🔵 DELIMITER remove
        if (stripos($trim, 'DELIMITER') === 0) {
            continue;
        }

        // 🔵 Collation fix
        $line = str_ireplace(
            [
                'utf8mb4_uca1400_ai_ci',
                'utf8mb4_0900_ai_ci',
                'utf8mb4_0900_as_ci',
                'utf8mb4_0900_bin'
            ],
            'utf8mb4_unicode_ci',
            $line
        );

        fwrite($out, $line);
    }

    fclose($in);
    fclose($out);

    echo "✅ Processed: $inputFile → $outputFile <br>";
}

echo "<br>🎉 ALL DONE!";
?>