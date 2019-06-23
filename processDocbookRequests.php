<?php

error_reporting(E_STRICT);

$dirs = scandir( "./uploads" );
foreach( $dirs as $docbook_folder ) {
	$files = scandir( "./uploads/$docbook_folder" );
	foreach( $files as $file ) {
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		if ( $ext == "json" ) {
			$docbook_folder = basename( $file, ".json" );
			$result = json_decode( file_get_contents( "./uploads/$docbook_folder/$docbook_folder.json" ), true );
			if ( $result['status'] != "Docbook generated" ) {
				chdir();
				exec( "php generateDocbook.php $docbook_folder" );
			}
		}
	}
}