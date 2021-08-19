<?php

namespace Dcc\ExportDataFiles;

use DateTime;
use Exception;
use ExternalModules\AbstractExternalModule;
use Project;
use REDCap as REDCap;

/**
 * Class ExportDataFiles
 * @package Dcc\ExportDataFiles
 */
class ExportDataFiles extends AbstractExternalModule
{

    /**
     * @var string The base output directory
     */
    private $outputDir;

    /**
     * @var string Documentation about what happened during the cron job.
     */
    private $cronDocumentation;

    private $startTimeStamp;
    private $logExport;
    private $systemEnabled;
    private $logOverwrite;
    private $originalPID;
    private $includePHI;


    /**
     * ExportDataFiles constructor.
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();

        $this->startTimeStamp = new DateTime();

        // get user supplied specifications
        $this->systemEnabled = $this->getSystemSetting('system-enabled');
        $this->outputDir = $this->getSystemSetting('root-dir');
        $this->logExport = $this->getSystemSetting('log-export');
        $this->logOverwrite = $this->getSystemSetting('log-overwrite');
        $this->includePHI = $this->getSystemSetting('include-phi');

        // Location of the log file.
        $this->cronDocumentation = $this->outputDir . DS . 'Cron documentation.log';

        // keep track of the original pid value even if it was not set.
        $this->originalPID = $_GET['pid'] ?? null;

    }

    /**
     * @param array $cronInfo The crons configuration block from config.json.
     * @throws Exception
     */

    function exportData(array $cronInfo): string
    {

        if (!is_dir($this->outputDir)) {
            REDCap::logEvent("Export Data Files E.M. could not access the output directory.");
            return 'The export directory is not available.';
        }

        if (!$this->systemEnabled) {
            return 'Disabled.';
        }

        if ($this->logOverwrite) {
            file_put_contents($this->cronDocumentation,
                $this->startTimeStamp->format('Y-m-d H:i:s') . ': Log Overwritten' . PHP_EOL);
        }

        $this->setSystemSetting('system-last-run', $this->startTimeStamp->format('Y-m-d H:i:s'));

        $this->log('Export Data Cron started ' . $this->startTimeStamp->format('Y-m-d H:i:s'));
        REDCap::logEvent("Exported csv data started using E.M.");
        $this->log('Logged start time in REDCap activity log.');
        $this->log('Base Directory: ' . $this->outputDir);
        $this->log('Documentation File: ' . $this->cronDocumentation);
        $this->log('Include PHI: ' . ($this->includePHI ? "Yes" : "No"));


        try {
            $projectIds = $this->getActiveProjectIds();
            $this->log('Project IDs generated.');
        } catch (Exception $e) {
            $this->log('Project IDs could not be generated.');
            return 'No project IDs.';
        }
        if (!$projectIds) {
            $this->log('There are no production projects to export.');
        }

        foreach ($projectIds as $pid) {
            // add check to see if the EM is enabled for the project
            $enabled = $this->getProjectSetting('project-enabled', $pid);
            if ($enabled) {
                $this->exportDataFiles($pid);
            } else {
                $this->log('PID ' . $pid . ' EM is not enabled in project EM settings');
            }
        }

        $endTimeStamp = new DateTime('now');
        $runTime = $endTimeStamp->diff($this->startTimeStamp);
        $readableRunTime = $runTime->format('%H Hours %I Minutes %S Seconds');

        $this->log('Completed in ' . $readableRunTime . PHP_EOL . PHP_EOL);

        $_GET['pid'] = $this->originalPID;

        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";

    }


    /**
     * Get active projects where that are not automatically included as a redcap demo project.
     * @return array an array of project IDs.  An empty array if no projects are found.
     */
    private function getActiveProjectIds(): array
    {
        $query = "SELECT `project_id` FROM `redcap_projects`" .
            " WHERE (`status` = 1 OR `status` = 2)" .
            " AND (`project_name` not like '%redcap_demo_%')" .
            " AND ISNULL (`completed_time`)";
        $params = [];
        $projectIds = [];

        try {
            $result = $this->query($query, $params);
        } catch (Exception $e) {
            $this->log('Caught exception' . $e->getMessage());
            return $projectIds;
        }

        if ($result->num_rows === 0) {
            $this->log('No projects with a status=1');
            return $projectIds;
        }

        while ($row = $result->fetch_assoc()) {
            $projectIds[] = $row['project_id'];
        }
        return $projectIds;
    }

