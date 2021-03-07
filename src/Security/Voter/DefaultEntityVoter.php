<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle\Security\Voter;

use CJDevStudios\DevBundle\Service\Stopwatch;
use CJDevStudios\EntityManagementBundle\Entity\AbstractEntity;
use CJDevStudios\EntityManagementBundle\Exception\UnknownEntityIdentifierException;
use CJDevStudios\EntityManagementBundle\Service\EntityRegistryInterface;
use CJDevStudios\EntityManagementBundle\Util\EntityUtil;
use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Security;

/**
 *
 * @since 1.0.0
 */
abstract class DefaultEntityVoter extends Voter {

   public const VIEW = 'view';
   public const EDIT = 'edit';
   public const CREATE = 'create';
   public const DELETE = 'delete';
   public const PURGE = 'purge';

   /**
    * @since 1.0.0
    * @var EntityManagerInterface
    */
   protected $entityManager;

   /**
    * @since 1.0.0
    * @var Security
    */
   protected $security;

   /**
    * @since 1.0.0
    * @var EntityRegistryInterface
    */
   protected $entityRegistry;

   /**
    * @var Stopwatch
    */
   protected $stopwatch;

   public function __construct(EntityManagerInterface $entityManager, Security $security, EntityRegistryInterface $entityRegistry,
                               Stopwatch $stopwatch)
   {
      $this->entityManager = $entityManager;
      $this->security = $security;
      $this->entityRegistry = $entityRegistry;
      $this->stopwatch = $stopwatch;
   }

   protected function getEffectiveRights($subject, $user = null)
   {
      $this->stopwatch->start('DefaultEntityVoter getEffectiveRights');
      if ($user === null) {
         $user = $this->security->getUser();
      }

      // If an instace of an entity is passed, get just the entity identifier
      if (is_object($subject)) {
         $subject_class = get_class($subject);
         $subject_id = $this->entityRegistry->getEntityIdentifier($subject_class);
      } else if ($this->entityRegistry->isEntityClassRegistered($subject)) {
         $subject_class = $subject;
         $subject_id = $this->entityRegistry->getEntityIdentifier($subject);
      } else {
         $subject_class = $this->entityRegistry->getEntityFromIdentifier($subject);
         $subject_id = $subject;
      }

      $repo = $this->entityManager->getRepository(PrivilegeSet_Right::class);

      // Get all sub-entities if this is a superclass
      try {
         if (EntityUtil::isSuperclassEntity($subject_class)) {
            $expanded_subjects = $this->entityRegistry->getChildEntitiesForMappedSuperclass($subject_class);
         } else {
            $expanded_subjects = [$subject_id];
         }
      } catch (AnnotationException|\ReflectionException $e) {
         $this->stopwatch->stop('DefaultEntityVoter getEffectiveRights');
         return [];
      }

      $effective = $repo->calculateEffectiveRights($repo->findByUserAndSubject($user, $expanded_subjects));
      // Flatten
      $flattened = [];
      foreach ($effective as $identifier => $rights) {
         $flattened = array_merge($flattened, $rights);
      }
      $this->stopwatch->stop('DefaultEntityVoter getEffectiveRights');
      return array_unique($flattened);
   }

   protected function supports($attribute, $subject): bool
   {
      $this->stopwatch->start('DefaultEntityVoter supports');
      // if the attribute isn't one we support, return false
      if (!in_array($attribute, [self::VIEW, self::EDIT, self::CREATE, self::DELETE, self::PURGE], false)) {
         $this->stopwatch->stop('DefaultEntityVoter supports');
         return false;
      }

      // If an instance of an entity is passed, get just the class name
      if (is_object($subject)) {
         $subject = get_class($subject);
      }

      // only vote on Entity objects
      $entities = $this->entityRegistry->getManifest()['entities'];
      if (!array_key_exists($subject, $entities)) {
         $this->stopwatch->stop('DefaultEntityVoter supports');
         return false;
      }

      $this->stopwatch->stop('DefaultEntityVoter supports');
      return true;
   }

   /**
    * Get the subject to use at runtime when checking permissions.
    * For example, a child entity like PrivilegeSet_Rights would use the rights from PrivilegeSet.
    *
    * @since 1.0.0
    * @param $requested_subject
    * @return string
    * @throws UnknownEntityIdentifierException
    */
   protected function resolveSubject($requested_subject): string
   {
      $this->stopwatch->start('AbstractOIMCVoter resolveSubject');
      $subject_impersonated = $requested_subject;
      $subject_entity = $this->entityRegistry->getEntityFromIdentifier($requested_subject);
      /** @var AbstractEntity $subject_entity */
      if ($subject_entity::RIGHTS_INHERITED_FROM !== null) {
         $subject_impersonated = $this->entityRegistry->getEntityIdentifier($subject_entity::RIGHTS_INHERITED_FROM);
      }
      $this->stopwatch->stop('AbstractOIMCVoter resolveSubject');
      return $subject_impersonated;
   }

   /**
    * Perform a single access check operation on a given attribute, subject and token.
    * It is safe to assume that $attribute and $subject already passed the "supports()" method check.
    *
    * @since 1.0.0
    * @param string $attribute
    * @param mixed $subject
    * @param TokenInterface $token
    * @return bool
    * @throws UnknownEntityIdentifierException
    */
   protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
   {
      $subject_impersonated = $this->resolveSubject($subject);
      $rights = $this->getEffectiveRights($subject_impersonated, $token->getUser());

      if ($rights === null || !count($rights)) {
         // If no privileges are explicitly assigned, deny this action
         return false;
      }
      return in_array($attribute, $rights, false);
   }
}
