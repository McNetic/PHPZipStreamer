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

class UNIX extends ExtFileAttr {

  // Octal
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

