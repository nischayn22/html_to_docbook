<?php

if ( empty( $argv[1] ) ) {
	exit;
}
generateDocbookXML( $argv[1] );
generateOutput( $argv[1] );

function generateDocbookXML( $docbook_folder ) {
	$result = json_decode( file_get_contents( "./uploads/$docbook_folder/$docbook_folder.json" ), true );
	$result['status'] = "Starting to Process";
	file_put_contents( "./uploads/$docbook_folder/$docbook_folder.json", json_encode( $result ) );

	if ( file_exists( "./uploads/$docbook_folder/xsl_repository.json" ) ) {
		$repo_path = json_decode( file_get_contents( "./uploads/$docbook_folder/xsl_repository.json" ), true )['DocBookExportXSLRepository'];
		if ( !file_exists( "./uploads/$docbook_folder/" . basename( $repo_path ) ) ) {
			shell_exec( "git clone $repo_path ./uploads/$docbook_folder/" . basename( $repo_path ) );
		}
		shell_exec( "git -C ./uploads/$docbook_folder/" . basename( $repo_path ) . " pull origin master -s recursive -X theirs" );
	}

	$page_html = file_get_contents( "./uploads/$docbook_folder/$docbook_folder" . "_pandoc.html" );

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHtml( mb_convert_encoding( $page_html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
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

		$replace_nodes_pandoc = [];
		foreach( $tmpDoc->getElementsByTagName( 'literallayout' ) as $pandoc_node ) {
			$paraNode = $tmpDoc->createElement( 'para' );
			foreach( $pandoc_node->attributes as $attribute ) {
				$paraNode->setAttribute( $attribute->name, $attribute->value );
			}
			foreach($pandoc_node->childNodes as $child) {
				$paraNode->appendChild($pandoc_node->removeChild($child));
			}
			if ( $pandoc_node->childNodes->length == 0 ) {
				$paraNode->textContent = $pandoc_node->textContent;
			}
			$replace_nodes_pandoc[] = [ $paraNode, $pandoc_node ];
		}

		foreach( $tmpDoc->getElementsByTagName( 'link' ) as $pandoc_node ) {
			if ( $pandoc_node->hasAttribute( 'xlink:href' ) ) {
				$ulinkNode = $tmpDoc->createElement( 'ulink' );
				$ulinkNode->setAttribute( "url", $pandoc_node->getAttribute( "xlink:href" ) );
				if ( $pandoc_node->childNodes->length == 0 ) {
					$ulinkNode->textContent = $pandoc_node->textContent;
				}
				$replace_nodes_pandoc[] = [ $ulinkNode, $pandoc_node ];
			}
		}

		foreach( $tmpDoc->getElementsByTagName( 'figure' ) as $pandoc_node ) {
			$label = array_shift( $xreflabels );
			$pandoc_node->setAttribute( 'xreflabel', $label );
			$pandoc_node->setAttribute( 'id', $label );
			$pandoc_node->appendChild( $tmpDoc->createElement( 'title', $label ) );
		}

		// TODO: Move to XSL implementation
		// These kind of modifications should typically be done using XSL
		foreach( $tmpDoc->getElementsByTagName( 'informaltable' ) as $pandoc_node ) {
			$pandoc_node->setAttribute( 'frame', "all" );
			$pandoc_node->setAttribute( 'pgwide', "1" );
		}

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
				if ( $pandoc_node->hasAttribute( 'xlink:href' ) ) {
					$footnoteParaNode->textContent = urldecode( $pandoc_node->getAttribute( 'xlink:href' ) );
				} else if ( $pandoc_node->hasAttribute( 'url' ) ) {
					$footnoteParaNode->textContent = urldecode( $pandoc_node->getAttribute( 'url' ) );
				} else {
					$footnoteParaNode->textContent = "Text Missing";
				}
				$footnoteNode->appendChild( $footnoteParaNode );
				$replace_nodes_pandoc[] = [ $footnoteNode, $pandoc_node ];
			}
		}
		foreach( $tmpDoc->getElementsByTagName( 'ulink' ) as $pandoc_node ) {
			if ( $pandoc_node->hasAttribute( 'role' ) && $pandoc_node->getAttribute( 'role' ) == 'footnote' ) {
				$footnoteNode = $tmpDoc->createElement( 'footnote' );
				$footnoteParaNode = $tmpDoc->createElement( 'para' );
				if ( $pandoc_node->hasAttribute( 'xlink:href' ) ) {
					$footnoteParaNode->textContent = urldecode( $pandoc_node->getAttribute( 'xlink:href' ) );
				} else if ( $pandoc_node->hasAttribute( 'url' ) ) {
					$footnoteParaNode->textContent = urldecode( $pandoc_node->getAttribute( 'url' ) );
				} else {
					$footnoteParaNode->textContent = "Text Missing";
				}
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

	$index_terms = json_decode( file_get_contents( "./uploads/$docbook_folder/index_terms.json" ), true );
	recursiveAddIndexTerms( $dom, $dom, $index_terms );

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

function recursiveAddIndexTerms( $dom, &$node, $index_terms ) {
	if( $node->hasChildNodes() ) {
		foreach( $node->childNodes as $childNode ) {
			recursiveAddIndexTerms( $dom, $childNode, $index_terms );
		}
	} else if ( !empty( $node->nodeValue ) ) {
		$tmpDoc = new DOMDocument();
		$node_content = $node->nodeValue;
		$indexOccurs = false;
		$index_term_xml_all = '';
		foreach( $index_terms as $index_term => $index_data ) {
			$index_term = trim($index_term);
			$index_term_xml = '<indexterm><primary>' . $index_term . '</primary></indexterm>';
			$search_term = $index_term;
			if ( !empty( $index_data['primary'] ) ) {
				$index_term_xml = '<indexterm><primary>' . $index_data['primary'] . '</primary><secondary>'. $index_term .'</secondary></indexterm>';
				$search_term = trim( $index_data['primary'] . " " . $index_term );
			}

			$node_content = trim(preg_replace('/\s\s+/', ' ', str_replace("\n", " ", $node_content)));
			if ( strpos( $node_content, $search_term ) !== FALSE ) {
				$index_term_xml_all .= $index_term_xml;
				$node_content = str_replace(
					$search_term,
					$search_term . $index_term_xml,
					$node_content 
				);
				$indexOccurs = true;
			}
		}
		if ( !$indexOccurs ) {
			return;
		}

		if ( $node->parentNode->tagName == "title" || $node->parentNode->tagName == "corpauthor" ) {
			return;
		}

		$tmpDoc->loadXML( "<body>$node_content</body>" );
		$replacement = $tmpDoc->getElementsByTagName( 'body' )->item(0)->childNodes;

		$new_node = $dom->createDocumentFragment();
		for ($i = 0; $i <= $replacement->length - 1; $i++) {
			$child = $dom->importNode($replacement->item($i), true);
			$new_node->appendChild($child);
		}
		$node->parentNode->replaceChild( $new_node, $node );
	}
}

function generateOutput( $docbook_folder ) {
	unlink( "./uploads/$docbook_folder/output.log" );
	error_log( "Starting to process...\n", 3, "./uploads/$docbook_folder/output.log" );

	$all_files = [];

	error_log( "Adding file $docbook_folder.xml to images directory\n", 3, "./uploads/$docbook_folder/output.log" );
	$all_files["$docbook_folder.xml"] = "./uploads/$docbook_folder/$docbook_folder.xml";
	$files = scandir( "./uploads/$docbook_folder/images" );

	foreach( $files as $docbook_file ) {
		$ext = pathinfo($docbook_file, PATHINFO_EXTENSION);
		if ( !empty( $ext ) ) {
			error_log( "Adding file $docbook_file to images directory\n", 3, "./uploads/$docbook_folder/output.log" );
			$all_files["images/" . basename( $docbook_file )] = "./uploads/$docbook_folder/images/$docbook_file";
		}
	}
	$output_filename = $docbook_folder ."_xml.zip";
	$output_filepath = "./uploads/$docbook_folder/". $output_filename;
	$zip = new ZipArchive();

	if( file_exists( $output_filepath ) ) {
		unlink( $output_filepath );
	}

	error_log( "Creating zip archive $output_filepath\n", 3, "./uploads/$docbook_folder/output.log" );
	if ( $zip->open( $output_filepath, ZipArchive::CREATE ) !== TRUE ) {
		exit( "cannot open <$output_filepath>\n" );
	}

	foreach( $all_files as $filename => $path ) {
		error_log( "Adding file $filename to zip archive\n", 3, "./uploads/$docbook_folder/output.log" );
		$zip->addFromString( $filename, file_get_contents( $path ) );
	}
	error_log( "Closing zip archive\n", 3, "./uploads/$docbook_folder/output.log" );
	$zip->close();
	unset( $all_files["$docbook_folder.xml"] );

	error_log( "Creating HTML file ./uploads/$docbook_folder/$docbook_folder.html\n", 3, "./uploads/$docbook_folder/output.log" );

	$xslt_output_log = [];
	$return_value = 0;

	unlink( "./uploads/$docbook_folder/xslt_html_output.log" );
	exec( "xsltproc --output ./uploads/$docbook_folder/$docbook_folder.html --stringparam html.stylesheet docbookexport_styles.css --stringparam fop1.extensions 1 ./docbook-xsl-1.79.1/html/docbook.xsl ./uploads/$docbook_folder/$docbook_folder.xml >> ./uploads/$docbook_folder/xslt_html_output.log 2>&1", $xslt_output_log, $return_value );

	error_log( implode( "\n", $xslt_output_log ) . "\n", 3, "./uploads/$docbook_folder/output.log" );
	error_log( "Process exited with code $return_value\n", 3, "./uploads/$docbook_folder/output.log" );

	$page_html = file_get_contents( "./uploads/$docbook_folder/$docbook_folder" . "_pandoc.html" );

	$all_files["$docbook_folder.html"] = "./uploads/$docbook_folder/$docbook_folder.html";
	$all_files["docbookexport_styles.css"] = "./uploads/$docbook_folder/docbookexport_styles.css";

	$output_filename = $docbook_folder ."_html.zip";
	$output_filepath = "./uploads/$docbook_folder/". $output_filename;

	error_log( "Creating ZIP archive $output_filename\n", 3, "./uploads/$docbook_folder/output.log" );
	$zip = new ZipArchive();

	if( file_exists( $output_filepath ) ) {
		unlink( $output_filepath );
	}

	if ( $zip->open( $output_filepath, ZipArchive::CREATE ) !== TRUE ) {
		exit( "cannot open <$output_filepath>\n" );
	}

	foreach( $all_files as $filename => $path ) {
		error_log( "Adding file $filename to zip archive\n", 3, "./uploads/$docbook_folder/output.log" );
		$zip->addFromString( $filename, file_get_contents( $path ) );
	}
	error_log( "Closing zip archive\n", 3, "./uploads/$docbook_folder/output.log" );
	$zip->close();

	$output_filename = $docbook_folder .".pdf";
	$output_filepath = "./uploads/$docbook_folder/". $output_filename;

	// $xsltproc_args = "";
	// if ( file_exists( "./uploads/$docbook_folder/xsltproc_args.txt" ) ) {
		// $xsltproc_args = file_get_contents( "./uploads/$docbook_folder/xsltproc_args.txt" );
	// }

	error_log( "Deleting old FOP file\n", 3, "./uploads/$docbook_folder/output.log" );
	if( file_exists( "./uploads/$docbook_folder/$docbook_folder.fo" ) ) {
		unlink( "./uploads/$docbook_folder/$docbook_folder.fo" );
	}

	error_log( "Creating FOP file\n", 3, "./uploads/$docbook_folder/output.log" );
	$xslt_output_log = [];
	$return_value = 0;
	unlink( "./uploads/$docbook_folder/xslt_fop_output.log" );
	exec( "java com.icl.saxon.StyleSheet -o ./uploads/$docbook_folder/$docbook_folder.fo ./uploads/$docbook_folder/$docbook_folder.xml ./uploads/$docbook_folder/docbookexport.xsl >> ./uploads/$docbook_folder/xslt_fop_output.log 2>&1", $xslt_output_log, $return_value );

	error_log( implode( "\n", $xslt_output_log ) . "\n", 3, "./uploads/$docbook_folder/output.log" );
	error_log( "Process exited with code $return_value\n", 3, "./uploads/$docbook_folder/output.log" );

	error_log( "Creating PDF file\n", 3, "./uploads/$docbook_folder/output.log" );
	$fop_output_log = [];
	$return_value = 0;
	exec( "fop -r -fo ./uploads/$docbook_folder/$docbook_folder.fo -pdf $output_filepath >> ./uploads/$docbook_folder/fop_output.log 2>&1", $fop_output_log, $return_value );

	error_log( implode( "\n", $fop_output_log ) . "\n", 3, "./uploads/$docbook_folder/output.log" );
	error_log( "Process exited with code $return_value\n", 3, "./uploads/$docbook_folder/output.log" );

	error_log( "Creating ODF file\n", 3, "./uploads/$docbook_folder/output.log" );
	$odf_output_log = [];
	$return_value = 0;
	exec( "./docbook2odf-0.244/utils/docbook2odf -f --debug -output-dir=./uploads/$docbook_folder -xsl-file=./docbook2odf-0.244/xsl ./uploads/$docbook_folder/$docbook_folder.xml >> ./uploads/$docbook_folder/odf_output.log 2>&1", $odf_output_log, $return_value );

	error_log( implode( "\n", $odf_output_log ) . "\n", 3, "./uploads/$docbook_folder/output.log" );
	error_log( "Process exited with code $return_value\n", 3, "./uploads/$docbook_folder/output.log" );

	$all_files = [];
	$files = scandir( "./uploads/$docbook_folder/$docbook_folder.od.temp/Pictures" );
	foreach( $files as $docbook_file ) {
		$ext = pathinfo($docbook_file, PATHINFO_EXTENSION);
		if ( !empty( $ext ) ) {
			$all_files["Pictures/" . basename( $docbook_file )] = "./uploads/$docbook_folder/$docbook_folder.od.temp/Pictures/$docbook_file";
		}
	}
	$files = scandir( "./uploads/$docbook_folder/$docbook_folder.od.temp/process" );
	foreach( $files as $docbook_file ) {
		$ext = pathinfo($docbook_file, PATHINFO_EXTENSION);
		if ( !empty( $ext ) ) {
			$all_files["process/" . basename( $docbook_file )] = "./uploads/$docbook_folder/$docbook_folder.od.temp/process/$docbook_file";
		}
	}
	$files = scandir( "./uploads/$docbook_folder/$docbook_folder.od.temp/META-INF" );
	foreach( $files as $docbook_file ) {
		$ext = pathinfo($docbook_file, PATHINFO_EXTENSION);
		if ( !empty( $ext ) ) {
			$all_files["META-INF/" . basename( $docbook_file )] = "./uploads/$docbook_folder/$docbook_folder.od.temp/META-INF/$docbook_file";
		}
	}
	$all_files["content.xml"] = "./uploads/$docbook_folder/$docbook_folder.od.temp/content.xml";
	$all_files["meta.xml"] = "./uploads/$docbook_folder/$docbook_folder.od.temp/meta.xml";
	$all_files["mimetype"] = "./uploads/$docbook_folder/$docbook_folder.od.temp/mimetype";
	$all_files["styles.xml"] = "./uploads/$docbook_folder/$docbook_folder.od.temp/styles.xml";

	$output_filename = $docbook_folder .".odt";
	$output_filepath = "./uploads/$docbook_folder/". $output_filename;

	error_log( "Creating ODT file $docbook_folder.odt\n", 3, "./uploads/$docbook_folder/output.log" );
	$zip = new ZipArchive();

	if ( $zip->open( $output_filepath, ZipArchive::CREATE ) !== TRUE ) {
		exit( "cannot open <$output_filepath>\n" );
	}
	foreach( $all_files as $filename => $path ) {
		error_log( "Adding file $filename to zip archive\n", 3, "./uploads/$docbook_folder/output.log" );
		$zip->addFromString( $filename, file_get_contents( $path ) );
	}
	error_log( "Closing zip archive\n", 3, "./uploads/$docbook_folder/output.log" );
	$zip->close();

	$result = json_decode( file_get_contents( "./uploads/$docbook_folder/$docbook_folder.json" ), true );

	error_log( "Updating $docbook_folder.json\n", 3, "./uploads/$docbook_folder/output.log" );

	$result['status'] = 'Docbook generated at ' . (new DateTime())->format("d M y H:i") . date_default_timezone_get();
	$result['docbook_zip'] = "/uploads/$docbook_folder/$docbook_folder" . "_xml.zip";
	$result['docbook_odf'] = "/uploads/$docbook_folder/$docbook_folder.odt";
	$result['docbook_html'] = "/uploads/$docbook_folder/$docbook_folder" . "_html.zip";
	$result['docbook_pdf'] = "/uploads/$docbook_folder/$docbook_folder.pdf";
	file_put_contents( "./uploads/$docbook_folder/$docbook_folder.json", json_encode( $result ) );
}
