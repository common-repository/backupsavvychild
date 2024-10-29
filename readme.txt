=== BackupSavvy Child wordpress plugin ===
Contributors: pdtasktrack
Donate link: http://www.hubroom.com
Tags: backup, multi backtup child, savvy backup
Requires at least: 4.5
Tested up to: 5.3.2
Stable tag: 1.2
Requires PHP: 7.1

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
The GNU General Public License see <http://www.gnu.org/licenses/>.

== Description ==

Here is a child plugin of wordpress backup platform.

You can create backups for all your wordpress sites from the one admin panel and upload it on one FTP storage by using this plugin.

The Dashboard you may install by this link https://wordpress.org/plugins/backupsavvy/

== Installation ==

 1) Download and activate "BackupSavvy" dashboard plugin on your main site - https://wordpress.org/plugins/backupsavvy/
 2) Download and activate "BackupSavvy Child" plugin for every site you want to backup
 3) Go to "BackupSavvy Child" settigs page
 4) Copy secret code of this site
 5) Go to "WP BackupSavvy" dashboard settings page
 6) Add child site with the new secret to your Dashboard
 7) Go to Storage tab of the Dashboard
 8) Add your remote ftp settings
 9) Go to Scheduler tab
 10) Add new job for automatic backups creation
 11) Go to "sites list" - here you can create the all(or one) backups manually. And also sync ftp settings for the new sites or add unique ftp settings for every site if you need it.

 == Frequently Asked Questions ==

 = Why the backups are not created on the windows server? =

 If site which is using this plugin is located on the windows server with Fast CGI it is a possible you'll be needed to change config settings, the activityTimeout exacyly:
 1) Find C:\Windows\System32\inetsrv\config\ applicationHost.config file
 2) Create the copy of this file
 3) Open the original (or use window+R->inetmgr)
 4) Find and change the activityTimeout to 80 (or more if it's possible in the server configuration of yours). It should be something like in this example:

 &lt;fastCgi&gt;
     &lt;application fullPath = "C:\php\php-cgi.exe" arguments = ""
         monitorChangesTo = "" stderrMode = "ReturnStdErrIn500" maxInstances = "4"
         idleTimeout = "300" activityTimeout = "80" requestTimeout = "1000"
         instanceMaxRequests = "10000" protocol = "NamedPipe" queueLength = "1000"
         flushNamedPipe = "false" rapidFailsPerMinute = "10"&gt;
         &lt;environmentVariables&gt;
             &lt;environmentVariable name="PHP_MAX_REQUESTS" value="5000" /&gt;
         &lt;/environmentVariables&gt;
     &lt;/application&gt;
 &lt;/fastCgi&gt;

 = Why backups are not uploading to dropbox or aws cloud on windows server? =
 1) Download and extract for cacert.pem https://curl.haxx.se/docs/caextract.html
 2) Put it on cert folder of yours php version (for example C:\Program files\php\v5.6\extras\ssl\cacert.pem)
 3) In your php.ini put this line in this section ("C:\Program files\php\v5.6\php.ini") find and change curl.cainfo:

 curl.cainfo = "C:\Program files\php\v5.6\extras\ssl\cacert.pem"