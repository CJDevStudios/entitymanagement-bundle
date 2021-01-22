<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle\ORM\Annotation;

use Doctrine\Common\Annotations\Annotation\Required;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * @since 1.0.0
 * @Annotation
 * @Target({"PROPERTY"})
 */
class Dropdown {

    /**
     * Data provider class for the dropdown.
     * @since 1.0.0
     * @var string
     * @Required
     */
    public string $provider_class;

    /**
     * Data provider function for the dropdown.
     * @since 1.0.0
     * @var string
     * @Required
     */
    public string $provider_func;
}
