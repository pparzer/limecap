<?php

// Set the namespace defined in config file
namespace LimeCapNamespace\LimeCap;

// on error it is useful to temporarily disable cron updates,
// this is for development and debugging purposes only
define("DISABLE_CRON_METHOD", false);

// Log debugging info to debug.log
define("DEBUG", true);

// Constants for survey state
define("STATE_NEW", "1");
define("STATE_ACTIVE", "2");
define("STATE_SUBMITTED", "3");
define("STATE_EXPIRED", "4");

// Constant for form complete
define("FORM_COMPLETE", "2");

// Default and minimum value for number of code digits
define("CODE_DIGITS", "5");
define("MIN_DIGITS", "3");

// default interval between cron jobs in seconds
define("CRON_DEFAULT", 60);

include_once 'vendor/autoload.php';

// Declare module class, which must extend AbstractExternalModule
class LimeCap extends \ExternalModules\AbstractExternalModule {

  private $pid;
  private $sessionKey;
  private $jsonClient;
  private $error = false;

  function __destruct() {
    $this->close_limesurvey_session();
  }


  // Save record hook:
  // For new instruments with the code field generate a code.
  // For new Limesurvey instruments activate code in Limesurvey.
  
  function redcap_save_record ($pid, $record, $instrument, $event, $group,
                               $survey_hash, $response, $instance) {
    $this->pid = $pid;
    $this->debug("redcap_save_record: ".$instrument.".".$record.".".$event.".".$instance);

    // Check if this is the instrument with the code field.
    // The name of the code field is stored in the project settings.
    
    $code_field = $this->getProjectSetting("code-field");
    $fields = \REDCap::getFieldNames($instrument);
    $idx = array_search($code_field, $fields);

    if (is_int($idx)) {
      // If this form includes the code field we call get_code().
      try {
        // get_code() creates a new code if code field is empty.
        $code = $this->get_code($record);
      } catch (\Exception $e) {
        $this->error("redcap_save_record: ".$e->getMessage());
      }
    }

    // Check if instrument is a Limesurvey form. The names of the
    // Limesurvey forms are stored in the project settings.
    
    $forms = $this->getProjectSetting("form-name");
    $idx = array_search($instrument, $forms);

    if (is_int($idx)) {

      // For Limesurvey forms the state is checked to decide what to do.
      // Every Limesurvey form must have a field with the name "<form>_state".
      // This field contains the state of the form in Limesurvey. The state
      // in REDCap should correspond to the state of the participant in the
      // connected Limesurvey form.
      // NEW       -> Limesurvey participant does not yet exist.
      // ACTIVE    -> Limesurvey particpant is active.
      // SUBMITTED -> Participant has completed and submitted the Limesurvey form.
      // EXPIRED   -> Limesurvey particpant exists, but has expired.
      
      $field = $instrument."_state";
      $state = $this->get_value($field, $record, $event, $instance);
      
      if ($state == STATE_NEW) {
        // Activate the code in Limesurvey.
        try {
          $this->activate_code($instrument, $record, $event, $instance);
        } catch (\Exception $e) {
          $this->error("redcap_save_record: ".$e->getMessage());
        }
      } else if ($state == STATE_ACTIVE) {
        // Verify if Limesurvey form is still active.
        try {
          $this->update_valid_active($instrument, $record, $event, $instance);
        } catch (\Exception $e) {
          $this->error("redcap_save_record: ".$e->getMessage());
        }
      } else if ($state == STATE_EXPIRED) {
        // Verify if Limesurvey form is still expired.
        try {
          $this->update_valid_expired($instrument, $record, $event, $instance);
        } catch (\Exception $e) {
          $this->error("redcap_save_record: ".$e->getMessage());
        }
      } else if (is_null($state)) {
        // If state is empty, check if form is deleted.
        $complete = $this->get_value($instrument."_complete", $record, $event, $instance);
        if (is_null($complete)) {
          // The form is deleted, delete also corresponding participant in Limesurvey
          try {
            $this->delete_participant($instrument, $record, $event, $instance);
          } catch (\Exception $e) {
            $this->error("redcap_save_record: ".$e->getMessage());
          }
        }
      }
    }
  }


  // Validate configuration settings hook. 
  // - Check connection to the Limesurvey API.
  // - Check validity of entered survey IDs.
  // - Check minimum number of code digits.
  
