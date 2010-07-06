<?php

function backTrace($context) {
	$trace = debug_backtrace();
	
	$calls = "\nBackTrace:";
	
	for($x=2; $x < count($trace); $x++) {
		$callNo = $x-2;
		$calls .= "\n {$callNo}: {$trace[$x]["function"]} ";
		$calls .= "(line {$trace[$x]["line"]} in {$trace[$x]["file"]}";
	}
	
	//$calls .= "\nVariables in {$trace[2]["function"]} ():";
	
	/*
	foreach($context as $name => $value) {
		if (!empty($value)) {
			$val = print_r($value,true);
			$calls .= "\n {$name} is {$val}";
		} else {
			$calls .= "\n {$name} is NULL";
		}
	}
	*/
	return $calls;
}

function customHandler($number, $string, $file, $line, $context) {
	$error = "";
	
	switch($number) {
		case E_USER_ERROR:
			$error .="\nERROR on line {$line} in {$file}.\n";
			$stop = true;
			break;
		case E_WARNING:
		case E_USER_WARNING:
			$error .= "\nE_WARNING on line {$line} in {$file}.\n";
			$stop = false;
			break;
		
		case E_NOTICE:
		case E_USER_NOTICE:
			$error .= "\nE_NOTICE on line {$line} in {$file}.\n";
			$stop = false;
			break;
			
		default: 
			$error .= "\nUNHANDLED ERROR on line {$line} in {$file}\n";
			$stop = false;
	}
	
	$error .= "Error: \"{$string}\" (error #{$number}).";
	$error .= backTrace($context);
	if ( isset($_SERVER['SERVER_NAME'])) { $error .= "\nClient IP: {$_SERVER["REMOTE_ADDR"]}"; }
	
	$prepend = "\n[PHP Error " . date("YmdHis") . "] ";
	$error = preg_replace("/\n/", $prepend, $error);
	$error .= "\n++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++\n";
	error_log($error, 3, ERROR_LOG);
	if ( $stop == true ) {
		die();
	}
}

 function er($message) {
  	if (isset($GLOBALS['debug']) && $GLOBALS['debug'] === true) {
  		$db = debug_backtrace();
	  	error_log("\nIn {$db[1]['file']}::{$db[1]['function']}:\n$message\n======\n", 3, ERROR_LOG);
  	}
  }
  
  
function tep_parse_input_field_data($data, $parse) {
return strtr(trim($data), $parse);
}

function tep_output_string($string, $translate = false, $protected = false) {
if ($protected == true) {
  return htmlspecialchars($string);
} else {
  if ($translate == false) {
	return tep_parse_input_field_data($string, array('"' => '&quot;'));
  } else {
	return tep_parse_input_field_data($string, $translate);
  }
}
}

function tep_output_string_protected($string) {
return tep_output_string($string, false, true);
}

function tep_sanitize_string($string) {
$string = ereg_replace(' +', ' ', $string);

return preg_replace("/[<>]/", '_', $string);
}

function tep_htmlwrap($str, $width = 60, $break = "####", $nobreak = "") {

  // Split HTML content into an array delimited by < and >
  // The flags save the delimeters and remove empty variables
  $content = preg_split("/([<>])/", $str, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

  // Transform protected element lists into arrays
  $nobreak = explode(" ", strtolower($nobreak));

  // Variable setup
  $intag = false;
  $innbk = array();
  $drain = "";

  // List of characters it is "safe" to insert line-breaks at
  // It is not necessary to add < and > as they are automatically implied
  $lbrks = "/?!%)-}]\\\"':;&";

  // Is $str a UTF8 string?
  $utf8 = (preg_match("/^([\x09\x0A\x0D\x20-\x7E]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*$/", $str)) ? true : false;

  while (list(, $value) = each($content)) {
    switch ($value) {

      // If a < is encountered, set the "in-tag" flag
      case "<": $intag = true; break;

      // If a > is encountered, remove the flag
      case ">": $intag = false; break;

      default:

        // If we are currently within a tag...
        if ($intag) {

          // Create a lowercase copy of this tag's contents
          $lvalue = strtolower($value);

          // If the first character is not a / then this is an opening tag
          if ($lvalue{0} != "/") {

            // Collect the tag name   
            preg_match("/^(\w*?)(\s|$)/", $lvalue, $t);

            // If this is a protected element, activate the associated protection flag
            if (in_array($t[1], $nobreak)) array_unshift($innbk, $t[1]);

          // Otherwise this is a closing tag
          } else {

            // If this is a closing tag for a protected element, unset the flag
            if (in_array(substr($lvalue, 1), $nobreak)) {
              reset($innbk);
              while (list($key, $tag) = each($innbk)) {
                if (substr($lvalue, 1) == $tag) {
                  unset($innbk[$key]);
                  break;
                }
              }
              $innbk = array_values($innbk);
            }
          }

        // Else if we're outside any tags...
        } else if ($value) {

          // If unprotected...
          if (!count($innbk)) {

            // Use the ACK (006) ASCII symbol to replace all HTML entities temporarily
            $value = str_replace("\x06", "", $value);
            preg_match_all("/&([a-z\d]{2,7}|#\d{2,5});/i", $value, $ents);
            $value = preg_replace("/&([a-z\d]{2,7}|#\d{2,5});/i", "\x06", $value);

            // Enter the line-break loop
            do {
              $store = $value;

              // Find the first stretch of characters over the $width limit
              if (preg_match("/^(.*?\s)?(\S{".$width."})(?!(".preg_quote($break, "/")."|\s))(.*)$/s".(($utf8) ? "" : ""), $value, $match)) {

                if (strlen($match[2])) {
                  // Determine the last "safe line-break" character within this match
                  for ($x = 0, $ledge = 0; $x < strlen($lbrks); $x++) $ledge = max($ledge, strrpos($match[2], $lbrks{$x}));
                  if (!$ledge) $ledge = strlen($match[2]) - 1;

                  // Insert the modified string
                  $value = $match[1].substr($match[2], 0, $ledge + 1).$break.substr($match[2], $ledge + 1).$match[4];
                }
              }

            // Loop while overlimit strings are still being found
            } while ($store != $value);

            // Put captured HTML entities back into the string
            foreach ($ents[0] as $ent) $value = preg_replace("/\x06/", $ent, $value, 1);
          }
        }
    }

    // Send the modified segment down the drain
    $drain .= $value;
  }

  // Return contents of the drain
  return $drain;
} 
?>