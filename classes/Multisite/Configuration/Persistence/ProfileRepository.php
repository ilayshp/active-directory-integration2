<?php
if (!defined('ABSPATH')) {
	die('Access denied.');
}

if (class_exists('Multisite_Configuration_Persistence_ProfileRepository')) {
	return;
}

/**
 * Multisite_Configuration_Persistence_ProfileRepository creates, updates and deletes profiles.
 * These profiles are necessary for the option managements.
 *
 * @author  Tobias Hellmann <the@neos-it.de>
 *
 * @access  public
 */
class Multisite_Configuration_Persistence_ProfileRepository
{
	const PREFIX = 'p_';
	const PREFIX_NAME = 'n_';
	const PREFIX_DESCRIPTION = 'd_';

	/* @var Multisite_Configuration_Persistence_ProfileConfigurationRepository */
	private $profileConfigurationRepository;

	/* @var Core_Persistence_WordPressRepository */
	private $wordPressRepository;

	/* @var Multisite_Configuration_Persistence_BlogConfigurationRepository */
	private $blogConfigurationRepository;

	/* @var Multisite_Option_Provider */
	private $optionProvider;

	/* @var Logger */
	private $logger;

	/** @var array */
	private $propertyMapping = array(
		Adi_Configuration_Options::PROFILE_NAME => self::PREFIX_NAME,
	);

	/**
	 * Adi_Database_Profiles constructor.
	 *
	 * @param Multisite_Configuration_Persistence_ProfileConfigurationRepository $profileConfigurationRepository
	 * @param Multisite_Configuration_Persistence_BlogConfigurationRepository    $blogConfigurationRepository
	 * @param Core_Persistence_WordPressRepository                               $wordPressRepository
	 * @param Multisite_Option_Provider                                          $optionProvider
	 */
	public function __construct(Multisite_Configuration_Persistence_ProfileConfigurationRepository $profileConfigurationRepository,
		Multisite_Configuration_Persistence_BlogConfigurationRepository $blogConfigurationRepository,
		Core_Persistence_WordPressRepository $wordPressRepository,
		Multisite_Option_Provider $optionProvider
	) {
		$this->profileConfigurationRepository = $profileConfigurationRepository;
		$this->blogConfigurationRepository = $blogConfigurationRepository;
		$this->wordPressRepository = $wordPressRepository;
		$this->optionProvider = $optionProvider;

		$this->logger = Logger::getLogger(__CLASS__);
	}

	/**
	 * Generate the WordPress option name.
	 *
	 * @param int    $profileId
	 * @param string $optionPrefix this is appended to the PREFIX and before the $profileId
	 *
	 * @return string
	 */
	protected function getProfileOption($profileId, $optionPrefix)
	{
		return ADI_PREFIX . self::PREFIX . $optionPrefix . $profileId;
	}

	/**
	 * Generate the WordPress option name using the given property mapping.
	 *
	 * @param $name
	 * @param $profileId
	 *
	 * @return string|void
	 */
	protected function getOptionNameByMapping($name, $profileId)
	{
		if (!isset($this->propertyMapping[$name])) {
			return false;
		}

		$prefix = $this->propertyMapping[$name];

		return ADI_PREFIX . self::PREFIX . $prefix . $profileId;
	}

	/**
	 * Returns ID NAME and IS_ACTIVE for all profiles
	 *
	 * @return array
	 */
	public function findAll()
	{
		$host = &$this;
		$profileIds = $this->findAllIds();

		return array_map(function($id) use ($host) {
			return array(
				'profileId'   => $id,
				'profileName' => $host->findName($id),
			);
		}, $profileIds);
	}

	/**
	 * Get all profiles.
	 *
	 * @return array(object)
	 */
	public function findAllIds()
	{
		if (!is_multisite()) {
			return array();
		}

		$table = $this->wordPressRepository->getTableSiteMeta();
		$prefix = $this->getProfileOption('', self::PREFIX_NAME);
		$sql = "SELECT meta_key FROM " . $table . " WHERE meta_key LIKE '$prefix%';";

		$result = $this->wordPressRepository->wpdb_get_col(
			$sql,
			array()
		);

		$profileIds = array();

		foreach ($result as $optionValue) {
			$profileIds[] = str_replace($prefix, '', $optionValue);
		}

		return $profileIds;
	}

