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
 * @version    0.2.2
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
        if (isset(self::$handles[$identifier]))
        {
            return self::$handles[$identifier];
        }
        
        // Decide on the best driver to use, and instantiate it.
        
        elseif (extension_loaded('mysqli'))
        {
            return self::$handles[$identifier] = new MySquirrelDriver_MySQLi($host, $user, $pass, $database, $charset = false);
        }
        elseif (extension_loaded('mysql'))
        {
            return self::$handles[$identifier] = new MySquirrelDriver_MySQL($host, $user, $pass, $database, $charset = false);
        }
        else
        {
            throw new MySquirrelException('Your installation of PHP does not support MySQL connectivity.');
        }
    }
    
    // Database handles are cached here.
    
    private static $handles = array();
    
    // Sequence generation method (for prepared statements).
    
    public static function sequence()
    {
        // PHP scripts are single-threaded, so this is good enough.
        
        static $val = 1;
        return $val++;
    }
}


/**
 * Drivers for various MySQL extensions.
 * 
 * Regardless of the underlying extension, all drivers expose the same public
 * methods. The user does not need to care which driver is in use.
 */

interface MySquirrelDriver
{
    public function __construct($host, $user, $pass, $database, $charset = false);
    public function paranoid();
    public function prepare($querystring);
    public function query($querystring);
    public function rawQuery($querystring);
    public function affectedRows();
    public function lastInsertID();
    public function beginTransaction();
    public function commit();
    public function rollback();
}

// MySquirrel Driver for MySQLi.

class MySquirrelDriver_MySQLi implements MySquirrelDriver
{
    // Instance-specific private properties.
    
    private $host;
    private $user;
    private $pass;
    private $database;
    private $charset;
    private $connection = false;
    private $paranoid = false;
    
    // Constructor.
    
    public function __construct($host, $user, $pass, $database, $charset = false)
    {
        // Store parameters as private properties.
        
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->database = $database;
        $this->charset = $charset;
    }
    
    // Paranoid method.
    
    public function paranoid()
    {
        // When in paranoid mode, raw queries are disabled, and quotes in queries are not permitted.
        
        return $this->paranoid = true;
    }
    
    // Connect method (private).
    
    private function connect()
    {
        // Connect.
        
        $this->connection = new MySQLi($this->host, $this->user, $this->pass);
        if (mysqli_connect_errno()) throw new MySquirrelException('Could not connect to ' . $this->host . ': ' . mysqli_connect_error());
        
        $select_db = @$this->connection->select_db($this->database);
        if (!$select_db) throw new MySquirrelException('Could not select database ' . $this->database . '.');
        
        // Select charset.
        
        if ($this->charset !== false)
        {
            $select_charset = @$this->connection->set_charset($this->charset);
            if (!$select_charset) throw new MySquirrelException('Could not set charset to ' . $this->charset . '.');
        }
    }
    
    // Prepare method.
    
    public function prepare($querystring)
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->connect();
        
        // Instantiate and return a new prepared statement object.
        
