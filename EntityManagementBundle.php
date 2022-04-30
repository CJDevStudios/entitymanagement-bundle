<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle;

use CJDevStudios\EntityManagementBundle\DependencyInjection\EntityManagementExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class EntityManagementBundle extends Bundle {

   public function getContainerExtension(): ?ExtensionInterface
   {
      return new EntityManagementExtension();
   }
}
