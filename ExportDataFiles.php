<?php

namespace Dcc\ExportDataFiles;

use Exception;
use REDCap as REDCap;

/**
 * Class ExportDataFiles
 * @package Dcc\ExportDataFiles
 */
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
    private $debugCronFile;

    /**
     * @var integer turn on or off debug output.  0=Off, 1=On
     */
    private $debugMode = 1;


    /**
     * @var string Directory for temporary debug files.  IE: Not automatically purged directory.
     */
    private $debugFileDir;
    /**
     * @var string Debug file that will be overwritten each time.
     */
    private $debugStaticFile;


    /**
     * ExportDataFiles constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->FileTimeStamp = date('m_d_Y_H_i_s', time());
        $this->outputDir = 'c:' . DS . 'www' . DS . 'data' . DS;

        $this->cronDocumentation = $this->outputDir . 'Cron documentation ' . $this->FileTimeStamp . '.txt';

        $this->debugFileDir = $this->outputDir . 'Debug' . DS;
        $this->debugCronFile = $this->debugFileDir . 'Debug Cron File ' . $this->FileTimeStamp . '.txt';
        $this->debugStaticFile = $this->debugFileDir . 'Debug Static File.txt';

        if (!file_exists($this->debugFileDir)) {
            $message = 'Made New directory ' . $this->debugFileDir;
            $this->documentCron($message);
            mkdir($this->debugFileDir, 0755, true);
        }

        $message = 'Cron started';
        $this->documentCron($message);

        $message = 'Base Directory: ' . $this->outputDir;
        $this->documentCron($message);

        $message = 'Documentation File: ' . $this->cronDocumentation;
        $this->documentCron($message);

        $message = 'Debug Cron File: ' . $this->debugCronFile;
        $this->debugCronMessage($message);

        $message = 'Debug Static File:' . $this->debugStaticFile;
        $this->debugStaticMessage($message);

    }

    /**
     * @param array $cronInfo The cron's configuration block from config.json.
     */

    function cron2(array $cronInfo): string
    {
        $message = 'Cron2 Started';
        $this->documentCron($message);
    }

    /**
     * @param array $cronInfo The cron's configuration block from config.json.
     */

    function cron1(array $cronInfo): string
    {

        $this->documentCron('Cron1 Started');

        $PId = 30;
        $project = $this->getProject($PId);
        $projectTitle = $project->getTitle();
        $projectFolderName = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $projectTitle);

        // $originalPid = $_GET['pid'];
        $projectPrint = print_r($project, true);
//        $this->debugStaticMessage($projectPrint);

        $this->debugStaticMessage("project title:" . $projectTitle);

        $projectPath = $this->outputDir . $projectFolderName . DS;

        $fileEnding = 'csv';
        $this->deleteOldCSVFiles($projectPath, $fileEnding);


        if (!file_exists($projectPath)) {
            $this->documentCron('Made New directory ' . $projectPath);
            mkdir($projectPath, 0755, true);
        }

        $projectStatus = REDCap::getProjectStatus($PId);
        $this->debugStaticMessage('Project status: ' . $projectStatus);

        $projectSettings = $this->getProjectSettings($PId);
        $message = print_r($projectSettings, true);
        $this->debugStaticMessage($message);

        $this->documentCron('Writing CSV data to Multi file');

        $projectCSVMultipleFile = $projectPath . 'all.csv';
        $allData = REDCap::getData($PId, 'csv');
        file_put_contents($projectCSVMultipleFile, $allData);

        $this->documentCron('Writing CSV data to file: Completed');

        $projectDictionary = REDCap::getDataDictionary($PId, 'array');
//        $dictionaryPrint = print_r($projectDictionary, true);
//        file_put_contents($this->debugStaticFile, $dictionaryPrint);
//
        $formVariables = [];
        foreach ($projectDictionary as $variable) {
            $formVariables[$variable['form_name']][] = $variable['field_name'];
        }

        $this->documentCron('Writing each instrument CSV data to file');
        foreach ($formVariables as $formName => $variables) {
            $data = REDCap::getData($PId, 'csv', null, $variables);
            $projectInstrumentPath = $projectPath . $formName . '.csv';
            file_put_contents($projectInstrumentPath, $data);
        }
        $this->documentCron('Writing each instrument CSV data to file: Completed');


        $this->debugStaticMessage('Project ID:' . $PId);

        $frameworkVersion = \ExternalModules\ExternalModules::getFrameworkVersion($this);
        $this->debugStaticMessage('Framework version: ' . $frameworkVersion);

        $availableMethods = print_r(get_class_methods($this), true);
        $this->debugCronMessage($availableMethods);

        foreach ($this->getProjectsWithModuleEnabled() as $localProjectId) {
            $_GET['pid'] = $localProjectId;
            $this->debugStaticMessage('ProjectsWithModuleEnabled: ' . $localProjectId);
        }

        $this->documentCron('Delete all previous cron output logs.  Keep the most current.');
        $ending = 'txt';
        $this->deleteOldCSVFiles($this->outputDir, $ending);

        $this->documentCron('Cron1 Completed');
//
//        // Put the pid back the way it was before this cron job (likely doesn't matter, but is good housekeeping practice)
//        // $_GET['pid'] = $originalPid;
//
        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }

    /**
     * @param $dir  string The folder location to clear the contents of.
     * @param $ending  string The file ending.  Examples: "txt" or "csv".
     */
    function deleteOldCSVFiles(string $dir, string $ending)
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

    /**
     * @param $message string
     * Message to write to the log file
     */
    function documentCron(string $message)
    {
        file_put_contents($this->cronDocumentation,
            date('H:i:s', time()) . ': ' . $message . PHP_EOL,
            FILE_APPEND);
    }

    /**
     * @param $message string
     * debug message is written to the debug error log file.
     */
    function debugCronMessage(string $message)
    {
        if ($this->debugMode != 1) return;
        file_put_contents($this->debugCronFile,
            date('H:i:s', time()) . ': ' . $message . PHP_EOL,
            FILE_APPEND);
    }

    /**
     * @param $message string
     * debug message is written to the debug error log file.
     */
    function debugStaticMessage(string $message)
    {
        if ($this->debugMode != 1) return;
        file_put_contents($this->debugStaticFile,
            date('H:i:s', time()) . ': ' . $message . PHP_EOL,
            FILE_APPEND);
    }
}
