<?php

include('mysquirrel.php');

$mysql = MySquirrel::connect('localhost', 'user', 'pass', 'database');
$mysql->paranoid();

$mysql->query('INSERT INTO users (name, password, email) VALUES (?, ?, ?)', $name, $password, $email);
$id = $mysql->lastInsertID();

$mysql->query('UPDATE users SET email = ? WHERE id = ?', $new_email, $id);

$result = $mysql->query('SELECT * FROM users WHERE id = ?', $id);
while ($row = $result->fetch()) {
    echo $row['name'];
}
