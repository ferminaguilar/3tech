<?php

declare(strict_types=1);

namespace Drupal\Tests\imagemagick\Unit;

use Drupal\imagemagick\Plugin\FileMetadata\ImagemagickIdentify;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for processing identify output.
 *
 * @phpstan-import-type FrameInfo from ImagemagickIdentify
 */
#[Group('imagemagick')]
class IdentifyOutputProcessTest extends UnitTestCase {

  /**
   * Provides data to testProcessOutput().
   */
  public static function providerProcessOutput(): \Generator {
    yield 'Invalid' => [
      "identify: no decode delegate for this image format `modules/contrib/imagemagick/src/ImagemagickExecArguments.php' @ error/constitute.c/ReadImage/752.\n",
      [],
    ];

    yield 'One frame, no data' => [
      "format:TXT|width:|height:|colorspace:|profiles:|exif_orientation:\n",
      [
        0 => [
          'format' => 'TXT',
          'width' => NULL,
          'height' => NULL,
          'colorspace' => NULL,
          'profiles' => [],
          'exif_orientation' => NULL,
        ],
      ],
    ];

    yield 'Valid IM, one frame' => [
      "format:JPEG|width:2000|height:1200|colorspace:sRGB|profiles:8bim,exif,icc,iptc,xmp|exif_orientation:1\n",
      [
        0 => [
          'format' => 'JPEG',
          'width' => 2000,
          'height' => 1200,
          'colorspace' => 'sRGB',
          'profiles' => ['8bim', 'exif', 'icc', 'iptc', 'xmp'],
          'exif_orientation' => 1,
        ],
      ],
    ];

    yield 'Valid GM, one frame' => [
      "format:JPEG|width:2000|height:1200|exif_orientation:1\n",
      [
        0 => [
          'format' => 'JPEG',
          'width' => 2000,
          'height' => 1200,
          'exif_orientation' => 1,
        ],
      ],
    ];

    yield 'GM, one frame, extra line, https://www.drupal.org/project/imagemagick/issues/3432948#comment-16355011' => [
      "format:JPEG|width:2000|height:1200|exif_orientation:1\n1\n\n",
      [
        0 => [
          'format' => 'JPEG',
          'width' => 2000,
          'height' => 1200,
          'exif_orientation' => 1,
        ],
      ],
    ];

    yield 'IM, one frame, extra lines, https://www.drupal.org/project/imagemagick/issues/3432948' => [
      "Decoded /tmp/magick-6050w68DqfhHR9x9. Dimensions: 328 x 450. Now saving...\nSaved file /tmp/magick-60508Ys8A13Dlzsu\nformat:PAM|width:328|height:450|colorspace:sRGB|profiles:|exif_orientation:\n",
      [
        0 => [
          'format' => 'PAM',
          'width' => 328,
          'height' => 450,
          'colorspace' => 'sRGB',
          'profiles' => [],
          'exif_orientation' => NULL,
        ],
      ],
    ];

    yield 'IM, four frames' => [
      <<<DATA
format:PDF|width:283|height:432|colorspace:sRGB|profiles:|exif_orientation:
format:PDF|width:283|height:432|colorspace:sRGB|profiles:|exif_orientation:
format:PDF|width:283|height:432|colorspace:sRGB|profiles:|exif_orientation:
format:PDF|width:283|height:432|colorspace:sRGB|profiles:|exif_orientation:
DATA,
      [
        0 => [
          'format' => 'PDF',
          'width' => 283,
          'height' => 432,
          'colorspace' => 'sRGB',
          'profiles' => [],
          'exif_orientation' => NULL,
        ],
        1 => [
          'format' => 'PDF',
          'width' => 283,
          'height' => 432,
          'colorspace' => 'sRGB',
          'profiles' => [],
          'exif_orientation' => NULL,
        ],
        2 => [
          'format' => 'PDF',
          'width' => 283,
          'height' => 432,
          'colorspace' => 'sRGB',
          'profiles' => [],
          'exif_orientation' => NULL,
        ],
        3 => [
          'format' => 'PDF',
          'width' => 283,
          'height' => 432,
          'colorspace' => 'sRGB',
          'profiles' => [],
          'exif_orientation' => NULL,
        ],
      ],
    ];

  }

  /**
   * Tests ::processOutput().
   *
   * @param FrameInfo $expected
   */
  #[DataProvider('providerProcessOutput')]
  public function testProcessOutput(string $data, array $expected): void {
    $this->assertEquals($expected, ImagemagickIdentify::processOutput($data));
  }

}
