<?php

namespace Dcc\ExportDataFiles;

use DateTime;
use Exception;
use ExternalModules\AbstractExternalModule;
use Project;
use REDCap;

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

    /**
     * @var DateTime
     */
    private $startTimeStamp;
    /**
     * @var
     */
    private $logExport;
    /**
     * @var
     */
    private $systemEnabled;
    /**
     * @var
     */
    private $logOverwrite;
    /**
     * @var mixed|null
     */
    private $originalPID;
    /**
     * @var
     */
    private $systemIncludePHI;

    private $useProjectName;
    /**
     * @var int
     */
    private $logMaxLines;


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
        $this->logMaxLines = (int)$this->getSystemSetting('log-lines');
        $this->systemIncludePHI = $this->getSystemSetting('system-include-phi');
        $this->useProjectName = $this->getSystemSetting('use-project-name');

        // Location of the log file.
        $this->cronDocumentation = $this->outputDir . DS . 'Cron documentation.log';

        // keep track of the original pid value even if it was not set.
        $this->originalPID = $_GET['pid'] ?? null;
        if ($this->logExport) {
            $this->displayCronLogLines();
        } else {
            $this->setSystemSetting('system-first-lines-log', '');
        }

    }

    /**
     * @param array $cronInfo The crons configuration block from config.json.
     * @throws Exception
     */

    public function exportData(array $cronInfo): string
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
        $this->log('Include PHI: ' . ($this->systemIncludePHI ? "Yes" : "No"));
        $this->log('Use project name for folder name: ' . ($this->useProjectName ? "Yes" : "No"));


        try {
            $projectIds = $this->getActiveProjectIds();
            $this->log('Project IDs generated.');
        } catch (Exception $e) {
            $this->log('Project IDs could not be generated.');
            REDCap::logEvent("Export Data Files E.M. Project IDs could not be generated.");
            return 'No project IDs.';
        }
        if (!$projectIds) {
            $this->log('There are no production projects to export.');
            REDCap::logEvent("Export Data Files E.M. There are no production projects to export.");
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
     * write a message to the log file.
     */
    private function log(string $message): void
    {
        if (!$this->logExport) {
            return;
        }
        file_put_contents($this->cronDocumentation,
            date('H:i:s') . ': ' . $message . PHP_EOL,
            FILE_APPEND);
    }

    /**
     * @param $pid
     * @throws Exception
     * Export CSV files.  One file for each instrument in each project.
     * Project data is located in a project specific folder.
     */
    private function exportDataFiles($pid): void
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
        $projectDictionaryCsv = REDCap::getDataDictionary($pid, 'csv');


        $this->log($pid . ': ' . $projectTitle . ' started.');

        // create a safe directory name.
        // The default name is the project ID. It never changes.
        // The user can specify if the project name should be used as the folder name instead.
        if ($this->useProjectName) {
            $projectFolderName = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $projectTitle);
        } else {
            $projectFolderName = (string)$pid;
        }

        $projectPath = $this->outputDir . DS . $projectFolderName . DS;
        $this->makeProjectDirectory($projectPath);

        // stop if the directory was not created.
        if (!file_exists($projectPath)) {
            REDCap::logEvent("Export Data Files E.M. Project specific path is unavailable.");
            return;
        }

        $projectStartTimeStamp = new DateTime();
        $projectReadMeFileName = $projectPath . '_readme_' . $projectTitle . '.txt';

        // create the dictionary
        $projectDictionaryFileName = $projectPath . 'dictionary.csv';
        $projectDictionaryCsv = REDCap::getDataDictionary($pid, 'csv');
        file_put_contents($projectDictionaryFileName, $projectDictionaryCsv);

        // Name of the Single Export file.
        $projectCSVMultipleFile = $projectPath . 'all.csv';


        // Get the variables for each instrument.
        $formVariables = $this->createFormVariables($pid);


        // todo rename var.  Why is this called sanitized?
        $sanitizedDictionary = [];
        foreach ($formVariables as $form => $variables) {
            foreach ($variables as $variable) {
                $sanitizedDictionary[] = $variable;
            }
        }

        // Get Data
        $projectAllData = REDCap::getData($pid, 'csv', null, $sanitizedDictionary);


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

        $projectEndTimeStamp = new DateTime();

        $projectMessage = $pid . ': ' . $projectTitle . ' exported via em.' . PHP_EOL .
            'Started at: ' . $projectStartTimeStamp->format('Y-m-d H:i:s') . PHP_EOL .
            'Completed at: ' . $projectEndTimeStamp->format('Y-m-d H:i:s') . PHP_EOL .
            'Include PHI: ' . ($this->systemIncludePHI ? "Yes" : "No");

        file_put_contents($projectReadMeFileName, $projectMessage);


    }

    /**
     * @throws Exception
     * Create an array where each instrument has an array of active event IDs for that specific instrument.
     */
    private function createFormVariables($pid): array
    {

        $proj = new Project($pid);
        $isLongitudinal = $proj->longitudinal;
        $projectDictionaryArray = REDCap::getDataDictionary($pid, 'array');

        $formVariables = [];

        $recordIdName = array_key_first($projectDictionaryArray);

        foreach ($projectDictionaryArray as $properties) {
            // include PHI only if marked yes on the system setting.
            if ($this->systemIncludePHI || (!$properties['identifier'] == 'y')) {
                // include project PHI only if the project checked yes to include PHI.
                if ($this->getProjectSetting('project-include-phi', $pid)) {
                    $formVariables[$properties['form_name']][] = $properties['field_name'];
                }
            }
        }

        foreach ($formVariables as $formName => $variables) {

            $variables[] = $formName . '_completed';
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

    /**
     * @param $projectPath
     * Create a project specific directory.
     */
    private function makeProjectDirectory($projectPath): void
    {
        if (!file_exists($projectPath)) {
            $this->log('Created new directory ' . $projectPath);
            if (!mkdir($projectPath, 0744, true) && !is_dir($projectPath)) {
                $this->log('Unable to make new directory ' . $projectPath);
            }
        }
    }

    private function getSampleLinesFromFile($maxLines): array
    {
        $handle = fopen($this->cronDocumentation, "r");
        if (!$handle) {
            return false;

        }
        $lines = [];
        for ($i = 0; $i <= $maxLines; $i++) {
            $line = fgets($handle);
            if ($line !== false) {
                $lines[] = $line;
            }
        }
        fclose($handle);

        return $lines;
    }

    private function displayCronLogLines()
    {
        if (!$this->logMaxLines) {
            $this->logMaxLines = 1000;
        } elseif ($this->logMaxLines > 1000) {
            $this->logMaxLines;
        }
        if ($this->logMaxLines > 0) {
            $lines = implode(PHP_EOL, $this->getSampleLinesFromFile($this->logMaxLines));
            if ($lines) {
                $this->setSystemSetting('system-first-lines-log', $lines);
            }
        }
    }
}
