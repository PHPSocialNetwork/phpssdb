<?php
namespace phpssdb\Core;
/**
 * Copyright (c) 2012, ideawu
 * All rights reserved.
 * @author: ideawu
 * @link: http://www.ideawu.com/
 *
 * SSDB PHP client SDK.
 */


/**
 * All methods(except *exists) returns false on error,
 * so one should use Identical(if($ret === false)) to test the return value.
 */
class SimpleSSDB extends SSDB
{
    public function __construct($host, $port, $timeout_ms=2000){
        parent::__construct($host, $port, $timeout_ms);
        $this->easy();
    }
}