  function validateSettings($settings) {
    $this->pid = $this->getProjectId();

    // find out if this are the system settings or the project settings
    if (array_key_exists("ls-user", $settings)) {

      $this->debug("validateSettings: validate project settings");

      // Check connection to Limesurvey API
      
      $user = trim($settings["ls-user"]);
      $pass = trim($settings["ls-pass"]);

      try {
        // args: user, pass
        $this->open_limesurvey_session($user, $pass);
      } catch (\Exception $e) {
        return "Cannot connect to Limesurvey: ".$e->getMessage();
      }

      // Check validity of survey IDs
      
      $surveys = $this->jsonClient->list_surveys($this->sessionKey);
      $surveys = array_column($surveys, "sid");
      $sid = array_map(trim, $settings["survey-id"]);
      $diff = array_diff($sid, $surveys);

      if (count($diff) > 0)
        return "Unvalid Survey IDs: ".implode(", ", $diff);

      // Verify that the number of code digits is not to low
      
      $digits = trim($settings["code-digits"]);
      if (!empty($digits)) {
        if (intval($digits) < MIN_DIGITS) {
          return "The number of code digits must be a positive integer >= 3.";
        }
      }
    }

    return;
  }


  // Save configuration hook, sanitize settings.
  // Sanitize URL, remove leading and trailing blanks in settings.
  
  function redcap_module_save_configuration($pid) {
    $this->pid = $pid;

    if (empty($pid)) {

      $this->debug("redcap_module_save_configuration: save system settings");

      $settings = ["ls-url", "proxy"];
      foreach ($settings as $s) {
        $val = $this->getSystemSetting($s);
        $val = filter_var($val, FILTER_SANITIZE_URL);
        $this->setSystemSetting($s, $val);
      }

      $val = $this->getSystemSetting("proxyauth");
      $val = trim($val);
      $this->setSystemSetting("proxyauth", $val);

    } else {

      $this->debug("redcap_module_save_configuration: save project settings");

      $settings = ["ls-user", "ls-pass", "code-prefix"];
      foreach ($settings as $s) {
        $val = $this->getProjectSetting($s, $pid);
        $val = trim($val);
        $this->setProjectSetting($s, $val, $pid);
      }

      $settings = ["survey-id", "code-appendix"];
      foreach ($settings as $s) {
        $val = $this->getProjectSetting($s, $pid);
        $val = array_map(trim, $val);
        $this->setProjectSetting($s, $val, $pid);
      }

      $digits = trim($this->getProjectSetting("code-digits", $pid));
      if (empty($digits)) {
        $this->setProjectSetting("code-digits", CODE_DIGITS, $pid);
      }
    }
  }


  // Cron method:
  // - Update state of submitted or expired surveys.
  // - On errors double cron interval.
  
  function update_limesurvey() {
    // For development or debugging purposes only.
    if (DISABLE_CRON_METHOD) return;

    // Get list of projects with this module enabled.
    
    $sql = "SELECT y.project_id FROM redcap_external_modules x
        JOIN redcap_external_module_settings y
        ON x.external_module_id=y.external_module_id
        WHERE x.directory_prefix='limecap'
        AND y.key='enabled'
        AND y.value='true'
        AND y.project_id IS NOT NULL;";
    $res = $this->query($sql);

    // Update each project ----------------------------------------------

    if ($res->num_rows > 0) {
      while($row = $res->fetch_array(MYSQLI_ASSOC)) {
        $pid = intval($row["project_id"]);
        $this->pid = $pid;
        try {
          $this->update_project();
        } catch (\Exception $e) {
          $this->error("update_project: ".$e->getMessage());
        }
        $this->close_limesurvey_session();
        $this->pid = NULL;
      }
    }

    // On errors increase cron interval ---------------------------------

    // get module id to construct WHERE part of SQL queries
    $sql = "select * from redcap_external_modules " .
           "where directory_prefix='limecap';";
    $res = $this->query($sql);
    $row = $res->fetch_array(MYSQLI_ASSOC);
    $where = " where external_module_id=". $row["external_module_id"] .
             " and cron_name='update_limesurvey';";

    // get current cron state
    $sql = "select cron_frequency from redcap_crons" . $where;
    $res = $this->query($sql);
    $row = $res->fetch_array(MYSQLI_ASSOC);

    // if the update finished without error reset cron interval to default
    // if current interval is larger due to past errors
    if ($this->error == false) {
      if ($row["cron_frequency"] != CRON_DEFAULT) {
        $this->debug("update_limesurvey: reset cron frequency to default");
        $sql = "update redcap_crons set cron_frequency=" .
          CRON_DEFAULT . $where;
        $res = $this->query($sql);
      }
    } else {
      // on error double cron interval
      $freq = 2 * $row["cron_frequency"];
      $this->debug("update_limesurvey: set cron interval to " . $freq);
      $sql = "update redcap_crons set cron_frequency=" . $freq . $where;
      $res = $this->query($sql);
    }
  }


