<?php

namespace Outlandish\Wpackagist;

use Composer\Package\Version\VersionParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends Command {

	private $versionParser;

	protected function configure() {
		$this
			->setName('update')
			->setDescription('Update version info for individual plugins')
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
		$package_types = array(
			'wordpress' => array(
				'svn_base' => rtrim($input->getOption('base'), '/') . '/',
				'is_core' => true
			),
			'wordpress-plugin' => array(
				'svn_base' => rtrim($input->getOption('pluginbase'), '/') . '/',
				'is_core' => false
			),
			'wordpress-theme' => array(
				'svn_base' => rtrim($input->getOption('themebase'), '/') . '/',
				'is_core' => false
			)
		);

		$this->versionParser = new VersionParser;

		foreach ($package_types as $pkg_group => $fetch_options) {
			$this->updatePackages($output, $svn_path, $fetch_options, $pkg_group);
		}
	}

	private function updatePackages(OutputInterface $output, $svn_path, $fetch_options, $pkg_group) {
		$packages = $this->getStalePackages($pkg_group);
		foreach ($packages as $index => $package) {
			$percent = floor($index / count($packages) * 1000) / 10;
			$output->writeln(sprintf("<info>%04.1f%%</info> Fetching %s/%s", $percent, $package->type, $package->name));
			try {
				$this->fetchPackage($package, $svn_path, $fetch_options);
			} catch (\Exception $e) {
				$output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
				sleep(1);
				continue;
			}
		}
	}

	private function getStalePackages($pkg_group) {
		/**
		 * @var \PDO $db
		 */
		$db = $this->getApplication()->getDb();

		$stmt = $db->prepare('
			SELECT * FROM packages
			WHERE (last_fetched IS NULL OR last_fetched < last_committed) AND type = :type
			ORDER BY last_committed DESC
		');
		$stmt->execute(array(':type' => $pkg_group));
		$packages = $stmt->fetchAll(\PDO::FETCH_OBJ);

		return $packages;
	}

	private function fetchPackage($package, $svn_path, $fetch_options) {
		/**
		 * @var \PDO $db
		 */
		$db = $this->getApplication()->getDb();

		$svn_url = $fetch_options['is_core'] ? "{$fetch_options['svn_base']}" : "{$fetch_options['svn_base']}{$package->name}/";

		exec("$svn_path ls ${svn_url}/tags", $tags, $returnCode);
		exec("$svn_path ls ${svn_url}/branches", $branches, $returnCode);
		if ($returnCode) {
			throw new Exception("Error from svn command", $returnCode);
		}

		$stripRSlash = function($str) {
			return rtrim($str, '/');
		};

		$tags = array_map($stripRSlash, $tags);
		$branches = array_map($stripRSlash, $branches);

		$versions = array();

		foreach ($tags as $tag) {
			if (!$parsedTag = $this->validateTag($tag)) {
				continue;
			}

			$data['version'] = $tag;
			$data['version_normalized'] = $parsedTag;

			$data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']);
			$data['version_normalized'] = preg_replace('{(^dev-|[.-]dev$)}i', '', $data['version_normalized']);

			if ($data['version_normalized'] !== $parsedTag) {
				continue;
			}
			$versions[] = $data;
		}

		array_unshift($branches, 'trunk');
		foreach ($branches as $branch) {
			if (!$parsedBranch = $this->validateBranch($branch)) {
				continue;
			}

			$data['version'] = $branch;
			$data['version_normalized'] = $parsedBranch;

			if ('dev-' === substr($parsedBranch, 0, 4) || '9999999-dev' === $parsedBranch) {
				$data['version'] = 'dev-' . $data['version'];
			} else {
				$data['version'] = preg_replace('{(\.9{7})+}', '.x', $parsedBranch);
			}

			$versions[] = $data;
		}

		$stmt = $db->prepare('UPDATE packages SET last_fetched = datetime("now"), versions = :json WHERE name = :name AND type = :type');
		$package->versions = json_encode($versions);
		$stmt->execute(array(':name' => $package->name, ':json' => $package->versions, ':type' => $package->type));
	}

	private function validateBranch($branch) {
		try {
			return $this->versionParser->normalizeBranch($branch);
		} catch (Exception $ex) {

		}

		return false;
	}

	private function validateTag($version) {
		try {
			return $this->versionParser->normalize($version);
		} catch (Exception $ex) {

		}

		return false;
	}

}
