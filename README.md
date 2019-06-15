# html_to_docbook
Script to convert HTML to Docbook using Pandoc

First add all the dependencies required as per https://www.mediawiki.org/wiki/Extension:DocBookExport#Dependencies

Add this folder to your server's web directory. For apache on Ubuntu this location is /var/www/html

Add write permissions for www-data to 'uploads' folder

Add a cron job to ensure docbook generation happens in the background, something like this should work:

5 * * * * /usr/bin/php -q /var/www/html/html_to_docbook/processDocbookRequests.php
