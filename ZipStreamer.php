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
 * @copyright Copyright (C) 2013-2014 Nicolai Ehemann and contributors
 * @license GNU GPL
 * @version 0.4
 */


class ZipStreamer {
  const VERSION = "0.9.8";

  const ZIP_LOCAL_FILE_HEADER = 0x04034b50; // local file header signature
  const ZIP_DATA_DESCRIPTOR_RECORD = 0x08074b50; // signature value for the data descriptor record
  const ZIP_CENTRAL_FILE_HEADER = 0x02014b50; // central file header signature
  const ZIP_END_OF_CENTRAL_DIRECTORY = 0x06054b50; // end of central directory record
  const ZIP64_END_OF_CENTRAL_DIRECTORY = 0x06064b50; //zip64 end of central directory record
  const ZIP64_END_OF_CENTRAL_DIR_LOCATOR = 0x07064b50; // zip64 end of central directory locator

  //TODO: make this dynamic, depending on flags/compression methods
  const ATTR_VERSION_TO_EXTRACT = 0x2d; // version needed to extract (min. 4.5)
  const ATTR_MADE_BY_VERSION = 0x032d; // made by version  (upper byte: UNIX, lower byte v4.5)

  const STREAM_CHUNK_SIZE = 1048576; // 1mb chunks

  const INT64_HIGH_MAP = 0xffffffff00000000;
  const INT64_LOW_MAP = 0x00000000ffffffff;

  private $extFileAttrFile;
  private $extFileAttrDir;

  /** @var array central directory record */
  private $cdRec = array();
  /** @var string final central dir. record */
  private $finalCd = '';
  /** @var int|float offset of next file to be added */
  private $offset = 0;
  /** @var boolean indicates zip is finalized and sent to client; no further addition possible */
  private $isFinalized = false;
  /** @var boolean indicates zip needs to be resumed */
  private $resumeRequired = false;
  /** @var boolean indicates zip is finalized and sent to client; no other action possible (resume...) */
  private $streamStarted = false;

  /** @var array files to zip */
  private $files = array();
  /** @var int|float store contentLength (equal to finalSize if resume not needed) */
  private $contentLength = 0;
  /** @var int|float total size of the zip */
  private $finalSize = 0;
  /** @var int|float where the download will be started */
  private $resumeAt = 0;

  /**
   * Constructor.
   *
   * @param bool   $sendHeaders Send suitable headers to the HTTP client (assumes nothing was sent yet)
   * @param string $archiveName Name to send to the HTTP client. Optional, defaults to "archive.zip".
   * @param string $contentType Content mime type. Optional, defaults to "application/zip".
   */
  function __construct($sendHeaders = false, $archiveName = 'archive.zip', $contentType = 'application/zip') {
    //TODO: is this advisable/necessary?
    if (ini_get('zlib.output_compression')) {
      ini_set('zlib.output_compression', 'Off');
    }
    if ($sendHeaders) {
      $headerFile = null;
      $headerLine = null;
      if (!headers_sent($headerFile, $headerLine)
            or die('<p><strong>Error:</strong> Unable to send file ' .
                   '$archiveName. HTML Headers have already been sent from ' .
                   '<strong>$headerFile</strong> in line <strong>$headerLine' .
                   '</strong></p>')) {
        if ((ob_get_contents() === false || ob_get_contents() == '')
             or die('\n<p><strong>Error:</strong> Unable to send file ' .
                    '<strong>$archiveName.epub</strong>. Output buffer ' .
                    'already contains text (typically warnings or errors).</p>')) {
          header('Pragma: public');
          header('Last-Modified: ' . gmdate('D, d M Y H:i:s T'));
          header('Expires: 0');
          header('Accept-Ranges: bytes');
          header('Connection: Keep-Alive');
          header('Content-Type: ' . $contentType);
          header('Content-Disposition: attachment; filename="' . $archiveName . '";');
          header('Content-Transfer-Encoding: binary');
        }
      }
      flush();
      // turn off output buffering
      ob_end_flush();
    }
    // initialize default external file attributes
    $this->extFileAttrFile = UNIX::getExtFileAttr(UNIX::S_IFREG |
                                                  UNIX::S_IRUSR | UNIX::S_IWUSR | UNIX::S_IRGRP |
                                                  UNIX::S_IROTH);
    $this->extFileAttrDir = UNIX::getExtFileAttr(UNIX::S_IFDIR |
                                                 UNIX::S_IRWXU | UNIX::S_IRGRP | UNIX::S_IXGRP |
                                                 UNIX::S_IROTH | UNIX::S_IXOTH) |
                            DOS::getExtFileAttr(DOS::DIR);
  }

