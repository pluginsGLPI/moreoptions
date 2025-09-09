<?php

use GlpiPlugin\Moreoptions\Config;

Session::checkLoginUser();

$config = new Config();

if (isset($_POST["update"])) {
    $config->check($_POST['id'], UPDATE);
    $config->update($_POST);
}
Html::back();