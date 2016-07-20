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

class GPFLAGS {
  const NONE = 0x0000;      // no flags set
  const ENCRYPT = 0x1 << 0; // File is Encrypted
  const COMP1 = 0x1 << 1;   // compression flag 1 (compression settings, see APPNOTE for details)
  const COMP2 = 0x1 << 2;   // compression flag 2 (compression settings, see APPNOTE for details)
  const ADD = 0x1 << 3;     // ADD : Sizes and crc32 are append in data descriptor.
  const ENH_DFL = 0x1 << 4; // for Deflate64.
  const SES = 0x1 << 6;     // Strong encryption.
  const EFS = 0x1 << 11;    // EFS flag (UTF-8 encoded filename and/or comment).
  const CDHIDE = 0x1 << 13; // When Encrypting CD, data is masked.

  // compression settings for deflate/deflate64
  const DEFL_NORM = 0x0000; // normal compression (COMP1 and COMP2 not set)
  const DEFL_MAX = COMP1;   // maximum compression
  const DEFL_FAST = COMP2;  // fast compression
  const DEFL_SFAST = 0x0006; // superfast compression (COMP1 and COMP2 set)

  const LZMA_EOS = COMP1;   // End of Stream marker for LZMA
  const IPLD_DICT = COMP1;  // Sliding Dict for Implode
  const IPLD_TREE = COMP2;  // Shannon-Fano tree for Implode
}

