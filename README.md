#Export CSV files of all production projects or projects in analysis via a cron job.

This E.M. provides a quick and easy way to export all projects as CSV files.  This E.M. may be useful if your center or REDCap instance
does data analysis in house.  It is imperative that the CSV files are stored in a secure location.  
`
The generated CSV files can then be read into a statistical package and analyzed. The data dictionary for the stats
package must be generated and configured, but this only must happen when the dictionary is first created or updated, infrequently we all hope.

The only mandatory setting is the output directory.  It is the location where the CSV files will be stored. The CSV files will be stored in a project specific folder within this top level directory. 

A log file is created and updated whenever the cron job runs. There is a setting to overwrite the previous log file which may be handy for debugging proposes. It is suggested that once in production the log file is never overwritten.
Logging can be turned off.

The JSON file specifies when this module runs.  It will run at 2:01am and can be set to any time or frequency.
NOTE: When this module is updated the new JSON file must be updated to your settings!

PHI can be included or excluded. By default, PHI is excluded.

The EM is also logged in REDCap's activity log.  Each project that is exported has its own entry.

There is one file "all.csv" that will contain all project data for every instrument and every time point. 

Each instrument is also exported as a separate CSV file. 
Each instrument only includes time points, (events), for which it is enabled.

When moving a project to "completed" be aware that the csv export will no longer occur for that project.  
To make sure the CSV files contain the latest data ensure that the cron job executed AFTER the last data entry. Check the project's activity log.  

Each project has a single setting, "enabled".  If it is enabled, then CSV files will be generated.  If this setting is not checked, csv will not be generated.

The last time the cron job executed is captured in a system settings field.