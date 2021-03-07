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

use CJDevStudios\DevBundle\Service\Stopwatch;
use CJDevStudios\EntityManagementBundle\Entity\AbstractEntity;
use CJDevStudios\EntityManagementBundle\Exception\UnknownEntityIdentifierException;
use CJDevStudios\EntityManagementBundle\Exception\UnknownModuleIdentifierException;
use CJDevStudios\EntityManagementBundle\ORM\Annotation\Dropdown;
use CJDevStudios\EntityManagementBundle\ORM\Annotation\FieldOptions;
use CJDevStudios\EntityManagementBundle\ORM\Annotation\VirtualColumn;
use CJDevStudios\EntityManagementBundle\Util\EntityUtil;
use Composer\Autoload\ClassLoader;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Basic implementation of an {@link EntityRegistryInterface Entity Registry}.
 *
 * @since 1.0.0
 */
class EntityRegistry implements EntityRegistryInterface {

    /** @var CacheItemPoolInterface */
    private $cache;

    /**
     *
     * @since 1.0.0
     * @var Stopwatch
     */
    private $stopwatch;

    /**
     * @since 1.0.0
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * EntityRegistry constructor.
     *
     * @since 1.0.0
     * @param CacheItemPoolInterface $entityRegistryPool Autowired cache pool. See {@see ConfigInterface/packages/cache.yaml}.
     * @param Stopwatch $stopwatch
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(CacheItemPoolInterface $entityRegistryPool, Stopwatch $stopwatch, EventDispatcherInterface $eventDispatcher)
    {
        $this->cache = $entityRegistryPool;
        $this->stopwatch = $stopwatch;
        $this->eventDispatcher = $eventDispatcher;

        // Trigger rebuild if needed
        $this->getManifest();
    }

    /**
     * Find all entities, then generate and cache a manifest of them.
     * The manifest is an array in the format [identifier => class].
     */
    private function buildManifest(): void
    {
        /** @global ClassLoader $AUTOLOADER */
        global $AUTOLOADER;

        $this->stopwatch->start('buildEntityManifest', 'app');

        if ($AUTOLOADER === null) {
            // Console env?
            $classes = (require dirname(__DIR__) . '/../vendor/autoload.php')->getClassMap();
        } else {
            $classes = $AUTOLOADER->getClassMap();
        }

        $toregister = [];

        $valid_paths = ['../../src', '../../plugins'];
        foreach ($classes as $class => $path) {
            foreach ($valid_paths as $valid_path) {
                if (strpos($path, $valid_path)) {
                    $toregister[] = $class;
                    break;
                }
            }
        }
        $this->tryRegisterClasses($toregister);

        $this->stopwatch->stop('buildEntityManifest');
    }

    private function tryRegisterClasses(array $classes): void
    {
        $modules = [];
        $entities = [];

        foreach ($classes as $class) {
            // Normalize the class name so proxy classes don't ruin things for us
            $class_normalized = str_replace('Proxies\\__CG__\\', '', $class);
            // Determine if this is a module, entity, or neither
            $class_parts = explode('\\', $class_normalized);
            if ($class_parts !== false && (str_starts_with($class_parts[0], 'OIMC') || (str_starts_with($class_parts[0], 'OIMCPlugins')))) {
                if (is_subclass_of($class, AbstractEntity::class)) {
                    $entities[] = $class_normalized;
                } else if (is_subclass_of($class, AbstractOIMCModule::class)) {
                    $modules[] = $class_normalized;
                }
            }
        }

        // Cache modules first so that we can add the entities to the correct modules when they are being registered.
        $this->registerModule($modules);
        $this->registerEntity($entities);
    }

    public function isModuleRegistered($module): bool
    {
        return isset(array_flip($this->getManifest()['modules'])[$module]);
    }

    public function isEntityClassRegistered($entity): bool
    {
        return isset(array_flip($this->getManifest()['entities'])[$entity]);
    }

    public function isEntityIdentifierRegistered($identifier): bool
    {
        return isset($this->getManifest()['entities'][$identifier]);
    }

