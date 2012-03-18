<?php
/**
 * @copyright Copyright (C) 2011 Michael De Wildt. All rights reserved.
 * @author Michael De Wildt (http://www.mikeyd.com.au/)
 * @license This program is free software; you can redistribute it and/or modify
 *          it under the terms of the GNU General Public License as published by
 *          the Free Software Foundation; either version 2 of the License, or
 *          (at your option) any later version.
 *
 *          This program is distributed in the hope that it will be useful,
 *          but WITHOUT ANY WARRANTY; without even the implied warranty of
 *          MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *          GNU General Public License for more details.
 *
 *          You should have received a copy of the GNU General Public License
 *          along with this program; if not, write to the Free Software
 *          Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110, USA.
 */
require_once 'mock-wp-functions.php';
require_once '../Classes/class-wp-backup-config.php';

class WP_Backup_Config_Test extends PHPUnit_Framework_TestCase {

	private $config;

	public function setUp() {
		reset_globals();

		$this->config = WP_Backup_Config::construct();
	}

	public function testConstruct() {
		$options = $this->config->get_options();
		$this->assertEquals($options, array(
			'dump_location' => 'Tests/backups',
			'dropbox_location' => 'WordPressBackup',
			'last_backup_time' => false,
			'in_progress' => false,
		));

		$history = $this->config->get_history();
		$this->assertEquals($history, array());

		$actions = $this->config->get_actions();
		$this->assertEquals($actions, array());
	}

	public function testConstructDudData() {
		global $options;
		$options['backup-to-dropbox-options'] = array('bad');

		$this->config = WP_Backup_Config::construct();
		$options = $this->config->get_options();
		$this->assertEquals($options, array(
			'dump_location' => 'Tests/backups',
			'dropbox_location' => 'WordPressBackup',
			'last_backup_time' => false,
			'in_progress' => false,
		));
	}

	public function testSetGetClearHistory() {
		set_current_time('2012-03-12 00:00:00');
		$this->config->log(WP_Backup_Config::BACKUP_STATUS_STARTED, 'One');

		set_current_time('2012-03-12 00:00:01');
		$this->config->log(WP_Backup_Config::BACKUP_STATUS_FINISHED, 'Two');

		set_current_time('2012-03-12 00:00:02');
		$this->config->log(WP_Backup_Config::BACKUP_STATUS_WARNING, 'Three');

		set_current_time('2012-03-12 00:00:03');
		$this->config->log(WP_Backup_Config::BACKUP_STATUS_FAILED, 'Four');

		$history = $this->config->get_history();

		$this->assertEquals($history[0], array(
			strtotime('2012-03-12 00:00:00'), WP_Backup_Config::BACKUP_STATUS_STARTED, 'One'
		));

		$this->assertEquals($history[1], array(
			strtotime('2012-03-12 00:00:01'), WP_Backup_Config::BACKUP_STATUS_FINISHED, 'Two'
		));

		$this->assertEquals($history[2], array(
			strtotime('2012-03-12 00:00:02'), WP_Backup_Config::BACKUP_STATUS_WARNING, 'Three'
		));

		$this->assertEquals($history[3], array(
			strtotime('2012-03-12 00:00:03'), WP_Backup_Config::BACKUP_STATUS_FAILED, 'Four'
		));

		$this->config->clear_history();
		$this->assertEquals($this->config->get_history(), array());
	}

	public function testMaxHistory() {
		for ($i = 0; $i < (WP_Backup_Config::MAX_HISTORY_ITEMS + 10); $i++) {
			$this->config->log(WP_Backup_Config::BACKUP_STATUS_STARTED, $i);
		}
		$history = $this->config->get_history();
		$this->assertEquals(100, count($history));
		$this->assertEquals(10, $history[0][2]);
		$this->assertEquals(109, $history[WP_Backup_Config::MAX_HISTORY_ITEMS - 1][2]);
	}

	public function testSetGetAction() {
		set_current_time('2012-03-12 00:00:00');
		$this->config->set_current_action('Action1', 'File1');
		set_current_time('2012-03-12 00:00:01');
		$this->config->set_current_action('Action2', 'File2');

		$action = $this->config->get_current_action();
		$this->assertEquals($action, array(
			'time' => strtotime('2012-03-12 00:00:01'),
			'message' => 'Action2',
			'file' => 'File2',
		));
	}

	public function testGetUploadedFiles() {
		$this->config->set_current_action('Action1', 'File1');
		$this->config->set_current_action('Action2', 'File2');

		$files = $this->config->get_uploaded_files();
		$this->assertEquals($files, array('File1', 'File2'));
	}

	public function testInProgess() {
		$this->assertFalse($this->config->in_progress());

		$this->config->set_in_progress(true);
		$this->assertTrue($this->config->in_progress());

		$this->config->set_in_progress(false);
		$this->assertFalse($this->config->in_progress());
	}