  function __destruct() {
    $this->isFinalized = true;
    $this->cdRec = null;

	foreach ($this->files as $file) {
		if (is_resource($file['stream'])) {
			fclose($file['stream']);
		}
	}
	$this->files = null;
    exit;
  }

  /**
   * Add a file to the archive at the specified location and file name.
   *
   * @param string $stream      Stream to read data from
   * @param string $filePath    Filepath and name to be used in the archive.
   * @param int    $timestamp   (Optional) Timestamp for the added file, if omitted or set to 0, the current time will be used.
   * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use fileComment, timestamp must be given.
   * @param bool   $compress    (Optional) Compress file, if set to false the file will only be stored. Default FALSE.
   * @return bool $success
   */
	public function addFileFromStream($stream, $filePath, $dataLength = -1, $timestamp = 0, $fileComment = null) {
		if ($this->isFinalized) {
			return false;
		}

		if (!is_resource($stream) || get_resource_type($stream) != 'stream') {
			return false;
		}

		$filePath = self::normalizeFilePath($filePath);

		$gpFlags = GPFLAGS::ADD;
		$gzMethod = GZMETHOD::STORE;

		if ($dataLength < 0) {
			$dataLength = self::getStreamSize($stream);
		}

		list($gpFlags, $lfh, $lfhLength) = $this->beginFile($filePath, $fileComment, $timestamp,
															$gpFlags, $gzMethod);

		// we will calculate the true crc32b after, so we use 0 for CRC in the LFH
		// But it will be written later in the data descriptor
		$this->cdRec[] = $this->buildCentralDirectoryHeader($filePath, $timestamp, $gpFlags, $gzMethod,
															$dataLength, $dataLength, 0, $this->extFileAttrFile);

		// calc offset
		$this->offset += $lfhLength + $dataLength + strlen($this->buildDataDescriptor());

		// We save all infos we will need later :
		// LFH,
		// stream,
		// filePath, timestamp, flags, method, filesize, crc32 (null now),
		// file attributes
		// data descriptor (null now)
		// And the new offset
		$this->files[] = array('lfh' => $lfh,
		                       'stream' => $stream,
		                       'path' => $filePath,
		                       'timestamp' => $timestamp,
		                       'gpflags' => $gpFlags,
		                       'gzmethod' => $gzMethod,
		                       'size' => $dataLength,
		                       'crc' => null,
		                       'fileattr' => $this->extFileAttrFile,
		                       'datadescriptor' => null,
		                       'offset' => $this->offset);

		return true;
	}

  /**
   * Close the archive.
   * A closed archive can no longer have new files added to it.
   * @return bool $success
   */
	public function finalize() {
		if (!$this->isFinalized && count($this->files) > 0) {
			$this->isFinalized = true;

			// This CD is temporary, we will make the true CD later during the streaming
			$this->buildFinalCd();

			$this->finalSize = $this->offset + strlen($this->finalCd);
			// contentLength = finalSize because the user can use zipstreamer without resume function
			// This can be changed later in the resume download function
			$this->contentLength = $this->finalSize;

			// We will need to rebuild the offset and the CD to get correct zip headers
			$this->offset = 0;
			$this->cdRec = array();
			$this->finalCd = '';

			return true;
		}
		return false;
	}

