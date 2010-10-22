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
 * @version    0.3.3
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

define('MYSQUIRREL_VERSION', '0.3.3');

/**
 * MySquirrel main class.
 * 
 * Instantiate this class to obtain a MySQL connection. This should be your main
 * gateway to all communications with the server.
 */

class MySquirrel
{
    // Some protected properties.
    
    protected $host;
    protected $user;
    protected $pass;
    protected $database;
    protected $charset;
    protected $connection = false;
    protected $paranoid = false;
    protected $unmagic = false;
    
    // Constructor.
    
    public function __construct($host, $user, $pass, $database, $charset = false)
    {
        // Check if MySQL is available.
        
        if (!function_exists('mysql_connect'))
        {
            throw new MySquirrelException('Your installation of PHP does not support MySQL connectivity.');
        }
        
        // Store parameters as private properties.
        
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->database = $database;
        $this->charset = $charset;
    }
    
    // Static connect method for 0.2 compatibility.
    
    public static function connect($host, $user, $pass, $database, $charset = false)
    {
        // This is how 0.2 used to do stuff.
        
        return new MySquirrel($host, $user, $pass, $database, $charset);
    }
    
    // Lazy connect method.
    
    protected function lazyConnect()
    {
        // Connect.
        
        $this->connection = mysql_connect($this->host, $this->user, $this->pass);
        if (!$this->connection) throw new MySquirrelException_ConnectionError('Could not connect to ' . $this->host . '.');
        
        $select_db = mysql_select_db($this->database, $this->connection);
        if (!$select_db) throw new MySquirrelException_ConnectionError('Could not select database ' . $this->database . '.');
        
        // Select charset (only available in MySQL 5.0.7+).
        
        if ($this->charset !== false)
        {
            if (function_exists('mysql_set_charset'))
            {
                $select_charset = mysql_set_charset($this->charset, $this->connection);
            }
            else
            {
                $select_charset = mysql_query('SET NAMES ' . mysql_real_escape_string($this->charset, $this->connection), $this->connection);
            }
            if (!$select_charset) throw new MySquirrelException_ConnectionError('Could not set charset to ' . $this->charset . '.');
        }
    }
    
    // Paranoid method.
    
    public function paranoid()
    {
        // When in paranoid mode, raw queries are disabled, and quotes in queries are not permitted.
        
        return $this->paranoid = true;
    }
    
    // Prepare method.
    
    public function prepare($querystring)
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->lazyConnect();
        
        // Instantiate and return a new prepared statement object.
        
