<?php
require_once dirname(__FILE__).'/accesscheck.php';

if (!$GLOBALS["commandline"]) {
  ob_end_flush();
  if (!MANUALLY_PROCESS_BOUNCES) {
    print $GLOBALS['I18N']->get("This page can only be called from the commandline");
    return;
  }
} else {
  ob_end_clean();
  print ClineSignature();
  ob_start();
}

print '<script language="Javascript" src="js/progressbar.js" type="text/javascript"></script>';
flush();
$outputdone =0;
function prepareOutput() {
  global $outputdone;
  if (!$outputdone) {
    $outputdone = 1;
    return formStart('name="outputform" class="processbounces" ').'<textarea name="output" rows=10 cols=50></textarea></form>';
  }
}

$report = "";
## some general functions
function finish ($flag,$message) {
  if ($flag == "error") {
    $subject = $GLOBALS['I18N']->get("Bounce processing error");
  } elseif ($flag == "info") {
    $subject = $GLOBALS['I18N']->get("Bounce Processing info");
  }
  if (!TEST && $message) {
    sendReport($subject,$message);
  }
}

function ProcessError ($message) {
  output( "$message");
  finish('error',$message);
  exit;
}

function processbounces_shutdown() {
  global $report,$process_id;
  releaseLock($process_id);
 # $report .= "Connection status:".connection_status();
  finish('info',$report);
}

function output ($message,$reset = 0) {
  $infostring = "[". date("D j M Y H:i",time()) . "] [" . getenv("REMOTE_HOST") ."] [" . getenv("REMOTE_ADDR") ."]";
  #print "$infostring $message<br/>\n";
  $message = preg_replace("/\n/",'',$message);
  ## contribution from http://forums.phplist.com/viewtopic.php?p=14648
  ## in languages with accented characters replace the HTML back
  //Replace the "&rsquo;" which is not replaced by html_decode
  $message = preg_replace("/&rsquo;/","'",$message); 
  //Decode HTML chars
  #$message = html_entity_decode($message,ENT_QUOTES,$_SESSION['adminlanguage']['charset']);
  $message = html_entity_decode($message,ENT_QUOTES,'UTF-8');
  if ($GLOBALS["commandline"]) {
    cl_output($message);
  } else {
    if ($reset)
      print '<script language="Javascript" type="text/javascript">
//        if (document.forms[0].name == "outputform") {
          document.outputform.output.value = "";
          document.outputform.output.value += "\n";
//        }
      </script>'."\n";

    print '<script language="Javascript" type="text/javascript">
//      if (document.forms[0].name == "outputform") {
        document.outputform.output.value += "'.$message.'";
        document.outputform.output.value += "\n";
//      } else
//        document.writeln("'.$message.'");
    </script>'."\n";
  }

  flush();
}

function findMessageId($text) {
  $msgid = 0;
  preg_match ("/X-MessageId: (.*)\R/iU",$text,$match);
  if (is_array($match) && isset($match[1])) {
    $msgid = trim($match[1]);
  }
  if (!$msgid) {
    # older versions use X-Message
    preg_match ("/X-Message: (.*)\R/iU",$text,$match);
    if (is_array($match) && isset($match[1])) {
      $msgid = trim($match[1]);
    }
  }
  return $msgid;
}

function findUserID($text) {
  global $tables;
  $userid = 0;
  $user = '';
  preg_match ("/X-ListMember: (.*)\R/iU",$text,$match);
  if (is_array($match) && isset($match[1])) {
    $user = trim($match[1]);
  }
  if (empty($user)) {
    # older version use X-User
    preg_match ("/X-User: (.*)\R/iU",$text,$match);
    if (is_array($match) && isset($match[1])) {
      $user = trim($match[1]);
    }
  }

  # some versions used the email to identify the users, some the userid and others the uniqid
  # use backward compatible way to find user
  if (strpos($user,'@') !== false) {
    $userid_req = Sql_Fetch_Row_Query(sprintf('select id from %s where email = "%s"',$tables["user"],sql_escape($user)));
    $userid = $userid_req[0];
  } elseif (preg_match("/^\d$/",$user)) {
    $userid = $user;
  } elseif (!empty($user)) {
    $userid_req = Sql_Fetch_Row_Query(sprintf('select id from %s where uniqid = "%s"',$tables["user"],sql_escape($user)));
    $userid = $userid_req[0];
  }
  
  ### if we didn't find any, parse anything looking like an email address and check if it's a subscriber.
  ## this is probably fairly time consuming, but as the process is only done once every so often
  ## that should not be too bad
  
  preg_match_all('/[\S]+@[\S\.]+/',$text,$regs);
  foreach ($regs[0] as $match) {
    $email = cleanEmail($match);
    $useridQ = Sql_Fetch_Row_Query(sprintf('select id from %s where email = "%s"',$tables['user'],sql_escape($email)));
    if (!empty($useridQ[0])) {
      $userid = $useridQ[0]; 
      break;
    }
  }
  
  return $userid;
}  