  // update state of LimeSurvey instruments for current project ($this->pid)
  private function update_project() {
    // $this->debug("Update project ".$this->pid);
    $forms = $this->getProjectSetting("form-name", $this->pid);

    for ($i = 0; $i < count($forms); $i++) {
      $instrument = $forms[$i];
      $this->update_submitted($instrument);
      $this->update_expired($instrument);
    }
  }


  // update state of submitted surveys
  private function update_submitted($instrument) {
    $state_field = $instrument."_state";
    $sql = "select * from redcap_data where project_id=".$this->pid.
         " and field_name='".$state_field."' and value='".STATE_ACTIVE."'";
    $res = $this->query($sql);

    if ($res->num_rows > 0) {
      while($row = $res->fetch_array(MYSQLI_ASSOC)) {
        $this->check_survey_state($instrument, $row["record"], $row["event_id"], 
          $row["instance"]);
      }
    }

    $res->free();
  }


  // mark expired surveys as 'expired' for instrument
  private function update_expired($instrument) {
    $now = date("Y-m-d H:i:s");
    $until = $instrument."_validuntil";
    $state = $instrument."_state";
    $filter = "[".$state."] = '".STATE_ACTIVE."' and [".$until.
      "] < '".$now."' and [".$until."] <> ''";
    $params = ["project_id" => $this->pid,
               "fields" => [$state],
               "filterLogic" => $filter];

    // find all active surveys with validuntil less than current date
    $data = \REDCap::getData($params);
    if (count($data) < 1) return;

    // set state of surveys to 'expired'
    $this->debug("update_expired: some surveys for ".$instrument." have expired");
    array_walk_recursive($data, function (&$v, $k) use ($state) {
                                  if ($k == $state) $v = STATE_EXPIRED;
                                });
    $this->chk_save(\REDCap::saveData($this->pid, 'array', $data));
  }


  // check if active survey has been completed
  private function check_survey_state($instrument, $record, $event, $instance) {
    // $this->debug("check_survey_state: ".$instrument.".".$record.".".$event.".".$instance);

    // check if there is a survey response for this form
    $res = $this->get_response($instrument, $record, $event, $instance);
    
    if (!empty($res)) {
      $this->debug("Survey completed: ".$instrument.".".$record.".".$event.".".$instance);
      $this->save_value($instrument."_startdate", $res["startdate"], 
        $record, $event, $instance);
      $this->save_value($instrument."_submitdate", $res["submitdate"], 
        $record, $event, $instance);
      $this->save_value($instrument."_state", STATE_SUBMITTED, 
        $record, $event, $instance);
      $this->save_value($instrument."_complete", FORM_COMPLETE, 
        $record, $event, $instance);
      return;
    }
    
    // if no response found, check if participant entry is still valid
    if (is_null($instance) or $instance == 1) $survey_event = $event;
    else $survey_event = $event . "." . $instance;
    
    $p = $this->get_participant($instrument, $record);
    if (is_null($p) or $p["firstname"] != $survey_event) {
      $this->debug("missing participant entry, set state to expired");
      $this->save_value($instrument."_state", STATE_EXPIRED, $record, $event, $instance);
    }
  }


