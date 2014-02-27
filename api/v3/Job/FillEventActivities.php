<?php

function civicrm_api3_job_fill_event_activities($params) {
  eventactivities_fill_event_activities();
  return civicrm_api3_create_success(array());
}