<?php
/*
This is single-file SSO client for Discourse.

# Latest version on Github:
https://github.com/ArseniyShestakov/singlefile-discourse-sso-php
# Discourse How-To about setting SSO provider:
https://meta.discourse.org/t/using-discourse-as-a-sso-provider/32974
# Based off paxmanchris example:
https://gist.github.com/paxmanchris/e93018a3e8fbdfced039
*/
define('SSO_DB_HOST', 'localhost');
define('SSO_DB_USERNAME', '');
define('SSO_DB_PASSWORD', '');
define('SSO_DB_SCHEMA', '');
define('SSO_DB_TABLE', 'sso_login');

define('SSO_URL_LOGGED', 'https://'.$_SERVER['HTTP_HOST']);
define('SSO_URL_SCRIPT', 'https://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME']);
define('SSO_URL_DISCOURSE', 'https://example.com');
// "sso secret" from Discourse admin panel
// Good way to generate one on Linux: pwgen -syc
define('SSO_SECRET', '<CHANGE_ME>');
// Another secret used for sign local cookie
define('SSO_LOCAL_SECRET', '<CHANGE_ME>');
// Seconds before new nonce expire
define('SSO_TIMEOUT', 60);
// Seconds before SSO authentication expire
define('SSO_EXPIRE', 2592000);
define('SSO_COOKIE', '__discourse_sso');
define('SSO_COOKIE_DOMAIN', $_SERVER['HTTP_HOST']);
define('SSO_COOKIE_SECURE', true);
define('SSO_COOKIE_HTTPONLY', true);

// We'll only redirect to Discrouse if script executed directly
if(basename(__FILE__) === basename($_SERVER['SCRIPT_NAME']))
{
	$DISCOURSE_SSO = new DiscourseSSOClient(true);
	$status = $DISCOURSE_SSO->getAuthentication();
	if(false !== $status && true == $status['logged'])
	{
		header('Location: '.URL_LOGGEDREDIRECT);
	}
	else if(empty($_GET) || !isset($_GET['sso']) || !isset($_GET['sig']))
	{
		$DISCOURSE_SSO->authenticate();
	}
	else
	{
		$DISCOURSE_SSO->verify($_GET['sso'], $_GET['sig']);
	}
}

class DiscourseSSOClient
{
	private $mysqli;
	private $sqlStructure = 'CREATE TABLE IF NOT EXISTS `%s` (
		`id` int(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		`nonce` text NOT NULL,
		`logged` Tinyint(1) NOT NULL,
		`name` text,
		`username` text,
		`email` text,
		`admin` Tinyint(1) NOT NULL,
		`moderator` Tinyint(1) NOT NULL,
		`expire` int(11) NOT NULL
	)';

	public function __construct($createTableIfNotExist = false)
	{
		$this->mysqli = new mysqli(SSO_DB_HOST, SSO_DB_USERNAME, SSO_DB_PASSWORD, SSO_DB_SCHEMA);
		if(mysqli_connect_errno())
		{
			exit('Discourse SSO: could not connect to MySQL database!');
		}
		if($createTableIfNotExist)
			$this->createTableIfNotExist();
		if(rand(0, 10) === 50)
			$this->removeExpiredNonces();
	}

	public function getAuthentication()
	{
		if(empty($_COOKIE) || !isset($_COOKIE[SSO_COOKIE]))
			return false;


		$cookie_nonce = explode(',', $_COOKIE[SSO_COOKIE], 2);
		if($cookie_nonce[1] !== $this->signCookie($cookie_nonce[0]))
			return false;

		$status = $this->getStatus($this->clear($cookie_nonce[0]));
		if(false === $status)
			return false;

		return $status;
	}

	public function authenticate()
	{
		$nonce = hash('sha512', mt_rand().time());
		$nonceExpire = time() + SSO_TIMEOUT;
		$this->addNonce($nonce, $nonceExpire);
		$this->setCookie($nonce, $nonceExpire);
		$payload = base64_encode(http_build_query(array(
			'nonce' => $nonce,
			'return_sso_url' => SSO_URL_SCRIPT
		)));
		$request = array(
			'sso' => $payload,
			'sig' => hash_hmac('sha256', $payload, SSO_SECRET)
		);
		$url = $this->getUrl($request);
		header('Location: '.$url);
		echo '<a href='.$url.'>Sign in with Discourse</a><pre>';
	}