        return new MySquirrelPreparedStmt_MySQLi($this->connection, $querystring, $this->paranoid);
    }
    
    // Query method.
    
    public function query($querystring /* and parameters */ )
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->connect();
        
        // Refuse to execute multiple statements at the same time.
        
        $querystring = trim($querystring, " \t\r\n;");
        if (strpos($querystring, ';') !== false)
        {
            throw new MySquirrelException('You are not allowed to execute multiple statements at once.');
        }
        
        // If in paranoid mode, refuse to execute querystrings with quotes in them.
        
        if ($this->paranoid && (strpos($querystring, '\'') !== false || strpos($querystring, '"') !== false || strpos($querystring, '--') !== false))
        {
            throw new MySquirrelException('While in paranoid mode, you cannot use querystrings with quotes or comments in them.');
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
            if (is_numeric($param))
            {
                $queryparts[$i] .= $param;
            }
            else
            {
                if (get_magic_quotes_runtime()) $param = stripslashes($param);
                $queryparts[$i] .= "'" . $this->connection->real_escape_string($param) . "'";
            }
        }
        $querystring = implode('', $queryparts);
        
        // Run the reconstructed query.
        
        $result = $this->connection->query($querystring);
        if ($error = mysqli_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysqli_error($this->connection));
        }
        
        // Return the result.
        
        if ($result === true || $result === false) return $result;
        return new MySquirrelResult_MySQLi($result);
    }
    
    // Raw query method.
    
    public function rawQuery($querystring)
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->connect();
        
        // Not in paranoid mode.
        
        if ($this->paranoid) throw new MySquirrelException('raw_query() is disabled in paranoid mode.');
        
        // Just query.
        
        $result = $this->connection->query($querystring);
        if ($error = mysqli_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysqli_error($this->connection));
        }
        
        // Return the result.
        
        if ($result === true || $result === false) return $result;
        return new MySquirrelResult_MySQLi($result);
    }
    
    // Number of affected rows.
    
    public function affectedRows()
    {
        return $this->connection->affected_rows();
    }
    
    // Last insert ID.
    
    public function lastInsertID()
    {
        // Return the last insert ID.
        
        return $this->connection->insert_id();
    }
    
    // Begin transaction.
    
    public function beginTransaction()
    {
        // No native support, so we just fire off a literal query.
        
        $success = @$this->connection->autocommit(false);
        if ($error = mysqli_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysqli_error($this->connection));
        }
        return $success;
    }
    
    // Commit transaction.
    
    public function commit()
    {
        // No native support, so we just fire off a literal query.
        
        $success = @$this->connection->commit();
        if ($error = mysqli_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysqli_error($this->connection));
        }
        return $success;
    }
    
    // Roll back transaction.
    
    public function rollback()
    {
        // No native support, so we just fire off a literal query.
        
        $success = @$this->connection->rollback();
        if ($error = mysqli_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysqli_error($this->connection));
        }
        return $success;
    }
}

// MySquirrel driver for MySQL_* functions.

class MySquirrelDriver_MySQL implements MySquirrelDriver
{
    // Instance-specific private properties.
    
    private $host;
    private $user;
    private $pass;
    private $database;
    private $charset;
    private $connection = false;
    private $paranoid = false;
    
    // Constructor.
    
    public function __construct($host, $user, $pass, $database, $charset = false)
    {
        // Store parameters as private properties.
        
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->database = $database;
        $this->charset = $charset;
    }
    
    // Paranoid method.
    
    public function paranoid()
    {
        // When in paranoid mode, raw queries are disabled, and quotes in queries are not permitted.
        
        return $this->paranoid = true;
    }
    
    // Connect method (private).
    
    private function connect()
    {
        // Connect.
        
        $this->connection = @mysql_connect($this->host, $this->user, $this->pass);
        if (!$this->connection) throw new MySquirrelException('Could not connect to ' . $this->host . '.');
        
        $select_db = @mysql_select_db($this->database, $this->connection);
        if (!$select_db) throw new MySquirrelException('Could not select database ' . $this->database . '.');
        
        // Select charset (only available in MySQL 5.0.7+).
        
        if ($this->charset !== false)
        {
            if (version_compare(PHP_VERSION, '5.2.3', '>='))
            {
                $select_charset = @mysql_set_charset($this->charset, $this->connection);
            }
            else
            {
                $select_charset = @mysql_query('SET NAMES ' . mysql_real_escape_string($this->charset, $this->connection), $this->connection);
            }
            if (!$select_charset) throw new MySquirrelException('Could not set charset to ' . $this->charset . '.');
        }
    }
    
    // Prepare method.
    
    public function prepare($querystring)
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->connect();
        
        // Instantiate and return a new prepared statement object.
        
