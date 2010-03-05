<?php

/**
 * -----------------------------------------------------------------------------
 *  M Y S Q U I R R E L   :   Protect PHP and MySQL against injection attacks!
 * -----------------------------------------------------------------------------
 * 
 * @package    MySquirrel
 * @author     Kijin Sung <kijinbear@gmail.com>
 * @copyright  (c) 2010, Kijin Sung <kijinbear@gmail.com>
 * @license    GPL v3 <http://www.opensource.org/licenses/gpl-3.0.html>
 * @link       http://github.com/kijin/mysquirrel
 * @version    0.1.0
 * 
 * -----------------------------------------------------------------------------
 * 
 * Copyright (c) 2010, Kijin Sung <kijinbear@gmail.com>
 * 
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * ----------------------------------------------------------------------------
 */

class MySquirrel
{
    // Connect method.
    
    public static function connect($host, $user, $pass, $database, $charset = false)
    {
        // Check if the same connection object is already cached.
        
        $identifier = md5("$host::$user::$pass::$database");
        if (isset(self::$instances[$identifier])) return self::$instances[$identifier];
        
        // Connect.
        
        $con = @mysql_connect($host, $user, $pass);
        if (!$con) throw new MySquirrelException('Could not connect to ' . $host . '.');
        
        $sdb = @mysql_select_db($database, $con);
        if (!$sdb) throw new MySquirrelException('Could not select database ' . $database . '.');
        
        // Select charset (only available in MySQL 5.0.7+).
        
        if ($charset !== false)
        {
            if (version_compare(PHP_VERSION, '5.2.3', '<'))
            {
                $chs = @mysql_query('SET NAMES ' . mysql_real_escape_string($charset, $con), $con);
            }
            else
            {
                $chs = @mysql_set_charset($charset, $con);
            }
            if (!$chs) throw new MySquirrelException('Could not set charset to ' . $charset . '.');
        }
        
        // Cache and return an instance of MySquirrel.
        
        return self::$instances[$identifier] = new MySquirrel($con);
    }
    
    // Instances are cached here.
    
    private static $instances = array();
    
    // Constructor.
    
    private function __construct($connection)
    {
        // Connection resource is passed from connect().
        
        $this->connection = $connection;
    }
    
    // Instance-specific private properties.
    
    private $connection = null;
    private $paranoid = false;
    
    // Paranoid method.
    
    public function paranoid()
    {
        // When in paranoid mode, raw queries are disallowed, and quotes in queries are not permitted.
        
        return $this->paranoid = true;
    }
    
    // Query method.
    
    public function query($querystring /* and parameters */ )
    {
        // Refuse to execute multiple statements at the same time.
        
        $querystring = trim($querystring, " \t\r\n;");
        if (strpos($querystring, ';') !== false)
        {
            throw new MySquirrelException('You are not allowed to execute multiple statements at once.');
        }
        
        // If in paranoid mode, refuse to execute querystrings with quotes in them.
        
        if ($this->paranoid && (strpos($querystring, '\'') !== false || strpos($querystring, '"') !== false))
        {
            throw new MySquirrelException('You are using paranoid mode. You are not allowed to use querystrings with quotes in them.');
        }
        
        // Get all parameters.
        
        $params = func_get_args();
        array_shift($params);
        
        // Count the number of placeholders.
        
        $count = substr_count($querystring, '?');
        if ($count !== count($params))
        {
            throw new MySquirrelException('Querystring has ' . $count . ' placeholders, but ' . count($params) . ' parameters given.');
        }
        
        // Replace all placeholders with properly escaped parameter values.
        
        $queryparts = explode('?', $querystring);
        for ($i = 0; $i < $count; $i++)
        {
            $param = $params[$i];
            if (get_magic_quotes_runtime()) $param = stripslashes($param);
            $queryparts[$i] .= "'" . mysql_real_escape_string($param, $this->connection) . "'";
        }
        $querystring = implode('', $queryparts);
        
        // Run the reconstructed query.
        
        $result = @mysql_query($querystring, $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        
        // If the result is boolean, return boolean.
        
        if ($result === true || $result === false)
        {
            return $result;
        }
        
        // Otherwise, return an instance of MySquirrelResult.
        
        else
        {
            return new MySquirrelResult($result);
        }
    }
    
    // Raw query method.
    
    public function rawQuery($querystring)
    {
        // Not in paranoid mode.
        
        if ($this->paranoid) throw new MySquirrelException('raw_query() is disabled in paranoid mode.');
        
        // Just query.
        
        $result = @mysql_query($querystring, $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        
        // If the result is boolean, return boolean.
        
        if ($result === true || $result === false)
        {
            return $result;
        }
        
        // Otherwise, return an instance of MySquirrelResult.
        
        else
        {
            return new MySquirrelResult($result);
        }
    }
    
    // Number of affected rows.
    
    public function affectedRows()
    {
        return mysql_affected_rows($this->connection);
    }
    
    // Last insert ID.
    
    public function lastInsertID()
    {
        // Return the last insert ID.
        
        return mysql_insert_id($this->connection);
    }
    
    // Begin transaction.
    
    public function beginTransaction()
    {
        // No native support, so we just fire off a literal query.
        
        return @mysql_query('BEGIN TRANSACTION', $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
    }
    
    // Commit transaction.
    
    public function commit()
    {
        // No native support, so we just fire off a literal query.
        
        return @mysql_query('COMMIT', $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
    }
    
    // Roll back transaction.
    
    public function rollback()
    {
        // No native support, so we just fire off a literal query.
        
        return @mysql_query('ROLLBACK', $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
    }
    
    // Destructor.
    
    public function __destruct()
    {
        @mysql_close($this->connection);
    }
}

// MySquirrel result class. An instance of this class is returned from SELECT queries.

class MySquirrelResult
{
    // Constructor.
    
    public function __construct($result)
    {
        // Result resource is passed from MySquirrel::query().
        
        if (!is_resource($result)) throw new Exception('Result is not a valid resource.');
        $this->result = $result;
    }
    
    // Result resource is stored here.
    
    private $result = null;
    
    // Fetch method (generic).
    
    public function fetch($type = MYSQL_BOTH)
    {
        return mysql_fetch_array($this->result, $type);
    }

    // Fetch method (returns associated array).
    
    public function fetchAssoc()
    {
        return mysql_fetch_assoc($this->result);
    }
    
    // Fetch method (returns object).
    
    public function fetchObject($class_name = false, $params = array())
    {
        return $class_name ? mysql_fetch_object($this->result, $class_name, $params) : mysql_fetch_object($this->result);
    }
    
    // Fetch method (returns enumerated array).
    
    public function fetchRow()
    {
        return mysql_fetch_row($this->result);
    }
    
    // Get field info.
    
    public function fieldInfo($offset)
    {
        return array(
            'name' => mysql_field_name($this->result, $offset),
            'type' => mysql_field_type($this->result, $offset),
            'length' => mysql_field_len($this->result, $offset),
            'table' => mysql_field_table($this->result, $offset),
            'flags' => mysql_field_flags($this->result, $offset),
        );
    }
    
    // Number of fields.
    
    public function numFields()
    {
        return mysql_num_fields($this->result);
    }
    
    // Number of rows.
    
    public function numRows()
    {
        return mysql_num_rows($this->result);
    }
    
    // Destructor.
    
    public function __destruct()
    {
        mysql_free_result($this->result);
    }
}

// MySquirrel exception class. If your code is lousy, you'll see a lot of these.

class MySquirrelException extends Exception
{
    
}
