<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information please see
 * <http://phing.info>.
 */
namespace Phing\Task\System;

use Exception;
use Phing\Exception\BuildException;
use Phing\Io\File;
use Phing\Io\FileReader;
use Phing\Io\FileWriter;
use Phing\Io\Util\FileUtils;
use Phing\Project;
use Phing\Task;
use Phing\Type\FileList;
use Phing\Type\FileSet;
use Phing\Type\FilterChain;
use Phing\Util\Register;


/**
 *  Appends text, contents of a file or set of files defined by a filelist to a destination file.
 *
 * <code>
 * <append text="And another thing\n" destfile="badthings.log"/>
 * </code>
 * OR
 * <code>
 * <append file="header.html" destfile="fullpage.html"/>
 * <append file="body.html" destfile="fullpage.html"/>
 * <append file="footer.html" destfile="fullpage.html"/>
 * </code>
 * OR
 * <code>
 * <append destfile="${process.outputfile}">
 *    <filterchain>
 *        <xsltfilter style="${process.stylesheet}">
 *            <param name="mode" expression="${process.xslt.mode}"/>
 *            <param name="file_name" expression="%{task.append.current_file.basename}"/> <!-- Example of using a RegisterSlot variable -->
 *        </xsltfilter>
 *    </filterchain>
 *     <filelist dir="book/" listfile="book/PhingGuide.book"/>
 * </append>
 * </code>
 * @package phing.tasks.system
 * @version $Id$
 */
class Append extends Task
{

    /** Append stuff to this file. */
    private $to;

    /** Explicit file to append. */
    private $file;

    /** Any filesets of files that should be appended. */
    private $filesets = array();

    /** Any filelists of files that should be appended. */
    private $filelists = array();

    /** Any filters to be applied before append happens. */
    private $filterChains = array();

    /** Text to append. (cannot be used in conjunction w/ files or filesets) */
    private $text;

    /** Sets specific file to append.
     * @param File $f
     */
    public function setFile(File $f)
    {
        $this->file = $f;
    }

    /**
     * The more conventional naming for method to set destination file.
     *
     * @param File $f
     *
     * @return void
     */
    public function setDestFile(File $f)
    {
        $this->to = $f;
    }

    /**
     * Supports embedded <filelist> element.
     *
     * @return FileList
     */
    public function createFileList()
    {
        $num = array_push($this->filelists, new FileList());

        return $this->filelists[$num - 1];
    }

    /**
     * Nested adder, adds a set of files (nested fileset attribute).
     *
     * @param FileSet $fs
     *
     * @return void
     */
    public function addFileSet(FileSet $fs)
    {
        $this->filesets[] = $fs;
    }

    /**
     * Creates a filterchain
     *
     * @return FilterChain The created filterchain object
     */
    public function createFilterChain()
    {
        $num = array_push($this->filterChains, new FilterChain($this->project));

        return $this->filterChains[$num - 1];
    }

    /**
     * Sets text to append.  (cannot be used in conjunction w/ files or filesets).
     *
     * @param string $txt
     *
     * @return void
     */
    public function setText($txt)
    {
        $this->text = (string)$txt;
    }

    /**
     * Sets text to append. Supports CDATA.
     *
     * @param string $txt
     *
     * @return void
     */
    public function addText($txt)
    {
        $this->text = (string)$txt;
    }

    /**
     * Append the file(s).
     *
     * {@inheritdoc}
     */
    public function main()
    {
        if ($this->to === null) {
            throw new BuildException("You must specify the 'destFile' attribute");
        }

        if ($this->file === null && empty($this->filelists) && empty($this->filesets) && $this->text === null) {
            throw new BuildException("You must specify a file, use a filelist, or specify a text value.");
        }

        if ($this->text !== null && ($this->file !== null || !empty($this->filelists))) {
            throw new BuildException("Cannot use text attribute in conjunction with file or filelists.");
        }

        // create a filwriter to append to "to" file.
        $writer = new FileWriter($this->to, $append = true);

        if ($this->text !== null) {

            // simply append the text
            $this->log("Appending string to " . $this->to->getPath());

            // for debugging primarily, maybe comment
            // out for better performance(?)
            $lines = explode("\n", $this->text);
            foreach ($lines as $line) {
                $this->log($line, Project::MSG_VERBOSE);
            }

            $writer->write($this->text);

        } else {

            // append explicitly-specified file
            if ($this->file !== null) {
                try {
                    $this->appendFile($writer, $this->file);
                } catch (Exception $ioe) {
                    $this->log(
                        "Unable to append contents of file " . $this->file->getAbsolutePath() . ": " . $ioe->getMessage(
                        ),
                        Project::MSG_WARN
                    );
                }
            }

            // append the files in the filelists
            foreach ($this->filelists as $fl) {
                try {
                    $files = $fl->getFiles($this->project);
                    $this->appendFiles($writer, $files, $fl->getDir($this->project));
                } catch (BuildException $be) {
                    $this->log($be->getMessage(), Project::MSG_WARN);
                }
            }

            // append any files in filesets
            foreach ($this->filesets as $fs) {
                try {
                    $files = $fs->getDirectoryScanner($this->project)->getIncludedFiles();
                    $this->appendFiles($writer, $files, $fs->getDir($this->project));
                } catch (BuildException $be) {
                    $this->log($be->getMessage(), Project::MSG_WARN);
                }
            }

        } // if ($text) {} else {}

        $writer->close();
    }

    /**
     * Append an array of files in a directory.
     *
     * @param FileWriter $writer The FileWriter that is appending to target file.
     * @param array $files array of files to delete; can be of zero length
     * @param File $dir directory to work from
     *
     * @return void
     */
    private function appendFiles(FileWriter $writer, $files, File $dir)
    {
        if (!empty($files)) {
            $this->log(
                "Attempting to append " . count(
                    $files
                ) . " files" . ($dir !== null ? ", using basedir " . $dir->getPath() : "")
            );
            $basenameSlot = Register::getSlot("task.append.current_file");
            $pathSlot = Register::getSlot("task.append.current_file.path");
            foreach ($files as $filename) {
                try {
                    $f = new File($dir, $filename);
                    $basenameSlot->setValue($filename);
                    $pathSlot->setValue($f->getPath());
                    $this->appendFile($writer, $f);
                } catch (Exception $ioe) {
                    $this->log(
                        "Unable to append contents of file " . $f->getAbsolutePath() . ": " . $ioe->getMessage(),
                        Project::MSG_WARN
                    );
                }
            }
        } // if !empty
    }

    /**
     * @param FileWriter $writer
     * @param File $f
     *
     * @return void
     */
    private function appendFile(FileWriter $writer, File $f)
    {
        $in = FileUtils::getChainedReader(new FileReader($f), $this->filterChains, $this->project);
        while (-1 !== ($buffer = $in->read())) { // -1 indicates EOF
            $writer->write($buffer);
        }
        $this->log("Appending contents of " . $f->getPath() . " to " . $this->to->getPath());
    }
}