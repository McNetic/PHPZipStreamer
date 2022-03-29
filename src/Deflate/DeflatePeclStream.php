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

class DeflatePeclStream extends DeflateStream {
  private $peclDeflateStream;

  const PECL1_DEFLATE_STREAM_CLASS = '\HttpDeflateStream';
  const PECL2_DEFLATE_STREAM_CLASS = '\http\encoding\Stream\Deflate';

  protected function __construct($level) {
    $class = self::PECL1_DEFLATE_STREAM_CLASS;
    if (!class_exists($class)) {
      $class = self::PECL2_DEFLATE_STREAM_CLASS;
    }
    if (!class_exists($class)) {
      new \Exception('unable to instantiate PECL deflate stream (requires pecl_http >= 0.10)');
    }

    $deflateFlags = constant($class . '::TYPE_RAW');
    switch ($level) {
      case COMPR::NORMAL:
        $deflateFlags |= constant($class . '::LEVEL_DEF');
        break;
      case COMPR::MAXIMUM:
        $deflateFlags |= constant($class . '::LEVEL_MAX');
        break;
      case COMPR::SUPERFAST:
        $deflateFlags |= constant($class . '::LEVEL_MIN');
        break;
    }
    $this->peclDeflateStream = new $class($deflateFlags);
  }

  public function update($data) {
    return $this->peclDeflateStream->update($data);
  }

  public function finish() {
    return $this->peclDeflateStream->finish();
  }
}
