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
use Psr\Log\LoggerInterface;
use ReflectionException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 *
 * @since 1.0.0
 */
class MenuManager {

   /** @var RouterInterface */
   private $router;

   /** @var LoggerInterface */
   private $logger;

   /** @var EntityRegistryInterface */
   private $entityRegistry;

   /** @var AuthorizationCheckerInterface */
   private $authorizationChecker;

   /**
    * @since 1.0.0
    * @var Stopwatch
    */
   private $stopwatch;

   /**
    * @since 1.0.0
    * @var SessionInterface
    */
   private $session;

   public function __construct(RouterInterface $router, LoggerInterface $logger, EntityRegistryInterface $entityRegistry, AuthorizationCheckerInterface $authorizationChecker,
                               Stopwatch $stopwatch, SessionInterface $session)
   {
      $this->router = $router;
      $this->logger = $logger;
      $this->entityRegistry = $entityRegistry;
      $this->authorizationChecker = $authorizationChecker;
      $this->stopwatch = $stopwatch;
      $this->session = $session;
   }

   /**
    * Get the menu layout for the current locale.
    * If the generated menu does not exist or is expired, it is regenerated.
    * @return mixed
    */
   public function getMenuLayout()
   {
      try {
         if (!$this->session->has('menu')) {
            $menu = $this->generateMenuLayout();
            foreach ($menu as $module => $module_data) {
               if (isset($module_data['items'])) {
                  foreach ($module_data['items'] as $item => $item_data) {
                     if (isset($item_data['class'])) {
                        $identifier = $this->entityRegistry->getEntityIdentifier($item_data['class']);
                        if (!$this->authorizationChecker->isGranted(AbstractOIMCVoter::VIEW, $identifier)) {
                           unset($menu[$module]['items'][$item]);
                        }
                     }
                     if (isset($item_data['items'])) {
                        foreach ($item_data['items'] as $subitem => $subitem_data) {
                           if (isset($subitem_data['class'])) {
                              $identifier = $this->entityRegistry->getEntityIdentifier($subitem_data['class']);
                              if (!$this->authorizationChecker->isGranted(AbstractOIMCVoter::VIEW, $identifier)) {
                                 unset($menu[$module]['items'][$item]['items'][$subitem]);
                              }
                           }
                        }
                     }
                  }
               }
            }
            $this->session->set('menu', $menu);
         }
         return $this->session->get('menu');
      } catch (ReflectionException $e) {
         $this->logger->error($e->getMessage());
      }
      return [];
   }

   /**
    * Build and append all lower tiered menus of the given class.
    * This functions looks for all classes extending the parent class (model), adds those menus, and then recursively calls this function to continue building lower tiers.
    * @param array $parent_menu Pointer to the higher-level menu tiers
    * @param string $parent_class The parent class which we are generating subtype menus for
    * @param array $model_classes Pool of model classes to consider when building the menu
    * @throws ReflectionException
    */
   private function autogenerateSubtypeMenu(array &$parent_menu, string $parent_class, $model_classes)
   {
      foreach ($model_classes as $class) {
         $rc = new ReflectionClass($class);

         $rcp = $rc->getParentClass();
         if (!$rcp || $rcp === 'Class') {
            continue;
         }
         if ($rcp->getShortName() === $parent_class || $rcp->getName() === $parent_class) {
            /** @var AbstractOIMCEntity $model */
            $model = $rc->getName();

            // normalize proxies
            $model = EntityUtils::normalizeEntityClass($model);
//                if (strpos($model, 'Proxies') === 0) {
//                    continue;
//                }

            $entity_id = $this->entityRegistry->getEntityIdentifier($class);
            if ($rc->getConstant('HAS_MENU_ITEM') === true) {
               $params = [
                  'name'  => $this->entityRegistry->getDisplayName($entity_id),
                  'icon'  => $model::getTypeIcon(),
                  'class' => $model,
                  'url'   => $this->router->generate('crud_list', ['itemtype' => $entity_id]),
                  'items' => [],
               ];
               $parent_menu[$rc->getShortName()] = $params;
            }
            $this->autogenerateSubtypeMenu($parent_menu[$rc->getShortName()]['items'], $rc->getShortName(), $model_classes);
         }
      }
   }

