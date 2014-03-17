<?php

require_once 'eventactivities.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function eventactivities_civicrm_config(&$config) {
  _eventactivities_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function eventactivities_civicrm_xmlMenu(&$files) {
  _eventactivities_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function eventactivities_civicrm_install() {
  _eventactivities_create_activity_types();
  return _eventactivities_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function eventactivities_civicrm_uninstall() {
  return _eventactivities_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function eventactivities_civicrm_enable() {
  return _eventactivities_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function eventactivities_civicrm_disable() {
  return _eventactivities_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function eventactivities_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _eventactivities_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function eventactivities_civicrm_managed(&$entities) {
  return _eventactivities_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_caseTypes
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 */
function eventactivities_civicrm_caseTypes(&$caseTypes) {
  _eventactivities_civix_civicrm_caseTypes($caseTypes);
}

/**
 * create required activity types
 */
function _eventactivities_create_activity_types() {
  $activityTypes = _eventactivities_get_activity_label_keys();
  foreach ($activityTypes as $activityType) {
    try {
      civicrm_api3('option_value', 'getsingle', array('option_group_id' => 'activity_type', 'name' => $activityType));
    }
    catch(Exception $e) {
      civicrm_api3('option_value', 'create', array(
      'option_group_id' => 'activity_type',
      'name' => $activityType,
      'component_id' => 'CiviEvent',
      ));
    }
  }
}
/**
 * Get activity type ids
 * @param string $all
 * @param string $attended
 * @return array ids of activity_types
 */
function _eventactivities_get_activity_types($all = TRUE, $attended = NULL) {
  static $types;
  if(!$types) {
    $types = civicrm_api3('OptionValue', 'get', array('option_group_id' => 'activity_type', 'name' => array('IN' => _eventactivities_get_activity_label_keys())));
    $types = $types['values'];
  }

  $labels =  _eventactivities_get_activity_label_keys($all, $attended);
  $filteredTypes = array();
  foreach ($types as $type) {
    if(in_array($type['name'], $labels)) {
      $filteredTypes[] = $type['value'];
    }
  }
  return $filteredTypes;
}

/**
 * get hardcoded labels
 * @param string $all
 * @param string $attended
 * @return multitype:boolean
 */
function _eventactivities_get_activity_labels($all = TRUE, $attended = NULL) {
  if($all) {
    return array('Event Attended' => TRUE, 'Event Not Attended' => FALSE);
  }
  if($attended) {
    return array('Event Attended' => TRUE);
  }
  return array('Event Not Attended' => FALSE);
}

/**
 * get labels keys
 * @param string $all
 * @param string $attended
 * @return multitype:boolean
 */
function _eventactivities_get_activity_label_keys($all = TRUE, $attended = NULL) {
  return array_keys(_eventactivities_get_activity_labels($all, $attended));
}

/**
 *
 * @param unknown $participantID
 * @param unknown $eventID
 * @return Ambigous <multitype:, number, unknown>
 */
function _eventactivities_get_event_details($participantID, $eventID) {
  return civicrm_api3('event', 'getsingle', array('id' => $eventID, 'return' => 'start_date, end_date, title' ));
}

/**
 * implement hook civicrm_post
 * @param unknown $op
 * @param unknown $objectName
 * @param unknown $objectId
 * @param unknown $objectRef
 */
function eventactivities_civicrm_post( $op, $objectName, $objectId, &$objectRef ) {
  if($objectName != 'Participant') {
    return;
  }

  if($op == 'delete') {
    _eventactivities_delete_related_activity($objectId);
    return;
  }

  if((empty($objectRef->status_id) || empty($objectRef->role_id)) && !empty($objectId)) {
    $participant = civicrm_api3('participant', 'getsingle', array('id' => $objectId, 'return' => 'status_id, role_id'));
    $status_id = $participant['status_id'];
    $role_ids = $participant['role_id'];
  }
  else {
    $status_id = $objectRef->status_id;
    $role_ids = explode(CRM_Core_DAO::VALUE_SEPARATOR, $objectRef->role_id);
  }
  $allroles = civicrm_api3('participant', 'getoptions', array('field' => 'role_id'));
  $roles = array();
  foreach ($role_ids as $role_id) {
    $roles[] = $allroles['values'][$role_id];
  }

  $role = implode(', ', $roles);
  $statuses = _eventactivities_get_participant_statuses();
  if($statuses[$status_id]['class'] == 'Positive') {
    $attended = TRUE;
  }
  elseif ($statuses[$status_id]['class'] == 'Negative') {
    $attended = FALSE;
  }
  else {
    //pending
    return;
  }
  _eventactivities_create_event_activity($objectId, $objectRef->contact_id, $attended, $objectRef->event_id, $statuses[$status_id]['label'], $role);

}

/**
 *
 * @param array $types as in classes in the status table
 * @return multitype:unknown
 */
function _eventactivities_get_participant_statuses($types = array('Positive', 'Negative')) {
  static $statuses;
  if(empty($statuses)) {
    $statuses = civicrm_api3('participant_status_type', 'get', array('is_active' => 1));
    $statuses = $statuses['values'];
  }

  $filteredStatuses = array();
  foreach ($statuses as $id => $status) {
    if(in_array($status['class'], $types)) {
      $filteredStatuses[$id] = $status;
    }
  }
  return $filteredStatuses;
}

/**
 * Delete activity related to the participant record being deleted
 * @param unknown $participantID
 */
function _eventactivities_delete_related_activity($participantID) {
  try {
    $activity = civicrm_api3('activity', 'getsingle', array('return' => 'id', 'source_record_id' => $participantID, 'activity_type_id' => array('IN' => _eventactivities_get_activity_types())));
    civicrm_api3('activity', 'delete', array('id' => $activity['id']));
  }
  catch(Exception $e) {
  }
}

/**
 * Delete activity related to the participant record being deleted
 * @param unknown $participantID
 */
function _eventactivities_create_event_activity($participantID, $contactID, $attended, $eventID, $status, $role) {
  $activityTypes =  _eventactivities_get_activity_types(FALSE, $attended);
  $activityTypeID = $activityTypes[0];
  $eventDetails = _eventactivities_get_event_details($participantID, $eventID);
  $subject = "{$eventDetails['title']} $status $role ";

  try {
    $activity = civicrm_api3('activity', 'getsingle', array('source_record_id' => $participantID, 'activity_type_id' => array('IN' => _eventactivities_get_activity_types())));
    if($activity['activity_type_id'] != $activityTypeID || $activity['subject'] != $subject) {
      civicrm_api3('activity', 'create', array('id' => $activity['id'], 'activity_type_id' => $activityTypeID, 'subject' => $subject));
    }
  }
  catch(Exception $e) {
    try {
      civicrm_api3('activity', 'create', array(
        'source_contact_id' => $contactID,
        'source_record_id' => $participantID,
        'target_contact_id' => $contactID,
        'activity_type_id' => $activityTypeID,
        'activity_date_time' => $eventDetails['start_date'],
        'subject' => $subject,
      ));
    }
    catch(Exception $e) {
    }
  }
}

/**
 * get all participants with no attended & re-save - thus creating one
 */
function eventactivities_fill_event_activities() {
  $activityTypes = implode(',', _eventactivities_get_activity_types());
  $statuses = implode(',', array_keys(_eventactivities_get_participant_statuses()));

  $sql = "
    SELECT p.id FROM civicrm_participant p LEFT JOIN civicrm_activity a ON p.id = a.source_record_id
    AND activity_type_id IN ( $activityTypes )
    WHERE a.id IS NULL
    AND p.status_id IN ( $statuses )
  ";
  $dao = CRM_Core_DAO::executeQuery($sql);
  while ($dao->fetch()) {
    civicrm_api3('participant', 'create', array('id' => $dao->id));
  }
}