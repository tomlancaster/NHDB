<?php
  /*
	$Id: database.php,v 1.21 2003/06/09 21:21:59 hpdl Exp $

	osCommerce, Open Source E-Commerce Solutions
	http://www.oscommerce.com

	Copyright (c) 2003 osCommerce

	Released under the GNU General Public License
  */

function tep_db_connect($server = DB_SERVER, $username = DB_SERVER_USERNAME, $password = DB_SERVER_PASSWORD, $database = DB_DATABASE, $link = 'db_link') {
    global $$link;
    if (USE_PCONNECT == 'true') {
		$$link = mysqli_pconnect($server, $username, $password);
    } else {
		$$link = mysqli_connect("p:localhost", $username, $password, $database);
    }

    if ($$link) {
		mysqli_select_db($$link, $database);
		mysqli_query($$link, "SET NAMES 'utf8'") or die('SET NAMES failed');
		//mysqli_query($$link, "SET time_zone = '" . TIME_ZONE . "'");
		mysqli_query($$link , "set sql_mode = ''");
	}
    return $$link;
  }

function tep_db_close($link = 'db_link') {
    global $$link;

    return mysqli_close($$link);
}

function tep_db_error($query, $errno, $error) { 
    throw new TNHException($errno . ' - ' . $error . "\n" . $query);
}

function tep_db_query($query, $link = 'db_link') {
    global $$link;

    if (defined('STORE_DB_TRANSACTIONS') && (STORE_DB_TRANSACTIONS == 'true')) {
		error_log('QUERY ' . $query . "\n", 3, STORE_PAGE_PARSE_TIME_LOG);
    }

    $result = mysqli_query($$link, $query) or tep_db_error($query, mysqli_errno($$link), mysqli_error($$link));

    if (defined('STORE_DB_TRANSACTIONS') && (STORE_DB_TRANSACTIONS == 'true')) {
		$result_error = mysqli_error($$link);
		error_log('RESULT ' . $result . ' ' . $result_error . "\n", 3, STORE_PAGE_PARSE_TIME_LOG);
    }

    return $result;
}

function tep_db_perform($table, $data, $action = 'insert', $parameters = '', $link = 'db_link') {
    reset($data);
    if ($action == 'insert') {
		$query = 'insert into ' . $table . ' (';
		while (list($columns, ) = each($data)) {
			$query .= $columns . ', ';
		}
		$query = substr($query, 0, -2) . ') values (';
		reset($data);
		while (list(, $value) = each($data)) {
			if (stripos($value,'convert_tz')) {
				$query .= $value . ', ';
				continue;
			}
			switch ((string)$value) {
			case 'now()':
				$query .= 'now(), ';
				break;
			case 'null':
				$query .= 'null, ';
				break;
			default:
				$query .= '\'' . tep_db_input($value) . '\', ';
				break;
			}
		}
		$query = substr($query, 0, -2) . ')';
    } elseif ($action == 'update') {
		$query = 'update ' . $table . ' set ';
		while (list($columns, $value) = each($data)) {
			switch ((string)$value) {
			case 'now()':
				$query .= $columns . ' = now(), ';
				break;
			case 'null':
				$query .= $columns .= ' = null, ';
				break;
			default:
				$query .= $columns . ' = \'' . tep_db_input($value) . '\', ';
				break;
			}
		}
		$query = substr($query, 0, -2) . ' where ' . $parameters;
    }
	//error_log("query: $query\n", 3, ERROR_LOG);
    return tep_db_query($query);
}

function tep_select_value($sql, $link = 'db_link') {
    global $$link;
    $res = mysqli_query($$link, $sql) or tep_db_error($sql, mysqli_errno($$link), mysqli_error($$link));
    if ($row = mysqli_fetch_row($res)) {
		return $row[0];
    } else {
		return null;
    }
}

function tep_db_escape_string($string, $link = 'db_link') {
	global $$link;
	return mysqli_real_escape_string($$link, $string);
}

function tep_esc($string) {
	$escaped =  tep_db_escape_string($string);
	return $escaped;
} 

function tep_db_fetch_array($db_query) {
    return mysqli_fetch_array($db_query, MYSQLI_ASSOC);
}

function tep_db_fetch_object($db_query) {
	return mysqli_fetch_object($db_query, MYSQLI_ASSOC);
}

function tep_db_fetch_all($db_query) {
	return mysqli_fetch_all($db_query, MYSQLI_ASSOC);
}

function tep_db_num_rows($db_query) {
    return mysqli_num_rows($db_query);
}

function tep_db_data_seek($db_query, $row_number) {
    return mysqli_data_seek($db_query, $row_number);
}

function tep_db_insert_id($foo ) {
	global $db_link;
    return mysqli_insert_id($db_link);
}

function tep_db_free_result($db_query) {
    return mysqli_free_result($db_query);
}

function tep_db_fetch_fields($db_query) {
    return mysqli_fetch_fields($db_query);
}

function tep_db_output($string) {
    return htmlspecialchars($string);
}

function tep_db_input($string) {
    return addslashes($string);
}

function tep_db_prepare($string) {
  global $$link;
  return mysqli_prepare($$link, $string);
}


function tep_db_prepare_input($string) {
    if (is_string($string)) {
		return trim(tep_sanitize_string(stripslashes($string)));
    } elseif (is_array($string)) {
		reset($string);
		while (list($key, $value) = each($string)) {
			$string[$key] = tep_db_prepare_input($value);
		}
		return $string;
    } else {
		return $string;
    }
}
// This funstion validates a plain text password with an
// encrpyted password
function tep_validate_password($plain, $encrypted) {
    if (tep_not_null($plain) && tep_not_null($encrypted)) {
		// split apart the hash / salt
		$stack = explode(':', $encrypted);

		if (sizeof($stack) != 2) return false;

		if (md5($stack[1] . $plain) == $stack[0]) {
			return true;
		}
    }

    return false;
}

function tep_db_autocommit($on = true, $link = 'db_link') {
	global $$link;
	return mysqli_autocommit($$link, $on);
}

function tep_db_commit($link = 'db_link') {
	global $$link;
	return mysqli_commit($$link);
}

////
// This function makes a new password from a plaintext password. 
function tep_encrypt_password($plain) {
    $password = '';

    for ($i=0; $i<10; $i++) {
		$password .= tep_rand();
    }

    $salt = substr(md5($password), 0, 9);

    $password = md5($salt . $plain) . ':' . $salt;

    return $password;
}
// Return a random value
function tep_rand($min = null, $max = null) {
    if (isset($min) && isset($max)) {
		if ($min >= $max) {
			return $min;
		} else {
			return mt_rand($min, $max);
		}
    } else {
		return mt_rand();
    }
}
function tep_not_null($value) {
    if (is_array($value)) {
		if (sizeof($value) > 0) {
			return true;
		} else {
			return false;
		}
    } else {
		if (($value != '') && (strtolower($value) != 'null') && (strlen(trim($value)) > 0)) {
			return true;
		} else {
			return false;
		}
    }
}
?>
