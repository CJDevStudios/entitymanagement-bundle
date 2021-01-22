<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle\Exception;

use Exception;

/**
 *
 * @since 1.0.0
 */
class UnknownEntityIdentifierException extends Exception {

    public function __construct($identifier)
    {
        parent::__construct('The specified entity identifier "' . $identifier . '" does not match any known entities');
    }
}
