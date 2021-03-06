<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_External\Command;

use OC\Core\Command\Base;
use OC\User\NoUserException;
use OCP\Files\External\Auth\InvalidAuth;
use OCP\Files\External\Backend\InvalidBackend;
use OCP\Files\External\IStorageConfig;
use OCP\Files\External\Service\IGlobalStoragesService;
use OCP\Files\External\Service\IUserStoragesService;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Base {
	/**
	 * @var IGlobalStoragesService
	 */
	protected $globalService;

	/**
	 * @var IUserStoragesService
	 */
	protected $userService;

	/**
	 * @var IUserSession
	 */
	protected $userSession;

	/**
	 * @var IUserManager
	 */
	protected $userManager;

	const ALL = -1;

	public function __construct(IGlobalStoragesService $globalService, IUserStoragesService $userService, IUserSession $userSession, IUserManager $userManager) {
		parent::__construct();
		$this->globalService = $globalService;
		$this->userService = $userService;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
	}

	protected function configure() {
		$this
			->setName('files_external:list')
			->setDescription('List configured admin or personal mounts')
			->addArgument(
				'user_id',
				InputArgument::OPTIONAL,
				'user id to list the personal mounts for, if no user is provided admin mounts will be listed'
			)->addOption(
				'show-password',
				null,
				InputOption::VALUE_NONE,
				'show passwords and secrets'
			)->addOption(
				'full',
				null,
				InputOption::VALUE_NONE,
				'don\'t truncate long values in table output'
			)->addOption(
				'all',
				'a',
				InputOption::VALUE_NONE,
				'show both system wide mounts and all personal mounts'
			)->addOption(
				'short',
				's',
				InputOption::VALUE_NONE,
				'show only a reduced mount info'
			);
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		if ($input->getOption('all')) {
			/** @var  $mounts IStorageConfig[] */
			$mounts = $this->globalService->getStorageForAllUsers();
			$userId = self::ALL;
		} else {
			$userId = $input->getArgument('user_id');
			$storageService = $this->getStorageService($userId);

			/** @var  $mounts IStorageConfig[] */
			$mounts = $storageService->getAllStorages();
		}

		$this->listMounts($userId, $mounts, $input, $output);
	}

	/**
	 * @param $userId $userId
	 * @param IStorageConfig[] $mounts
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	public function listMounts($userId, array $mounts, InputInterface $input, OutputInterface $output) {
		$outputType = $input->getOption('output');
		$shortView = $input->getOption('short');

		if (\count($mounts) === 0) {
			if ($outputType === self::OUTPUT_FORMAT_JSON || $outputType === self::OUTPUT_FORMAT_JSON_PRETTY) {
				$output->writeln('[]');
			} else {
				if ($userId === self::ALL) {
					$output->writeln("<info>No mounts configured</info>");
				} elseif ($userId) {
					$output->writeln("<info>No mounts configured by $userId</info>");
				} else {
					$output->writeln("<info>No admin mounts configured</info>");
				}
			}
			return;
		}

		if ($shortView) {
			$headers = ['Mount ID', 'Mount Point', 'Type'];
		} else {
			$headers = ['Mount ID', 'Mount Point', 'Storage', 'Authentication Type', 'Configuration', 'Options'];

			if (!$userId || $userId === self::ALL) {
				$headers[] = 'Applicable Users';
				$headers[] = 'Applicable Groups';
			}

			if ($userId === self::ALL) {
				$headers[] = 'Type';
			}

			if (!$input->getOption('show-password')) {
				$hideKeys = ['password', 'refresh_token', 'token', 'client_secret', 'public_key', 'private_key'];
				foreach ($mounts as $mount) {
					$config = $mount->getBackendOptions();
					foreach ($config as $key => $value) {
						if (\in_array($key, $hideKeys)) {
							$mount->setBackendOption($key, '***');
						}
					}
				}
			}
		}

		if ($outputType === self::OUTPUT_FORMAT_JSON || $outputType === self::OUTPUT_FORMAT_JSON_PRETTY) {
			$keys = \array_map(function ($header) {
				return \strtolower(\str_replace(' ', '_', $header));
			}, $headers);

			if ($shortView) {
				$pairs = \array_map(function (IStorageConfig $config) use ($keys) {
					$values = [
							$config->getId(),
							$config->getMountPoint(),
							$config->getType() === IStorageConfig::MOUNT_TYPE_ADMIN ? 'admin' : 'personal'
						];

					return \array_combine($keys, $values);
				}, $mounts);
			} else {
				$pairs = \array_map(function (IStorageConfig $config) use ($keys, $userId) {
					$values = [
							$config->getId(),
							$config->getMountPoint(),
							$config->getBackend()->getStorageClass(),
							$config->getAuthMechanism()->getIdentifier(),
							$config->getBackendOptions(),
							$config->getMountOptions()
						];
					if (!$userId || $userId === self::ALL) {
						$values[] = $config->getApplicableUsers();
						$values[] = $config->getApplicableGroups();
					}
					if ($userId === self::ALL) {
						$values[] = $config->getType() === IStorageConfig::MOUNT_TYPE_ADMIN ? 'admin' : 'personal';
					}

					return \array_combine($keys, $values);
				}, $mounts);
			}

			if ($outputType === self::OUTPUT_FORMAT_JSON) {
				$output->writeln(\json_encode(\array_values($pairs)));
			} else {
				$output->writeln(\json_encode(\array_values($pairs), JSON_PRETTY_PRINT));
			}
		} else {

			// default output style
			$full = $input->getOption('full');
			$defaultMountOptions = [
				'encrypt' => true,
				'previews' => true,
				'filesystem_check_changes' => 1,
				'enable_sharing' => false,
				'encoding_compatibility' => false
			];
			$countInvalid = 0;
			// In case adding array elements, add them only after the first two (Mount ID / Mount Point)
			// and before the last one entry (Type). Necessary for option -s
			$rows = \array_map(function (IStorageConfig $config) use ($shortView, $userId, $defaultMountOptions, $full, &$countInvalid) {
				if ($config->getBackend() instanceof InvalidBackend || $config->getAuthMechanism() instanceof InvalidAuth) {
					$countInvalid++;
				}
				$storageConfig = $config->getBackendOptions();
				$keys = \array_keys($storageConfig);
				$values = \array_values($storageConfig);

				if (!$full) {
					$values = \array_map(function ($value) {
						if (\is_string($value) && \strlen($value) > 32) {
							return \substr($value, 0, 6) . '...' . \substr($value, -6, 6);
						} else {
							return $value;
						}
					}, $values);
				}

				$configStrings = \array_map(function ($key, $value) {
					return $key . ': ' . \json_encode($value);
				}, $keys, $values);
				$configString = \implode(', ', $configStrings);

				$mountOptions = $config->getMountOptions();
				// hide defaults
				foreach ($mountOptions as $key => $value) {
					if ($value === $defaultMountOptions[$key]) {
						unset($mountOptions[$key]);
					}
				}
				$keys = \array_keys($mountOptions);
				$values = \array_values($mountOptions);

				$optionsStrings = \array_map(function ($key, $value) {
					return $key . ': ' . \json_encode($value);
				}, $keys, $values);
				$optionsString = \implode(', ', $optionsStrings);

				$values = [
					$config->getId(),
					$config->getMountPoint(),
					$config->getBackend()->getText(),
					$config->getAuthMechanism()->getText(),
					$configString,
					$optionsString
				];

				if (!$userId || $userId === self::ALL) {
					$applicableUsers = \implode(', ', $config->getApplicableUsers());
					$applicableGroups = \implode(', ', $config->getApplicableGroups());
					if ($applicableUsers === '' && $applicableGroups === '') {
						$applicableUsers = 'All';
					}
					$values[] = $applicableUsers;
					$values[] = $applicableGroups;
				}
				// This MUST stay the last entry
				if ($shortView || $userId === self::ALL) {
					$values[] = $config->getType() === IStorageConfig::MOUNT_TYPE_ADMIN ? 'Admin' : 'Personal';
				}

				return $values;
			}, $mounts);

			$table = new Table($output);
			$table->setHeaders($headers);
			$table->setRows($this->getColumns($shortView, $rows));
			$table->render();

			if ($countInvalid > 0) {
				$output->writeln(
					"<error>Number of invalid storages found: $countInvalid.\n" .
					"The listed configuration details are likely incomplete.\n" .
					"Please make sure that all related apps that provide these storages are enabled or delete these.</error>"
				);
			}
		}
	}

	// Removes all unused columns for option -s.
	// Only the first two (Mount ID / Mount Point) and the last column (Mount Type) is kept.
	protected function getColumns($shortView, $rows) {
		if ($shortView) {
			$newRows = [];
			// $subArr is a copy of $rows anyways...
			foreach ($rows as $subArr) {
				$c = \count($subArr) - 1;
				$u = false;
				foreach ($subArr as $key => $val) {
					if ((int)$key > 1 && (int)$key < $c) {
						unset($subArr[$key]);
						$u = true;
					}
				}
				if ($u) {
					$subArr = \array_values($subArr);
				}
				$newRows[] = $subArr;
			}
			return $newRows;
		} else {
			return $rows;
		}
	}
	
	protected function getStorageService($userId) {
		if (!empty($userId)) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				throw new NoUserException("user $userId not found");
			}
			$this->userSession->setUser($user);
			return $this->userService;
		} else {
			return $this->globalService;
		}
	}
}
