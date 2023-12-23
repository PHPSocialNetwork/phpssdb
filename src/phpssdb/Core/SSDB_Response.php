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
final class SSDB_Response
{
    public string $cmd;
    public string $code;
    public string $message;

    /**
     * @var mixed|null
     */
    public $data = null;

    /**
     * @param string $code
     * @param mixed $data_or_message
     */
    function __construct(string $code = 'ok', $data_or_message = null)
    {
        $this->code = $code;
        if ($code == 'ok') {
            $this->data = $data_or_message;
        } else {
            $this->message = $data_or_message;
        }
    }

    public function __toString()
    {
        if ($this->code == 'ok') {
            $s = $this->data === null ? '' : json_encode($this->data);
        } else {
            $s = $this->message;
        }
        return sprintf('%-13s %12s %s', $this->cmd, $this->code, $s);
    }

    public function ok(): bool
    {
        return $this->code === 'ok';
    }

    public function not_found(): bool
    {
        return $this->code === 'not_found';
    }
}