  // update valid dates for active forms if changed
  private function update_valid_active($instrument, $record, $event, $instance) {
    $this->debug("update_valid_active: ".$instrument.".".$record.".".$event.".".$instance);

    if (is_null($instance) or $instance == 1) $survey_event = $event;
    else $survey_event = $event . "." . $instance;
 
    // get participant info from Limesurvey
    $p = $this->get_participant($instrument, $record);
     
    // if there is no matching participant in Limesurvey, set to expired
    if ($survey_event != $p["firstname"]) {
      $this->debug("update_valid_active: no matching participant found, set to expired");
      $this->save_value($instrument."_state", STATE_EXPIRED, $record, $event, $instance);
      return false;
    } 
    
    $from = $this->get_validfrom($instrument, $record, $event, $instance);
    $until = $this->get_validuntil($instrument, $record, $event, $instance);

    if ($p["validfrom"] != $from or $p["validuntil"] != $until) {
      $d = ["validfrom" => $from, "validuntil" => $until];
      $res = $this->set_participant($instrument, $record, $p["tid"], $d);
    
      // if validuntil < now, set state to "expired"
      if (strtotime($until) < time()) {
        $this->debug("set state to 'expired': ".
          $instrument.".".$record.".".$event.".".$instance);
        $this->save_value($instrument."_state", STATE_EXPIRED, $record, $event, $instance);
      }
    }
  }  


  // update valid dates for expired forms if changed
  private function update_valid_expired($instrument, $record, $event, $instance) {
    $this->debug("update_valid_expired: ".$instrument.".".$record.".".$event.".".$instance);

    if (is_null($instance) or $instance == 1) $survey_event = $event;
    else $survey_event = $event . "." . $instance;

    $until = $this->get_validuntil($instrument, $record, $event, $instance);

    // if validuntil > now, than activate form
    if (strtotime($until) > time()) {
      $this->activate_code($instrument, $record, $event, $instance);
    }
  }


  // activate Limesurvey instrument
  private function activate_code($instrument, $record, $event, $instance) {
    $this->debug("activate_code: ".$instrument.".".$record.".".$event.".".$instance);

    if (is_null($instance) or $instance == 1) $survey_event = $event;
    else $survey_event = $event . "." . $instance;

    $token = $this->get_token($record, $instrument);
    $from = $this->get_validfrom($instrument, $record, $event, $instance);
    $until = $this->get_validuntil($instrument, $record, $event, $instance);
    
    $p = ["firstname"  => $survey_event,
          "lastname"   => $record, 
          "token"      => $token, 
          "validfrom"  => $from, 
          "validuntil" => $until];

    // get additional attributes
    $attr_field = $this->getProjectSetting("attribute-field", $this->pid);
    
    for ($i = 0; $i < count($attr_field); $i++) {
      $a_field = $attr_field[$i];
      if (empty($a_field)) continue;
      $a_value = $this->get_value($a_field, $record);
      if (!is_null($a_value)) {
        $p["attribute_".($i+1)] = $a_value;
      }
    }

    $res = $this->add_participant($instrument, $record, $p);
    
    if (!empty($res)) {
      $this->activate_form($instrument, $record, $event, $instance);
    }
  }
  
  
  // set state of Limesurvey form to 'active', taking care that all other
  // active forms for the same record and instrument are set to 'expired'
  private function activate_form($instrument, $record, $event, $instance) {
    $this->debug("activate_form: ".$instrument.".".$record.".".$event.".".$instance);

    // set active surveys for the same record and instrument to expired
    $sql = "UPDATE redcap_data SET value='" . STATE_EXPIRED . "'" .
           " WHERE project_id=" . $this->pid .
           " AND record='" . $record."'" . 
           " AND field_name='" . $instrument . "_state'" . 
           " AND value='" . STATE_ACTIVE . "';";
    $res = $this->query($sql);
      
    $this->save_value($instrument."_state", STATE_ACTIVE, $record, $event, $instance);
  }


