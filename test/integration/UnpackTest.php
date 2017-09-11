<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 */

class UnpackTest extends PHPUnit_Framework_TestCase
{
    private $tmpfname;

    function setUp()
    {
        parent::setUp();

        // create a zip file in tmp folder
        $this->tmpfname = tempnam("/tmp", "FOO");
        $outstream = fopen($this->tmpfname, 'w');

        $zip = new ZipStreamer\ZipStreamer((array(
            'outstream' => $outstream
        )));
        $stream = fopen(__DIR__ . "/../../README.md", "r");
        $zip->addFileFromStream($stream, 'README.test');
        fclose($stream);
        $zip->finalize();

        fflush($outstream);
        fclose($outstream);
    }

    public function test7zip() {
        $output = [];
        $return_var = -1;
        exec('7z t ' . escapeshellarg($this->tmpfname), $output, $return_var);
        $fullOutput = implode("\n", $output);
        $this->assertEquals(0, $return_var, $fullOutput);
        $this->assertTrue(in_array('1 file, 939 bytes (1 KiB)', $output), $fullOutput);
    }

    public function testUnzip() {
        $output = [];
        $return_var = -1;
        exec('unzip -t ' . escapeshellarg($this->tmpfname), $output, $return_var);
        $fullOutput = implode("\n", $output);
        $this->assertEquals(0, $return_var, $fullOutput);
        $this->assertTrue(in_array('    testing: README.test              OK', $output), $fullOutput);
    }
}
