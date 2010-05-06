<?php

class DB
{
	var $conn;
	var $master;
	var $cfg;
	function DB($cfg)
	{
		// example cfg
/*		$cfg['dbserver'] = 'localhost';
		$cfg['dbuser'] = 'dbuser';
		$cfg['dbpass'] = 'topsecret';
		$cfg['dbname'] = 'mydb';*/
		$this->cfg = $cfg;
		$this->_startdb();
	}
	function _startdb()
	{
		$this->conn = mysql_connect($this->cfg['dbserver'],$this->cfg['dbuser'],$this->cfg['dbpass'],true);
		if ($this->conn) {
			mysql_select_db($this->cfg['dbname']);
		} else {
			return $this->ShowError();
		}
		if (mysql_error($this->conn)) {
			return $this->ShowError();
		}
	}
	function _closedb()
	{
		mysql_close($this->conn);
	}
	function ExpandListForInQuery($list,$key = "")
	{
		$out = '';
		if (is_array($list)) {
			foreach ($list as $i) {
				if ($key) {
					$out .= ($out?",":"IN (")."'".mysql_real_escape_string($i[$key])."'";
				} else {
					$out .= ($out?",":"IN (")."'".mysql_real_escape_string($i)."'";
				}
			}
			$out .= ")";
		} else if ($list) {
			$out = "IN('".mysql_real_escape_string($list)."')";
		} else {
			# huh?? die
			$this->backtrace("Empty array/string in ExpandListForInQuery()");
		}
		return $out;
	}
	function ExecSQLRet($query,$return = "")
	{
		$result = mysql_query($query, $this->conn);
		if (mysql_error($this->conn)) {
			return $this->ShowError($query);
		}
		if (!$result) return;
		if ($row = mysql_fetch_assoc($result))
		{
			if ($return) {
				return $row[$return];
			} else {
				return $row;
			}
		}
		return;
	}
	function insert_id()
	{
		return mysql_insert_id($this->conn);
	}
	function ExecSQL($query)
	{
		$result = mysql_query($query, $this->conn);
		if (mysql_error($this->conn)) {
			return $this->ShowError($query);
		}
		return $result;
	}
	function ExecSQLArray($query, $phs = array()) {
		foreach ($phs as $ph) {
			$ph = "'" . mysql_real_escape_string($ph) . "'";
			$query = substr_replace( $query, $ph, strpos($query, '?'), 1 );
		}
		return $this->ExecSQL($query);
	}
	function ShowError($query = "")
	{
		if ($query) {
			print "Query: [$query] <br>";
		}
		print "Mysql Error: [".mysql_error($this->conn)."] <br>";
		print $this->backtrace();
		die();
		return;
	}
	function ExecSQLList($query)
	{
		$results = array();
		$result = mysql_query($query, $this->conn);
		if (mysql_error($this->conn)) {
			return $this->ShowError($query);
		}
		if (!$result) return;
		while ($row = mysql_fetch_assoc($result)) {
			array_push($results, $row);
		}
		return $results;
	}
	function num_rows($db_query) {
		return mysql_num_rows($db_query);
	}
	function backtrace($msg = "")
	{
		if ($msg) $output = $msg;
		$output = "<div style='text-align: left; font-family: monospace;'>\n";
		$output .= "<b>Backtrace:</b><br />\n";
		$backtrace = debug_backtrace();
		# hide the backtrace and SQL error call
		#array_shift($backtrace);
		foreach ($backtrace as $bt) {
			$args = '';
			foreach ($bt['args'] as $a) {
				if (!empty($args)) {
				$args .= ', ';
				}
				switch (gettype($a)) {
					case 'integer':
					case 'double':
						$args .= $a;
						break;
					case 'string':
						$a = htmlspecialchars(substr($a, 0, 64)).((strlen($a) > 64) ? '...' : '');
						$args .= "\"$a\"";
						break;
					case 'array':
						$args .= 'Array('.count($a).')';
						break;
					case 'object':
						$args .= 'Object('.get_class($a).')';
						break;
					case 'resource':
						$args .= 'Resource('.strstr($a, '#').')';
						break;
					case 'boolean':
						$args .= $a ? 'True' : 'False';
						break;
					case 'NULL':
						$args .= 'Null';
						break;
					default:
						$args .= 'Unknown';
				}
			}
			$output .= "<br />\n";
			$output .= "<b>file:</b> {$bt['file']}:{$bt['line']}<br />\n";
			$output .= "<b>call:</b> {$bt['class']}{$bt['type']}{$bt['function']}($args)<br />\n";
		}
		$output .= "</div>\n";
		if ($msg) {
			print $output;
			die();
		}
		return $output;
	}
}
?>