  // get or create unique LimeSurvey code for this record
  private function get_code($record) {
  
    // get name of the code field for this project
    $field = $this->getProjectSetting("code-field", $this->pid);
    if (empty($field)) {
      throw new \Exception("get_code: no code field configured");
    }

    // fetch value of code field -------------------------------------------
    $sql = "select * from redcap_data where project_id=".$this->pid.
           " and record='".$record."' and field_name='".$field."';";
    $res = $this->query($sql);

    // if we find values return the first
    if ($res->num_rows > 0) {
      $row = $res->fetch_array(MYSQLI_ASSOC);
      return $row["value"];
    }
    
    // if no value found, create a new code --------------------------------
    $this->debug("get_code: create new code for record '".$record."'");
    $prefix = $this->getProjectSetting("code-prefix", $this->pid);
    $digits = intval($this->getProjectSetting("code-digits", $this->pid));
    if ($digits < MIN_DIGITS) $digits = CODE_DIGITS;
    $min = pow(10, $digits - 1);
    $max = pow(10, $digits) - 1;

    // find a unique code, try as many times as the number of possible codes
    $code = null;
    for ($i = $min; $i <= $max; $i++) {
      if (!empty($code)) break;

      // try a new random code
      $try_code = $prefix.rand($min, $max);
      $this->debug("get_code: try new code ".$try_code);

      // check if this code is in use by some other record
      $sql = "select * from redcap_data where project_id=".$this->pid.
           " and field_name='".$field."' and value='".$try_code."';";
      $res = $this->query($sql);

      // if the new code is not found in this project we can use it
      if ($res->num_rows == 0) {
        $code = $try_code;
      } else {
        $this->debug("get_code: code ".$try_code." is in use, try another");
      }
      $res->free();
    }

    // if code is still empty, we are out of codes
    if (empty($code)) {
      throw new \Exception("get_code: out of survey codes, increase code range");
    }

    // to store the code we have to find the event of the code field
    // for this record

    // first find the instrument of the code field
    $res = \REDCap::getDataDictionary($this->pid, "array", false, $field);
    $instrument = $res[$field]["form_name"];
    $this->debug("get_code: code field '".$field."' in form '".$instrument."'");

    // then find the arm of record
    $sql = "select distinct a.arm_id from redcap_events_metadata a ".
           "join redcap_data b on a.event_id = b.event_id where ".
           "project_id=".$this->pid." and record='".$record."';";
    $res = $this->query($sql);
    
    if ($res->num_rows < 1) {
      throw new \Exception("get_code: cannot find arm of code field");
    }
    
    $row = $res->fetch_array(MYSQLI_ASSOC);
    $arm = $row["arm_id"];

    // now we can get the event of instrument in this arm
    $sql = "select a.event_id from redcap_events_metadata a ".
           "join redcap_events_forms b on a.event_id = b.event_id ".
           "where a.arm_id = '".$arm."' and b.form_name = '".$instrument."';";
    $res = $this->query($sql);
    
    if ($res->num_rows < 1) {
      throw new \Exception("get_code: cannot find event of code field");
    }
    
    $row = $res->fetch_array(MYSQLI_ASSOC);
    $event = $row["event_id"];

    // set value of code field to the new code
    $this->save_value($field, $code, $record, $event);

    $this->debug("get_code: code=".$code." for record '".$record.
      "' event ".$event." arm ".$arm);

    return($code);
  }


  // create token from code and code appendix of instrument
  private function get_token($record, $instrument) {
    $code = $this->get_code($record);
    $forms = $this->getProjectSetting("form-name", $this->pid);
    $apdx = $this->getProjectSetting("code-appendix", $this->pid);
    $i = array_search($instrument, $forms);
    $token = $code.$apdx[$i];
    
    // $this->debug("get_token: token for record ".$record." instrument ".
    //   $instrument." is ".$token);

    return $token;
  }


  // return the Limesurvey SID for instrument
  private function instrument_sid($instrument) {
    $forms = $this->getProjectSetting("form-name", $this->pid);
    $sid = $this->getProjectSetting("survey-id", $this->pid);
    $i = array_search($instrument, $forms);
    return $sid[$i];
  }


