<?php
/**
 * CodeIgniter
 *
 * An open source application development framework for PHP
 *
 * This content is released under the MIT License (MIT)
 *
 * Copyright (c) 2014-2017 British Columbia Institute of Technology
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package    CodeIgniter
 * @author     CodeIgniter Dev Team
 * @copyright  2014-2017 British Columbia Institute of Technology (https://bcit.ca/)
 * @license    https://opensource.org/licenses/MIT	MIT License
 * @link       https://codeigniter.com
 * @since      Version 4.0.0
 * @filesource
 */

namespace CodeIgniter\Encryption;

use Config\Encryption as EncryptionConfig;
use CodeIgniter\Encryption\Exceptions\EncryptionException;
use CodeIgniter\Config\Services;

/**
 * CodeIgniter Encryption Manager
 *
 * Provides two-way keyed encryption via PHP's Sodium and/or OpenSSL extensions.
 * This class determines the driver, cipher, and mode to use, and then
 * initializes the appropriate encryption handler.
 */
class Encryption
{

	/**
	 * The encrypter we create
	 *
	 * @var string
	 */
	protected $encrypter;

	/**
	 * Our remembered configuration
	 */
	protected $config = null;

	/**
	 * Our default configuration
	 */
	protected $default = [
		'driver' => 'OpenSSL', // The PHP extension we plan to use
		'key'    => '', // no starting key material
	];
	protected $driver, $key;

	/**
	 * HMAC digest to use
	 */
	protected $digest = 'SHA512';

	/**
	 * Map of drivers to handler classes, in preference order
	 *
	 * @var array
	 */
	protected $drivers = [
		'OpenSSL',
		'Sodium',
	];

	// --------------------------------------------------------------------

	/**
	 * Class constructor
	 *
	 * @param  mixed $params Configuration parameters
	 * @return void
	 *
	 * @throws \CodeIgniter\Encryption\Exceptions\EncryptionException
	 */
	public function __construct($params = null)
	{
		$this->config = array_merge($this->default, (array) new \Config\Encryption());

		if (is_string($params))
		{
			$params = ['driver' => $params];
		}

		$params = $this->properParams($params);

		// Check for an unknown driver
		if (isset($this->drivers[$params['driver']]))
		{
			throw EncryptionException::forDriverNotAvailable($params['driver']);
		}

		// determine what is installed
		$this->handlers = [
			'OpenSSL' => extension_loaded('openssl'),
			'Sodium'  => extension_loaded('libsodium'),
		];

		if (! in_array(true, $this->handlers))
		{
			throw EncryptionException::forNoHandlerAvailable();
		}
	}

	/**
	 * Initialize or re-initialize an encrypter
	 *
	 * @param  array $params Configuration parameters
	 * @return \CodeIgniter\Encryption\EncrypterInterface
	 *
	 * @throws \CodeIgniter\Encryption\Exceptions\EncryptionException
	 */
	public function initialize(array $params = [])
	{
		$params = $this->properParams($params);

		// Insist on a driver
		if (! isset($params['driver']))
		{
			throw EncryptionException::forNoDriverRequested();
		}

		// Check for an unknown driver
		if (! in_array($params['driver'], $this->drivers))
		{
			throw EncryptionException::forUnKnownHandler($params['driver']);
		}

		// Check for an unavailable driver
		if (! $this->handlers[$params['driver']])
		{
			throw EncryptionException::forDriverNotAvailable($params['driver']);
		}

		// Derive a secret key for the encrypter
		if (isset($params['key']))
		{
			$hmacKey          = strcmp(phpversion(), '7.1.2') >= 0 ? \hash_hkdf($this->digest, $params['key']) : self::hkdf($params['key'], $this->digest);
			$params['secret'] = bin2hex($hmacKey);
		}

		$handlerName     = 'CodeIgniter\\Encryption\\Handlers\\' . $this->driver . 'Handler';
		$this->encrypter = new $handlerName($params);
		return $this->encrypter;
	}

	/**
	 * Determine proper parameters
	 *
	 * @param array|object $params
	 *
	 * @return array|null
	 */
	protected function properParams($params = null)
	{
		// use existing config if no parameters given
		if (empty($params))
		{
			$params = $this->config;
		}

		// treat the paramater as a Config object?
		if (is_object($params))
		{
			$params = (array) $params;
		}

		// override base config with passed parameters
		$params = array_merge($this->config, $params);
		// make sure we only have expected parameters
		$params = array_intersect_key($params, $this->default);

		// and remember what we are up to
		$this->config = $params;

		// make the parameters conveniently accessible
		foreach ($params as $pkey => $value)
		{
			$this->$pkey = $value;
		}

		return $params;
	}

	// --------------------------------------------------------------------

	/**
	 * Create a random key
	 *
	 * @param  integer $length Output length
	 * @return string
	 */
	public static function createKey($length = 32)
	{
		return random_bytes($length);
	}

	// --------------------------------------------------------------------

	/**
	 * __get() magic, providing readonly access to some of our protected properties
	 *
	 * @param  string $key Property name
	 * @return mixed
	 */
	public function __get($key)
	{
		if (in_array($key, ['config', 'key', 'driver', 'drivers', 'default'], true))
		{
			return $this->{$key};
		}

		return null;
	}

	// --------------------------------------------------------------------

	/**
	 * Byte-safe strlen()
	 *
	 * @param  string $str
	 * @return integer
	 */
	protected static function strlen($str)
	{
		return mb_strlen($str, '8bit');
	}

	// --------------------------------------------------------------------

	/**
	 * HKDF legacy implementation, from CodeIgniter3.
	 *
	 * Fallback if PHP version < 7.1.2
	 *
	 * @link https://tools.ietf.org/rfc/rfc5869.txt
	 *
	 * @param string  $key    Input key
	 * @param string  $digest A SHA-2 hashing algorithm
	 * @param string  $salt   Optional salt
	 * @param integer $length Output length (defaults to the selected digest size)
	 * @param string  $info   Optional context/application-specific info
	 *
	 * @return string    A pseudo-random key
	 */
	public static function hkdf($key, $digest = 'sha512', $salt = null, $length = 64, $info = '')
	{
		self::strlen($salt) || $salt = str_repeat("\0", $length);

		$prk = hash_hmac($digest, $key, $salt, true);
		$key = '';
		for ($key_block = '', $block_index = 1; self::strlen($key) < $length; $block_index ++)
		{
			$key_block = hash_hmac($digest, $key_block . $info . chr($block_index), $prk, true);
			$key      .= $key_block;
		}

		return self::substr($key, 0, $length);
	}

	// --------------------------------------------------------------------

	/**
	 * Byte-safe substr()
	 *
	 * @param  string  $str
	 * @param  integer $start
	 * @param  integer $length
	 * @return string
	 */
	protected static function substr($str, $start, $length = null)
	{
		return mb_substr($str, $start, $length, '8bit');
	}

}
