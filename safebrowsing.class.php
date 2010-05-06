<?php

// TODO Add some comments
// TODO Write some unittests for this
require_once('db.class.php');

class GoogleSafeBrowsing 
{
	private $apiKey = null;
	private $secureConnection = false;
	private $googleListUrl =   "http://sb.google.com/safebrowsing/update?client=api&apikey=%APIKEY%&version=%TYPE%:%FROM%:%TO%";
	private $secureUrl = "https://sb-ssl.google.com/safebrowsing/getkey?client=api"; 
	private $clientKey = null;
	private $wrappedKey = null;
	private $secureHashSeperator = ':coolgoog:';
	private $db = null;
	
	public function __construct($apiKey, $secureConnection = False, $cfg = false) 
	{
		$this->apiKey = $apiKey;
		$this->secureConnection = $secureConnection;
 		if (is_array($cfg)) {
			$this->db = new DB($cfg);
 		} else if (isset($cfg)) {
 			$this->db = $cfg;
 		}
	}
	
	private function _initiatePrivateSession() {
		// Retreive the data        
		if (($data = @file_get_contents($this->secureUrl)) == false) {
		return false;
		}
		
		// Split it, baby!        
		if (($data = explode("\n", $data)) == false) {
		return false;
		}
		
		$matches = array();
		foreach ($data as $row) {  
		
		// We irgnore empty lines         
		if ($row == '') {
			continue;
		}
		
		// Check (and split) the information we got
		if (!preg_match('/([^:]+):([^:]+):([^:]+)/i', $row, $matches)) {
			return false;
		}
		
		// Incomplete data detected!
		if ($matches[2] != strlen($matches[3])) {
			return false;
		}
		
		// Match to the right variables
		if ($matches[1] == 'clientkey') {
			$this->clientKey = $matches[3];
		}
		if ($matches[1] == 'wrappedkey') {
			$this->wrappedKey = $matches[3];
		}
		}
		
		return true;
	}
	
	private function _canonicalizeIp($ip) 
	{
		// TODO Implement IP canonicalization
		
		return $ip;
	}
	
	private function _canonicalizePath($path) 
	{
		// Remove some anchors from the url
		$path = preg_replace('/#.*$/i', '', $path);
		
		// Need trailing slash
		if (strpos($path, '?') == 0 and substr($path, -1) != '/' and preg_match('/\.[a-z0-9]{1,4}$/i', $path) == 0) {
		$path .= '/';
		}
		
		// Replacing "/./" with "/"
		$count = 0;        
		$path = str_replace('/./', '/', $path, $count);
		while ($count > 0) {
		$path = str_replace('/./', '/', $path, $count);
		}
		
		// Removing "/../" along with the preceding path component
		$pos = strpos($path, '/../');
		while ($pos > 0) {
		$startPos = $pos - 1;
		
		while ($startPos >= 0) {
			if (substr($path, $startPos, 1) == '/') {
			break;    
			} 
			
			$startPos -= 1;
		}
		
		$path = substr($path, 0, $startPos + 1) . substr($path, ($pos + 4));
		$pos = strpos($path, '/../');
		}
		
		// Fully escape
		while (strpos($path, '%') > 0 ) {
		$path = urldecode($path);
		}
		
		// Re-Escape once
		$path = urlencode($path);
		$path = str_replace('%2F', '/', $path);
		$path = str_replace('%3F', '?', $path);
		$path = str_replace('%3D', '=', $path);
		$path = str_replace('%7E', '~', $path);
		
		return $path;
	}
	
	private function _canonicalizeHost($host) 
	{
		// collapse multiple dots
		$count = 0;        
		$host = str_replace('..', '.', $host, $count);
		while ($count > 0) {
		$host = str_replace('..', '.', $host, $count);
		}
		
		// strip leading and trailing dots
		if (substr($host, 0, 1) == '.') {
		$host = substr($host, 1);
		}
		if (substr($host, strlen($host) - 1, 1) == '.') {
		$host = substr($host, 0, -1);
		}
		
		return $host;
	}
	