  // get Limesurvey response
  private function get_response($instrument, $record, $event, $instance) {
    // $this->debug("get_response: ".$instrument.".".$record.".".$event.".".$instance);
    if (is_null($instance) or $instance == 1) $survey_event = $event;
    else $survey_event = $event.".".$instance;   
     
    $token = $this->get_token($record, $instrument);
    $sid = $this->instrument_sid($instrument);
    $this->open_limesurvey_session();
    $client = $this->jsonClient;
    $key = $this->sessionKey;

    // get list of completed surveys matching token
    $res = $client->export_responses_by_token($key, $sid, 'json',
      $token, NULL, 'complete');
    if (!is_string($res)) return;

    // convert base64 string to array
    $res = json_decode(base64_decode($res), true);
    $dat = array_reduce($res["responses"], 'array_merge', []);

    // find row matching record and event
    foreach ($dat as $x) {
      if ($x["record"] == $record and $x["event"] == $survey_event) {
        return $x;
      }
    }

    return;  
  }
  
  
  // get Limesurvey participant
  private function get_participant($instrument, $record) {
    // $this->debug("get_participant: ".$instrument.".".$record);
    
    $token = $this->get_token($record, $instrument);
    $sid = $this->instrument_sid($instrument);
    $this->open_limesurvey_session();
    $client = $this->jsonClient;
    $key = $this->sessionKey;
    
    $p = $client->get_participant_properties($key, $sid, ['token' => $token]);
    
    if (array_key_exists("status", $p)) {
      // get_participant_properties returns an error if participant
      // is not found, but we dont consider this as an error
      if (strpos($p["status"], "No results were found") == false) {
        $this->error("get_participant: ".$p["status"]);
      }
      return;
    }
    return $p;  
  }
  
  
  // delete Limesurvey participant
  private function delete_participant($instrument, $record, $event, $instance) {

    if (is_null($instance) or $instance == 1) $survey_event = $event;
    else $survey_event = $event . "." . $instance;

    // get participant info from Limesurvey
    $p = $this->get_participant($instrument, $record);
    
    // verify event and instance of participant
    if ($survey_event != $p["firstname"]) return false;

    $this->debug("delete_participant: ".$instrument.".".$record.".".$event.".".$instance);
    $sid = $this->instrument_sid($instrument);
    $this->open_limesurvey_session();
    $client = $this->jsonClient;
    $key = $this->sessionKey;
    $res = $client->delete_participants($key, $sid, [$p["tid"]]);
    $this->dump($res);
    if (array_key_exists("status", $res)) {
      $this->error("delete_participant: ".$res["status"]);
      return;
    }
    return $res;
  }
  
  
  // add Limesurvey participant
  private function add_participant($instrument, $record, $data) {
    $this->debug("add_participant: ".$instrument.".".$record);
    $this->dump($data);
    
    $sid = $this->instrument_sid($instrument);
    $this->open_limesurvey_session();
    $client = $this->jsonClient;
    $key = $this->sessionKey;
    
    // delete participants with the same token
    $p = $this->get_participant($instrument, $record);
    
    if (array_key_exists("tid", $p)) {
      $res = $client->delete_participants($key, $sid, [$p["tid"]]);
    }

    // add participant
    $res = $client->add_participants($key, $sid, [$data], false);
    $err = array_column($res, "errors");
    if (count($err) > 0 ) {
      $this->dump($err);
      throw new \Exception("add_participant: error adding token to Limesurvey");
    }
    if (array_key_exists("status", $res)) {
      $this->error("add_participant: ".$res["status"]);
      return;
    }
    
    return $res;    
  }
  

  // set Limesurvey participant properties
  private function set_participant($instrument, $record, $tid, $data) {
    $this->debug("set_participant: ".$tid.": ".$instrument.".".$record);
    $this->dump($data);
    
    $sid = $this->instrument_sid($instrument);
    $this->open_limesurvey_session();
    $client = $this->jsonClient;
    $key = $this->sessionKey;
 
    $res = $client->set_participant_properties($key, $sid, $tid, $data);
 
    if (array_key_exists("status", $p)) {
      $this->error("check_valid_date: ".$p["status"]);
      return;
    }
    return $res; 
  }
  
  
  // get Limesurvey session key
  private function open_limesurvey_session($user = NULL, $pass = NULL) {

    if (!empty($this->sessionKey)) return;

    if (empty($user)) $user = $this->getProjectSetting("ls-user", $this->pid);
    if (empty($pass)) $pass = $this->getProjectSetting("ls-pass", $this->pid);

    $url = $this->getSystemSetting("ls-url");
    $proxy = $this->getSystemSetting("proxy");
    $auth = $this->getSystemSetting("proxyauth");

    $client = new \org\jsonrpcphp\JsonRPCClient($url);
    if (!empty($proxy)) $client->setProxy($proxy, $auth);
    $time = microtime(true);
    $key = $client->get_session_key($user, $pass);
    $time = microtime(true) - $time;
    if ($time > 10) $this->debug("get_session_key time: ".$time);

    if (is_array($key)) {
      throw new \Exception("open Limesurvey session: ".$key["status"]);
    }

    $this->sessionKey = $key;
    $this->jsonClient = $client;
  }


  // release Limesurvey session key
  private function close_limesurvey_session() {
    if (empty($this->sessionKey)) return;
    $this->sessionKey = NULL;
    $this->jsonClient = NULL;
  }


