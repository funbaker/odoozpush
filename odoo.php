<?php
require_once("backend/odoo/config.php");

require_once('lib/default/diffbackend/diffbackend.php');
require_once('backend/odoo/ripcord/ripcord.php');

class BackendOdoo extends BackendDiff {
  protected $uid = false;
  protected $utz;
  protected $password = false;
  protected $models = null;
  protected $partnerID = false;

  public function GetSupportedASVersion() {
        return ZPush::ASV_14;
  }

  public function Logon($username, $domain, $password) {
    $common = ripcord::client(ODOO_SERVER . '/xmlrpc/2/common');
    $this->uid = $common->authenticate(ODOO_DB, $username, $password, []);
    $this->username = $username;
    $this->domain = $domain;
    $this->password = $password;

    if ($this->uid) {
      $this->models = ripcord::client(ODOO_SERVER . '/xmlrpc/2/object');
      $this->models->_throwExceptions = true;
      $partners = $this->models->execute_kw(ODOO_DB, $this->uid, $password,
        'res.users', 'search_read', [[
          ['id', '=', $this->uid]
        ]], [
          'fields' => ['partner_id']
      ]);

      if (count($partners) == 0) {
        return false;
      }

      ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::Logon: $partners = (' . print_r($partners, true)) . ')';

      $this->partnerID = $partners[0]['partner_id'][0];
      ZLOG::Write(LOGLEVEL_INFO, 'Odoo:Logon: Logged in with partner/user id ' . $this->partnerID . '/' . $this->uid);

      # timezone
      $users = $this->models->execute_kw(ODOO_DB, $this->uid, $password,
        'res.users', 'search_read', [[
          ['id', '=', $this->uid]
        ]], [
          'fields' => ['tz']
        ]
      );
      $user = $users[0];
      $this->utz = $user['tz'];

      return true;
    }
    return false;
  }

	public function Logoff() {
    return true;
  }

	public function SendMail($sm) {
    return true;//not implemented
  }

	public function GetWasteBasket() {
    return false;//not implemented
  }

	public function GetAttachmentData($attname) {
    return false;//not implemented
  }

	public function GetFolderList() {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetFolderList()');
    $folders = [];
    $folders[] = $this->StatFolder('calendar');
    $folders[] = $this->StatFolder('partners');
    $folders[] = $this->StatFolder('tasks');

    return $folders;
  }

  public function StatFolder($id) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::StatFolder(' . $id . ')');
    $folder = $this->GetFolder($id);
    $stat = [];
    $stat["id"] = $id;
    $stat["parent"] = $folder->parentid;
    $stat["mod"] = $folder->displayname;