	public function canonicalize($url) 
	{
		// Remove the protocol-part!
		$url = preg_replace('#^http://#i', '', $url);
		
		// Remove the trailing slash
		if (substr($url, strlen($url) - 1, 1) == '/') {
		$url = substr($url, 0, -1);
		}
	
		// Split the url into 2 parts to do host-specific stuff
		$matches = array();
		$url = preg_match('#^([^/]+)(.*)#i', $url, $matches);
		
		// Lowercase the host
		$host = strtolower($matches[1]);
		$host = $this->_canonicalizeIp($host);
		$host = $this->_canonicalizeHost($host);
		$path = $this->_canonicalizePath($matches[2]);
		
		// Merge the two url-parts
		$url = urlencode($host) . $path;
		
		// Replacing "//" with "/" in front of "?"
		$count = 0;
		$subUrl = substr($url, 0, strpos($url, '?'));
			
		$subUrl = str_replace('//', '/', $subUrl, $count);
		while ($count > 0) {
		$subUrl = str_replace('//', '/', $subUrl, $count);
		}
		
		$url = $subUrl . substr($url, strpos($url, '?'));        
		
		return $url;
	}
	public function IsBlacklisted($url) {
		if (!$this->db) throw new Exception('no database initialized');
		
		$lookups = $this->lookupsFor($url);
		//foreach($lookups as $key => $l) {
			$list = $this->db->ExpandListForInQuery($lookups);
			$ret = $this->db->ExecSQLList('SELECT id,ghash FROM gsb_list WHERE ghash '.$list);
			foreach($ret as $r) {
				if ($r['ghash']) return true;
			}
		return false;
	}
	public function lookupsFor($url, $md5 = true)
	{
		// not really the best way to clean and maybe not 100% accurate, but good for now :)
		
		// remove http prefix
		$url = preg_replace('#^http(s)?://#i', '', strtolower($url));
		
		// check for any encodings which need to be decoded
		while (strpos($url,'%')) {
			$url = urldecode($url);
		}
		// remove ../ stuff
		$url = preg_replace('/\/[^\/]+\/\.{2,}\//', '/', $url);
		// remove // stuff
		$url = preg_replace('/\/{2,}/', '/', $url);
		$url_pieces = parse_url('http://'.$url);
		
		$host = $url_pieces['host'];
		if (isset($url_pieces['path'])) {
			$path = $this->_canonicalizePath($url_pieces['path']);
		} else {
			$path = '/';
		}
		$query = (isset($url_pieces['query'])?$url_pieces['query']:false);
		
		// Split the hostname into components so we can do y.z from x.y.z lookups
		$hostComponents = array();
		$hostparts = array_reverse(explode(".",$host));
		$hostComponents[] = array_shift($hostparts);
		$hostComponents[0] = array_shift($hostparts).'.'.$hostComponents[0];
		foreach($hostparts as $hp) {
			$hostComponents[] = $hp.'.'.$hostComponents[count($hostComponents)-1];
		}
	
		// Split the path into components
		$pathComponents = array();
		if (!empty($path) && $path != '/') {
			$pathComponents = explode('/', $path);
			
			// remove heading slash entry
			unset($pathComponents[0]);
			
			// remove the trailing slash entry
			if ($pathComponents[count($pathComponents)] == '') {
				unset($pathComponents[count($pathComponents)]);
			}
		}
		
		// Build the hostname + path mixes we need to lookup
		$lookups = array();
		foreach ($hostComponents as $host) {
			$lookups[] = $host.'/';
			if (!empty($path)) {
				//$lookups[] = $host . $path;
				if (count($pathComponents) > 0) {
					foreach ($pathComponents as $component) {
						$tempString = '';
						
						foreach ($pathComponents as $prefix) {
							if ($prefix == $component) {
								break;
							}
							$tempString .= '/' . $prefix;
						}
						if (isset($url_pieces['path']) && $tempString . '/' . $component == $url_pieces['path']) {
							$lookups[] = $host . $tempString . '/' . $component;
						} else {
							$lookups[] = $host . $tempString . '/' . $component .'/';
						}
					}
				}
			}
			if (!empty($query)) {
				$lookups[] = $host . $path .'?' .$query;
			}
		}
		
		// Create the md5-sums
		if ($md5) {
			$temp = array();
			foreach ($lookups as $entry) {
				$temp[$entry] = md5($entry);
			}
			$lookups = $temp;
		}
		return $lookups;
	}
	
