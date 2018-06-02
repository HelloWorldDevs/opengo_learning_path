<?php

namespace Drupal\opigno_learning_path\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\Group;
use Drupal\opigno_learning_path\LearningPathValidator;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the actions related to LP membership.
 */
class LearningPathMembershipController extends ControllerBase {

  protected $connection;

  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('database'));
  }

  /**
   * Returns response for the autocompletion.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions.
   */
  public function findUserGroupAutocomplete() {
    $matches = [];
    $string = \Drupal::request()->query->get('q');

    if ($string) {
      /** @var \Drupal\group\Entity\Group $curr_group */
      $curr_group = \Drupal::routeMatch()
        ->getParameter('group');

      // Find users by email or name.
      $query = \Drupal::entityQuery('user')
        ->condition('uid', 0, '<>');

      $cond_group = $query
        ->orConditionGroup()
        ->condition('mail', '%'
          . $this->connection->escapeLike($string)
          . '%', 'LIKE')
        ->condition('name', '%'
          . $this->connection->escapeLike($string)
          . '%', 'LIKE');

      $query = $query
        ->condition($cond_group)
        ->sort('name')
        ->range(0, 100);

      $uids = $query->execute();
      $users = User::loadMultiple($uids);

      /** @var \Drupal\user\Entity\User $user */
      foreach ($users as $user) {
        $id = $user->id();
        $name = $user->getDisplayName();

        // Skip users that already members of current group.
        if ($curr_group->getMember($user) !== FALSE) {
          continue;
        }

        $matches[] = [
          'value' => "$name (User $id)",
          'label' => $name,
          'type' => 'user',
          'id' => 'user_' . $id,
        ];
      }

      // Find groups.
      $query = \Drupal::entityQuery('group')
        ->condition('type', 'opigno_class')
        ->condition('label', '%'
          . $this->connection->escapeLike($string)
          . '%', 'LIKE')
        ->sort('label')
        ->range(0, 100);

      $gids = $query->execute();
      $groups = Group::loadMultiple($gids);

      /** @var \Drupal\group\Entity\Group $group */
      foreach ($groups as $group) {
        $id = $group->id();
        $name = $group->label();

        $matches[] = [
          'value' => "$name (Group $id)",
          'label' => $name,
          'type' => 'group',
          'id' => 'class_' . $id,
        ];
      }
    }

    return new JsonResponse($matches);
  }

  /**
   * Returns users that are not members of current group for the autocompletion.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions.
   */
  public function findUsersAutocomplete() {
    $matches = [];
    $string = \Drupal::request()->query->get('q');

    if ($string) {
      /** @var \Drupal\group\Entity\Group $curr_group */
      $curr_group = \Drupal::routeMatch()
        ->getParameter('group');

      // Find users by email or name.
      $query = \Drupal::entityQuery('user')
        ->condition('uid', 0, '<>');

      $cond_group = $query
        ->orConditionGroup()
        ->condition('mail', '%'
          . $this->connection->escapeLike($string)
          . '%', 'LIKE')
        ->condition('name', '%'
          . $this->connection->escapeLike($string)
          . '%', 'LIKE');

      $query = $query
        ->condition($cond_group)
        ->sort('name')
        ->range(0, 100);

      $uids = $query->execute();
      $users = User::loadMultiple($uids);

      /** @var \Drupal\user\Entity\User $user */
      foreach ($users as $user) {
        $id = $user->id();
        $name = $user->getDisplayName();

        // Skip users that already members of current group.
        if ($curr_group->getMember($user) !== FALSE) {
          continue;
        }

        $matches[] = [
          'value' => "$name ($id)",
          'label' => $name,
          'id' => 'user_' . $id,
        ];
      }
    }

    return new JsonResponse($matches);
  }

  /**
   * Returns users of current group for the autocompletion.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions.
   */
  public function findUsersInGroupAutocomplete() {
    $matches = [];
    $string = \Drupal::request()->query->get('q');

    if ($string) {
      /** @var \Drupal\group\Entity\Group $curr_group */
      $curr_group = \Drupal::routeMatch()
        ->getParameter('group');

      // Find users by email or name.
      $query = \Drupal::entityQuery('user')
        ->condition('uid', 0, '<>');

      $cond_group = $query
        ->orConditionGroup()
        ->condition('mail', '%'
          . $this->connection->escapeLike($string)
          . '%', 'LIKE')
        ->condition('name', '%'
          . $this->connection->escapeLike($string)
          . '%', 'LIKE');

      $query = $query
        ->condition($cond_group)
        ->sort('name');

      $uids = $query->execute();
      $users = User::loadMultiple($uids);

      /** @var \Drupal\user\Entity\User $user */
      foreach ($users as $user) {
        $id = $user->id();
        $name = $user->getDisplayName();

        // Remove users that are not members of current group.
        if ($curr_group->getMember($user) === FALSE) {
          continue;
        }

        $matches[] = [
          'value' => "$name ($id)",
          'label' => $name,
          'id' => $id,
        ];
      }
    }

    return new JsonResponse($matches);
  }

  /**
   * Ajax callback used in opingo_learning_path_member_add.js.
   *
   * Returns group members list.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getGroupMembers() {
    $matches = [];

    /** @var \Drupal\group\Entity\Group $group */
    $group = \Drupal::routeMatch()->getParameter('group');
    $members = $group->getMembers();

    /** @var \Drupal\group\GroupMembership $member */
    foreach ($members as $member) {
      $user = $member->getUser();
      $matches[$user->id()] = $user->getDisplayName();
    }

    return new JsonResponse($matches);
  }

  /**
   * Ajax callback used in opingo_learning_path_member_add.js.
   *
   * Creates user.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function createUser(Group $group) {
    $account = $this->currentUser();
    $roles = $account->getRoles();
    $is_admin = in_array('administrator', $roles);
    $is_platform_um = in_array('user_manager', $roles);

    if (!($is_admin || $is_platform_um)) {
      throw new AccessDeniedHttpException();
    }

    $name = \Drupal::request()->query->get('name');
    $email = \Drupal::request()->query->get('email');

    // Create new user.
    $user = User::create();
    $user->enforceIsNew();
    $user->setUsername($name);
    $user->setEmail($email);
    $user->activate();
    $user->save();

    // Assign the user to the learning path.
    $group->addMember($user);

    return new JsonResponse([
      'id' => $user->id(),
      'message' => t('New user profile created'),
    ]);
  }

  /**
   * Ajax callback used in opingo_learning_path_member_add.js.
   *
   * Creates class.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function createClass(Group $group) {
    $account = $this->currentUser();
    $roles = $account->getRoles();
    $is_admin = in_array('administrator', $roles);
    $is_platform_um = in_array('user_manager', $roles);

    if (!($is_admin
      || $is_platform_um
      || $account->hasPermission('create opigno class group'))) {
      throw new AccessDeniedHttpException();
    }

    $name = \Drupal::request()->query->get('name');
    $users = \Drupal::request()->query->get('users');

    if (isset($users)) {
      // Parse uids.
      $uids = array_map(function ($user) {
        list($type, $id) = explode('_', $user);
        return $id;
      }, $users);

      // Load users.
      $users = User::loadMultiple($uids);
    }
    else {
      $users = [];
    }

    // Create new class.
    /** @var \Drupal\group\Entity\Group $class */
    $class = Group::create([
      'type' => 'opigno_class',
      'label' => $name,
    ]);
    $class->save();

    // Assign the class to the learning path.
    $group->addContent($class, 'subgroup:opigno_class');

    // Assign users to the class.
    foreach ($users as $user) {
      if (!isset($user)) {
        continue;
      }

      $class->addMember($user);
    }

    // Assign users to the learning path.
    foreach ($users as $user) {
      if (!isset($user)) {
        continue;
      }

      $group->addMember($user);
    }

    return new JsonResponse([
      'id' => $class->id(),
      'message' => t('New class created'),
    ]);
  }

  /**
   * Ajax callback used in opingo_learning_path_member_overview.js.
   *
   * Removes member from learning path.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function deleteUser() {
    /** @var \Drupal\group\Entity\Group $group */
    $group = \Drupal::routeMatch()->getParameter('group');

    if (!isset($group)) {
      throw new NotFoundHttpException();
    }

    $uid = \Drupal::request()->query->get('user_id');
    $user = User::load($uid);

    if (!isset($user)) {
      throw new NotFoundHttpException();
    }

    $member = $group->getMember($user);

    if (!isset($member)) {
      throw new NotFoundHttpException();
    }

    $account = $this->currentUser();
    $roles = $account->getRoles();
    $is_admin = in_array('administrator', $roles);
    $is_platform_um = in_array('user_manager', $roles);

    if (!($is_admin
      || $is_platform_um
      || $member->getGroupContent()->access('delete', $account))) {
      throw new AccessDeniedHttpException();
    }

    $group->removeMember($user);

    return new JsonResponse();
  }

  /**
   * Ajax callback used in opingo_learning_path_member_overview.js.
   *
   * Removes class from learning path.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function deleteClass() {
    $group = \Drupal::routeMatch()->getParameter('group');

    $class_id = \Drupal::request()->query->get('class_id');
    $class = Group::load($class_id);

    if (!isset($group) || !isset($class)) {
      throw new NotFoundHttpException();
    }

    $content = $group->getContent();

    $account = $this->currentUser();
    $roles = $account->getRoles();
    $is_admin = in_array('administrator', $roles);
    $is_platform_um = in_array('user_manager', $roles);

    /** @var \Drupal\group\Entity\GroupContentInterface $item */
    foreach ($content as $item) {
      $entity = $item->getEntity();
      $type = $entity->getEntityTypeId();
      $bundle = $entity->bundle();

      if ($type === 'group' && $bundle === 'opigno_class'
        && $entity->id() === $class->id()) {
        if (!($is_admin
          || $is_platform_um
          || $item->access('delete', $account))) {
          throw new AccessDeniedHttpException();
        }

        $item->delete();
        break;
      }
    }

    return new JsonResponse();
  }

  /**
   * Ajax callback used in opingo_learning_path_member_overview.js.
   *
   * Toggles user role in learning path.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function toggleRole() {
    /** @var \Drupal\group\Entity\Group $group */
    $group = \Drupal::routeMatch()->getParameter('group');

    $uid = \Drupal::request()->query->get('uid');
    $user = User::load($uid);

    $role = \Drupal::request()->query->get('role');

    if (!isset($group) || !isset($user) || !isset($role)) {
      throw new NotFoundHttpException();
    }

    $member = $group->getMember($user);

    if (!isset($member)) {
      throw new NotFoundHttpException();
    }

    $account = $this->currentUser();
    $roles = $account->getRoles();
    $is_admin = in_array('administrator', $roles);
    $is_platform_um = in_array('user_manager', $roles);

    if (!($is_admin
      || $is_platform_um
      || $member->getGroupContent()->access('update', $account))) {
      throw new AccessDeniedHttpException();
    }

    $group_content = $member->getGroupContent();

    $values = $group_content->get('group_roles')->getValue();
    $found = FALSE;

    foreach ($values as $index => $value) {
      if ($value['target_id'] === $role) {
        $found = TRUE;
        unset($values[$index]);
        break;
      }
    }

    if ($found === FALSE) {
      $values[] = ['target_id' => $role];
    }

    $group_content->set('group_roles', $values);
    $group_content->save();

    return new JsonResponse();
  }

  /**
   * Ajax callback used in opingo_learning_path_member_overview.js.
   *
   * Validates user role in learning path.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function validate() {
    /** @var \Drupal\group\Entity\Group $group */
    $group = \Drupal::routeMatch()->getParameter('group');
    $gid = $group->id();

    $uid = \Drupal::request()->query->get('user_id');
    $user = User::load($uid);

    if (!isset($group) || !isset($user)) {
      throw new NotFoundHttpException();
    }

    $member = $group->getMember($user);

    if (!isset($member)) {
      throw new NotFoundHttpException();
    }

    $account = $this->currentUser();
    $roles = $account->getRoles();
    $is_admin = in_array('administrator', $roles);
    $is_platform_um = in_array('user_manager', $roles);

    if (!($is_admin
      || $is_platform_um
      || $member->getGroupContent()->access('update', $account))) {
      throw new AccessDeniedHttpException();
    }

    $group_content = $member->getGroupContent();

    $query = \Drupal::database()
      ->merge('opigno_learning_path_group_user_status')
      ->key('mid', $group_content->id())
      ->insertFields([
        'mid' => $group_content->id(),
        'uid' => $uid,
        'gid' => $gid,
        'status' => 1,
        'message' => '',
      ])
      ->updateFields([
        'uid' => $uid,
        'gid' => $gid,
        'status' => 1,
        'message' => '',
      ]);
    $result = $query->execute();

    if ($result) {
      // Invalidate cache.
      $tags = $member->getCacheTags();
      \Drupal::service('cache_tags.invalidator')
        ->invalidateTags($tags);

      // Send email.
      $module = 'opigno_learning_path';
      $key = 'opigno_learning_path_membership_validated';
      $email = $user->getEmail();
      $lang = $user->getPreferredLangcode();
      $params = [];
      $params['subject'] = $this->t('Your membership to the training @training has been approved', [
        '@training' => $group->label(),
      ]);
      $site_config = \Drupal::config('system.site');
      $link = $group->toUrl()->setAbsolute()->toString();
      $params['message'] = $this->t('Dear @username

Your membership to the training @training has been approved. You can now access this training at: <a href=":link">@link_text</a>

@platform', [
        '@username' => $user->getDisplayName(),
        '@training' => $group->label(),
        ':link' => $link,
        '@link_text' => $link,
        '@platform' => $site_config->get('name'),
      ]);

      \Drupal::service('plugin.manager.mail')
        ->mail($module, $key, $email, $lang, $params);
    }

    return new JsonResponse();
  }

  public function access(Group $group, AccountInterface $account) {

    // Check if user has uncompleted steps.
    LearningPathValidator::stepsValidate($group);

    if (empty($group) || !is_object($group)) {
      return AccessResult::forbidden();
    }

    if (!$group->access('view', $account)) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowed();
  }

}
