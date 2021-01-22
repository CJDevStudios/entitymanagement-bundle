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

use Doctrine\Common\Annotations\Annotation\Target;

/**
 * @since 1.0.0
 * @Annotation
 * @Target({"PROPERTY"})
 */
class VirtualColumn {

    /**
     * Type of the virtual column
     * @since 1.0.0
     * @var string
     */
    public string $type = 'ArrayCollection';

    /**
     * Extra options for the virtual column
     * @since 1.0.0
     * @var array
     */
    public array $options = [];
}