	private function _fetchData($url, $baseVersion, $type) 
	{
		// Initiate the private session
		if ($this->secureConnection) {
			if (!$this->_initiatePrivateSession()) {
				return false;
			}
		}
		
		// Generate a valid URL to fetch the data
		$url = str_replace('%APIKEY%', $this->apiKey, $url);
		$url = str_replace('%TYPE%', $type, $url);
		
		if (!preg_match('/(\d)(\.(\d+))?/',$baseVersion,$version)) {
			return false;
		}
		if ($version[3] == '') $version[3] = '-1';
		$url = str_replace('%FROM%', $version[1], $url);
		$url = str_replace('%TO%', $version[3], $url);
	
		if ($this->secureConnection) {
		$url = "{$url}&wrkey={$this->wrappedKey}";
		}
		
		//echo ">> $url\n";
		//return false;
		
		// Retreive the data
		if (($rawdata = @file_get_contents($url)) == false) {
			return false;
		}
		
		// Split it, baby!
		if (($data = explode("\t", $rawdata)) == false) {
			return false;
		}
		
		// Split the header
		$matches = array();
		if (!preg_match('/\[goog-(black|malware)-hash ([0-9]+\.[0-9]+) ?(update|)\](\[mac=([^\]]*)\]|)/i', $data[0], $matches)) {
			return false;
		}
	
		if ($this->secureConnection) {
		// TODO Validate received data with received mac!
		
		/*print_r($matches[6]);
		echo "\n";
		$data = md5("{$this->clientKey}{$this->secureHashSeperator}{$rawdata}{$this->secureHashSeperator}{$this->clientKey}");
		print_r(base64_encode(md5($data, true)));
		echo "\n";
		return;*/
		}
		
		// Structure (+ some data) of the stuff we return
		$return = array();        
		$return['version'] = $matches[2];        
		$return['update'] = ($matches[3] == 'update') ? true : false;
		$return['add'] = array();
		$return['drop'] = array();
		
		// Sort the hashes into the return-array
		foreach ($data as $index => $row) {
			// We ignore the headerline 
			if ($index == 0) {
				continue;
			}
			
			if (substr($row, 1, 1) == '+') {
				$return['add'][] = substr($row, 2);
			}
			if (substr($row, 1, 1) == '-') {
				$return['drop'][] = substr($row, 2);
			}
		}
		
		// We are done! 
		return $return;
	}
	public function FirstTimeInit() {
		if (!$this->db) throw new Exception('no database initialized');
		$this->db->ExecSQL("
CREATE TABLE `gsb_status` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`version` VARCHAR( 30 ) NOT NULL ,
`gtype` ENUM( 'goog-malware-hash', 'goog-black-hash' ) NOT NULL ,
`last_update` DATETIME NOT NULL
) ENGINE = InnoDB ");
		$this->db->ExecSQL("CREATE TABLE `gsb_list` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`gtype` ENUM( 'goog-malware-hash', 'goog-black-hash' ) NOT NULL ,
`ghash` VARCHAR( 32 ) NOT NULL ,
`chash` INT NOT NULL ,
`last_update` DATETIME NOT NULL,
INDEX ( `gtype` , `ghash` , `chash` , `last_update`)
) ENGINE = InnoDB ");
		$this->db->ExecSQL('INSERT INTO gsb_status (version,last_update,gtype) VALUES(1,NOW()-INTERVAL 1 DAY,"goog-black-hash")');
		$this->db->ExecSQL('INSERT INTO gsb_status (version,last_update,gtype) VALUES(1,NOW()-INTERVAL 1 DAY,"goog-malware-hash")');
	}
	public function UpdateList() {
		if (!$this->db) throw new Exception('no database initialized');
		$this->db->ExecSQL('LOCK TABLES gsb_status WRITE');
		$status = $this->db->ExecSQLList('SELECT id,version,last_update,gtype FROM gsb_status WHERE last_update < NOW() - INTERVAL 20 MINUTE LIMIT 2');
		foreach ($status as $s) {
			
			$update = $this->_fetchData($this->googleListUrl, $s['version'],$s['gtype']);
			if (!is_array($update)) {
				$this->db->ExecSQL('UPDATE gsb_status SET last_update = NOW() WHERE id = '.$s['id']);
				continue;
			}
			$this->db->ExecSQL('START TRANSACTION');
			if (!$update['update']) {
				$this->db->ExecSQL('DELETE FROM gsb_list WHERE gtype ="'.$s['gtype'].'"');
			}
			foreach($update['add'] as $a) {
				$this->db->ExecSQL('INSERT INTO gsb_list (gtype,ghash,chash,last_update) VALUES("'.$s['gtype'].'","'.$a.'","'.crc32($a).'",NOW())');
			}
			foreach($update['drop'] as $a) {
				$this->db->ExecSQL('DELETE FROM gsb_list WHERE ghash = "'.$a.'"');
			}
			$this->db->ExecSQL('UPDATE gsb_status SET last_update = NOW(), version = "'.$update['version'].'" WHERE id = '.$s['id']);
			$this->db->ExecSQL('COMMIT');
		}
		$this->db->ExecSQL('UNLOCK TABLES');
	}
	
	public function getBlackList($baseVersion = 1) 
	{
		return $this->_fetchData($this->googleListUrl, $baseVersion,'goog-black-hash');
	}
	
	public function getMalwareList($baseVersion = 1) 
	{
		return $this->_fetchData($this->googleListUrl, $baseVersion,'goog-malware-hash');
	}
}