	private function buildFinalCd() {
		if ($this->isFinalized) {
			// get central directory
			$cd = implode('', $this->cdRec);
			$cdRecLength = strlen($cd);

			// get the zip64 end of central directory record
			$cd .= $this->buildZip64EndOfCentralDirectoryRecord($cdRecLength);

			// get the zip64 end of central directory locator
			$cd .= $this->buildZip64EndOfCentralDirectoryLocator($cdRecLength);

			// get end of central directory record
			$cd .= $this->buildEndOfCentralDirectoryRecord();

			$this->finalCd = $cd;

			return true;
		}
		return false;
	}

	public function getContentLength() {
		if ($this->isFinalized && !$this->streamStarted) {
			return $this->contentLength;
		}
		return false;
	}
	
	public function getZipSize() {
		if ($this->isFinalized && !$this->streamStarted) {
			return $this->finalSize;
		}
		return false;
	}

	private function beginFile($filePath, $fileComment, $timestamp, $gpFlags = 0x0000, $gzMethod = GZMETHOD::STORE,
	                           $dataLength = 0, $gzLength = 0, $dataCRC32 = 0) {

		$isFileUTF8 = mb_check_encoding($filePath, 'UTF-8') && !mb_check_encoding($filePath, 'ASCII');
		$isCommentUTF8 = !empty($fileComment) && mb_check_encoding($fileComment, 'UTF-8')
		                 && !mb_check_encoding($fileComment, 'ASCII');

		if ($isFileUTF8 || $isCommentUTF8) {
			$gpFlags |= GPFLAGS::EFS;
		}

		$localFileHeader = $this->buildLocalFileHeader($filePath, $timestamp, $gpFlags, $gzMethod);

		return array($gpFlags, $localFileHeader, strlen($localFileHeader));
	}

	private function streamFileData(&$file) {
		$stream = &$file['stream'];

		$hashCtx = ($file['crc'] === null) ? hash_init('crc32b') : $file['crc'];

 		while (!feof($stream)) {
			$data = fread($stream, self::STREAM_CHUNK_SIZE);
			hash_update($hashCtx, $data);
			
			$this->flush($data);
		}

		fclose($stream);

		$crc = unpack('N', hash_final($hashCtx, true))[1];

		return $crc;
	}

	public function startStreaming() {
		if ($this->isFinalized && !$this->streamStarted) {
			$this->prepareResume();
			
			$this->streamStarted = true;

			foreach ($this->files as $file) {
				extract($file);
				
				if (is_resource($stream)) {
					$this->flush($lfh);

					$crc = $this->streamFileData($file);

					if ($datadescriptor === null) {
						$datadescriptor = $this->buildDataDescriptor($size, $size, $crc);
					}
					$this->flush($datadescriptor);

					$this->cdRec[] = $this->buildCentralDirectoryHeader($path, $timestamp, $gpflags, $gzmethod,
					                                                    $size, $size, $crc, $fileattr);

					// Update the offset
					$this->offset = $offset;
				}
			}

			if ($this->finalCd === '') {
				$this->buildFinalCd();
			}
			$this->flush($this->finalCd);

			return true;
		}
		return false;
	}
	
	public function resumeAt($start = 0) {
		// resume only if 0 < $start < $this->finalSize
		if (!$this->isFinalized || $this->streamStarted
		    || $start <= 0 || $start >= $this->finalSize) {

			return false;
		}
		
		$this->resumeAt = $start;
		$this->contentLength = $this->finalSize - $start;
		$this->resumeRequired = true;

		return true;
	}

