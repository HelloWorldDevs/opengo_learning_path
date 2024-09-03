<?php

namespace Drupal\opigno_learning_path\TwigExtension;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\opigno_learning_path\Controller\LearningPathController;
use Drupal\opigno_learning_path\Entity\LPStatus;
use Drupal\opigno_learning_path\LearningPathAccess;
use Drupal\opigno_learning_path\Traits\LearningPathAchievementTrait;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Class DefaultTwigExtension.
 */
class DefaultTwigExtension extends AbstractExtension {

  use LearningPathAchievementTrait;

  /**
   * {@inheritdoc}
   */
  public function getTokenParsers() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getNodeVisitors() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getTests() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction(
        'is_group_member',
        [$this, 'is_group_member']
      ),
      new TwigFunction(
        'get_join_group_link',
        [$this, 'get_join_group_link']
      ),
      new TwigFunction(
        'get_start_link',
        [$this, 'get_start_link']
      ),
      new TwigFunction(
        'get_progress',
        [$this, 'get_progress']
      ),
      new TwigFunction(
        'get_training_content',
        [$this, 'get_training_content']
      ),
      new TwigFunction(
        'opigno_modules_counter',
        [$this, 'opigno_modules_counter']
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOperators() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'opigno_learning_path.twig.extension';
  }

  /**
   * Tests if user is member of a group.
   *
   * @param mixed $group
   *   Group.
   * @param mixed $account
   *   User account.
   *
   * @return bool
   *   Member flag.
   */
  public function is_group_member($group = NULL, $account = NULL) {
    if (!$group) {
      $group = \Drupal::routeMatch()->getParameter('group');
    }

    if (empty($group)) {
      return FALSE;
    }

    if (!$account) {
      $account = \Drupal::currentUser();
    }

    return $group->getMember($account) !== FALSE;
  }

  /**
   * Returns join group link.
   *
   * @param mixed $group
   *   Group.
   * @param mixed $account
   *   User account.
   * @param array $attributes
   *   Attributes.
   *
   * @return mixed|null|string
   *   Join group link or empty.
   */
  public function get_join_group_link($group = NULL, $account = NULL, array $attributes = []) {
    $route = \Drupal::routeMatch();

    if (!isset($group)) {
      $group = $route->getParameter('group');
    }

    if (!isset($account)) {
      $account = \Drupal::currentUser();
    }

    $route_name = $route->getRouteName();
    $visibility = $group->field_learning_path_visibility->value;
    $access = isset($group) && $group->access('view', $account) && ($group->hasPermission('join group', $account) || $visibility == 'public' || $visibility == 'semiprivate');

    // If training is paid.
    $is_member = $group->getMember($account) !== FALSE;
    $module_commerce_enabled = \Drupal::moduleHandler()->moduleExists('opigno_commerce');
    if ($module_commerce_enabled
      && $group->hasField('field_lp_price')
      && $group->get('field_lp_price')->value != 0
      && !$is_member) {

      return '';
    }

    if ($route_name == 'entity.group.canonical' && $access) {
      $link = NULL;
      $validation = LearningPathAccess::requiredValidation($group, $account);
      $is_anonymous = $account->id() === 0;

      if ($visibility == 'semiprivate' && $validation) {
        $joinLabel = t('Request subscription to the training');
      }
      else {
        $joinLabel = t('Subscribe to training');
      }

      if ($is_anonymous) {
        if ($visibility === 'public') {
          $link = [
            'title' => t('Start'),
            'route' => 'opigno_learning_path.steps.start',
            'args' => ['group' => $group->id()],
          ];
          $attributes['class'][] = 'use-ajax';
        }
        else {
          $url = Url::fromRoute('entity.group.canonical', ['group' => $group->id()]);
          $link = [
            'title' => $joinLabel,
            'route' => 'user.login',
            'args' => ['destination' => \Drupal::service('renderer')->render($url)->toString()],
          ];
        }
      }
      elseif (!$is_member) {
        $link = [
          'title' => $joinLabel,
          'route' => 'entity.group.join',
          'args' => ['group' => $group->id()],
        ];
      }

    if ($route_name == 'entity.group.canonical' && $is_anonymous && $visibility == 'semiprivate') {
      $url = Url::fromRoute('entity.group.canonical', ['group' => $group->id()]);
      $link = [
        'title' => t('Create an account and subscribe'),
        'route' => 'user.login',
        'args' => ['prev_path' => \Drupal::service('renderer')->render($url)->toString()],
      ];
    }

      if ($link) {
        $url = Url::fromRoute($link['route'], $link['args'], ['attributes' => $attributes]);
        $l = Link::fromTextAndUrl($link['title'], $url)->toRenderable();

        return \Drupal::service('renderer')->render($l);
      }
    }

    return '';
  }

