<?php
  
  /*
   * Author:
   *    Abhinav Singh
   *
   * Contact:
   *    mailsforabhinav@gmail.com
   *    admin@abhinavsingh.com
   *
   * Site:
   *    http://abhinavsingh.com
   *    http://abhinavsingh.com/blog
   *
   * Source:
   *    http://code.google.com/p/jaxl
   *
   * About:
   *    JAXL stands for "Just Another XMPP Library"
   *    For geeks, JAXL stands for "Jabber XMPP Library"
   *    
   *    I wrote this library while developing Gtalkbots (http://gtalkbots.com)
   *    I have highly customized it to work with Gtalk Servers and inspite of
   *    production level usage at Gtalkbots, I recommend still not to use this
   *    for any live project.
   *    
   *    Feel free to add me in Gtalk and drop an IM.
   *
  */
  
  /*
   * ==================================== IMPORTANT =========================================
   * Until and Unless you are aware of XMPP protocol details, you should not edit this class.
   * This is the base XMPP class, which should be extended as JAXL class.
   * ==================================== IMPORTANT =========================================
  */
  
  /*
   * Available methods:
   *    __construct(), __destruct(), connect(), streamHandler()
   *    sendStream(), sendStatus(), sendEndStream(), sendPresence(), sendMessage(), sendXML()
   *    parseStream(), parseProceed(), parseSuccess(), parsePresence(), parseMessage(), parseIq(), parseChallenge()
   *    getFeatureList(), getIq(), getId(), getJid(), getTime(), getXML()
   *    bind(), splitXML(), subscribe(), eventMessage(), eventPresence(), explodeData(), implodeData()
   *    encryptPassword(), getBareJid(), splitJid(), roster(), setStatus()
  */
 
  declare(ticks=1);
 
  include_once("logger.class.php");
  include_once("mysql.class.php");
  include_once("xml.class.php");
  
  class XMPP {
    
    /*
     * __construct() method performs various initialization
    */
    function __construct() {
      $this->configure();

      if($this->phpLINUX && $this->phpPCNTL) {
        pcntl_signal(SIGINT, array($this, "shutdown"));
        pcntl_signal(SIGTERM, array($this, "shutdown"));
        print "OSType: Linux, Registering shutdown for SIGINT and SIGTERM\n";
      }
      else {
        print "OSType: Windows, Unable to register shutdown functions, try moving to a linux box\n";
      }

      if(!$this->phpOPENSSL) print "OpenSSL: Disabled for CLI, kindly enable if unable to authenticate\n";
      else print "OpenSSL: Enabled for CLI\n";

      $this->init();
    }
    
    /*
     * __destruct()
    */
    function __destruct() {
      $this->disconnect();
    }
    
    function configure() {
      $this->phpPCNTL = (extension_loaded('pcntl') === TRUE) ? TRUE : FALSE;
      $this->phpOPENSSL = (extension_loaded('openssl') === TRUE) ? TRUE : FALSE;
      $this->phpLINUX = (strtoupper(substr(PHP_OS, 0,3)) == "WIN") ? FALSE : TRUE;
    }

    function init() { 
      global $env, $key, $db, $logEnable, $logDB;
      
      $host = $key[$env]['host'];    // Jabber Server Hostname                
      $port = $key[$env]['port'];    // Jabber Server Port                    
      $user = $key[$env]['user'];    // Jabber User                           
      $pass = $key[$env]['pass'];    // Jabber Password                       
      $domain = $key[$env]['domain'];  // Jabber Domain                         
      $dbhost = $db[$env]['dbhost'];   // MySQL DB Host                         
      $dbname = $db[$env]['dbname'];   // MySQL DB Name                         
      $dbuser = $db[$env]['dbuser'];   // MySQL DB User                         
      $dbpass = $db[$env]['dbpass'];   // MySQL DB Pass                         
 
      $this->isConnected = FALSE;      
      $this->stream = NULL;
      $this->auth = NULL;
      $this->jid = NULL;
      $this->xmlBuffer = '';
      $this->timeout = 20;
      $this->lastid = 0;
      $this->lastSendTime = 0;
      $this->logEnable = $logEnable;
      $this->logDB = $logDB;
      $this->sessionRequired = FALSE;
      $this->secondChallenge = FALSE;
      $this->presenceType = array("unavailable", "subscribe", "subscribed", "unsubscribe", "unsubscribed", "probe", "error");
      
      $this->host = $host;
      $this->port = $port;
      $this->user = $user;
      $this->pass = $pass;
      $this->domain = $domain;
      $this->resource = "jaxl";
      $this->status = "Online using JAXL - Just Another XMPP Library";
      
      // Array that saves all roster list
      $this->rosterList = array();
      
      // This variable is set to TRUE when authentication, service discovery, roster request has taken place
      $this->done = FALSE;
      
      // Gmail Email related variables
      $this->resultTime = FALSE;

      // XEP-0199: XMPP Ping parameters
      $this->emptyResponses = 0;
     
      // XEP-0133: Service Administration
      $this->command = array();

      // Custom Jaxl library implementation for providing periodic job handlers
      $this->jobs = array();
      $this->jobsTick = 1;
 
      $this->logger = new Logger("Initializing class variables");
      if($this->logDB) { $this->mysql = new MySQL($dbhost,$dbname,$dbuser,$dbpass); }
      $this->xmlize = new XML();
    }

    /* 
     * connect() method which make a TCP connection $host:$post
    */
    function connect() {
      if(!$this->stream) {
        $this->logger->logger("Trying to connect at ".$this->host.":".$this->port);
        if($this->stream = @fsockopen($this->host,$this->port,$this->errorno,$this->errorstr,$this->timeout)) {
          $this->logger->logger("Connection made successfully at ".$this->host.":".$this->port);
          $this->isConnected = TRUE;
          
          stream_set_blocking($this->stream,0);
          stream_set_timeout($this->stream,3600*24);
          
          $this->sendStream();
        }
        else {
          $this->logger->logger("Failed to establish a connection at ".$this->host.":".$this->port);
          return FALSE;
        }
      }
      else {
        $this->logger->logger("Already connected, sending start stream again at ".$this->host.":".$this->port);
        $this->isConnected = TRUE;
        $this->sendStream();
      }
    }
   
    function disconnect() {
      $this->sendEndStream();
      $this->isConnected = FALSE;
    }

    function reconnect() {
      $this->disconnect();
      $this->init();
      $this->connect();
    }

    function shutdown() {
      $this->disconnect();
      exit;
    }
 
    /*
     * sendStream() method sends the initial stream to jabber server
    */
    function sendStream() {
      $xml = '<?xml version="1.0"?>';
      $xml .= '<stream:stream xmlns:stream="http://etherx.jabber.org/streams" version="1.0" xmlns="jabber:client" to="'.$this->domain.'" xml:lang="en" xmlns:xml="http://www.w3.org/XML/1998/namespace">';
      $this->sendXML($xml);
    }
    
    /*
     * sendEndStream() method sends the closing stream to jabber server
    */
    function sendEndStream() {
      $xml = '</stream:stream>';
      $this->sendXML($xml);
    }
    
    /*
     * sendXML() method is responsible for pushing all XML to jabber server
    */
    function sendXML($xml) {
      if($this->getTime() - $this->lastSendTime < .1) {
        usleep(100000);
      }
      if($this->logEnable) { $this->logger->logger("Sending XML>>\n".$xml); }
      $this->lastSendTime = $this->getTime();
      return fwrite($this->stream,$xml."\n");
    }
    
    /*
     * getXML() method fetches the incoming stream data
    */
    function getXML() {
      sleep(1);
      
      // $emptyLine counts number of empty reads from the server
      $emptyLine = 0;
      
      // $packetCount reads in number of packets read
      $packetCount = 0;
      
      // set $xml to previous stream residue
      $xml = $this->xmlBuffer;
      
      // set $xmlBuffer to '' after use
      $this->xmlBuffer = '';
      
      // Logic is to read the 2048 bytes of input stream 1600 times
      // However if number of empty read exceeds 15 come out of loop
      for($i=0;$i<1600;$i++) {
        $line = fread($this->stream,2048);
        if(strlen($line) == 0) {
          $emptyLine++;
          if($emptyLine > 15) break;
        }
        else {
          $xml .= $line;
        }
      }
      $xml = trim($xml);
      
      if(empty($xml)) { 
	$this->emptyResponses++;
	if($this->emptyResponses >= JAXL_PING_INTERVAL) {
          global $env, $key;
          $this->ping($key[$env]['domain']);
          $this->emptyResponses = 0;
        }
      }
      else {
	$this->emptyResponses = 0;
        if($this->auth) {
          $xmlarr = $this->splitXML($xml);
          $packetCount = count($xmlarr);
          for($i=0;$i<$packetCount;$i++) {
            $xml = $xmlarr[$i];
            $arr = $this->xmlize->xmlize($xml);
            if((!$this->xmlize->valid) && ($i == $packetCount-1) && (substr($xmlarr[$i],0,14) != '<stream:stream')) {
              $this->xmlBuffer = $xmlarr[$i];
              $this->logger->logger("Stored an invalid packet:\n".$this->xmlBuffer);
            }
            else {
              if($this->logEnable) { $this->logger->logger("Received XML<<\n".$xml); }
              $this->streamHandler($arr);
            }
          }
        }
        else {
          if($this->logEnable) { $this->logger->logger("Received XML<<\n".$xml); }
          $arr = $this->xmlize->xmlize($xml);
          $this->streamHandler($arr);
        }
      }
      
      // process scheduled jobs
      if($this->done) $this->processJobs();
    }
    
    /*
     * splitXML() method breaks the incoming xml stream into valid xml stanza
    */
    function splitXML($xml) {
      $temp = preg_split("/<(message|iq|presence|stream)(?=[\:\s\>])/", $xml, -1, PREG_SPLIT_DELIM_CAPTURE);
      $xmlarr = array();
      for ($a=1; $a<count($temp); $a=$a+2) {
        $xmlarr[] = "<".$temp[$a].$temp[($a+1)];
      }
      return $xmlarr;
    }
    
    /*
     * streamHandler() handles the incoming xml stream
     * It also returns back necessary streams back to main program
     * for further processing (e.g. messages, presence ..)
    */
    function streamHandler($arr) {
      if(empty($arr)) {
        return FALSE;
      }
      else {
        switch(TRUE) {
          case isset($arr['stream:stream']):
            $this->parseStream($arr);
            break;
          case isset($arr['proceed']):
            $this->parseProceed($arr);
            break;
          case isset($arr['success']):
            $this->parseSuccess($arr);
            break;
          case isset($arr['iq']):
            $this->parseIq($arr);
            break;
          case isset($arr['presence']):
            $this->parsePresence($arr);
            break;
          case isset($arr['message']):
            $this->parseMessage($arr);
            break;
          case isset($arr['challenge']):
            $this->parseChallenge($arr);
            break;
          case isset($arr['stream:error']):
            $this->parseError($arr);
            break;
          default:
            break;
        }
      }
    }
    
    /*
     * parseError() method parses the stream error cases
    */
    function parseError($arr) {
      if(isset($arr["stream:error"]["#"]["see-other-host"]) /*&& $arr["stream:error"]["#"]["str:text"][0]["@"]["xmlns:str"]=="urn:ietf:params:xml:ns:xmpp-streams"*/) {
        // Problem: http://code.google.com/p/jaxl/issues/detail?id=6
        // Solution: http://xmpp.org/internet-drafts/draft-saintandre-rfc3920bis-08.html#streams-error-conditions-see-other-host
	if(isset($arr["stream:error"]["#"]["str:text"][0]["#"])) {
	  global $env, $key;
	  $key[$env]['host'] = $arr["stream:error"]["#"]["str:text"][0]["#"];
        }
        $this->reconnect();
      }
    }
    
    /*
     * parseStream() method parses the initial stream sent by Jabber Server
    */
    function parseStream($arr) {
      if($arr["stream:stream"]['@']['xmlns'] != "jabber:client" || 
         $arr["stream:stream"]['@']["xmlns:stream"] != "http://etherx.jabber.org/streams") {
        $this->logger->logger("Unrecognized stream packet received");
      }
      else {
        $this->streamId = $arr["stream:stream"]['@']['id'];
        $this->streamHost = $arr["stream:stream"]['@']['from'];
        $this->streamVersion = $arr["stream:stream"]['@']['version'];
        
        $arrStreamFeatures = $arr["stream:stream"]["#"]["stream:features"][0];
        
        $xml = '';
        if(isset($arrStreamFeatures["#"]["starttls"]) && 
          ($arrStreamFeatures["#"]["starttls"][0]["@"]["xmlns"] == "urn:ietf:params:xml:ns:xmpp-tls")) {
          $this->logger->logger("Starting TLS Encryption...");
          $xml = '<starttls xmlns="urn:ietf:params:xml:ns:xmpp-tls"/>';
          print "Starting TLS Encryption...\n";
        }
        else if(isset($arrStreamFeatures["#"]["mechanisms"]) && 
               ($arrStreamFeatures["#"]["mechanisms"][0]["@"]["xmlns"] == "urn:ietf:params:xml:ns:xmpp-sasl")) {
          $this->logger->logger("Authenticating...");
          $mechanism = array();
          foreach ($arrStreamFeatures["#"]["mechanisms"][0]["#"]["mechanism"] as $row) {
            $mechanism[] = $row["#"];
          }
          switch(TRUE) {
            case in_array("DIGEST-MD5",$mechanism):
              $xml = '<auth xmlns="urn:ietf:params:xml:ns:xmpp-sasl" mechanism="DIGEST-MD5"/>';
              print "Attempting DIGEST-MD5 Authentication...\n";
              break;
            case in_array("PLAIN",$mechanism):
              $xml = '<auth xmlns="urn:ietf:params:xml:ns:xmpp-sasl" mechanism="PLAIN">';
              $xml .= base64_encode("\x00".$this->user."\x00".$this->pass);
              $xml .= '</auth>';
              print "Attempting PLAIN Authentication...\n";
              break;
          }
        }
        else if(isset($arrStreamFeatures["#"]["bind"]) && 
               ($arrStreamFeatures["#"]["bind"][0]["@"]["xmlns"] == "urn:ietf:params:xml:ns:xmpp-bind")) {
          $this->logger->logger("Binding to the server stream");
          $xml = '<iq type="set" id="'.$this->getId().'">';
          $xml .= '<bind xmlns="urn:ietf:params:xml:ns:xmpp-bind">';
          $xml .= '<resource>'.$this->resource.'</resource>';
          $xml .= '</bind>';
          $xml .= '</iq>';
          
          /* Reference: http://code.google.com/p/jaxl/issues/detail?id=1 */
          $this->sessionRequired = isset($arrStreamFeatures["#"]["session"]);
        }
        $this->sendXML($xml);
      }
    }
    
    /*
     * parseProceed() method turns on encryption and resend the initial stream
    */
    function parseProceed($arr) {
      if($arr["proceed"]["@"]["xmlns"] == "urn:ietf:params:xml:ns:xmpp-tls") {
        stream_set_blocking($this->stream, 1);
        if(!stream_socket_enable_crypto($this->stream, TRUE, STREAM_CRYPTO_METHOD_TLS_CLIENT))
	  stream_socket_enable_crypto($this->stream, TRUE, STREAM_CRYPTO_METHOD_SSLv3_CLIENT);
        stream_set_blocking($this->stream, 0);
        $this->connect();
      }
    }
   
    /*
     * parseSuccess() method is called after successful authentication
    */
    function parseSuccess($arr) {
      if ($arr["success"]['@']['xmlns'] == "urn:ietf:params:xml:ns:xmpp-sasl") {
        $this->logger->logger("Authentication Successful");
        $this->sendStream();
      }
    }
    
    /*
     * parseIq() method
    */
    function parseIq($arr) {
      if(isset($arr["iq"]["#"]["command"])) {
        $this->processCommand($arr); 
      }
      else if(isset($arr["iq"]["#"]["mailbox"])) {
        // Implementation of Google's Gmail Extension http://code.google.com/apis/talk/jep_extensions/gmail.html
        $GmailThreadId = array();
        $GmailURL = array();
        // 0 means user has not participated in this thread
        // 1 means user is one of the many recipients listed in the thread
        // 2 means user is the sole recipient of this thread
        $GmailParticipation = array();
        // Indicated number of messages in this thread
        $GmailMessages = array();
        $GmailDate = array();
        // For a multi-message thread, it contains sender information of each thread
        // E.g. $GmailSenders = array([0] => array("address","name","originator","unread")); where
        // address is the mail address of the sender[0]
        // name is the name of the sender[0]
        // originator = 1 means this person started the thread, originator = 0 means otherwise
        // unread = 1 means this thread includes unread messages, unread = 0 means otherwise
        $GmailSenders = array();
        $GmailLabels = array();
        $GmailSubject = array();
        $GmailSnippet = array();
        
        $GmailTotalMatched = $arr["iq"]["#"]["mailbox"][0]["@"]["total-matched"];
        $this->resultTime = $arr["iq"]["#"]["mailbox"][0]["@"]["result-time"];
        
        foreach($arr["iq"]["#"]["mailbox"][0]["#"]["mail-thread-info"] as $mailThread) {
          array_push($GmailThreadId,$mailThread["@"]["tid"]);
          array_push($GmailURL,$mailThread["@"]["url"]);
          array_push($GmailParticipation,$mailThread["@"]["participation"]);
          array_push($GmailMessages,$mailThread["@"]["messages"]);
          array_push($GmailDate,$mailThread["@"]["date"]);
          foreach($mailThread["#"]["senders"][0]["#"]["sender"] as $sender) {
            array_push($GmailSenders,array("address"=>$sender["@"]["address"],"name"=>$sender["@"]["name"],"originator"=>$sender["@"]["originator"],"unread"=>$sender["@"]["unread"]));
          }
          array_push($GmailLabels,$mailThread["#"]["labels"][0]["#"]);
          array_push($GmailSubject,$mailThread["#"]["subject"][0]["#"]);
          array_push($GmailSnippet,$mailThread["#"]["snippet"][0]["#"]);
        }
        $this->eventNewEMail($GmailTotalMatched,$GmailThreadId,$GmailURL,$GmailParticipation,$GmailMessages,$GmailDate,$GmailSenders,$GmailLabels,$GmailSubject,$GmailSnippet);
      }
      else if(isset($arr["iq"]["#"]["new-mail"]) && $arr["iq"]["#"]["new-mail"][0]["@"]["xmlns"] == "google:mail:notify") {
        // Implementation of Google's Gmail Extension http://code.google.com/apis/talk/jep_extensions/gmail.html
        $this->getNewEMail();
      }
      else if(isset($arr["iq"]["#"]["bind"])) {
        $this->jid = $arr["iq"]["#"]["bind"][0]["#"]["jid"][0]["#"];
        if($this->sessionRequired) { $this->startSession(); }
        else { $this->bind(); }
      }
      else if(isset($arr["iq"]["#"]["query"])) {
        $ns = $arr["iq"]["#"]["query"][0]["@"]["xmlns"];
        switch($ns) {
          case "jabber:iq:roster":
            $rosters = $arr["iq"]["#"]["query"][0]["#"]["item"];
            // Do-not enter this loop, if no item present in roster
            if(is_array($rosters)) {
              foreach($rosters as $roster) {
                $roster = $roster["@"];
                
                // Save the rosterList
                array_push($this->rosterList,$roster["jid"]);
                $this->rosterList = array_unique($this->rosterList);
                
                if($roster["subscription"] == "none") {
                  $this->subscribe($roster["jid"]);
                }
                else if($roster["subscription"] == "both") {
                  
                }
              }
            }
            if(!$this->done) {
              $this->done = TRUE;
              $this->setStatus();
            }
            break;
          case "http://jabber.org/protocol/disco#info":
            $this->roster('get');
            print "Requesting Roster List...\n";
            break;
        }
      }
      else if(isset($arr["iq"]["#"]["session"]) || ($arr['iq']['@']['type'] == "result" && $arr['iq']['@']['id'] == "sess_1")) {
        $this->bind();
      }
    }
    
    /*
     * bind() method is called on successful binding of the stream
     * It further query for available features, roaster list and set status
    */
    function bind() {
      $this->auth = TRUE;
      $this->getFeatureList();
    }
    
    /*
     * startSession() method called if session is required
     * Added after issue found with connection to local Ejabberd server
     * Reference: http://code.google.com/p/jaxl/issues/detail?id=1
    */
    function startSession() {
      print "Starting Session...\n";
      $xml = '';
      $xml .= '<iq type="set" id="sess_1" to="'.$this->domain.'">';
      $xml .= '<session xmlns="urn:ietf:params:xml:ns:xmpp-session"/>';
      $xml .= '</iq>';
      $this->sendXML($xml);
    }
    
    /*
     * getFeatureList() method gets the list of feature supported by the jabber server
    */
    function getFeatureList() {
      $xml = '<iq type="get" to="'.$this->domain.'"><query xmlns="http://jabber.org/protocol/disco#info"/></iq>';
      print "Requesting Feature List...\n";
      $this->sendXML($xml);
    }
    
    /*
     * getIq() method query for a iq $type
    */
    function getIq($type) {
      
    }
    
    /*
     * sendStatus() method called by bind(). Sets a status message.
    */
    function sendStatus($status = null, $show = "chat") {
      $xml = "<presence>";
      $xml .= "<show>".$show."</show>";
      if($status) {
        $xml .= "<status>".$status."</status>";
      }
      $xml .= "</presence>";
      $this->sendXML($xml);
    }
    
    /*
     * parsePresence() method handles any incoming presence
    */
    function parsePresence($arr) {
      if(isset($arr["presence"]["@"]["type"])) {
        switch($arr["presence"]["@"]["type"]) {
          case "subscribe":
            $this->subscribe($arr["presence"]["@"]["from"]);
            break;
        }
      }
      else if(isset($arr["presence"]["#"]["status"])) {
        $fromJid = $arr["presence"]["@"]["from"];
        $status = $arr["presence"]["#"]["status"][0]["#"];
        
        if(isset($arr["presence"]["#"]["x"][0]["#"]["photo"])) {
          $photo = $arr["presence"]["#"]["x"][0]["#"]["photo"][0]["#"];
        }
        else if(isset($arr["presence"]["#"]["x"][1]["#"]["photo"])) {
          $photo = $arr["presence"]["#"]["x"][1]["#"]["photo"][0]["#"];
        }
        else {
          $photo = "";
        }
        
        $this->eventPresence($fromJid,$status,$photo);
      }
    }
    
    /*
     * subscribe() method sends presence to all subscription request
     * i.e. By default accept all subscribe request
    */
    function subscribe($jid) {
      $this->sendPresence("subscribed",$jid);
    }
    
    /*
     * sendPresence() sends presence stream to a particular jid
    */
    function sendPresence($type,$tojid) {
      if (!in_array($type,$this->presenceType)) {
        $this->logger->logger("[[ERROR]]] Trying to send an inappropriate subscription type:".$type);
        return FALSE;
      }
      $xml = '<presence from="'.$this->jid.'" to="'.$tojid.'" type="'.$type.'"/>';
      $this->sendXML($xml);
    }
    
    /*
     * parseMessage() method parses any incoming message stream
    */
    function parseMessage($arr) {
      $arr = $arr["message"];
      if(isset($arr["@"]["type"]) && ($arr["@"]["type"] == "chat")) { //online messages
        $fromJid = $arr["@"]["from"];
        if(isset($arr["#"]["body"][0]["#"])) {
          $content = $arr["#"]["body"][0]["#"];
          $this->eventMessage($fromJid, $content);
        }
      }
      else if(isset($arr["#"]["x"][0]["@"]["xmlns"]) && ($arr["#"]["x"][0]["@"]["xmlns"] == "jabber:x:delay")) { //offline messages
        $fromJid = $arr["@"]["from"];
        $content = $arr["#"]["body"][0]["#"];
        $this->eventMessage($fromJid, $content, TRUE);
      }
    }
    
    /*
     * sendMessage() method sends message to a particular jid
    */
    function sendMessage($toJid,$content) {
      $xml  = '<message type="chat" from="'.$this->jid.'" to="'.$toJid.'">';
      $xml .= '<body>'.$content.'</body>';
      $xml .= '</message>';
      $this->sendXML($xml);
    }
    
    /*
     * getId() method returns incremented $lastid
    */
    function getId() {
      $this->lastid++;
      return $this->lastid;
    }
    
    /*
     * getJid() method returns clean JID
    */
    function getJid($jid) {
      $jid = explode("/", $jid, 2);
      return $jid[0];
    }
    
    /*
     * Custom time() function
    */
    function getTime() {
      list($usec,$sec) = explode(" ",microtime());
      return (float)$sec + (float)$usec;
    }
    
    /*
     * parseChallenge() method called when DIGEST-MD5 auth is in progress
    */
    function parseChallenge($arr) {
      if($arr['challenge']['@']['xmlns'] == "urn:ietf:params:xml:ns:xmpp-sasl") {
        // Decode Challenge
        $decoded = base64_decode($arr['challenge']['#'][0]);
        $decoded = $this->explodeData($decoded);
        if (!isset($decoded['digest-uri'])) {
          $decoded['digest-uri'] = 'xmpp/'.$this->domain;
        }
        
        // Generate cnonce
        $str = '';
        mt_srand((double)microtime()*10000000);
        for ($i=0; $i<32; $i++) {
          $str .= chr(mt_rand(0, 255));
        }
        $decoded['cnonce'] = base64_encode($str);
        
        if($this->secondChallenge) {
          $xml = '<response xmlns="urn:ietf:params:xml:ns:xmpp-sasl"/>';
        }
        else {
          // Making sure that only 'auth' is used as value of qop
          if (isset($decoded['qop']) && $decoded['qop'] != 'auth' && strpos($decoded['qop'],'auth') !== false) {
            $decoded['qop'] = 'auth';
          } 
          
          // Generate Response Array
          $response = array('username'=>$this->user,
                            'response'=>$this->encryptPassword(array_merge($decoded,array('nc'=>'00000001'))),
                            'charset'	=> 'utf-8',
                            'nc'=>'00000001',
                            'qop'=>'auth',
                          );
          
          // Prepare Response Key
          foreach (array('nonce', 'digest-uri', 'realm', 'cnonce') as $key) {
            if (isset($decoded[$key])) {
              $response[$key] = $decoded[$key];
            }
          }
          
          $xml = '<response xmlns="urn:ietf:params:xml:ns:xmpp-sasl">';
          $xml .= base64_encode($this->implodeData($response));
          $xml .= '</response>';
          
          $this->secondChallenge = TRUE;
        }
        $this->sendXML($xml);
      }
      else {
        // Proceed with other Auth Mechanisms
        
      }
    }
    
    /*
     * explodeData() method called while decoding challenge
    */
    function explodeData($data) {
      $data = explode(',', $data);
      $pairs = array();
      $key = false;
      
      foreach ($data as $pair) {
        $dd = strpos($pair, '=');
        
        if ($dd) {
          $key = trim(substr($pair, 0, $dd));
          $pairs[$key] = trim(trim(substr($pair, $dd + 1)), '"');
        }
        else if (strpos(strrev(trim($pair)), '"') === 0 && $key) {
          $pairs[$key] .= ',' . trim(trim($pair), '"');
          continue;
        }
      }
      return $pairs;
    }
    
    /*
     * implodeData() method is just opposite of explodeData()
    */
    function implodeData($data) {
      $return = array();
      foreach ($data as $key => $value) {
        $return[] = $key . '="' . $value . '"';
      }
      return implode(',', $return);
    }

    
    /*
     * Encrypts a password as in RFC 2831
    */
    function encryptPassword($data) {
      foreach (array('realm', 'cnonce', 'digest-uri') as $key){
        if (!isset($data[$key])) {
          $data[$key] = '';
        }
      }
      $pack = md5($this->user.':'.$data['realm'].':'.$this->pass);
      if (isset($data['authzid'])) {
        $a1 = pack('H32',$pack).sprintf(':%s:%s:%s',$data['nonce'],$data['cnonce'],$data['authzid']);
      }
      else {
        $a1 = pack('H32',$pack).sprintf(':%s:%s',$data['nonce'],$data['cnonce']);
      }
      $a2 = 'AUTHENTICATE:'.$data['digest-uri'];
      
      return md5(sprintf('%s:%s:%s:%s:%s:%s', md5($a1), $data['nonce'], $data['nc'], $data['cnonce'], $data['qop'], md5($a2)));
    }
    
    /*
     * roster() method takes care of getting, adding, removing, updating contacts
    */
    function roster($type, $forJid = NULL, $name = NULL, $groups = NULL) {
      if($type == "get") {
        $xml = '<iq type="get" id="'.$this->getId().'">';
        $xml .= '<query xmlns="jabber:iq:roster"/>';
        $xml .= '</iq>';
      }
      else if($forJid != '') {
        $xml = '<iq from="'.$this->jid.'" type="set" id="'.$this->getId().'" >';
        if($type == "add") {
          $xml .= '<query xmlns="jabber:iq:roster">';
          $xml .= '<item jid="'.$forJid.'" name="">';
          $xml .= '<group></group>';
        }
        else if($type == "remove") {
          $xml .= '<query xmlns="jabber:iq:roster">';
          $xml .= '<item jid="'.$forJid.'" subscription="remove">';
        }
        else if($type == "update") {
          $xml .= '<query xmlns="jabber:iq:roster">';
          $xml .= '<item jid="'.$forJid.'" name="'.$name.'" subscription="both">';
          foreach($groups as $group) { $xml .= '<group>'.$group.'</group>'; }
        }
        $xml .= '</item>';
        $xml .= '</query>';
        $xml .= '</iq>';
      }
      $this->sendXML($xml);
    }
    
    /*
     * getBareJid() method returns back bareJid
    */
    function getBareJid($jid) {
      list($user,$domain,$resource) = $this->splitJid($jid);
      return ($user ? $user."@" : "").$domain;
    }
    
    /*
     * splitJid() method splits JID into three components (user,domain,resource)
    */
    function splitJid($jid) {
      preg_match("/(?:([^\@]+)\@)?([^\/]+)(?:\/(.*))?$/",$jid,$matches);
      return array($matches[1],$matches[2],@$matches[3]);
    }
    
    /*
     * getNewEMail() is a Gmail specific function which queries for new mails in the inbox
    */
    function getNewEMail() {
      $xml = '<iq type="get" from="'.$this->jid.'" to="'.$this->getBareJid($this->jid).'" id="'.$this->getId().'">';
      if($this->resultTime) {
        $xml .= '<query xmlns="google:mail:notify" newer-than-time="'.$this->resultTime.'" />';
      }
      else {
        $xml .= '<query xmlns="google:mail:notify" />';
      }
      $xml .= '</iq>';
      $this->sendXML($xml);
    }

    function ping($toJid='') {
      $xml = '<iq type="get" from="'.$this->jid.'" to="'.$toJid.'" id="'.$this->getId().'">';
      $xml .= '<ping xmlns="urn:xmpp:ping"/>';
      $xml .= '</iq>';
      $this->sendXML($xml);
    }

    /* XEP-0133 Service Administration Starts */
    function processCommand($arr) {
      global $env, $key;
      
      $command = end(explode("#", $arr["iq"]["#"]["command"][0]["@"]["node"]));
      if($arr["iq"]["#"]["command"][0]["@"]["status"] == "executing") {
        $id = $arr["iq"]["@"]["id"];
        switch($command) {
          case 'add-user' || 'delete-user':
	    $xml = '<iq type="set" from="'.$this->jid.'" to="'.$key[$env]['domain'].'" xml:lang="en">';
            $sessionId = $arr["iq"]["#"]["command"][0]["@"]["sessionid"];
	    $xml .= '<command xmlns="http://jabber.org/protocol/commands" node="http://jabber.org/protocol/admin#'.$command.'" sessionid="'.$sessionId.'">';
	    $xml .= '<x xmlns="jabber:x:data" type="submit">';
	    foreach($arr["iq"]["#"]["command"][0]["#"]["x"][0]["#"]["field"] as $field) {
 	      $type = $field["@"]["type"];
	      $var = $field["@"]["var"];
	      if(isset($field["#"]["value"][0]["#"])) $value = $field["#"]["value"][0]["#"];
	      else $value = $this->command[$command][$id][$var];
	      if(isset($field["#"]["required"])) $required = TRUE;
	      else $required = FALSE;
	      $xml .= '<field type="'.$type.'" var="'.$var.'">';
	      $xml .= '<value>'.$value.'</value>';
	      $xml .= '</field>';
            }
	    $xml .= '</x>';
            $xml .= '</command>';
	    $xml .= '</iq>';
	    $this->command["session-id"][$sessionId] = $id;
            break;
          default:
            break;
        }
	$this->sendXML($xml);
      }
      else if($arr["iq"]["#"]["command"][0]["@"]["status"] == "completed") {
	$sessionId = $arr["iq"]["#"]["command"][0]["@"]["sessionid"];
        if($this->command[$command][$this->command["session-id"][$sessionId]]["callback"]) {
	  $callback = $this->command[$command][$this->command["session-id"][$sessionId]]["callback"];
	  unset($this->command[$command][$this->command["session-id"][$sessionId]]);
	  unset($this->command["session-id"][$sessionId]);
	  call_user_func($callback, "completed");
	}
      }
    }

    function sendCommand($type, $param) {
      global $env, $key;
       
      $id = $this->getId();

      $xml = '<iq type="set" from="'.$this->jid.'" to="'.$key[$env]['domain'].'" id="'.$id.'" xml:lang="en">';
      $xml .= '<command xmlns="http://jabber.org/protocol/commands" action="execute" node="http://jabber.org/protocol/admin#'.$type.'"/>';
      $xml .= '</iq>';

      switch($type) {
        case 'add-user':
          $this->command[$type][$id] = array('accountjid'=>$param[0].'@'.$key[$env]['domain'], 'password'=>$param[1], 'password-verify'=>$param[1], 'email'=>$param[2], 'given_name'=>$param[3], 'surname'=>$param[4], 'callback'=>$param[5]);
	  break;
	case 'delete-user' || 'disable-user' || 'reenable-user' || 'end-user-session':
	  $this->command[$type][$id] = array('accountjids'=>$param[0].'@'.$key[$env]['domain'], 'callback'=>$param[1]);
	  break;
	default:
	  $thi->logger->logger("Command not implemented...");
	  break;
      }

      $this->sendXML($xml);
    }
 
    function addUser($bareJid, $password, $email, $firstName=FALSE, $lastName=FALSE, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("add-user", $param);
    }

    function deleteUser($bareJid, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("delete-user", $param);
    }

    function disableUser($bareJid, $callback=FALSE) { 
      $param = func_get_args();
      $this->sendCommand("disable-user", $param);
    }

    function reEnableUser($bareJid, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("reenable-user", $param);
    }

    function endUserSession($bareJid, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("end-user-session", $param);
    }

    function getUserPassword($bareJid, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("get-user-password", $param);
    }

    function changeUserPassword($bareJid, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("change-user-password", $param);
    }

    function getUserRoster($bareJid, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("get-user-roster", $param);
    }

    function getUserLastLoginTime($bareJid, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("get-user-lastlogin", $param);
    }

    function getUserStats($bareJid, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("user-stats", $param);
    }

    function editBlacklist($bareJids, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("edit-blacklist", $param);
    }

    function editWhitelist($bareJids, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("edit-whitelist", $param);
    }
     
    function getRegisteredUserCount() {
      $param = func_get_args();
      $this->sendCommand("get-registered-users-num", $param);
    }

    function getDisabledUserCount() {
      $param = func_get_args();
      $this->sendCommand("get-disabled-users-num", $param);
    }

    function getOnlineUserCount() {
      $param = func_get_args();
      $this->sendCommand("get-online-users-num", $param);
    }

    function getActiveUserCount() {
      $param = func_get_args();
      $this->sendCommand("get-active-users-num", $param);
    }

    function getIdleUserCount() {
      $param = func_get_args();
      $this->sendCommand("get-idle-users-num", $param);
    }

    function getRegisteredUserList($item, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("get-registered-users-list", $param);
    }

    function getDisabledUserList($item, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("get-disabled-users-list", $param);
    }

    function getOnlineUserList($item, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("get-online-users-list", $param);
    }

    function getActiveUserList($item, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("get-active-users", $param);
    }

    function getIdleUserList($item, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("get-idle-users", $param);
    }

    function sendAnnouncement($announcement, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("announce", $param);
    }

    function setMOTD($message, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("set-motd", $param);
    }

    function editMOTD($message, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("edit-motd", $param);
    }

    function deleteMOTD() {
      $param = func_get_args();
      $this->sendCommand("delete-motd", $param);
    }

    function setWelcomeMessage($message, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("set-welcome", $param);
    }

    function deleteWelcomeMessage() {
      $param = func_get_args();
      $this->sendCommand("delete-welcome", $param);
    }

    function editAdminList($bareJids, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("edit-admin", $param);
    }

    function restartService($delay, $announcement, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("restart", $param);
    }

    function shutdownService($delay, $announcement, $callback=FALSE) {
      $param = func_get_args();
      $this->sendCommand("shutdown", $param);
    }
    /* XEP-0133 Service Administration Ends */

    /* Start: Custom Jaxl library implementation for providing periodic job handlers */
    function addJob($interval, $callback) {
      $this->jobs[$interval][] = $callback;
    }
   
    function processJobs() {
      foreach($this->jobs as $interval => $callbacks) {
        if(($this->jobsTick % $interval) == 0) {
          foreach($callbacks as $callback) {
            call_user_func($callback);
          }
        }
      }
      $this->jobsTick++;
    }
    /* End: Custom Jaxl library implementation for providing periodic job handlers */

    /*
     * eventNewEMail() method is called when a new mail notification is received
    */
    function eventNewEMail($total,$thread,$url,$participation,$messages,$date,$senders,$labels,$subject,$snippet) {
      // Extended by JAXL class (see jaxl.class.php)
    }
    
    /*
     * eventMessage() method called when a message stanza is received
    */
    function eventMessage($fromJid, $content, $offline = FALSE) {
      // Extended by JAXL class (see jaxl.class.php)
    }
    
    /*
     * eventPresence() method is called when a presence stanza is received
    */
    function eventPresence($fromJid, $status, $photo) {
      // Extended by JAXL class (see jaxl.class.php)
    }
    
    /*
     * setStatus() method is called after auth, service discovery and roster request
     * used to set a custom status message
    */
    function setStatus() {
      // Extended by JAXL class (see jaxl.class.php)
    }
    
  }

?>