    /**
     * @since 1.0.0
     * @param $entity_class
     * @return array
     */
    private function getMappedSuperclassParents($entity_class): array
    {
        $rc = new ReflectionClass($entity_class);
        $reader = new AnnotationReader();
        $superclasses = [];

        $parent = $rc->getParentClass();
        while ($parent && $parent->getName() !== AbstractOIMCEntity::class) {
            $superclass = $reader->getClassAnnotation($parent, MappedSuperclass::class);
            if ($superclass !== null) {
                $superclasses[] = $parent->getName();
            }
            $parent = $parent->getParentClass();
        }

        return $superclasses;
    }

    /**
     * {@inheritDoc}
     */
    public function registerEntity($entities): void
    {
        if (!is_array($entities)) {
            $entities = [$entities];
        }

        $manifest_item = $this->cache->getItem('manifest');
        $rights_manifest_item = $this->cache->getItem('rights_manifest');
        $manifest = $manifest_item->get() ?? [];
        $rights_manifest = $rights_manifest_item->get() ?? [];
        //TODO Replace with bundle config value
        $allow_override = true;

        foreach ($entities as $entity) {
            $class_parts = explode('\\', $entity);
            if ($class_parts === false || count($class_parts) < 4 || (new ReflectionClass($entity))->isAbstract()) {
                continue;
            }
            $identifier = $this->getEntityIdentifier($entity);
            $manifest['entities'][$identifier] = $entity;

            /** @var AbstractOIMCEntity $entity */
            $rc = new ReflectionClass($entity);

            if ($class_parts[0] === 'OIMC') {
                // OIMC\Entity\MODULE\ENTITY
                [0 => $owner, 2 => $module] = $class_parts;
            } else if ($class_parts[0] === 'OIMCPlugins') {
                // OIMCPlugins\COMPANY\PLUGIN\Entity\MODULE\ENTITY
                $owner = $class_parts[1] . '.' . $class_parts[2];
                [4 => $module] = $class_parts;
            } else {
                // Invalid namespace
                continue;
            }

            if (!array_key_exists($module, $manifest['modules'])) {
                // Some entity is trying to register itself in a module that doesn't exist
                continue;
            }

            // If this is a plugin entity that would override one already registered by the core
            if ($owner !== 'OIMC' && ($this->isEntityIdentifierRegistered($identifier) && $this->getEntityOwner($identifier) === 'OIMC')) {
                if (!$allow_override) {
                    // Plugins are not allowed to override core entities based on the config. Skip this entity.
                    continue;
                }
            }

            $traits = $rc->getTraitNames();
            $parent = $rc->getParentClass();

            while ($parent !== false) {
                $traits = array_merge($traits, $parent->getTraitNames());
                $parent = $parent->getParentClass();
            }

            $rights = $entity::getSupportedBasicRights();

            if (in_array(Trashable::class, $traits, true)) {
                $rights[] = AbstractOIMCVoter::DELETE;
            }

            $item = $this->cache->getItem($identifier);
            $item_data = [
                'class'             => $entity,
                'owner'             => $owner,
                'module'            => $module,
                'form_template'     => $this->determineFormTemplate($owner, $identifier),
                'traits'            => $traits,
                'rights'            => $entity::RIGHTS_INHERITED_FROM === null ? $rights : [],
                'is_relation'       => $rc->isSubclassOf(AbstractOIMCEntityRelation::class),
                'settings_group'    => $entity::SETTINGS_GROUP,
                'display_name'      => 'entity.' . strtolower($identifier) . '.name',
                'superclasses'      => $this->getMappedSuperclassParents($entity),
            ];
            if ($owner !== 'OIMC') {
                $item_data['display_name'] = strtolower('entity.' . $identifier . '.name');//strtolower('plugin.'.$owner.'.entity.' . $identifier . '.name');
            }
            $item->set($item_data);

            $this->cache->save($item);

            // Add this entity to the module sub-key entities
            $module_data = &$manifest['modules'][$module];
            $module_data['entities'][] = $identifier;
            if ($entity::RIGHTS_INHERITED_FROM === null) {
                $module_data['rights'] = array_unique(array_merge($module_data['rights'], $rights));
                $rights_manifest[$module]['rights'] = $module_data['rights'];
                $rights_manifest[$module]['entities'][$identifier] = $rights;
            }
        }

//        foreach ($entities as $entity) {
//            $identifier = $this->getEntityIdentifier($entity);
//            $item = $this->cache->getItem($identifier);
//            $item_props = $item->get();
//            $item_props['fields'] = $entity::getFields();
//            $item->set($item_props);
//            $this->cache->save($item);
//        }
        $manifest_item->set($manifest);
        $rights_manifest_item->set($rights_manifest);
        $this->cache->save($manifest_item);
        $this->cache->save($rights_manifest_item);
    }

