<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author J0WI <J0WI@users.noreply.github.com>
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Tigran Mkrtchyan <tigran.mkrtchyan@desy.de>
 * @author Vincent Petry <vincent@nextcloud.com>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OC\Files\Storage\Wrapper;

use OC\Files\Filesystem;
use OC\SystemConfig;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\FileInfo;
use OCP\Files\Storage\IStorage;

class Quota extends Wrapper {
	/** @var callable|null */
	protected $quotaCallback;
	protected ?int $quota;
	protected string $sizeRoot;
	private SystemConfig $config;

	/**
	 * @param array $parameters
	 */
	public function __construct($parameters) {
		parent::__construct($parameters);
		$this->quota = $parameters['quota'] ?? null;
		$this->quotaCallback = $parameters['quotaCallback'] ?? null;
		$this->sizeRoot = $parameters['root'] ?? '';
		$this->config = \OC::$server->get(SystemConfig::class);
	}

	/**
	 * @return int quota value
	 */
	public function getQuota(): int {
		if ($this->quota === null) {
			$quotaCallback = $this->quotaCallback;
			if ($quotaCallback === null) {
				throw new \Exception("No quota or quota callback provider");
			}
			$this->quota = $quotaCallback();
		}
		return $this->quota;
	}

	private function hasQuota(): bool {
		return $this->getQuota() !== FileInfo::SPACE_UNLIMITED;
	}

	/**
	 * @param string $path
	 * @param \OC\Files\Storage\Storage $storage
	 */
	protected function getSize($path, $storage = null) {
		if ($this->config->getValue('quota_include_external_storage', false)) {
			$rootInfo = Filesystem::getFileInfo('', 'ext');
			if ($rootInfo) {
				return $rootInfo->getSize(true);
			}
			return \OCP\Files\FileInfo::SPACE_NOT_COMPUTED;
		} else {
			if (is_null($storage)) {
				$cache = $this->getCache();
			} else {
				$cache = $storage->getCache();
			}
			$data = $cache->get($path);
			if ($data instanceof ICacheEntry and isset($data['size'])) {
				return $data['size'];
			} else {
				return \OCP\Files\FileInfo::SPACE_NOT_COMPUTED;
			}
		}
	}

	/**
	 * Get free space as limited by the quota
	 *
	 * @param string $path
	 * @return int|bool
	 */
	public function free_space($path) {
		if (!$this->hasQuota()) {
			return $this->storage->free_space($path);
		}
		if ($this->getQuota() < 0 || strpos($path, 'cache') === 0 || strpos($path, 'uploads') === 0) {
			return $this->storage->free_space($path);
		} else {
			$used = $this->getSize($this->sizeRoot);
			if ($used < 0) {
				return \OCP\Files\FileInfo::SPACE_NOT_COMPUTED;
			} else {
				$free = $this->storage->free_space($path);
				$quotaFree = max($this->getQuota() - $used, 0);
				// if free space is known
				if ($free >= 0) {
					$free = min($free, $quotaFree);
				} else {
					$free = $quotaFree;
				}
				return $free;
			}
		}
	}

	/**
	 * see https://www.php.net/manual/en/function.file_put_contents.php
	 *
	 * @param string $path
	 * @param mixed $data
	 * @return int|false
	 */
	public function file_put_contents($path, $data) {
		if (!$this->hasQuota()) {
			return $this->storage->file_put_contents($path, $data);
		}
		$free = $this->free_space($path);
		if ($free < 0 or strlen($data) < $free) {
			return $this->storage->file_put_contents($path, $data);
		} else {
			return false;
		}
	}

	/**
	 * see https://www.php.net/manual/en/function.copy.php
	 *
	 * @param string $source
	 * @param string $target
	 * @return bool
	 */
	public function copy($source, $target) {
		if (!$this->hasQuota()) {
			return $this->storage->copy($source, $target);
		}
		$free = $this->free_space($target);
		if ($free < 0 or $this->getSize($source) < $free) {
			return $this->storage->copy($source, $target);
		} else {
			return false;
		}
	}

	/**
	 * see https://www.php.net/manual/en/function.fopen.php
	 *
	 * @param string $path
	 * @param string $mode
	 * @return resource|bool
	 */
	public function fopen($path, $mode) {
		if (!$this->hasQuota()) {
			return $this->storage->fopen($path, $mode);
		}
		$source = $this->storage->fopen($path, $mode);

		// don't apply quota for part files
		if (!$this->isPartFile($path)) {
			$free = $this->free_space($path);
			if ($source && is_int($free) && $free >= 0 && $mode !== 'r' && $mode !== 'rb') {
				// only apply quota for files, not metadata, trash or others
				if ($this->shouldApplyQuota($path)) {
					return \OC\Files\Stream\Quota::wrap($source, $free);
				}
			}
		}
		return $source;
	}

	/**
	 * Checks whether the given path is a part file
	 *
	 * @param string $path Path that may identify a .part file
	 * @return string File path without .part extension
	 * @note this is needed for reusing keys
	 */
	private function isPartFile($path) {
		$extension = pathinfo($path, PATHINFO_EXTENSION);

		return ($extension === 'part');
	}

	/**
	 * Only apply quota for files, not metadata, trash or others
	 */
	private function shouldApplyQuota(string $path): bool {
		return strpos(ltrim($path, '/'), 'files/') === 0;
	}

	/**
	 * @param IStorage $sourceStorage
	 * @param string $sourceInternalPath
	 * @param string $targetInternalPath
	 * @return bool
	 */
	public function copyFromStorage(IStorage $sourceStorage, $sourceInternalPath, $targetInternalPath) {
		if (!$this->hasQuota()) {
			return $this->storage->copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
		}
		$free = $this->free_space($targetInternalPath);
		if ($free < 0 or $this->getSize($sourceInternalPath, $sourceStorage) < $free) {
			return $this->storage->copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
		} else {
			return false;
		}
	}

	/**
	 * @param IStorage $sourceStorage
	 * @param string $sourceInternalPath
	 * @param string $targetInternalPath
	 * @return bool
	 */
	public function moveFromStorage(IStorage $sourceStorage, $sourceInternalPath, $targetInternalPath) {
		if (!$this->hasQuota()) {
			return $this->storage->moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
		}
		$free = $this->free_space($targetInternalPath);
		if ($free < 0 or $this->getSize($sourceInternalPath, $sourceStorage) < $free) {
			return $this->storage->moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
		} else {
			return false;
		}
	}

	public function mkdir($path) {
		if (!$this->hasQuota()) {
			return $this->storage->mkdir($path);
		}
		$free = $this->free_space($path);
		if ($this->shouldApplyQuota($path) && $free == 0) {
			return false;
		}

		return parent::mkdir($path);
	}

	public function touch($path, $mtime = null) {
		if (!$this->hasQuota()) {
			return $this->storage->touch($path, $mtime);
		}
		$free = $this->free_space($path);
		if ($free == 0) {
			return false;
		}

		return parent::touch($path, $mtime);
	}
}