  /**
   * Returns groupstart link.
   *
   * @param mixed $group
   *   Group.
   * @param array $attributes
   *   Attributes.
   *
   * @return array|mixed|null
   *   Group start link or empty.
   */
  public function get_start_link($group = NULL, array $attributes = [], $one_button = FALSE) {
    if (!$group) {
      $group = \Drupal::routeMatch()->getParameter('group');
    }

    if (filter_var($group, FILTER_VALIDATE_INT) !== FALSE) {
      $group = Group::load($group);
    }

    if (empty($group) || (!is_object($group)) || (is_object($group) && $group->bundle() !== 'learning_path')) {
      return [];
    }

    $args = [];
    $current_route = \Drupal::routeMatch()->getRouteName();
    $visibility = $group->field_learning_path_visibility->value;
    $account = \Drupal::currentUser();
    $is_anonymous = $account->id() === 0;
    if ($is_anonymous && $visibility != 'public') {
      if ($visibility != 'semiprivate'
            && (!$group->hasField('field_lp_price')
            || $group->get('field_lp_price')->value == 0)) {
        return [];
      }
    }

    // Check if we need to wait validation.
    $validation = LearningPathAccess::requiredValidation($group, $account);
    $member_pending = !LearningPathAccess::statusGroupValidation($group, $account);
    $module_commerce_enabled = \Drupal::moduleHandler()->moduleExists('opigno_commerce');
    $required_trainings = LearningPathAccess::hasUncompletedRequiredTrainings($group, $account);

    if (
      $module_commerce_enabled
      && $group->hasField('field_lp_price')
      && $group->get('field_lp_price')->value != 0
      && !$group->getMember($account)) {
      // Get currency code.
      $cs = \Drupal::service('commerce_store.current_store');
      $store_default = $cs->getStore();
      $default_currency = $store_default ? $store_default->getDefaultCurrencyCode() : '';
      $top_text = $group->get('field_lp_price')->value . ' ' . $default_currency;
      $top_text = [
        '#type' => 'inline_template',
        '#template' => '<div class="top-text price">{{top_text}}</div>',
        '#context' => [
          'top_text' => $top_text ?? '',
        ],
      ];
      $text = t('Buy');
      $attributes['class'][] = 'btn-bg';
      $route = 'opigno_commerce.subscribe_with_payment';
    }
    elseif ($visibility === 'public' && $is_anonymous) {
      $text = t('Start');
      $route = 'opigno_learning_path.steps.start';
      $attributes['class'][] = 'use-ajax';
      $attributes['class'][] = 'start-link';
    }
    elseif (!$group->getMember($account) || $is_anonymous) {
      if ($group->hasPermission('join group', $account)) {
        if ($current_route == 'entity.group.canonical') {
          $text = $validation ? t('Request subscription') : t('Enroll');
          $attributes['class'][] = 'btn-bg';
          $attributes['data-toggle'][] = 'modal';
          $attributes['data-target'][] = '#join-group-form-overlay';
        }
        else {
          $text = t('Learn more');
        }

        $route = ($current_route == 'entity.group.canonical') ? 'entity.group.join' : 'entity.group.canonical';
        if ($current_route == 'entity.group.canonical') {
          $attributes['class'][] = 'join-link';
        }
      }
      elseif ($visibility === 'semiprivate' && $is_anonymous) {
        if ($current_route == 'entity.group.canonical') {
          $text = t('Create an account and subscribe');
          $route = 'user.login';
          $args += ['prev_path' => Url::fromRoute('entity.group.canonical', ['group' => $group->id()])->toString()];
        }
        else {
          $text = t('Learn more');
          $route = 'entity.group.canonical';
        }
      }
      else {
        return '';
      }
    }
    elseif ($member_pending || $required_trainings) {
      $route = 'entity.group.canonical';
      if ($required_trainings) {
        // Display only the icon for certain cases (for ex., on the catalog).
        if ($one_button) {
          $top_text = [
            '#markup' => Markup::create('<i class="fi fi-rr-lock"></i>'),
          ];
        }
        else {
          $links = [];
          foreach ($required_trainings as $gid) {
            $training = Group::load($gid);
            $url = Url::fromRoute($route, ['group' => $training->id()]);
            $link = Link::fromTextAndUrl($training->label(), $url)
              ->toRenderable();
            array_push($links, $link);
          }
          $top_text = $links;
          $top_text = [
            '#type' => 'inline_template',
            '#template' => '<div class="top-text complete"><i class="fi fi-rr-lock"></i><div>{{"Complete"|t}}<br>{{top_text}}<br>{{"before"|t}}</div></div>',
            '#context' => [
              'top_text' => \Drupal::service('renderer')->render($top_text) ?? '',
            ],
          ];
        }
      }
      else {
        // Display only the icon for certain cases (for ex., on the catalog).
        if ($one_button) {
          $top_text = [
            '#markup' => Markup::create('<i class="fi fi-rr-menu-dots"></i>'),
          ];
        }
        else {
          $top_text = [
            '#type' => 'inline_template',
            '#template' => '<div class="top-text approval"><i class="fi fi-rr-menu-dots"></i><div>{{top_text}}</div></div>',
            '#context' => [
              'top_text' => t('Approval Pending'),
            ],
          ];
        }
      }

      $text = t('Start');

      $attributes['class'][] = 'disabled';
      $attributes['class'][] = 'approval-pending-link';
    }
    else {
      $uid = $account->id();
      $expired = LPStatus::isCertificateExpired($group, $uid);
      $is_passed = opigno_learning_path_is_passed($group, $uid, $expired);
      $status_class = $is_passed ? 'passed' : 'pending';
      switch ($status_class) {
        case 'passed':
        case 'failed':
          $text = t('Restart');
          if (!$one_button) {
            $top_link_text = t('See result');
          }
          break;

        default:
          // Old implementation.
          if (opigno_learning_path_started($group, $account)) {
            if (!$one_button) {
              $top_link_text = t('See progress');
            }
            $text = t('Continue training');
          }
          else {
            $text = t('Start');
          }
      }

      $url = Url::fromRoute('opigno_learning_path.training', ['group' => $group->id()]);
      $top_text = isset($top_link_text) ? [
        '#type' => 'link',
        '#url' => $url,
        '#title' => $top_link_text,
        '#access' => $url->access($this->currentUser()),
        '#attributes' => [
          'class' => ['btn', 'btn-rounded', 'continue-link'],
        ],
      ] : [];

      $route = 'opigno_learning_path.steps.type_start';
      $attributes['class'][] = 'use-ajax';

      if (opigno_learning_path_started($group, $account)) {
        $attributes['class'][] = 'continue-link';
      }
      else {
        $attributes['class'][] = 'start-link';
      }
    }

    $type = $current_route === 'view.opigno_training_catalog.training_catalogue' ? 'catalog' : 'group';

    $args += ['group' => $group->id(), 'type' => $type];
    $url = Url::fromRoute($route, $args, ['attributes' => $attributes]);
    $l = Link::fromTextAndUrl($text, $url)->toRenderable();
    $content = [
      $top_text ?? [],
      $l,
    ];
    $cache = CacheableMetadata::createFromRenderArray($content);
    $cache->addCacheableDependency($group);
    $cache->addCacheableDependency($account);
    $cache->applyTo($content);
    return $content;
  }