    public function registerModule($modules): void
    {
        if (!is_array($modules)) {
            $modules = [$modules];
        }

        $manifest_item = $this->cache->getItem('manifest');
        $rights_manifest_item = $this->cache->getItem('rights_manifest');
        $manifest = $manifest_item->get() ?? [];
        $rights_manifest = $rights_manifest_item->get() ?? [];

        foreach ($modules as $module) {
            $class_parts = explode('\\', $module);
            if (count($class_parts) < 3) {
                continue;
            }
            if ((new ReflectionClass($module))->isAbstract()) {
                continue;
            }
            $identifier = $this->getModuleIdentifier($module);
            $manifest['modules'][$identifier] = [
                'class'    => $module,
                'entities' => [],
                'rights'   => [],
            ];

            $rights_manifest[$identifier] = [
                'rights'   => [],
                'entities' => [],
            ];

            [0 => $owner] = $class_parts;

            $item = $this->cache->getItem($identifier);
            $item->set([
                'display_name' => 'module.' . strtolower($identifier) . '.name',
                'class'        => $module,
                'owner'        => $owner,
            ]);
            $this->cache->save($item);
        }
        $manifest_item->set($manifest);
        $rights_manifest_item->set($rights_manifest);
        $this->cache->save($manifest_item);
        $this->cache->save($rights_manifest_item);
    }

    /**
     * Automatically determine which Twig template is used for the view/edit form for a given entity.
     *
     * @param string $owner The entity owner. This is the first part of the entity's namespace or if it is a plugin, just the short plugin identifier (plugin directory name).
     *  If you only have the entity identifier, you can use {@link EntityRegistry::getOwner()} to find the owner.
     * @param string $entity_identifier The identifier assigned to an entity class by this registry.
     *  If you only have a full class name, you can use {@link EntityRegistry::getEntityIdentifier()} to lookup its identifier.
     * @return string The path to the template that should be used.
     * @since 1.0.0
     */
    protected function determineFormTemplate(string $owner, $entity_identifier): string
    {
        $template = null;

        if ($owner === 'OIMC') {
            $template_dir = '../templates';
        } else {
            $plugin_id = strtolower(preg_replace('/^Plugin/', '', $owner));
            $template_dir = "../plugins/{$plugin_id}/templates";
        }

        $override_path = "{$template_dir}/entity_forms/{$entity_identifier}.html.twig";

        if (file_exists($override_path)) {
            $template = "entity_forms/{$entity_identifier}.html.twig";
        }

        //TODO Support matching templates by parent classes? The nearest parents to the runtime class should take priority.

        return $template ?? 'elements/_crud_form_tab_embedded.html.twig';
    }

    public function getMetadata(string $identifier): array
    {
        $metadata = $this->cache->getItem($identifier);
        if (!$metadata->isHit()) {
            throw new UnknownEntityIdentifierException($identifier);
        }
        return $metadata->get();
    }

    /**
     * Get the short, unique identifier for the given entity class.
     * We expect the first part of a namespace to be 'OIMC' or 'PluginNAME'.
     * The second part is expected to be 'Entity', followed by the module name, and finally end with the entity name.
     * We do not permit a plugin to have an entity with the same name in the same module as OIMC.
     * Therefore, we can shorten the fully-qualified name of the class down to a 'ModuleEntity' format.
     * Note that anything between the module and entity names are considered sub-modules and are not added to the identifier.
     * Therefore, you cannot have two entities with the same name in the same module or its sub-modules.
     * The ownership information 'OIMC' or 'PluginNAME' is not stored in the identifier, but should be known to the
     * entity manager.
     * <p>
     * Here are some examples of fully-qualified class names and their corresponding identifiers:
     * <ul>
     * <li>OIMC\Entity\Inventory\Server - InventoryServer</li>
     * <li>OIMCPlugins\CJDevStudios\PluginJamfBundle\Entity\Inventory\Ebook - InventoryEbook</li>
     * </ul>
     * </p>
     *
     * @since 1.0.0
     * @param $entity_class
     * @return string
     */
    public function getEntityIdentifier($entity_class): string
    {
        $normalized = EntityUtil::normalizeEntityClass($entity_class);
        /** @var array $class_parts */
        $class_parts = explode('\\', $normalized);
        $is_plugin = $class_parts[0] === 'OIMCPlugins';
        $module = $is_plugin ? $class_parts[4] : $class_parts[2];
        return $module . end($class_parts);
    }