    /**
     * @param $message string
     * Message to write to the log file
     */
    private function log(string $message)
    {
        if (!$this->logExport) return;
        file_put_contents($this->cronDocumentation,
            date('H:i:s', time()) . ': ' . $message . PHP_EOL,
            FILE_APPEND);
    }

    /**
     * @param $pid
     * @throws Exception
     */
    private function exportDataFiles($pid)
    {
        $proj = new Project($pid);

        REDCap::logEvent("Exported data for  project ID " . $pid . ' using E.M.',
            "",
            "",
            null,
            null,
            $pid);

        // Get basic project info.
        $project = $this->getProject($pid);
        $projectTitle = $project->getTitle();
        $eventForms = $proj->eventsForms;

        $this->log($pid . ': ' . $projectTitle . ' started.');

        // create a safe directory name.
        $projectFolderName = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $projectTitle);
        $projectPath = $this->outputDir . DS . $projectFolderName . DS;
        $this->makeProjectDirectory($projectPath);

        // stop if the directory was not created.
        if (!file_exists($projectPath)) {
            return;
        }

        // Name of the Single Export file.
        $projectCSVMultipleFile = $projectPath . 'all.csv';

        // Get Data
        $projectAllData = REDCap::getData($pid, 'csv');

        // Get the variables for each instrument.
        $formVariables = $this->createFormVariables($pid);

        // create list of the events for each form
        $formEvents = [];
        foreach ($eventForms as $eventId => $formNames) {
            foreach ($formNames as $formName) {
                $formEvents[$formName][] = $eventId;
            }
        }

        // look up the exact events that each instrument is in and ONLY get these events.

        // create the single export file.
        file_put_contents($projectCSVMultipleFile, $projectAllData);
        $this->log('    Exported all file.');

        // create a single csv file for each instrument.
        foreach ($formVariables as $formName => $variables) {
            // if the instrument is not assigned an event do not include the event in the export.
            if (empty($formEvents[$formName])) {
                continue;
            }
            $data = REDCap::getData($pid, 'csv', null, $variables, $formEvents[$formName]);
            $projectInstrumentPath = $projectPath . $formName . '.csv';
            file_put_contents($projectInstrumentPath, $data);
        }

        // we are done!  Celebrate with a very calm note in the log file.
        $this->log('    Exported instrument CSV files.' . PHP_EOL);

    }

    /**
     * @throws Exception
     */
    private function createFormVariables($pid): array
    {

        $proj = new Project($pid);
        $isLongitudinal = $proj->longitudinal;
        $projectDictionary = REDCap::getDataDictionary($pid, 'array');

        $formVariables = [];

        $recordIdName = array_key_first($projectDictionary);

        foreach ($projectDictionary as $properties) {
            // include PHI only if marked yes on the system setting.
            if ($this->includePHI || (!$properties['identifier'] == 'y')) {
                $formVariables[$properties['form_name']][] = $properties['field_name'];
            }
        }

        foreach ($formVariables as $formName => $variables) {

            array_push($variables, $formName . '_completed');
            array_unshift($variables, 'redcap_repeat_instance', 'redcap_repeat_instrument');

            if ($isLongitudinal) {
                array_unshift($variables, 'redcap_event_name');
            }

            // add record_id name to all forms
            array_unshift($variables, $recordIdName);

            //  remove duplicate record ID for the first instrument from the array.
            $formVariables[$formName] = array_unique($variables);
        }

        return $formVariables;
    }

    private function makeProjectDirectory($projectPath): void
    {
        if (!file_exists($projectPath)) {
            $this->log('Created new directory ' . $projectPath);
            mkdir($projectPath, 0744, true);
            if (!file_exists($projectPath)) {
                $this->log('Unable to make new directory ' . $projectPath);
            }
        }
    }
}
