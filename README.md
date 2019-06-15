# html_to_docbook
Script to convert HTML to Docbook using Pandoc

This script is to be used along with the MediaWiki extension DocBookExport. See https://www.mediawiki.org/wiki/Extension:DocBookExport

First add all the dependencies required as per https://www.mediawiki.org/wiki/Extension:DocBookExport#Dependencies

Copy this folder to your server's web directory. For apache on Ubuntu this location is /var/www/html

Create a folder "uploads" and assign write permissions for www-data to it

Add a cron job to ensure docbook generation happens in the background, something like this should work:

5 * * * * /usr/bin/php -q /var/www/html/html_to_docbook/processDocbookRequests.php