    public function getRuntimeEntityClass($entity_class): string
    {
        try {
            return $this->getEntityFromIdentifier($this->getEntityIdentifier($entity_class));
        } catch (UnknownEntityIdentifierException $e) {
            return $entity_class;
        }
    }

    /**
     * @inheritDoc
     */
    public function getModuleIdentifier($module_class): string
    {
        /** @var array $class_parts */
        $class_parts = explode('\\', $module_class);
        return $class_parts[(int) count($class_parts) - 1];
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityFromIdentifier(string $identifier): string
    {
        $manifest = $this->getManifest()['entities'];
        if (!isset($manifest[$identifier])) {
            throw new UnknownEntityIdentifierException($identifier);
        }
        return $manifest[$identifier];
    }

    /**
     * @inheritDoc
     * @throws UnknownEntityIdentifierException
     */
    public function getModuleFromIdentifier(string $identifier): string
    {
        $manifest = $this->getManifest()['modules'];
        if (!isset($manifest[$identifier])) {
            throw new UnknownEntityIdentifierException($identifier);
        }
        return $manifest[$identifier]['class'];
    }

    public function clear(): bool
    {
        $this->eventDispatcher->dispatch(new Event(), 'cjds.entityregistry.clear');
        return $this->cache->clear();
    }

    /**
     * {@inheritDoc}
     */
    public function getManifest(): array
    {
        try {
            if (!$this->cache->hasItem('manifest')) {
                $this->buildManifest();
            }
            return $this->cache->getItem('manifest')->get() ?? [
                'entities'  => [],
                'modules'   => []
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'entities'  => [],
                'modules'   => []
            ];
        }
    }

    public function getEntityModule(string $identifier): string
    {
        return $this->getMetadata($identifier)['module'];
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityOwner(string $identifier): string
    {
        return $this->getMetadata($identifier)['owner'];
    }

    /**
     * @inheritDoc
     */
    public function getModuleOwner(string $identifier): string
    {
        return $this->getMetadata($identifier)['owner'];
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityRights(string $identifier): array
    {
        return $this->getMetadata($identifier)['rights'];
    }

    /**
     * @inheritDoc
     */
    public function getModuleRights(string $identifier): array
    {
        return $this->getMetadata($identifier)['rights'];
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityTraits(string $identifier): array
    {
        return $this->getMetadata($identifier)['traits'];
    }

    public function getIsEntityRelation(string $identifier): bool
    {
        return $this->getMetadata($identifier)['is_relation'];
    }

    /**
     * Get the form template for the given identifier.
     *
     * @since 1.0.0
     * @param string $identifier The short entity identifier.
     * @return string The path to the Twig template
     * @throws UnknownEntityIdentifierException
     */
    public function getFormTemplate(string $identifier): string
    {
        return $this->getMetadata($identifier)['form_template'];
    }

    /**
     * @inheritDoc
     * @throws UnknownEntityIdentifierException
     */
    public function getEntitiesByOwner(string $owner): array
    {
        $manifest = $this->getManifest();
        $results = [];

        foreach ($manifest as $module => $module_data) {
            foreach ($module_data['entities'] as $identifier => $entity_class) {
                if ($this->getEntityOwner($identifier) === $owner) {
                    $results[] = $identifier;
                }
            }
        }

        return $results;
    }

    /**
     * @inheritDoc
     * @throws UnknownModuleIdentifierException
     */
    public function getModulesByOwner(string $owner): array
    {
        $manifest = $this->getManifest();
        $results = [];

        foreach ($manifest as $identifier => $module_data) {
            if ($this->getModuleOwner($identifier) === $owner) {
                $results[] = $identifier;
            }
        }

        return $results;
    }

    /**
     * @inheritDoc
     */
    public function getEntitiesByModule(string $module): array
    {
        $manifest = $this->getManifest();
        return isset($manifest['modules'][$module]) ? $manifest['modules'][$module]['entities'] : [];
    }

    /**
     * @inheritDoc
     */
    public function getAllRights(): array
    {
        try {
            $rights = $this->cache->getItem('rights_manifest')->get();

            // Remove entities that have no rights
            foreach ($rights as $module => &$module_data) {
                foreach ($module_data['entities'] as $entity => $entity_rights) {
                    if ($entity_rights === null || !count($entity_rights)) {
                        unset($module_data['entities'][$entity]);
                    }
                }
            }
            return $rights ?? [];
        } catch (InvalidArgumentException $e) {
            return [];
        }
    }

    /**
     * {@inheritDoc}
     * @throws UnknownEntityIdentifierException
     */
    public function getEntitySettingsGroup(string $identifier): ?string
    {
        return $this->getMetadata($identifier)['settings_group'];
    }

    public function getDisplayName(string $identifier): string
    {
        return $this->getMetadata($identifier)['display_name'];
    }

    /**
     * @since 1.0.0
     * @param string $identifier
     * @return array
     * @throws UnknownEntityIdentifierException
     */
    public function getMappedSuperclassesForEntity(string $identifier): array
    {
        return $this->getMetadata($identifier)['superclasses'];
    }

    /**
     * @since 1.0.0
     * @param string $entity_class
     * @return array
     */
    public function getChildEntitiesForMappedSuperclass(string $entity_class): array
    {
        $entity_manifest = $this->getManifest()['entities'];
        $result = [];

        foreach ($entity_manifest as $identifier => $class) {
            try {
                if (in_array($entity_class, $this->getMetadata($identifier)['superclasses'], true)) {
                    $result[] = $identifier;
                }
            } catch (UnknownEntityIdentifierException $e) {
                // Should never happen
            }
        }

        return $result;
    }

    protected function getEntityProperties(string $entity_class, $filter, bool $include_parents = true): array
    {
       $reflected = new ReflectionClass($entity_class);
       $result = [
          $entity_class => $reflected->getProperties($filter)
       ];

       if ($include_parents) {
          while ($parent_class = $reflected->getParentClass()) {
             $result[$parent_class->getName()] = $parent_class->getProperties($filter);
          }
       }

       return $result;
    }

   /**
    * @param string $identifier
    * @return array
    * @throws UnknownEntityIdentifierException
    * @throws \Symfony\Component\Cache\Exception\CacheException
    */
    public function getFields(string $identifier): array
    {
       $entity_class = $this->getEntityFromIdentifier($identifier);
       $cache = new ApcuAdapter('cjds.entitymanager', 0);
       $cache_key = 'fields' . str_replace('\\', '.', $entity_class);

       return $cache->get($cache_key, function (ItemInterface $item) use ($entity_class) {
          $item->expiresAfter(5 * 60 * 60);
          $annotation_reader = new AnnotationReader();
          $field_properties = [];
          static::getEntityProperties($entity_class, ReflectionProperty::IS_PRIVATE | ReflectionProperty::IS_PROTECTED);

          //Make sure that the "common" fields are displayed first by reversing array so that it goes from top-most parent to the target class
          $field_properties = array_reverse($field_properties, true);

          $fields = [];
          foreach ($field_properties as $class => $class_fields) {
             /** @var ReflectionProperty $field */
             foreach ($class_fields as $field) {
                /** @var Column $column */
                $column = $annotation_reader->getPropertyAnnotation($field, Column::class);
                /** @var VirtualColumn $virtualcolumn */
                $virtualcolumn = $annotation_reader->getPropertyAnnotation($field, VirtualColumn::class);
                /** @var JoinColumn $join_column */
                $join_column = $annotation_reader->getPropertyAnnotation($field, JoinColumn::class);
                if ($virtualcolumn !== null || $column !== null || $join_column !== null) {
                   $display_name = null;
                   $id = $annotation_reader->getPropertyAnnotation($field, Id::class);
                   /** @var FieldOptions $field_options */
                   $field_options = $annotation_reader->getPropertyAnnotation($field, FieldOptions::class);
                   if (isset($field_options->displayName)) {
                      $display_name = $field_options->displayName;
                   }

                   if ($column !== null) {
                      $required = !$column->nullable && (!isset($column->options['default']) || $column->options['default'] === null);
                   } else if ($join_column !== null) {
                      $required = !$join_column->nullable;
                   } else {
                      $required = false;
                   }

                   $fo_readonly = ($field_options !== null ? $field_options->readonly : false);
                   $column_type = 'normal';
                   if ($column !== null) {
                      $column_type = 'normal';
                   } else if ($virtualcolumn !== null) {
                      $column_type = 'virtual';
                   } else if ($join_column !== null) {
                      $column_type = 'join';
                   }
                   $params = [
                      'form_options'      => [
                         //'label'         => $display_name,
                         'required'   => $required,
                         'empty_data' => $column->options['default'] ?? null,
                         'disabled'   => $id !== null ? true : ($virtualcolumn !== null || $fo_readonly),
                      ],
                      'form_visibility'   => $id !== null ? 'excluded' : ($field_options !== null ? $field_options->form_visibility : 'show'),
                      'search_visibility' => $id !== null ? 'show' : ($field_options !== null ? $field_options->search_visibility : 'show'),
                      'column_type'       => $column_type
                   ];

                   if (($field_options !== null && $field_options->self_itemlink) || $field->getName() === 'name') {
                      $params['link'] = [
                         'route'  => '@crud_view',
                         'params' => [
                            'itemtype' => '@_identifier',
                            'items_id' => '@id' // Replaced by Twig
                         ],
                      ];
                   }

                   $relation_types = [ManyToOne::class, OneToMany::class, OneToOne::class, ManyToMany::class];
                   if ($virtualcolumn !== null) {
                      $params['field_type'] = $field_options->field_type ?? $virtualcolumn->type;
                      $relation = null;
                      foreach ($relation_types as $relation_type) {
                         $relation = $annotation_reader->getPropertyAnnotation($field, $relation_type);
                         if ($relation !== null) {
                            break;
                         }
                      }
                      if ($display_name === null && $relation !== null) {
                         $display_name = $this->getDisplayName($this->getEntityIdentifier($relation->targetEntity));
                      }
                      if ($relation !== null) {
                         $params['field_subtype'] = $relation->targetEntity ?? $params['field_type'];
                         $params['link'] = [
                            'route'  => '@crud_view',
                            'params' => [
                               'itemtype' => $this->getEntityIdentifier($params['field_subtype']),
                               'items_id' => '@rel_id' // Replaced by Twig
                            ],
                         ];
                      } else if ($params['field_type'] === 'item_link') {
                         $params['link'] = [
                            'route'  => '@crud_view',
                            'params' => [
                               'itemtype' => '@'.$virtualcolumn->options['itemtype'],
                               'items_id' => '@'.$virtualcolumn->options['items_id']
                            ],
                         ];
                      } else if ($params['field_type'] === 'ArrayCollection') {
                         $params['subtype'] = $virtualcolumn->options['subtype'];
                         $params['form_options']['disabled'] = false;
                      }
                   } else if ($join_column !== null) {
                      $relation = null;
                      foreach ($relation_types as $relation_type) {
                         $relation = $annotation_reader->getPropertyAnnotation($field, $relation_type);
                         if ($relation !== null) {
                            break;
                         }
                      }
                      if ($display_name === null) {
                         $display_name = $this->getDisplayName($this->getEntityIdentifier($relation->targetEntity));
                      }
                      $params['field_type'] = $relation->targetEntity;
                      $params['link'] = [
                         'route'  => '@crud_view',
                         'params' => [
                            'itemtype' => $this->getEntityIdentifier($params['field_type']),
                            'items_id' => '@rel_id' // Replaced by Twig
                         ],
                      ];
                   } else {
                      if ($column->type === 'string') {
                         switch ($field->getName()) {
                            case 'url':
                               $column->type = 'url';
                               break;
                            case 'email':
                               $column->type = 'email';
                               break;
                            case 'phone':
                               $column->type = 'phone';
                               break;
                         }
                      }
                      /** @var Dropdown $dropdown */
                      $dropdown = $annotation_reader->getPropertyAnnotation($field, Dropdown::class);
                      if ($dropdown) {
                         $column->type = 'choice';
                         if ($column->type === 'simple_array') {
                            $params['form_options']['multiple'] = true;
                         }
                         $params['dropdown'] = [
                            'provider_class' => $dropdown->provider_class,
                            'provider_func'  => $dropdown->provider_func,
                         ];
                      }
                      $params['field_type'] = $field_options->field_type ?? $column->type;
                   }

                   if ($display_name === null) {
                      $display_name = 'field.' . strtolower($field->getName());
                   }
                   $params['form_options']['label'] = $display_name;
                   $fields[$field->getName()] = $params;
                }
             }
          }
          return $fields;
       });
    }
}