	/**
	 * Find the profile name for the given $id.
	 *
	 * @param int         $profileId
	 * @param string|null $newProfileName
	 *
	 * @return mixed
	 */
	public function findName($profileId, $newProfileName = null)
	{
		$newProfileName = (null !== $newProfileName) ? $newProfileName : __('New Profile', NEXT_AD_INT_I18N);
		$name = $this->getProfileOption($profileId, self::PREFIX_NAME);

		return get_site_option($name, $newProfileName);
	}

	/**
	 * Find the profile description for the given $id.
	 *
	 * @param int $profileId
	 *
	 * @return mixed
	 */
	public function findDescription($profileId)
	{
		$name = $this->getProfileOption($profileId, self::PREFIX_DESCRIPTION);

		return get_site_option($name, '');
	}

	/**
	 * Search for a free id and persist the new profile.
	 *
	 * @param $data
	 *
	 * @return int
	 */
	public function insertProfileData($data)
	{
		// search for free id
		$freeId = 1;
		for (; true; $freeId++) {
			$name = $this->getProfileOption($freeId, self::PREFIX_NAME);

			if (false === get_site_option($name, false)) {
				break;
			}
		}

		$this->updateProfileData($data, $freeId);

		return $freeId;
	}

	/**
	 * Update the profile data using the given {@see $data}.
	 *
	 * @param $id
	 * @param $data
	 */
	public function updateProfileData($data, $id)
	{
		foreach ($data as $name => $value) {
			$optionName = $this->getOptionNameByMapping($name, $id);

			if (false === $optionName) {
				continue;
			}

			$this->profileConfigurationRepository->persistSanitizedPermission(
				$id,
				Adi_Configuration_Options::PROFILE_NAME,
				Multisite_Configuration_Service::DISABLED_FOR_BLOG_ADMIN
			);
			update_site_option($optionName, $value['option_value']);
		}
	}

	/**
	 * Insert a new profile with name and description.
	 *
	 * @param string $profileName
	 * @param        $profileDescription
	 *
	 * @return bool|false|int
	 */
	public function insert($profileName, $profileDescription)
	{
		// search for free id
		$freeId = 1;

		for (; true; $freeId++) {
			$prefix = $this->getProfileOption($freeId, self::PREFIX_NAME);

			if (false === get_site_option($prefix, false)) {
				break;
			}
		}

		$prefix = $this->getProfileOption($freeId, self::PREFIX_NAME);
		update_site_option($prefix, $profileName);

		$description = $this->getProfileOption($freeId, self::PREFIX_DESCRIPTION);
		update_site_option($description, $profileDescription);

		return $freeId;
	}

	/**
	 * Insert a default profile if no profile exists.
	 *
	 * @return bool
	 */
	public function insertDefaultProfile()
	{
		$name = $this->getProfileOption(1, self::PREFIX_NAME);

		// default profile is already installed
		$installed_info = get_site_option($name, false);

		if (false !== $installed_info) {
			// do not add it again
			return false;
		}

		$name = __('My ADI profile', NEXT_AD_INT_I18N);
		$description = __(
			'This profile has been created by the plugin installation automatically. It can safely be deleted.',
			NEXT_AD_INT_I18N
		);

		return $this->insert($name, $description);
	}

	/**
	 * Change the name of profile $id.
	 *
	 * @param int    $id
	 * @param string $profileName
	 *
	 * @return bool|false|int
	 */
	public function updateName($id, $profileName)
	{
		$name = $this->getProfileOption($id, self::PREFIX_NAME);

		return update_site_option($name, $profileName);
	}

	/**
	 * Change the description of profile $id.
	 *
	 * @param int    $id
	 * @param string $profileDescription
	 *
	 * @return bool
	 */
	public function updateDescription($id, $profileDescription)
	{
		$description = $this->getProfileOption($id, self::PREFIX_DESCRIPTION);

		return update_site_option($description, $profileDescription);
	}

	/**
	 * Delete profile $id
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public function delete($id)
	{
		$name = $this->getProfileOption($id, self::PREFIX_NAME);
		delete_site_option($name);

		$description = $this->getProfileOption($id, self::PREFIX_DESCRIPTION);
		delete_site_option($description);

		$options = $this->optionProvider->getNonTransient();

		foreach ($options as $optionName => $meta) {
			$this->profileConfigurationRepository->deleteValue($id, $optionName);
			$this->profileConfigurationRepository->deletePermission($id, $optionName);
		}

		$this->blogConfigurationRepository->deleteProfileAssociations($id);
	}
}