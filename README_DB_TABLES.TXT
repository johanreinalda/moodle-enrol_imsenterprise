===========================================================
NOTE: in the production TLE, these tables already exist!!!!
===========================================================

In a clean Moodle 2 install, these need to be created by hand, since there is no install script yet!

--
-- Table structure for table `mdl_tbird_course_autohide`
--

CREATE TABLE IF NOT EXISTS `mdl_tbird_course_autohide` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `courseid` bigint(10) NOT NULL COMMENT 'course table "id" field',
  `enddate` bigint(10) NOT NULL DEFAULT '0' COMMENT 'unix time stamp for end-date from IMS data',
  `hiddendate` bigint(10) NOT NULL DEFAULT '0' COMMENT 'unix time stamp when course was auto-hidden',
  UNIQUE KEY `courseid` (`courseid`),
  KEY `id` (`id`)
) DEFAULT CHARSET=utf8 COMMENT='Stores end-dates of course, and when made invisible' ;



--
-- Table structure for table `mdl_tbird_course_info`
--

CREATE TABLE IF NOT EXISTS `mdl_tbird_course_info` (
  `id` bigint(10) unsigned NOT NULL AUTO_INCREMENT,
  `courseid` bigint(10) unsigned NOT NULL,
  `name` varchar(50) NOT NULL DEFAULT '',
  `value` varchar(2048) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) DEFAULT CHARSET=utf8 COMMENT='Holds additional info about courses by thunderbird.edu' ;
