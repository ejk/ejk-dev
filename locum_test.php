<pre>
<?php
$time_start = microtime(true);
////////////////////////////////////////////////////////////////////////////////
require_once('/usr/local/lib/locum/locum-client.php');
$l = new locum_client;
print_r($l->search('keyword', 'dogs', 10, 0));
////////////////////////////////////////////////////////////////////////////////
$time = microtime(true) - $time_start;
echo "<p>Execution time: $time seconds</p>";
?>
</pre>