	private function prepareResume() {
		if (!$this->resumeRequired) {
			return false;
		}
		
		$start = $this->resumeAt;

		$lastFileSent = -1;
		foreach ($this->files as $i => $file) {
			extract($file);
			// If $start >= offset of the file, the file is already sent
			// so just calc crc32 and make the central dir. record
			if ($start >= $offset) {
				if (is_resource($stream)) {
					$hashCtx = hash_init('crc32b');
					hash_update_stream($hashCtx, $stream);

					fclose($stream);

					$crc = unpack('N', hash_final($hashCtx, true))[1];

					$this->cdRec[] = $this->buildCentralDirectoryHeader($path, $timestamp, $gpflags, $gzmethod,
																		$size, $size, $crc, $fileattr);

					// Update the offset
					$this->offset = $offset;

					$lastFileSent = $i;
					unset($this->files[$i]);
				}
			} else { // Else, stop here and send the partial file (or the partial final header)
				break;
			}
		}

		$bytesToPrepare = $start - $this->offset;

		// If there is a partially downloaded file
		// bytesToPrepare means "remaining data to prepare before start the download"
		if (isset($this->files[$lastFileSent+1])) {
			$partialFile = &$this->files[$lastFileSent+1];

			// Calc some useful values to know where we are in the file
			$startOfFile = $this->offset + strlen($partialFile['lfh']);
			$startOfDataDescriptor = $startOfFile + $partialFile['size'];

			// If the download was stopped during a local file header
			if ($start < $startOfFile) {
				// We build the part of the LFH the client needs
				$partialFile['lfh'] = substr($partialFile['lfh'], $bytesToPrepare);
			} else { // When the download stopped in the middle of a file
				if (is_resource($partialFile['stream'])) {
					$stream = &$partialFile['stream'];
					$hashCtx = hash_init('crc32b');

					$bytesToPrepare = $bytesToPrepare - strlen($partialFile['lfh']);
					// The lfh needs to be reset because it was already sent
					$partialFile['lfh'] = '';

					while (!feof($stream) && $bytesToPrepare > 0) {
						$chunkSize = (int) ($bytesToPrepare > self::STREAM_CHUNK_SIZE) ?
											self::STREAM_CHUNK_SIZE : $bytesToPrepare;

						hash_update($hashCtx, fread($stream, $chunkSize));
						$bytesToPrepare = $bytesToPrepare - $chunkSize;
					}

					$partialFile['crc'] = hash_copy($hashCtx);

					if ($bytesToPrepare > 0) {
						// Now, if $bytesToPrepare is still > 0,
						// this means the file was already sent and we need to send the data descriptor
						$size = $partialFile['size'];
						$crc = unpack('N', hash_final($hashCtx, true))[1];

						$dataDescriptor = $this->buildDataDescriptor($size, $size, $crc);
						$partialFile['datadescriptor'] = substr($dataDescriptor, $bytesToPrepare);
					}
				}
			}
		} else { // In case all files were downloaded and we were sending the CDRec of the zip. (the end)
			$this->buildFinalCd();
			$this->finalCd = substr($this->finalCd, $bytesToPrepare);
		}
		return true;
	}

	private function buildZip64ExtendedInformationField($dataLength = 0, $gzLength = 0) {
		$zip64InfoFieldBeginning = $this->pack16le(0x0001);	// tag for this "extra" block type (ZIP64)	 2 bytes (0x0001)

		$zip64InfoField = $this->pack64le($dataLength)		// original uncompressed file size	  8 bytes
						 . $this->pack64le($gzLength);		// size of compressed data            8 bytes

		// If dataLength != -1, we are building the central dir., not the local file header
		// So we have to add the offset
		if ($dataLength !== -1 && $gzLength !== -1) {
			$zip64InfoField .= $this->pack64le($this->offset);	     // offset of local header record     8 bytes
		}

		$zip64InfoField = $zip64InfoFieldBeginning
						. $this->pack16le(strlen($zip64InfoField)) // size of this "extra" block         2 bytes
						. $zip64InfoField;

		return $zip64InfoField;
	}