        return new MySquirrelPreparedStmt_MySQL($this->connection, $querystring, $this->paranoid);
    }
    
    // Query method.
    
    public function query($querystring /* and parameters */ )
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->connect();
        
        // Refuse to execute multiple statements at the same time.
        
        $querystring = trim($querystring, " \t\r\n;");
        if (strpos($querystring, ';') !== false)
        {
            throw new MySquirrelException('You are not allowed to execute multiple statements at once.');
        }
        
        // If in paranoid mode, refuse to execute querystrings with quotes in them.
        
        if ($this->paranoid && (strpos($querystring, '\'') !== false || strpos($querystring, '"') !== false || strpos($querystring, '--') !== false))
        {
            throw new MySquirrelException('While in paranoid mode, you cannot use querystrings with quotes or comments in them.');
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
            if (is_numeric($param))
            {
                $queryparts[$i] .= $param;
            }
            else
            {
                if (get_magic_quotes_runtime()) $param = stripslashes($param);
                $queryparts[$i] .= "'" . mysql_real_escape_string($param, $this->connection) . "'";
            }
        }
        $querystring = implode('', $queryparts);
        
        // Run the reconstructed query.
        
        $result = @mysql_query($querystring, $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        
        // Return the result.
        
        if ($result === true || $result === false) return $result;
        return new MySquirrelResult_MySQL($result);
    }
    
    // Raw query method.
    
    public function rawQuery($querystring)
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->connect();
        
        // Not in paranoid mode.
        
        if ($this->paranoid) throw new MySquirrelException('raw_query() is disabled in paranoid mode.');
        
        // Just query.
        
        $result = @mysql_query($querystring, $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        
        // Return the result.
        
        if ($result === true || $result === false) return $result;
        return new MySquirrelResult_MySQL($result);
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
        
        $success = @mysql_query('BEGIN TRANSACTION', $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        return $success;
    }
    
    // Commit transaction.
    
    public function commit()
    {
        // No native support, so we just fire off a literal query.
        
        $success = @mysql_query('COMMIT', $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        return $success;
    }
    
    // Roll back transaction.
    
    public function rollback()
    {
        // No native support, so we just fire off a literal query.
        
        $success = @mysql_query('ROLLBACK', $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        return $success;
    }
}


/**
 * Prepared statement classes various MySQL extensions.
 * 
 * Regardless of the underlying extension, all drivers expose the same public
 * methods. The user does not need to care which driver is in use.
 */

interface MySquirrelPreparedStmt
{
    public function __construct($connection, $querystring, $paranoid);
    public function execute();
}

// MySquirrel prepared statement class for MySQLi.

class MySquirrelPreparedStmt_MySQLi implements MySquirrelPreparedStmt
{
    // Constructor.
    
    public function __construct($connection, $querystring, $paranoid)
    {
        // Store the arguments in the instance.
        
        $this->connection = $connection;
        $this->querystring = $querystring;
        
        // Refuse to execute multiple statements at the same time.
        
        $querystring = trim($querystring, " \t\r\n;");
        if (strpos($querystring, ';') !== false)
        {
            throw new MySquirrelException('You are not allowed to execute multiple statements at once.');
        }
        
        // If in paranoid mode, refuse to execute querystrings with quotes in them.
        
        if ($paranoid && (strpos($querystring, '\'') !== false || strpos($querystring, '"') !== false || strpos($querystring, '--') !== false))
        {
            throw new MySquirrelException('While in paranoid mode, you cannot use querystrings with quotes or comments in them.');
        }
        
        // Create a name for this prepared statement.
        
        $this->statement = 'mysquirrel' . MySquirrel::sequence();
        
        // Count the number of placeholders.
        
        $this->numargs = substr_count($querystring, '?');
        
        // Prepare the statement.
        
        $result = $this->connection->query('PREPARE ' . $this->statement . ' FROM \'' . $this->connection->real_escape_string($querystring) . '\'');
        if ($error = mysqli_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysqli_error($this->connection));
        }
    }
    
    // Information about the statement.
    
    private $connection;
    private $querystring;
    private $statement;
    private $numargs;
    
    // Execute method.
    
    public function execute( /* parameters */ )
    {
        // Get all parameters.
        
        $params = func_get_args();
        $count = func_num_args();
        if ($count !== $this->numargs)
        {
            throw new MySquirrelException('Prepared statement has ' . $this->numargs . ' placeholders, but ' . $count . ' parameters given.');
        }
        
        // Initialize the execute querystring.
        
        $querystring = 'EXECUTE ' . $this->statement;
        
        // Set server-side variables with properly escaped parameter values.
        
        for ($i = 0; $i < $count; $i++)
        {
            $param = $params[$i];
            if (!is_numeric($param))
            {
                if (get_magic_quotes_runtime()) $param = stripslashes($param);
                $param = "'" . $this->connection->real_escape_string($param) . "'";
            }
            $varname = '@' . $this->statement . '_v' . $i;
            $this->connection->query('SET ' . $varname . ' = ' . $param);
            if ($i == 0)
            {
                $querystring .= ' USING ' . $varname;
            }
            else
            {
                $querystring .= ', ' . $varname;
            }
        }
        
        // Execute the query.
        
        $result = $this->connection->query($querystring);
        if ($error = mysqli_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysqli_error($this->connection));
        }
        
        // Return the result.
        
        if ($result === true || $result === false) return $result;
        return new MySquirrelResult_MySQLi($result);
    }
    
    // Destructor.
    
    public function __destruct()
    {
        // Deallocate the statement.
        
        $this->connection->query('DEALLOCATE PREPARE ' . $this->statement);
    }
}