   /**
    * @return array
    * @throws ReflectionException
    */
   private function generateMenuLayout(): array
   {
      $this->stopwatch->start('generateMenuLayout', 'oimc');

      $manifest = $this->entityRegistry->getManifest();
      // Use known array for core modules to ensure they are listed in a specific order
      // Then add any extra modules in (sorted by class)
      $registered_modules = $manifest['modules'];
      $core_modules = AbstractOIMCModule::getCoreModules();
      $all_modules = [];
      foreach ($core_modules as $core_module) {
         foreach ($registered_modules as $module_id => $module_data) {
            if ($core_module === $module_data['class']) {
               $all_modules[$module_id] = $core_module;
            }
         }
      }
      //sort($registered_modules);
      $all_modules = array_replace($all_modules, $registered_modules);

      $menu = [];

      $registered_entities = $manifest['entities'];
      $loaded_classes = array_values($registered_entities);

      $sub_models = array_filter($loaded_classes, static function ($c) {
         return get_parent_class($c) !== AbstractOIMCEntity::class;
      });

      /** @var string $module */
      foreach ($all_modules as $module => $module_data) {
         /** @var AbstractOIMCModule|string $module_class */
         $module_class = $module_data['class'];
         if (!$module_class::HAS_MENU_ITEM) {
            continue;
         }
         $classpath = explode('\\', $module_class);
         $classname = end($classpath);
         $menu[$classname] = [
            'name'  => $this->entityRegistry->getDisplayName($module),
            'icon'  => $module_class::getIcon(),
            'class' => $module_class,
         ];

         $route_override = $module_class::getMenuRouteOverride();
         if ($route_override) {
            $menu[$classname]['url'] = $this->router->generate($route_override['route'], $route_override['route_params'] ?? []);
         } else {
            $menu[$classname]['items'] = [];
         }

         foreach ($module_class::getExtraMenuItems() as $item) {
            $menu[$classname]['items'][] = [
               'name' => $item['name'],
               'url'  => $item['url'] ?? ($this->router->generate($item['route'], $item['route_params'] ?? [])),
               'icon' => $item['icon'] ?? null,
            ];
         }

         $entities = $module_data['entities'];
         $parent_entities = [];
         foreach ($entities as $entity_id) {
            /** @var AbstractOIMCEntity|string $entity_class */
            $entity_class = $registered_entities[$entity_id];
            $parent = get_parent_class($entity_class);

            $rcp = new ReflectionClass($parent);
            while ($rcp->isAbstract() && $rcp->getName() !== "OIMC\\Entity\\AbstractOIMCEntity") {
               if ($rcp->getParentClass() === null) {
                  break;
               }
               $rcp = $rcp->getParentClass();
            }
            if ($rcp->getName() === "OIMC\\Entity\\AbstractOIMCEntity") {
               $parent_entities[] = $entity_id;
               continue;
            }
         }

         $reader = new AnnotationReader();
         foreach ($entities as $entity_id) {
            /** @var AbstractOIMCEntity|string $entity_class */
            $entity_class = $registered_entities[$entity_id];

            if (!in_array($entity_id, $parent_entities)) {
               continue;
            }

            $menu_items = $entity_class::getMenuItems();
            if (empty($menu_items)) {
               $rc = (new ReflectionClass($entity_class));
               if (!$rc->getConstant('HAS_MENU_ITEM')) {
                  continue;
               }
               if ($rc->isAbstract()) {
                  if (!isset($menu_items[$module]['items'])) {
                     $menu_items[$module]['items'] = [];
                  }
                  $this->autogenerateSubtypeMenu($menu_items[$module]['items'], $entity_class, $sub_models);
               } else {
                  // This was for disabling going to the list view on superclasses.
//                        $url = '#';
//                        $msc = $reader->getClassAnnotation($rc, MappedSuperclass::class);
//                        if ($msc === null) {
//                            $url = $this->router->generate('crud_list', ['itemtype' => $entity_id]);
//                        }

                  $params = [
                     'name'  => $this->entityRegistry->getDisplayName($entity_id),
                     'icon'  => $entity_class::getTypeIcon(),
                     'class' => $entity_class,
                     'url'   => $this->router->generate('crud_list', ['itemtype' => $entity_id]), //$url,
                     'items' => [],
                  ];

                  $menu_items = [
                     $module => [
                        'items' => [
                           $rc->getShortName() => $params,
                        ],
                     ],
                  ];
                  $this->autogenerateSubtypeMenu($menu_items[$module]['items'][$rc->getShortName()]['items'], $rc->getShortName(), $sub_models);
               }
            } else {
               foreach ($menu_items as $k => $tlm) {
                  if (!isset($tlm['items']) || !count($tlm['items'])) {
                     continue;
                  }
                  foreach ($tlm['items'] as $shortclass => $params) {
                     if (isset($params['class'])) {
                        /** @var AbstractOIMCEntity $menu_class */
                        $menu_class = $params['class'];
                        $params['name'] = $this->entityRegistry->getDisplayName($entity_id);
                        $params['icon'] = $params['icon'] ?? $menu_class::getTypeIcon();
                        $params['class'] = $menu_class;
                        //$rc = (new ReflectionClass($menu_class));
                        //$namespace = $rc->getNamespaceName();
                        //$namespace_p = explode('\\', $namespace);
                        //$module = strtolower($namespace_p[count($namespace_p) - 1]);
                        $params['url'] = $this->router->generate('crud_list', ['itemtype' => $entity_id]);
                        $menu_items[$k]['items'][$shortclass] = $params;
                     }
                  }
               }
            }
            $menu = array_merge_recursive($menu, $menu_items);
         }
      }

      $this->stopwatch->stop('generateMenuLayout');

      return $menu;
   }

