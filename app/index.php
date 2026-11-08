<?php
header('Cache-Control: no-store');
$query=[];
foreach(['company','month'] as $key)if(is_string($_GET[$key]??null))$query[$key]=$_GET[$key];
header('Location: /index.html'.($query?'?'.http_build_query($query):''),true,303);
exit;
