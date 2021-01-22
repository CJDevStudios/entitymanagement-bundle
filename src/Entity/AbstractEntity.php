<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle\Entity;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

abstract class AbstractEntity {

   /**
    * @since 1.0.0
    * @return string|null CSS class for the icon used to represent this item type.
    */
   public static function getTypeIcon(): ?string
   {
      return null;
   }

   /**
    * @since 1.0.0
    * @return array Array of navigation menu items. If not specified, this is auto-generated. Otherwise, the array must specify the full layout.
    */
   public static function getMenuItems(): array
   {
      return [];
   }

   /**
    * @since 1.0.0
    * @return array Array of non-standard items to add to the toolbar. These items should be related to the itemtype itself and not an individual item.
    * For example, 'Add' or 'Search' but not 'Duplicate'.
    */
   public static function getExtraToolbarItems(): array
   {
      return [];
   }

   private static function getProperties($class, &$properties, $filter): void
   {
      try {
         $reflected = new ReflectionClass($class);
         $field_properties[$class] = $reflected->getProperties($filter);
         $properties = array_merge($properties, $field_properties);
         if ($reflected->getParentClass() && $reflected->getParentClass() !== __CLASS__) {
            static::getProperties($reflected->getParentClass()->getName(), $properties, $filter);
         }
      } catch (ReflectionException $e) {
         return;
      }
   }

   /**
    * Get an array of fields with relevant metadata about their behaviors in forms and search results.
    * This is an expensive process for information that is not expected to change at runtime, so the results are cached for 5 hours.
    * If you are a developer, you can clear the cache using the Debug menu to force regeneration of this information.
    *
    * @since 1.0.0
    * @return array
    * @throws InvalidArgumentException
    * @throws CacheException
    */
   public static function getFields(): array
   {
      /** @global Kernel $KERNEL */
      global $KERNEL;
      $cache = new ApcuAdapter('oimc', 0);
      $cache_key = 'fields' . str_replace('\\', '.', static::class);

      return $cache->get($cache_key, static function (ItemInterface $item) use ($KERNEL) {
         $entityRegistry = $KERNEL->getContainer()->get('oimc.entityregistry');
         $item->expiresAfter(5 * 60 * 60);
         $annotation_reader = new AnnotationReader();
         $field_properties = [];
         static::getProperties(static::class, $field_properties, ReflectionProperty::IS_PRIVATE | ReflectionProperty::IS_PROTECTED);

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
                        $display_name = $entityRegistry->getDisplayName($entityRegistry->getEntityIdentifier($relation->targetEntity));
                     }
                     if ($relation !== null) {
                        $params['field_subtype'] = $relation->targetEntity ?? $params['field_type'];
                        $params['link'] = [
                           'route'  => '@crud_view',
                           'params' => [
                              'itemtype' => $entityRegistry->getEntityIdentifier($params['field_subtype']),
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
                        $display_name = $entityRegistry->getDisplayName($entityRegistry->getEntityIdentifier($relation->targetEntity));
                     }
                     $params['field_type'] = $relation->targetEntity;
                     $params['link'] = [
                        'route'  => '@crud_view',
                        'params' => [
                           'itemtype' => $entityRegistry->getEntityIdentifier($params['field_type']),
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

   /**
    * Helper function for creating an array construct with information about a tab
    *
    * @since 1.0.0
    * @param string $name The localized tab name
    * @param string $route_name The route name
    * @param array $params Route parameter array
    * @param array $query_params Query parameter array
    * @param bool $is_main True if this is the main tab for an item (Usually displays the items fields tather than relations)
    * @return array
    */
   protected static function createTab($name, $route_name, $params, $query_params = [], $is_main = false): array
   {
      $query_params['tab_embedded'] = $query_params['tab_embedded'] ?? true;
      return [
         'name'         => $name,
         'is_main'      => $is_main,
         'route'        => $route_name,
         'route_params' => $params,
         'query_params' => $query_params,
      ];
   }

   public static function getTabs(): array
   {
      $identifier = static::getEntityIdentifier();
      $tabs = [
         static::createTab(static::getTypeName(1), 'crud_view', [
            'itemtype' => $identifier,
            'items_id' => '@id',
         ], [], true),
      ];
      if (EntityUtils::hasTrait(static::class, Noted::class)) {
         $tabs[] = Note::getTabForEntity(static::class);
      }
      return $tabs;
   }

   /**
    * Convenience method for getting this entity's identifier since entities cannot have autowired services.
    * This uses the oimc.entityregistry service.
    * @since 1.0.0
    * @return string
    */
   protected static function getEntityIdentifier(): string
   {
      /** @global Kernel $KERNEL */
      global $KERNEL;
      $entityRegistry = $KERNEL->getContainer()->get('oimc.entityregistry');

      return $entityRegistry->getEntityIdentifier(static::class);
   }

   /**
    * Return an array of export methods allowed for this entity.
    * @since 1.0.0
    * @return array Array of export methods
    */
   public static function getSupportedExportMethods(): array
   {
      return [EntityExporter::FORMAT_CSV, EntityExporter::FORMAT_JSON, EntityExporter::FORMAT_EXCEL];
   }

   public static function getSupportedBasicRights(): array
   {
      return [AbstractOIMCVoter::VIEW, AbstractOIMCVoter::EDIT, AbstractOIMCVoter::CREATE, AbstractOIMCVoter::PURGE];
   }
}
