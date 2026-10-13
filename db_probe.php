<?php
require_once __DIR__ . '/config/app.php';
$r = $conn->query('SELECT id, restaurant_name, city, is_active FROM halal_restaurants ORDER BY id');
echo '<pre>';
while($row = $r->fetch_assoc()) {
    echo "id={$row['id']} | active={$row['is_active']} | city=\"{$row['city']}\" | {$row['restaurant_name']}\n";
}
echo "\nTotal: " . $conn->query('SELECT COUNT(*) c FROM halal_restaurants')->fetch_assoc()['c'] . " restaurants\n";
echo "Active: " . $conn->query('SELECT COUNT(*) c FROM halal_restaurants WHERE is_active=1')->fetch_assoc()['c'] . "\n";
echo "Inactive: " . $conn->query('SELECT COUNT(*) c FROM halal_restaurants WHERE is_active=0')->fetch_assoc()['c'] . "\n";
echo '</pre>';
