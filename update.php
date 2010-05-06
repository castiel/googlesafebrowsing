<?php

include 'safebrowsing.class.php';


$cfg['dbserver'] = 'localhost';
$cfg['dbuser'] = 'dbuser';
$cfg['dbpass'] = 'topsecret';
$cfg['dbname'] = 'dbname';

$class = new GoogleSafeBrowsing('YOUR_GSB_API_KEY_HERE', true, $cfg);

// only run this once if you don't have the tables necessary
//$class->FirstTimeInit();

$class->UpdateList();

/*
 * Example test:

	if ($class->IsBlacklisted($url)) {
		// do something
	}

 */

?>
