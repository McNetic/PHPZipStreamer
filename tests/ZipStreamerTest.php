<?php
/**
 * Copyright (C) 2014-2015 Nicolai Ehemann <en@enlightened.de>
 *
 * This file is licensed under the GNU GPL version 3 or later.
 * See COPYING for details.
 */
namespace Rivimey\ZipStreamer\Tests;

use Rivimey\ZipStreamer;
use Rivimey\ZipStreamer\Count64\PackBits;
use Rivimey\ZipStreamer\DOS;
use Rivimey\ZipStreamer\UNIX;
use Rivimey\ZipStreamer\GPFLAGS;
use Rivimey\ZipStreamer\Count64\Count64;
use Rivimey\ZipStreamer\Count64\Count64_32;
use Rivimey\ZipStreamer\Count64\Count64_64;
use Rivimey\ZipStreamer\Deflate\COMPR;
use Rivimey\ZipStreamer\Deflate\DeflateStream;
use Rivimey\ZipStreamer\Deflate\DeflatePeclStream;
use Rivimey\ZipStreamer\Deflate\DeflateStoreStream;

require "tests/ZipComponents.php";

class TestZipStreamer extends \PHPUnit_Framework_TestCase {
  const ATTR_MADE_BY_VERSION = 0x032d; // made by version (upper byte: UNIX, lower byte v4.5)
  const EXT_FILE_ATTR_DIR = 0x41ed0010;
  const EXT_FILE_ATTR_FILE = 0x81a40000;
  protected $outstream;

  protected function setUp() {
    parent::setUp();
    $this->outstream = fopen('php://memory', 'rw');
    zipRecord::setUnitTest($this);
  }

  protected function tearDown() {
    fclose($this->outstream);
    parent::tearDown();
  }

  protected function getOutput() {
    rewind($this->outstream);
    return stream_get_contents($this->outstream);
  }

  protected static function getVersionToExtract($zip64, $isDir) {
    if ($zip64) {
      $version = 0x2d; // 4.5 - File uses ZIP64 format extensions
    } else if ($isDir) {
      $version = 0x14; // 2.0 - File is a folder (directory)
    } else {
      $version = 0x0a; // 1.0 - Default value
    }
    return $version;
  }

  protected function assertOutputEqualsFile($filename) {
    return $this->assertEquals(file_get_contents($filename), $this->getOutput());
  }

  protected function assertContainsOneMatch($pattern, $input) {
    $results = preg_grep($pattern, $input);
    return $this->assertEquals(1, sizeof($results));
  }

  protected function assertOutputZipfileOK($files, $options) {
    if (0 < sizeof($files)) { // php5.3 does not combine empty arrays
      $files = array_combine(array_map(function ($element) {
        return $element->filename;
      }, $files), $files);
    }
    $fileheader_nonzero = (isset($options['fileheader_nonzero']) && $options['fileheader_nonzero']);
    $output = $this->getOutput();

    $eocdrec = EndOfCentralDirectoryRecord::constructFromString($output);
    $this->assertEquals(strlen($output) - 1, $eocdrec->end, "EOCDR last item in file");

    if ($options['zip64']) {
      $eocdrec->assertValues(array(
          "numberDisk" => 0xffff,
          "numberDiskStartCD" => 0xffff,
          "numberEntriesDisk" => 0xffff,
          "numberEntriesCD" => 0xffff,
          "size" => 0xffffffff,
          "offsetStart" => 0xffffffff,
          "lengthComment" => 0,
          "comment" => ''
      ));

      $z64eocdloc = Zip64EndOfCentralDirectoryLocator::constructFromString($output, strlen($output) - ($eocdrec->begin + 1));

      $this->assertEquals($z64eocdloc->end + 1, $eocdrec->begin, "Z64EOCDL directly before EOCDR");
      $z64eocdloc->assertValues(array(
          "numberDiskStartZ64EOCDL" => 0,
          "numberDisks" => 1
      ));

      $z64eocdrec = Zip64EndOfCentralDirectoryRecord::constructFromString($output, strlen($output) - ($z64eocdloc->begin + 1));

      $this->assertEquals(Count64::construct($z64eocdrec->begin), $z64eocdloc->offsetStart, "Z64EOCDR begin");
      $this->assertEquals($z64eocdrec->end + 1, $z64eocdloc->begin, "Z64EOCDR directly before Z64EOCDL");
      $z64eocdrec->assertValues(array(
          "size" => Count64::construct(44),
          "madeByVersion" => PackBits::pack16le(self::ATTR_MADE_BY_VERSION),
          "versionToExtract" => PackBits::pack16le($this->getVersionToExtract($options['zip64'], False)),
          "numberDisk" => 0,
          "numberDiskStartCDR" => 0,
          "numberEntriesDisk" => Count64::construct(sizeof($files)),
          "numberEntriesCD" => Count64::construct(sizeof($files))
      ));
      $sizeCD = $z64eocdrec->sizeCD->getLoBytes();
      $offsetCD = $z64eocdrec->offsetStart->getLoBytes();
      $beginFollowingRecord = $z64eocdrec->begin;
    } else {
      $eocdrec->assertValues(array(
          "numberDisk" => 0,
          "numberDiskStartCD" => 0,
          "numberEntriesDisk" => sizeof($files),
          "numberEntriesCD" => sizeof($files),
          "lengthComment" => 0,
          "comment" => ''
      ));
      $sizeCD = $eocdrec->size;
      $offsetCD = $eocdrec->offsetStart;
      $beginFollowingRecord = $eocdrec->begin;
    }

    $cdheaders = array();
    $pos = $offsetCD;
    $cdhead = null;

    while ($pos < $beginFollowingRecord) {
      $cdhead = CentralDirectoryHeader::constructFromString($output, $pos);
      $filename = $cdhead->filename;
      $pos = $cdhead->end + 1;
      $cdheaders[$filename] = $cdhead;

      $this->assertArrayHasKey($filename, $files, "CDH entry has valid name");
      $cdhead->assertValues(array(
          "madeByVersion" => PackBits::pack16le(self::ATTR_MADE_BY_VERSION),
          "versionToExtract" => PackBits::pack16le($this->getVersionToExtract($options['zip64'], File::DIR == $files[$filename]->type)),
          "gpFlags" => ((!$fileheader_nonzero && File::FILE == $files[$filename]->type) ? PackBits::pack16le(GPFLAGS::ADD) : PackBits::pack16le(GPFLAGS::NONE)),
          "gzMethod" => (File::FILE == $files[$filename]->type ? PackBits::pack16le($options['compress']) : PackBits::pack16le(COMPR::STORE)),
          "dosTime" => PackBits::pack32le(ZipStreamer\ZipStreamer::getDosTime($files[$filename]->date)),
          "lengthFilename" => strlen($filename),
          "lengthComment" => 0,
          "fileAttrInternal" => PackBits::pack16le(0x0000),
          "fileAttrExternal" => (File::FILE == $files[$filename]->type ? PackBits::pack32le(self::EXT_FILE_ATTR_FILE) : PackBits::pack32le(self::EXT_FILE_ATTR_DIR))
      ));
      if ($options['zip64']) {
        $cdhead->assertValues(array(
            "sizeCompressed" => 0xffffffff,
            "size" => 0xffffffff,
            "lengthExtraField" => 32,
            "diskNumberStart" => 0xffff,
            "offsetStart" => 0xffffffff
        ));
        $cdhead->z64Ext->assertValues(array(
            "sizeField" => 28,
            "size" => Count64::construct($files[$filename]->getSize()),
            "diskNumberStart" => 0
        ));
      } else {
        $cdhead->assertValues(array(
            "size" => $files[$filename]->getSize(),
            "lengthExtraField" => 0,
            "diskNumberStart" => 0
        ));
      }
    }
    if (0 < sizeof($files)) {
      $this->assertEquals($cdhead->end + 1, $beginFollowingRecord, "CDH directly before following record");
      $this->assertEquals(sizeof($files), sizeof($cdheaders), "CDH has correct number of entries");
      $this->assertEquals($sizeCD, $beginFollowingRecord - $offsetCD, "CDH has correct size");
    } else {
      $this->assertNull($cdhead);
    }

    $first = True;
    foreach ($cdheaders as $filename => $cdhead) {
      $file = $files[$filename];
      $origFileSize = $file->getSize();

      if ($options['zip64']) {
        $sizeCompressed = $cdhead->z64Ext->sizeCompressed->getLoBytes();
        $offsetStart = $cdhead->z64Ext->offsetStart->getLoBytes();
      } else {
        $sizeCompressed = $cdhead->sizeCompressed;
        $offsetStart = $cdhead->offsetStart;
      }
      if ($first) {
        $this->assertEquals(0, $offsetStart, "first file directly at beginning of zipfile");
      } else {
        $this->assertEquals($endLastFile + 1, $offsetStart, "file immediately after last file");
      }
      $fileentry = FileEntry::constructFromString($output, $offsetStart, $sizeCompressed);
      $this->assertEquals($file->data, $fileentry->data, 'CDH Data');
      $this->assertEquals(hexdec(hash('crc32b', $file->data)), $cdhead->dataCRC32, 'CDH CRC32');
      if (GPFLAGS::ADD & $fileentry->lfh->gpFlags) {
        $this->assertNotNull($fileentry->dd, "data descriptor present (flag ADD set)");
      }
      else {
        $this->assertNull($fileentry->dd, "data descriptor NOT present (flag ADD unset)");
      }

      // Flag fileheader_nonzero is set by test harness when we expect LocalFileHeader to
      // have size & crc values, rather than zeros with a following DataDescriptor.
      if ($fileheader_nonzero) {
        if ($options['zip64']) {
          // if zip64, the 32 bit headers are unused:
          $fileentry->lfh->assertValues(array(
                 "sizeCompressed" => 0xffffffff,
                 "size" => 0xffffffff,
               ));
          // The 64 bit headers
          $fileentry->lfh->z64Ext->assertValues(array(
                 "sizeField" => 28,
                 "diskNumberStart" => 0,
                 "size" => Count64::construct($origFileSize)
               ));

          if ($fileentry->lfh->z64Ext->sizeCompressed->getLoBytes() > 0) {
            $this->assertGreaterThanOrEqual($fileentry->lfh->z64Ext->size->getLoBytes(),
                                            $fileentry->lfh->z64Ext->sizeCompressed->getLoBytes());
          }
        }
        else {
          // 32 bit headers, so it's easier:
          if ($fileentry->lfh->sizeCompressed > 0) {
            $fileentry->lfh->assertValues(array("size" => $origFileSize));
            $this->assertGreaterThanOrEqual($fileentry->lfh->size,
                                            $fileentry->lfh->sizeCompressed);
          }
        }
        $fileentry->lfh->assertValues(array(
               "versionToExtract" => PackBits::pack16le($this->getVersionToExtract($options['zip64'], File::DIR == $files[$filename]->type)),
               "gpFlags" => ((!$fileheader_nonzero && File::FILE == $files[$filename]->type) ? GPFLAGS::ADD : GPFLAGS::NONE),
               "gzMethod" => (File::FILE == $files[$filename]->type ? $options['compress'] : COMPR::STORE),
               "dosTime" => PackBits::pack32le(ZipStreamer\ZipStreamer::getDosTime($files[$filename]->date)),
               "lengthFilename" => strlen($filename),
               "filename" => $filename
             ));
      }
      else {
        if ($options['zip64']) {
          $fileentry->lfh->assertValues(array(
                 "sizeCompressed" => 0xffffffff,
                 "size" => 0xffffffff,
               ));
          $fileentry->lfh->z64Ext->assertValues(array(
                 "sizeField" => 28,
                 "size" => Count64::construct(0),
                 "sizeCompressed" => Count64::construct(0),
                 "diskNumberStart" => 0
               ));
        }
        else {
          $fileentry->lfh->assertValues(array(
               "sizeCompressed" => 0,
               "size" => 0,
             ));
        }
        $fileentry->lfh->assertValues(array(
               "versionToExtract" => PackBits::pack16le($this->getVersionToExtract($options['zip64'], File::DIR == $files[$filename]->type)),
               "gpFlags" => ((!$fileheader_nonzero && File::FILE == $files[$filename]->type) ? GPFLAGS::ADD : GPFLAGS::NONE),
               "gzMethod" => (File::FILE == $files[$filename]->type ? $options['compress'] : COMPR::STORE),
               "dosTime" => PackBits::pack32le(ZipStreamer\ZipStreamer::getDosTime($files[$filename]->date)),
               "dataCRC32" => 0x0000,
               "lengthFilename" => strlen($filename),
               "filename" => $filename
             ));
      }

      $endLastFile = $fileentry->end;
      $first = False;
    }
    if (0 < sizeof($files)) {
      $this->assertEquals($offsetCD, $endLastFile + 1, "last file directly before CDH");
    } else {
      $this->assertEquals(0, $beginFollowingRecord, "empty zip file, CD records at beginning of file");
    }
  }

