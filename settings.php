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
 * IMS Enterprise enrolments plugin settings and presets.
 *
 * @package    enrol
 * @subpackage imsenterprise
 * @copyright  2010 Eugene Venter
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot.'/enrol/imsenterprise/locallib.php');

    $settings->add(new admin_setting_heading('enrol_imsenterprise_settings', '', get_string('pluginname_desc', 'enrol_imsenterprise')));

    //--- general settings -----------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_imsenterprise_basicsettings', get_string('basicsettings', 'enrol_imsenterprise'), ''));

    $settings->add(new admin_setting_configtext('enrol_imsenterprise/imsfilelocation', get_string('location', 'enrol_imsenterprise'), '', ''));

    $settings->add(new admin_setting_configtext('enrol_imsenterprise/logtolocation', get_string('logtolocation', 'enrol_imsenterprise'), '', ''));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/mailadmins', get_string('mailadmins', 'enrol_imsenterprise'), '', 0));

    //--- user data options ---------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_imsenterprise_usersettings', get_string('usersettings', 'enrol_imsenterprise'), ''));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/createnewusers', get_string('createnewusers', 'enrol_imsenterprise'), get_string('createnewusers_desc', 'enrol_imsenterprise'), 0));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/imsdeleteusers', get_string('deleteusers', 'enrol_imsenterprise'), get_string('deleteusers_desc', 'enrol_imsenterprise'), 0));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/fixcaseusernames', get_string('fixcaseusernames', 'enrol_imsenterprise'), '', 0));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/fixcasepersonalnames', get_string('fixcasepersonalnames', 'enrol_imsenterprise'), '', 0));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/imssourcedidfallback', get_string('sourcedidfallback', 'enrol_imsenterprise'), get_string('sourcedidfallback_desc', 'enrol_imsenterprise'), 0));
    
/**
 * custom additions
 */
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updateuserlastname', get_string('update_userlastname', 'enrol_imsenterprise'), '', 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updateuseremails', get_string('update_useremails', 'enrol_imsenterprise'), '', 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updateuserurls', get_string('update_userurls', 'enrol_imsenterprise'), '', 0));
    
	$auths = get_plugin_list('auth');
	$auth_options = array();
	foreach ($auths as $auth => $unused) {
		$auth_options[$auth] = get_string('pluginname', "auth_{$auth}");
	}
    $settings->add(new admin_setting_configselect('enrol_imsenterprise/defaultauthentication', get_string('defaultauthentication', 'enrol_imsenterprise'), '', '', $auth_options));

    $settings->add(new admin_setting_configtext('enrol_imsenterprise/defaultmanualpassword', get_string('defaultmanualpassword', 'enrol_imsenterprise'), '', ''));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/forcepasswordchange', get_string('forcepasswordchange', 'enrol_imsenterprise'), get_string('forcepasswordchangedescription', 'enrol_imsenterprise'), 0));

/**
 * end of custom additions
 */

    $settings->add(new admin_setting_heading('enrol_imsenterprise_usersettings_roles', get_string('roles', 'enrol_imsenterprise'), get_string('imsrolesdescription', 'enrol_imsenterprise')));

    if (!during_initial_install()) {
        $coursecontext = context_course::instance(SITEID);
        $assignableroles = get_assignable_roles($coursecontext);
        $assignableroles = array('0' => get_string('ignore', 'enrol_imsenterprise')) + $assignableroles;
        $imsroles = new imsenterprise_roles();
        foreach ($imsroles->get_imsroles() as $imsrolenum => $imsrolename) {
            $settings->add(new admin_setting_configselect('enrol_imsenterprise/imsrolemap'.$imsrolenum, format_string('"'.$imsrolename.'" ('.$imsrolenum.')'), '', (int)$imsroles->determine_default_rolemapping($imsrolenum), $assignableroles));
        }
    }

    //--- course data options -------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_imsenterprise_coursesettings', get_string('coursesettings', 'enrol_imsenterprise'), ''));

    $settings->add(new admin_setting_configtext('enrol_imsenterprise/truncatecoursecodes', get_string('truncatecoursecodes', 'enrol_imsenterprise'), get_string('truncatecoursecodes_desc', 'enrol_imsenterprise'), 0, PARAM_INT, 2));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/createnewcourses', get_string('createnewcourses', 'enrol_imsenterprise'), get_string('createnewcourses_desc', 'enrol_imsenterprise'), 0));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/createnewcategories', get_string('createnewcategories', 'enrol_imsenterprise'), get_string('createnewcategories_desc', 'enrol_imsenterprise'), 0));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/imsunenrol', get_string('allowunenrol', 'enrol_imsenterprise'), get_string('allowunenrol_desc', 'enrol_imsenterprise'), 0));
    
