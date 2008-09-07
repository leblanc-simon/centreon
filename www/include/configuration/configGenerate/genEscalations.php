<?php
/*
 * Centreon is developped with GPL Licence 2.0 :
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.txt
 * Developped by : Julien Mathis - Romain Le Merlus 
 * 
 * The Software is provided to you AS IS and WITH ALL FAULTS.
 * Centreon makes no representation and gives no warranty whatsoever,
 * whether express or implied, and without limitation, with regard to the quality,
 * any particular or intended purpose of the Software found on the Centreon web site.
 * In no event will Centreon be liable for any direct, indirect, punitive, special,
 * incidental or consequential damages however they may arise and even if Centreon has
 * been previously advised of the possibility of such damages.
 * 
 * For information : contact@centreon.com
 */

/*
 * This script generate the file configuration escalations.cfg used by Nagios.
 * This script is shared to 6 parts:
 *  - PART 1 : Collect Escalations for all Hosts
 *  - PART 2 : Collect Escalations for all HostGroups 
 *  - PART 3 : Collect Escalations for all ServiceGroups
 *  - PART 4 : Collect Escalations for all Services
 *  - PART 5 : Generate the configuration with all datas collected
 *
 * PHP version 5
 *
 * @package genEscalations.php
 * @author Damien Duponchelle dduponchelle@merethis.com
 * @version $Id: $
 * @copyright (c) 2007-2008 Centreon
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 */

	if (!isset($oreon))
		exit();

	if (!is_dir($nagiosCFGPath.$tab['id']."/")) {
		mkdir($nagiosCFGPath.$tab['id']."/");
	}

	// Creating handle to write the file configuration
	$handle = create_file($nagiosCFGPath.$tab['id']."/escalations.cfg", $oreon->user->get_name());

	// PART 1 - Escalations for all Hosts
	$DBRESULT =& $pearDB->query("SELECT DISTINCT esc.* FROM escalation_host_relation ehr, escalation esc WHERE ehr.escalation_esc_id = esc.esc_id ORDER BY esc.esc_name");
	if (PEAR::isError($DBRESULT)){
		print "DB Error : ".$DBRESULT->getDebugInfo()."<br />";		
	}
	
	$escalation = array();
	$i = 1;
	$str = NULL;
	while($escalation =& $DBRESULT->fetchRow()) {
		
		$BP = false;
		$strDef = "";
		$linkedToHost = 0;
		
		$DBRESULT2 =& $pearDB->query("SELECT DISTINCT host.host_id, host.host_name FROM escalation_host_relation ehr, host , ns_host_relation nhr WHERE ehr.escalation_esc_id = '".$escalation["esc_id"]."' AND host.host_id = ehr.host_host_id AND host.host_id = nhr.host_host_id AND nhr.nagios_server_id = '".$tab['id']."' ");
		if (PEAR::isError($DBRESULT2)) {
			print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
		}
		
		$host = array();
		$strTemp = NULL;
		while ($host =& $DBRESULT2->fetchRow())	{
			
			$BP = false;
			array_key_exists($host["host_id"], $gbArr[2]) ? $BP = true : NULL;
			
			if ($BP && isHostOnThisInstance($host["host_id"], $tab['id'])) {
				$linkedToHost++;
				$strTemp != NULL ? $strTemp .= ", ".$host["host_name"] : $strTemp = $host["host_name"];
			}
		}
		
		$DBRESULT2->free();			
		if (isset($strTemp) == true) {
			$ret["comment"] ? ($strDef .= "# '".$escalation["esc_name"]."' host escalation definition ".$i."\n") : NULL;
			
			if ($ret["comment"] && $escalation["esc_comment"]) {
				$comment = array();
				$comment = explode("\n", $escalation["esc_comment"]);
				
				foreach ($comment as $cmt) {
					$strDef .= "# ".$cmt."\n";
				}
			}
			
			$strDef .= "define hostescalation{\n";
			$strDef .= print_line("host_name", $strTemp);
			
			$cg = array();
			$strTemp = NULL;
			
			$DBRESULT2 =& $pearDB->query("SELECT DISTINCT cg.cg_id, cg.cg_name FROM escalation_contactgroup_relation ecgr, contactgroup cg WHERE ecgr.escalation_esc_id = '".$escalation["esc_id"]."' AND ecgr.contactgroup_cg_id = cg.cg_id ORDER BY cg.cg_name");
			if (PEAR::isError($DBRESULT2)) {
				print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
			}
			
			while($cg =& $DBRESULT2->fetchRow()) {
				$BP = false;				
				array_key_exists($cg["cg_id"], $gbArr[1]) ? $BP = true : $BP = false;
				
				if ($BP) {
					$strTemp != NULL ? $strTemp .= ", ".$cg["cg_name"] : $strTemp = $cg["cg_name"];
				}
			}
			
			$DBRESULT2->free();
			if (isset($strTemp) == true) $strDef .= print_line("contact_groups", $strTemp);			
			if (isset($escalation["first_notification"]) == true) $strDef .= print_line("first_notification", $escalation["first_notification"]);
			if (isset($escalation["last_notification"]) == true) $strDef .= print_line("last_notification", $escalation["last_notification"]);
			if (isset($escalation["notification_interval"]) == true) $strDef .= print_line("notification_interval", $escalation["notification_interval"]);
			
			$DBRESULT2 =& $pearDB->query("SELECT tp_name FROM timeperiod WHERE tp_id = '".$escalation["escalation_period"]."'");
			if (PEAR::isError($DBRESULT2)) {
				print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
			}
				
			$tp =& $DBRESULT2->fetchRow();				
			if (isset($tp["tp_name"]) == true) $strDef .= print_line("escalation_period", $tp["tp_name"]);
			if (isset($escalation["escalation_options1"]) == true) $strDef .= print_line("escalation_options", $escalation["escalation_options1"]);
			
			$DBRESULT2->free();
			$strDef .= "}\n\n";
			$i++;
		}
		
		if (isset($linkedToHost) == true) {
			$str .= $strDef;
		}
		unset($strDef);
	}
	unset($escalation);
	$DBRESULT->free();

	// PART 2 - Escalations for all HostGroups	
	$DBRESULT =& $pearDB->query("SELECT DISTINCT esc.* FROM escalation_hostgroup_relation ehgr, escalation esc WHERE ehgr.escalation_esc_id = esc.esc_id ORDER BY esc.esc_name");
	if (PEAR::isError($DBRESULT)) {
		print "DB Error : ".$DBRESULT->getDebugInfo()."<br />";
	}
	
	$escalation = array();
	while($escalation =& $DBRESULT->fetchRow())	{
		$BP = false;
		$DBRESULT2 =& $pearDB->query("SELECT DISTINCT hg.hg_id, hg.hg_name FROM escalation_hostgroup_relation ehgr, hostgroup hg WHERE ehgr.escalation_esc_id = '".$escalation["esc_id"]."' AND hg.hg_id = ehgr.hostgroup_hg_id");
		if (PEAR::isError($DBRESULT2)) {
			print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
		}
		
		$hg = array();
		$strTemp = NULL;
		while ($hg =& $DBRESULT2->fetchRow()) {
			$BP = false;
			array_key_exists($hg["hg_id"], $gbArr[3]) ? $BP = true : NULL;
			
			if($BP) {	
				$strTemp != NULL ? $strTemp .= ", ".$hg["hg_name"] : $strTemp = $hg["hg_name"];
			}
		}
		
		$DBRESULT2->free();
		
		if (isset($strTemp) == true) {
			$ret["comment"] ? ($str .= "# '".$escalation["esc_name"]."' host (group) escalation definition ".$i."\n") : NULL;
			
			if (isset($ret["comment"]) == true && isset($escalation["esc_comment"]) == true) {
				$comment = array();
				$comment = explode("\n", $escalation["esc_comment"]);
				
				foreach ($comment as $cmt) {
					$str .= "# ".$cmt."\n";
				}
			}
			
			$str .= "define hostescalation{\n";
			$str .= print_line("hostgroup_name", $strTemp);			
			
			$cg = array();
			$strTemp = NULL;
			
			$DBRESULT2 =& $pearDB->query("SELECT DISTINCT cg.cg_id, cg.cg_name FROM escalation_contactgroup_relation ecgr, contactgroup cg WHERE ecgr.escalation_esc_id = '".$escalation["esc_id"]."' AND ecgr.contactgroup_cg_id = cg.cg_id ORDER BY cg.cg_name");
			if (PEAR::isError($DBRESULT2)) {
				print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
			}
			
			while($cg =& $DBRESULT2->fetchRow()) {
				$BP = false;				
				array_key_exists($cg["cg_id"], $gbArr[1]) ? $BP = true : $BP = false;
				
				if($BP) {
					$strTemp != NULL ? $strTemp .= ", ".$cg["cg_name"] : $strTemp = $cg["cg_name"];
				}
			}
			
			$DBRESULT2->free();
			
			if (isset($strTemp) == true) $str .= print_line("contact_groups", $strTemp);			
			if ($escalation["first_notification"] != NULL) $str .= print_line("first_notification", $escalation["first_notification"]);
			if ($escalation["last_notification"] != NULL) $str .= print_line("last_notification", $escalation["last_notification"]);
			if ($escalation["notification_interval"] != NULL) $str .= print_line("notification_interval", $escalation["notification_interval"]);
			
			$DBRESULT2 =& $pearDB->query("SELECT tp_name FROM timeperiod WHERE tp_id = '".$escalation["escalation_period"]."'");
			if (PEAR::isError($DBRESULT2)) {
				print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
			}
			
			$tp =& $DBRESULT2->fetchRow();				
			if (isset($tp["tp_name"]) == true) $str .= print_line("escalation_period", $tp["tp_name"]);
			if ($escalation["escalation_options1"]) $str .= print_line("escalation_options", $escalation["escalation_options1"]);
			$str .= "}\n\n";
			$i++;
		}
	}
	unset($escalation);
	$DBRESULT->free();	

	// PART 3 - Escalations for all ServiceGroups	
	$DBRESULT =& $pearDB->query("SELECT DISTINCT esc.* FROM escalation_servicegroup_relation esgr, escalation esc WHERE esgr.escalation_esc_id = esc.esc_id ORDER BY esc.esc_name");
	if (PEAR::isError($DBRESULT)) {
		print "DB Error : ".$DBRESULT->getDebugInfo()."<br />";
	}
	
	$escalation = array();
	$strTemp = NULL;
	while($escalation =& $DBRESULT->fetchRow())	{
		$BP = false;
		
		$DBRESULT2 =& $pearDB->query("SELECT DISTINCT sg.sg_id, sg.sg_name FROM escalation_servicegroup_relation esgr, servicegroup sg WHERE esgr.escalation_esc_id = '".$escalation["esc_id"]."' AND sg.sg_id = esgr.servicegroup_sg_id");
		if (PEAR::isError($DBRESULT2)) {
			print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
		}
		
		$sg = array();
		
		while ($sg =& $DBRESULT2->fetchRow()) {
			$BP = false;
			array_key_exists($sg["sg_id"], $gbArr[5]) ? $BP = true : NULL;
			
			if($BP) {	
				$strTemp != NULL ? $strTemp .= ", ".$sg["sg_name"] : $strTemp = $sg["sg_name"];
			}
		}
		
		$DBRESULT2->free();
			
		if (isset($strTemp) == true) {
			$ret["comment"] ? ($str .= "# '".$escalation["esc_name"]."' service (group) escalation definition ".$i."\n") : NULL;
			
			if ($ret["comment"] && $escalation["esc_comment"]) {
				$comment = array();
				$comment = explode("\n", $escalation["esc_comment"]);
				
				foreach ($comment as $cmt) {
					$str .= "# ".$cmt."\n";
				}
			}
			
			$str .= "define serviceescalation{\n";
			$str .= print_line("servicegroup_name", $strTemp);			
			
			$cg = array();
			$strTemp = NULL;
			$DBRESULT2 =& $pearDB->query("SELECT DISTINCT cg.cg_id, cg.cg_name FROM escalation_contactgroup_relation ecgr, contactgroup cg WHERE ecgr.escalation_esc_id = '".$escalation["esc_id"]."' AND ecgr.contactgroup_cg_id = cg.cg_id ORDER BY cg.cg_name");
			if (PEAR::isError($DBRESULT2)) {
				print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
			}
			
			while($cg =& $DBRESULT2->fetchRow()) {
				$BP = false;			
				array_key_exists($cg["cg_id"], $gbArr[1]) ? $BP = true : $BP = false;
				
				if($BP) {
					$strTemp != NULL ? $strTemp .= ", ".$cg["cg_name"] : $strTemp = $cg["cg_name"];
				}
			}
			
			$DBRESULT2->free();
			
			if (isset($strTemp) == true) $str .= print_line("contact_groups", $strTemp);			
			if (isset($escalation["first_notification"]) == true) $str .= print_line("first_notification", $escalation["first_notification"]);
			if (isset($escalation["last_notification"]) == true) $str .= print_line("last_notification", $escalation["last_notification"]);
			if (isset($escalation["notification_interval"]) == true) $str .= print_line("notification_interval", $escalation["notification_interval"]);

			$DBRESULT2 =& $pearDB->query("SELECT tp_name FROM timeperiod WHERE tp_id = '".$escalation["escalation_period"]."'");
			if (PEAR::isError($DBRESULT2)) {
				print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
			}
			
			$tp =& $DBRESULT2->fetchRow();				
			if (isset($tp["tp_name"]) == true) $str .= print_line("escalation_period", $tp["tp_name"]);
			if (isset($escalation["escalation_options2"]) == true) $str .= print_line("escalation_options", $escalation["escalation_options2"]);

			$str .= "}\n\n";
			$i++;
		}
	}
	unset($escalation);
	$DBRESULT->free();	

	// PART 4 -Escalation for all Services	
	$DBRESULT =& $pearDB->query("SELECT DISTINCT service.service_activate, service.service_description, esr.service_service_id FROM service, escalation_service_relation esr WHERE esr.service_service_id = service.service_id ORDER BY esr.service_service_id");
	if (PEAR::isError($DBRESULT)) {
		print "DB Error : ".$DBRESULT->getDebugInfo()."<br />";
	}
	
	$generated = 0;
	while($service =& $DBRESULT->fetchRow()) {
		$BP = false;		
		array_key_exists($service["service_service_id"], $gbArr[4]) ? $BP = true : NULL;
		
		if($BP) {
			$DBRESULT2 =& $pearDB->query("SELECT * FROM escalation esc, escalation_service_relation esr WHERE esr.service_service_id = '".$service["service_service_id"]."' AND esc.esc_id = esr.escalation_esc_id ORDER BY esc.esc_name");
			if (PEAR::isError($DBRESULT2)) {
				print "DB Error : ".$DBRESULT2->getDebugInfo()."<br />";
			}
			
			$escalation = array();
			while ($escalation =& $DBRESULT2->fetchRow()) {
				if (isHostOnThisInstance($escalation["host_host_id"], $tab['id'])){
					$host = array();
					$BP = false;
					$strDef = "";
					array_key_exists($escalation["host_host_id"], $gbArr[2]) ? $BP = true : NULL;
					
					$service["service_description"] = str_replace('#S#', "/", $service["service_description"]);
					$service["service_description"] = str_replace('#BS#', "\\", $service["service_description"]);
									
					if ($BP) {
						$ret["comment"] ? ($strDef .= "# '".$escalation["esc_name"]."' service escalation definition ".$i."\n") : NULL;
						
						if (isset($ret["comment"]) == true && isset($escalation["esc_comment"]) == true)	{
							$comment = array();
							$comment = explode("\n", $escalation["esc_comment"]);
							
							foreach ($comment as $cmt) {
								$strDef .= "# ".$cmt."\n";
							}
						}
						
						$strDef .= "define serviceescalation{\n";			
						$strDef .= print_line("host_name", getMyHostName($escalation["host_host_id"]));										
						if (isHostOnThisInstance($escalation["host_host_id"], $tab['id'])){						
							$generated++;
						}
						
						$strDef .= print_line("service_description", $service["service_description"]);
						$cg = array();
						$strTemp = NULL;
						
						$DBRESULT3 =& $pearDB->query("SELECT DISTINCT cg.cg_id, cg.cg_name FROM escalation_contactgroup_relation ecgr, contactgroup cg WHERE ecgr.escalation_esc_id = '".$escalation["esc_id"]."' AND ecgr.contactgroup_cg_id = cg.cg_id ORDER BY cg.cg_name");
						if (PEAR::isError($DBRESULT3)) {
							print "DB Error : ".$DBRESULT3->getDebugInfo()."<br />";
						}
						
						while($cg =& $DBRESULT3->fetchRow()) {
							$BP = false;				
							array_key_exists($cg["cg_id"], $gbArr[1]) ? $BP = true : $BP = false;
							
							if ($BP) {
								$strTemp != NULL ? $strTemp .= ", ".$cg["cg_name"] : $strTemp = $cg["cg_name"];
							}
						}
						$DBRESULT3->free();
	
						if (isset($strTemp) == true) $strDef .= print_line("contact_groups", $strTemp);			
						if (isset($escalation["first_notification"]) == true) $strDef .= print_line("first_notification", $escalation["first_notification"]);
						if (isset($escalation["last_notification"]) == true) $strDef .= print_line("last_notification", $escalation["last_notification"]);
						if (isset($escalation["notification_interval"]) == true) $strDef .= print_line("notification_interval", $escalation["notification_interval"]);
	
						$DBRESULT4 =& $pearDB->query("SELECT tp_name FROM timeperiod WHERE tp_id = '".$escalation["escalation_period"]."'");
						if (PEAR::isError($DBRESULT4)) {
							print "DB Error : ".$DBRESULT4->getDebugInfo()."<br />";
						}
						$tp =& $DBRESULT4->fetchRow();
						$DBRESULT4->free();		
						if (isset($tp["tp_name"]) == true) $strDef .= print_line("escalation_period", $tp["tp_name"]);
						if (isset($escalation["escalation_options2"]) == true) $strDef .= print_line("escalation_options", $escalation["escalation_options2"]);
	
						$strDef .= "}\n\n";					
						$i++;
					}					
				}
				if ($generated){	
					$str .= $strDef;
					$strDef = "";
				}				
			}	
			unset($escalation);
			$DBRESULT2->free();		
		}
	}		
	unset($service);
	$DBRESULT->free();
	
	// PART 5 - Generating final file configuration escalations.cfg		
	write_in_file($handle, html_entity_decode($str, ENT_QUOTES), $nagiosCFGPath.$tab['id']."/escalations.cfg");
	fclose($handle);
	unset($str);
	unset($i);
?>