  /**
   * @return array array(filename, mimetype), expectedMimetype, expectedFilename, $description, $browser
   */
  public function providerSendHeadersOK() {
    return array(
      // Regular browsers
        array(
            array(),
            'application/zip',
            'archive.zip',
            'default headers',
            'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36',
            'Content-Disposition: attachment; filename*=UTF-8\'\'archive.zip; filename="archive.zip"',
        ),
        array(
            array(
                'file.zip',
                'application/octet-stream',
                ),
            'application/octet-stream',
            'file.zip',
            'specific headers',
            'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36',
            'Content-Disposition: attachment; filename*=UTF-8\'\'file.zip; filename="file.zip"',
        ),
      // Internet Explorer
        array(
            array(),
            'application/zip',
            'archive.zip',
            'default headers',
            'Mozilla/5.0 (compatible, MSIE 11, Windows NT 6.3; Trident/7.0; rv:11.0) like Gecko',
            'Content-Disposition: attachment; filename="archive.zip"',
        ),
        array(
            array(
                'file.zip',
                'application/octet-stream',
            ),
            'application/octet-stream',
            'file.zip',
            'specific headers',
            'Mozilla/5.0 (compatible, MSIE 11, Windows NT 6.3; Trident/7.0; rv:11.0) like Gecko',
            'Content-Disposition: attachment; filename="file.zip"',
        ),
    );
  }

  /**
   * @dataProvider providerSendHeadersOK
   * @preserveGlobalState disabled
   * @runInSeparateProcess
   *
   * @param array $arguments
   * @param string $expectedMimetype
   * @param string $expectedFilename
   * @param string $description
   * @param string $browser
   * @param string $expectedDisposition
   */
  public function testSendHeadersOKWithRegularBrowser(array $arguments,
                                                      $expectedMimetype,
                                                      $expectedFilename,
                                                      $description,
                                                      $browser,
                                                      $expectedDisposition) {
    $zip = new ZipStreamer\ZipStreamer(array(
        'outstream' => $this->outstream
    ));
    $_SERVER['HTTP_USER_AGENT'] = $browser;
    call_user_func_array(array($zip, "sendHeaders"), $arguments);
    $headers = xdebug_get_headers();
    $this->assertContains('Pragma: public', $headers);
    $this->assertContains('Expires: 0', $headers);
    $this->assertContains('Accept-Ranges: bytes', $headers);
    $this->assertContains('Connection: Keep-Alive', $headers);
    $this->assertContains('Content-Transfer-Encoding: binary', $headers);
    $this->assertContains('Content-Type: ' . $expectedMimetype, $headers);
    $this->assertContains($expectedDisposition, $headers);
    $this->assertContainsOneMatch('/^Last-Modified: /', $headers);
  }

