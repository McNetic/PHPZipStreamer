<?php
/**
 * Class to create zip files on the fly and stream directly to the HTTP client as the content is added.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Inspired by
 * CreateZipFile by Rochak Chauhan  www.rochakchauhan.com (http://www.phpclasses.org/browse/package/2322.html)
 * and
 * ZipStream by A. Grandt https://github.com/Grandt/PHPZip (http://www.phpclasses.org/package/6116)
 *
 * Unix-File attributes according to
 * http://unix.stackexchange.com/questions/14705/the-zip-formats-external-file-attribute
 *
 * @author Nicolai Ehemann <en@enlightened.de>
 * @author André Rothe <arothe@zks.uni-leipzig.de>
 * @author Ruth Ivimey-Cook <ruth@ivimey.org>
 * @copyright Copyright (C) 2013-2016 Ruth Ivimey-Cook, Nicolai Ehemann and contributors
 * @license GNU GPL
 * @version 2.0
 */
namespace Rivimey\ZipStreamer;

use Rivimey\ZipStreamer\Count64\PackBits;
use Rivimey\ZipStreamer\DOS;
use Rivimey\ZipStreamer\UNIX;
use Rivimey\ZipStreamer\Count64\Count64;
use Rivimey\ZipStreamer\Count64\Count64_32;
use Rivimey\ZipStreamer\Count64\Count64_64;
use Rivimey\ZipStreamer\Deflate\COMPR;
use Rivimey\ZipStreamer\Deflate\DeflateStream;
use Rivimey\ZipStreamer\Deflate\DeflatePeclStream;
use Rivimey\ZipStreamer\Deflate\DeflateStoreStream;

class ZipStreamer {
  const VERSION = "1.0";

  const ZIP_LOCAL_FILE_HEADER = 0x04034b50; // local file header signature
  const ZIP_CENTRAL_FILE_HEADER = 0x02014b50; // central file header signature
  const ZIP_END_OF_CENTRAL_DIRECTORY = 0x06054b50; // end of central directory record
  const ZIP64_END_OF_CENTRAL_DIRECTORY = 0x06064b50; //zip64 end of central directory record
  const ZIP64_END_OF_CENTRAL_DIR_LOCATOR = 0x07064b50; // zip64 end of central directory locator

  const ATTR_MADE_BY_VERSION = 0x032d; // made by version  (upper byte: UNIX, lower byte v4.5)

  const STREAM_CHUNK_SIZE = 1048560; // 16 * 65535 = almost 1mb chunks, for best deflate performance

  private $extFileAttrFile;
  private $extFileAttrDir;

  /** @var stream output stream zip file is written to */
  private $outstream;
  /** @var boolean zip64 enabled */
  private $zip64 = True;
  /** @var int compression method */
  private $compress;
  /** @var int compression level */
  private $level;

  /** @var array central directory record */
  private $cdRec = array();
  /** @var int offset of next file to be added */
  private $offset;
  /** @var boolean indicates zip is finalized and sent to client; no further addition possible */
  private $isFinalized = False;

  /*
   * These values are used to persist state during addFileOpen/Write/Close.
   */
  private $isFileOpen = False;
  private $hashCtx = False;
  private $filePath;
  private $addFileOptions;
  private $gpFlags;
  private $dataLength;
  private $lfhLength;
  private $gzLength;

  /**
   * Constructor. Initializes ZipStreamer object for immediate usage.
   * @param array $options Optional, ZipStreamer and zip file options as key/value pairs.
   *                       Valid options are:
   *                       * outstream: stream the zip file is output to (default: stdout)
   *                       * zip64: enabled/disable zip64 support (default: True)
   *                       * compress: int, compression method (one of COMPR::STORE,
   *                                   COMPR::DEFLATE, default COMPR::STORE)
   *                                   can be overridden for single files
   *                       * level: int, compression level (one of COMPR::NORMAL,
   *                                COMPR::MAXIMUM, COMPR::SUPERFAST, default COMPR::NORMAL)
   */
  function __construct($options = NULL) {
    $defaultOptions = array(
      'outstream' => NULL,
      'zip64' => True,
      'compress' => COMPR::STORE,
      'level' => COMPR::NORMAL,
    );
    if (is_null($options)) {
      $options = array();
    }
    $options = array_merge($defaultOptions, $options);

    if ($options['outstream']) {
      $this->outstream = $options['outstream'];
    }
    else {
      $this->outstream = fopen('php://output', 'w');
    }
    $this->zip64 = $options['zip64'];
    $this->compress = $options['compress'];
    $this->level = $options['level'];
    $this->validateCompressionOptions($this->compress, $this->level);
    //TODO: is this advisable/necessary?
    if (ini_get('zlib.output_compression')) {
      ini_set('zlib.output_compression', 'Off');
    }
    // initialize default external file attributes
    $this->extFileAttrFile = UNIX::getExtFileAttr(UNIX::S_IFREG |
                                                  UNIX::S_IRUSR | UNIX::S_IWUSR | UNIX::S_IRGRP |
                                                  UNIX::S_IROTH);
    $this->extFileAttrDir = UNIX::getExtFileAttr(UNIX::S_IFDIR |
                                                 UNIX::S_IRWXU | UNIX::S_IRGRP | UNIX::S_IXGRP |
                                                 UNIX::S_IROTH | UNIX::S_IXOTH) |
                            DOS::getExtFileAttr(DOS::DIR);
    $this->offset = Count64::construct(0, !$this->zip64);
  }