// MySquirrel prepared statement class for MySQL_* functions.

class MySquirrelPreparedStmt_MySQL implements MySquirrelPreparedStmt
{
    // Constructor.
    
    public function __construct($connection, $querystring, $paranoid)
    {
        // Store the arguments in the instance.
        
        $this->connection = $connection;
        $this->querystring = $querystring;
        
        // Refuse to execute multiple statements at the same time.
        
        $querystring = trim($querystring, " \t\r\n;");
        if (strpos($querystring, ';') !== false)
        {
            throw new MySquirrelException('You are not allowed to execute multiple statements at once.');
        }
        
        // If in paranoid mode, refuse to execute querystrings with quotes in them.
        
        if ($paranoid && (strpos($querystring, '\'') !== false || strpos($querystring, '"') !== false || strpos($querystring, '--') !== false))
        {
            throw new MySquirrelException('While in paranoid mode, you cannot use querystrings with quotes or comments in them.');
        }
        
        // Create a name for this prepared statement.
        
        $this->statement = 'mysquirrel' . MySquirrel::sequence();
        
        // Count the number of placeholders.
        
        $this->numargs = substr_count($querystring, '?');
        
        // Prepare the statement.
        
        $result = @mysql_query('PREPARE ' . $this->statement . ' FROM \'' . mysql_real_escape_string($querystring, $this->connection) . '\'', $this->connection);
        
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
    }
    
    // Information about the statement.
    
    private $connection;
    private $querystring;
    private $statement;
    private $numargs;
    
    // Execute method.
    
