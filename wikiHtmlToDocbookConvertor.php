<?php
error_reporting(E_STRICT);

$result = array();

$request_type = $_POST['request_type'];
$docbook_folder = $_POST['docbook_name'];

if( $request_type == "getDocbook" ) {
	mkdir( "./uploads/$docbook_folder" );
	mkdir( "./uploads/$docbook_folder/images" );

	$total = count( $_FILES['files']['name'] );
	for( $i = 0; $i < $total; $i++ ) {
		$tmpFilePath = $_FILES['files']['tmp_name'][$i];
		if ( !empty( $tmpFilePath ) ) {
			$filename = $_FILES['files']['name'][$i];
			$ext = pathinfo($filename, PATHINFO_EXTENSION);
			if ( $ext == "html" || $ext == "xsl" ) {
				move_uploaded_file( $tmpFilePath, "./uploads/$docbook_folder/$filename" );
			} else {
				move_uploaded_file( $tmpFilePath, "./uploads/$docbook_folder/images/$filename" );
			}
		}
	}

	$result['result'] = "success";
	$result['status'] = "In Queue";
	file_put_contents( "./uploads/$docbook_folder/$docbook_folder.json", json_encode( $result ) );
} else if ( $request_type == "getDocbookStatus" ) {
	$result = json_decode( file_get_contents( "./uploads/$docbook_folder/$docbook_folder.json" ), true );
} else {
	$result['result'] = "failed";
	$result['error'] = "Unrecognized command";
}

echo json_encode( $result );