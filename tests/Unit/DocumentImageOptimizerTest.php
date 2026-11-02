<?php

namespace Tests\Unit;

use App\Services\DocumentImageOptimizer;
use PHPUnit\Framework\TestCase;

class DocumentImageOptimizerTest extends TestCase
{
    public function test_shrinks_large_jpeg_and_reduces_bytes(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $image = imagecreatetruecolor(3000, 2000);
        $this->assertNotFalse($image);
        $bg = imagecolorallocate($image, 240, 240, 240);
        imagefilledrectangle($image, 0, 0, 2999, 1999, $bg);
        ob_start();
        imagejpeg($image, null, 95);
        $binary = (string) ob_get_clean();

        $result = (new DocumentImageOptimizer)->optimize($binary, 'image/jpeg');

        $this->assertNotNull($result);
        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertLessThan(strlen($binary), strlen($result['contents']));

        $resized = imagecreatefromstring($result['contents']);
        $this->assertNotFalse($resized);
        $this->assertLessThanOrEqual(2048, max(imagesx($resized), imagesy($resized)));
    }

    public function test_leaves_small_images_alone(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $image = imagecreatetruecolor(100, 80);
        $this->assertNotFalse($image);
        ob_start();
        imagejpeg($image, null, 80);
        $binary = (string) ob_get_clean();

        $this->assertNull((new DocumentImageOptimizer)->optimize($binary, 'image/jpeg'));
    }
}
