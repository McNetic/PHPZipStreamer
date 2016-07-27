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
namespace Rivimey\ZipStreamer\Deflate;

use Rivimey\ZipStreamer\Count64\PackBits;

class DeflateStoreStream extends DeflateStream {
  const BLOCK_HEADER_NORMAL = 0x00;
  const BLOCK_HEADER_FINAL = 0x01;
  const BLOCK_HEADER_ERROR = 0x03;

  const MAX_UNCOMPR_BLOCK_SIZE = 0xffff;

  public function update($data) {
    $result = '';
    for ($pos = 0, $len = strlen($data); $pos < $len; $pos += self::MAX_UNCOMPR_BLOCK_SIZE) {
      $result .= $this->write_block(self::BLOCK_HEADER_NORMAL, substr($data, $pos, self::MAX_UNCOMPR_BLOCK_SIZE));
    }
    return $result;
  }

  public function finish() {
    return $this->write_block(self::BLOCK_HEADER_FINAL, '');
  }

  private function write_block($header, $data) {
    return ''
        . PackBits::pack8($header)                    // block header                     3 bits, null padding = 1 byte
        . PackBits::pack16le(strlen($data))           // block data length                2 bytes
        . PackBits::pack16le(0xffff ^ strlen($data))  // complement of block data size    2 bytes
        . $data                             // data
        . '';
      }
}
