<?php
/**
 * Created by PhpStorm.
 * User: max
 * Date: 14/11/2016
 * Time: 20:33
 */

namespace Mindy\Bundle\MindyBundle\Dashboard;

interface WidgetInterface
{
    public function getTemplate();

    public function getData();
}