<?php
class OSM {
	const CACHE_NONE = 0x00;
	const CACHE_NONPERSISTANT = 0x01;
	const CACHE_PERSISTANTEXCLUSIVE = 0x02;
	const CACHE_PERSISTANT = 0x03;
	
	const BADGETYPE_CHALLENGE = "challenge";
	const BADGETYPE_STAGED = "staged";
	const BADGETYPE_ACTIVITY = "activity";
	
	/**
	 * API ID, from Ed
	 * 
	 * @var int
	 */
	private $apiid = -1;
	
	/**
	 * API Token, from Ed
	 * 
	 * @var string
	 */
	private $token = 'XXX';
	
	/**
	 * The user ID, obtained through authorize()
	 * 
	 * @var int
	 */
	private $userid = null;
	
	/**
	 * The user secret, obtained through authorize()
	 * 
	 * @var string
	 */
	private $secret = null;
	
	/**
	 * The absolute URL which all URLs are relative to.
	 * 
	 * @var string
	 */
	private $base = 'https://www.onlinescoutmanager.co.uk/';
	
	/**
	 * Non-persistent cache
	 * 
	 * @var string[]
	 */
	private $cache = array();
	
	/**
	 * Construct object
	 * 
	 * @param string $apiid API ID, from Ed
	 * @param string $token API Token, from Ed
	 */
	public function __construct($apiid, $token) {
		$this->apiid = $apiid;
		$this->token = $token;
		$this->loadAuthorization();
		
	}
	
	/**
	 * Load authorization which has been saved in the $_SESSION
	 * 
	 * @return boolean
	 */
	private function loadAuthorization() {
		if (isset($_SESSION['osm_userid'])) {
			$this->userid = $_SESSION['osm_userid'];
			$this->secret = $_SESSION['osm_secret'];
			return true;
		}
		return false;
	}
	
	/**
	 * Check if the API is currently authorized
	 * 
	 * @return boolean
	 */
	public function isAuthorized() {
		if ($this->userid !== false) {
			return true;
		}
		return false;
	}
	
	/**
	 * Authorize the API with the username and password provided
	 * 
	 * @param string $email    Email address of user to authorize
	 * @param string $password Password of the user to authorize
	 * 
	 * @return boolean;
	 */
	public function authorize($email, $password) {
		$parts['password'] = $password;
		$parts['email'] = $email;
		$json = $this->perform_query('users.php?action=authorise', $parts);
		if (!isset($json->secret)) {
			return false;
		}
		$this->secret = $json->secret;
		$this->userid = $json->userid;
		if (session_id() != "") {
			$_SESSION['osm_userid'] = $this->userid;
			$_SESSION['osm_secret'] = $this->secret;
		}
		$this->destroyPersistantCache();
		return true;
	}
	
	/**
	 * Perform a query against the API endpoint
	 * 
	 * @param string   $url       The URL to query, relative to the base URL
	 * @param string[] $parts     The URL parts, encoded as an associative array
	 * @param int      $cachetype The type of caching to use
	 * 
	 * @return string[];
	 */
	private function perform_query($url, $parts=array(), $cachetype=0) {
		$parts['token'] = $this->token;
		$parts['apiid'] = $this->apiid;
		if (!$this->isAuthorized()) {
			throw new Exception("OSM API not authorized");
		}
		$parts['userid'] = $this->userid;
		$parts['secret'] = $this->secret;
		
		
		$data = http_build_query($parts);
		if ($cache = $this->getCache($url, $data, $cachetype)) {
			return $cache;
		}
		$curl_handle = curl_init();
		curl_setopt($curl_handle, CURLOPT_URL, $this->base.$url);
		curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $data);
		curl_setopt($curl_handle, CURLOPT_POST, 1);
		curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
		$msg = curl_exec($curl_handle);
		$out = json_decode($msg);
		$this->setCache($url, $data, $cachetype, $cachetype, $out);
		return $out;
	}
	
	/**
	 * Implement caching using a datahash devised from query
	 * 
	 * @param string $url  URL of data to cache
	 * @param string $data Params passed to API, after HTTP encoding.
	 * @param int    $type The type of caching to use
	 * 
	 * @return string
	 */
	private function getCache($url, $data, $type) {
		$datahash = sha1($url."?".$data);
		if ($type & self::CACHE_NONPERSISTANT && isset($this->cache[$datahash])) {
			return $this->cache[sha1($url."?".$data)];
		}
		
		if ($type & self::CACHE_PERSISTANTEXCLUSIVE && isset($_SESSION['osm_cache']) && isset($_SESSION['osm_cache'][$datahash])) {
			return $_SESSION['osm_cache'][$datahash];
		}
		return null;
	}
	
	/**
	 * Cache the value for future queries
	 * 
	 * @param string   $url    URL of data to cache
	 * @param string   $data   Params passed to API, after HTTP encoding.
	 * @param int      $type   The type of caching to use CACHE_*
	 * @param string[] $return The returned value of the query after JSON decoding
	 * 
	 * @return null
	 */
	private function setCache($url, $data, $type, $return) {
		$datahash = sha1($url."?".$data);
		if ($type & self::CACHE_NONPERSISTANT) {
			$this->cache[sha1($url."?".$data)] = $return;
		}
		
		if ($type & self::CACHE_PERSISTANTEXCLUSIVE) {
			if (!isset($_SESSION['osm_cache'])) {
				$_SESSION['osm_cache'] = array();
			}
			$_SESSION['osm_cache'][$datahash] = $return;
		}
	}
	
	/**
	 * Destroys the session cache
	 * 
	 * @return null
	 */
	public function destroyPersistantCache() {
		if (isset($_SESSION['osm_cache'])) {
			$_SESSION['osm_cache'] = array();
		}
	}
	
	/**
	 * Get the programme terms
	 * 
	 * @return Object
	 */
	public function getTerms() {
		return $this->perform_query('api.php?action=getTerms');
	}
	
	/**
	 * Get badges available
	 * 
	 * @param string $type Type, from the BADGETYPE_*
	 * 
	 * @return Object
	 */
	public function getBadges($type=null) {
		perform_query('challenges.php?action=getBadgeDetails&section=scouts&badgeType=challenge', $type==null? array(): array("badgeType"=>$type)); //badgeType = challenge/staged/activity
	}
	
	/**
	 * Get events
	 * 
	 * @param int $sectionid The section ID returned by getTerms()
	 * 
	 * @return object
	 */
	public function getEvents($sectionid) {
		$events = perform_query('events.php?action=getEvents&sectionid='.$sectionid);
		return $events;
	}
	
	/**
	 * List of records of the kids present in term $termid
	 * 
	 * @param string $sectionid The section ID returned by getTerms()
	 * @param string $termid    The term ID returned by getTerms()
	 * 
	 * @return Object
	 */
	public function getKidsByTermID($sectionid, $termid) {
		return perform_query('challenges.php?termid='.$termid.'&type=challenge&section=scouts&c=community&sectionid='. $sectionid, array());
	}
}