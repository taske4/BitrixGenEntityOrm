<?php

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

$gen = new \Zapovednik\GenOrm('b_sale_location');
$gen->gen();