<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle\Util;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Mapping\MappedSuperclass;

class EntityUtil {

   /**
    * Checks if the given class has the specified trait.
    * This function checks the class itself and all parent classes for the trait.
    * @since 1.0.0
    * @param string $class The class
    * @param string $trait The trait
    * @return bool True if the class or its parents have the specified trait
    */
   public static function hasTrait(string $class, string $trait): bool
   {
      // Get traits of all parent classes
      do {
         $traits = class_uses($class, true);
         if (in_array($trait, $traits, true)) {
            return true;
         }
      } while ($class = get_parent_class($class));

      return false;
   }

   public static function getAllEntities(): array
   {
      /** @global $KERNEL Kernel */
      global $KERNEL;

      $entityRegistry = $KERNEL->getContainer()->get('oimc.entityregistry');
      $translator = $KERNEL->getContainer()->get('translator');
      $manifest = $entityRegistry->getManifest()['entities'];
      $results = [];

      foreach ($manifest as $entity => $entity_class) {
         $display_name = $translator->trans($entityRegistry->getDisplayName($entity), ['count' => 1], 'oimc');
         $results[$display_name] = $entity;
      }

      return $results;
   }

   /**
    * Check if the given entity is a mapped superclass.
    * @since 1.0.0
    * @param string $entity
    * @return bool
    * @throws \ReflectionException
    */
   public static function isSuperclassEntity(string $entity): bool
   {
      $reader = new AnnotationReader();
      $rc = new \ReflectionClass($entity);
      return $reader->getClassAnnotation($rc, MappedSuperclass::class) !== null;
   }

   public static function normalizeEntityClass($entity): string
   {
      if (is_object($entity)) {
         $entity = get_class($entity);
      }

      return ClassUtils::getRealClass($entity);
   }
}