   /**
    * Get the navigation breadcrumbs for the given entity (by identifier).
    * @param string $entity_id
    * @return array
    * @throws CacheException
    * @throws ReflectionException
    */
   public function getBreadcrumbs(string $entity_id): array
   {
      $breadcrumbs = [];
      $class_heirarchy = [];
      $menuLayout = $this->getMenuLayout();
      $entity_class = $this->entityRegistry->getEntityFromIdentifier($entity_id);
      $entity_metadata = $this->entityRegistry->getMetadata($entity_id);
      $module = $entity_metadata['module'];

      $rc = new ReflectionClass($entity_class);
      $class_heirarchy[] = $entity_id;
      $parent = $rc->getParentClass();
      while ($parent) {
         $class_heirarchy[] = $this->entityRegistry->getEntityIdentifier($parent->getName());
         $parent = $parent->getParentClass();
      }
      if (isset($menuLayout[$module])) {
         $breadcrumbs[] = [
            'name' => $this->entityRegistry->getDisplayName($module),
            'url'  => $menuLayout[$module]['url'] ?? '',
         ];
      }

      $class_heirarchy = array_reverse($class_heirarchy);

      if (!(isset($menuLayout[$module]) && !isset($menuLayout[$module]['items']))) {
         $menu_pointer = isset($menuLayout[$module]) ? $menuLayout[$module]['items'] : $menuLayout;
         foreach ($class_heirarchy as $class) {
            if (!array_key_exists($class, $menu_pointer)) {
               if ($class === $entity_id) {
                  $breadcrumbs[] = [
                     'name' => $entity_metadata['display_name'],
                     'url'  => $this->router->generate('crud_list', ['itemtype' => $entity_id]),
                  ];
                  break;
               }

               continue;
            }

            $breadcrumbs[] = [
               'name' => $menu_pointer[$class]['class']::getTypeName(),
               'url'  => $menu_pointer[$class]['url'] ?? '',
            ];
            $menu_pointer = $menu_pointer[$class]['items'];
         }
      } else {
         $breadcrumbs[] = [
            'name' => $entity_metadata['display_name'],
            'url'  => $this->router->generate('crud_list', ['itemtype' => $entity_id]),
         ];
      }

      return $breadcrumbs;
   }

   public function regenerateMenu(): void
   {
      $this->session->remove('menu');
   }
}