	private function buildLocalFileHeader($filePath, $timestamp, $gpFlags = 0x0000, $gzMethod = GZMETHOD::STORE) {
		$dosTime = self::getDosTime($timestamp);
		$zip64Ext = $this->buildZip64ExtendedInformationField(-1, -1);


		return ''
			. $this->pack32le(self::ZIP_LOCAL_FILE_HEADER)   // local file header signature     4 bytes  (0x04034b50)
			. $this->pack16le(self::ATTR_VERSION_TO_EXTRACT) // version needed to extract       2 bytes
			. $this->pack16le($gpFlags)                      // general purpose bit flag        2 bytes
			. $this->pack16le($gzMethod)                     // compression method              2 bytes
			. $this->pack32le($dosTime)                      // last mod file time              2 bytes
			                                                 // last mod file date              2 bytes
			. $this->pack32le(0)                             // crc-32                          4 bytes
			. $this->pack32le(-1)                            // compressed size                 4 bytes
			. $this->pack32le(-1)                            // uncompressed size               4 bytes
			. $this->pack16le(strlen($filePath))             // file name length                2 bytes
			. $this->pack16le(strlen($zip64Ext))             // extra field length              2 bytes
			. $filePath                                      // file name                       (variable size)
			. $zip64Ext;                                     // extra field                     (variable size)
	}

	private function buildDataDescriptor($dataLength = -1, $gzLength = -1, $dataCRC32 = 0) {
		return ''
			. $this->pack32le(self::ZIP_DATA_DESCRIPTOR_RECORD)  // data descriptor signature       4 bytes  (0x08074b50)
			. $this->pack32le($dataCRC32)                        // crc-32                          4 bytes
			. $this->pack64le($dataLength)                       // compressed size                 8 bytes
			. $this->pack64le($gzLength);                        // uncompressed size               8 bytes
	}

	private function buildCentralDirectoryHeader($filePath, $timestamp, $gpFlags,
		$gzMethod, $dataLength, $gzLength, $dataCRC32, $extFileAttr) {
		$dosTime = self::getDosTime($timestamp);
		$zip64Ext = $this->buildZip64ExtendedInformationField($dataLength, $gzLength);

		return ''
			. $this->pack32le(self::ZIP_CENTRAL_FILE_HEADER)  //central file header signature   4 bytes  (0x02014b50)
			. $this->pack16le(self::ATTR_MADE_BY_VERSION)     //version made by                 2 bytes
			. $this->pack16le(self::ATTR_VERSION_TO_EXTRACT)  //version needed to extract       2 bytes
			. $this->pack16le($gpFlags)                       //general purpose bit flag        2 bytes
			. $this->pack16le($gzMethod)                      //compression method              2 bytes
			. $this->pack32le($dosTime)                       //last mod file time              2 bytes
															  //last mod file date              2 bytes
			. $this->pack32le($dataCRC32)                     //crc-32                          4 bytes
			. $this->pack32le(-1)                    //compressed size                 4 bytes
			. $this->pack32le(-1)                      //uncompressed size               4 bytes
			. $this->pack16le(strlen($filePath))              //file name length                2 bytes
			. $this->pack16le(strlen($zip64Ext))              //extra field length              2 bytes
			. $this->pack16le(0)                              //file comment length             2 bytes
			. $this->pack16le(0)                              //disk number start               2 bytes
			. $this->pack16le(0)                              //internal file attributes        2 bytes
			. $this->pack32le($extFileAttr)                   //external file attributes        4 bytes
			. $this->pack32le(-1)                             //relative offset of local header 4 bytes
			. $filePath                                       //file name                       (variable size)
			. $zip64Ext                                       //extra field                     (variable size)
			//TODO: implement?
			. '';                                             //file comment                    (variable size)
	}

	private function buildZip64EndOfCentralDirectoryRecord($cdRecLength) {
		$cdRecCount = sizeof($this->cdRec);

		return ''
			. $this->pack32le(self::ZIP64_END_OF_CENTRAL_DIRECTORY) // zip64 end of central dir signature         4 bytes  (0x06064b50)
			. $this->pack64le(44)                                   // size of zip64 end of central directory
																	// record                                     8 bytes
			. $this->pack16le(self::ATTR_MADE_BY_VERSION)           //version made by                             2 bytes
			. $this->pack16le(self::ATTR_VERSION_TO_EXTRACT)        //version needed to extract                   2 bytes
			. $this->pack32le(0)                                    // number of this disk                        4 bytes
			. $this->pack32le(0)                                    // number of the disk with the start of the
																	// central directory                          4 bytes
			. $this->pack64le($cdRecCount)                          // total number of entries in the central
																	// directory on this disk                     8 bytes
			. $this->pack64le($cdRecCount)                          // total number of entries in the
																	// central directory                          8 bytes
			. $this->pack64le($cdRecLength)                         // size of the central directory              8 bytes
			. $this->pack64le($this->offset)                        // offset of start of central directory
																	// with respect to the starting disk number   8 bytes
			. '';                                                   // zip64 extensible data sector               (variable size)
	}