        return new MySquirrelPreparedStmt($this->connection, $querystring, $this->paranoid, $this->unmagic);
    }
    
    // Query method.
    
    public function query($querystring /* and parameters */ )
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->lazyConnect();
        
        // Refuse to execute multiple statements at the same time.
        
        $querystring = trim($querystring, " \t\r\n;");
        if (strpos($querystring, ';') !== false)
        {
            throw new MySquirrelException_MultipleStatementsError('You cannot make multiple queries at once. Please use rawQuery() instead.');
        }
        
        // If in paranoid mode, refuse to execute querystrings with quotes in them.
        
        if ($this->paranoid && (strpos($querystring, '\'') !== false || strpos($querystring, '"') !== false || strpos($querystring, '--') !== false))
        {
            throw new MySquirrelException_ParanoidModeError('While in paranoid mode, you cannot use queries with quotes or comments in them.');
        }
        
        // Get all parameters.
        
        $params = func_get_args();
        array_shift($params);
        if (count($params) === 1 && is_array($params[0])) $params = $params[0];
        
        // Count the number of placeholders.
        
        $count = substr_count($querystring, '?');
        if ($count !== count($params))
        {
            throw new MySquirrelException_ParameterMismatchError('Query has ' . $count . ' placeholders, but ' . count($params) . ' parameters given.');
        }
        
        // Replace all placeholders with properly escaped parameter values.
        
        $queryparts = explode('?', $querystring);
        for ($i = 0; $i < $count; $i++)
        {
            $param = $params[$i];
            if ($this->unmagic) $param = stripslashes($param);
            $queryparts[$i] .= "'" . mysql_real_escape_string($param, $this->connection) . "'";
        }
        $querystring = implode('', $queryparts);
        
        // Run the reconstructed query.
        
        return $this->commonQuery($querystring);
    }
    
    // Raw query method.
    
    public function rawQuery($querystring)
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->lazyConnect();
        
        // Not in paranoid mode.
        
        if ($this->paranoid) throw new MySquirrelException_ParanoidModeError('rawQuery() is disabled in paranoid mode.');
        
        // Just query.
        
        return $this->commonQuery($querystring);
    }
    
    // Common query method.
    
    protected function commonQuery($querystring)
    {
        // Query, and handle errors.
        
        $result = mysql_query($querystring, $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        
        // Return the result.
        
        return (is_bool($result)) ? $result : new MySquirrelResult($result);
    }
    
    // Number of affected rows.
    
    public function affectedRows()
    {
        // Not useful unless connected.
        
        if ($this->connection === false) return false;
        
        // Return.
        
        return mysql_affected_rows($this->connection);
    }
    
    // Last insert ID.
    
    public function lastInsertID()
    {
        // Not useful unless connected.
        
        if ($this->connection === false) return false;
        
        // Return.
        
        return mysql_insert_id($this->connection);
    }
    
    // Begin transaction.
    
    public function beginTransaction()
    {
        // Lazy connecting.
        
        if ($this->connection === false) $this->lazyConnect();
        
        // No native support, so we just fire off a literal query.
        
        try
        {
            return $this->commonQuery('BEGIN');
        }
        catch (MySquirrelException $e)
        {
            throw new MySquirrelException_TransactionError('Can\'t begin: ' . $e->getMessage());
        }
    }
    
    // Commit transaction.
    
    public function commit()
    {
        // Can't commit when not connected.
        
        if ($this->connection === false) throw new MySquirrelException_TransactionError('Can\'t commit: No transaction is currently in progress.');
        
        // No native support, so we just fire off a literal query.
        
        try
        {
            return $this->commonQuery('COMMIT');
        }
        catch (MySquirrelException $e)
        {
            throw new MySquirrelException_TransactionError('Can\'t commit: ' . $e->getMessage());
        }
    }
    
    // Roll back transaction.
    
    public function rollback()
    {
        // Can't rollback when not connected.
        
        if ($this->connection === false) throw new MySquirrelException_TransactionError('Can\'t rollback: No transaction is currently in progress.');
        
        // No native support, so we just fire off a literal query.
        
        
        try
        {
            return $this->commonQuery('ROLLBACK');
        }
        catch (MySquirrelException $e)
        {
            throw new MySquirrelException_TransactionError('Can\'t rollback: ' . $e->getMessage());
        }
    }
    
    // Unmagic method.
    
    public function unmagic()
    {
        // If enabled, MySquirrel will automatically compensate for magic quotes.
        
        if (get_magic_quotes_gpc() || get_magic_quotes_runtime()) $this->unmagic = true;
    }
    
    // Sequence generation method for prepared statements.
    
    public static function nextSequence()
    {
        // PHP scripts are single-threaded, so this is good enough.
        
        static $val = 1;
        return $val++;
    }
}


/**
 * Prepared statement class.
 * 
 * This class is instantiated and returned when MySquirrel->prepare() is called.
 */

class MySquirrelPreparedStmt
{
    // Information about the current statement.
    
    protected $connection;
    protected $querystring;
    protected $statement;
    protected $numargs;
    protected $unmagic;
    
    // Constructor.
    
    public function __construct($connection, $querystring, $paranoid, $unmagic)
    {
        // Store the arguments in the instance.
        
        $this->connection = $connection;
        $this->querystring = $querystring;
        $this->unmagic = $unmagic;
        
        // Refuse to execute multiple statements at the same time.
        
        $querystring = trim($querystring, " \t\r\n;");
        if (strpos($querystring, ';') !== false)
        {
            throw new MySquirrelException_MultipleStatementsError('You cannot prepare multiple statements at once.');
        }
        
        // If in paranoid mode, refuse to execute querystrings with quotes in them.
        
        if ($paranoid && (strpos($querystring, '\'') !== false || strpos($querystring, '"') !== false || strpos($querystring, '--') !== false))
        {
            throw new MySquirrelException_ParanoidModeError('While in paranoid mode, you cannot use queries with quotes or comments in them.');
        }
        
        // Create a name for this prepared statement.
        
        $this->statement = 'mysquirrel' . MySquirrel::nextSequence();
        
        // Count the number of placeholders.
        
        $this->numargs = substr_count($querystring, '?');
        
        // Prepare the statement.
        
        $this->realQuery('PREPARE ' . $this->statement . ' FROM \'' . mysql_real_escape_string($this->querystring, $this->connection) . '\'');
    }
    
