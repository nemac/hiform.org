<?php

namespace Drupal\Tests\tome_static_cron\Functional;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\Traits\Core\CronRunTrait;
use Drupal\tome_static\DeprecationHelper;

/**
 * Tests the functionality of Tome Static Cron.
 *
 * @group tome_static_cron
 */
class TomeStaticCronTest extends BrowserTestBase {

  use CronRunTrait;
  use ImageFieldCreationTrait;
  use TestFileCreationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'tome_static_cron',
    'image',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->createContentType([
      'type' => 'article',
    ]);

    $field_settings = [
      'file_extensions' => 'png',
    ];
    $formatter_settings = [
      'image_style' => 'large',
      'image_link' => '',
    ];
    DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.0.0',
      fn() => $this->createImageField('field_image', 'node', 'article', [], $field_settings, [], $formatter_settings),
      fn() => $this->createImageField('field_image', 'article', [], $field_settings, [], $formatter_settings),
    );

    $directory = $this->siteDirectory . '/files/tome/static';
    $settings['settings']['tome_static_directory'] = (object) [
      'value' => $directory,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
  }

  /**
   * Tests the static cron.
   */
  public function testStaticCron() {
    /** @var \Drupal\tome_static\StaticGeneratorInterface $static */
    $static = \Drupal::service('tome_static.generator');

    $valid_images = [];
    foreach ($this->getTestFiles('image') as $image) {
      $regex = '/\.png$/i';
      if (preg_match($regex, $image->filename)) {
        $valid_images[] = $image;
      }
    }

    $file = File::create([
      'uri' => $valid_images[0]->uri,
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file->save();

    $image_factory = $this->container->get('image.factory');
    $image = $image_factory->get($valid_images[0]->uri);
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Test node',
      'field_image' => [
        'target_id' => $file->id(),
        'width' => $image->getWidth(),
        'height' => $image->getHeight(),
      ],
    ]);

    // @todo Why does calling tome_static_cron_cron() fail here on the testbot?
    _tome_static_cron_queue_paths(['/node/' . $node->id()], 'http://google.com');

    $filename = $static->getStaticDirectory() . '/node/' . $node->id() . '/index.html';
    $image_filename = $static->getStaticDirectory() . \Drupal::service('file_url_generator')->generateString(ImageStyle::load('large')->buildUri($file->getFileUri()));
    $this->assertFileDoesNotExist($filename);
    $this->assertFileDoesNotExist($image_filename);
    $this->cronRun();
    $this->assertFileExists($filename);
    $this->assertFileExists($image_filename);
    $this->assertStringContainsString('Test node', file_get_contents($filename));
  }

}
