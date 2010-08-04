<?php

// Just include the one file.

include('mysquirrel.php');

// Connect.

$mysql = new MySquirrel('localhost', 'user', 'pass', 'database');

// Activate paranoid mode.

$mysql->paranoid();

// This is how you query the database.

$result = $mysql->query('SELECT * FROM users WHERE id = ?', $id);
while ($row = $result->fetch()){
    echo $row['name'];
}

// Additional parameters are passed as additional arguments to query();

$mysql->query('UPDATE users SET email = ? WHERE id = ?', $new_email, $id);

// Insert and get the autoincrement ID.

$mysql->query('INSERT INTO users (name, password, email) VALUES (?, ?, ?)', $name, $password, $email);
$id = $mysql->lastInsertID();

// Use prepared statements for extra security and performance gains.

$stmt = $mysql->prepare('INSERT INTO users (name, password, email) VALUES (?, ?, ?)');
$stmt->execute($name, $password, $email);
$stmt->execute($name, $password, $email);
$stmt->execute($name, $password, $email);