  // check result of REDCap::saveData() for errors
  private function chk_save($result) {
    $error = $result["errors"];
    if (is_string($error)) throw new \Exception($error);
    $warning = $result["warnings"];
    if (is_string($warning)) $this->debug($warning);
    if ($result["item_count"] == 0) $this->debug("Warning: no data saved");
    return $result;
  }


  // get redcap field value, works with repated instances
  private function get_value($field, $record, $event = NULL, $instance = NULL) {
    if (is_null($instance) or $instance == 1) $instance = "NULL";
    
    if (is_null($event)) $where_event = "";
    else $where_event = " and event_id=".$event;
    $sql = "select * from redcap_data where project_id=".$this->pid. 
           " and record='".$record."' and field_name='".$field."'".
           " and instance<=>".$instance.$where_event.";";
           
    $res = $this->query($sql);
    $row = $res->fetch_array(MYSQLI_ASSOC);
    $res->free();
    // $this->debug("get_value: " . $sql);
    // $this->dump($row);
    return $row["value"];
  }
  
 
  // update redcap field value, works with repeated instances
  private function save_value($field, $value, $record, $event, $instance = NULL) {
    if (is_null($instance) or $instance == 1) $instance = "NULL";
    
    if(is_null($value) or $value == "") {
      // delete field
      $sql = "delete from redcap_data where project_id=".$this->pid.
             " and record='".$record."' and event_id=".$event.
             " and field_name='".$field."' and instance<=>".$instance.";";
    } else {
      // check if field has value
      $old_val = $this->get_value($field, $record, $event, $instance);
      
      if (is_null($old_val)) {
        // field has no value, use insert
        $sql = "insert redcap_data set project_id=".$this->pid.
               ", record='".$record."', event_id=".$event.
               ", field_name='".$field."', value='".$value.
               "', instance=".$instance.";";
      } else {
        // field has value, use update
        $sql = "update redcap_data set value='".$value.
               "' where project_id=".$this->pid.
               " and record='".$record."' and event_id=".$event.
               " and field_name='".$field."' and instance<=>".$instance.";";
      }
    }
    
    // $this->debug("save_value: " . $sql);
    
    $this->query($sql);
  }


  // get validfrom date and set it to the default if missing
  // caution! call this function only for existing Limesurvey forms
  private function get_validfrom($instrument, $record, $event, $instance) {
    $from = $this->get_value($instrument."_validfrom", $record, $event, $instance);

    if (is_null($from)) {
      $from = date("Y-m-d H:i:s", time());
      $this->save_value($instrument."_validfrom", $from, $record, $event, $instance);
      $this->debug("get_validfrom: replace empty validfrom with " . $from);
    }
    return $from;
  }


  // get validuntil date and set it to the default if missing
  // caution! call this function only for existing Limesurvey forms
  private function get_validuntil($instrument, $record, $event, $instance) {
    $until = $this->get_value($instrument."_validuntil", $record, $event, $instance);

    if (is_null($until)) {
      $until = date("Y-m-d H:i:s", strtotime("+30 days", time()));
      $this->save_value($instrument."_validuntil", $until, $record, $event, $instance);
      $this->debug("get_validuntil: replace empty validuntil with " . $until);
    }
    return $until;
  }

 
  // print error message to error.log and send a mail with the
  // error message to root
  private function error($message) {
    $this->error = true;
    $this->debug("Error: ".$message);
    $log_file = $this->getModulePath()."error.log";
    $txt = date("Y-m-d H:i:s");
    if (!empty($this->pid)) $txt = $txt." PID ".$this->pid;
    $txt = $txt.": ".$message."\n";
    file_put_contents($log_file, $txt, FILE_APPEND);
    mail("root", "LimeCap Error", $message,
      "Content-Type: text/plain; charset=utf-8");
  }

  
  // print a debug message to debug.log
  private function debug($message) {
    if (!DEBUG) return;
    $log_file = $this->getModulePath()."debug.log";
    $txt = date("Y-m-d H:i:s");
    if (!empty($this->pid)) $txt = $txt." PID ".$this->pid;
    $txt = $txt.": ".$message."\n";
    file_put_contents($log_file, $txt, FILE_APPEND);
  }


  // dump data to debug.log
  private function dump($data) {
    if (!DEBUG) return;
    $log_file = $this->getModulePath()."debug.log";
    ob_start();
    var_dump($data);
    $buffer = ob_get_contents();
    ob_end_clean();
    file_put_contents($log_file, $buffer, FILE_APPEND);
  }
}

?>
