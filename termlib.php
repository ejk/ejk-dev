<?php
/**
 * A Libray enabling terminal commands to III servers
 * termlib.php
 * @author Eric J. Klooster
 * @package TermLib
 */

/**
 * TermLib Class allows terminal commands to the III server
 */
class TermLib {
  private $cli;
  private $host;
  private $user;
  private $pass;
  private $init;
  private $init_pass;
  private $resource;
  private $stdio;

  private function login() {
    if (!($this->resource = @ssh2_connect($this->host))) {
      if ($this->cli) echo "ssh2_connect(" . $this->host . ") FAIL\n";
      return 'ERR';
    } else {
      if (!@ssh2_auth_password($this->resource, $this->user, $this->pass)) {
        if ($this->cli) echo "ssh2_auth_password(" . $this->user . "," . $this->pass . ") FAIL\n";
        return 'ERR';
      } else {
        if (!($this->stdio = @ssh2_shell($this->resource, "xterm"))) {
          if ($this->cli) echo "ssh2_shell() FAIL\n";
          return 'ERR';
        }
      }
    }
    return 0;
  }

  private function transmit($command, $expect = NULL) {
    fwrite($this->stdio, $command);
    usleep(80000);

    while($line = fgets($this->stdio)) {
      $cmdresult .= trim($line);
    }

    if ($expect) {
      $i = 250000;
      while ($i <= 2000000 && (strpos($cmdresult, $expect) === FALSE)) {
        //echo "USLEEP: " . $i . "\n";
        usleep($i);
        while($line = fgets($this->stdio)) {
          $cmdresult .= trim($line);
        }
        $i*=2; // backoff wait time
      }
      if ($i > 2000000 && (strpos($cmdresult, $expect) === FALSE)) {
        $retarr['error'] = 1;
      } else {
        $retarr['error'] = 0;
      }
    } else {
      $retarr['error'] = 0;
    }
    $retarr['unfiltered'] = $cmdresult;
    $retarr['result'] = self::iii_filter($cmdresult);
    return $retarr;
  }

  private function disconnect() {
    fclose($this->stdio);
  }

  private function iii_filter($string) {
    $string = preg_replace('%\x1B\[(.*?);(.*?)H%s', "\n", $string);
    $string = preg_replace('%\x1B%s', '', $string);
    return $string;
  }

  public function __construct($hostname, $username, $password, $initials, $initials_pass) {
    $this->cli = (php_sapi_name() == "cli" ? TRUE : FALSE);
    $this->host = $hostname;
    $this->user = $username;
    $this->pass = $password;
    $this->init = $initials;
    $this->init_pass = $initials_pass;
    if ($this->cli) echo "TermLib Constructor\n";
  }
  
  /**
  * Retrieve MARC field information for a bib record
  */
  public function get_bib_info($bnum) {
    $bib_record = array();
    $bnum = ".b" . substr(preg_replace('/[^0-9]/', '', $bnum), 0, 7) . "a";
    if ($this->cli) echo "Grabbing Bib Info for $bnum\n";
    
    if ($this->login()) {
      if ($this->cli) echo "SSH LOGIN ERROR\n";
      return "SSH LOGIN ERROR";
    }
    
    $trans_arr = array(
      array('input' => '', 'expect' => 'MAIN MENU'),
      array('input' => 'd', 'expect' => 'CATALOG DATABASE'),
      array('input' => 'u', 'expect' => 'key your initials'),
      array('input' => $this->init . PHP_EOL, 'expect' => 'key your password'),
      array('input' => $this->init_pass . PHP_EOL, 'expect' => 'BIBLIOGRAPHIC'),
      array('input' => 'b', 'expect' => 'want to update'),
      array('input' => $bnum . PHP_EOL, 'expect' => 'Key its number'),
    );
    
    foreach ($trans_arr as $cmd) {
      $trans = $this->transmit($cmd['input'], $cmd['expect']);
      if ($this->cli) echo $cmd['input'] . ":" . $cmd['expect'] . "\n";
      if ($trans['error']) {
        $status = "ERROR";
        $info = $cmd['input'] . " EXPECTING " . $cmd['expect'];
      }
    }
  
    if ($status != "ERROR") {
      // determine max code of editable fields
      preg_match("/Choose one \(1-([0-9]{1,3})/", $trans['unfiltered'], $max_code_match);
      $max_code = $max_code_match[1];
      while(!$last_code_found) {
        preg_match_all("/([0-9]{2}) ([a-zA-Z0-9]{3,9}) (.*)/", $trans['result'], $matches);
        if ($matches[0]) {
          foreach ($matches[1] as $index => $code) {
            $marc = $matches[2][$index];
            $value = $matches[3][$index];
            $marc .= trim(substr($value, 0, 4));
            $value = trim(substr($value, 4));
            
            if (intval($marc)) {
              $bib_record[$code] = array('marc' => $marc, 'value' => $value);
            }
            
            if ($code == $max_code) {
              $last_code_found = TRUE;
            }
          }
        }
        $trans = $this->transmit('m');
      }
    }
    $this->disconnect();
    return $bib_record;
  }

