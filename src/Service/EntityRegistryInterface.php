<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle\Service;

use CJDevStudios\EntityManagementBundle\Exception\UnknownEntityIdentifierException;
use CJDevStudios\EntityManagementBundle\Exception\UnknownModuleIdentifierException;
use InvalidArgumentException;
use ReflectionException;

/**
 * Entity registry interface.
 * This tracks metadata for entity types along with their modules.
 * This registry also handles entity rights management. It will get the base supported rights and add additional ones as needed based on traits or other factors.
 * For example, any entity with the Trashable trait will get the 'delete' right in addition to the default 'purge' right.
 * @since 1.0.0
 */
interface EntityRegistryInterface {

   public function isModuleRegistered(string $module): bool;

   public function isEntityIdentifierRegistered(string $identifier): bool;

   public function isEntityClassRegistered(string $entity): bool;

   /**
    * Manually registers an entity class and adds it to the manifest.
    * Under normal circumstances, there is no need to call this since all entities should be registered automatically.
    * For entities that live in the "tests" directory, you will have to register them manually during the test.
    *
    * It is not required for implementations of this registry to check that the entity classes provided are actually entities..
    * You should check this yourself and not expect the registry to check it.
    *
    * @since 1.0.0
    * @param array|string $entities
    * @throws InvalidArgumentException
    * @throws ReflectionException
    */
   public function registerEntity(array|string $entities): void;

   /**
    * Manually registers an module class and adds it to the manifest.
    * Under normal circumstances, there is no need to call this since all modules should be registered automatically.
    * For modules that live in the "tests" directory, you will have to register them manually during the test.
    *
    * It is not required for implementations of this registry to check that the module classes provided are actually modules.
    * You should check this yourself and not expect the registry to check it.
    *
    * @since 1.0.0
    * @param array|string $modules
    * @throws InvalidArgumentException
    * @throws ReflectionException
    */
   public function registerModule(array|string $modules): void;

   /**
    * Get the short, unique identifier for the given entity class.
    *
    * @since 1.0.0
    * @param string $entity_class The fully-qualified (with namespace) name of the entity class.
    * @return string
    */
   public function getEntityIdentifier(string $entity_class): string;

   /**
    * Get the current representation of the given entity class.
    * If the class is overridden by a plugin, that class is returned instead.
    * Typically this will get the identifier for the given class, and then search it in the manifest to see what class is currently registered with that identifier.
    *
    * @since 1.0.0
    * @param string $entity_class The fully-qualified (with namespace) name of the entity class.
    * @return string
    */
   public function getRuntimeEntityClass(string $entity_class): string;

   /**
    * Get the short, unique identifier for the given module class.
    *
    * @since 1.0.0
    * @param string $module_class The fully-qualified (with namespace) name of the module class.
    * @return string
    */
   public function getModuleIdentifier(string $module_class): string;

   /**
    * Get the fully-qualified entity name for the given identifier.
    *
    * @since 1.0.0
    * @param string $identifier The short entity identifier.
    * @return string
    * @throws UnknownEntityIdentifierException
    */
   public function getEntityFromIdentifier(string $identifier): string;

   /**
    * Get the fully-qualified module name for the given identifier.
    *
    * @since 1.0.0
    * @param string $identifier The short module identifier.
    * @return string
    * @throws UnknownModuleIdentifierException
    */
   public function getModuleFromIdentifier(string $identifier): string;

   /**
    * Get the module of the entity for the given identifier.
    *
    * @since 1.0.0
    * @param string $identifier
    * @return string
    * @throws UnknownEntityIdentifierException
    * @throws UnknownModuleIdentifierException
    */
   public function getEntityModule(string $identifier): string;

   /**
    * Get the owner of the entity for the given identifier.
    *
    * @since 1.0.0
    * @param string $identifier The short entity identifier.
    * @return string
    * @throws UnknownEntityIdentifierException
    */
   public function getEntityOwner(string $identifier): string;

   /**
    * Get the owner of the module for the given identifier.
    *
    * @since 1.0.0
    * @param string $identifier The short module identifier.
    * @return string
    * @throws UnknownModuleIdentifierException
    */
   public function getModuleOwner(string $identifier): string;

   /**
    * Get the valid right attributes for the given entity by identifier.
    * Example attributes include 'view', 'edit', 'create', etc.
    *
    * @since 1.0.0
    * @param string $identifier The short entity identifier.
    * @return array
    * @throws UnknownEntityIdentifierException
    */
   public function getEntityRights(string $identifier): array;

