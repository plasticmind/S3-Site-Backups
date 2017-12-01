<?php

/*
 * Archive and backup to Amazon S3 for Simply Recipes
 * by Jesse Gardner, 03.28.2012
 * Modified for multiple databases by Carolos Foscolos, 04.11.2012
 */

 // Global variables
 
 // START EDITING --->
 
  	$bucket = 'UNIQUE_BUCKETNAME_12345'; // Amazon requires this to be unique. Make the bucket on amazon first to make sure the name is valid.
	$archive_path = './backups/'; // This is where we're going to put our local backups
	$empty_archivepath = false; // WARNING: If this is enabled the backups directory will be cleared before backup starts, if not enabled will fill up your server.
	
	$expire_after = 30; // How many days should Amazon hold on to a backup?
	$notify_email = 'youremail@example.com'; // Comma-separated list of email address to notify on successful backup
	$notify_sitename = 'Site Backups'; // Name to use for email notification subject line
	$date = date("Y-m-d");
	
	$path_to_archive = './public_html/'; // The local path we want to back up
	$db_host   = 'localhost';
	$db_names   = array('DB_NAME1', 'DB_NAME2'); // Use array('DB_NAME1') for only one array. No limit.
	$db_user   = 'DB_USERNAME'; // Current script only allows one user/pass for all databases
	$db_pwd    = 'DB_PASSWORD';
	$s3_region = 'US'; // Format eg. REGION_EU_W1

// <--- STOP EDITING!


// Set up the AmazonS3 class
	require_once './s3/sdk.class.php';
	$s3 = new AmazonS3();

// Empty backup directory
	if ($empty_archivepath) {
		exec("(rm $archive_path"."*.gz) &> /dev/null ");
	}	
	
	$s3->enable_path_style(TRUE); 
	 
// Set s3 region if NOT within the US Standard	
	if ($s3_region != 'US') {
        $s3->set_region(AmazonS3::$s3_region);
	}
	 
// Zip directory for backing up
	$asset_archive_filename = 'backup-files-' . $date . '.tar.gz';
	$asset_archive = $archive_path . $asset_archive_filename;
	
	// Zip
	exec("(tar -cvzf $asset_archive $path_to_archive) &> /dev/null ");

	$asset_archive_size = byteConvert(filesize($asset_archive));
	
	// Add to S3 upload batch
	$s3->batch()->create_object($bucket, $asset_archive_filename, array('fileUpload' => $asset_archive ));

    $summary = '';

// Dump databases for backing up 

	foreach ($db_names as $db_name) {
		$db_archive_filename = 'backup-db-'. str_replace('_','-',$db_name) . $date . '.sql.gz';
		$db_archive = $archive_path . $db_archive_filename;

		// Dump
		exec("(mysqldump --opt --host=$db_host --user=\"$db_user\" --password=\"$db_pwd\" \"$db_name\" | gzip -9c > $db_archive) &> /dev/null ");
		$db_archive_size = byteConvert(filesize($db_archive));

		$summary .= "Database archive: $db_archive_filename ($db_archive_size)\n";
		
		// Add to S3 upload batch
		$s3->batch()->create_object($bucket, $db_archive_filename, array('fileUpload' => $db_archive ));
	}

// Give the bucket a moment to get created if it doesn't exist yet

	$exists = $s3->if_bucket_exists($bucket);
	while (!$exists)
	{
		// Not yet? Sleep for 1 second, then check again
		sleep(1);
		$exists = $s3->if_bucket_exists($bucket);
	}	

	// Upload batch to S3
	$file_upload_response = $s3->batch()->send();
	
	// Success?

	if ($file_upload_response->areOK()) {
		 $to = $notify_email;
		 $subject = "[$notify_sitename] Nightly backup successful";
		 $body = <<<BODY
The $notify_sitename backup just ran, successfully:

Asset archive: $asset_archive_filename ($asset_archive_size)
$summary
	
You can rest easy.

--
"The Server"
BODY;
		mail($to, $subject, $body);
	}

// Set expiration rules

	$response = $s3->create_object_expiration_config($bucket, array(
	    'rules' => array(
	        array(
	            'prefix' => '', // Empty prefix applies expiration rule to every file in the bucket
	            'expiration' => array(
	                'days' => $expire_after
	            )
	        )
	    )
	));
 
	if ($response->isOK())
	{
	    // Give the configuration a moment to take
	    while (!$s3->get_object_expiration_config($bucket)->isOK())
	    {
	        sleep(1);
	    }
	}

// Helper functions

	// This just helps make the file sizes in our email more human-friendly.
	function byteConvert(&$bytes){
	    $b = (int)$bytes;
	    $s = array('B', 'kB', 'MB', 'GB', 'TB');
	    if($b < 0){ return "0 ".$s[0]; }
	    $con = 1024;
	    $e = (int)(log($b,$con));
	    return number_format($b/pow($con,$e),2,'.','.').' '.$s[$e];
	}


