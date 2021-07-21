<?php

namespace Dcc\ExportDataFiles;

use Exception;
use REDCap as REDCap;

class ExportDataFiles extends \ExternalModules\AbstractExternalModule
{

    /**
     * @var string The base output directory
     */
    private $outputDir;

    /**
     * @var string
     */
    private $FileTimeStamp;

    /**
     * @var string Documentation about what happened during the cron job.
     */
    private $cronDocumentation;

    /**
     * @var string Temporary file for debug
     */
    private $tempFile;

    /**
     * @var integer turn on or off debug output.  0=Off, 1=On
     */
    private $debugMode = 1;


    /**
     * @var string Directory for temporary files.  IE: Not automatically purged directory.
     */
    private $tempFileDir;


    public function __construct()
    {
        parent::__construct();
        $this->FileTimeStamp = date('m_d_Y_H_i_s', time());
        $this->outputDir = 'c:' . DS . 'www' . DS . 'data' . DS;

        $this->cronDocumentation = $this->outputDir . 'Cron documentation ' . $this->FileTimeStamp . '.txt';

        $this->tempFileDir = $this->outputDir . 'Temp' . DS;
        $this->tempFile = $this->tempFileDir . DS . 'Cron Temp File ' . $this->FileTimeStamp . '.txt';

        if (!file_exists($this->tempFileDir)) {
            $message = 'Made New directory ' . $this->tempFileDir;
            $this->documentCron($message);
            mkdir($this->tempFileDir, 0755, true);
        }

        $message = 'Cron started';
        $this->documentCron($message);

        $message = 'Base Directory: ' . $this->outputDir;
        $this->documentCron($message);

        $message = 'Documentation File: ' . $this->cronDocumentation;
        $this->documentCron($message);

        $message = 'Debug File: ' . $this->tempFile;
        $this->documentCron($message);
    }

    /**
     * @param array $cronInfo The cron's configuration block from config.json.
     */

    function cron1(array $cronInfo): string
    {
        // return;
        $PId = 30;
        // $originalPid = $_GET['pid'];

        $projectPath = $this->outputDir . $PId . DS;

        $fileEnding = 'csv';
        $this->deleteOldCSVFiles($projectPath, $fileEnding);


        if (!file_exists($projectPath)) {
            $message = 'Made New directory ' . $projectPath;
            $this->documentCron($message);
            mkdir($projectPath, 0755, true);
        }

        $fileNameArray = $projectPath . $this->FileTimeStamp . ' .csv';
        $data = REDCap::getData($PId, 'csv');

        $message = 'Got Project Data';
        $this->documentCron($message);

        if (empty($data)) {
            $data = [['empty', 'from get data'], ['c', 'd']];
        } else if (!$data) {
            $data = [['Not', 'false'], ['c', 'd']];
        } else if (is_null($data)) {
            $data = [['is', 'Null'], ['c', 'd']];
        }



//        $projects = $this->getProjectsWithModuleEnabled();
        $projects = [['a', 'b'], ['c', 'd']];  /// fake data because
        $message = 'Debug to Temp: Writing Projects with Module Enabled';
        $this->documentCron($message);
        $temp = fopen($this->tempFile, 'w');
        foreach ($projects as $fields) {
            fputcsv($temp, $fields);
        }
        fclose($temp);

        $message = 'Before Framework';
        $this->documentCron($message);

        foreach ($this->getProjectsWithModuleEnabled() as $localProjectId) {
            $_GET['pid'] = $localProjectId;

            file_put_contents('REDCap Cron Example with PID.txt', 'It did it');
        }

        $message = 'After Framework';
        $this->documentCron($message);

        $message = 'Writing Projects with Module Enabled: Completed.';
        $this->documentCron($message);


        $message = 'Writing CSV data to file';
        $this->documentCron($message);

        $fp = fopen($fileNameArray, 'w');

        foreach ($data as $fields) {
            fputcsv($fp, $fields);
        }

        fclose($fp);

        $message = 'Completed Writing data ';
        $this->documentCron($message);


        $message = 'Delete all previous output files.  Keep the most current.';
        $this->documentCron($message);
        $ending = 'txt';
        $this->deleteOldCSVFiles($this->outputDir, $ending);


        $message = 'Cron Completed';
        $this->documentCron($message);


        // Put the pid back the way it was before this cron job (likely doesn't matter, but is good housekeeping practice)
        // $_GET['pid'] = $originalPid;

        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }

    function deleteOldCSVFiles($dir, $ending)
    {

        // Get a list of all CSV files in your folder.
        $files = glob($dir . "*." . $ending);

        // Sort them by modification date.
        usort($files, function ($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Remove the newest from your list.
        array_pop($files);

        // Delete all the rest.
        array_map('unlink', $files);

        $message = 'Deleted files at ' . $dir . ' ending in ' . $ending;
        $this->documentCron($message);
    }

    function documentCron($message)
    {
        // Document that the cron started
        if ($this->debugMode != 1) return;
        file_put_contents($this->cronDocumentation,
            date('H:i:s', time()) . ': ' . $message . PHP_EOL,
            FILE_APPEND);
    }
}