	public function verify($sso, $signature)
	{
		$sso = urldecode($sso);
		if(hash_hmac('sha256', $sso, SSO_SECRET) !== $signature)
		{
			header('HTTP/1.1 404 Not Found');
			exit();
		}

		$query = array();
		parse_str(base64_decode($sso), $query);
		$query['nonce'] = $this->clear($query['nonce']);

		if(false === $this->getStatus($query['nonce'])){
			header('HTTP/1.1 404 Not Found');
			exit();
		}

		$loginExpire = time() + SSO_EXPIRE;
		$this->loginUser($query, $loginExpire);
		$this->setCookie($query['nonce'], $loginExpire);
		header('Access-Control-Allow-Origin: *');
		header('Location: '.SSO_URL_LOGGED);
	}

	private function removeExpiredNonces()
	{
		$this->mysqli->query('DELETE FROM '.SSO_DB_TABLE.' WHERE expire < UNIX_TIMESTAMP()');
	}

	private function addNonce($nonce, $expire)
	{
		$nonce = $this->mysqli->escape_string($nonce);
		$this->mysqli->query("INSERT INTO ".SSO_DB_TABLE." (`id`, `nonce`, `logged`, `expire`) VALUES (NULL, '$nonce', '0', '".$expire."');");
	}

	private function getStatus($nonce)
	{
		$return = array(
			'nonce' => $nonce,
			'logged' => false,
			'data' => array(
				'name' => '',
				'username' => '',
				'email' => '',
				'admin'	=> false,
				'moderator'	=> false
			)
		);
		$nonce = $this->mysqli->escape_string($nonce);
		if($result = $this->mysqli->query("SELECT * FROM ".SSO_DB_TABLE." WHERE `nonce`='$nonce' AND `expire` > UNIX_TIMESTAMP()"))
		{
			if($result->num_rows === 1)
			{
				$row = $result->fetch_assoc();
				$return['logged'] = intval($row['logged']) == 1;
				$return['data']['name'] = $row['name'];
				$return['data']['username'] = $row['username'];
				$return['data']['email'] = $row['email'];
				$return['data']['admin'] = intval($row['admin']) == 1;
				$return['data']['moderator'] = intval($row['admin']) == 1;
			}
			return $return;
		}
		return false;
	}

	private function loginUser($data, $expire)
	{
		$isAdmin = $data['admin'] === 'true' ? '1' : '0';
		$isModerator = $data['moderator'] === 'true' ? '1' : '0';
		$this->mysqli->query("UPDATE `".SSO_DB_TABLE."`
			SET
				`logged` = 1,
				`expire` = ".$expire.",
				`name` = '".$this->mysqli->escape_string($data['name'])."',
				`username` = '".$this->mysqli->escape_string($data['username'])."',
				`email` = '".$this->mysqli->escape_string($data['email'])."',
				`admin` = '".$isAdmin."',
				`moderator` = '".$isModerator."'
			WHERE `nonce` = '".$this->mysqli->escape_string($data['nonce'])."'");
	}

	private function setCookie($value, $expire)
	{
		setcookie(SSO_COOKIE, $value.','.$this->signCookie($value), $expire, "/", SSO_COOKIE_DOMAIN, SSO_COOKIE_SECURE, SSO_COOKIE_HTTPONLY);
	}

	private function getUrl($request)
	{
		return SSO_URL_DISCOURSE.'/session/sso_provider?'.http_build_query($request);
	}

	private function signCookie($string)
	{
		return hash_hmac('sha256', $string, SSO_LOCAL_SECRET);
	}

	private function clear($string)
	{
		return preg_replace('[^A-Za-z0-9_]', '', trim($string));
	}

	private function createTableIfNotExist()
	{
		if($result = $this->mysqli->query(sprintf("SHOW TABLES LIKE '%s'", SSO_DB_TABLE)))
		{
			if($result->num_rows != 1)
			{
				$this->mysqli->query(sprintf($this->sqlStructure, SSO_DB_TABLE));
			}
		}
	}

	public function dropTable()
	{
		$this->mysqli->query("DROP TABLE IF EXISTS ".SSO_DB_TABLE);
	}
}
