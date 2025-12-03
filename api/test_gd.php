<?php
if (extension_loaded('gd')) {
    echo 'GD مفعل ✅<br>';
    $im = imagecreate(100, 100);
    if ($im !== false) {
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, 100, 100, $white);
        imagestring($im, 5, 10, 40, 'Test GD OK', $black);
        header('Content-Type: image/png');
        imagepng($im);
        imagedestroy($im);
        exit();  // إيقاف النص
    } else {
        echo 'فشل imagecreate ❌';
    }
} else {
    echo 'GD غير مفعل ❌';
}
?>