  /**
   * Update a bib record with the given text
   * You MUST enter the field code number as returned by get_bib_info()
   */
  public function set_bib_info($bnum, $code, $marc, $value) {
    $bib_record = array();
    $status = "SUCCESS";
    $bnum = ".b" . substr(preg_replace('/[^0-9]/', '', $bnum), 0, 7) . "a";
    if ($this->cli) echo "UPDATING BIB $bnum\n";
    
    if ($this->login()) {
      if ($this->cli) echo "SSH LOGIN ERROR\n";
      return "SSH LOGIN ERROR";
    }
    
    $trans_arr = array(
      array('input' => '', 'expect' => 'MAIN MENU'),
      array('input' => 'd', 'expect' => 'CATALOG DATABASE'),
      array('input' => 'u', 'expect' => 'key your initials'),
      array('input' => $this->init . PHP_EOL, 'expect' => 'key your password'),
      array('input' => $this->init_pass . PHP_EOL, 'expect' => 'BIBLIOGRAPHIC'),
      array('input' => 'b', 'expect' => 'want to update'),
      array('input' => $bnum . PHP_EOL, 'expect' => 'Key its number'),
      array('input' => $code, 'expect' => 'MARC'),
      array('input' => $marc . PHP_EOL, 'expect' => 'Key new data'),
      array('input' => $value . PHP_EOL, 'expect' => 'Key its number'),
      array('input' => 'q', 'expect' => 'MAKE changes'),
      array('input' => 'm', 'expect' => 'BIBLIOGRAPHIC'),
    );
    
    foreach ($trans_arr as $cmd) {
      $trans = $this->transmit($cmd['input'], $cmd['expect']);
      if ($this->cli) echo $cmd['input'] . ":" . $cmd['expect'] . "\n";
      if ($trans['error']) {
        $status = "ERROR";
        $info = $cmd['input'] . " EXPECTING " . $cmd['expect'];
      }
    }
    $this->disconnect();
    return array('status' => $status, 'info' => $info, 'trans' => $trans);
  }
  
  /**
   * update_bib_info searches the Bib record for the old field text
   * If found, it replaces it with new text
   * returns a Error if old text is not found in the Bib record
   */
  public function update_bib_info($bnum, $old_text, $new_text) {
    $bib = $this->get_bib_info($bnum);
    foreach ($bib as $code => $field) {
      if ($field['value'] == $old_text) {
        // found the field to update
        $this->set_bib_info($bnum, $code, $field['marc'], $new_text);
        if ($this->cli) echo "UPDATED Bib:" . $bnum . " field code:" . $code . " marc:" . $field['marc'] . " with:" . $new_text . "\n";
        $found = TRUE;
        break;
      }
    }
    if (!$found) {
      if ($this->cli) echo "FIELD TEXT NOT FOUND:" . $old_text . " in Bib: " . $bnum . "\n";
      return "ERROR: FIELD TEXT NOT FOUND";
    }
  }
  
} // End of class TermLib

?>