	private function buildZip64EndOfCentralDirectoryLocator($cdRecLength) {
		$zip64RecStart = $this->offset + $cdRecLength;

		return ''
			. $this->pack32le(self::ZIP64_END_OF_CENTRAL_DIR_LOCATOR) // zip64 end of central dir locator signature  4 bytes  (0x07064b50)
			. $this->pack32le(0)                                      // number of the disk with the start of the
																	  // zip64 end of central directory              4 bytes
			. $this->pack64le($zip64RecStart)                         // relative offset of the zip64 end of
																	  // central directory record                    8 bytes
			. $this->pack32le(1);                                     // total number of disks                       4 bytes
	}

	private function buildEndOfCentralDirectoryRecord() {
		return ''
			. $this->pack32le(self::ZIP_END_OF_CENTRAL_DIRECTORY) // end of central dir signature    4 bytes  (0x06064b50)
			. $this->pack16le(0)                                 // number of this disk             2 bytes
			. $this->pack16le(0)                                 // number of the disk with the
																// start of the central directory  2 bytes
			. $this->pack16le(-1)                                 // total number of entries in the
																// central directory on this disk  2 bytes
			. $this->pack16le(-1)                                 // total number of entries in the
																// central directory               2 bytes
			. $this->pack32le(-1)                                 // size of the central directory   4 bytes
			. $this->pack32le(-1)                                 // offset of start of central
																// directory with respect to the
																// starting disk number            4 bytes
			. $this->pack16le(0)                                  // .ZIP file comment length        2 bytes
			//TODO: implement?
			. '';                                                 // .ZIP file comment               (variable size)
	}

  // Utility methods ////////////////////////////////////////////////////////

  private static function normalizeFilePath($filePath) {
    return trim(str_replace('\\', '/', $filePath), '/');
  }

  private static function getStreamSize(&$stream) {
    $fstat = fstat($stream);
	$dataLength = $fstat['size'];
	
	if (PHP_INT_SIZE === 4 || $dataLength < 0) {
		$dataLength = 0;
		
		while (!feof($stream)) {
			$dataLength = $dataLength + strlen(fread($stream, self::STREAM_CHUNK_SIZE));
		}
		
		rewind($stream);
	}
	
	return $dataLength;
  }

