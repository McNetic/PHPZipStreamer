<?php
/**
 * Simple class to support some very basic operations on 64 bit intergers
 * on 32 bit machines.
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
 * @author Nicolai Ehemann <en@enlightened.de>
 * @copyright Copyright (C) 2013-2014 Nicolai Ehemann and contributors
 * @license GNU GPL
 */
namespace Rivimey\ZipStreamer\Count64;


class PackBits {

  const INT64_HIGH_MAP = 0xffffffff00000000;
  const INT64_LOW_MAP =  0x00000000ffffffff;
  const INT_MAX_32 = 0xffffffff;

  /**
   * Unsigned right shift
   *
   * @param int $bits integer to be shifted
   * @param int $shift number of bits to be shifted
   * @return int shifted integer
   */
  static public function urShift($bits, $shift) {
    if ($shift == 0) {
      return $bits;
    }
    return ($bits >> $shift) & ~(1 << (8 * PHP_INT_SIZE - 1) >> ($shift - 1));
  }

  /**
   * Convert binary data string to readable hex string
   *
   * @param string $data binary string
   * @return string readable hex string
   */
  static public function byte2hex($data) {
    return unpack("h*", $data);
  }

  /**
   * Pack 1 byte data into binary string
   *
   * @param mixed $data data
   * @return string 1 byte binary string
   */
  static public function pack8($data) {
    return pack('C', $data);
  }

  /**
   * Pack 2 byte data into binary string, little endian format
   *
   * @param mixed $data data
   * @return string 2 byte binary string
   */
  static public function pack16le($data) {
    return pack('v', $data);
  }

  /**
   * Unpack 2 byte binary string, little endian format to 2 byte data
   *
   * @param string $data binary string
   * @return integer 2 byte data
   */
  static public function unpack16le($data) {
    $result = unpack('v', $data);
    return $result[1];
  }

  /**
   * Pack 4 byte data into binary string, little endian format
   *
   * @param mixed $data data
   * @return 4 byte binary string
   */
  static public function pack32le($data) {
    return pack('V', $data);
  }

  /**
   * Unpack 4 byte binary string, little endian format to 4 byte data
   *
   * @param string $data binary string
   * @return integer 4 byte data
   */
  static public function unpack32le($data) {
    $result = unpack('V', $data);
    return $result[1];
  }

  /**
   * Pack 8 byte data into binary string, little endian format
   *
   * @param mixed $data data
   * @return string 8 byte binary string
   */
  static public function pack64le($data) {
    if (is_object($data)) {
      if ("Count64_32" == get_class($data)) {
        $value = $data->_getValue();
        $hiBytess = $value[0];
        $loBytess = $value[1];
      }
      else {
        $hiBytess = ($data->_getValue() & self::INT64_HIGH_MAP) >> 32;
        $loBytess = $data->_getValue() & self::INT64_LOW_MAP;
      }
    }
    else if (4 == PHP_INT_SIZE) {
      $hiBytess = 0;
      $loBytess = $data;
    }
    else {
      $hiBytess = ($data & self::INT64_HIGH_MAP) >> 32;
      $loBytess = $data & self::INT64_LOW_MAP;
    }
    return pack('VV', $loBytess, $hiBytess);
  }

  /**
   * Unpack 8 byte binary string, little endian format to 8 byte data
   *
   * @param string $data binary string
   * @return Count64Base data
   */
  static public function unpack64le($data) {
    $bytes = unpack('V2', $data);
    return Count64::construct(array(
                                $bytes[1],
                                $bytes[2]
                              ));
  }
}