    public function execute( /* parameters */ )
    {
        // Get all parameters.
        
        $params = func_get_args();
        $count = func_num_args();
        if ($count !== $this->numargs)
        {
            throw new MySquirrelException('Prepared statement has ' . $this->numargs . ' placeholders, but ' . $count . ' parameters given.');
        }
        
        // Initialize the execute querystring.
        
        $querystring = 'EXECUTE ' . $this->statement;
        
        // Set server-side variables with properly escaped parameter values.
        
        for ($i = 0; $i < $count; $i++)
        {
            $param = $params[$i];
            if (!is_numeric($param))
            {
                if (get_magic_quotes_runtime()) $param = stripslashes($param);
                $param = "'" . mysql_real_escape_string($param, $this->connection) . "'";
            }
            $varname = '@' . $this->statement . '_v' . $i;
            mysql_query('SET ' . $varname . ' = ' . $param, $this->connection);
            if ($i == 0)
            {
                $querystring .= ' USING ' . $varname;
            }
            else
            {
                $querystring .= ', ' . $varname;
            }
        }
        
        // Execute the query.
        
        $result = @mysql_query($querystring, $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        
        // Return the result.
        
        if ($result === true || $result === false) return $result;
        return new MySquirrelResult_MySQL($result);
    }
    
    // Destructor.
    
    public function __destruct()
    {
        // Deallocate the statement.
        
        mysql_query('DEALLOCATE PREPARE ' . $this->statement, $this->connection);
    }
}


/**
 * Result classes for various MySQL extensions.
 * 
 * Regardless of the underlying extension, all result classes expose the same
 * public methods. The user does not need to care which driver is in use.
 * One exception is the fieldInfo() method which, when using MySQLi, may return
 * more than the minumal set of values returned by MySQL. If portability is a
 * concern, do not rely on these extra values.
 */

interface MySquirrelResult
{
    public function __construct($result);
    public function fetch();
    public function fetchAssoc();
    public function fetchObject($class_name = false, $params = array());
    public function fetchRow();
    public function fetchAll();
    public function fieldInfo($offset);
    public function numFields();
    public function numRows();
}

// MySquirrel result class for MySQLi.

class MySquirrelResult_MySQLi implements MySquirrelResult
{
    // Constructor.
    
    public function __construct($result)
    {
        // Result object is passed from MySquirrelDriver_MySQLi::query().
        
        if (!($result instanceof MySQLi_Result)) throw new Exception('Result is not a valid object.');
        $this->result = $result;
    }
    
    // Result resource is stored here.
    
    private $result = null;
    
    // Fetch method (generic).
    
    public function fetch()
    {
        return $this->result->fetch_array(MYSQLI_BOTH);
    }

    // Fetch method (returns associated array).
    
    public function fetchAssoc()
    {
        return $this->result->fetch_assoc();
    }
    
    // Fetch method (returns object).
    
    public function fetchObject($class_name = false, $params = array())
    {
        return $class_name ? $this->result->fetch_object($class_name, $params) : $this->result->fetch_object();
    }
    
    // Fetch method (returns enumerated array).
    
    public function fetchRow()
    {
        return $this->result->fetch_row();
    }
    
    // Fetch-all method.
    
    public function fetchAll()
    {
        if (version_compare(PHP_VERSION, '5.3.0', '>='))
        {
            return $this->result->fetch_all(MYSQLI_BOTH);
        }
        else
        {
            $return = array();
            while ($row = $this->result->fetch_array(MYSQLI_BOTH)) $return[] = $row;
            return $return;
        }
    }
    
    // Get field info.
    
    public function fieldInfo($offset)
    {
        return (array)$this->result->fetch_field_direct($offset);
    }
    
    // Number of fields.
    
    public function numFields()
    {
        return mysqli_num_fields($this->result);
    }
    
    // Number of rows.
    
    public function numRows()
    {
        return mysqli_num_rows($this->result);
    }
    
    // Destructor.
    
    public function __destruct()
    {
        return $this->result->free();
    }
}

// MySquirrel result class for MySQL_* functions.

class MySquirrelResult_MySQL implements MySquirrelResult
{
    // Constructor.
    
    public function __construct($result)
    {
        // Result resource is passed from MySquirrelDriver_MySQL::query().
        
        if (!is_resource($result)) throw new Exception('Result is not a valid resource.');
        $this->result = $result;
    }
    
    // Result resource is stored here.
    
    private $result = null;
    
    // Fetch method (generic).
    
    public function fetch()
    {
        return mysql_fetch_array($this->result, MYSQL_BOTH);
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
    
    // Fetch-all method.
    
    public function fetchAll()
    {
        $return = array();
        while ($row = mysql_fetch_array($this->result, MYSQL_BOTH)) $return[] = $row;
        return $return;
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


/**
 * MySquirrel exception class.
 * 
 * No error passes silently. If your code is lousy, you'll see a lot of these.
 * It is your responsibility to handle these exceptions in an appropriate way.
 */

class MySquirrelException extends Exception
{
    
}
