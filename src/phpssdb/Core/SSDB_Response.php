<?php
/**
 *
 * This file is part of Phpfastcache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt and LICENCE files.
 *
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 * @author Contributors  https://github.com/PHPSocialNetwork/phpfastcache/graphs/contributors
 */

namespace phpssdb\Core;


/**
 * Class SSDB_Response
 * @package phpssdb\Core
 */
class SSDB_Response
{
    public $cmd;
    public $code;
    public $data = null;
    public $message;

    function __construct($code='ok', $data_or_message=null){
        $this->code = $code;
        if($code == 'ok'){
            $this->data = $data_or_message;
        }else{
            $this->message = $data_or_message;
        }
    }

    function __toString(){
        if($this->code == 'ok'){
            $s = $this->data === null? '' : json_encode($this->data);
        }else{
            $s = $this->message;
        }
        return sprintf('%-13s %12s %s', $this->cmd, $this->code, $s);
    }

    function ok(){
        return $this->code == 'ok';
    }

    function not_found(){
        return $this->code == 'not_found';
    }
}
