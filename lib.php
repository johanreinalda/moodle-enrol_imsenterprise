<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * IMS Enterprise file enrolment plugin.
 *
 * This plugin lets the user specify an IMS Enterprise file to be processed.
 * The IMS Enterprise file is mainly parsed on a regular cron,
 * but can also be imported via the UI (Admin Settings).
 * @package    enrol
 * @subpackage imsenterprise
 * @copyright  2010 Eugene Venter
 * @author     Eugene Venter - based on code by Dan Stowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/*

Note for programmers:

This class uses regular expressions to mine the data file. The main reason is
that XML handling changes from PHP 4 to PHP 5, so this should work on both.

One drawback is that the pattern-matching doesn't (currently) handle XML
namespaces - it only copes with a <group> tag if it says <group>, and not
(for example) <ims:group>.

This should also be able to handle VERY LARGE FILES - so the entire IMS file is
NOT loaded into memory at once. It's handled line-by-line, 'forgetting' tags as
soon as they are processed.

N.B. The "sourcedid" ID code is translated to Moodle's "idnumber" field, both
for users and for courses.

*/

require_once($CFG->dirroot.'/group/lib.php');

//this table contains end of course timestamps, parsed here in the <group> tag
//the process_course_autohide() will close courses once their time comes...
if (!defined("TBIRD_COURSE_AUTOHIDE_TABLE")) { define("TBIRD_COURSE_AUTOHIDE_TABLE", 'tbird_course_autohide'); }

//this table is defined in the block 'course_meeting_info', in db/install.xml file
//it is used by that block, and 'course_list_tbird' to show course meeting data
//the data is loaded from IMS xml files processed by this enrolment module 'imsenterprise'
if (!defined("TBIRD_COURSE_INFO_TABLE")) { define("TBIRD_COURSE_INFO_TABLE", 'tbird_course_info'); }