  /**
   * Returns current user progress.
   *
   * @return array|mixed|null
   *   Current user progress.
   */
  public function get_progress($ajax = TRUE, $class = 'group-page', ?GroupInterface $group = NULL) {
    $group = $group ?: \Drupal::routeMatch()->getParameter('group');
    if (!$group instanceof GroupInterface) {
      return [];
    }

    $account = \Drupal::currentUser();
    $member_pending = !LearningPathAccess::statusGroupValidation($group, $account);
    $required_trainings = LearningPathAccess::hasUncompletedRequiredTrainings($group, $account);

    // Don't display the progress not all required trainings completed or the
    // membership approval is needed.
    if ($member_pending || $required_trainings) {
      return [];
    }

    /** @var \Drupal\opigno_learning_path\Progress $progress_service */
    $progress_service = \Drupal::service('opigno_learning_path.progress');
    if ($ajax) {
      $content = $progress_service->getProgressAjaxContainer($group->id(), $account->id(), '', $class);
    }
    else {
      $content = $progress_service->getProgressBuild($group->id(), $account->id(), '', $class);
    }

    $cache = CacheableMetadata::createFromRenderArray($content);
    $cache->addCacheableDependency($group);
    $cache->addCacheableDependency($account);
    $cache->applyTo($content);

    return ($content);
  }

  /**
   * Returns training content.
   *
   * @return mixed|null
   *   Training content.
   */
  public function get_training_content() {
    $controller = new LearningPathController();
    return $controller->trainingContent();
  }

  /**
   * Counter of modules by group.
   */
  public function opigno_modules_counter($group) {
    $steps = $this->getStepsByGroup($group);
    return Markup::create(count($steps));
  }

}