  function __destruct() {
    $this->isFinalized = True;
    $this->cdRec = NULL;
  }

  private function getVersionToExtract($isDir) {
    if ($this->zip64) {
      $version = 0x2d; // 4.5 - File uses ZIP64 format extensions
    }
    else if ($isDir) {
      $version = 0x14; // 2.0 - File is a folder (directory)
    }
    else {
      $version = 0x0a; //   1.0 - Default value
    }
    return $version;
  }

  /**
   * Send appropriate http headers before streaming the zip file and disable output buffering.
   * This method, if used, has to be called before adding anything to the zip file.
   *
   * @param string $archiveName
   *   Filename of archive to be created (optional, default 'archive.zip')
   * @param string $contentType
   *   Content mime type to be set (optional, default 'application/zip')
   */
  public function sendHeaders($archiveName = 'archive.zip', $contentType = 'application/zip') {
    $headerFile = NULL;
    $headerLine = NULL;
    if (!headers_sent($headerFile, $headerLine)
        or die("<p><strong>Error:</strong> Unable to send file " .
               "$archiveName. HTML Headers have already been sent from " .
               "<strong>$headerFile</strong> in line <strong>$headerLine" .
               "</strong></p>")
    ) {
      if ((ob_get_contents() === False || ob_get_contents() == '')
          or die("\n<p><strong>Error:</strong> Unable to send file " .
                 "<strong>$archiveName.epub</strong>. Output buffer " .
                 "already contains text (typically warnings or errors).</p>")
      ) {
        header('Pragma: public');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s T'));
        header('Expires: 0');
        header('Accept-Ranges: bytes');
        header('Connection: Keep-Alive');
        header('Content-Type: ' . $contentType);
        // Use UTF-8 filenames when not using Internet Explorer
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') > 0) {
          header('Content-Disposition: attachment; filename="' . rawurlencode($archiveName) . '"');
        }
        else {
          header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($archiveName)
                 . '; filename="' . rawurlencode($archiveName) . '"');
        }
        header('Content-Transfer-Encoding: binary');
      }
    }
    $this->flush();
    // turn off output buffering
    @ob_end_flush();
  }

  /**
   * Add a file to the archive at the specified location and file name.
   *
   * @param string $stream
   *   Stream to read data from
   * @param string $filePath
   *   Filepath and name to be used in the archive.
   * @param array $options
   *   Optional, additional options
   *   Valid options are:
   *      * int timestamp: timestamp for the file (default: current time)
   *      * string comment: comment to be added for this file (default: none)
   *      * int compress: compression method (override global option for this file)
   *      * int level: compression level (override global option for this file)
   *
   * @return bool $success
   */
  public function addFileFromStream($stream, $filePath, $options = NULL) {
    if ($this->isFinalized) {
      return False;
    }
    $defaultOptions = array(
      'timestamp' => NULL,
      'comment' => NULL,
      'compress' => $this->compress,
      'level' => $this->level,
    );
    if (is_null($options)) {
      $options = array();
    }
    $options = array_merge($defaultOptions, $options);
    $this->validateCompressionOptions($options['compress'], $options['level']);

    if (!is_resource($stream) || get_resource_type($stream) != 'stream') {
      return False;
    }

    $filePath = self::normalizeFilePath($filePath);

    $gpFlags = GPFLAGS::ADD;

    list($gpFlags, $lfhLength) =
      $this->beginFile($filePath, False, $options['comment'],
                       $options['timestamp'], $gpFlags, $options['compress']);

    list($dataLength, $gzLength, $dataCRC32) =
      $this->streamFileData($stream, $options['compress'], $options['level']);

    $ddLength = $this->addDataDescriptor($dataLength, $gzLength, $dataCRC32);

    // build cdRec
    $this->cdRec[] =
      $this->buildCentralDirectoryHeader($filePath, $options['timestamp'],
                                         $gpFlags, $options['compress'],
                                         $dataLength, $gzLength, $dataCRC32,
                                         $this->extFileAttrFile, False);

    // calc offset
    $this->offset->add($ddLength)->add($lfhLength)->add($gzLength);

    return True;
  }

  /**
   * Add a file to the archive at the specified location and file name.
   *
   * @param string $stream Stream to read data from
   * @param string $filePath Filepath and name to be used in the archive.
   * @param array $options Optional, additional options
   *                            Valid options are:
   *                               * int timestamp: timestamp for the file (default: current time)
   *                               * string comment: comment to be added for this file (default: none)
   *                               * int compress: compression method (override global option for this file)
   *                               * int level: compression level (override global option for this file)
   * @return bool $success
   */
  public function addFileOpen($filePath, $options = NULL) {
    if ($this->isFinalized || $this->isFileOpen) {
      return False;
    }
    $this->isFileOpen = True;
    $defaultOptions = array(
      'timestamp' => NULL,
      'comment' => NULL,
      'compress' => $this->compress,
      'level' => $this->level,
    );
    if (is_null($options)) {
      $options = array();
    }
    $this->addFileOptions = array_merge($defaultOptions, $options);
    $this->validateCompressionOptions($this->addFileOptions['compress'], $this->addFileOptions['level']);

    $this->filePath = self::normalizeFilePath($filePath);
    $this->gpFlags = GPFLAGS::ADD;
    $this->dataLength = Count64::construct(0, !$this->zip64);
    $this->gzLength = Count64::construct(0, !$this->zip64);
    $this->hashCtx = hash_init('crc32b');
    if (COMPR::DEFLATE === $this->addFileOptions['compress']) {
      $this->compStream = DeflateStream::create($this->addFileOptions['level']);
    }

    list($this->gpFlags, $this->lfhLength) =
      $this->beginFile($this->filePath, False,
                       $this->addFileOptions['comment'], $this->addFileOptions['timestamp'],
                       $this->gpFlags, $this->addFileOptions['compress'],
                       0, 0, 0);
    return True;
  }

  /**
   * Add another block of data to the file opened with addFileOpen().
   *
   * @param string $block
   *   Data to write to the file.
   * @return bool $success
   *   False if there is no file open with addFileOpen(), else True.
   */
  public function addFileWrite($block) {
    if ($this->isFinalized || !$this->isFileOpen) {
      return False;
    }
    $this->writeFile($block, $this->addFileOptions['compress'], $this->addFileOptions['level']);
    $this->flush();

    return True;
  }

  /**
   * Close the file in the archive.
   *
   * @return bool $success
   */
  public function addFileClose() {
    if ($this->isFinalized || !$this->isFileOpen) {
      return False;
    }

    if (COMPR::DEFLATE === $this->addFileOptions['compress']) {
      $data = $this->compStream->finish();
      unset($this->compStream);

      $this->gzLength->add(strlen($data));
      $this->write($data);
    }
    $this->flush();
    $this->dataCRC32 = hexdec(hash_final($this->hashCtx));

    $ddLength = $this->addDataDescriptor($this->dataLength, $this->gzLength, $this->dataCRC32);

    // build cdRec
    $this->cdRec[] =
      $this->buildCentralDirectoryHeader($this->filePath, $this->addFileOptions['timestamp'],
                                         $this->gpFlags, $this->addFileOptions['compress'],
                                         $this->dataLength, $this->gzLength,
                                         $this->dataCRC32, $this->extFileAttrFile, False);

    // calc offset
    $this->offset->add($ddLength)->add($this->lfhLength)->add($this->gzLength);
    $this->isFileOpen = False;
    return True;
  }

  /**
   * Add a file to the archive at the specified location and file name.
   *
   * @param string $data
   *   The (complete) contents of the file.
   * @param string $filePath
   *   Filepath and name to be used in the archive.
   * @param array $options Optional, additional options
   *   Valid options are:
   *    * int timestamp: timestamp for the file (default: current time)
   *    * string comment: comment to be added for this file (default: none)
   *    * it compress: compression method (override global option for this file)
   *    * nt level: compression level (override global option for this file)
   * @return bool $success
   */
  public function addFileFromString($data, $filePath, $options = NULL) {
    if ($this->isFinalized) {
      return False;
    }

    $defaultOptions = array(
      'timestamp' => NULL,
      'comment' => NULL,
      'compress' => $this->compress,
      'level' => $this->level,
    );
    if (is_null($options)) {
      $options = array();
    }
    $options = array_merge($defaultOptions, $options);
    $this->validateCompressionOptions($options['compress'], $options['level']);

    if (!is_string($data)) {
      return False;
    }

    $this->filePath = self::normalizeFilePath($filePath);
    $this->gpFlags = GPFLAGS::NONE;

    $this->dataLength = Count64::construct(0, !$this->zip64);
    $this->gzLength = Count64::construct(0, !$this->zip64);
    $this->lfhLength = Count64::construct(0, !$this->zip64);
    $this->dataCRC32 = hexdec(hash('crc32b', $data));
    if (COMPR::DEFLATE === $options['compress']) {
      $compStream = DeflateStream::create($options['level']);
    }

    $this->dataLength->add(strlen($data));
    if (COMPR::DEFLATE === $options['compress']) {
      $data = $compStream->update($data);
    }
    $this->gzLength->add(strlen($data));

    list($this->gpFlags, $this->lfhLength) =
      $this->beginFile($this->filePath, False,
                       $options['comment'], $options['timestamp'],
                       $this->gpFlags, $options['compress'],
                       $this->dataLength, $this->gzLength, $this->dataCRC32);

    $this->write($data);

    if (COMPR::DEFLATE === $options['compress']) {
      $data = $compStream->finish();
      $this->gzLength->add(strlen($data));
      $this->write($data);
    }
    $this->flush();

    // build cdRec
    $this->cdRec[] =
      $this->buildCentralDirectoryHeader($filePath, $options['timestamp'],
                                         $this->gpFlags, $options['compress'],
                                         $this->dataLength, $this->gzLength, $this->dataCRC32,
                                         $this->extFileAttrFile, False);

    // calc offset
    $this->offset->add($this->lfhLength)->add($this->gzLength);

    return True;
  }

  /**
   * Add an empty directory entry to the zip archive.
   *
   * @param string $directoryPath Directory Path and name to be added to the archive.
   * @param array $options Optional, additional options
   *                            Valid options are:
   *                               * int timestamp: timestamp for the file (default: current time)
   *                               * string comment: comment to be added for this file (default: none)
   * @return bool $success
   */
  public function addEmptyDir($directoryPath, $options = NULL) {
    if ($this->isFinalized) {
      return False;
    }
    $defaultOptions = array(
      'timestamp' => NULL,
      'comment' => NULL,
    );
    if (is_null($options)) {
      $options = array();
    }
    $options = array_merge($defaultOptions, $options);

    $directoryPath = self::normalizeFilePath($directoryPath) . '/';

    if (strlen($directoryPath) > 0) {
      $gpFlags = 0x0000;
      $gzMethod = COMPR::STORE; // Compression type 0 = stored

      list($gpFlags, $lfhLength) = $this->beginFile($directoryPath, True,
                                                    $options['comment'], $options['timestamp'],
                                                    $gpFlags, $gzMethod);
      // build cdRec
      $this->cdRec[] = $this->buildCentralDirectoryHeader($directoryPath, $options['timestamp'], $gpFlags, $gzMethod,
                                                          Count64::construct(0, !$this->zip64), Count64::construct(0, !$this->zip64), 0, $this->extFileAttrDir, True);

      // calc offset
      $this->offset->add($lfhLength);

      return True;
    }
    return False;
  }

  /**
   * Close the archive.
   *
   * A closed archive can no longer have new files added to it. After
   * closing, the zip file is completely written to the output stream.
   *
   * @return bool $success
   */
  public function finalize() {
    if (!$this->isFinalized) {

      // print central directory
      $cd = implode('', $this->cdRec);
      $this->write($cd);

      if ($this->zip64) {
        // print the zip64 end of central directory record
        $this->write($this->buildZip64EndOfCentralDirectoryRecord(strlen($cd)));

        // print the zip64 end of central directory locator
        $this->write($this->buildZip64EndOfCentralDirectoryLocator(strlen($cd)));
      }

      // print end of central directory record
      $this->write($this->buildEndOfCentralDirectoryRecord(strlen($cd)));

      $this->flush();

      $this->isFinalized = True;
      $cd = NULL;
      $this->cdRec = NULL;

      return True;
    }
    return False;
  }

  /**
   * Check that the indicated compression method and level is acceptable.
   *
   * @param $compress
   * @param $level
   * @throws \Exception
   */
  private function validateCompressionOptions($compress, $level) {

    switch($compress) {
      case COMPR::STORE:
        break;

      case COMPR::DEFLATE:
        if (COMPR::NONE !== $level
            && !class_exists(DeflatePeclStream::PECL1_DEFLATE_STREAM_CLASS)
            && !class_exists(DeflatePeclStream::PECL2_DEFLATE_STREAM_CLASS)
        ) {
          throw new \Exception('unable to use compression method DEFLATE with level other than NONE (requires pecl_http >= 0.10)');
        }
        break;

      default:
        throw new \Exception('invalid option ' . $compress . ' (compression method)');
        break;
    }

    if (!(COMPR::NONE === $level ||
          COMPR::NORMAL === $level ||
          COMPR::MAXIMUM === $level ||
          COMPR::SUPERFAST === $level)
    ) {
      throw new \Exception('invalid option ' . $level . ' (compression level');
    }
  }

  /**
   * Write a block of data to the output stream.
   *
   * @param $data
   * @return int
   */
  private function write($data) {
    return fwrite($this->outstream, $data);
  }

  /**
   * Flush the output stream.
   */
  private function flush() {
    fflush($this->outstream);
    ob_flush();
    flush();
  }

  /**
   * Build and write out a local file header for a new file.
   *
   * Character encoding checks are done on both the filename and comment; if
   * either requires UTF-8, then the record is marked as using UTF-8.
   *
   * @param $filePath
   *   The file pathname for the new file; checked for UTF-8 vs ASCII encoding.
   * @param $isDir
   *   Flag: if True this is actually a directory entry not a file.
   * @param $fileComment
   *   The file comment describing the file; can be (often is) empty. Also
   *   checked for encoding.
   * @param $timestamp
   *   The last modification / update date timestamp (unix style) to store for
   *   this file.
   * @param $gpFlags
   *   The general flags for this file, using the GPFLAGS constants.
   * @param $gzMethod
   *   The compression method, using the COMPR constants.
   * @param int $dataLength
   *   The length of the file in bytes, or 0 if this is not known at this point,
   *   in which case GPFLAGS must include ADD and a DataDirectory record must
   *   appear after the data including this and the CRC.
   * @param int $gzLength
   *   The compressed length of the file in bytes, or 0 if not known: as for
   *   dataLength.
   * @param int $dataCRC32
   *   The CRC32b checksum of the file, or 0 if not known: as for dataLength.
   *
   * @return array[2]
   *   Two values:
   *    - gpFlags - updated Flags to store.
   *    - lfhLength - the length of the file header in bytes.
   */
  private function beginFile($filePath, $isDir, $fileComment, $timestamp,
                             $gpFlags, $gzMethod,
                             $dataLength = 0, $gzLength = 0, $dataCRC32 = 0) {

    $isFileUTF8 = mb_check_encoding($filePath, 'UTF-8') && !mb_check_encoding($filePath, 'ASCII');
    $isCommentUTF8 = !empty($fileComment) && mb_check_encoding($fileComment, 'UTF-8')
                     && !mb_check_encoding($fileComment, 'ASCII');

    if ($isFileUTF8 || $isCommentUTF8) {
      $gpFlags |= GPFLAGS::EFS;
    }

    $localFileHeader = $this->buildLocalFileHeader($filePath, $timestamp,
                                                   $gpFlags, $gzMethod, $dataLength,
                                                   $gzLength, $isDir, $dataCRC32);

    $this->write($localFileHeader);

    return array($gpFlags, strlen($localFileHeader));
  }

  /**
   * Write data to the current output stream.
   *
   * Track the total data length, update the compressed data and checksums,
   * and write the block to the php output stream.
   *
   * @param $data
   *   The data to (compress and) write to the file.
   * @param $compress
   *   The compression method. One of the COMPR constants.
   *    - DEFLATE is the only compression supported at present;
   *    - STORE is supported for 'do not compress'.
   * @param $level
   *   Not used.
   */
  private function writeFile($data, $compress, $level) {

    $this->dataLength->add(strlen($data));
    hash_update($this->hashCtx, $data);
    if (COMPR::DEFLATE === $compress) {
      $data = $this->compStream->update($data);
    }
    $this->gzLength->add(strlen($data));
    $this->write($data);

  }

  /**
   * Copy the data from the input stream to a file started with beginFile.
   *
   * Initialise CRC32, read and write chunks of data through to the output
   * stream, and calculate the final CRC, compressing the data as required.
   *
   * @param $stream
   *   A php fopen() resource stream to read.
   * @param $compress
   *   The compression method to use. Only DEFLATE is supported.
   * @param $level
   *   The compression level to use for DEFLATE compression.
   *
   * @return array[3]
   *   Three values:
   *    - dataLength - the number of bytes copied from the input stream.
   *    - gpzLength - the number of bytes written tothe output stream.
   *    - crc - the CRC32b checksum of the input data.
   */
  private function streamFileData($stream, $compress, $level) {
    $dataLength = Count64::construct(0, !$this->zip64);
    $gzLength = Count64::construct(0, !$this->zip64);
    $hashCtx = hash_init('crc32b');
    if (COMPR::DEFLATE === $compress) {
      $compStream = DeflateStream::create($level);
    }

    while (!feof($stream) && $data = fread($stream, self::STREAM_CHUNK_SIZE)) {
      $dataLength->add(strlen($data));
      hash_update($hashCtx, $data);
      if (COMPR::DEFLATE === $compress) {
        $data = $compStream->update($data);
      }
      $gzLength->add(strlen($data));
      $this->write($data);

      $this->flush();
    }
    if (COMPR::DEFLATE === $compress) {
      $data = $compStream->finish();
      $gzLength->add(strlen($data));
      $this->write($data);

      $this->flush();
    }
    $crc = hexdec(hash_final($hashCtx));
    return array($dataLength, $gzLength, $crc);
  }

  /**
   * Create a Zip64 extended information record.
   *
   * @param int $dataLength
   * @param int $gzLength
   * @return string
   */
  private function buildZip64ExtendedInformationField($dataLength = 0, $gzLength = 0) {
    return ''
           . PackBits::pack16le(0x0001)         // tag for this "extra" block type (ZIP64)        2 bytes (0x0001)
           . PackBits::pack16le(28)             // size of this "extra" block                     2 bytes
           . PackBits::pack64le($dataLength)    // original uncompressed file size                8 bytes
           . PackBits::pack64le($gzLength)      // size of compressed data                        8 bytes
           . PackBits::pack64le($this->offset)  // offset of local header record                  8 bytes
           . PackBits::pack32le(0);             // number of the disk on which this file starts   4 bytes
  }

  /**
   * Create a local file header record.
   *
   * Uses flag $this->zip64 to indicate whether to use 64 bit data structures.
   *
   * @param $filePath
   *   The file pathname for the new file.
   * @param $timestamp
   *   The last modification / update date timestamp (Unix style) to store for
   *   this file.
   * @param $gpFlags
   *   The general flags for this file, using the GPFLAGS constants.
   * @param $gzMethod
   *   The compression method, using the COMPR constants.
   * @param $dataLength
   *   The length of the file in bytes, or 0 if this is not known at this point,
   *   in which case GPFLAGS must include ADD and a DataDirectory record must
   *   appear after the data including this and the CRC.
   * @param $gzLength
   *   The compressed length of the file in bytes, or 0 if not known: as for
   * @param $isDir
   *   Flag: if True this is actually a directory entry not a file.
   * @param int $dataCRC32
   *   The CRC32b checksum of the file, or 0 if not known: as for dataLength.
   *
   * @return string
   */
  private function buildLocalFileHeader($filePath, $timestamp, $gpFlags,
                                        $gzMethod, $dataLength, $gzLength,
                                        $isDir = False, $dataCRC32 = 0) {
    $versionToExtract = $this->getVersionToExtract($isDir);
    $dosTime = self::getDosTime($timestamp);
    if ($this->zip64) {
      $zip64Ext = $this->buildZip64ExtendedInformationField($dataLength, $gzLength);
      $dataLength = -1;
      $gzLength = -1;
      $offset = -1;
    }
    else {
      $zip64Ext = '';
      $dataLength = is_numeric($dataLength) ? $dataLength : $dataLength->getLoBytes();
      $gzLength = is_numeric($gzLength) ? $gzLength : $gzLength->getLoBytes();
      $offset = $this->offset->getLoBytes();
    }

    return ''
           . PackBits::pack32le(self::ZIP_LOCAL_FILE_HEADER)   // local file header signature     4 bytes  (0x04034b50)
           . PackBits::pack16le($versionToExtract)             // version needed to extract       2 bytes
           . PackBits::pack16le($gpFlags)                      // general purpose bit flag        2 bytes
           . PackBits::pack16le($gzMethod)                     // compression method              2 bytes
           . PackBits::pack32le($dosTime)                      // last mod file time              2 bytes
                                                               // last mod file date              2 bytes
           . PackBits::pack32le($dataCRC32)                    // crc-32                          4 bytes
           . PackBits::pack32le($gzLength)                     // compressed size                 4 bytes
           . PackBits::pack32le($dataLength)                   // uncompressed size               4 bytes
           . PackBits::pack16le(strlen($filePath))             // file name length                2 bytes
           . PackBits::pack16le(strlen($zip64Ext))             // extra field length              2 bytes
           . $filePath                                         // file name                       (variable size)
           . $zip64Ext;                                        // extra field                     (variable size)
  }

  /**
   * Create a data descriptor record.
   *
   * @param $dataLength
   * @param $gzLength
   * @param $dataCRC32
   * @return int
   */
  private function addDataDescriptor($dataLength, $gzLength, $dataCRC32) {
    if ($this->zip64) {
      $length = 20;
      $packedGzLength = PackBits::pack64le($gzLength);
      $packedDataLength = PackBits::pack64le($dataLength);
    }
    else {
      $length = 12;
      $packedGzLength = PackBits::pack32le($gzLength->getLoBytes());
      $packedDataLength = PackBits::pack32le($dataLength->getLoBytes());
    }

    $this->write(''
                 . PackBits::pack32le($dataCRC32)  // crc-32                          4 bytes
                 . $packedGzLength                 // compressed size                 4/8 bytes (depending on zip64 enabled)
                 . $packedDataLength               // uncompressed size               4/8 bytes (depending on zip64 enabled)
                 . '');
    return $length;
  }

  /**
   * Create a Zip64 End of Central Directory record.
   *
   * @param $cdRecLength
   * @return string
   */
  private function buildZip64EndOfCentralDirectoryRecord($cdRecLength) {
    $versionToExtract = $this->getVersionToExtract(False);
    $cdRecCount = sizeof($this->cdRec);

    return ''
           . PackBits::pack32le(self::ZIP64_END_OF_CENTRAL_DIRECTORY) // zip64 end of central dir signature         4 bytes  (0x06064b50)
           . PackBits::pack64le(44)                                   // size of zip64 end of central directory
                                                                      // record                                     8 bytes
           . PackBits::pack16le(self::ATTR_MADE_BY_VERSION)           //version made by                             2 bytes
           . PackBits::pack16le($versionToExtract)                    // version needed to extract                  2 bytes
           . PackBits::pack32le(0)                                    // number of this disk                        4 bytes
           . PackBits::pack32le(0)                                    // number of the disk with the start of the
                                                                      // central directory                          4 bytes
           . PackBits::pack64le($cdRecCount)                          // total number of entries in the central
                                                                      // directory on this disk                     8 bytes
           . PackBits::pack64le($cdRecCount)                          // total number of entries in the
                                                                      // central directory                          8 bytes
           . PackBits::pack64le($cdRecLength)                         // size of the central directory              8 bytes
           . PackBits::pack64le($this->offset)                        // offset of start of central directory
                                                                      // with respect to the starting disk number   8 bytes
           . '';                                                      // zip64 extensible data sector               (variable size)

  }

  /**
   * Create a Zip64 End of Central Directory Locator record.
   *
   * @param $cdRecLength
   * @return string
   */
  private function buildZip64EndOfCentralDirectoryLocator($cdRecLength) {
    $zip64RecStart = Count64::construct($this->offset, !$this->zip64)
                            ->add($cdRecLength);

    return ''
           . PackBits::pack32le(self::ZIP64_END_OF_CENTRAL_DIR_LOCATOR) // zip64 end of central dir locator signature  4 bytes  (0x07064b50)
           . PackBits::pack32le(0)                                      // number of the disk with the start of the
                                                                        // zip64 end of central directory              4 bytes
           . PackBits::pack64le($zip64RecStart)                         // relative offset of the zip64 end of
                                                                        // central directory record                    8 bytes
           . PackBits::pack32le(1);                                     // total number of disks                       4 bytes
  }

  /**
   * Build a Central Directory Header record.
   *
   * @param $filePath
   * @param $timestamp
   * @param $gpFlags
   * @param $gzMethod
   * @param $dataLength
   * @param $gzLength
   * @param $dataCRC32
   * @param $extFileAttr
   * @param $isDir
   * @return string
   */
  private function buildCentralDirectoryHeader($filePath, $timestamp, $gpFlags,
                                               $gzMethod, $dataLength, $gzLength, $dataCRC32,
                                               $extFileAttr, $isDir) {
    $versionToExtract = $this->getVersionToExtract($isDir);
    $dosTime = self::getDosTime($timestamp);
    if ($this->zip64) {
      $zip64Ext = $this->buildZip64ExtendedInformationField($dataLength, $gzLength);
      $dataLength = -1;
      $gzLength = -1;
      $diskNo = -1;
      $offset = -1;
    }
    else {
      $zip64Ext = '';
      $dataLength = $dataLength->getLoBytes();
      $gzLength = $gzLength->getLoBytes();
      $diskNo = 0;
      $offset = $this->offset->getLoBytes();
    }

    return ''
           . PackBits::pack32le(self::ZIP_CENTRAL_FILE_HEADER)  // Central file header signature   4 bytes  (0x02014b50)
           . PackBits::pack16le(self::ATTR_MADE_BY_VERSION)     // Version made by                 2 bytes
           . PackBits::pack16le($versionToExtract)              // Version needed to extract       2 bytes
           . PackBits::pack16le($gpFlags)                       // General purpose bit flag        2 bytes
           . PackBits::pack16le($gzMethod)                      // Compression method              2 bytes
           . PackBits::pack32le($dosTime)                       // Last mod file time              2 bytes
                                                                // Last mod file date              2 bytes
           . PackBits::pack32le($dataCRC32)                     // Crc-32                          4 bytes
           . PackBits::pack32le($gzLength)                      // Compressed size                 4 bytes
           . PackBits::pack32le($dataLength)                    // Uncompressed size               4 bytes
           . PackBits::pack16le(strlen($filePath))              // File name length                2 bytes
           . PackBits::pack16le(strlen($zip64Ext))              // Extra field length              2 bytes
           . PackBits::pack16le(0)                              // File comment length             2 bytes
           . PackBits::pack16le($diskNo)                        // Disk number start               2 bytes
           . PackBits::pack16le(0)                              // Internal file attributes        2 bytes
           . PackBits::pack32le($extFileAttr)                   // External file attributes        4 bytes
           . PackBits::pack32le($offset)                        // Relative offset of local header 4 bytes
           . $filePath                                          // File name                       (variable size)
           . $zip64Ext                                          // Extra field                     (variable size)
           . '';                           //TODO: implement?   // File comment                    (variable size)
  }

  /**
   * Build an End of Central Directory record.
   *
   * @param $cdRecLength
   * @return string
   */
  private function buildEndOfCentralDirectoryRecord($cdRecLength) {
    if ($this->zip64) {
      $diskNumber = -1;
      $cdRecCount = -1;
      $cdRecLength = -1;
      $offset = -1;
    }
    else {
      $diskNumber = 0;
      $cdRecCount = sizeof($this->cdRec);
      $offset = $this->offset->getLoBytes();
    }
    //throw new \Exception(sprintf("zip64 %d diskno %d", $this->zip64, $diskNumber));

    return ''
           . PackBits::pack32le(self::ZIP_END_OF_CENTRAL_DIRECTORY) // end of central dir signature    4 bytes  (0x06064b50)
           . PackBits::pack16le($diskNumber)                        // number of this disk             2 bytes
           . PackBits::pack16le($diskNumber)                        // number of the disk with the
                                                                    // start of the central directory  2 bytes
           . PackBits::pack16le($cdRecCount)                        // total number of entries in the
                                                                    // central directory on this disk  2 bytes
           . PackBits::pack16le($cdRecCount)                        // total number of entries in the
                                                                    // central directory               2 bytes
           . PackBits::pack32le($cdRecLength)                       // size of the central directory   4 bytes
           . PackBits::pack32le($offset)                            // offset of start of central
                                                                    // directory with respect to the
                                                                    // starting disk number            4 bytes
           . PackBits::pack16le(0)                                  // .ZIP file comment length        2 bytes
           . '';                            // TODO: implement?     // .ZIP file comment               (variable size)
  }

  // Utility methods ////////////////////////////////////////////////////////

  private static function normalizeFilePath($filePath) {
    return trim(str_replace('\\', '/', $filePath), '/');
  }

  /**
   * Calculate the 2 byte dostime used in the zip entries.
   *
   * @param int $timestamp
   * @return 2-byte encoded DOS Date
   */
  public static function getDosTime($timestamp = 0) {
    $timestamp = (int) $timestamp;
    $oldTZ = @date_default_timezone_get();
    date_default_timezone_set('UTC');
    $date = ($timestamp == 0 ? getdate() : getdate($timestamp));
    date_default_timezone_set($oldTZ);
    if ($date['year'] >= 1980) {
      return (($date['mday'] + ($date['mon'] << 5) + (($date['year'] - 1980) << 9)) << 16)
             | (($date['seconds'] >> 1) + ($date['minutes'] << 5) + ($date['hours'] << 11));
    }
    return 0x0000;
  }
}