   /**
    * Get the valid right attributes for the given module by identifier.
    * Example attributes include 'view', 'edit', 'create', etc.
    *
    * @since 1.0.0
    * @param string $identifier The short module identifier.
    * @return array
    * @throws UnknownModuleIdentifierException
    */
   public function getModuleRights(string $identifier): array;

   /**
    * Get all traits for the given entity by identifier.
    *
    * @since 1.0.0
    * @param string $identifier The short entity identifier.
    * @return array
    * @throws UnknownEntityIdentifierException
    */
   public function getEntityTraits(string $identifier): array;

   public function getIsEntityRelation(string $identifier): bool;

   /**
    * Clear any cached information about entities and modules (OIMC cached data only. Not Doctrine).
    *
    * @since 1.0.0
    * @return bool False if an error occurs. Otherwise, true.
    */
   public function clear(): bool;

   /**
    * Get an array of all known modules and entities. The array returned may or may not be the same as how the manifest is stored.
    * The resulting array will be in the following format:
    * [
    *     'modules' => [
    *         'Administration' => [
    *             'class'  => 'OIMC\Modules\Administration',
    *             'entities' => ['AdministrationConfig', 'AdministrationDepartments']
    *         ]
    *     ],
    *     'entities' => [
    *         'AdministrationConfig' => 'OIMC\Entity\Administration\Config'
    *     ]
    * ]
    *
    * The modules key contains an associative array with the module identifiers as the key.
    * The class sub-key for each module contains the class name for the module.
    * The entities sub-key for each module contains a list of identifiers for each entity in that module.
    * The entities key of the result contains pairs of entity identifiers and their class names.
    *
    * This manifest can be used to enumerate the known modules and entities or as a lookup table for converting between class names and identifiers.
    *
    * @since 1.0.0
    * @return array
    * @see EntityRegistryInterface::getModuleFromIdentifier()
    * @see EntityRegistryInterface::getModuleIdentifier()
    * @see EntityRegistryInterface::getEntityFromIdentifier()
    * @see EntityRegistryInterface::getEntityIdentifier()
    */
   public function getManifest(): array;

   /**
    * Get all entities by their identifier owned by the specified owner.
    *
    * @since 1.0.0
    * @param string $owner
    * @return array
    */
   public function getEntitiesByOwner(string $owner): array;

   /**
    * Get all entities by their identifier owned by the specified owner.
    *
    * @since 1.0.0
    * @param string $owner
    * @return array
    */
   public function getModulesByOwner(string $owner): array;

   /**
    * Get all entities by their identifier contained in the specified module.
    *
    * @since 1.0.0
    * @param string $module
    * @return array
    */
   public function getEntitiesByModule(string $module): array;

   /**
    * Get an array of all valid rights.
    * The result is an associative array in the following format:
    *
    * [Administration => [
    *     'module_rights' => ['view', 'edit', 'create'],
    *     [AdministrationConfig => ['view', 'edit', 'create']]
    * ]]
    *
    * The rights in the 'module_rights' key are a combined list of all rights each entity in that module.
    * This makes it easier and less resource-intensive to display headings in the rights form among other situations
    * so you don't have to parse the rights of every entity in the module manually.
    * @return array
    */
   public function getAllRights(): array;

   /**
    * Get the name of the settings group this entity should reside in if any.
    * If this is not a settings entity, null will be returned.
    *
    * @param string $identifier The short entity identifier.
    * @return string|null
    */
   public function getEntitySettingsGroup(string $identifier): ?string;

   /**
    * @since 1.0.0
    * @param string $identifier
    * @return string
    */
   public function getDisplayName(string $identifier): string;

   /**
    * @since 1.0.0
    * @param string $identifier
    * @return array
    */
   public function getMappedSuperclassesForEntity(string $identifier): array;

   /**
    * @since 1.0.0
    * @param string $entity_class
    * @return array
    */
   public function getChildEntitiesForMappedSuperclass(string $entity_class): array;

   /**
    * @since 1.0.0
    * @param string $identifier
    * @return array
    */
   public function getMetadata(string $identifier): array;

   /**
    * Get an array of fields with relevant metadata about their behaviors in forms and search results.
    * This is an expensive process for information that is not expected to change at runtime, so the results are cached for some time.
    * @param string $identifier
    * @return array
    */
   public function getFields(string $identifier): array;
}