    return $stat;
  }

  public function GetFolder($id) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetFolder(' . $id . ')');
    if ($id == 'calendar') {
      $folder = new SyncFolder();
      $folder->serverid = $id;
      $folder->parentid = "0";
      $folder->displayname = "Calendar";
      $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
      return $folder;
    }
    else if ($id == 'partners') {
      $folder = new SyncFolder();
      $folder->serverid = $id;
      $folder->parentid = "0";
      $folder->displayname = "Partners";
      $folder->type = SYNC_FOLDER_TYPE_CONTACT;
      return $folder;
    }
    else if ($id == 'tasks') {
      $folder = new SyncFolder();
      $folder->serverid = $id;
      $folder->parentid = "0";
      $folder->displayname = "Tasks";
      $folder->type = SYNC_FOLDER_TYPE_TASK;
      return $folder;
    }
    return false;
  }

	public function ChangeFolder($folderid, $oldid, $displayname, $type){
    return false;
  }

	public function DeleteFolder($id, $parentid){
    return false;
  }

	public function GetMessageList($folderid, $cutoffdate) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList(' . $folderid . ')');

    $cutoffdate = date('c', $cutoffdate);
    $messages = [];

    if ($folderid == 'calendar') {
      try {
        $events = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
          'calendar.event', 'search_read', [['|',
            ['user_id', '=', $this->uid],
            ['partner_ids', 'in', [$this->partnerID]],
            ['write_date', '>=', $cutoffdate]
          ]], [
            'fields' => ['id', 'write_date']
          ]
        );
      }
      catch (Exception $e) {
        if ($e->faultCode == 2) {
          ZLog::Write(LOGLEVEL_WARN, 'Error retrieving events.
            Please make sure that the calendar module is installed');
        }
      }

      ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList: $events = (' . print_r($events, true)) . ')';

      foreach($events as $event) {
        $messages[] = [
          'id' => 'event_' . $event['id'],
          'mod' => strtotime($event['write_date']),
          'flags' => 1
        ];
      }
    }
    else if ($folderid == 'partners') {
      $partners = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'res.partner', 'search_read', [[
          ['is_company', '!=', 'True'],
          ['write_date', '>=', $cutoffdate]
        ]], [
          'fields' => ['id', 'write_date']
        ]
      );

      ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList: $partners = (' . print_r($partners, true)) . ')';

      foreach($partners as $partner) {
        $messages[] = [
          'id' => 'partner_' . $partner['id'],
          'mod' => strtotime($partner['write_date']),
          'flags' => 1
        ];
      }
    }
    else if ($folderid == 'tasks') {
      try {
        $tasks = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
          'project.task', 'search_read', [[
            ['user_id', '=', $this->uid],
            ['write_date', '>=', $cutoffdate]
          ]], [
            'fields' => ['id', 'write_date']
          ]
        );
      }
      catch (Exception $e) {
        if ($e->faultCode == 2) {
          ZLog::Write(LOGLEVEL_WARN, 'Error retrieving tasks.
            Please make sure that the project module is installed');
        }
      }

      ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList: $tasks = (' . print_r($tasks, true)) . ')';

      foreach($tasks as $task) {
        $messages[] = [
          'id' => 'task_' . $task['id'],
          'mod' => strtotime($task['write_date']),
          'flags' => 1
        ];
      }
    }

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList: $messages = (' . print_r($messages, true)) . ')';
    return $messages;
  }


	public function GetMessage($folderid, $id, $contentparameters) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage(' . $folderid . ', ' . $id . ', ...)');

    if ($folderid == 'calendar') {
      return $this->GetEvent($id, $contentparameters);
    }
    else if ($folderid == 'partners') {
      return $this->GetPartner($id, $contentparameters);
    }
    else if ($folderid == 'tasks') {
      return $this->GetTask($id, $contentparameters);
    }

    return false;
  }

  protected function GetEvent($id, $contentparameters) {
    $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

    $events = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'calendar.event', 'search_read', [['|',
        ['user_id', '=', $this->uid],
        ['partner_ids', 'in', [$this->partnerID]],
        ['id', '=', intval(substr($id, 6))]
      ]],
      ['fields' => []]
    );

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetEvent: $events = (' . print_r($events, true) . ')');

    if (!count($events)) {
      $message = new SyncAppointment();
      $message->deleted = 1;
      return $message;
    }
    $event = $events[0];

    $users = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'res.users', 'search_read', [[
        ['id', '=', $event['user_id'][0]]
      ]], [
        'fields' => ['email']
      ]
    );
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $users = (' . print_r($users, true)) . ')';
    if (!count($users)) return false;
    $user = $users[0];

    $attendees = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'calendar.attendee', 'read', [$event['attendee_ids']], [
        'fields' => [
          'cn', 'email', 'state'
        ]
      ]
    );
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $attendees = (' . print_r($attendees, true)) . ')';

    $categories = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'calendar.event.type', 'read', [$event['categ_ids']], ['fields' => []]);
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $categories = (' . print_r($categories, true)) . ')';

    $message = new SyncAppointment();
    $message->uid = $event['id'];
    $message->dtstamp = strtotime($event['write_date']);
    $message->starttime = strtotime($event['start'])
      + $this->_getTimezoneOffset($this->utz);
    $message->endtime = strtotime($event['stop'])
      + $this->_getTimezoneOffset($this->utz);
    $message->timezone = $this->_GetTimezoneString($this->utz);
    $message->subject = $event['name'];

    if (count($attendees) != 0) {
      $message->organizername = $event['user_id'][1];
      $message->organizeremail = $user['email'];
    }

    $message->location = $event['location'];

    if ($event['recurrency']) {
      $recurrence = new SyncRecurrence();
      switch ($event['rrule_type']) {
        case 'daily':
          $recurrence->type = 0;
          break;
        case 'weekly':
          $recurrence->type = 1;
          break;
        case 'monthly':
          $recurrence->type = 2;
          if ($event['month_by'] == 'day') {
            $recurrence->type = 3;
          }
          break;
        case 'yearly':
          $recurrence->type = 5;
          break;
      }
      $recurrence->until = strtotime($event['final_date']);
      $recurrence->occurrences = intval($event['count']);
      $recurrence->interval = intval($event['interval']);

      //weekly
      if ($recurrence->type == 1) {
        $recurrence->dayofweek = 0;
        if ($event['su']) $recurrence->dayofweek += 1;
        if ($event['mo']) $recurrence->dayofweek += 2;
        if ($event['tu']) $recurrence->dayofweek += 4;
        if ($event['we']) $recurrence->dayofweek += 8;
        if ($event['th']) $recurrence->dayofweek += 16;
        if ($event['fr']) $recurrence->dayofweek += 32;
        if ($event['sa']) $recurrence->dayofweek += 64;
      }

      //monthly
      if ($recurrence->type == 2) {
        $recurrence->dayofmonth = intval($event['day']);
      }

      //monthly on nth day
      if ($recurrence->type == 3) {
        $recurrence->dayofweek = 0;

        switch ($event['byday']) {
          case '1':
          case '2':
          case '3':
          case '4':
          case '5':
            $recurrence->weekofmonth = intval($event['byday']);
            break;
          case '-1':
            $recurrence->weekofmonth = 5;
            break;
        }

        switch ($event['week_list']) {
          case 'SU':
            $recurrence->dayofweek += 1;
            break;
          case 'MO':
            $recurrence->dayofweek += 2;
            break;
          case 'TU':
            $recurrence->dayofweek += 4;
            break;
          case 'WE':
            $recurrence->dayofweek += 8;
            break;
          case 'TH':
            $recurrence->dayofweek += 16;
            break;
          case 'FR':
            $recurrence->dayofweek += 32;
            break;
          case 'SA':
            $recurrence->dayofweek += 64;
            break;
        }
      }

      if ($recurrence->type == 5) {
        $start_date = strtotime($event['start_date']);
        $recurrence->monthofyear = intval(date('n', $start_date));
      }

      $message->recurrence = $recurrence;
    }

    switch ($event['class']) {
      case 'public':
        $message->sensitivity = 0;
        break;
      case 'private':
        $message->sensitivity = 2;
        break;
      case 'confidential':
        $message->sensitivity = 3;
        break;
    }

    switch ($event['show_as']) {
      case 'free':
        $message->busystatus = 0;
        break;
      case 'busy':
        $message->busystatus = 2;
        break;
    }

    $message->alldayevent = $event['allday'];
    $message->reminder = 30;//TODO
    $message->meetingstatus = count($attendees) == 0 ? 0 : 1;

    $message->attendees = array_map(function ($attendee) use ($message) {
      $syncattendee = new SyncAttendee();
      $syncattendee->email = $attendee['email'];
      $syncattendee->name = $attendee['cn'];

      $syncattendee->attendeetype = 1;
      switch ($attendee['state']) {
        case 'needsAction':
          $syncattendee->attendeestatus = 5;
          break;
        case 'tentative':
          $syncattendee->attendeestatus = 2;
          break;
        case 'declined':
          $syncattendee->attendeestatus = 4;
          break;
        case 'accepted':
          $syncattendee->attendeestatus = 3;
          break;
        default:
          $syncattendee->attendeestatus = 0;
      }

      return $syncattendee;
    }, $attendees);

    if (Request::GetProtocolVersion() >= 12.0) {
      $message->asbody = new SyncBaseBody();
      $message->asbody->type = SYNC_BODYPREFERENCE_PLAIN;
      $message->asbody->data = $event['description'];
      $message->asbody->estimatedDataSize = mb_strlen($event['description'], 'UTF-8');

      if ($message->asbody->estimatedDataSize > $truncsize) {
        $message->asbody->data = Utils::Utf8_truncate($message->asbody->data, $truncsize);
        $message->asbody->truncated = 1;
      }
    }
    else {
      $message->body = $event['description'];
      $message->body = str_replace("\n", "\r\n", str_replace("\r", "", $message->body));
      $message->bodysize = mb_strlen($event['description'], 'UTF-8');
      if(strlen($message->body) > $truncsize) {
        $message->body = Utils::Utf8_truncate($message->body, $truncsize);
        $message->bodytruncated = 1;
      }
    }

    $message->categories = array_map(function ($category) {
      return $category['name'];
    }, $categories);

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $message = (' . print_r($message, true) . ')');
    return $message;
  }

  protected function GetPartner($id, $contentparameters) {
    $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

    $partners = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'res.partner', 'search_read', [[
        ['is_company', '!=', 'True'],
        ['id', '=', intval(substr($id, 8))]
      ]], [
        'fields' => []
      ]
    );
    if (!count($partners)) {
      $message = new SyncContact();
      $message->deleted = 1;
      return $message;
    }

    $partner = $partners[0];

    $categories = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'res.partner.category', 'read', [$partner['category_id']], ['fields' => []]);
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $categories = (' . print_r($categories, true)) . ')';

    $message = new SyncContact();
    $message->birthday = strtotime($partner['birthdate']);
    $message->businesscity = $partner['city'];
    if ($partner['country_id']) $message->businesscountry = $partner['country_id'][1];
    $message->businesspostalcode = $partner['zip'];

    if ($partner['state_id']) $message->businessstate = $partner['state_id'][1];
    $message->businessstreet = $partner['street'];

    $message->businessfaxnumber = $partner['fax'];
    $message->businessphonenumber = $partner['phone'];
    if ($partner['company_id']) $message->companyname = $partner['company_id'][1];
    $message->email1address = $partner['email'];
    $message->fileas = $partner['name'];

    $names = preg_split('/\s+/', $partner['name'], 3, PREG_SPLIT_NO_EMPTY);
    if (count($names) == 1) $message->firstname = $names[0];
    if (count($names) == 3) {
      $message->firstname = $names[0];
      $message->middlename = $names[1];
      $message->lastname = $names[2];
    }
    else if (count($names) == 2) {
      $message->firstname = $names[0];
      $message->lastname = $names[1];
    }

    $message->jobtitle = $partner['function'];
    $message->title = $partner['title'];
    $message->webpage = $partner['website'];

    $message->categories = array_map(function ($category) {
      return $category['name'];
    }, $categories);

    $body = $partner['comment'];
    $message->bodytruncated = false;
    if(strlen($body) > $truncsize) {
      $body = Utils::Utf8_truncate($body, $truncsize);
      $message->bodytruncated = true;
    }
    $message->body = str_replace("\n", "\r\n", str_replace("\r", "", $body));
    $message->asbody = new SyncBaseBody();

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $message = (' . print_r($message, true) . ')');
    return $message;
  }

  protected function GetTask($id, $contentparameters) {
    $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

    $tasks = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'project.task', 'search_read', [[
        ['user_id', '=', $this->uid],
        ['id', '=', intval(substr($id, 5))]
      ]], [
        'fields' => [
          'stage_id',
          'tag_ids',
          'description',
          'date_last_stage_update',
          'date_deadline',
          'priority',
          'date_start',
          'name'
        ]
      ]
    );
    if (!count($tasks)) {
      $message = new SyncTask();
      $message->deleted = 1;
      return $message;
    }

    $task = $tasks[0];

    $stage = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'project.task.type', 'read', [$task['stage_id'][0]], ['fields' => []]);
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $stage = (' . print_r($stage, true)) . ')';
    if (count($stage) == 0) {
      return false;
    }

    $categories = [];
    if (isset($task['tag_ids'])) {
      $categories = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'project.tags', 'read', [$task['tag_ids']], ['fields' => []]);
    }
    if (isset($task['categ_ids'])) {
      $categories = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'project.tags', 'read', [$task['categ_ids']], ['fields' => []]);
    }
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $categories = (' . print_r($categories, true)) . ')';

    $message = new SyncTask();

    $body = $task['description'];
    $message->bodytruncated = false;
    if(strlen($body) > $truncsize) {
      $body = Utils::Utf8_truncate($body, $truncsize);
      $message->bodytruncated = true;
    }
    $message->body = str_replace("\n", "\r\n", str_replace("\r", "", $body));
    $message->asbody = new SyncBaseBody();
    $message->asbody->data = $body;

    $message->complete = 0;
    if ($stage['fold']) {
      $message->complete = 1;
      $message->datecompleted = strtotime($task['date_last_stage_update']);
    }

    if ($task['date_deadline']) {
      $message->duedate = strtotime($task['date_deadline']);
      $message->utcduedate = strtotime($task['date_deadline']);
    }

    $message->importance = intval($task['priority']);
    $message->sensitivity = 0;
    $message->startdate = strtotime($task['date_start']);
    $message->utcstartdate = strtotime($task['date_start']);
    $message->subject = $task['name'];

    $message->categories = array_map(function ($category) {
      return $category['name'];
    }, $categories);

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $message = (' . print_r($message, true) . ')');
    return $message;
  }

  public function StatMessage($folderid, $id) {
    if ($folderid == 'calendar') {
      $events = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'calendar.event', 'search_read', [[
          ['id', '=', intval(substr($id, 6))]
        ]], [
          'fields' => ['id', 'write_date']
        ]
      );

      if (!count($events)) return false;
      $event = $events[0];

      return [
        'id' => 'event_' . $event['id'],
        'mod' => strtotime($event['write_date']),
        'flags' => 1
      ];
    }
    else if ($folderid == 'partners') {
      $partners = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'res.partner', 'search_read', [[
          ['id', '=', intval(substr($id, 8))]
        ]], [
          'fields' => ['id', 'write_date']
        ]
      );

      if (!count($partners)) return false;
      $partner = $partners[0];

      return [
        'id' => 'partner_' . $partner['id'],
        'mod' => strtotime($partner['write_date']),
        'flags' => 1
      ];
    }
    else if ($folderid == 'tasks') {
      $tasks = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'project.task', 'search_read', [[
          ['id', '=', intval(substr($id, 5))]
        ]], [
          'fields' => ['id', 'write_date']
        ]
      );

      if (!count($tasks)) return false;
      $task = $tasks[0];

      return [
        'id' => 'task_' . $task['id'],
        'mod' => strtotime($task['write_date']),
        'flags' => 1
      ];
    }

    return false;
  }

	public function ChangeMessage($folderid, $id, $message, $contentParameters) {
    if ($folderid == 'calendar') {
      return $this->ChangeEvent($folderid, $id, $message, $contentParameters);
    }

    return false;
  }

  protected function ChangeEvent($folderid, $id, $message, $contentParameters) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::ChangeEvent: message = (' . print_r($message, true)) . ')';

    $starttime = $message->starttime - $this->_getTimezoneOffset($this->utz)
      + _getOffsetFromTimezoneString($message->timezone);
    $stop = $message->endtime - $this->_getTimezoneOffset($this->utz)
      + _getOffsetFromTimezoneString($message->timezone);

    $vals = [
      'start' => date("Y-m-d H:i:s", $starttime),
      'name' => $message->subject,
      //organizer
      'location' => $message->location,
      'stop' => date("Y-m-d H:i:s", $stop)
    ];
    //recurrence
    if ($message->recurrence) {
      $vals['recurrency'] = true;
      $recurrence = $message->recurrence;

      switch ($recurrence->type) {
        case '0':
          $vals['rrule_type'] = 'daily';
          break;
        case 1:
          $vals['rrule_type'] = 'weekly';
          break;
        case 2:
          $vals['rrule_type'] = 'monthly';
          $vals['day'] = intval($recurrence->dayofmonth);
          break;
        case 3:
          $vals['rrule_type'] = 'monthly';
          $vals['month_by'] = 'day';
          break;
        case 5:
          $vals['rrule_type'] = 'yearly';
          break;
      }

      $vals['final_date'] = date("Y-m-d H:i:s", $message->until);
      $vals['count'] = intval($recurrence->occurrences);
      $vals['interval'] = intval($recurrence->interval);

      $daynum = [
        'su' => 1,
        'mo' => 2,
        'tu' => 4,
        'we' => 8,
        'th' => 16,
        'fr' => 32,
        'sa' => 64
      ];
      //dayofweek
      foreach ($daynum as $day => $number) {
        $vals[$day] = false;
        if (($recurrence->dayofweek & $number) == $number) $vals[$day] = true;
      }

      $vals['byday'] = $recurrence->weekofmonth == 5 ? -1 : intval($recurrence->weekofmonth);

      foreach ($daynum as $day => $number) {
        if (($recurrence->dayofweek & $number) == $number) $vals['week_list'] = strtoupper($day);
      }
    }

    $vals['class'] = [
      0 => 'public',
      1 => 'private',
      2 => 'private',
      3 => 'confidential'
    ][$message->sensitivity];

    $vals['show_as'] = [
      0 => 'free',
      2 => 'busy'
    ][$message->busystatus];

    $vals['allday'] = boolval($message->alldayevent);

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::ChangeEvent: vals = (' . print_r($vals, true)) . ')';

    if ($id) {
      $eventID = intval(substr($id, 6));
      $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'calendar.event', 'write', [[$eventID], $vals]
      );
    }
    else {
      $id = intval($this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'calendar.event', 'create', [$vals]
      ));
      $eventID = 'event_' . $id;
    }

    $stat = [
      'id' => $eventID,
      'mod' => "*",
      'flags' => 1
    ];
    #$stat = $this->StatMessage($folderid, $id);
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::ChangeEvent: eventID = (' . $eventID . ')');
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::ChangeEvent: stat = (' . print_r($stat, true)) . ')';
    return $stat;
  }

	public function SetReadFlag($folderid, $id, $flags, $contentParameters) {
    return false;
  }

	public function DeleteMessage($folderid, $id, $contentParameters) {
    return false;
  }

	public function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
    return false;
  }

  protected function _getUTC() {
    return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
  }

  private function _getOffsetFromTimezoneString($tz_string) {
    //Get a list of all timezones
    $identifiers = DateTimeZone::listIdentifiers();
    //Try the default timezone first
    array_unshift($identifiers, date_default_timezone_get());
    foreach ($identifiers as $tz) {
        $str = $this->_getTimezoneString($tz, false);
        if ($str == $tz_string) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->_GetTimezoneFromString(): Found timezone: '%s'.", $tz));
            return $this->_getTimezoneOffset($tz);
        }
    }
    return 0;
  }

  /**
   * Generate ActiveSync Timezone Packed String.
   * @param string $timezone
   * @param string $with_names
   * @throws Exception
   * @copyright see https://github.com/fmbiete/Z-Push-contrib/blob/master/backend/caldav/caldav.php
   */
  //This returns a timezone that matches the timezonestring.
  //We can't be sure this is the one you chose, as multiple timezones have same timezonestring
  protected function _getTimezoneString($timezone, $with_names = true) {
    // UTC needs special handling
    if ($timezone == "UTC")
      return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
    try {
      //Generate a timezone string (PHP 5.3 needed for this)
      $timezone = new DateTimeZone($timezone);
      $trans = $timezone->getTransitions(time());
      $stdTime = null;
      $dstTime = null;
      if (count($trans) < 3) {
          throw new Exception();
      }
      if ($trans[1]['isdst'] == 1) {
          $dstTime = $trans[1];
          $stdTime = $trans[2];
      }
      else {
          $dstTime = $trans[2];
          $stdTime = $trans[1];
      }
      $stdTimeO = new DateTime($stdTime['time']);
      $stdFirst = new DateTime(sprintf("first sun of %s %s", $stdTimeO->format('F'), $stdTimeO->format('Y')), timezone_open("UTC"));
      $stdBias = $stdTime['offset'] / -60;
      $stdName = $stdTime['abbr'];
      $stdYear = 0;
      $stdMonth = $stdTimeO->format('n');
      $stdWeek = floor(($stdTimeO->format("j")-$stdFirst->format("j"))/7)+1;
      $stdDay = $stdTimeO->format('w');
      $stdHour = $stdTimeO->format('H');
      $stdMinute = $stdTimeO->format('i');
      $stdTimeO->add(new DateInterval('P7D'));
      if ($stdTimeO->format('n') != $stdMonth) {
          $stdWeek = 5;
      }
      $dstTimeO = new DateTime($dstTime['time']);
      $dstFirst = new DateTime(sprintf("first sun of %s %s", $dstTimeO->format('F'), $dstTimeO->format('Y')), timezone_open("UTC"));
      $dstName = $dstTime['abbr'];
      $dstYear = 0;
      $dstMonth = $dstTimeO->format('n');
      $dstWeek = floor(($dstTimeO->format("j")-$dstFirst->format("j"))/7)+1;
      $dstDay = $dstTimeO->format('w');
      $dstHour = $dstTimeO->format('H');
      $dstMinute = $dstTimeO->format('i');
      $dstTimeO->add(new DateInterval('P7D'));
      if ($dstTimeO->format('n') != $dstMonth) {
        $dstWeek = 5;
      }
      $dstBias = ($dstTime['offset'] - $stdTime['offset']) / -60;
      if ($with_names) {
        return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, $stdName, 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, $dstName, 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
      }
      else {
        return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, '', 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, '', 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
      }
    }
    catch (Exception $e) {
      // If invalid timezone is given, we return UTC
      return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
    }
    return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
  }

  protected function _getTimezoneOffset($tz) {
    $dateTZ = new DateTimeZone($tz);
    $now = new DateTime("now", $dateTZ);
    return $dateTZ->getOffset($now);
  }
}
?>