  /**
   * A test data source for the actual test functions (tagged with dataProvider).
   *
   * @return array
   */
  public function providerZipfileOK() {
    $zip64Options = array(array(True, 'True'), array(False, 'False'));
    $defaultLevelOption = array(array(COMPR::NORMAL, 'COMPR::NORMAL'));
    $compressOptions = array(array(COMPR::STORE, 'COMPR::STORE'), array(COMPR::DEFLATE, 'COMPR::DEFLATE'));
    $levelOptions = array(array(COMPR::NONE, 'COMPR::NONE'), array(COMPR::SUPERFAST, 'COMPR::SUPERFAST'), array(COMPR::MAXIMUM, 'COMPR::MAXIMUM'));
    $fileSets = array(
      'empty' => array(
        'content' => array(),
      ),
      'one empty dir' => array(
        'content' => array(
          new File('test/', File::DIR, 1)
        ),
      ),
      'one file' => array(
        'content' => array(
          new File('test1.txt', File::FILE, 1, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed elit diam, posuere vel aliquet et, malesuada quis purus. Aliquam mattis aliquet massa, a semper sem porta in. Aliquam consectetur ligula a nulla vestibulum dictum. Interdum et malesuada fames ac ante ipsum primis in faucibus. Nullam luctus faucibus urna, accumsan cursus neque laoreet eu. Suspendisse potenti. Nulla ut feugiat neque. Maecenas molestie felis non purus tempor, in blandit ligula tincidunt. Ut in tortor sit amet nisi rutrum vestibulum vel quis tortor. Sed bibendum mauris sit amet gravida tristique. Ut hendrerit sapien vel tellus dapibus, eu pharetra nulla adipiscing. Donec in quam faucibus, cursus lacus sed, elementum ligula. Morbi volutpat vel lacus malesuada condimentum. Fusce consectetur nisl euismod justo volutpat sodales.')
        ),
      ),
      'one larger file' => array(
        'content' => array(
          new File('test1.txt', File::FILE, 1, '
Lorem ipsum dolor sit amet, consectetur adipiscing elit. Donec lectus risus, tempor eu tortor quis, luctus tempus leo. Maecenas pretium eget velit id lacinia. Morbi eget consequat urna. Quisque imperdiet lorem odio, vitae maximus tellus imperdiet et. Duis non malesuada nunc. Vestibulum non augue ut magna vehicula elementum. Maecenas imperdiet commodo augue in tempor. Etiam eget nulla ut elit dictum rhoncus sit amet eget nisi. Vivamus ac lacinia neque. Cras semper est sed arcu suscipit, eu pretium ipsum consectetur. Aenean ultrices venenatis mauris. Aenean pellentesque elit et imperdiet dignissim.
Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Donec id hendrerit odio. Etiam imperdiet vulputate aliquam. Aliquam sit amet felis lacinia, pretium ante id, porta nisl. Duis tristique placerat massa, quis tristique magna. Proin cursus vel nibh et pulvinar. Aenean venenatis ligula at pretium pharetra. Mauris convallis dictum enim venenatis suscipit. Nulla nec lacus sed sem sagittis imperdiet ac et ipsum. Aenean vitae urna ac diam aliquam bibendum ac ut leo. Aenean condimentum erat purus, sit amet placerat ante vulputate at.
Vestibulum imperdiet felis dui, iaculis aliquam enim imperdiet et. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Integer neque mi, placerat sed gravida in, tempor ut sem. Quisque pharetra diam eget turpis mattis, quis convallis turpis sollicitudin. Donec nec elementum lacus. Quisque rhoncus vel lectus ac efficitur. Integer lorem urna, ultrices a elementum vitae, hendrerit sit amet tellus. Phasellus quis maximus nibh. Mauris ante nibh, lacinia et nulla ut, fringilla fermentum ante. Vestibulum porttitor et justo sit amet molestie.
Nam bibendum in ipsum ac dictum. Pellentesque diam sapien, congue vitae varius et, suscipit vitae justo. In purus ex, vehicula vel tristique non, faucibus eget diam. Integer nec leo facilisis magna efficitur luctus semper at sapien. Nunc non purus ut libero facilisis dignissim. Duis luctus nec enim quis imperdiet. Etiam pretium lorem et orci auctor, eget hendrerit ante bibendum. Nullam imperdiet quam nec dui malesuada lobortis. Maecenas id leo nibh. Nunc non ante vel arcu porttitor scelerisque ut eu sem. Integer a facilisis nisi. Duis non fringilla arcu. Nullam viverra tincidunt ex eget faucibus. Morbi rhoncus consequat orci, non volutpat tortor blandit ac. Suspendisse tincidunt ante eu leo pretium pellentesque.
Etiam ac arcu porta, dictum nisi at, interdum enim. Sed sit amet scelerisque libero, et lobortis libero. Phasellus sed facilisis libero, a volutpat orci. Nunc quis rhoncus eros. Fusce facilisis leo a volutpat ornare. Donec sit amet leo porta erat mollis hendrerit. Pellentesque eget ante iaculis, condimentum nisl a, dictum lectus. Fusce bibendum posuere nibh eget eleifend. Duis ut ex ante. Mauris ut tortor nec felis vulputate dapibus. Morbi quis nibh quis libero volutpat viverra at vestibulum libero. Curabitur feugiat vel arcu nec gravida. Etiam sed dui ut elit volutpat congue. Duis ac feugiat justo. Donec sed nibh mollis, scelerisque enim sed, cursus enim.
Quisque consectetur, dui eu pretium ultricies, mauris odio tempus nulla, eget consectetur lectus justo vehicula quam. Mauris tristique odio suscipit arcu faucibus euismod. Duis vel nunc sem. Integer felis nisi, varius id laoreet vitae, dignissim egestas ipsum. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Cras id rhoncus magna, ut bibendum ante. Suspendisse mattis suscipit justo, a tempus dolor cursus et. Quisque diam tortor, tempus rhoncus nisi quis, malesuada rhoncus leo. Aenean vitae ex urna. Nam interdum sapien cursus ultrices scelerisque. Mauris diam diam, rhoncus vitae vulputate eget, rhoncus eget quam. Proin pretium lorem vitae purus euismod, in rhoncus mi pulvinar. Phasellus tincidunt dignissim rhoncus.
Pellentesque vehicula metus non cursus vehicula. Pellentesque faucibus, dolor ac vehicula condimentum, urna eros varius nulla, eu vulputate libero lacus quis metus. Nulla sodales urna sit amet velit dictum, eu ullamcorper odio finibus. Donec vitae nulla consectetur, accumsan tortor ac, ultrices eros. Phasellus magna ante, egestas vel posuere nec, vulputate sit amet libero. Morbi et imperdiet neque. Proin cursus nibh quis neque lobortis, at placerat quam facilisis. Vivamus ultrices egestas sodales. Nunc nec posuere dolor, sit amet pharetra lacus. In hac habitasse platea dictumst.
Sed sed magna et odio bibendum ornare. Etiam auctor odio velit, non scelerisque nibh aliquam et. Vestibulum accumsan vehicula velit vitae tempus. Duis non congue ante, non commodo lorem. Integer eleifend dui sed eros bibendum, pulvinar auctor ipsum consequat. Mauris sit amet justo ac nulla interdum semper. Aenean volutpat nibh non nisl consectetur, non gravida magna facilisis. Cras at volutpat nibh. Quisque egestas mollis augue, quis fermentum augue blandit quis. Cras fringilla congue nisi, eget hendrerit leo interdum id. Nulla facilisi. Aliquam nec mi id justo sagittis tristique. Quisque sit amet tellus sit amet turpis tincidunt convallis et et nunc. Nunc tristique non nibh vitae tristique. Aliquam tortor lacus, facilisis a velit non, vehicula egestas quam. Donec non urna vel nunc maximus condimentum.
Praesent a velit eu neque sodales rhoncus. Proin fermentum ac diam et tempor. Suspendisse hendrerit lacinia nibh id hendrerit. Integer vel risus est. Donec condimentum, lacus id accumsan interdum, arcu lectus gravida tellus, eu pellentesque urna ligula vel tortor. Nulla congue mi eget ipsum dapibus cursus. Curabitur vehicula tristique nulla a auctor. Vestibulum ut neque in turpis accumsan tincidunt. Nunc fermentum enim a felis tristique, sit amet tempor justo tempus. Ut ac tortor porttitor, sodales libero a, sodales libero. Ut varius, nulla ac placerat viverra, nunc sapien volutpat libero, ac rutrum velit felis a odio. Pellentesque ut odio ut sem vulputate volutpat. Proin a egestas augue. Vestibulum eget tellus purus.
In sit amet tristique metus, vitae eleifend nibh. Pellentesque quis mauris purus. Etiam lacinia lorem eget nisl sodales porttitor. In ac urna non sem efficitur aliquam. Nullam suscipit, lorem eget scelerisque posuere, diam augue vulputate elit, sit amet sagittis ipsum nulla quis odio. Proin felis justo, congue et nisi non, sagittis imperdiet dolor. Cras vestibulum sem libero, sed gravida felis tempor sit amet. Donec faucibus tellus ut diam laoreet molestie.
Nam ex erat, iaculis maximus ipsum non, blandit tempor risus. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris vitae ultrices turpis. In nec vehicula nibh. Sed sed pretium nulla. In hac habitasse platea dictumst. Integer posuere ipsum sed feugiat facilisis. Suspendisse non quam quis lacus venenatis venenatis. Suspendisse scelerisque, ipsum et venenatis ullamcorper, mauris velit consequat magna, et laoreet nunc augue nec elit. Morbi aliquam, felis eget ornare ullamcorper, nulla tortor mollis erat, et pellentesque est urna et urna. Ut scelerisque non augue blandit iaculis. Phasellus rutrum auctor ex, et pellentesque mauris lacinia vel. Praesent bibendum, quam id interdum molestie, sem magna pulvinar tellus, eget porta justo libero a elit. Fusce magna ante, sollicitudin vitae odio nec, elementum accumsan turpis. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
Integer sodales consequat tellus eu iaculis. Quisque accumsan ex in nulla eleifend lobortis. Nullam cursus sed diam eleifend pharetra. Aenean consectetur finibus mi, id dapibus sem elementum sed. Nunc ac quam orci. Etiam sollicitudin leo elit, sit amet dictum odio luctus nec. Sed interdum condimentum vulputate. Sed eu nisi ut nibh pulvinar cursus. Praesent at magna urna. Sed non tempus erat, nec posuere tortor. In at turpis porta, suscipit libero ut, placerat ex. Suspendisse eu blandit quam, eget eleifend metus. Donec sollicitudin viverra fermentum. Sed pretium pulvinar diam ut iaculis.
Quisque a elementum tellus. Fusce nec enim ullamcorper, egestas turpis quis, ullamcorper ligula. Vivamus convallis velit id nunc malesuada ullamcorper. Donec pharetra felis fermentum nulla dignissim, dapibus viverra dui volutpat. Duis ornare ante tellus, id porttitor erat pellentesque eget. Sed dignissim orci sit amet risus placerat, vel dignissim odio commodo. Nullam eros sem, porta vel egestas nec, cursus id tortor. Duis vitae quam quam. Maecenas commodo turpis tempus efficitur eleifend. Etiam fringilla scelerisque magna, ut rutrum ex aliquet a. Duis vulputate nisl at tincidunt posuere. Donec dignissim augue tellus, et ultricies velit maximus ac. Vestibulum a est ante.
Aliquam erat volutpat. Aenean non turpis diam. Cras auctor felis purus. Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Phasellus ullamcorper ipsum eu vehicula auctor. Praesent efficitur tempus dolor non scelerisque. Donec vel sapien at dolor porttitor tempor.
Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Pellentesque dapibus est a mi vulputate suscipit. Sed dignissim nibh eget metus maximus sagittis. Suspendisse potenti. Nullam id luctus urna, id iaculis sem. Nunc dui nulla, euismod et cursus a, aliquet ac odio. Maecenas bibendum varius urna. Nullam convallis mi vitae condimentum molestie. Sed rhoncus pellentesque tellus, eget hendrerit tortor consectetur vel. Sed posuere turpis sed facilisis dictum. Suspendisse non leo dui. Donec interdum bibendum ipsum, sed iaculis tortor malesuada id. Donec tincidunt tortor vel porta tincidunt. Quisque scelerisque nisi nec sem pharetra, sed porta ipsum elementum.'
          )
        ),
      ),
      'large file' => array(
        'content' => array(
          new File('test1.txt', File::FILE, 1, '
Duis fermentum egestas enim eget ullamcorper. Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Suspendisse fringilla quam id tortor sagittis ultricies. Praesent vel maximus odio, quis interdum ligula. Duis metus ante, sollicitudin a condimentum viverra, ultrices id sapien. Fusce nec urna quam. Donec blandit molestie tempus. Etiam tempor vitae leo a lacinia. Praesent aliquet non leo sit amet imperdiet. Etiam maximus, ipsum vitae porttitor tincidunt, elit quam ultrices enim, a malesuada justo diam vitae purus. Vestibulum rhoncus tristique tincidunt. Nunc et blandit ante, eu blandit lectus. Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae;
Donec non ligula cursus, tincidunt neque at, auctor lacus. Suspendisse varius orci placerat, posuere felis id, sagittis orci. Nullam varius hendrerit lectus non vulputate. Duis sagittis sodales urna at aliquet. Quisque vitae turpis ex. Pellentesque vitae interdum lacus, ac rutrum metus. In vestibulum turpis dolor, non elementum lorem finibus ac. Sed metus libero, convallis et vulputate elementum, feugiat et diam. Phasellus fringilla fermentum ante, a imperdiet justo tempor sit amet. Mauris consectetur sit amet odio nec venenatis. Integer malesuada elit ipsum, ut auctor diam auctor a.
Nulla facilisi. Phasellus volutpat fermentum lectus ac consectetur. Aliquam nulla augue, accumsan eget orci vitae, euismod ultrices eros. Pellentesque ultrices urna lacus, eu iaculis urna cursus sit amet. Aliquam gravida non sapien sed cursus. Sed viverra urna facilisis, aliquam nisl id, ultrices sapien. Nam mollis ultrices nunc sit amet placerat. Etiam id lorem a tortor viverra porta. Suspendisse finibus ultrices tristique. Vestibulum arcu ex, facilisis a ante sed, ultrices bibendum massa. Suspendisse justo quam, pellentesque eget orci sed, varius sagittis purus. Duis in maximus magna, a facilisis mi. Morbi elementum pulvinar justo. Nulla facilisi. Sed ac finibus urna. Ut felis sem, sagittis a justo id, feugiat interdum nibh.
Curabitur tristique bibendum ligula ut congue. In faucibus arcu sit amet diam iaculis posuere. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Donec nec libero blandit, porta augue quis, ultrices est. Aenean ultrices risus erat, vitae semper odio imperdiet eget. Phasellus dapibus id nulla iaculis aliquet. Aliquam sagittis quis diam vel pretium. Fusce ac egestas massa. Nunc tristique tincidunt quam et finibus.
In eu elit sed sapien pulvinar maximus. Suspendisse fermentum consequat sapien, vitae bibendum ante pharetra vitae. In placerat magna ac neque convallis aliquam eu nec turpis. Sed ut nisl eget ante blandit tempor vel id nisl. Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Vivamus id mauris a neque sodales volutpat non et est. Vestibulum rutrum pharetra mauris sed aliquet. Morbi auctor elementum justo ut vestibulum. Pellentesque ac tellus auctor, laoreet tellus eget, luctus magna. Donec dolor ligula, ornare eget urna a, euismod fringilla ipsum. Interdum et malesuada fames ac ante ipsum primis in faucibus. Ut eget magna a nunc imperdiet accumsan. Donec vitae mollis lacus. Cras faucibus viverra purus. Donec nec quam in quam finibus bibendum. Ut eget quam sem.
Sed sed ligula sed nisi sollicitudin accumsan vitae quis augue. Nunc sollicitudin ut augue sed hendrerit. Quisque turpis felis, hendrerit non quam et, mattis varius leo. Duis enim ipsum, porttitor vitae dui nec, pulvinar pharetra odio. Ut gravida blandit fermentum. Maecenas et commodo enim, in dignissim sem. Quisque vestibulum, metus ut aliquet sodales, quam tellus vehicula dui, vitae tincidunt neque tortor sit amet risus. Etiam ac cursus ligula.
Donec suscipit ipsum eu justo luctus consequat. Phasellus pellentesque tincidunt orci vel lobortis. Nam bibendum ex aliquam nunc varius molestie. Nullam quis enim faucibus orci accumsan congue. Nulla facilisi. Proin quis diam quis mauris consectetur auctor. Nam scelerisque diam eu diam vulputate, id fermentum risus pretium. Sed mauris ipsum, consectetur a odio ut, rutrum tempus velit.
Nunc faucibus consectetur cursus. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Praesent quis sem ac massa fringilla aliquet. Maecenas sed lectus pharetra, iaculis libero sit amet, lobortis mauris. Quisque sodales arcu id enim commodo ultricies. Phasellus accumsan, ante in dapibus tempus, velit dolor luctus turpis, at consequat ligula leo vel libero. Nullam gravida ante eu ligula facilisis fringilla. Sed auctor vitae sapien ultrices luctus.
Nam viverra turpis eros, eget rutrum orci commodo quis. Vestibulum sit amet lacus vel metus tincidunt euismod ac eget magna. Nullam bibendum velit libero, eget auctor libero ullamcorper non. Nullam sagittis id ligula et tincidunt. Sed dolor ante, mattis ac varius et, laoreet rutrum turpis. Cras sollicitudin nulla a dapibus finibus. Fusce tempor sed dui at vulputate. In efficitur luctus leo. Nam aliquam elementum justo. Donec eget iaculis justo. Etiam dapibus nulla sed libero rutrum ullamcorper. Aliquam tristique aliquet sapien et ultrices. Vivamus ut ex maximus, semper ex eget, condimentum velit. Mauris eget augue sit amet leo ultrices euismod ut ac nulla. Aenean eget mauris orci. Morbi congue enim orci, non maximus augue luctus quis.
Vestibulum rutrum lectus lacus, vel condimentum justo ultrices quis. Donec ac erat eu nisi sodales cursus. Nullam eu dolor ante. Nulla fringilla tortor ut dolor ullamcorper laoreet sed et tellus. Phasellus sapien odio, ornare et malesuada ac, semper id lorem. Duis malesuada iaculis sapien, sit amet fringilla magna vehicula posuere. Cras pellentesque est tellus, id pretium massa efficitur in. Duis id egestas leo. Etiam id aliquet lectus.
Nulla magna velit, dictum id dui quis, aliquam fringilla risus. Sed venenatis, mi sit amet consequat mattis, nisi velit imperdiet ipsum, maximus facilisis dui ipsum quis eros. Pellentesque eleifend lacus vitae quam auctor, non aliquet lacus consequat. Nam euismod venenatis vestibulum. Nunc sodales erat et diam accumsan ornare. Fusce vestibulum consectetur porttitor. Aenean maximus non tellus a sodales.
Cras et luctus arcu. Maecenas id ultrices dui. Proin mollis urna nec ligula tempor, ac ullamcorper odio placerat. Cras mi metus, condimentum ac urna in, egestas auctor neque. Vestibulum vel elementum mauris. Ut placerat quam elit, vitae ornare nisl laoreet in. Suspendisse venenatis ullamcorper nunc, eget tristique purus interdum eu. Cras ac est ac velit tincidunt varius. Suspendisse finibus ornare arcu at accumsan. Nulla a mollis est, sit amet viverra ipsum. Suspendisse a felis mattis, tincidunt felis quis, gravida justo. Suspendisse potenti. Cras consequat scelerisque lectus a imperdiet.
Duis tincidunt urna et leo tempus, a dapibus ante suscipit. Aenean pretium gravida nisl, in faucibus nibh aliquam id. Proin vel quam aliquet, sollicitudin orci in, interdum dolor. Mauris egestas non velit vitae sollicitudin. Pellentesque dignissim enim massa, et iaculis orci vehicula nec. Mauris sit amet blandit ex. Donec ac ullamcorper nisi, eu mollis tortor. Donec varius eros sit amet nunc vulputate tincidunt.
Proin molestie imperdiet dui at ultrices. Fusce blandit elit in arcu pellentesque varius. Aenean egestas arcu neque, non ornare ipsum euismod maximus. Proin egestas venenatis diam, quis feugiat diam pulvinar eget. Vestibulum ut sodales libero. Sed dapibus metus in aliquam pretium. Nulla et rutrum quam. Nullam nec nisi at velit molestie porttitor eget imperdiet lacus.
Sed tellus dui, scelerisque ac odio nec, pretium aliquet massa. Pellentesque nunc libero, malesuada efficitur urna at, congue dapibus lorem. Nunc scelerisque sed neque at faucibus. Sed posuere velit sem, vel sodales magna euismod a. Maecenas quis vehicula mi, malesuada tincidunt quam. Maecenas finibus vulputate tellus, non rutrum tortor imperdiet vel. Nunc tristique, lectus viverra mollis volutpat, turpis ante lobortis sem, nec vehicula dui ex in nisl. Vestibulum non vestibulum elit. Donec efficitur risus non massa aliquet hendrerit. Mauris convallis ultricies elit egestas porttitor. Vestibulum sed eleifend mi, et tincidunt mi. Etiam imperdiet placerat ex quis venenatis.
Aenean maximus sem lorem, finibus cursus ante tincidunt et. Vivamus hendrerit ligula velit, lacinia porta neque rutrum a. Nullam eu placerat nisl. Donec eget nulla quis sem malesuada efficitur. Aenean maximus turpis a lacus volutpat auctor. Phasellus dictum pharetra egestas. Maecenas non fringilla neque. Nunc non lectus erat. Phasellus pulvinar viverra egestas. Suspendisse semper nisi at purus tincidunt consectetur. Vestibulum sodales sapien sit amet diam ullamcorper, vitae laoreet lorem consequat. Ut felis quam, pellentesque a ultricies consequat, ornare eget quam. Donec quis interdum metus. Integer scelerisque, massa ac semper porta, magna purus pharetra libero, non malesuada nibh lectus quis orci. Praesent egestas dignissim pharetra. Duis eu eros velit.
Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Nam pulvinar porttitor molestie. Donec ornare tincidunt tellus. Morbi pulvinar bibendum eleifend. Nam et auctor sem, at dignissim nulla. Quisque facilisis commodo suscipit. Sed pretium, felis ac mollis tristique, elit tellus vulputate odio, eget tincidunt sapien lacus sed dui. Donec porta, ipsum vel rhoncus congue, leo justo tristique lorem, ac rutrum quam odio vel enim. Suspendisse ut leo nec ante hendrerit pulvinar eu et risus. Ut consequat arcu ac mauris viverra, facilisis tristique urna consectetur. Ut a lectus quis magna commodo luctus. Duis et dolor turpis.
Ut ante est, fermentum sed dolor et, tempus euismod nisi. Etiam porta augue at dictum dictum. Quisque mollis vitae justo at gravida. Vestibulum ipsum nunc, accumsan vel blandit ullamcorper, sollicitudin feugiat dolor. Donec pretium rutrum diam ac efficitur. In metus libero, vulputate in rhoncus sed, aliquet a mauris. Cras diam leo, tempor nec ante ut, tincidunt blandit nunc. Duis eu dolor porttitor, ullamcorper nulla non, suscipit dolor. Ut tristique viverra sem, vel malesuada nisi euismod iaculis. Aenean molestie tristique sodales. Duis quis dui justo. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Donec placerat, tortor ut suscipit aliquet, enim eros pulvinar sapien, quis porta quam odio et augue.
Cras bibendum interdum massa vitae posuere. Duis ut ipsum nec ipsum viverra tincidunt sed a urna. Mauris bibendum libero sit amet nisi tempus dignissim. Suspendisse sed lorem pulvinar, scelerisque ipsum non, feugiat nunc. Quisque ut purus libero. Curabitur gravida, nisi vel malesuada egestas, est sem venenatis libero, eget dapibus turpis tellus id ligula. Vivamus eget neque viverra, mollis diam ac, consectetur massa.
Nam eu posuere quam, non tincidunt libero. Fusce cursus sapien ac est vestibulum gravida. Cras sollicitudin ornare placerat. Maecenas eu sem gravida, tempus purus sit amet, accumsan ex. Donec vehicula, sem eu ultrices mollis, nisi eros condimentum enim, at dictum mauris est non libero. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Vestibulum et bibendum felis. Proin suscipit enim in hendrerit mollis. Aenean ornare fringilla purus, sit amet dapibus orci varius sit amet. Quisque posuere libero quis velit pharetra, sed euismod nisi scelerisque. Quisque varius ipsum eu neque dictum pharetra. Fusce hendrerit mi turpis, id eleifend metus egestas ac. Donec rhoncus nisi nunc. Sed vitae neque lacinia, mollis arcu ac, molestie ante.
Sed tortor nisi, eleifend sit amet lorem eget, pretium rutrum enim. Cras mi nulla, elementum vel bibendum at, consequat dictum lectus. Suspendisse dapibus, orci at cursus efficitur, lorem magna suscipit neque, ac congue leo nibh et odio. Mauris nulla felis, vehicula eget dolor vel, interdum ultricies risus. Cras risus ligula, semper nec nisi vel, aliquam molestie neque. Donec sollicitudin vestibulum auctor. Curabitur ullamcorper ligula vitae lacus efficitur elementum. Vestibulum imperdiet id enim id rutrum. Suspendisse condimentum ut ligula vel pellentesque.
Curabitur et ligula risus. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Interdum et malesuada fames ac ante ipsum primis in faucibus. Ut finibus nibh sit amet ipsum euismod consectetur. Morbi sed nunc ac nunc pretium pellentesque in ultrices elit. Sed ornare sodales velit eget maximus. Aenean non malesuada augue, pulvinar ultricies felis. Integer id ex ut eros auctor maximus.
Fusce at porta eros, lacinia finibus lacus. In eu odio ut nulla ullamcorper rhoncus vel a augue. Vestibulum vitae purus mollis mauris dignissim venenatis. Cras massa ante, pharetra non mollis in, interdum in felis. Nulla at metus et urna aliquam viverra. Curabitur egestas ante et ultrices cursus. Nulla convallis justo non dolor ornare vehicula. Nam non pellentesque ipsum. Etiam tristique ac elit vitae semper.
Donec et sem felis. Morbi tristique nulla eu ex semper mattis. Fusce cursus id tortor imperdiet convallis. Duis sagittis felis at magna laoreet pulvinar. Donec interdum felis nunc, nec ultrices turpis tincidunt a. Nunc scelerisque tellus at mi rhoncus, eu elementum lacus malesuada. Sed felis felis, tincidunt id metus nec, accumsan dictum magna. Integer ullamcorper purus leo, sed efficitur felis cursus eu.
Aenean vel nulla ac augue elementum pretium ornare non libero. Sed dictum dolor non quam scelerisque, a fringilla erat vehicula. Vivamus at erat ac sapien porta porttitor sed non mauris. Integer lacinia lacus vehicula, pellentesque nunc eget, sollicitudin odio. Vivamus dictum pharetra iaculis. Ut eu blandit arcu. Nullam euismod pretium euismod. Morbi eget lacus efficitur, dignissim lorem eu, elementum neque. Donec turpis mi, mattis a dignissim quis, facilisis nec nibh. Nullam ut lacinia arcu. Integer et leo lectus. Proin lobortis elit ut urna mollis fringilla. Nulla in elit non enim dignissim ultrices feugiat nec lorem. Integer tristique iaculis pulvinar. Fusce facilisis rhoncus neque.
Phasellus tincidunt diam id ipsum convallis, sed imperdiet diam sollicitudin. Nunc porta rhoncus condimentum. Nullam facilisis efficitur risus nec semper. Maecenas ipsum velit, porta ut luctus vel, varius ac metus. Aliquam cursus ultricies scelerisque. Fusce imperdiet lorem ultricies, mattis sem non, finibus augue. Nulla vestibulum nisl mi, id egestas tellus consectetur eget. Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Donec aliquam bibendum felis sed placerat. Suspendisse pellentesque mauris lacus, sit amet blandit lorem lobortis at. Sed et quam eget nibh imperdiet consequat in at metus.
Phasellus pellentesque tempor pharetra. Suspendisse id sagittis est, sit amet rhoncus neque. Aenean vitae magna ut arcu luctus auctor. Phasellus fermentum egestas tristique. Donec eu vestibulum mi. Nam dignissim lobortis tortor eu pulvinar. Etiam dapibus in lorem eget facilisis. Sed bibendum, turpis in rutrum rutrum, neque lorem tincidunt erat, eu commodo enim nisi a est. Maecenas vel laoreet magna. Etiam tempor augue posuere maximus ultrices.
Etiam ut placerat leo. Aenean ornare sapien eget lacus cursus sollicitudin. Pellentesque a elit consequat, viverra justo a, porta augue. Nam vitae ligula nec lectus eleifend auctor vitae vitae tellus. Nulla facilisi. Ut finibus commodo tristique. Integer vulputate augue a tellus finibus facilisis. Fusce a eleifend magna. Aenean eu auctor urna, sit amet consequat odio.
Suspendisse potenti. Curabitur ultricies hendrerit leo, sed mollis mauris hendrerit quis. Nulla facilisi. Aliquam non lectus a augue pulvinar scelerisque vitae euismod tellus. Ut tristique sem sit amet efficitur gravida. Nullam ac pulvinar arcu, sit amet porttitor lectus. Proin pulvinar neque ultrices, viverra urna sed, ullamcorper neque. Etiam sollicitudin volutpat aliquet.
Praesent vestibulum vulputate nunc vitae tincidunt. Cras euismod lectus sit amet est iaculis bibendum. Donec quis sodales ligula. Duis gravida lacinia nulla, vitae dapibus est blandit dignissim. Suspendisse sed vehicula eros, at pellentesque orci. Fusce blandit venenatis ipsum quis facilisis. Integer sollicitudin, diam sed mollis semper, ligula urna bibendum leo, et sagittis metus elit vitae sem. Integer auctor aliquam erat eu laoreet. Sed in metus odio. Mauris vel blandit arcu, et semper felis. Aliquam id fringilla dolor, nec volutpat sem. Cras ac erat erat. Duis vel justo laoreet, convallis velit sit amet, tristique nulla. Etiam vel pharetra ligula, ac ultricies est. Proin accumsan urna nunc, in cursus ligula consectetur sed. Nullam interdum, dui sit amet accumsan mattis, nisi massa iaculis turpis, eu condimentum nibh libero scelerisque metus.
Pellentesque vitae diam purus. Ut fermentum ex sed diam placerat auctor. Phasellus sollicitudin ante purus, id interdum magna lobortis sit amet. Fusce molestie, purus vel pharetra iaculis, elit ex egestas lorem, sit amet maximus odio eros eu dolor. Vivamus quis dapibus nisl, elementum sodales dolor. Nulla ac tortor quis libero interdum lobortis. Vivamus aliquam urna ac tortor scelerisque viverra id quis tortor. Vestibulum lacus sem, vestibulum eget lorem a, ullamcorper tristique ligula. Pellentesque et orci dictum tortor imperdiet luctus eu nec diam. Sed a libero in diam luctus dapibus non eu odio. Vivamus malesuada mauris et tristique hendrerit. Pellentesque fringilla et mauris vitae finibus. Aenean pretium faucibus nibh, ut mattis orci convallis vel. Fusce semper ex ex, id luctus est cursus in.
Sed ut accumsan erat, a aliquam nisi. Mauris rhoncus leo ut semper laoreet. Integer lobortis dui ac urna tempor ultricies sed consectetur tortor. Suspendisse a gravida diam, vel hendrerit ex. Suspendisse cursus libero non turpis pharetra, eget laoreet risus maximus. Nam at ullamcorper magna, quis molestie eros. Suspendisse interdum neque leo, ullamcorper ultrices ante iaculis ac. Aliquam luctus euismod libero, sed semper arcu viverra ut. Sed pretium nisi justo, ut dignissim dolor luctus vitae. Vivamus egestas neque vel accumsan volutpat. Etiam sed fermentum enim. Proin ac augue dui.
Nunc ac nulla eget elit viverra vehicula ac vel dolor. Quisque in dignissim ipsum, in fermentum mauris. In hac habitasse platea dictumst. Phasellus ut orci nec enim vehicula vehicula. Phasellus facilisis efficitur libero, nec pellentesque augue vehicula a. Nam id gravida tellus, quis semper lorem. Proin fermentum orci orci, vitae ultricies magna malesuada quis. Cras maximus tempus magna, eget bibendum orci varius ac. Sed sollicitudin fermentum magna at tempus. Maecenas euismod bibendum sem a gravida. Curabitur ultricies dapibus orci, vitae auctor ipsum bibendum vel. Nunc lobortis scelerisque risus quis mollis. Sed cursus nibh ac metus finibus consequat. Proin mattis leo at ante tincidunt, id semper metus malesuada. Quisque et enim nec quam ornare tempor ut vel neque. Donec commodo leo quis urna fermentum lacinia.
Sed dictum ex vitae pellentesque viverra. Vivamus mi metus, auctor non sodales quis, dignissim et massa. Quisque at enim rhoncus, placerat nunc in, sollicitudin purus. Aenean dictum, ex quis suscipit aliquam, mauris felis efficitur nisl, vel vestibulum sapien libero at urna. Sed at sem eget dui imperdiet faucibus. Duis purus tortor, viverra et faucibus at, interdum at ipsum. Duis iaculis in leo vel consectetur.
Morbi dignissim, turpis a porttitor pretium, lectus urna sollicitudin massa, sed pharetra leo ex et ligula. Cras augue ex, porttitor eget tempor non, faucibus in augue. Nunc sit amet lacus nec neque iaculis hendrerit eget in leo. Mauris maximus tristique quam sit amet rutrum. Praesent porta dui ligula, eu lacinia eros fermentum et. Nullam in ante eu tellus bibendum vestibulum. Phasellus magna ligula, venenatis egestas dapibus non, lacinia ac turpis. Praesent a ipsum maximus, egestas neque eget, dictum nunc. Quisque bibendum eros vitae ipsum ullamcorper, non gravida ante auctor. Fusce rhoncus malesuada augue et auctor.
Nunc magna ligula, facilisis in luctus vel, vehicula sit amet nibh. Sed semper, tellus sit amet efficitur eleifend, lorem nunc vehicula ante, et tristique massa enim vel quam. Morbi urna urna, pulvinar id ex sit amet, facilisis dapibus neque. Morbi sollicitudin arcu ex, ut ultrices mauris ornare sed. Pellentesque pellentesque, orci et consectetur scelerisque, elit nunc aliquet nibh, vitae ultricies purus nunc eu arcu. Nunc justo turpis, elementum ut nulla eu, vestibulum blandit orci. Donec eu nisi non erat aliquam sagittis. Sed posuere lacus sed imperdiet tincidunt. Proin viverra leo at felis accumsan vestibulum. Pellentesque sollicitudin vehicula est, non placerat lacus dapibus at. Curabitur nec est eros. Etiam eget malesuada nisl. Nam maximus egestas eros, nec ullamcorper mauris dictum a. Sed massa tellus, hendrerit quis arcu ut, ultricies aliquam quam. Vivamus fermentum euismod diam, vitae elementum enim tincidunt et. Integer vel felis ut ipsum scelerisque volutpat sed quis ex.
Curabitur sagittis gravida nibh, eget pharetra nulla ornare a. Mauris dictum congue neque. Aenean a tempus mi. Donec id neque aliquet, luctus mauris et, dignissim est. Proin consectetur consequat mattis. Phasellus interdum dui ut tortor porta convallis. Duis laoreet id arcu vitae ullamcorper. Cras malesuada tellus sed justo tincidunt ultricies. Nunc vel mi libero. Sed non aliquet metus, varius varius diam. Cras nec elit nisl. Etiam vestibulum ligula magna, quis hendrerit ligula vehicula nec. Aliquam faucibus diam eu lacus tincidunt, sollicitudin rutrum mauris maximus.
Nullam sollicitudin eget elit eget dapibus. Nunc congue porttitor dapibus. Nam pretium laoreet semper. Vivamus et nisi eu justo bibendum tristique. Aenean at efficitur tortor. Curabitur eu velit vulputate arcu consequat consectetur in sed ante. Integer rutrum laoreet ipsum, eget lacinia tortor elementum nec. Proin ut euismod velit. Nunc finibus, augue sed venenatis mattis, dui enim mollis quam, vel blandit mi sem non odio. Mauris nec enim eros. Morbi dictum malesuada purus, et vehicula nunc efficitur sit amet. Praesent sit amet tortor a enim tincidunt faucibus. Pellentesque imperdiet commodo vestibulum.
Maecenas dui dui, mattis non elit a, rhoncus tincidunt sem. Quisque non congue orci. Sed sit amet suscipit augue. Vestibulum lacus velit, condimentum eget est in, facilisis volutpat sapien. Nam mattis sed sem aliquet venenatis. Integer tempus gravida leo in sodales. Etiam ac orci vel metus auctor fermentum. Vestibulum sit amet mauris tortor. Donec vulputate, velit sed blandit tincidunt, ante lorem mattis purus, in hendrerit ligula lacus a arcu. Etiam consequat ut nulla nec iaculis. Proin porttitor ornare risus, non congue augue faucibus quis. Morbi et urna vestibulum, dignissim libero et, vestibulum enim. Maecenas sit amet mi sed ex blandit semper nec quis leo.
Nullam libero velit, tincidunt vitae arcu sit amet, bibendum interdum mauris. Morbi pretium felis quis lorem feugiat tincidunt nec et velit. Phasellus scelerisque elit facilisis interdum dapibus. Quisque at dui non nulla lacinia volutpat. Phasellus nunc tortor, elementum id commodo at, faucibus gravida nibh. Phasellus id risus rhoncus, condimentum urna sit amet, aliquam lacus. Nullam molestie nulla convallis urna mollis, suscipit sagittis tellus gravida. Mauris aliquet ut lectus sed fermentum. Aenean porttitor tempor nisi, et facilisis odio ornare non. Quisque rhoncus diam sollicitudin metus ultrices, et tempor quam bibendum. Aliquam commodo augue ligula, eu aliquam purus mattis sit amet. Curabitur rutrum laoreet elit at pulvinar. Proin in aliquet ante. Nulla tempor mi magna, at blandit neque ultrices sit amet.
Sed vel lorem eu nisi volutpat placerat id ut lacus. Sed a facilisis quam. Vestibulum ac lacus in nisl mattis porta. Praesent iaculis rhoncus sem at hendrerit. Quisque elementum augue at gravida maximus. Interdum et malesuada fames ac ante ipsum primis in faucibus. Pellentesque auctor neque sed enim sollicitudin, sit amet scelerisque purus tempor. Integer eget nibh ante. Cras rhoncus hendrerit aliquet. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Aenean mollis ante vel libero cursus, nec scelerisque ligula tincidunt. Maecenas at ultricies nulla. Sed malesuada odio id arcu accumsan, nec gravida urna tincidunt.
Ut id lacus ut dolor rutrum iaculis accumsan id est. Sed consectetur varius lectus et hendrerit. Quisque consequat ante a purus aliquet sodales. Maecenas pretium, mauris sit amet dignissim tempor, quam tortor consectetur eros, vitae imperdiet metus lectus a leo. Mauris sed egestas metus. Vestibulum ut gravida ante, nec malesuada justo. Quisque id quam in purus viverra dapibus. Vestibulum at nisi ex. Interdum et malesuada fames ac ante ipsum primis in faucibus. Pellentesque purus justo, finibus id tortor nec, rhoncus aliquet justo. Ut lobortis, turpis vel molestie tincidunt, justo mi sodales metus, eu convallis lorem purus in nisi. Aenean vel sapien sodales, feugiat diam a, cursus nulla. Maecenas vel venenatis libero. Praesent dapibus eget massa in efficitur. Suspendisse vitae leo eget nunc gravida elementum nec a nulla. Mauris fringilla mauris vitae pellentesque accumsan.
Quisque eu mi sed enim efficitur fringilla. Ut vel congue erat. Duis vel turpis dolor. Nam sit amet nulla suscipit, commodo quam sed, tincidunt dolor. Nam sed placerat lorem. Sed mi metus, hendrerit in posuere eget, vestibulum vitae tellus. Sed eu augue ante. Vivamus leo dolor, congue eget blandit sed, vestibulum vitae quam. Morbi eget mi et purus vulputate malesuada commodo nec odio. Nullam vel sagittis est, vitae tincidunt metus. Aliquam in tincidunt metus, id congue turpis. Donec vitae nibh ac leo consectetur faucibus vel nec lorem. Ut mollis interdum laoreet. Vivamus eget metus felis. Nulla sit amet sapien nibh. Donec mollis congue nisi, vitae congue mi iaculis cursus.
Sed fermentum metus non erat tristique vestibulum. Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Fusce tortor tellus, lacinia at posuere in, condimentum id nulla. Duis et molestie est. Vestibulum sit amet iaculis nulla, faucibus accumsan eros. Nunc rutrum ipsum vitae ex luctus, ut rhoncus justo volutpat. Etiam quis quam aliquam diam viverra fermentum. Sed et sollicitudin turpis. Nam neque justo, rutrum ac aliquet vel, placerat sed mi. Vestibulum porttitor lacus et odio lobortis, at euismod purus mollis. Sed auctor suscipit ipsum ut egestas. Aliquam sit amet velit semper, cursus arcu id, mattis ipsum. Ut congue, erat venenatis dapibus bibendum, nibh est dictum dolor, eu mollis orci lacus eu elit.
Mauris eget dolor at nisl maximus sollicitudin. In tincidunt efficitur turpis et mattis. Proin sit amet arcu commodo, pharetra est vitae, ultrices dui. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Sed facilisis metus a nisi viverra sollicitudin. Pellentesque eget tortor a leo rhoncus tempor non ac risus. Mauris vulputate dolor et magna ornare commodo et ac erat. Nunc molestie sagittis tempor. Cras quis molestie magna. Cras tempor quis nulla quis cursus. Quisque sed nisl non orci viverra volutpat et vitae libero. Duis sagittis bibendum accumsan. Integer pellentesque orci in nulla euismod, et porttitor eros lacinia. Fusce ultricies consectetur lorem ut tincidunt. Nullam nec turpis nec urna malesuada faucibus.
Proin ac pharetra arcu. Vivamus rhoncus eros eget elit condimentum varius. Nunc blandit semper dolor. Ut lacinia mattis leo, et cursus leo condimentum et. Suspendisse potenti. Vestibulum et sagittis lacus. Suspendisse mollis facilisis velit quis semper. Morbi malesuada odio a posuere sollicitudin. In sed elit non velit commodo condimentum sit amet ut nisi.
Maecenas ornare purus lorem, sed rhoncus ex bibendum aliquet. Sed malesuada, turpis at luctus rhoncus, ipsum enim egestas urna, ut posuere mi nulla gravida lectus. Morbi malesuada est vel sagittis finibus. Vestibulum eget odio nulla. Duis mi mauris, pharetra ac elit in, ornare porta diam. Nullam erat diam, hendrerit ut pretium ut, dapibus vitae dolor. Nullam vel tempor risus. Nulla blandit sodales viverra.
Vivamus sit amet venenatis velit. Fusce imperdiet efficitur felis, non porttitor est sagittis vitae. Mauris ac faucibus lectus. Nullam in tortor purus. Praesent nunc leo, suscipit sodales rutrum eget, sodales quis felis. Praesent et nulla imperdiet, posuere enim vitae, placerat turpis. Integer ornare ipsum ac mi dictum, id iaculis lectus ultrices. Phasellus feugiat dictum quam ac placerat. Etiam a nulla elit. Donec elementum, ex eu fermentum ornare, eros nulla tincidunt est, ac scelerisque velit enim ultrices augue.
Praesent id quam ut ex gravida commodo. Etiam tristique felis arcu, tempor accumsan libero faucibus eu. Donec in libero a tortor scelerisque maximus. Fusce justo tortor, condimentum et lacus in, vulputate efficitur ante. Nunc feugiat scelerisque tempus. Integer libero diam, fringilla at diam congue, placerat maximus nunc. Vivamus lobortis id felis elementum tempor. Aenean mattis massa ligula, sit amet rutrum risus viverra sit amet. Phasellus a lobortis purus. Nunc vel ex tristique, eleifend mauris sed, vestibulum eros. Vestibulum ac dolor volutpat neque suscipit posuere. Ut nibh turpis, fermentum sit amet mollis vel, tincidunt in turpis. Vivamus non interdum magna. Sed lobortis nisi urna, eget mollis felis ornare ac. Duis eu lacinia tortor, sit amet pharetra nisi.'
          )
        ),
      ),
      'simple structure' => array(
        'content' => array(
          new File('test1.txt', File::FILE, 1, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed elit diam, posuere vel aliquet et, malesuada quis purus. Aliquam mattis aliquet massa, a semper sem porta in. Aliquam consectetur ligula a nulla vestibulum dictum. Interdum et malesuada fames ac ante ipsum primis in faucibus. Nullam luctus faucibus urna, accumsan cursus neque laoreet eu. Suspendisse potenti. Nulla ut feugiat neque. Maecenas molestie felis non purus tempor, in blandit ligula tincidunt. Ut in tortor sit amet nisi rutrum vestibulum vel quis tortor. Sed bibendum mauris sit amet gravida tristique. Ut hendrerit sapien vel tellus dapibus, eu pharetra nulla adipiscing. Donec in quam faucibus, cursus lacus sed, elementum ligula. Morbi volutpat vel lacus malesuada condimentum. Fusce consectetur nisl euismod justo volutpat sodales.'),
          new File('test/', File::DIR, 1),
          new File('test/test12.txt', File::FILE, 1, 'Duis malesuada lorem lorem, id sodales sapien sagittis ac. Donec in porttitor tellus, eu aliquam elit. Curabitur eu aliquam eros. Nulla accumsan augue quam, et consectetur quam eleifend eget. Donec cursus dolor lacus, eget pellentesque risus tincidunt at. Pellentesque rhoncus purus eget semper porta. Duis in magna tincidunt, fermentum orci non, consectetur nibh. Aliquam tortor eros, dignissim a posuere ac, rhoncus a justo. Sed sagittis velit ac massa pulvinar, ac pharetra ipsum fermentum. Etiam commodo lorem a scelerisque facilisis.')
        ),
      )
    );

    $data = array();
    foreach ($zip64Options as $zip64) {
      foreach ($compressOptions as $compress) {
        $levels = $defaultLevelOption;
        if (COMPR::DEFLATE == $compress[0]) {
          $levels = array_merge($levels, $levelOptions);
        }
        foreach ($levels as $level) {
          foreach ($fileSets as $descr => $fileSet) {
            $options = array(
              'zip64' => $zip64[0],
              'compress' => $compress[0],
              'level' => $level[0],
            );
            $description = $descr . ' (options[zip64=' . $zip64[1] . ',compress=' . $compress[1] . ',level=' . $level[1] . '])';

            // The dataProvider flag means these rows are passed as args to the
            // testZipFile() et al test functions.
            array_push($data, array(
              $options,
              $fileSet['content'],
              $description,
            ));
          }
        }
      }
    }
    return $data;
  }

  /**
   * @dataProvider providerZipfileOK
   */
  public function testZipfile($options, $files, $description) {
    $options = array_merge($options, array('outstream' => $this->outstream));
    $zip = new ZipStreamer\ZipStreamer($options);
    foreach ($files as $file) {
      if (File::DIR == $file->type) {
        $zip->addEmptyDir($file->filename, array('timestamp' => $file->date));
      } else {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $file->data);
        rewind($stream);
        $zip->addFileFromStream($stream, $file->filename, array('timestamp' => $file->date));
        fclose($stream);
      }
    }
    $zip->finalize();

    $options['fileheader_nonzero'] = FALSE;
    $this->assertOutputZipfileOK($files, $options);
  }

  /**
   * @dataProvider providerZipfileOK
   */
  public function testZipfileString($options, $files, $description) {
    $options = array_merge($options, array('outstream' => $this->outstream));
    $zip = new ZipStreamer\ZipStreamer($options);
    foreach ($files as $file) {
      if (File::DIR == $file->type) {
        $zip->addEmptyDir($file->filename, array('timestamp' => $file->date));
      } else {
        $zip->addFileFromString($file->data, $file->filename, array('timestamp' => $file->date));
      }
    }
    $zip->finalize();

    $options['fileheader_nonzero'] = TRUE;
    $this->assertOutputZipfileOK($files, $options);
  }

  /**
   * @dataProvider providerZipfileOK
   */
  public function testZipfileStreamed($options, $files, $description) {
    $options = array_merge($options, array('outstream' => $this->outstream));
    $zip = new ZipStreamer\ZipStreamer($options);
    foreach ($files as $file) {
      if (File::DIR == $file->type) {
        $zip->addEmptyDir($file->filename, array('timestamp' => $file->date));
      } else {
        $zip->addFileOpen($file->filename, array('timestamp' => $file->date));
        $zip->addFileWrite($file->data);
        $zip->addFileClose();
      }
    }
    $zip->finalize();

    $options['fileheader_nonzero'] = FALSE;
    $this->assertOutputZipfileOK($files, $options);
  }

  /** https://github.com/McNetic/PHPZipStreamer/issues/29
  *  ZipStreamer produces an error when the size of a file to be added is a
  *   multiple of the STREAM_CHUNK_SIZE (also for empty files)
  */
  public function testIssue29() {
    $options = array('zip64' => True,'compress' => COMPR::DEFLATE, 'outstream' => $this->outstream);
    $zip = new ZipStreamer\ZipStreamer($options);
    $stream = fopen('php://memory', 'r+');
    $zip->addFileFromStream($stream, "test.bin");
    fclose($stream);
    $zip->finalize();
  }
}