/**
 * custom additions
 */
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/snapshotunenrol', get_string('snapshotunenrol', 'enrol_imsenterprise'), get_string('snapshotunenrol_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/useshortname', get_string('useshortname', 'enrol_imsenterprise'), get_string('useshortname_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updatevisibility', get_string('updatevisibility', 'enrol_imsenterprise'), get_string('updatevisibility_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updateshortname', get_string('updateshortname', 'enrol_imsenterprise'), get_string('updateshortname_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updatefullname', get_string('updatefullname', 'enrol_imsenterprise'), get_string('updatefullname_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updatesummary', get_string('updatesummary', 'enrol_imsenterprise'), get_string('updatesummary_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updatecategory', get_string('updatecategory', 'enrol_imsenterprise'), get_string('updatecategory_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/updatestartdate', get_string('updatestartdate', 'enrol_imsenterprise'), get_string('updatestartdate_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/coursenotenrollable', get_string('coursenotenrollable', 'enrol_imsenterprise'), get_string('coursenotenrollable_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/categoryvisible', get_string('categoryvisible', 'enrol_imsenterprise'), get_string('categoryvisible_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/autohidecourse', get_string('autohidecourse', 'enrol_imsenterprise'), get_string('autohidecourse_desc', 'enrol_imsenterprise'), 0));
    
    $settings->add(new admin_setting_configtext('enrol_imsenterprise/autohidecourseafterndays', get_string('autohidecourseafterndays', 'enrol_imsenterprise'), get_string('autohidecourseafterndays_desc', 'enrol_imsenterprise'), 0, PARAM_INT, 3));
    
    $settings->add(new admin_setting_configtext('enrol_imsenterprise/autohidecoursehourtorun', get_string('autohidecoursehourtorun', 'enrol_imsenterprise'), get_string('autohidecoursehourtorun_desc', 'enrol_imsenterprise'), 0, PARAM_INT, 2));
    
    //this needs to be a read-only field in this form.
    $settings->add(new admin_setting_configtext('enrol_imsenterprise/autohidecourselastrun', get_string('autohidecourselastrun', 'enrol_imsenterprise'), get_string('autohidecourselastrun_desc', 'enrol_imsenterprise'), 'Never'));
    
    
    
/**
 * end of custom additions
 */
    
    //--- miscellaneous -------------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_imsenterprise_miscsettings', get_string('miscsettings', 'enrol_imsenterprise'), ''));

    $settings->add(new admin_setting_configtext('enrol_imsenterprise/imsrestricttarget', get_string('restricttarget', 'enrol_imsenterprise'), get_string('restricttarget_desc', 'enrol_imsenterprise'), ''));

    $settings->add(new admin_setting_configcheckbox('enrol_imsenterprise/imscapitafix', get_string('usecapitafix', 'enrol_imsenterprise'), get_string('usecapitafix_desc', 'enrol_imsenterprise'), 0));

    $importnowstring = get_string('aftersaving...', 'enrol_imsenterprise').' <a href="../enrol/imsenterprise/importnow.php">'.get_string('doitnow', 'enrol_imsenterprise').'</a>';
    $settings->add(new admin_setting_heading('enrol_imsenterprise_doitnowmessage', '', $importnowstring));
}
