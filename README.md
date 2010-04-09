Introduction
============

MySquirrel is a lightweight object-oriented wrapper around the MySQL and MySQLi extensions of PHP 5.
The focus is on simplicity and ease of use, robust error handling, and most of all, security.

MySquirrel is designed with the **small-time web developer** in mind,
whose projects often end up in the unpredictable and inconsistent world of **shared hosting**.
MySquirrel does not require advanced database extensions such as PDO,
while bringing to you some of the major benefits of those newer interfaces.
The only requirements are PHP 5.1+ and a reasonably up-to-date version of MySQL.

MySquirrel makes it super easy to run **parametrized queries** and **prepared statements**,
while requiring nothing more than good old `mysql_*` functions.
(If MySQLi is available, MySquirrel will prefer it, without any change to the API.)
MySquirrel can also be used in **paranoid mode**, which enables additional security measures.
All variables marked with `?` and passed as separate parameters are automatically escaped.
No more tedious escaping, no more SQL injection vulnerabilities.

MySquirrel is released under the [GNU General Public License, version 3](http://www.gnu.org/licenses/gpl.html).

### What's wrong with `mysql_query()` ?

Good old `mysql_*` functions are among the most commonly used in PHP web development,
but they are rather difficult to protect against [SQL injection](http://en.wikipedia.org/wiki/Sql_injection) attacks.
Injection-proofing often involves tedious escaping, and PHP supplies no less than three functions
with convoluted names for this purpose: `stripslashes` (deprecated), `mysql_escape_string` (also deprecated),
and `mysql_real_escape_string` ([recommended](http://ca2.php.net/manual/en/function.mysql-real-escape-string.php)).
You are supposed to apply this function to each and every variable that you use in your query,
all the while accounting for the quirks introduced by abominations such as magic quotes.
However, a single unescaped parameter is all it takes for a remote attacker to pwn your database:
`' OR 1 = 1; DELETE FROM table; --`.
No wonder everyone wants you to stay far away from `mysql_*` functions.

More modern extensions, such as [PDO](http://ca.php.net/manual/en/book.pdo.php) and [MySQLi](http://ca.php.net/manual/en/book.mysqli.php),
enable prepared statements and/or parametrized queries that can greatly reduce injection vulnerabilities.
But they are not always available with shared hosting where PHP programs are most often deployed,
not to mention that the powerful API (especially MySQLi) makes common tasks much more complicated than usual.
Try to run a parametrized query using MySQLi with half a dozen bound variables!
It is just as tedious, if not more, to use MySQLi properly as it is to use MySQL properly.
As a result, PHP developers often hang on to the nearly deprecated `mysql_*` functions.

MySquirrel comes to the rescue. Just see how easy it is, below:

### Quick start guide

Just include one file.

    include('mysquirrel.php');

Instead of `mysql_connect()`, call `MySquirrel::connect()` and use the returned object.

    $mysql = MySquirrel::connect('localhost', 'user', 'pass', 'database');
    $mysql->paranoid();

Mark variables with a placeholder, and supply the values separately.

    $result = $mysql->query('SELECT * FROM users WHERE id = ?', $id);
    while ($row = $result->fetch()) {
        echo $row['name'];
    }

The result class implements the Iterator interface, so you can also do this.

    $result = $mysql->query('SELECT * FROM users WHERE id = ?', $id);
    foreach ($result as $row) {
        echo $row['name'];
    }

Supply any number of additional variables as extra arguments.

    $mysql->query('UPDATE users SET email = ? WHERE id = ?', $new_email, $id);
    $ar = $mysql->affectedRows();

Alternatively, you can pass an array of parameters. This can be useful sometimes.

    $params = array($name, $password, $email);
    $mysql->query('INSERT INTO users (name, password, email) VALUES (?, ?, ?)', $params);
    $id = $mysql->lastInsertID();

Use prepared statements to speed up identical queries.

    $stmt = $mysql->prepare('INSERT INTO users (name, password, email) VALUES (?, ?, ?)');
    $stmt->execute($name1, $password1, $email1);
    $stmt->execute($name2, $password2, $email2);
    $stmt->execute($name3, $password3, $email3);


Reference Guide
===============

The static method `MySquirrel::connect()` returns a `MySquirrelConnection_*` object,
on which methods such as `query()` can be called.
(The type of this object depends on whether or not MySQLi is available, but the API is the same.)
Likewise, a query that has a result set (e.g. `SELECT`) returns a `MySquirrelResult_*` object,
on which methods such as `fetch()` can be called.

Errors will not pass unnoticed as they frequently do in legacy applications;
rather, any error condition reported by the server will result in a `MySquirrelException` being thrown.

### MySquirrel class

  * connect($host, $user, $pass, $database, [$charset] )

### MySquirrelConnection class

These methods should be called on the return value of `MySquirrel::connect()`.

  * paranoid() _Activates paranoid mode (see below)_
  * prepare($querystring)
  * query($querystring, [$param1, $param2 ... ] )
  * rawQuery($querystring)
  * affectedRows()
  * lastInsertID()
  * beginTransaction()  _Only with InnoDB_
  * commit()  _Only with InnoDB_
  * rollback()  _Only with InnoDB_
  * unmagic() _If enabled, automatically compensate for evil magic quotes_

### MySquirrelPreparedStmt class

These methods should be called on the return value of `prepare()`.

  * execute( [$param1, $param2 ... ] )

### MySquirrelResult class

These methods should be called on the return value of `query()`, `rawQuery()`, or `execute()`.
    
  * fetch() _Returns both types of arrays_
  * fetchAssoc() _Returns an associative array_
  * fetchObject($class, $params) _Returns an object_
  * fetchRow() _Returns an enumerated array_
  * fetchAll() _Returns every row in the result set_
  * fieldInfo($offset) _Returns information about a column_
  * numFields()
  * numRows()


Notes on Best Practice
======================

Never, ever put variables into the querystring, e.g. `WHERE id = $id AND name = '$name'`.
Script kiddies and other criminals love you for mixing variables with SQL!
Instead, put a question mark (`?`) where you would normally put a variable.
*Do not put quotes around it.* Just pretend that the question mark is part of the SQL syntax.
Then supply the variable itself as an additional argument when you call `query()`, as in the examples above.

You can supply as many variables as you want in this way,
but *the number of additional arguments must match the number of placeholders*.
The same rule applies when you pass variables as a single array.
MySquirrel will automatically escape all parameters supplied using this syntax;
there is no need to pre-escape them in any way.

### One Statement at a Time

Notice that `query()` will not accept querystrings that contain multiple SQL statements.
Only one statement is permitted per method call.
This helps prevent malicious folks from attaching `DELETE` or similar to your querystring.

A somewhat inconvenient side effect of this rule is that *you cannot run queries that contain semicolons*.
You cannot even have semicolons inside string literals.
String literals containing semicolons should be passed as separate parameters instead.
In practice, most string literals come from insecure sources,
so it is often a good idea to pass them as separate parameters anyway.
Paranoid mode, explained below, actually makes this practice mandatory.

Another side effect is that *you cannot run multiple statements at the same time*.
Nor is it possible to run statements that contain other statements, such as
many forms of `CREATE PROCEDURE`. If you need to execute several statements at the same time,
or if you need to run statements that contain other statements, use `rawQuery()` instead.
This might be useful, for example, when creating the tables and indexes for the first time in an install script.
Note, however, that `rawQuery()` gives you dangerous power unless you are absolutely sure what you're doing.
In paranoid mode, `rawQuery()` cannot be used.

### Prepared Statements

Prepared queries are executed in two steps.
First, you call the `prepare()` method on the connection object to create a prepared statement on the server.
(This requires MySQL 4.1 or higher.)
If the querystring is syntactically correct, the method will return an object
on which you can call `execute()` as many times as you want -- with or without parameters.
This improves performance when running the same query over and over again.

### Paranoid Mode

Paranoid mode, which can be activated by calling `paranoid()` on the connection object,
makes two changes to MySquirrel's behavior:

  * It disables `rawQuery()`.
  * It makes `query()` reject querystrings that contain quotes, comments, or null bytes.

Note that these restrictions will also prevent you from using any string literal at all,
such as `SELECT * FROM tickets WHERE status = 'pending' ...` among others.
Although string literals are not necessarily insecure, in practice,
a lot of these originate from untrusted sources and result in vulnerabilities.
Likewise, comments and null bytes in short queries are a common sign of injection attacks.

Paranoid mode is not perfectly secure, and cannot be, since it uses blacklisting instead of whitelisting.
This shortcoming is by design; the purpose is to disable only the most obvious attack vectors.
The additional security offered by paranoid mode should **not** be relied upon as the sole barrier against injection attacks.

For compatibility reasons, paranoid mode is **not** activated by default.
If you want additional security and you don't care about the minor limitations posed by it,
call `paranoid()` immediately after obtaining the connection object.

### Undoing Magic Quotes

If your server has magic quotes enabled (*which is not a good idea, but sometimes you can't help*),
and if most of the parameters you're going to use come from potentially insecure sources (GET, POST, etc),
you can call `unmagic()` to tell MySquirrel to compensate for magic quotes
and prevent extraneous backslashes from being inserted.
Compensating for magic quotes has no implications for security whatsoever,
but incorrect use may cause some strings to be inserted incorrectly.

### Error Handling

Unlike `mysql_*` functions, MySquirrel will not allow errors to pass silently.
Nor will it follow the dickheaded practice of just `die()`ing on any database error.
*If any error occurs, `MySquirrelException` will be thrown*.
It is your responsibility to catch and handle this exception properly.
Often, `mysql_*` doesn't even issue a warning when you try to execute SQL statements with syntax erorrs!
This recklessness must come to an end, and MySquirrel goes to great lengths to remedy the situation where possible.

### Connection Caching

MySquirrel does not support persistent connections, but it does cache connection objects while a script is running.
If you lose reference to the object you were working with (e.g. you move to a different scope),
just call `MySquirrel::connect()` again with the same parameters to retrieve it.
Paranoid mode, if previously activated, will remain activated.

One limitation of this functionality is that you can't open two connections
to the same database using the same username/password combination.
However, web applications very rarely do this anyway, so you most likely won't have to worry about this.

