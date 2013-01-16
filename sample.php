<?php
include "library.php";

$api = new OSM(XXX_API_KEY, XXX_API_SECRET);
if (!$api->isAuthorized()) {
	$api->authorize(XXX_EMAIL, XXX_PASSWORD);
}
var_dump($api->getTerms());