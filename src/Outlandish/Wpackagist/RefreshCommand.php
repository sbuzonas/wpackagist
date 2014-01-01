<?php

namespace Outlandish\Wpackagist;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RefreshCommand extends Command {

	protected function configure() {
		$this
			->setName('refresh')
			->setDescription('Refresh list of plugins and themes from WP SVN')
			->addOption(
				'svn', null, InputOption::VALUE_REQUIRED, 'Path to svn executable', 'svn'
			)->addOption(
			'base', null, InputOption::VALUE_REQUIRED, 'Subversion repository base for WordPress core', 'http://core.svn.wordpress.org/'
		)->addOption(
			'pluginbase', null, InputOption::VALUE_REQUIRED, 'Subversion repository base for WordPress plugins', 'http://plugins.svn.wordpress.org/'
		)->addOption(
			'themebase', null, InputOption::VALUE_REQUIRED, 'Subversion repository base for WordPress themes', 'http://themes.svn.wordpress.org/'
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$svn_path = $input->getOption('svn');

		$base = rtrim($input->getOption('base'), '/') . '/';
		$output->writeln("Fetching WordPress svn info from $base");
		$this->updateCore($svn_path, $base);

		$plugin_base = rtrim($input->getOption('pluginbase'), '/') . '/';
		$this->refreshPackages($output, $svn_path, $plugin_base, 'wordpress-plugin');

		$theme_base = rtrim($input->getOption('themebase'), '/') . '/';
		$this->refreshPackages($output, $svn_path, $theme_base, 'wordpress-theme');
	}

	private function getPackages($svn_path, $svn_base) {
		exec("$svn_path ls --xml $svn_base", $xmlLines, $returnCode);
		if ($returnCode) {
			throw new \Exception("Error from svn command", $returnCode);
		}
		$xml = simplexml_load_string(implode("\n", $xmlLines));

		return $xml;
	}

	private function updateCore($svn_path, $svn_base, $pkg_name = "wordpress", $pkg_group = "wordpress") {
		exec("$svn_path info --xml $svn_base", $xmlLines, $returnCode);
		if ($returnCode) {
			throw new Exception("Error from svn command", $returnCode);
		}
		$xml = simplexml_load_string(implode("\n", $xmlLines));
		unset($xmlLines);

		/**
		 * @var \PDO $db
		 */
		$db = $this->getApplication()->getDb();

		$updateStmt = $db->prepare('UPDATE packages SET last_committed = :date WHERE name = :name AND type = :type');

		$db->beginTransaction();
		$date = date('Y-m-d H:i:s', strtotime((string) $xml->entry->commit->date));
		$params = array(':name' => (string) $pkg_name, ':type' => (string) $pkg_group, ':date' => $date);
		$updateStmt->execute($params);
		if ($updateStmt->rowCount() == 0) {
			$insertStmt = $db->prepare('INSERT INTO packages (name, type, last_committed) VALUES (:name, :type, :date)');
			$insertStmt->execute($params);
		}
		$db->commit();
	}

	private function updateDatabase(\SimpleXMLElement $packages, $pkg_group) {
		/**
		 * @var \PDO $db
		 */
		$db = $this->getApplication()->getDb();

		$updateStmt = $db->prepare('UPDATE packages SET last_committed = :date WHERE name = :name AND type = :type');
		$insertStmt = $db->prepare('INSERT INTO packages (name, type, last_committed) VALUES (:name, :type, :date)');
		$db->beginTransaction();
		$newPackages = 0;
		foreach ($packages->list->entry as $entry) {
			$date = date('Y-m-d H:i:s', strtotime((string) $entry->commit->date));
			$params = array(':name' => (string) $entry->name, ':type' => (string) $pkg_group, ':date' => $date);

			$updateStmt->execute($params);
			if ($updateStmt->rowCount() == 0) {
				$insertStmt->execute($params);
				$newPackages++;
			}
		}
		$db->commit();

		$updatedPackages = $db->query('SELECT COUNT(*) FROM packages WHERE last_fetched < last_committed')->fetchColumn();

		return array($newPackages, $updatedPackages);
	}

	private function refreshPackages(OutputInterface $output, $svn_path, $svn_base, $pkg_group) {
		$output->writeln("Fetching full $pkg_group list from $svn_base");

		try {
			$packages = $this->getPackages($svn_path, $svn_base);
		} catch (\Exception $e) {
			$output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
			return 1; // error code
		}

		$output->writeln("Updating database");

		list($newPackages, $updatedPackages) = $this->updateDatabase($packages, $pkg_group);

		$output->writeln(sprintf("Found %s new and %s updated %s packages", $newPackages, $updatedPackages, $pkg_group));
	}

}
