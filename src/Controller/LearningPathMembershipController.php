<?php

namespace Drupal\opigno_learning_path\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\group\GroupMembership;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the actions related to LP membership.
 */
class LearningPathMembershipController extends ControllerBase {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Form builder.
   *
   * @var \Drupal\Core\Form\FormBuilder
   */
  protected $formBuilder;

  /**
   * LearningPathMembershipController constructor.
   */
  public function __construct(
    Connection $connection,
    FormBuilderInterface $formBuilder
  ) {
    $this->connection = $connection;
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('form_builder')
    );
  }

  /**
   * Callback for opening the create members modal form.
   */
  public function createMembersFormModal() {
    $form = $this->formBuilder->getForm('Drupal\opigno_learning_path\Form\LearningPathCreateMemberForm');
    $command = new OpenModalDialogCommand($this->t('Create new members'), $form);

    $response = new AjaxResponse();
    $response->addCommand($command);
    return $response;
  }

  /**
   * Callback for opening the create members modal form.
   */
  public function createUserFormModal() {
    $form = $this->formBuilder->getForm('Drupal\opigno_learning_path\Form\LearningPathCreateUserForm');
    $command = new OpenModalDialogCommand($this->t('2/2 create a new user'), $form);

    $response = new AjaxResponse();
    $response->addCommand($command);
    return $response;
  }

  /**
   * Callback for opening the create members modal form.
   */
  public function createClassFormModal() {
    $form = $this->formBuilder->getForm('Drupal\opigno_learning_path\Form\LearningPathCreateClassForm');
    $command = new OpenModalDialogCommand($this->t('Create a new class'), $form);

    $response = new AjaxResponse();
    $response->addCommand($command);
    return $response;
  }

  /**
   * Returns response for the autocompletion.
   *
   * @param \Drupal\group\Entity\Group $group
   *   Group.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions.
   */
  public function addUserToTrainingAutocompleteSelect(Group $group): array {
    $matches = [];
    $default = [];
    $is_class = $group->getGroupType()->id() == 'opigno_class';
    $string = \Drupal::request()->query->get('q');
    // Find users by email or name.
    $query = \Drupal::entityQuery('user')
      ->condition('uid', 0, '<>');
    if ($string !== NULL) {
      $like_string = '%' . $this->connection->escapeLike($string) . '%';
      $cond_group = $query
        ->orConditionGroup()
        ->condition('mail', $like_string, 'LIKE')
        ->condition('name', $like_string, 'LIKE');
      $query = $query
        ->condition($cond_group);
    }

    $query->sort('name');

    $uids = $query->execute();
    $users = User::loadMultiple($uids);

    /** @var \Drupal\user\Entity\User $user */
    foreach ($users as $user) {
      $id = $user->id();
      $name = $user->getDisplayName();

      // Skip users that already members of the current group.
      if ($group->getMember($user) !== FALSE) {
        $default[] = 'user_' . $id;
      }

      $matches['user_' . $id] = [
        'value' => "$name (User #$id)",
        'label' => "$name (User #$id)",
        'type' => 'user',
        'id' => 'user_' . $id,
      ];
    }

    if (!$is_class) {
      // Find classes by name.
      $query = \Drupal::entityQuery('group')
        ->condition('type', 'opigno_class');
      if ($string && $like_string) {
        $query->condition('label', $like_string, 'LIKE');
      }
      $query->sort('label')
        ->range(0, 20);

      $gids = $query->execute();
      $classes = Group::loadMultiple($gids);

      $db_connection = \Drupal::service('database');
      /** @var \Drupal\group\Entity\Group $class */
      foreach ($classes as $class) {
        // Check if class already added.
        $is_class_added = $db_connection->select('group_content_field_data', 'g_c_f_d')
          ->fields('g_c_f_d', ['id'])
          ->condition('gid', $group->id())
          ->condition('entity_id', $class->id())
          ->condition('type', 'group_content_type_27efa0097d858')
          ->execute()->fetchField();

        // If class haven't added yet.
        $id = $class->id();
        $name = $class->label();
        if ($is_class_added) {
          $default[] = 'class_' . $id;
        }
        $matches['class_' . $id] = [
          'value' => "$name (Group #$id)",
          'label' => "$name (Group #$id)",
          'type' => 'group',
          'id' => 'class_' . $id,
          'members' => array_reduce($class->getMembers(), function ($acc, GroupMembership $entity) {
            $user = $entity->getGroupContent()->getEntity();
            $id = $user->id();
            $name = $user->getDisplayName();
            $acc[$user->getEntityTypeId() . '_' . $user->id()] = [
              'value' => "$name (User #$id)",
              'label' => "$name (User #$id)",
              'type' => 'user',
              'id' => 'user_' . $id,
            ];
            return $acc;
          }, []),
        ];
      }
    }

    return [$matches, $default];
  }


  /**
   * Returns response for the autocompletion.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function addUserToTrainingAutocomplete(Group $group) {
    [$matches, $default] = $this->addUserToTrainingAutocompleteSelect($group);
    return new JsonResponse($matches);
  }

  /**
   * Returns response for the autocompletion.
   *
   * @param \Drupal\group\Entity\Group $group
   *   Group.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions.
   */
  public function addUserToClassAutocomplete(Group $group) {
    $matches = [];
    $string = \Drupal::request()->query->get('q');
    if ($string !== NULL) {
      $like_string = '%' . $this->connection->escapeLike($string) . '%';
      // Find users by email or name.
      $query = \Drupal::entityQuery('user')
        ->condition('uid', 0, '<>')
        ->condition('name', $like_string, 'LIKE')
        ->sort('name')
        ->range(0, 20);
      $uids = $query->execute();

      $count = count($uids);

      if ($count < 20) {
        $range = 20 - $count;
        $query = \Drupal::entityQuery('user')
          ->condition('uid', 0, '<>')
          ->condition('mail', $like_string, 'LIKE')
          ->sort('name')
          ->range(0, $range);
        $uids = array_merge($uids, $query->execute());
      }

      $users = User::loadMultiple($uids);

      /** @var \Drupal\user\Entity\User $user */
      foreach ($users as $user) {
        $id = $user->id();
        $name = $user->getDisplayName();

        $matches[] = [
          'value' => "$name ($id)",
          'label' => "$name ($id)",
          'type' => 'user',
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
      $like_string = '%' . $this->connection->escapeLike($string) . '%';
      /** @var \Drupal\group\Entity\Group $curr_group */
      $curr_group = \Drupal::routeMatch()
        ->getParameter('group');

      if (!is_null($curr_group) && !$curr_group instanceof Group) {
        $curr_group = Group::load($curr_group); 
      }

      // Find users by email or name.
      $query = \Drupal::entityQuery('user')
        ->condition('uid', 0, '<>');

      $cond_group = $query
        ->orConditionGroup()
        ->condition('mail', $like_string, 'LIKE')
        ->condition('name', $like_string, 'LIKE');

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
   * Ajax callback for searching user in a training classes.
   *
   * @param \Drupal\group\Entity\Group $group
   *   Group object.
   * @param string $class_id
   *   Class group ID.
   * @param string $uid
   *   User ID.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Ajax command or empty.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function findGroupMember(Group $group, $class_id, $uid) {
    $response = new AjaxResponse();

    if ($class_id === '0') {
      $content_types = [
        'group_content_type_27efa0097d858',
        'group_content_type_af9d804582e19',
        'learning_path-group_membership',
      ];

      $group_content_ids = \Drupal::entityQuery('group_content')
        ->condition('gid', $group->id())
        ->condition('type', $content_types, 'IN')
        ->sort('changed', 'DESC')
        ->execute();
      $content = \Drupal::entityTypeManager()
        ->getStorage('group_content')
        ->loadMultiple($group_content_ids);

      $users = [];
      $classes = [];

      /** @var \Drupal\group\Entity\GroupContentInterface $item */
      foreach ($content as $item) {
        $entity = $item->getEntity();
        if ($entity === NULL) {
          continue;
        }

        $type = $entity->getEntityTypeId();
        $bundle = $entity->bundle();

        if ($type === 'user') {
          $users[$entity->id()] = [
            'group content' => $item,
            'entity' => $entity,
          ];
        }
        elseif ($type === 'group' && $bundle === 'opigno_class') {
          $classes[$entity->id()] = [
            'group content' => $item,
            'entity' => $entity,
          ];
        }
      }

      if ($classes) {
        foreach ($classes as $class) {
          $view_id = 'opigno_group_members_table';
          $display = 'group_members_block';
          $args = [$class['entity']->id()];

          $members_view = Views::getView($view_id);
          if (is_object($members_view)) {
            $members_view->storage->set('group_members', array_keys($users));
            $members_view->setArguments($args);
            $members_view->setDisplay($display);
            $members_view->setItemsPerPage(0);
            $members_view->execute();
            if (!empty($members_view->result)) {
              foreach ($members_view->result as $key => $item) {
                $member = $item->_entity->getEntity();
                if ($member->id() == $uid) {
                  $display_default = $members_view->storage->getDisplay('default');
                  $per_page = $display_default["display_options"]["pager"]["options"]["items_per_page"];
                  $current_page = intdiv($key, $per_page);
                  $class_id = $class['entity']->id();
                  break 2;
                }
              }
            }
          }
        }

        if (isset($current_page)) {
          $selector = '#class-' . $class_id . ' .views-element-container';
          $members_view = Views::getView($view_id);
          if (is_object($members_view)) {
            $members_view->storage->set('group_members', array_keys($users));
            $members_view->setArguments($args);
            $members_view->setDisplay($display);
            $members_view->setCurrentPage($current_page);
            $members_view->preExecute();
            $members_view->execute();
            $members_view_renderable = $members_view->buildRenderable($display, $args);

            $response->addCommand(new ReplaceCommand($selector, $members_view_renderable));
          }
        }
      }
    }

    return $response;
  }

  /**
   * Ajax callback used in opigno_learning_path_member_overview.js.
   *
   * Removes member from learning path.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Response.
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

    $group->removeMember($user);
    return new JsonResponse();
  }

  /**
   * Ajax callback used in opigno_learning_path_member_overview.js.
   *
   * Removes class from learning path.
   */
  public function deleteClass() {
    /** @var \Drupal\group\Entity\Group $group */
    $group = \Drupal::routeMatch()->getParameter('group');

    $class_id = \Drupal::request()->query->get('class_id');
    $class = Group::load($class_id);

    if (!isset($group) || !isset($class)) {
      throw new NotFoundHttpException();
    }

    $content = $group->getContent();
    $account = $this->currentUser();

    /** @var \Drupal\group\Entity\GroupContentInterface $item */
    foreach ($content as $item) {
      $entity = $item->getEntity();
      $type = $entity->getEntityTypeId();
      $bundle = $entity->bundle();

      if ($type === 'group' && $bundle === 'opigno_class'
        && $entity->id() === $class->id()) {
        $item->delete();
        break;
      }
    }

    return new JsonResponse();
  }

  /**
   * Ajax callback used in opigno_learning_path_member_overview.js.
   *
   * Toggles user role in learning path.
   */
  public function toggleRole() {
    /** @var \Drupal\group\Entity\Group $group */
    $group = \Drupal::routeMatch()->getParameter('group');
    $query = \Drupal::request()->query;
    $uid = $query->get('uid');
    $user = User::load($uid);
    $role = $query->get('role');
    if (!isset($group) || !isset($user) || !isset($role)) {
      throw new NotFoundHttpException();
    }

    $member = $group->getMember($user);
    if (!isset($member)) {
      throw new NotFoundHttpException();
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
   * Ajax callback used in opigno_learning_path_member_overview.js.
   *
   * Validates user role in learning path.
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

      // Set notification.
      $message = $this->t('Enrollment validated to a new training "@name"', ['@name' => $group->label()]);
      $url = Url::fromRoute('entity.group.canonical', ['group' => $group->id()])->toString();
      opigno_set_message($uid, $message, $url);

      $config = \Drupal::config('opigno_learning_path.learning_path_settings');
      $send_to_users = $config->get('opigno_learning_path_notify_users');
      if ($send_to_users) {
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
        $args = [
          '@username' => $user->getDisplayName(),
          '@training' => $group->label(),
          ':link' => $link,
          '@link_text' => $link,
          '@platform' => $site_config->get('name'),
        ];
        $params['message'] = $this->t('Dear @username

Your membership to the training @training has been approved. You can now access this training at: <a href=":link">@link_text</a>

@platform', $args);

        \Drupal::service('plugin.manager.mail')
          ->mail($module, $key, $email, $lang, $params);
      }
    }

    return new JsonResponse();
  }

  /**
   * Callback from RouteSubscriber::alterRoutes() to alter group module members view page.
   * If group type is learning_path, loads form, otherwise loads the default group module members view.
   */
  public function alterGroupMembersRoute() {
    $group_id = \Drupal::routeMatch()->getRawParameter('group');
    if (!empty($group_id)) {
      $group = Group::load($group_id);
      if (!empty($group)) {
        if ($group->getGroupType()->id() == 'learning_path') {
          return \Drupal::formBuilder()->getForm('\Drupal\opigno_learning_path\Form\LearningPathMembersForm');
        }
      }
    }

    $view = Views::getView('group_members');
    if ($view) {
      $view->setDisplay('page_1');
      $view->initHandlers();
      return $view->buildRenderable('page_1', [$group_id]);
    }
  }

}