function decodeBody($header,$body) {
  $transfer_encoding = '';
  if (preg_match('/Content-Transfer-Encoding: ([\w-]+)/i',$header,$regs)) {
    $transfer_encoding = strtolower($regs[1]);
  }
  switch ($transfer_encoding) {
    case 'quoted-printable':
      $decoded_body = @imap_qprint($body);break;
    case 'base64': 
      $decoded_body = @imap_base64($body);break;
    case '7bit':
    case '8bit':
    default:
     # $body = $body;
  }
  if (!empty($decoded_body)) {
    return $decoded_body;
  } else {
    return $body;
  }
}

function processImapBounce ($link,$num,$header) {
  global $tables;
  $headerinfo = imap_headerinfo($link,$num);
  $bounceDate = @strtotime($headerinfo->date);
  $body = imap_body ($link,$num);
  $body = decodeBody($header,$body);
 
  $msgid = findMessageId($body);
  $userid = findUserID($body);
  if (VERBOSE) {
    output("UID".$userid." MSGID".$msgid);
  }
  
  ## @TODO add call to plugins to determine what to do.
  # for now, quick hack to zap MsExchange Delayed messages
  if (preg_match('/Action: delayed\s+Status: 4\.4\.7/im',$body)) {
    ## just say we did something, when actually we didn't
    return true;
  }

  Sql_Query(sprintf('insert into %s (date,header,data)
    values("%s","%s","%s")',
    $tables["bounce"],
    date("Y-m-d H:i",$bounceDate),
    addslashes($header),
    addslashes($body)));

  $bounceid = Sql_Insert_Id($tables['bounce'], 'id');
  
  return processBounceData($bounceid,$msgid,$userid);
}
 
