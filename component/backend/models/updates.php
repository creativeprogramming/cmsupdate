<?php
/**
 *  @package    AkeebaCMSUpdate
 *  @copyright  Copyright (c)2010-2013 Nicholas K. Dionysopoulos
 *  @license    GNU General Public License version 3, or later
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

defined('_JEXEC') or die();

class CmsupdateModelUpdates extends FOFModel
{
	/**
	 * Public constructor
	 *
	 * @param   array   $config  The model configuration array
	 */
	public function __construct($config = array())
	{
		parent::__construct($config);

		$this->table = '';
	}

	/**
	 * Purges the Joomla! update cache. We ARE NOT using this cache, but the CMS
	 * does. We want to bust the cache to provent Joomla! from reporting updates
	 * after we install an update through our component
	 *
	 * @return  bool  True on success
	 */
	protected function purgeJoomlaUpdateCache()
	{
		$db = JFactory::getDbo();

		// Modify the database record
		$update_site = new stdClass;
		$update_site->last_check_timestamp = 0;
		$update_site->enabled = 1;
		$update_site->update_site_id = 1;
		$db->updateObject('#__update_sites', $update_site, 'update_site_id');

		$query = $db->getQuery(true)
			->delete($db->quoteName('#__updates'))
			->where($db->quoteName('update_site_id') . ' = ' . $db->quote('1'));
		$db->setQuery($query);

		if ($db->execute())
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Returns an array with the configured FTP options
	 *
	 * @return  array
	 */
	public function getFTPOptions()
	{
		$config = JFactory::getConfig();
		return array(
			'host' => $config->get('ftp_host'),
			'port' => $config->get('ftp_port'),
			'username' => $config->get('ftp_user'),
			'password' => $config->get('ftp_pass'),
			'directory' => $config->get('ftp_root'),
			'enabled' => $config->get('ftp_enable'),
		);
	}


	public function getAllUpdates($force = false)
	{
		// Get the component parameters
		JLoader::import('cms.component.helper');
		$params = JComponentHelper::getParams('com_cmsupdate');

		// Do I have to check for updates?
		if (!$force)
		{
			// Check with the specified frequency which has to be between 1 hour and 30 days
			$frequency = $params->get('frequency', 6);

			if (($frequency < 0) || ($frequency > 720))
			{
				$frequency = 6;
			}

			// Get the last time we checked for updates
			$lastCheck = $params->get('lastcheck', 0);

			$nextCheckTimeStamp = $lastCheck + 3600 * $frequency;

			$force = $nextCheckTimeStamp >= time();
		}

		// Do I have a cache? If not I have to force an update fetch.
		$cache = null;

		if (!$force)
		{
			$cacheEncoded = $params->get('updatecache', '');

			if (!empty($cacheEncoded))
			{
				$cache = json_decode($cacheEncoded, true);
			}

			if (empty($cache))
			{
				$cache = null;
				$force = true;
			}
		}

		// If we are forced to perform an update fetch do it and refresh the cache
		if ($force)
		{
			// Get the update sources we are configured to use
			$sources = array(
				'lts'		=> true,
				'sts'		=> true,
				'test'		=> true,
				'custom'	=> $params->get('customurl', ''),
			);

			switch ($params->get('updatesource', 'all'))
			{
				case 'custom':
					$sources['lts'] = false;
					$sources['sts'] = false;
					$sources['testing'] = false;
					break;

				case 'testing':
					$sources['lts'] = false;
					$sources['sts'] = false;
					$sources['custom'] = '';
					break;

				case 'sts':
					$sources['lts'] = false;
					$sources['test'] = false;
					$sources['custom'] = '';
					break;

				case 'lts':
					$sources['sts'] = false;
					$sources['test'] = false;
					$sources['custom'] = '';
					break;
			}

			// Get the updates
			$provider = new AcuUpdateProviderJoomla();
			$cache = $provider->getUpdates($sources);

			// JSON-encode them
			$cacheEncoded = json_encode($cache);

			// Save the cache
			$params->set('updatecache', $cacheEncoded);
			$params->set('lastcheck', time());

			$component = JComponentHelper::getComponent('com_cmsupdate');

			$db = JFactory::getDbo();
			$query = $db->getQuery(true)
				->update($db->qn('#__extensions'))
				->set($db->qn('params') . ' = ' . $db->q($params->toString('JSON')))
				->where($db->qn('extension_id') . ' = ' . $db->q($component->id));
			$db->setQuery($query);
			$db->execute();
		}

		return $cache;
	}

	/**
	 * Returns information about whether we need to update Joomla!
	 *
	 * @param   boolean  $force  Set to true to forcibly reload from the network
	 *
	 * @return  object
	 */
	public function getUpdateInfo($force = false)
	{
		static $updateInfo = null;

		if (!empty($updateInfo) && !$force)
		{
			return $updateInfo;
		}

		$updateInfo = array(
			'status'    => false,
			'source'	=> 'none',
			'installed' => null,
			'current'   => null,
			'sts'       => null,
			'lts'       => null,
			'test'      => null,
		);

		$data = $this->getAllUpdates($force);

		if (empty($data))
		{
			return (object)$updateInfo;
		}

		$updateInfo = (object)array_merge($updateInfo, $data);

		// Get the minnotify setting
		$params = JComponentHelper::getParams('com_cmsupdate');
		$minnotify = $params->get('minnotify', 'current');

		$jVersion = $this->sanitiseVersion(JVERSION);

		// We trigger an update only when there is a new release of the minimum specified stability available for download
		switch ($minnotify)
		{
			case 'test':
				// Do we have a testing release?
				if (!empty($updateInfo->test['version']) && ($updateInfo->test['version'] != $jVersion))
				{
					$updateInfo->status = true;
					$updateInfo->source = 'test';
					break;
				}
				// Do not break; we have to fall through the rest of the switch

			case 'lts':
				// Do we have an lts release?
				if (!empty($updateInfo->lts['version']) && ($updateInfo->lts['version'] != $jVersion))
				{
					$updateInfo->status = true;
					$updateInfo->source = 'lts';
					break;
				}
				// Do not break; we have to fall through the rest of the switch

			case 'sts':
				// Do we have an sts release?
				if (!empty($updateInfo->sts['version']) && ($updateInfo->sts['version'] != $jVersion))
				{
					$updateInfo->status = true;
					$updateInfo->source = 'sts';
					break;
				}
				// Do not break; we have to fall through the rest of the switch

			case 'current':
				// Do we have a current branch release?
				if (!empty($updateInfo->current['version']) && ($updateInfo->current['version'] != $jVersion))
				{
					$updateInfo->status = true;
					$updateInfo->source = 'current';
					break;
				}
				break;
		}

		return $updateInfo;
	}
}