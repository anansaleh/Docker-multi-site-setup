<?php
echo "<h1>site1.test — PHP via FPM</h1>";
echo "<p>PHP version: " . phpversion() . "</p>";
echo "<p>Server software: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p>Served from: " . __FILE__ . "</p>";