function processBounceData($bounceid,$msgid,$userid) {
  global $tables;
  $useremailQ = Sql_fetch_row_query(sprintf('select email from %s where id = %d',$tables['user'],$userid));
  $useremail = $useremailQ[0];
  if ($msgid === "systemmessage" && !empty($userid)) {
    Sql_Query(sprintf('update %s
      set status = "bounced system message",
      comment = "%s marked unconfirmed"
      where id = %d',
      $tables["bounce"],
      $userid,$bounceid));
     logEvent("$userid ".$GLOBALS['I18N']->get("system message bounced, user marked unconfirmed"));
     addUserHistory($useremail,$GLOBALS['I18N']->get("Bounced system message"),"
    <br/>".$GLOBALS['I18N']->get("User marked unconfirmed")."
    <br/><a href=\"./?page=bounce&amp;id=$bounceid\">".$GLOBALS['I18N']->get("View Bounce")."</a>

    ");
    Sql_Query(sprintf('update %s
      set confirmed = 0
      where id = %d',
      $tables["user"],
      $userid));
  } elseif (!empty($msgid) && !empty($userid)) {
    ## check if we already have this um as a bounce
    ## so that we don't double count "delayed" like bounces
    $exists = Sql_Fetch_Row_Query(sprintf('select count(*) from %s where user = %d and message = %d',
      $tables["user_message_bounce"],$userid,$msgid));
    if (empty($exists[0])) {
      Sql_Query(sprintf('insert into %s
        set user = %d, message = %d, bounce = %d',
        $tables["user_message_bounce"],
        $userid,$msgid,$bounceid));
      Sql_Query(sprintf('update %s
        set status = "bounced list message %d",
        comment = "%s bouncecount increased"
        where id = %d',
        $tables["bounce"],
        $msgid,
        $userid,$bounceid));
      Sql_Query(sprintf('update %s
        set bouncecount = bouncecount + 1
        where id = %d',
        $tables["message"],
        $msgid));
      Sql_Query(sprintf('update %s
        set bouncecount = bouncecount + 1
        where id = %d',
        $tables["user"],
        $userid));
    } else {
      ## we create the relationship, but don't increase counters
      Sql_Query(sprintf('insert into %s
        set user = %d, message = %d, bounce = %d',
        $tables["user_message_bounce"],
        $userid,$msgid,$bounceid));
      Sql_Query(sprintf('update %s
        set status = "bounced list message %d",
        comment = "duplicate bounce for %d"
        where id = %d',
        $tables["bounce"],
        $msgid,
        $userid,$bounceid));
    }
  } elseif ($userid) {
    Sql_Query(sprintf('update %s
      set status = "bounced unidentified message",
      comment = "%s bouncecount increased"
      where id = %d',
      $tables["bounce"],
      $userid,$bounceid));
    Sql_Query(sprintf('update %s
      set bouncecount = bouncecount + 1
      where id = %d',
      $tables["user"],
      $userid));
  } elseif ($msgid === 'systemmessage') {
    Sql_Query(sprintf('update %s
      set status = "bounced system message",
      comment = "unknown user"
      where id = %d',
      $tables["bounce"],
      $bounceid));
     logEvent("$userid ".$GLOBALS['I18N']->get("system message bounced, but unknown user"));
  } elseif ($msgid) {
    Sql_Query(sprintf('update %s
      set status = "bounced list message %d",
      comment = "unknown user"
      where id = %d',
      $tables["bounce"],
      $msgid,
      $bounceid));
    Sql_Query(sprintf('update %s
      set bouncecount = bouncecount + 1
      where id = %d',
      $tables["message"],
      $msgid));
  } else {
    Sql_Query(sprintf('update %s
      set status = "unidentified bounce",
      comment = "not processed"
      where id = %d',
      $tables["bounce"],
      $bounceid));
     return false;
  }
  return true;
}

function processPop ($server,$user,$password) {
  $port =  $GLOBALS["bounce_mailbox_port"];
  if (!$port) {
    $port = '110/pop3/notls';
  }
  set_time_limit(6000);

  if (!TEST) {
    $link=imap_open("{".$server.":".$port."}INBOX",$user,$password,CL_EXPUNGE);
  } else {
    $link=imap_open("{".$server.":".$port."}INBOX",$user,$password);
  }

  if (!$link) {
    output($GLOBALS['I18N']->get("Cannot create POP3 connection to")." $server: ".imap_last_error());
    return;
  }
  return processMessages($link,100000);
}

function processMbox ($file) {
  set_time_limit(6000);

  if (!TEST) {
    $link=imap_open($file,"","",CL_EXPUNGE);
  } else {
    $link=imap_open($file,"","");
  }
  if (!$link) {
    output($GLOBALS['I18N']->get("Cannot open mailbox file")." ".imap_last_error());
    return;
  }
  return processMessages($link,100000);
}

function processMessages($link,$max = 3000) {
  global $bounce_mailbox_purge_unprocessed,$bounce_mailbox_purge;
  $num = imap_num_msg($link);
  output( $num . " ".$GLOBALS['I18N']->get("bounces to fetch from the mailbox")."\n");
  output( $GLOBALS['I18N']->get("Please do not interrupt this process")."\n");
  $report = $num . " ".$GLOBALS['I18N']->get("bounces to process")."\n";
  if ($num > $max) {
    print $GLOBALS['I18N']->get("Processing first")." $max ".$GLOBALS['I18N']->get("bounces")."<br/>";
    $report .= $num . " ".$GLOBALS['I18N']->get("processing first")." $max ".$GLOBALS['I18N']->get("bounces")."\n";
    $num = $max;
  }
  if (TEST) {
    print $GLOBALS['I18N']->get("Running in test mode, not deleting messages from mailbox")."<br/>";
  } else {
    print $GLOBALS['I18N']->get("Processed messages will be deleted from mailbox")."<br/>";
  }
  $nberror = 0;
#  for ($x=1;$x<150;$x++) {
  for($x=1; $x <= $num; $x++) {
    set_time_limit(60);
    $header = imap_fetchheader($link,$x);
    if ($x % 25 == 0)
  #    output( $x . " ". nl2br($header));
      output($x . " done",1);
    print "\n";
    flush();
    $processed = processImapBounce($link,$x,$header);
    if ($processed) {
      if (!TEST && $bounce_mailbox_purge) {
        if (VERBOSE) output( $GLOBALS['I18N']->get("Deleting message")." $x");
        imap_delete($link,$x);
      } elseif (VERBOSE) {
        output(s("Not deleting processed message")." $x $bounce_mailbox_purge");
      }
    } else {
      if (!TEST && $bounce_mailbox_purge_unprocessed) {
        if (VERBOSE) output( $GLOBALS['I18N']->get("Deleting message")." $x");
        imap_delete($link,$x);
      } elseif (VERBOSE) {
        output(s("Not deleting unprocessed message")." $x");
      }
    }
    flush();
  }
  flush();
  output($GLOBALS['I18N']->get("Closing mailbox, and purging messages"));
  set_time_limit(60 * $num);
  imap_close($link);
#  print '<script language="Javascript" type="text/javascript"> finish(); </script>';
  if ($num)
    return $report;
}

if (!function_exists('imap_open')) {
  Error($GLOBALS['I18N']->get('IMAP is not included in your PHP installation, cannot continue').
  '<br/>'.$GLOBALS['I18N']->get('Check out').
  ' <a href="http://www.php.net/manual/en/ref.imap.php">http://www.php.net/manual/en/ref.imap.php</a>');
  return;
}

if (empty($bounce_mailbox) && (empty($bounce_mailbox_host) || empty($bounce_mailbox_user) || empty($bounce_mailbox_password))) {
  Error($GLOBALS['I18N']->get("Bounce mechanism not properly configured"));
  return;
}

print '<script language="Javascript" type="text/javascript"> yposition = 10;document.write(progressmeter); start();</script>';
print prepareOutput();
flushBrowser();

# lets not do this unless we do some locking first
register_shutdown_function('processbounces_shutdown');
$abort = ignore_user_abort(1);
if (!empty($GLOBALS['commandline']) && isset($cline['f'])) {
  # force set, so kill other processes
  cl_output('Force set, killing other send processes');
  $process_id = getPageLock(1);
} else {
  $process_id = getPageLock();
}
if (empty($process_id)) {
  return;
}
$download_report = '';
switch ($bounce_protocol) {
  case "pop":
    $download_report =  processPop ($bounce_mailbox_host,$bounce_mailbox_user,$bounce_mailbox_password);
    break;
  case "mbox":
    $download_report = processMbox($bounce_mailbox);
    break;
  default:
    Error($GLOBALS['I18N']->get("bounce_protocol not supported"));
    return;
}

# now we have filled database with all available bounces

## reprocess the unidentified ones, as the bounce detection has improved, so it might catch more

cl_output('reprocessing');
$reparsed = $count = 0;
$reidentified = 0;
$req = Sql_Query(sprintf('select * from %s where status = "unidentified bounce"',$tables['bounce']));
$total = Sql_Affected_Rows();
cl_output(s('%d bounces to reprocess',$total));
while ($bounce = Sql_Fetch_Assoc($req)) {
  $count++;
  if ($count % 25 == 0) {
    cl_progress(s('%d out of %d processed',$count,$total));
  }
  $bounceBody = decodeBody($bounce['header'],$bounce['data']);
  $userid = findUserID($bounceBody);
  $messageid = findMessageId($bounceBody);
  if (!empty($userid) || !empty($messageid)) {
    $reparsed++;
    if (processBounceData($bounce['id'],$messageid,$userid)) {
      $reidentified++;
    }
  }
}
cl_output(s('%d out of %d processed',$count,$total));
if (VERBOSE) {
  output(s('%d bounces were re-processed and %d bounces were re-identified',$reparsed,$reidentified));
}
$advanced_report = '';
if (USE_ADVANCED_BOUNCEHANDLING) {
  output($GLOBALS['I18N']->get('Processing bounces based on active bounce rules'));
  $bouncerules = loadBounceRules();
  $matched = 0;
  $notmatched = 0;
  $limit =  ' limit 10';
  $limit =  ' limit 10000';
  $limit = '';
# @@ not sure whether this one would make sense
#  $req = Sql_Query(sprintf('select * from %s as bounce, %s as umb,%s as user where bounce.id = umb.bounce
#    and user.id = umb.user and !user.confirmed and !user.blacklisted %s',
#    $GLOBALS['tables']['bounce'],$GLOBALS['tables']['user_message_bounce'],$GLOBALS['tables']['user'],$limit));
  $req = Sql_Query(sprintf('select * from %s as bounce, %s as umb where bounce.id = umb.bounce %s',
    $GLOBALS['tables']['bounce'],$GLOBALS['tables']['user_message_bounce'],$limit));
  while ($row = Sql_Fetch_Array($req)) {
    $alive = checkLock($process_id);
    if ($alive)
      keepLock($process_id);
    else
      ProcessError($GLOBALS['I18N']->get("Process Killed by other process"));
#    output('User '.$row['user']);
    $rule = matchBounceRules($row['data'],$bouncerules);
#    output('Action '.$rule['action']);
#    output('Rule'.$rule['id']);
    $userdata = array();
    if ($rule && is_array($rule)) {
      if ($row['user']) {
        $userdata = Sql_Fetch_Array_Query("select * from {$tables["user"]} where id = ".$row['user']);
      }
      $report_linkroot = $GLOBALS['admin_scheme'].'://'.$GLOBALS['website'].$GLOBALS['adminpages'];

      Sql_Query(sprintf('update %s set count = count + 1 where id = %d',
        $GLOBALS['tables']['bounceregex'],$rule['id']));
      Sql_Query(sprintf('insert ignore into %s (regex,bounce) values(%d,%d)',
        $GLOBALS['tables']['bounceregex_bounce'],$rule['id'],$row['bounce']));
      
      switch ($rule['action']) {
        case 'deleteuser':
          logEvent('User '.$userdata['email'].' deleted by bounce rule '.PageLink2('bouncerule&amp;id='.$rule['id'],$rule['id']));
          $advanced_report .= 'User '.$userdata['email'].' deleted by bounce rule '.$rule['id']."\n";
          $advanced_report .= 'User: '.$report_linkroot.'/?page=user&amp;id='.$userdata['id']."\n";
          $advanced_report .= 'Rule: '.$report_linkroot.'/?page=bouncerule&amp;id='.$rule['id']."\n";
          deleteUser($row['user']);
          break;
        case 'unconfirmuser':
          logEvent('User '.$userdata['email'].' unconfirmed by bounce rule '.PageLink2('bouncerule&amp;id='.$rule['id'],$rule['id']));
          Sql_Query(sprintf('update %s set confirmed = 0 where id = %d',$GLOBALS['tables']['user'],$row['user']));
          $advanced_report .= 'User '.$userdata['email'].' made unconfirmed by bounce rule '.$rule['id']."\n";
          $advanced_report .= 'User: '.$report_linkroot.'/?page=user&amp;id='.$userdata['id']."\n";
          $advanced_report .= 'Rule: '.$report_linkroot.'/?page=bouncerule&amp;id='.$rule['id']."\n";
          addUserHistory($userdata['email'],s('Auto Unconfirmed'),s('Subscriber auto unconfirmed for')." ".s('bounce rule').' '.$rule['id']);
          addSubscriberStatistics('auto unsubscribe',1);
          break;
        case 'deleteuserandbounce':
          logEvent('User '.$row['user'].' deleted by bounce rule '.PageLink2('bouncerule&amp;id='.$rule['id'],$rule['id']));
          $advanced_report .= 'User '.$userdata['email'].' deleted by bounce rule '.$rule['id']."\n";
          $advanced_report .= 'User: '.$report_linkroot.'/?page=user&amp;id='.$userdata['id']."\n";
          $advanced_report .= 'Rule: '.$report_linkroot.'/?page=bouncerule&amp;id='.$rule['id']."\n";
          deleteUser($row['user']);
          deleteBounce($row['bounce']);
          break;
        case 'unconfirmuseranddeletebounce':
          logEvent('User '.$userdata['email'].' unconfirmed by bounce rule '.PageLink2('bouncerule&amp;id='.$rule['id'],$rule['id']));
          Sql_Query(sprintf('update %s set confirmed = 0 where id = %d',$GLOBALS['tables']['user'],$row['user']));
          $advanced_report .= 'User '.$userdata['email'].' made unconfirmed by bounce rule '.$rule['id']."\n";
          $advanced_report .= 'User: '.$report_linkroot.'/?page=user&amp;id='.$userdata['id']."\n";
          $advanced_report .= 'Rule: '.$report_linkroot.'/?page=bouncerule&amp;id='.$rule['id']."\n";
          addUserHistory($userdata['email'],s('Auto unconfirmed'),s('Subscriber auto unconfirmed for')." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          addSubscriberStatistics('auto unsubscribe',1);
          deleteBounce($row['bounce']);
          break;
        case 'blacklistuser':
          logEvent('User '.$userdata['email'].' blacklisted by bounce rule '.PageLink2('bouncerule&amp;id='.$rule['id'],$rule['id']));
          addUserToBlacklist($userdata['email'],$GLOBALS['I18N']->get("Auto Blacklisted"),$GLOBALS['I18N']->get("User auto blacklisted for")." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          $advanced_report .= 'User '.$userdata['email'].' blacklisted by bounce rule '.$rule['id']."\n";
          $advanced_report .= 'User: '.$report_linkroot.'/?page=user&amp;id='.$userdata['id']."\n";
          $advanced_report .= 'Rule: '.$report_linkroot.'/?page=bouncerule&amp;id='.$rule['id']."\n";
          addUserHistory($userdata['email'],$GLOBALS['I18N']->get("Auto Unsubscribed"),$GLOBALS['I18N']->get("User auto unsubscribed for")." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          addSubscriberStatistics('auto blacklist',1);
          break;
        case 'blacklistuseranddeletebounce':
          logEvent('User '.$userdata['email'].' blacklisted by bounce rule '.PageLink2('bouncerule&amp;id='.$rule['id'],$rule['id']));
          addUserToBlacklist($userdata['email'],$GLOBALS['I18N']->get("Auto Blacklisted"),$GLOBALS['I18N']->get("User auto blacklisted for")." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          $advanced_report .= 'User '.$userdata['email'].' blacklisted by bounce rule '.$rule['id']."\n";
          $advanced_report .= 'User: '.$report_linkroot.'/?page=user&amp;id='.$userdata['id']."\n";
          $advanced_report .= 'Rule: '.$report_linkroot.'/?page=bouncerule&amp;id='.$rule['id']."\n";
          addUserHistory($userdata['email'],$GLOBALS['I18N']->get("Auto Unsubscribed"),$GLOBALS['I18N']->get("User auto unsubscribed for")." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          addSubscriberStatistics('auto blacklist',1);
          deleteBounce($row['bounce']);
          break;
        case 'blacklistemail':
          logEvent('email '.$userdata['email'].' blacklisted by bounce rule '.PageLink2('bouncerule&amp;id='.$rule['id'],$rule['id']));
          addEmailToBlackList($userdata['email'],$GLOBALS['I18N']->get("Auto Blacklisted"),$GLOBALS['I18N']->get("email auto blacklisted for")." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          $advanced_report .= 'email '.$userdata['email'].' blacklisted by bounce rule '.$rule['id']."\n";
          $advanced_report .= 'User: '.$report_linkroot.'/?page=user&amp;id='.$userdata['id']."\n";
          $advanced_report .= 'Rule: '.$report_linkroot.'/?page=bouncerule&amp;id='.$rule['id']."\n";
          addUserHistory($userdata['email'],$GLOBALS['I18N']->get("Auto Unsubscribed"),$GLOBALS['I18N']->get("email auto unsubscribed for")." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          addSubscriberStatistics('auto blacklist',1);
          break;
        case 'blacklistemailanddeletebounce':
          logEvent('email '.$userdata['email'].' blacklisted by bounce rule '.PageLink2('bouncerule&amp;id='.$rule['id'],$rule['id']));
          addEmailToBlackList($userdata['email'],$GLOBALS['I18N']->get("Auto Blacklisted"),$GLOBALS['I18N']->get("User auto blacklisted for")." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          $advanced_report .= 'email '.$userdata['email'].' blacklisted by bounce rule '.$rule['id']."\n";
          $advanced_report .= 'User: '.$report_linkroot.'/?page=user&amp;id='.$userdata['id']."\n";
          $advanced_report .= 'Rule: '.$report_linkroot.'/?page=bouncerule&amp;id='.$rule['id']."\n";
          addUserHistory($userdata['email'],$GLOBALS['I18N']->get("Auto Unsubscribed"),$GLOBALS['I18N']->get("User auto unsubscribed for")." ".$GLOBALS['I18N']->get("bounce rule").' '.$rule['id']);
          addSubscriberStatistics('auto blacklist',1);
          deleteBounce($row['bounce']);
          break;
        case 'deletebounce':
          deleteBounce($row['bounce']);
          break;
      }

      $matched++;
    } else {
      $notmatched++;
    }
  }
  output($matched.' '.$GLOBALS['I18N']->get('bounces processed by advanced processing'));
  output($notmatched.' '.$GLOBALS['I18N']->get('bounces were not matched by advanced processing rules'));
}

# have a look who should be flagged as unconfirmed
output($GLOBALS['I18N']->get("Identifying consecutive bounces"));

# we only need users who are confirmed at the moment
$userid_req = Sql_query(sprintf('select distinct %s.user from %s,%s
  where %s.id = %s.user and %s.confirmed',
  $tables["user_message_bounce"],
  $tables["user_message_bounce"],
  $tables["user"],
  $tables["user"],
  $tables["user_message_bounce"],
  $tables["user"]
));
$total = Sql_Affected_Rows();
if (!$total)
  output($GLOBALS['I18N']->get("Nothing to do"));

$usercnt = 0;
$unsubscribed_users = "";
while ($user = Sql_Fetch_Row($userid_req)) {
  keepLock($process_id);
  set_time_limit(600);
  $msg_req = Sql_Query(sprintf('select * from
    %s um left join %s umb on (um.messageid = umb.message and userid = user)
    where userid = %d and um.status = "sent"
    order by entered desc',
    $tables["usermessage"],$tables["user_message_bounce"],
    $user[0]));
/*  $cnt = 0;
  $alive = 1;$removed = 0;
  while ($alive && !$removed && $bounce = Sql_Fetch_Array($msg_req)) {
    $alive = checkLock($process_id);
    if ($alive)
      keepLock($process_id);
    else
      ProcessError($GLOBALS['I18N']->get("Process Killed by other process"));
    if (sprintf('%d',$bounce["bounce"]) == $bounce["bounce"]) {
      $cnt++;
      if ($cnt >= $bounce_unsubscribe_threshold) {
        $removed = 1;
        output(sprintf('unsubscribing %d -> %d bounces',$user[0],$cnt));
        $userurl = PageLink2("user&amp;id=$user[0]",$user[0]);
        logEvent($GLOBALS['I18N']->get("User")." $userurl ".$GLOBALS['I18N']->get("has consecutive bounces")." ($cnt) ".$GLOBALS['I18N']->get("over threshold, user marked unconfirmed"));
        $emailreq = Sql_Fetch_Row_Query("select email from {$tables["user"]} where id = $user[0]");
        addUserHistory($emailreq[0],$GLOBALS['I18N']->get("Auto Unsubscribed"),$GLOBALS['I18N']->get("User auto unsubscribed for")." $cnt ".$GLOBALS['I18N']->get("consecutive bounces"));
        Sql_Query(sprintf('update %s set confirmed = 0 where id = %d',$tables["user"],$user[0]));
        addSubscriberStatistics('auto unsubscribe',1);
        $email_req = Sql_Fetch_Row_Query(sprintf('select email from %s where id = %d',$tables["user"],$user[0]));
        $unsubscribed_users .= $email_req[0] . " [$user[0]] ($cnt)\n";
      }
    } elseif ($bounce["bounce"] == "") {
      $cnt = 0;
    }
  }*/
  #$alive = 1;$removed = 0; DT 051105
  $cnt=0;
  $alive = 1;
  $removed = $msgokay = $unconfirmed = $unsubscribed = 0;
        #while ($alive && !$removed && $bounce = Sql_Fetch_Array($msg_req)) { DT 051105
  while ($alive && !$removed && !$msgokay && $bounce = Sql_Fetch_Array($msg_req)) {

    $alive = checkLock($process_id);
    if ($alive) {
      keepLock($process_id);
    } else {
      ProcessError("Process Killed by other process");
    }

    if (sprintf('%d',$bounce["bounce"]) == $bounce["bounce"]) {
      $cnt++;
      if ($cnt >= $bounce_unsubscribe_threshold) {
        if (!$unsubscribed) {
          output(sprintf('unsubscribing %d -> %d bounces',$user[0],$cnt));
          $userurl = PageLink2("user&amp;id=$user[0]",$user[0]);
          logEvent(s('User (url:%s) has consecutive bounces (%d) over threshold (%d), user marked unconfirmed',$userurl,$cnt,$bounce_unsubscribe_threshold));
          $emailreq = Sql_Fetch_Row_Query("select email from {$tables["user"]} where id = $user[0]");
          addUserHistory($emailreq[0],s('Auto Unconfirmed'),s('Subscriber auto unconfirmed for %d consecutive bounces',$cnt));
          Sql_Query(sprintf('update %s set confirmed = 0 where id = %d',$tables["user"],$user[0]));
          $email_req = Sql_Fetch_Row_Query(sprintf('select email from %s where id = %d',$tables["user"],$user[0]));
          $unsubscribed_users .= $email_req[0]."\t\t($cnt)\t\t". $GLOBALS['scheme'].'://'.getConfig('website').$GLOBALS['adminpages'].'/?page=user&amp;id='.$user[0]. "\n";
          $unsubscribed = 1;
        }
        if (BLACKLIST_EMAIL_ON_BOUNCE && $cnt >= BLACKLIST_EMAIL_ON_BOUNCE) {
          $removed = 1;
          #0012262: blacklist email when email bounces
          cl_output(s('%d consecutive bounces, threshold reached, blacklisting subscriber',$cnt));
          addEmailToBlackList($emailreq[0], s('%d consecutive bounces, threshold reached',$cnt));
        }
      }
    } elseif ($bounce["bounce"] == "") {
      #$cnt = 0; DT 051105
      $cnt = 0;
      $msgokay = 1; #DT 051105 - escaping loop if message received okay
    }
  }
  if ($usercnt % 5 == 0) {
#    output($GLOBALS['I18N']->get("Identifying consecutive bounces"));
    cl_progress(s('processed %d out of %d subscribers',$usercnt, $total),1);
  }
  $usercnt++;
  flush();
}
if (!$GLOBALS["commandline"]) {
  print '<script language="Javascript" type="text/javascript"> finish(); </script>';
}

#output($GLOBALS['I18N']->get("Identifying consecutive bounces"));
output("\n".s('total of %d subscribers processed',$total). '                            ');

$report = '';

if ($advanced_report) {
  $report .= $GLOBALS['I18N']->get('Report of advanced bounce processing:')."\n$advanced_report\n";
}
if ($unsubscribed_users) {
  $report .= "\n".$GLOBALS['I18N']->get("Below are users who have been marked unconfirmed. The in () is the number of consecutive bounces.")."\n";
  $report .= "\n$unsubscribed_users";
}
if ($report) {
  $report = $GLOBALS['I18N']->get("Report:") . "\n$download_report\n" . $report;
}
# shutdown will take care of reporting
#finish("info",$report);

# IMAP errors following when Notices are on are a PHP bug
# http://bugs.php.net/bug.php?id=7207

