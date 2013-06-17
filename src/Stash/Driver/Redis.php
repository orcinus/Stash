<?php

/*
 * This file is part of the Stash package.
 *
 * (c) Robert Hafner <tedivm@tedivm.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Stash\Driver;

use Stash;

/**
 * The Redis driver is used for storing data on a Redis system. This class uses
 * the PhpRedis extension to access the Redis server.
 *
 * @package Stash
 * @author  Robert Hafner <tedivm@tedivm.com>
 */
class Redis implements DriverInterface
{
    protected $defaultOptions = array ();
    protected $redis;
    protected $keyCache = array();

    /**
     *
     * @param array $options
     */
    public function __construct(array $options = array())
    {
       if(!self::isAvailable())
           throw new \RuntimeException('Unable to load Redis driver without PhpRedis extension.');

        // Normalize Server Options
        if(isset($options['servers']))
        {
            $servers = (is_array($options['servers']))
                ? $options['servers']
                : array($options['servers']);

            unset($options['servers']);

        }else{
            $servers = array(array('host' => '127.0.0.1', 'port' => '6379', 'ttl' => 0.1));
        }

        // Merge in default values.
        $options = array_merge($this->defaultOptions, $options);




        // this will have to be revisited to support multiple servers, using
        // the RedisArray object. That object acts as a proxy object, meaning
        // most of the class will be the same even after the changes.


        $server = $servers[0];

        $redis = new \Redis();



        if(isset($server['socket'])) {
            $redis->connect($server['socket']);
        }else{
            $port = isset($server['port']) ? $server['port'] : 6369;
            $ttl = isset($server['ttl']) ? $server['ttl'] : 0.1;
            $redis->connect($server['host'], $port, $ttl);
        }

        // auth - just password
        if(isset($options['password']))
            $redis->auth($options['password']);


        // select database
        if(isset($options['database']))
            $redis->select($options['database']);

        $this->redis = $redis;


    }

    /**
     *
     *
     * @param array $key
     * @return array
     */
    public function getData($key)
    {
        return unserialize($this->redis->get($this->makeKeyString($key)));
    }

    /**
     *
     *
     * @param array $key
     * @param array $data
     * @param int $expiration
     * @return bool
     */
    public function storeData($key, $data, $expiration)
    {
        $store = serialize(array('data' => $data, 'expiration' => $expiration));
        if(is_null($expiration))
        {
            return $this->redis->setex($this->makeKeyString($key), $store, $expiration);
        }else{
            return $this->redis->set($this->makeKeyString($key), $store);
        }
    }

    /**
     * Clears the cache tree using the key array provided as the key. If called with no arguments the entire cache gets
     * cleared.
     *
     * @param null|array $key
     * @return bool
     */
    public function clear($key = null)
    {
        if(is_null($key))
        {
            $this->redis->flushDB();
            return true;
        }

        $keyString = $this->makeKeyString($key);
        $keyReal = $this->makeKeyString($key);
        $this->redis->incr($keyString); // increment index for children items
        $this->redis->delete($keyReal); // remove direct item.
        $this->keyCache = array();
        return true;
    }

    /**
     *
     * @return bool
     */
    public function purge()
    {
        // @todo when the RedisArray class is used run the rehash function here

        return true;
    }

    /**
     *
     *
     * @return bool
     */
    static public function isAvailable()
    {
        return class_exists('Redis', false);
    }


    protected function makeKeyString($key, $path = false)
    {
        // array(name, sub);
        // a => name, b => sub;

        $key = \Stash\Utilities::normalizeKeys($key);

        $keyString = 'cache:::';
        foreach ($key as $name) {
            //a. cache:::name
            //b. cache:::name0:::sub
            $keyString .= $name;

            //a. :pathdb::cache:::name
            //b. :pathdb::cache:::name0:::sub
            $pathKey = ':pathdb::' . $keyString;
            $pathKey = md5($pathKey);

            if (isset($this->keyCache[$pathKey])) {
                $index = $this->keyCache[$pathKey];
            } else {
                $index = $this->redis->incr($pathKey);
                $this->keyCache[$pathKey] = $index;
            }

            //a. cache:::name0:::
            //b. cache:::name0:::sub1:::
            $keyString .= '_' . $index . ':::';
        }

        return $path ? $pathKey : md5($keyString);
    }


}