	public function testSetGetScheduleWhereTimeOfDayHasPast() {
		//The blog time is 10 hours from now
		$blog_time = strtotime(date('Y-m-d H:00:00', strtotime('+10 hours')));
		set_current_time(date('Y-m-d H:i:s ', $blog_time));

		$this->config->set_schedule(date('D', $blog_time), date('H', $blog_time) . ':00', 'daily');
		$schedule = $this->config->get_schedule();

		//Next week in the blog time time is expected
		$expected_date_time = date('Y-m-d', strtotime('+7 days', $blog_time)) . ' ' . date('H', $blog_time) . ':00:00';
		$this->assertEquals($expected_date_time, date('Y-m-d H:i:s', $schedule[0]));
		$this->assertEquals('daily', $schedule[1]);

		//Next week in the server time is expected
		$schedule = wp_next_scheduled('execute_periodic_drobox_backup');
		$this->assertEquals(strtotime('+7 days', strtotime(date('Y-m-d H:00:00'))), $schedule);
	}

	public function testSetGetScheduleWhereTimeOfDayHasPastAndNoDaySupplied() {
		//The blog time is -4 hours from now
		$blog_time = strtotime(date('Y-m-d H:00:00', strtotime('-10 hours')));
		set_current_time(date('Y-m-d H:i:s ', $blog_time));

		$this->config->set_schedule(null, date('H', $blog_time) . ':00', 'daily');
		$schedule = $this->config->get_schedule();

		//Today in the blog time time is expected
		$expected_date_time = date('Y-m-d', strtotime('+1 day', $blog_time)) . ' ' . date('H', $blog_time) . ':00:00';
		$this->assertEquals($expected_date_time, date('Y-m-d H:i:s', $schedule[0]));
		$this->assertEquals('daily', $schedule[1]);

		//Today in the server time is expected
		$schedule = wp_next_scheduled('execute_periodic_drobox_backup');
		$this->assertEquals(strtotime('+1 day', strtotime(date('Y-m-d H:00:00'))), $schedule);
	}

	public function testSetGetScheduleWhereTimeOfDayHasNotPast() {
		//The blog time is 2 hours from now
		$blog_time = strtotime(date('Y-m-d H:00:00', strtotime('-10 hours')));
		set_current_time(date('Y-m-d H:i:s ', $blog_time));

		$day = date('H', $blog_time) + 1;
		if ($day < 10) {
			$day = "0$day";
		}

		$this->config->set_schedule(date('D', $blog_time), $day . ':00', 'daily');
		$schedule = $this->config->get_schedule();

		//Today in the blog time time is expected
		$expected_date_time = date('Y-m-d', $blog_time) . ' ' . $day . ':00:00';
		$this->assertEquals($expected_date_time, date('Y-m-d H:i:s', $schedule[0]));
		$this->assertEquals('daily', $schedule[1]);

		//Today in the server time is expected
		$schedule = wp_next_scheduled('execute_periodic_drobox_backup');
		$this->assertEquals(strtotime('+1 hour', strtotime(date('Y-m-d H:00:00'))), $schedule);
	}

	public function testSetGetOptions() {
		//Test bad paths
		$bad_chars = array('!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '+', '=', '{', '}', ']', '[', ':', ';', '"', '\'', '<', '>', '?', ',', '~', '`', '|', '\\');
		foreach ($bad_chars as $bad_char) {
			$error_msg = 'Invalid directory path. Path must only contain alphanumeric characters and the forward slash (\'/\') to separate directories.';

			$options['dump_location'] = $bad_char;
			$options['dropbox_location'] = $bad_char;
			$options['in_progress'] = false;
			$options['last_backup_time'] = false;

			$errors = $this->config->set_options($options);
			$this->assertNotEmpty($errors);
			$this->assertEquals($bad_char, $errors['dump_location']['original']);
			$this->assertEquals($error_msg, $errors['dump_location']['message']);
			$this->assertEquals($bad_char, $errors['dropbox_location']['original']);
			$this->assertEquals($error_msg, $errors['dump_location']['message']);
		}

		//The there where errors so the data should remain as it was set in the unit test setup
		$config = $this->config->get_options();
		$this->assertEquals('Tests/backups', $config['dump_location']);
		$this->assertEquals('WordPressBackup', $config['dropbox_location']);

		//Test good paths
		$options['dump_location'] = 'wp-content/backups';
		$options['dropbox_location'] = 'WordPressBackup';
		$errors = $this->config->set_options($options);
		$this->assertEmpty($errors);
		$config = $this->config->get_options();
		$this->assertEquals('wp-content/backups', $config['dump_location']);
		$this->assertEquals('WordPressBackup', $config['dropbox_location']);

		//It is expected that any leading slashes are removed and extra slashes in between are removed
		$options['dump_location'] = '///wp-content////backups///';
		$options['dropbox_location'] = '////WordPressBackups///SiteOne////';
		$errors = $this->config->set_options($options);
		$config = $this->config->get_options();
		$this->assertEmpty($errors);
		$this->assertEquals('wp-content/backups', $config['dump_location']);
		$this->assertEquals('WordPressBackups/SiteOne', $config['dropbox_location']);

		$this->assertTrue(isset($options['last_backup_time']));
		$this->assertTrue(isset($options['in_progress']));
	}

	public function testCleanUp() {
		$this->config->set_schedule('Monday', '00:00:00', 'daily');

		set_current_time('2012-03-12 00:00:00');
		$this->config->set_current_action('Action1', 'File1');

		$this->assertNotEmpty($this->config->get_actions());

		$this->config->clean_up();

		$this->assertEmpty($this->config->get_actions());
		$this->assertFalse(wp_next_scheduled('monitor_dropbox_backup_hook'));
		$this->assertFalse(wp_next_scheduled('run_dropbox_backup_hook'));
	}
}