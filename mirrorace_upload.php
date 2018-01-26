<?php
/* Example script to upload files to MirrorAce.com via api. */
/* Detailed information on API available at https://mirrorace.com/api. */
/* Author: MirrorAce */
/* URL: https://mirrorace.com */
//vars
$api_key = '0123456789abcdefabcdefabcdefabcd';
$api_token = '0123456789abcdefabcdefabcdefabcd';
//absolute file path
$file = '/home/file.rar';
//$filename = 'file.zip'; //set manually
$filename = basename($file); //set automatically //file.rar
$file_size = filesize($file);
/* STEP 1: get upload_key */
$url = 'https://mirrorace.com/api/v1/file/upload';
$post = array(
	'api_key' => $api_key,
	'api_token' => $api_token,
);
$opt = array();
$opt[CURLOPT_URL] = $url;
$opt[CURLOPT_POST] = true;
$opt[CURLOPT_POSTFIELDS] = $post;
$opt[CURLOPT_RETURNTRANSFER] = true;
$opt[CURLOPT_SSL_VERIFYPEER] = false;
$opt[CURLOPT_SSL_VERIFYHOST] = false;
$ch = curl_init();
curl_setopt_array($ch, $opt);
$response = curl_exec( $ch );
$response_info = curl_getinfo( $ch );
curl_close ( $ch );
if ($response_info['http_code'] != 200) {
	echo 'Unable to request upload_key and other variables. http_code: ' . $response_info['http_code'];
	exit;
}
$json = json_decode($response, true);
if (!$json) {
	echo 'Unable to parse response while requesting upload_key and other variables. Response: ' . $response;
	exit;
}
if ($json['status'] != 'success' ) {
	echo 'Error while requesting upload_key and other variables. Error: ' . $json['result'];
	exit;
}
/* STEP 2: Upload file */
//vars
$url = $json['result']['server_file'];
$cTracker = $json['result']['cTracker'];
$upload_key = $json['result']['upload_key'];
$default_mirrors = $json['result']['default_mirrors'];
$max_chunk_size = $json['result']['max_chunk_size'];
$max_file_size = $json['result']['max_file_size'];
$max_mirrors = $json['result']['max_mirrors'];
//check file size limit
if ($file_size >= $max_file_size) {
	echo 'File exceeds maximum file size allowed: ' . $max_file_size;
	exit;
}
//setup
$mirrors = $default_mirrors;
$chunk_size = $max_chunk_size;
$chunks = $file_size / $chunk_size;
$chunks = ceil($chunks);
//range vars //for multi chunk upload
$last_range = false;
$response = false;
$i = 0;
$while_error = false;
while( $i < $chunks && !$while_error ) {
	$range_start = 0 ;
	$range_end = min( $chunk_size, $file_size - 1 );
	
	if ( $last_range !== false ) {
		$range_start = $last_range + 1 ;
		$range_end = min( $range_start + $chunk_size, $file_size - 1 ) ;
	}
	
	$last_range = $range_end;
	
	$post = array(
		'api_key' => $api_key,
		'api_token' => $api_token,
		'cTracker' => $cTracker,
		'upload_key' => $upload_key,
		//these required vars will be added by buildMultiPartRequest function 
		//'files' => $file,
		//'mirrors[1]' => 1,
		//'mirrors[2]' => 2,
	);
	
	$range = "bytes {$range_start}-{$range_end}/{$file_size}";
	
	$opt = array();
	$opt[CURLOPT_URL] = $url;
	$opt[CURLOPT_POST] = true;
	$opt[CURLOPT_RETURNTRANSFER] = true;
	$opt[CURLOPT_SSL_VERIFYPEER] = false;
	$opt[CURLOPT_SSL_VERIFYHOST] = false;
	
	$ch = curl_init();
	
	$file_chunk = file_get_contents( $file, false, NULL, $range_start, ($range_end - $range_start + 1));
	
	$ch = buildMultiPartRequest($ch, uniqid(), $post, $mirrors, array($filename => $file_chunk), $range);
	
	curl_setopt_array($ch, $opt);
	$response = curl_exec( $ch );
	$response_info = curl_getinfo( $ch );
	curl_close ( $ch );
	
	if ($response_info['http_code'] != 200) {
		$while_error = true;
		echo 'Chunk file post error on part no: ' . $i .  '. http_code: ' . $response_info['http_code'];
		exit;
	}
	
	$json = false;
	if (!$while_error) {
		$json = json_decode($response, true);
		
		if ($json) {
			if ($json['status'] == 'error') {
				$while_error = true;
				echo 'Error while uploading part no: ' . $i . '. Error: ' . $json['result'];
				exit;
			}
		} else {
			$while_error = true;
			echo 'Unable to parse post response on part no: ' . $i . '. Response: ' . $response;
			exit;
		}
		
	}
	
	$i++;
	
}
//Parse response after all chunks are uploaded
$json = false;
$json = json_decode($response, true);
if (!$json) {
	echo 'Unable to Parse response. Response: ' . $response;
	exit;
}
if ($json['status'] == 'error') {
	echo 'Error after uploading all chunks. Error: ' . $json['result'];
	exit;
}
$download_link = $json['result']['url'];
echo $download_link;
exit;
function buildMultiPartRequest($ch, $boundary, $fields, $mirrors, $files, $range) {
	$delimiter = '-------------' . $boundary;
	$data = '';
	foreach ($fields as $name => $content) {
		$data .= "--" . $delimiter . "\r\n"
			. 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n"
			. $content . "\r\n";
	}
	
	////add mirrors[] feilds
	foreach ($mirrors as $m) {
		$data .= "--" . $delimiter . "\r\n"
			. "Content-Disposition: form-data; name=\"mirrors[]\"\r\n\r\n"
			. $m . "\r\n";
	}
	
	foreach ($files as $name => $content) {
		$data .= "--" . $delimiter . "\r\n"
			. 'Content-Disposition: form-data; name="files[]"; filename="' . $name . '"' . "\r\n\r\n"
			. $content . "\r\n";
	}
	$data .= "--" . $delimiter . "--\r\n";
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => [
			'Content-Type: multipart/form-data; boundary=' . $delimiter,
			'Content-Length: ' . strlen($data),
			'Content-Range: ' . $range
		],
		CURLOPT_POSTFIELDS => $data
	]);
	
	return $ch;
}
?>