class enrol_imsenterprise_plugin extends enrol_plugin {

var $log;

// new variables needed - JKR
var $starttime;
var $errorCount = 0; // should really be in constructor - JKR
var $warningCount = 0;

/**
* Read in an IMS Enterprise file.
* Originally designed to handle v1.1 files but should be able to handle
* earlier types as well, I believe.
*
*/
function cron() {
    global $CFG;

    // Get configs
    $imsfilelocation    = $this->get_config('imsfilelocation');
    $logtolocation      = $this->get_config('logtolocation');
    $mailadmins         = $this->get_config('mailadmins');
    $prev_time          = $this->get_config('prev_time');
    $prev_md5           = $this->get_config('prev_md5');
    $prev_path          = $this->get_config('prev_path');
	$snapshotunenrol	= $this->get_config('snapshotunenrol');
    $autohide           = $this->get_config('autohidecourse');

	// track courses for snapshot unenrol - JKR
	$coursecodes = Array();
	$central_member_list = Array();
	
    if (empty($imsfilelocation)) {
        // $filename = "$CFG->dirroot/enrol/imsenterprise/example.xml";  // Default location
        $filename = "$CFG->dataroot/1/imsenterprise-enrol.xml";  // Default location
    } else {
        $filename = $imsfilelocation;
    }

    $this->logfp = false; // File pointer for writing log data to
    if(!empty($logtolocation)) {
        $this->logfp = fopen($logtolocation, 'a');
    }

    if ( file_exists($filename) ) {
        @set_time_limit(0);
        $this->starttime = time();

        $this->log_line('----------------------------------------------------------------------');
        $this->log_line("IMS Enterprise enrol cron process launched at " . userdate(time()));
        $this->log_line('Found file '.$filename);
        $this->xmlcache = '';

        // Make sure we understand how to map the IMS-E roles to Moodle roles
        $this->load_role_mappings();

        $md5 = md5_file($filename); // NB We'll write this value back to the database at the end of the cron
        $filemtime = filemtime($filename);

        // Decide if we want to process the file (based on filepath, modification time, and MD5 hash)
        // This is so we avoid wasting the server's efforts processing a file unnecessarily
        if(empty($prev_path)  || ($filename != $prev_path)) {
            $fileisnew = true;
        } elseif(isset($prev_time) && ($filemtime <= $prev_time)) {
            $fileisnew = false;
            $this->log_line('File modification time is not more recent than last update - skipping processing.');
        } elseif(isset($prev_md5) && ($md5 == $prev_md5)) {
            $fileisnew = false;
            $this->log_line('File MD5 hash is same as on last update - skipping processing.');
        } else {
            $fileisnew = true; // Let's process it!
        }

        if($fileisnew) {

            $listoftags = array('group', 'person', 'member', 'membership', 'comments', 'properties'); // The list of tags which should trigger action (even if only cache trimming)
            $this->continueprocessing = true; // The <properties> tag is allowed to halt processing if we're demanding a matching target

            // FIRST PASS: Run through the file and process the group/person entries
            if (($fh = fopen($filename, "r")) != false) {

                $line = 0;
                while ((!feof($fh)) && $this->continueprocessing) {

                    $line++;
                    $curline = fgets($fh);
                    $this->xmlcache .= $curline; // Add a line onto the XML cache

                    while (true) {
                      // If we've got a full tag (i.e. the most recent line has closed the tag) then process-it-and-forget-it.
                      // Must always make sure to remove tags from cache so they don't clog up our memory
                      if($tagcontents = $this->full_tag_found_in_cache('group', $curline)) {
                          $coursecodes[] = $this->process_group_tag($tagcontents);
                          $this->remove_tag_from_cache('group');
                      } elseif($tagcontents = $this->full_tag_found_in_cache('person', $curline)) {
                          $this->process_person_tag($tagcontents);
                          $this->remove_tag_from_cache('person');
                      } elseif($tagcontents = $this->full_tag_found_in_cache('membership', $curline)) {
                          $central_member_list[] = $this->process_membership_tag($tagcontents);
                          $this->remove_tag_from_cache('membership');
                      } elseif($tagcontents = $this->full_tag_found_in_cache('comments', $curline)) {
                          $this->remove_tag_from_cache('comments');
                      } elseif($tagcontents = $this->full_tag_found_in_cache('properties', $curline)) {
                          $this->process_properties_tag($tagcontents);
                          $this->remove_tag_from_cache('properties');
                      } else {
                          break;
                      }
                    } // End of while-tags-are-detected
                } // end of while loop

//THIS NEEDS TESTING - JKR
                if($snapshotunenrol){
					$this->snapshot_unenrol($coursecodes,$central_member_list);
				}
//THIS NEEDS TESTING

                fclose($fh);
                fix_course_sortorder();
            } // end of if(file_open) for first pass

            /*


            SECOND PASS REMOVED
            Since the IMS specification v1.1 insists that "memberships" should come last,
            and since vendors seem to have done this anyway (even with 1.0),
            we can sensibly perform the import in one fell swoop.


            // SECOND PASS: Now go through the file and process the membership entries
            $this->xmlcache = '';
            if (($fh = fopen($filename, "r")) != false) {
                $line = 0;
                while ((!feof($fh)) && $this->continueprocessing) {
                    $line++;
                    $curline = fgets($fh);
                    $this->xmlcache .= $curline; // Add a line onto the XML cache

                    while(true){
                  // Must always make sure to remove tags from cache so they don't clog up our memory
                  if($tagcontents = $this->full_tag_found_in_cache('group', $curline)){
                          $this->remove_tag_from_cache('group');
                      }elseif($tagcontents = $this->full_tag_found_in_cache('person', $curline)){
                          $this->remove_tag_from_cache('person');
                      }elseif($tagcontents = $this->full_tag_found_in_cache('membership', $curline)){
                          $this->process_membership_tag($tagcontents);
                          $this->remove_tag_from_cache('membership');
                      }elseif($tagcontents = $this->full_tag_found_in_cache('comments', $curline)){
                          $this->remove_tag_from_cache('comments');
                      }elseif($tagcontents = $this->full_tag_found_in_cache('properties', $curline)){
                          $this->remove_tag_from_cache('properties');
                      }else{
                    break;
                  }
                }
                } // end of while loop
                fclose($fh);
            } // end of if(file_open) for second pass


           */

    		$this->log_line('');  // for ease of reading - JKR
            $timeelapsed = time() - $this->starttime;
            $this->log_line('Process has completed. Time taken: '.$timeelapsed.' seconds.');


        } // END of "if file is new"


        // These variables are stored so we can compare them against the IMS file, next time round.
        $this->set_config('prev_time', $filemtime);
        $this->set_config('prev_md5',  $md5);
        $this->set_config('prev_path', $filename);



    }else{ // end of if(file_exists)
        $this->log_line('File not found: '.$filename);
    }

	//see if we need to look at hiding course after end-date - JKR
    if($autohide) $this->process_course_autohide();

    if (!empty($mailadmins)) {
        $msg = "An IMS enrolment has been carried out within Moodle.\nTime taken: $timeelapsed seconds.\n\n";
        if(!empty($logtolocation)){
            if($this->logfp){
                $msg .= "Log data has been written to:\n";
                $msg .= "$logtolocation\n";
                $msg .= "(Log file size: ".ceil(filesize($logtolocation)/1024)."Kb)\n\n";
            }else{
                $msg .= "The log file appears not to have been successfully written.\nCheck that the file is writeable by the server:\n";
                $msg .= "$logtolocation\n\n";
            }
        }else{
            $msg .= "Logging is currently not active.";
        }

        $eventdata = new stdClass();
        $eventdata->modulename        = 'moodle';
        $eventdata->component         = 'imsenterprise';
        $eventdata->name              = 'imsenterprise_enrolment';
        $eventdata->userfrom          = get_admin();
        $eventdata->userto            = get_admin();
        $eventdata->subject           = "Moodle IMS Enterprise enrolment notification";
        $eventdata->fullmessage       = $msg;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml   = '';
        $eventdata->smallmessage      = '';
        message_send($eventdata);

        $this->log_line('Notification email sent to administrator.');

    }

    // show global error count - JKR
    $this->log_line('');  // for ease of reading - JKR
    $this->log_line("Warnings found: $this->warningCount");
    $this->log_line("Errors found: $this->errorCount");
    $this->log_line("IMS Enterprise enrol cron process finished at " . userdate(time()));
    $this->log_line('----------------------------------------------------------------------');
    
    if($this->logfp){
      fclose($this->logfp);
    }


} // end of cron() function

/**
* Check if a complete tag is found in the cached data, which usually happens
* when the end of the tag has only just been loaded into the cache.
* Returns either false, or the contents of the tag (including start and end).
* @param string $tagname Name of tag to look for
* @param string $latestline The very last line in the cache (used for speeding up the match)
*/
function full_tag_found_in_cache($tagname, $latestline){ // Return entire element if found. Otherwise return false.
    if(strpos(strtolower($latestline), '</'.strtolower($tagname).'>')===false){
        return false;
    }elseif(preg_match('{(<'.$tagname.'\b.*?>.*?</'.$tagname.'>)}is', $this->xmlcache, $matches)){
        return $matches[1];
    }else return false;
}

/**
* Remove complete tag from the cached data (including all its contents) - so
* that the cache doesn't grow to unmanageable size
* @param string $tagname Name of tag to look for
*/
function remove_tag_from_cache($tagname){ // Trim the cache so we're not in danger of running out of memory.
    ///echo "<p>remove_tag_from_cache: $tagname</p>";  flush();  ob_flush();
    //  echo "<p>remove_tag_from_cache:<br />".htmlspecialchars($this->xmlcache);
    $this->xmlcache = trim(preg_replace('{<'.$tagname.'\b.*?>.*?</'.$tagname.'>}is', '', $this->xmlcache, 1)); // "1" so that we replace only the FIRST instance
    //  echo "<br />".htmlspecialchars($this->xmlcache)."</p>";
}

/**
* Very simple convenience function to return the "recstatus" found in person/group/role tags.
* 1=Add, 2=Update, 3=Delete, as specified by IMS, and we also use 0 to indicate "unspecified".
* @param string $tagdata the tag XML data
* @param string $tagname the name of the tag we're interested in
*/
function get_recstatus($tagdata, $tagname){
    if(preg_match('{<'.$tagname.'\b[^>]*recstatus\s*=\s*["\'](\d)["\']}is', $tagdata, $matches)){
        // echo "<p>get_recstatus($tagname) found status of $matches[1]</p>";
        return intval($matches[1]);
    }else{
        // echo "<p>get_recstatus($tagname) found nothing</p>";
        return 0; // Unspecified
    }
}

/**
* Process the group tag. This defines a Moodle course.
* @param string $tagconents The raw contents of the XML element
*/
function process_group_tag($tagcontents) {
    global $DB;

    $this->log_line('');  // for ease of reading - JKR
    
    // Get configs
    $truncatecoursecodes    = $this->get_config('truncatecoursecodes');
    $createnewcourses       = $this->get_config('createnewcourses');
    $createnewcategories    = $this->get_config('createnewcategories');

    // custom settings added - JKR
    $useshortname			= $this->get_config('useshortname');
    $updatesummary			= $this->get_config('updatesummary');
    $updatecategory			= $this->get_config('updatecategory');
    $updatestartdate		= $this->get_config('updatestartdate');
    $updateshortname		= $this->get_config('updateshortname');
    $updatefullname			= $this->get_config('updatefullname');
    $updatevisibility		= $this->get_config('updatevisibility');
    $coursenotenrollable	= $this->get_config('coursenotenrollable');
    $categoryvisible		= $this->get_config('categoryvisible');
    
    // end of custom settings
    
    // Process tag contents
    $group = new stdClass();
    if (preg_match('{<sourcedid>.*?<id>(.+?)</id>.*?</sourcedid>}is', $tagcontents, $matches)) {
        $group->coursecode = trim($matches[1]);
    }
    //see if we use the course shortname - JKR
    if(intval($useshortname) > 0){
    	if (preg_match('{<description>.*?<short>(.*?)</short>.*?</description>}is', $tagcontents, $matches)) {
    		$group->shortname = trim($matches[1]);
    	}
    	if (preg_match('{<description>.*?<long>(.*?)</long>.*?</description>}is', $tagcontents, $matches)){
    		$group->description = trim($matches[1]);
    	} else {
    		$group->description = '';
    	}
    }else if(preg_match('{<description>.*?<short>(.*?)</short>.*?</description>}is', $tagcontents, $matches)) {
		$group->shortname = $group->coursecode;
		$group->description = trim($matches[1]);
    } else {
		$group->shortname = $group->coursecode;
		$group->description = $group->coursecode;
    }
    
    if (preg_match('{<description>.*?<short>(.*?)</short>.*?</description>}is', $tagcontents, $matches)) {
        $group->shortname = trim($matches[1]);
    }
      
    if (preg_match('{<description>.*?<full>(.*?)</full>.*?</description>}is', $tagcontents, $matches)) {
        $group->fulldescription = trim($matches[1]);
    }
    if (preg_match('{<org>.*?<orgunit>(.*?)</orgunit>.*?</org>}is', $tagcontents, $matches)) {
        $group->category = trim($matches[1]);
    }

    // check start date of course - JKR
    if(preg_match('{<timeframe>.*?<begin>(.*?)</begin>.*?</timeframe>}is', $tagcontents, $matches)){
    	//see if there is actual data here:
    	if(strlen($matches[1]) > 0) {
    		$group->startdate =  mktime(0,0,0,substr($matches[1],5,2),substr($matches[1],8,2),substr($matches[1],0,4));
    	}
    } else {
    	$group->startdate = '';
    }
    //also get end date - JKR
    if(preg_match('{<timeframe>.*?<end>(.*?)</end>.*?</timeframe>}is', $tagcontents, $matches)){
    	//see if there is actual data here:
    	if(strlen($matches[1]) > 0) {
    		$group->enddate =  mktime(0,0,0,substr($matches[1],5,2),substr($matches[1],8,2),substr($matches[1],0,4));
    	}
    } else {
    	$group->enddate = '';
    }
    //see if course should be visible or not - JKR
    if(preg_match('{<extension>.*?<visible>(.*?)</visible>.*?</extension>}is', $tagcontents, $matches)){
    	$group->visible =  trim($matches[1]);
    } else {
    	$group->visible = '';
    }
    //meeting-info is a tag added to contain course meeting date and time information
    //it is stored in a separate table, enrol_imsenterprise_meeting_info,
    //with zero or one row per courseid - JKR
    if(preg_match('{<extension>.*?<meeting-info>(.*?)</meeting-info>.*?</extension>}is', $tagcontents, $matches)){
    	$group->meeting_info = trim($matches[1]);
    	$this->log_line('Course ' . $group->shortname . ' meeting info: ' . $group->meeting_info);
    } else {
    	$group->meeting_info = '';
    }
    
    $recstatus = ($this->get_recstatus($tagcontents, 'group'));
    //echo "<p>get_recstatus for this group returned $recstatus</p>";

    if (!(strlen($group->coursecode)>0)) {
        $this->log_line('Error at line '.$line.': Unable to find course code in \'group\' element.');
        $this->errorCount++;
    } else {
        // First, truncate the course code if desired
        if (intval($truncatecoursecodes)>0) {
            $group->coursecode = ($truncatecoursecodes > 0)
                     ? substr($group->coursecode, 0, intval($truncatecoursecodes))
                     : $group->coursecode;
        }

        /* -----------Course aliasing is DEACTIVATED until a more general method is in place---------------

       // Second, look in the course alias table to see if the code should be translated to something else
        if($aliases = $DB->get_field('enrol_coursealias', 'toids', array('fromid'=>$group->coursecode))){
            $this->log_line("Found alias of course code: Translated $group->coursecode to $aliases");
            // Alias is allowed to be a comma-separated list, so let's split it
            $group->coursecode = explode(',', $aliases);
        }
       */

        // For compatibility with the (currently inactive) course aliasing, we need this to be an array
        $group->coursecode = array($group->coursecode);

        // Third, check if the course(s) exist
        foreach ($group->coursecode as $coursecode) {
            $coursecode = trim($coursecode);
            if (!$DB->get_field('course', 'id', array('idnumber'=>$coursecode))) {
                if (!$createnewcourses) {
                    $this->log_line("Course $coursecode not found in Moodle's course idnumbers, and not creating new course!");
                    $this->errorCount++; // shown at end - JKR
                } else {
                    // Set shortname to description or description to shortname if one is set but not the other.
                    $nodescription = !isset($group->description);
                    $noshortname = !isset($group->shortname);
                    if ( $nodescription && $noshortname) {
                        // If neither short nor long description are set let if fail
                        $this->log_line("Neither long nor short name are set for $coursecode");
                    } else if ($nodescription) {
                        // If short and ID exist, then give the long short's value, then give short the ID's value
                        $group->description = $group->shortname;
                        $group->shortname = $coursecode;
                    } else if ($noshortname) {
                        // If long and ID exist, then map long to long, then give short the ID's value.
                        $group->shortname = $coursecode;
                    }
                    // Create the (hidden) course(s) if not found
                    $courseconfig = get_config('moodlecourse'); // Load Moodle Course shell defaults
                    $course = new stdClass();
                    $course->fullname = $group->description;
                    $course->shortname = $group->shortname;
                    if (!empty($group->fulldescription)) {
                        $course->summary = format_text($group->fulldescription, FORMAT_HTML);
                    }
                    $course->idnumber = $coursecode;
                    $course->format = $courseconfig->format;
                    $course->visible = $courseconfig->visible;
                    $course->numsections = $courseconfig->numsections;
                    $course->hiddensections = $courseconfig->hiddensections;
                    $course->newsitems = $courseconfig->newsitems;
                    $course->showgrades = $courseconfig->showgrades;
                    $course->showreports = $courseconfig->showreports;
                    $course->maxbytes = $courseconfig->maxbytes;
                    $course->groupmode = $courseconfig->groupmode;
                    $course->groupmodeforce = $courseconfig->groupmodeforce;
                    $course->enablecompletion = $courseconfig->enablecompletion;
                    $course->completionstartonenrol = $courseconfig->completionstartonenrol;
                    // Insert default names for teachers/students, from the current language

                    // Handle course categorisation (taken from the group.org.orgunit field if present)
                    if (strlen($group->category)>0) {
                        // If the category is defined and exists in Moodle, we want to store it in that one
                        if ($catid = $DB->get_field('course_categories', 'id', array('name'=>$group->category))) {
                            $course->category = $catid;
                        } else if ($createnewcategories) {
                            // Else if we're allowed to create new categories, let's create this one
                            $newcat = new stdClass();
                            $newcat->name = $group->category;
                            $newcat->visible = 0;
                            
                            //if 'category visible' flag set, mark as such - JKR
                            if(intval($categoryvisible)>0) {
                            	$newcat->visible = 1;
                            }
                            
                            // add some error checcking here - JKR
                            if($catid = $DB->insert_record('course_categories', $newcat)) {
                            	$course->category = $catid;
                            	$this->log_line("Created new (" . ($newcat->visible ? "visible" : "hidden") . ") category, #$catid: $newcat->name");
                            } else {
                            	$this->log_line('Failed to create new category: '.$newcat->name);
                            	$this->errorCount++;
                            	$course->category = 1;
                            }
                        } else {
                            // If not found and not allowed to create, stick with default
                            $this->log_line('Category '.$group->category.' not found in Moodle database, so using default category instead.');
                            $course->category = 1;
                        }
                    } else {
                        $course->category = 1;
                    }
                    $course->timecreated = time();
                    $course->startdate = time();
                    // Choose a sort order that puts us at the start of the list!
                    $course->sortorder = 0;

                    //if 'not enrollable' flag set, mark as such - JKR
                    if(intval($coursenotenrollable)>0) {
                    	$course->enrollable = 0;
                    }
                    
                    $courseid = $DB->insert_record('course', $course);

                    // Setup default enrolment plugins
                    $course->id = $courseid;
                    enrol_course_updated(true, $course, null);

                    // Setup the blocks
                    $course = $DB->get_record('course', array('id' => $courseid));
                    blocks_add_default_course_blocks($course);

                    $section = new stdClass();
                    $section->course = $course->id;   // Create a default section.
                    $section->section = 0;
                    $section->summaryformat = FORMAT_HTML;
                    $section->id = $DB->insert_record("course_sections", $section);

                    add_to_log(SITEID, "course", "new", "view.php?id=$course->id", "$course->fullname (ID $course->id)");

                    $this->log_line("Created course $coursecode in Moodle (Moodle ID is $course->id)");
                    
                    //now we need to store the end date, if set - JKR
                    if( $group->enddate <> "" and is_int($group->enddate)){
                    	$autohide = new stdClass;
                    	$autohide->courseid = $course->id;
                    	$autohide->enddate = $group->enddate;
                    	//$this->log_line("Auto-hide record: " . print_r($autohide,true));
                    	if(!$DB->insert_record(TBIRD_COURSE_AUTOHIDE_TABLE,$autohide)) {
                    		$this->log_line('Error adding autohide end date to table.');
                    		$this->errorCount++;
                    	} else {
                    		$this->log_line("Created autohide end date as $group->enddate");
                    	}
                    }
                    	
                    //save the meeting information, if set - JKR
                    if($group->meeting_info <> '') {
                    	//new course, so add meeting info to table
                    	$courseinfo = new stdClass;
                    	$courseinfo->courseid = $course->id;
                    	$courseinfo->name = 'meeting-info';
                    	$courseinfo->value = $group->meeting_info;
                    	$id = $DB->insert_record(TBIRD_COURSE_INFO_TABLE,$courseinfo);
                    	if($id) {
                    		$this->log_line("Added meeting info to table, id = $id");
                    	} else {
                    		$this->log_line('Error adding meeting info to table.');
                    		$this->errorCount++;
                    	}
                    }
                    
                }
            } else if ($recstatus==3 && ($courseid = $DB->get_field('course', 'id', array('idnumber'=>$coursecode)))) {
                // If course does exist, but recstatus==3 (delete), then set the course as hidden
                $DB->set_field('course', 'visible', '0', array('id'=>$courseid));
            }
            // else we should modify an existing course - JKR
            else {
            	if ($old_course=$DB->get_record('course',array('idnumber'=>$coursecode))) {
            		//do nothing
            		//				}
            		//				if (is_object($old_course)) {
            		$this->log_line("Modifying existing course id=" . $old_course->id );
            		$course = new stdClass();
            		
            		//THIS NEEDS TO BE LOOKED AT - JKR 20101203
            		//the course->enrol field no longer exists in 2.0
            		//					if ($old_course->enrol != 'imsenterprise') {
            		//						$course->id=$old_course->id;
            		//						$course->enrol='imsenterprise';
            		//						$this->log_line($coursecode.' processed by IMS Enterprise');
            		//					}
            		if(intval($updatevisibility)>0){
            			// if we don't get a visible tag from XML,
            			// we do NOT want to modify the course visibility
            			// this is indicated by group->visible = "", see around line 508 for the tag parsing
            			// JKR 20090213
            			if($group->visible <> '') {
            				if ($old_course->visible != $group->visible && isset($group->visible)) {
            					$course->id=$old_course->id;
            					$course->visible=is_null($group->visible)?0:$group->visible;
            					if ($group->visible) {
            						$this->log_line($coursecode.': Course now visible to students');
            					} else {
            						$this->log_line($coursecode.': Course is no longer visible to students');
            					}
            				}
            			}
            		}
            		//if enabled, allow course shortname to be updated from IMS data - JKR 20090731
            		if(intval($updateshortname)>0){
            			if($old_course->shortname != addslashes($group->shortname) && !empty($group->shortname)) {
            				$course->id=$old_course->id;
            				$course->shortname=addslashes($group->shortname);
            				$this->log_line($coursecode.': Course shortname updated from '.$old_course->shortname.' to '.$course->shortname);
            			}
            		}
            		if(intval($updatefullname)>0){
            			if (intval($useshortname)==0 || strlen($group->description)==0) {
            				if ($old_course->fullname != addslashes($group->shortname) && !empty($group->shortname)) {
            					$course->id=$old_course->id;
            					$course->fullname=addslashes($group->shortname);
            					$this->log_line($coursecode.': Course fullname updated from '.$old_course->fullname.' to '.$course->fullname);
            				}
            			} else {
            				if ($old_course->fullname != addslashes($group->description) && !empty($group->description)) {
            					$course->id=$old_course->id;
            					$course->fullname=addslashes($group->description);
            					$this->log_line($coursecode.': Course fullname updated from '. $DB->get_field('course','fullname',array('id'=>$course->id)) . ' to ' . $course->fullname);
            				}
            			}
            		}
            		if(intval($updatesummary)>0){
            			if (!empty($group->fulldescription) && $old_course->summary != addslashes($group->fulldescription)) {
            				$course->id=$old_course->id;
            				$course->summary=addslashes($group->fulldescription);
            				$this->log_line($coursecode.': Course summary updated');
            			}
            		}
            		if(intval($updatecategory)>0){
            			if($catid = $DB->get_field('course_categories', 'id', array('name'=>addslashes($group->category)))){
            				if ($old_course->category != $catid && !empty($catid)) {
            					$course->id=$old_course->id;
            					$course->category=$catid;
            					$this->log_line($coursecode.': Course category changed to '.$group->category);
            				}
            			}
            		}
            	
            		if(intval($updatestartdate)>0){
            			if ($old_course->startdate != $group->startdate && !empty($group->startdate)) {
            				$course->id=$old_course->id;
            				$course->startdate=$group->startdate;
            				$this->log_line($coursecode.': Startdate updated to '.$group->startdate);
            			}
            		}
            		if (!empty($course->id)) {
            			//$this->log_line('New course object:');
            			//$this->log_line( print_r($course,true) );
            			$DB->update_record('course', $course);
            		}
            		//now we need to update the end date, if set - JKR
            		if( $group->enddate <> '' and is_int($group->enddate)){
            			$autohide = new stdClass;
            			//we cannot use $course->id
            			//as this is not set if there were no changes to course
            			$autohide->courseid = $old_course->id;
            			$autohide->enddate = $group->enddate;
            			//$this->log_line("Auto-hide record: " . print_r($autohide,true));
            			if($oldautohide = $DB->get_record(TBIRD_COURSE_AUTOHIDE_TABLE,array('courseid'=>$autohide->courseid))) {
            				//need record id for update!
            				$autohide->id = $oldautohide->id;
            				$DB->update_record(TBIRD_COURSE_AUTOHIDE_TABLE,$autohide);
            				$this->log_line($coursecode.': Enddate updated to '.$group->enddate);
            			} else {
            				$DB->insert_record(TBIRD_COURSE_AUTOHIDE_TABLE,$autohide);
            				$this->log_line($coursecode.': Enddate set to '.$group->enddate);
            			}
            		}
            		//update or save the meeting info here - JKR 20100524
            		if($group->meeting_info <> '') {
            			//update record if exists
            			$courseinfo = new stdClass;
            			$infoselect = array('courseid'=>$old_course->id,'name'=>'meeting-info');
            			if($oldcourseinfo = $DB->get_record(TBIRD_COURSE_INFO_TABLE,$infoselect)) {
            				$courseinfo->id = $oldcourseinfo->id;
            				$courseinfo->courseid = $oldcourseinfo->courseid;
            				$courseinfo->name = 'meeting-info';
            				$courseinfo->value = $group->meeting_info;
            				$DB->update_record(TBIRD_COURSE_INFO_TABLE,$courseinfo);
            				$this->log_line("Meeting info updated.");
            	
            			} else {
            				//else add this information
            				$courseinfo->courseid = $old_course->id;
            				$courseinfo->name = 'meeting-info';
            				$courseinfo->value = $group->meeting_info;
            				if($id = $DB->insert_record(TBIRD_COURSE_INFO_TABLE,$courseinfo)) {
            					$this->log_line("Meeting info added to table.");
            				} else {
            					$this->log_line("Error adding new meeting info to table.");
            					$this->errorCount++;
            				}
            			}
            		}
            	}
            } // end of else update existing course - JKR
            
        } // End of foreach(coursecode)
    }
    
    // return to keep for snapshot unenroll - JKR
    return $group->coursecode;
    
} // End process_group_tag()

/**
* Process the person tag. This defines a Moodle user.
* @param string $tagconents The raw contents of the XML element
*/
function process_person_tag($tagcontents){
    global $CFG, $DB;

    $this->log_line('');  // for ease of reading - JKR
    
    // Get plugin configs
    $imssourcedidfallback   = $this->get_config('imssourcedidfallback');
    $fixcaseusernames       = $this->get_config('fixcaseusernames');
    $fixcasepersonalnames   = $this->get_config('fixcasepersonalnames');
    $imsdeleteusers         = $this->get_config('imsdeleteusers');
    $createnewusers         = $this->get_config('createnewusers');

    // custom settings added - JKR
    $defaultauthentication  = $this->get_config('defaultauthentication');
    $defaultmanualpassword	= $this->get_config('defaultmanualpassword');
    $forcepasswordchange    = $this->get_config('forcepasswordchange');
    $updateuseremails		= $this->get_config('updateuseremails');
    $updateuserurls			= $this->get_config('updateuserurls');
    
    $person = new stdClass();
    if(preg_match('{<sourcedid>.*?<id>(.+?)</id>.*?</sourcedid>}is', $tagcontents, $matches)){
        $person->idnumber = trim($matches[1]);
    }
    if(preg_match('{<name>.*?<n>.*?<given>(.+?)</given>.*?</n>.*?</name>}is', $tagcontents, $matches)){
        $person->firstname = trim($matches[1]);
    }
    if(preg_match('{<name>.*?<n>.*?<family>(.+?)</family>.*?</n>.*?</name>}is', $tagcontents, $matches)){
        $person->lastname = trim($matches[1]);
    }
    if(preg_match('{<userid>(.*?)</userid>}is', $tagcontents, $matches)){
        $person->username = trim($matches[1]);
    }
    if($imssourcedidfallback && trim($person->username)==''){
      // This is the point where we can fall back to useing the "sourcedid" if "userid" is not supplied
      // NB We don't use an "elseif" because the tag may be supplied-but-empty
        $person->username = $person->idnumber;
    }
    if(preg_match('{<email>(.*?)</email>}is', $tagcontents, $matches)){
        $person->email = trim($matches[1]);
    }
    if(preg_match('{<url>(.*?)</url>}is', $tagcontents, $matches)){
        $person->url = trim($matches[1]);
    }
    if(preg_match('{<adr>.*?<locality>(.+?)</locality>.*?</adr>}is', $tagcontents, $matches)){
        $person->city = trim($matches[1]);
    }
    if(preg_match('{<adr>.*?<country>(.+?)</country>.*?</adr>}is', $tagcontents, $matches)){
        $person->country = trim($matches[1]);
    }

    //optional extension for initial password - JKR
    //only used for 'manual' accounts (see below near line 825)
    if(preg_match('{<extension>.*?<password>(.*?)</password>.*?</extension>}is', $tagcontents, $matches)){
    	$person->newpassword =  trim($matches[1]);
    	$this->log_line("Setting manual default password from XML file");
    } else {
    	//use global default password
    	// $this->log_line("Setting manual default password to default: '" . $CFG->enrol_defaultmanualpassword . "'");
    	$person->newpassword = $defaultmanualpassword;
    }
    // $this->log_line("Setting newpassword to: '" . $person->newpassword . "'");
    
    // Fix case of some of the fields if required
    if($fixcaseusernames && isset($person->username)){
        $person->username = strtolower($person->username);
    }
    if($fixcasepersonalnames){
        if(isset($person->firstname)){
            $person->firstname = ucwords(strtolower($person->firstname));
        }
        if(isset($person->lastname)){
            $person->lastname = ucwords(strtolower($person->lastname));
        }
    }

    $recstatus = ($this->get_recstatus($tagcontents, 'person'));


    // Now if the recstatus is 3, we should delete the user if-and-only-if the setting for delete users is turned on
    // In the "users" table we can do this by setting deleted=1
    if($recstatus==3){

        if($imsdeleteusers){ // If we're allowed to delete user records
            // Make sure their "deleted" field is set to one
            $DB->set_field('user', 'deleted', 1, array('username'=>$person->username));
            $this->log_line("Marked user record for user '$person->username' (ID number $person->idnumber) as deleted.");
        }else{
            $this->log_line("Ignoring deletion request for user '$person->username' (ID number $person->idnumber).");
        }

    }else{ // Add or update record

        // If the user exists (matching sourcedid) then we don't need to do anything.
        if(!$DB->get_field('user', 'id', array('idnumber'=>$person->idnumber)) && $createnewusers){
            // If they don't exist and haven't a defined username, we log this as a potential problem.
            if((!isset($person->username)) || (strlen($person->username)==0)){
                $this->log_line("Cannot create new user for ID # $person->idnumber - no username listed in IMS data for this person.");
                $this->errorCount++;
            } else if ($DB->get_field('user', 'id', array('username'=>$person->username))){
                // If their idnumber is not registered but their user ID is, then add their idnumber to their record
                $DB->set_field('user', 'idnumber', $person->idnumber, array('username'=>$person->username));
            } else {

            // If they don't exist and they have a defined username, and $createnewusers == true, we create them.
            $person->lang = 'manual'; //TODO: this needs more work due to multiauth changes
            $person->auth = $defaultauthentication;
            
            //always set the local (manual) default password
            $person->password =  hash_internal_user_password($person->newpassword);
                 
            $person->confirmed = 1;
            $person->timemodified = time();
            $person->mnethostid = $CFG->mnet_localhost_id;
            $id = $DB->insert_record('user', $person);

            if($forcepasswordchange and 'manual' == $person->auth) {
                set_user_preference('auth_forcepasswordchange', '1', $id);
            }
            
    /*
    Photo processing is deactivated until we hear from Moodle dev forum about modification to gdlib.

                                 //Antoni Mas. 07/12/2005. If a photo URL is specified then we might want to load
                                 // it into the user's profile. Beware that this may cause a heavy overhead on the server.
                                 if($CFG->enrol_processphoto){
                                   if(preg_match('{<photo>.*?<extref>(.*?)</extref>.*?</photo>}is', $tagcontents, $matches)){
                                     $person->urlphoto = trim($matches[1]);
                                   }
                                   //Habilitam el flag que ens indica que el personatge t foto prpia.
                                   $person->picture = 1;
                                   //Llibreria creada per nosaltres mateixos.
                                   require_once($CFG->dirroot.'/lib/gdlib.php');
                                   if ($usernew->picture = save_profile_image($id, $person->urlphoto,'user')) { TODO: use process_new_icon() instead
                                     $DB->set_field('user', 'picture', $usernew->picture, array('id'=>$id));  /// Note picture in DB
                                   }
                                 }
    */
                $this->log_line("Created user record for user '$person->username' (ID number $person->idnumber).");
            }
        } elseif ($createnewusers) {
            $this->log_line("User record already exists for user '$person->username' (ID number $person->idnumber).");
            
            //we are going to update their person data - JKR
            $msg=false;
            $orig_person=$DB->get_record('user',array('idnumber'=>$person->idnumber));
            //we actually want to allow first name to be changed from IMS data
            //$person->firstname = isset($person->firstname) ? $person->firstname : null;
            //if (!$orig_person->firstname && $person->firstname) {
            //	$msg.="User $person->username  firstname updated to $person->firstname.\n";
            //} else {
            //	//don't want to update a first name by accident
            //	$person->firstname=$orig_person->firstname;
            //}
            $person->firstname = isset($person->firstname) ? $person->firstname : null;
            if ($orig_person->firstname != $person->firstname) {
            	$msg.="User $person->username firstname updated from $orig_person->firstname to $person->firstname.\n";
            }
            $person->lastname = isset($person->lastname) ? $person->lastname : null;
            if ($orig_person->lastname != $person->lastname) {
            	$msg.="User $person->username lastname updated from $orig_person->lastname to $person->lastname.\n";
            }
            $person->email = isset($person->email) ? $person->email : null;
            if(intval($updateuseremails)>0){
            	if ($orig_person->email != $person->email) {
            		$msg.="User email updated from $orig_person->email to $person->email.\n";
            	}
            }
            
			// this still needs testing - JKR        
            if($orig_person->username != $person->username){
            	$msg .= "Username changing from $orig_person->username to $person->username\n";
            	if ($actual_person_id=$DB->get_field('user','id',array('username' => $person->username))) {
            		if ($orig_person->lastlogin < 1 && $actual_person_id != $orig_person->id) {
            			$orig_person->deleted=1;
            			$DB->update_record('user', $orig_person);
            			$msg.="Mismatching username! Change status to deleted of user with idnumber: ".$person->idnumber;
            			$msg.="\n And change person being updated from moodle user id ".$orig_person->id." to ".$actual_person_id;
            			$orig_person->id=$actual_person_id;
            		} else {
            			$mail_msg = "IMS-E has passed a user with idnumber: $person->idnumber.  Moodle has a record of a user with this idnumber, but it has a different username than the one passed ($orig_person->username -> $person->username).  This person has logged in before so no automated correction process was possible.";
            			email_to_user(get_admin(), get_admin(), "SyD: mismatching record passed via IMS-E", $mail_msg);
            			$this->log_line('Mismatching record passed via IMS-E for person with idnumber:'.$person->idnumber);
            		}
            	}
            }

			$person->url = isset($person->url) ? $person->url : null;
            if(intval($updateuserurls)>0){
            	if (!$orig_person->url && $person->url) {
            		$msg.="User url updated to $person->url.";
            	} else {
            		//don't want to update a url by accident
            		$person->url=$orig_person->url;
            	}
            } else {
				$person->url=$orig_person->url;
            }
            if ($msg) {
				$person->id=$orig_person->id;
				$DB->update_record('user', $person);
				$this->log_line("$msg");
            } else {
				$this->log_line("No changes found for exising user record '$person->username' (ID number $person->idnumber).");
            }
            

            // Make sure their "deleted" field is set to zero.
            $DB->set_field('user', 'deleted', 0, array('idnumber'=>$person->idnumber));
        }else{
            $this->log_line("No user record found for '$person->username' (ID number $person->idnumber).");
        }

    } // End of are-we-deleting-or-adding

} // End process_person_tag()

/**
* Process the membership tag. This defines whether the specified Moodle users
* should be added/removed as teachers/students.
* @param string $tagconents The raw contents of the XML element
*/
function process_membership_tag($tagcontents){
    global $DB;

    $this->log_line('');  // for ease of reading - JKR
    
    // Get plugin configs
    $truncatecoursecodes = $this->get_config('truncatecoursecodes');
    $imscapitafix = $this->get_config('imscapitafix');

    $memberstally = 0;
    $membersuntally = 0;
    
    //here we store all enrolments as coming from the current IMS file - JKR
    $centralmembers = array();
    
    // In order to reduce the number of db queries required, group name/id associations are cached in this array:
    $groupids = array();

    $ship = new stdClass();

    if(preg_match('{<sourcedid>.*?<id>(.+?)</id>.*?</sourcedid>}is', $tagcontents, $matches)){
        $ship->coursecode = ($truncatecoursecodes > 0)
                                 ? substr(trim($matches[1]), 0, intval($truncatecoursecodes))
                                 : trim($matches[1]);
        $ship->courseid = $DB->get_field('course', 'id', array('idnumber'=>$ship->coursecode));
    }
    if($ship->courseid && preg_match_all('{<member>(.*?)</member>}is', $tagcontents, $membermatches, PREG_SET_ORDER)){
        $courseobj = new stdClass();
        $courseobj->id = $ship->courseid;

        // some more verbose logging - JKR
        $this->log_line('');
        $this->log_line("Start enrolling users to course SOURCEDID $ship->coursecode, Moodle id = $courseobj->id");
        
        
        foreach($membermatches as $mmatch){
            $member = new stdClass();
            $memberstoreobj = new stdClass();
            if(preg_match('{<sourcedid>.*?<id>(.+?)</id>.*?</sourcedid>}is', $mmatch[1], $matches)){
                $member->idnumber = trim($matches[1]);
            }
            if(preg_match('{<role\s+roletype=["\'](.+?)["\'].*?>}is', $mmatch[1], $matches)){
                $member->roletype = trim($matches[1]); // 01 means Student, 02 means Instructor, 3 means ContentDeveloper, and there are more besides
            } elseif($imscapitafix && preg_match('{<roletype>(.+?)</roletype>}is', $mmatch[1], $matches)){
                // The XML that comes out of Capita Student Records seems to contain a misinterpretation of the IMS specification!
                $member->roletype = trim($matches[1]); // 01 means Student, 02 means Instructor, 3 means ContentDeveloper, and there are more besides
            }
            if(preg_match('{<role\b.*?<status>(.+?)</status>.*?</role>}is', $mmatch[1], $matches)){
                $member->status = trim($matches[1]); // 1 means active, 0 means inactive - treat this as enrol vs unenrol
            }

            $recstatus = ($this->get_recstatus($mmatch[1], 'role'));
            if($recstatus==3){
              $member->status = 0; // See above - recstatus of 3 (==delete) is treated the same as status of 0
              //echo "<p>process_membership_tag: unenrolling member due to recstatus of 3</p>";
            }

            $timeframe = new stdClass();
            $timeframe->begin = 0;
            $timeframe->end = 0;
            if(preg_match('{<role\b.*?<timeframe>(.+?)</timeframe>.*?</role>}is', $mmatch[1], $matches)){
                $timeframe = $this->decode_timeframe($matches[1]);
            }
            if(preg_match('{<role\b.*?<extension>.*?<cohort>(.+?)</cohort>.*?</extension>.*?</role>}is', $mmatch[1], $matches)){
                $member->groupname = trim($matches[1]);
                // The actual processing (ensuring a group record exists, etc) occurs below, in the enrol-a-student clause
            }

            $rolecontext = get_context_instance(CONTEXT_COURSE, $ship->courseid);
            $rolecontext = $rolecontext->id; // All we really want is the ID
//$this->log_line("Context instance for course $ship->courseid is...");
//print_r($rolecontext);

            // Add or remove this student or teacher to the course...
            $memberstoreobj->userid = $DB->get_field('user', 'id', array('idnumber'=>$member->idnumber));
            $memberstoreobj->enrol = 'imsenterprise';
            $memberstoreobj->course = $ship->courseid;
            $memberstoreobj->time = time();
            $memberstoreobj->timemodified = time();
            if($memberstoreobj->userid){

                // Decide the "real" role (i.e. the Moodle role) that this user should be assigned to.
                // Zero means this roletype is supposed to be skipped.
                $moodleroleid = $this->rolemappings[$member->roletype];
                if(!$moodleroleid) {
                    $this->log_line("SKIPPING role $member->roletype for $memberstoreobj->userid ($member->idnumber) in course $memberstoreobj->course");
                    continue;
                }

                if(intval($member->status) == 1) {
                    // Enrol the member

                    $einstance = $DB->get_record('enrol',
                                    array('courseid' => $courseobj->id, 'enrol' => $memberstoreobj->enrol));
                    if (empty($einstance)) {
                        // Only add an enrol instance to the course if non-existent

                    	// more verbose log - JKR
                    	$this->log_line('Adding enrol instance to course');
                    	 
                        $enrolid = $this->add_instance($courseobj);
                        $einstance = $DB->get_record('enrol', array('id' => $enrolid));
                    }

                    $this->enrol_user($einstance, $memberstoreobj->userid, $moodleroleid, $timeframe->begin, $timeframe->end);

                    $this->log_line("Enrolled user #$memberstoreobj->userid ($member->idnumber) to role $member->roletype in course $memberstoreobj->course");

                    // track for snapshot unenroll - JKR
                    $memberstoreobj->roleid=$moodleroleid;
					$centralmembers[]=$memberstoreobj;
					
					$memberstally++;

                    // At this point we can also ensure the group membership is recorded if present
                    if(isset($member->groupname)){
                        // Create the group if it doesn't exist - either way, make sure we know the group ID
                        if(isset($groupids[$member->groupname])) {
                            $member->groupid = $groupids[$member->groupname]; // Recall the group ID from cache if available
                        } else {
                            if($groupid = $DB->get_field('groups', 'id', array('courseid'=>$ship->courseid, 'name'=>$member->groupname))){
                                $member->groupid = $groupid;
                                $groupids[$member->groupname] = $groupid; // Store ID in cache
                            } else {
                                // Attempt to create the group
                                $group = new stdClass();
                                $group->name = $member->groupname;
                                $group->courseid = $ship->courseid;
                                $group->timecreated = time();
                                $group->timemodified = time();
                                $groupid = $DB->insert_record('groups', $group);
                                $this->log_line('Added a new group for this course: '.$group->name);
                                $groupids[$member->groupname] = $groupid; // Store ID in cache
                                $member->groupid = $groupid;
                            }
                        }
                        // Add the user-to-group association if it doesn't already exist
                        if($member->groupid) {
                            groups_add_member($member->groupid, $memberstoreobj->userid);
                        }
                    } // End of group-enrolment (from member.role.extension.cohort tag)

                } elseif ($this->get_config('imsunenrol')) {
                    // Unenrol member

                    $einstances = $DB->get_records('enrol',
                                    array('enrol' => $memberstoreobj->enrol, 'courseid' => $courseobj->id));
                    foreach ($einstances as $einstance) {
                        // Unenrol the user from all imsenterprise enrolment instances
                        $this->unenrol_user($einstance, $memberstoreobj->userid);
                    }

                    $membersuntally++;
                    $this->log_line("Unenrolled $member->idnumber from role $moodleroleid in course");
                }

            } else {	// user not found, error - JKR
            	$this->log_line("Error: user $member->idnumber NOT FOUND!");
            	$this->errorCount++;
            }
        }
        $this->log_line("Added $memberstally users to course $ship->coursecode");
        if($membersuntally > 0){
            $this->log_line("Removed $membersuntally users from course $ship->coursecode");
        }
    }
    
    // return member list for snapshot unenrol - JKR
    return $centralmembers;
    
} // End process_membership_tag()



/**
 * Snapshot unenrol. Compares Moodle users with IMS Enterprise users
 * @param array $coursecodes of the courses created
 * @param array $central_member_list of the membership objects in IMS Enterprise spec
 */

function snapshot_unenrol($coursecodes,$central_member_list) {
	global $DB;
	
    $this->log_line('');  // for ease of reading - JKR
	$this->log_line('--- snapshot_unenrol() started ---');
	// $this->log_line( "coursecode parameter:\n" . print_r($coursecodes,true) );
	// $this->log_line( "central_member_list parameter:\n" . print_r($central_member_list,true) );

	//get all students in the moodle database
	//not needed, already set as array() - JKR
	//$coursecodes = isset($coursecodes) ? $coursecodes : array();
	$central_userids=array();
	

	// Build the central_userids array before the course loop - APG
	if (isset($central_member_list)) {
		//build list of IMS users in this role in all current IMS course data
		foreach ($central_member_list as $central_member_array) {
			//make array of the members in central records by course
			foreach ($central_member_array as $central_member_object) {
				if (is_object($central_member_object)) {
						$central_userids[$central_member_object->course][]=$central_member_object->userid;
				}
			}
		}
	} else {
		$central_member_list=array();
	}
	
	
	// $coursecodes is an array of arrays, not an array of values as in v1.9
	// this it to handle Course Aliasing, see process_group_tag() above - JKR
	foreach($coursecodes as $courseidlist) {
		$keep_informal=true;
		
		// NOTE: this only looks at the first course in this record (ie. $courseidlist[0] )
		// if Course Aliasing, see process_group_tag() above, is ever implemented, we need to redo this - JKR
		$courseidnumber = $courseidlist[0];
		
		$course = $DB->get_record('course',array('idnumber'=>$courseidnumber));
		$this->log_line("IMS COURSE: " . $courseidnumber . "(id=" . $course->id . ")" );


		$context = get_context_instance(CONTEXT_COURSE,$course->id);
		//$this->log_line("Getting role records\n");
		$roles=$DB->get_records('role');
		
		// Grab the enrolment group for imsenterprise so that we can ensure only IMS users are removed - APG
		//$this->log_line("Getting enrolment_group records\n");
		$enrolment_group = $DB->get_record('enrol', array('courseid'=>$course->id, 'enrol'=>'imsenterprise'));
		if($enrolment_group) {

			foreach ($roles as $role) {
				//$role->name is invalid in >= 2.4, use role_get_name($role) - JKR
				//$this->log_line("  ROLE: $role->id (" . role_get_name($role) . ")" );
				
				//get list of Moodle users in this role in  course
				
				// This now has a pre-check for the enrolment group that is imsenterprise so we no longer have to check for it later - APG
				// In 2.4, cannot used mixed parameters, so last where clause is collapsed into single statement, with empty parameters - JKR 20130514
			    if ($contextusers = get_role_users($role->id, $context, false, 'u.id,u.username,ra.roleid, ra.itemid',
			        			'u.id', null,'', '', '', 'ra.itemid = ' . $enrolment_group->id, null)) {
			        			 
					//$this->log_line( "    MOODLE contextusers:\n" . print_r($contextusers,true));
					
					//show all IMS users in this role for the current class.
					//$this->log_line( "    IMS central_userids:\n" . print_r($central_userids,true));
					//loop through moodle users in this role and compare with IMS users
					foreach ($contextusers as $moodle_user) {
						//$this->log_line("    MOODLE CONTEXT SINGLE USER: $moodle_user->id ($moodle_user->username, Enrol=$moodle_user->itemid, Keep=$keep_informal)");
						if (!in_array($moodle_user->id,$central_userids[$course->id])) {
							$this->unenrol_user($enrolment_group, $moodle_user->id);
							$this->log_line("User $moodle_user->username removed from role $role->id from course site $course->id because no longer has this role in central database.");
						}
					}  /* for each moodle/context user */
				}  /* if contextusers */
			}  /* for each role */
		}  /* if enrolment_group exists */
		else {
			$this->log_line("WARNING: enrolment_group 'imsenterprise' NOT found for this course!");
			$this->warningCount++;
		}
	}  /* for each course */
	
	$this->log_line("--- snapshot_unenrol() finished ---");
}



/**
 * run through the mdl_TBIRD_COURSE_AUTOHIDE_TABLE table and find courses that need to be hidden
 * JKR 20091004 and later
 */
function process_course_autohide() {
	global $DB;

	//get settings
	$autohidecourse				= $this->get_config('autohidecourse');
	$autohidecourselastrun		= $this->get_config('autohidecourselastrun');
	$autohidecourseafterndays	= $this->get_config('autohidecourseafterndays');
	$autohidecoursehourtorun	= $this->get_config('autohidecoursehourtorun');

	//use a Moodle lib call to get current hour, with timezone and DST adjustments
	$starthour = intval(userdate($this->starttime,"%H"));
	$hourtorun = intval($autohidecoursehourtorun);
    $this->log_line('');  // for ease of reading - JKR
	$this->log_line("Checking if course auto-hide needs to run (run in hour $hourtorun, current hour $starthour):");
	//$this->log_line("  Last ran at "  . date("r",intval($this->get_config('autohidecourselastrun'))) . "\n  Now = " . date("r",$starttime) . ", hour = $starthour");
	//this complicated logic is mostly for debugging - JKR
	if($hourtorun > 0) {
		if($starthour == $hourtorun) {
			//$this->log_line("  Hour OK.");
			$this->log_line("Starttime: " . $this->starttime . "  Last run: " . intval($autohidecourselastrun));
			if($this->starttime - intval($autohidecourselastrun) > (24 * 60 * 60)) {
				$this->log_line("  YES: Running course auto-hide.");
				$lastdatetohide = $this->starttime - (intval($autohidecourseafterndays) * 24 * 60 * 60);
				$this->log_line("  Most recent end date to hide: " . date("r",$lastdatetohide) . " (" . $lastdatetohide . ")");
				//find old courses not hidden yet
				$select = "hiddendate = 0 and enddate < $lastdatetohide";
				//$this->log_line("  SELECT query: " . $select);
				$courseshidden = 0;
				//this needs to be fixed!
				if($rs = $DB->get_recordset_select(TBIRD_COURSE_AUTOHIDE_TABLE,$select)) {
					//go hide these courses
					foreach ($rs as $row) {
						//while($row = $rs->FetchNextObj()) {
						$this->log_line('  Hiding course ' . $row->courseid);
						$DB->set_field('course', 'visible', '0', array('id'=>$row->courseid));
						$row->hiddendate = $this->starttime;
						if(!$DB->update_record(TBIRD_COURSE_AUTOHIDE_TABLE,$row)) {
							$this->log_line('Error updating auto-hide table.');
							$this->errorCount++;
						} else {
							$courseshidden++;
						}
					}
				}
				$this->log_line('  Courses hidden: ' . $courseshidden);
				//update the last run value
				$this->set_config('autohidecourselastrun', $this->starttime);
			} else {
				$this->log_line('  NO: Already ran in this hour.');
			}
		} else {
			$this->log_line('  NO - wrong hour.');
		}
	} else {
		$this->log_line('  NO - not enabled.');
	}
}

// end of custom additions - JKR


/**
* Process the properties tag. The only data from this element
* that is relevant is whether a <target> is specified.
* @param string $tagconents The raw contents of the XML element
*/
function process_properties_tag($tagcontents){
    $imsrestricttarget = $this->get_config('imsrestricttarget');

    if ($imsrestricttarget) {
        if(!(preg_match('{<target>'.preg_quote($imsrestricttarget).'</target>}is', $tagcontents, $matches))){
            $this->log_line("Skipping processing: required target \"$imsrestricttarget\" not specified in this data.");
            $this->continueprocessing = false;
        }
    }
}

/**
* Store logging information. This does two things: uses the {@link mtrace()}
* function to print info to screen/STDOUT, and also writes log to a text file
* if a path has been specified.
* @param string $string Text to write (newline will be added automatically)
*/
function log_line($string){
    mtrace($string);
    if($this->logfp) {
        fwrite($this->logfp, $string . "\n");
    }
}

/**
* Process the INNER contents of a <timeframe> tag, to return beginning/ending dates.
*/
function decode_timeframe($string){ // Pass me the INNER CONTENTS of a <timeframe> tag - beginning and/or ending is returned, in unix time, zero indicating not specified
    $ret = new stdClass();
    $ret->begin = $ret->end = 0;
    // Explanatory note: The matching will ONLY match if the attribute restrict="1"
    // because otherwise the time markers should be ignored (participation should be
    // allowed outside the period)
    if(preg_match('{<begin\s+restrict="1">(\d\d\d\d)-(\d\d)-(\d\d)</begin>}is', $string, $matches)){
        $ret->begin = mktime(0,0,0, $matches[2], $matches[3], $matches[1]);
    }
    if(preg_match('{<end\s+restrict="1">(\d\d\d\d)-(\d\d)-(\d\d)</end>}is', $string, $matches)){
        $ret->end = mktime(0,0,0, $matches[2], $matches[3], $matches[1]);
    }
    return $ret;
} // End decode_timeframe

/**
* Load the role mappings (from the config), so we can easily refer to
* how an IMS-E role corresponds to a Moodle role
*/
function load_role_mappings() {
    require_once('locallib.php');
    global $DB;

    $imsroles = new imsenterprise_roles();
    $imsroles = $imsroles->get_imsroles();

    $this->rolemappings = array();
    foreach($imsroles as $imsrolenum=>$imsrolename) {
        $this->rolemappings[$imsrolenum] = $this->rolemappings[$imsrolename] = $this->get_config('imsrolemap' . $imsrolenum);
    }
}

} // end of class


