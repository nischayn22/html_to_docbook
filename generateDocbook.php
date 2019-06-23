<?php

if ( empty( $argv[1] ) ) {
	exit;
}
generateDocbookXML( $argv[1] );
generateOutput( $argv[1] );

function generateDocbookXML( $docbook_folder ) {
	$result = array();
	$result['status'] = "Starting to Process";
	file_put_contents( "./uploads/$docbook_folder/$docbook_folder.json", json_encode( $result ) );

	$page_html = file_get_contents( "./uploads/$docbook_folder/$docbook_folder.html" );

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHtml( $page_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$xreflabels = [];
	foreach( $dom->getElementsByTagName( 'figure' ) as $node ) {
		$xreflabels[] = $node->getAttribute( 'xreflabel' );
	}

	$replace_nodes = [];
	foreach( $dom->getElementsByTagName( 'html_pandoc' ) as $node ) {
		$html = $dom->saveXML( $node );

		$temp_file = tempnam(sys_get_temp_dir(), 'docbook_html');
		if ( !file_put_contents( $temp_file, $html ) ) {
			$result['result'] = "failed";
			$result['error'] = "Failed writing to a temporary file";
		}
		$cmd = "pandoc ". $temp_file . " -f html -t docbook 2>&1";
		$pandoc_output = shell_exec( $cmd );

		$tmpDoc = new DOMDocument();
		$tmpDoc->loadXML( '<body>' . $pandoc_output . '</body>' );

		foreach( $tmpDoc->getElementsByTagName( 'figure' ) as $pandoc_node ) {
			$label = array_shift( $xreflabels );
			$pandoc_node->setAttribute( 'xreflabel', $label );
			$pandoc_node->setAttribute( 'id', $label );
			$pandoc_node->appendChild( $tmpDoc->createElement( 'title', $label ) );
		}

		$replace_nodes_pandoc = [];
		foreach( $tmpDoc->getElementsByTagName( 'link' ) as $pandoc_node ) {
			if ( $pandoc_node->hasAttribute( 'role' ) && $pandoc_node->getAttribute( 'role' ) == 'xref' ) {
				$label = str_replace( '_', ' ', explode( '#', $pandoc_node->getAttribute( 'xlink:href' ) )[1] );
				$xrefNode = $tmpDoc->createElement( 'xref' );
				$xrefNode->setAttribute( 'linkend', $label );
				$replace_nodes_pandoc[] = [ $xrefNode, $pandoc_node ];
			}
		}
		foreach( $tmpDoc->getElementsByTagName( 'link' ) as $pandoc_node ) {
			if ( $pandoc_node->hasAttribute( 'role' ) && $pandoc_node->getAttribute( 'role' ) == 'footnote' ) {
				$footnoteNode = $tmpDoc->createElement( 'footnote' );
				$footnoteParaNode = $tmpDoc->createElement( 'para' );
				$footnoteParaNode->textContent = urldecode( $pandoc_node->getAttribute( 'xlink:href' ) );
				$footnoteNode->appendChild( $footnoteParaNode );
				$replace_nodes_pandoc[] = [ $footnoteNode, $pandoc_node ];
			}
		}

		foreach( $tmpDoc->getElementsByTagName( 'imagedata' ) as $pandoc_node ) {
			$file_url = $pandoc_node->getAttribute( 'fileref' );
			$image_filename = basename( $file_url );
			$pandoc_node->setAttribute( 'fileref', "images/$image_filename" );
		}
		foreach( $replace_nodes_pandoc as $replace_pair ) {
			$replace_pair[1]->parentNode->replaceChild( $replace_pair[0], $replace_pair[1] );
		}

		$replacement = $tmpDoc->getElementsByTagName( 'body' )->item(0)->childNodes;
		$new_node = $dom->createDocumentFragment();
		for ($i = 0; $i <= $replacement->length - 1; $i++) {
			$child = $dom->importNode($replacement->item($i), true);
			$new_node->appendChild($child);
		}
		$replace_nodes[] = [$new_node, $node];
	}

	foreach( $replace_nodes as $replace_pair ) {
		$replace_pair[1]->parentNode->replaceChild( $replace_pair[0], $replace_pair[1] );
	}

	$dom->xmlStandalone = false;
	$docbook_xml = $dom->saveXML();
	$docbook_xml = preg_replace_callback(
		'/\bFOOTNOTE\b(.*)\bFOOTNOTE\b/',
		function( $matches ) use ( &$placeholderId ) {
			return '<footnote><para>' . $matches[1] . '</para></footnote>';
		},
		$docbook_xml
	);
	if ( !file_put_contents( "./uploads/$docbook_folder/$docbook_folder.xml", $docbook_xml ) ) {
		$result['result'] = "failed";
		$result['error'] = "Failed writing docbook xml file";
	} else {
		$result['result'] = "success";
		$result['status'] = "Processing";
	}
	file_put_contents( "./uploads/$docbook_folder/$docbook_folder.json", json_encode( $result ) );
}

function generateOutput( $docbook_folder ) {
	$all_files = [];
	$all_files["$docbook_folder.xml"] = "./uploads/$docbook_folder/$docbook_folder.xml";
	$files = scandir( "./uploads/$docbook_folder/images" );
	foreach( $files as $docbook_file ) {
		$ext = pathinfo($docbook_file, PATHINFO_EXTENSION);
		if ( !empty( $ext ) ) {
			$all_files[$docbook_file] = "./uploads/$docbook_folder/images/$docbook_file";
		}
	}

	$output_filename = '';
	$output_filepath = '';
	$filesize = 0;
	$content_type = '';
	$output_filename = $docbook_folder .".zip";
	$output_filepath = "./uploads/$docbook_folder/". $output_filename;
	$zip = new ZipArchive();

	if( file_exists( $output_filepath ) ) {
		unlink( $output_filepath );
	}

	if ( $zip->open( $output_filepath, ZipArchive::CREATE ) !== TRUE ) {
		exit( "cannot open <$output_filepath>\n" );
	}

	foreach( $all_files as $filename => $path ) {
		$zip->addFromString( $filename, file_get_contents( $path ) );
	}
	$zip->close();

	$output_filename = $docbook_folder .".pdf";
	$output_filepath = "./uploads/$docbook_folder/". $output_filename;

	shell_exec( "xsltproc --output ./uploads/$docbook_folder/$docbook_folder.fo --stringparam fop1.extensions 1 ./uploads/$docbook_folder/docbookexport.xsl ./uploads/$docbook_folder/$docbook_folder.xml" );

	shell_exec( "fop -fo " . "./uploads/$docbook_folder/$docbook_folder.fo -pdf $output_filepath" );
	shell_exec( "./docbook2odf-0.244/utils/docbook2odf -xsl-file=./docbook2odf-0.244/xsl ./uploads/$docbook_folder/$docbook_folder.xml" );
	rename( "$docbook_folder.odt", "./uploads/$docbook_folder/$docbook_folder.odt" );

	$result = json_decode( file_get_contents( "./uploads/$docbook_folder/$docbook_folder.json" ), true );

	$result['status'] = 'Docbook generated';
	$result['docbook_zip'] = "/uploads/$docbook_folder/$docbook_folder.zip";
	$result['docbook_odf'] = "/uploads/$docbook_folder/$docbook_folder.odf";
	$result['docbook_pdf'] = "/uploads/$docbook_folder/$docbook_folder.pdf";
	file_put_contents( "./uploads/$docbook_folder/$docbook_folder.json", json_encode( $result ) );
}