  /**
   * Calculate the 2 byte dostime used in the zip entries.
   *
   * @param int $timestamp
   * @return 2-byte encoded DOS Date
   */
  private static function getDosTime($timestamp = 0) {
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

	/**
	* Pack 2 byte data into binary string, little endian format
	*
	* @param mixed $data data
	* @return string 2 byte binary string
	*/
	private static function pack16le($data) {
		return pack('v', $data);
	}

	/**
	* Pack 4 byte data into binary string, little endian format
	*
	* @param mixed $data data
	* @return 4 byte binary string
	*/
	private static function pack32le($data) {
		return pack('V', $data);
	}

	/**
	* Pack 8 byte data into binary string, little endian format
	* If PHP_INT_SIZE == 8, just cut the data after 32 bit then pack
	* Else, if PHP_INT_SIZE == 4, data are processed before packing
	*
	* @param mixed $data data
	* @return string 8 byte binary string
	*/
	private static function pack64le($data) {
		$hiBytes = $loBytes = 0;

		if (8 == PHP_INT_SIZE) {
			$hiBytes = ($data & self::INT64_HIGH_MAP) >> 32;
			$loBytes = $data & self::INT64_LOW_MAP;
		} elseif (4 == PHP_INT_SIZE) {
			// -1 is a special value, it means 0xffffffff on 32-bit php,
			// but here we need 0xffffffffffffffff so $hiBytes = -1 too
			if ($data === -1) {
				$hiBytes = $loBytes = -1;
			}

			while ($data > 0) {
				$chunk = ($data > PHP_INT_MAX) ? PHP_INT_MAX : (int)$data;
				$sum = (int)($loBytes + $chunk);
					// overflow!
					if (($loBytes > -1 && $sum < $loBytes && $sum > -1)
					   || ($loBytes < 0 && ($sum < $loBytes || $sum > -1))) {
						$hiBytes = (int)($hiBytes + 1);
					}
				$loBytes = $sum;
				$data = $data - PHP_INT_MAX;
			}
		}

		return pack('VV', $loBytes, $hiBytes);
	}

     /**
     * Flush
     *
     * @access  private
     * @return  void
     * @param   string  $data
     */
    private function flush($data = '')
    {
		echo $data;
        flush();
    }
}

abstract class ExtFileAttr {

  # ZIP external file attributes layout
  # TTTTsstrwxrwxrwx0000000000ADVSHR
  # ^^^^____________________________ UNIX file type
  #     ^^^_________________________ UNIX setuid, setgid, sticky
  #        ^^^^^^^^^________________ UNIX permissions
  #                 ^^^^^^^^________ "lower-middle byte" (TODO: what is this?)
  #                         ^^^^^^^^ DOS attributes (reserved, reserved, archived, directory, volume, system, hidden, read-only

  public static function getExtFileAttr($attr) {
    return $attr;
  }
}

class UNIX extends ExtFileAttr {

  # Octal
  const S_IFIFO = 0010000; /* named pipe (fifo) */
  const S_IFCHR = 0020000; /* character special */
  const S_IFDIR = 0040000; /* directory */
  const S_IFBLK = 0060000; /* block special */
  const S_IFREG = 0100000; /* regular */
  const S_IFLNK = 0120000; /* symbolic link */
  const S_IFSOCK = 0140000; /* socket */
  const S_ISUID = 0004000; /* set user id on execution */
  const S_ISGID = 0002000; /* set group id on execution */
  const S_ISTXT = 0001000; /* sticky bit */
  const S_IRWXU = 0000700; /* RWX mask for owner */
  const S_IRUSR = 0000400; /* R for owner */
  const S_IWUSR = 0000200; /* W for owner */
  const S_IXUSR = 0000100; /* X for owner */
  const S_IRWXG = 0000070; /* RWX mask for group */
  const S_IRGRP = 0000040; /* R for group */
  const S_IWGRP = 0000020; /* W for group */
  const S_IXGRP = 0000010; /* X for group */
  const S_IRWXO = 0000007; /* RWX mask for other */
  const S_IROTH = 0000004; /* R for other */
  const S_IWOTH = 0000002; /* W for other */
  const S_IXOTH = 0000001; /* X for other */
  const S_ISVTX = 0001000; /* save swapped text even after use */

  public static function getExtFileAttr($attr) {
    return parent::getExtFileAttr($attr) << 16;
  }
}

class DOS extends ExtFileAttr {

  const READ_ONLY = 0x1;
  const HIDDEN = 0x2;
  const SYSTEM = 0x4;
  const VOLUME = 0x8;
  const DIR = 0x10;
  const ARCHIVE = 0x20;
  const RESERVED1 = 0x40;
  const RESERVED2 = 0x80;
}

class GPFLAGS {
  const ADD = 0x0008; // ADD flag (sizes and crc32 are append in data descriptor)
  const EFS = 0x0800; // EFS flag (UTF-8 encoded filename and/or comment)
}

class GZMETHOD {
  const STORE = 0x0000; //  0 - The file is stored (no compression)
  const DEFLATE = 0x0008; //  8 - The file is Deflated
}