    // Execute method.
    
    public function execute( /* parameters */ )
    {
        // Get all parameters.
        
        $params = func_get_args();
        if (count($params) === 1 && is_array($params[0])) $params = $params[0];
        $count = count($params);
        
        if ($count !== $this->numargs)
        {
            throw new MySquirrelException_ParameterMismatchError('Prepared statement has ' . $this->numargs . ' placeholders, but ' . $count . ' parameters given.');
        }
        
        // Initialize the execute querystring.
        
        $querystring = 'EXECUTE ' . $this->statement;
        
        // Set server-side variables with properly escaped parameter values.
        
        for ($i = 0; $i < $count; $i++)
        {
            $param = $params[$i];
            if ($this->unmagic) $param = stripslashes($param);
            $param = "'" . mysql_real_escape_string($param, $this->connection) . "'";
            
            $varname = '@' . $this->statement . '_v' . $i;
            $this->realQuery('SET ' . $varname . ' = ' . $param);
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
        
        return $this->realQuery($querystring);
    }
    
    // Real query method.
    
    protected function realQuery($querystring)
    {
        // Query, and handle errors.
        
        $result = mysql_query($querystring, $this->connection);
        if ($error = mysql_errno($this->connection))
        {
            throw new MySquirrelException('Error ' . $error . ': ' . mysql_error($this->connection));
        }
        
        // Return the result.
        
        return (is_bool($result)) ? $result : new MySquirrelResult($result);
    }
    
    // Destructor.
    
    public function __destruct()
    {
        // Deallocate the statement.
        
        $this->realQuery('DEALLOCATE PREPARE ' . $this->statement);
    }
}


/**
 * Result class.
 * 
 * This class is instantiated and returned when a query has a result set.
 * This is usually the case with SELECT queries.
 */

class MySquirrelResult implements Iterator
{
    // Constructor.
    
    public function __construct($result)
    {
        $this->result = $result;
    }
    
    // Some protected properties.
    
    protected $result = null;
    protected $iter_count = false;
    protected $iter_index = false;
    
    // Iterator: Rewind.
    
    public function rewind()
    {
        // Reset the counter.
        
        $this->iter_count = $this->numRows();
        $this->iter_index = 0;
        
        // Seek to the top.
        
        if (mysql_num_rows($this->result) > 0) mysql_data_seek($this->result, 0);
    }
    
    // Iterator: Valid.
    
    public function valid()
    {
        return ($this->iter_index < $this->iter_count) ? true : false;
    }
    
    // Iterator: Current.
    
    public function current()
    {
        return $this->fetchAssoc();
    }
    
    // Iterator: Key.
    
    public function key()
    {
        return $this->iter_index - 1;
    }
    
    // Iterator: Next.
    
    public function next()
    {
        // iter_index is already incremented by fetchAssoc().
    }
    
    // Fetch method (generic).
    
    public function fetch()
    {
        $this->iter_index++;
        return mysql_fetch_array($this->result, MYSQL_BOTH);
    }
    
    // Fetch method (returns associated array).
    
    public function fetchAssoc()
    {
        $this->iter_index++;
        return mysql_fetch_assoc($this->result);
    }
    
    // Fetch method (returns object).
    
    public function fetchObject($class_name = false, $params = array())
    {
        $this->iter_index++;
        return $class_name ? mysql_fetch_object($this->result, $class_name, $params) : mysql_fetch_object($this->result);
    }
    
    // Fetch method (returns enumerated array).
    
    public function fetchRow()
    {
        $this->iter_index++;
        return mysql_fetch_row($this->result);
    }
    
    // Fetch-all method.
    
    public function fetchAll()
    {
        $return = array();
        $this->rewind();
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
 * MySquirrel exceptions.
 * 
 * No error passes silently. If your code is lousy, you'll see a lot of these.
 * It is your responsibility to handle these exceptions in an appropriate way.
 */

class MySquirrelException extends Exception { }
class MySquirrelException_ConnectionError extends MySquirrelException { }
class MySquirrelException_MultipleStatementsError extends MySquirrelException { }
class MySquirrelException_ParanoidModeError extends MySquirrelException { }
class MySquirrelException_ParameterMismatchError extends MySquirrelException { }
class MySquirrelException_TransactionError extends MySquirrelException { }
