<?php

namespace Drupal\opigno_learning_path\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\opigno_group_manager\Controller\OpignoGroupManagerController;
use Drupal\opigno_ilt\Entity\ILT;
use Drupal\opigno_learning_path\Entity\LPStatus;
use Drupal\opigno_learning_path\Progress;
use Drupal\opigno_module\Entity\OpignoActivity;
use Drupal\opigno_module\Entity\OpignoModule;
use Drupal\opigno_module\OpignoModuleBadges;
use Drupal\opigno_moxtra\Entity\Meeting;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class LearningPathAchievementController.
 */
class LearningPathAchievementController extends ControllerBase {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Progress bar service.
   *
   * @var \Drupal\opigno_learning_path\Progress
   */
  protected $progress;

  /**
   * Formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, Progress $progress, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->progress = $progress;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('opigno_learning_path.progress'),
      $container->get('date.formatter')
    );
  }

  /**
   * Returns max score that user can have in this module & activity.
   *
   * @param \Drupal\opigno_module\Entity\OpignoModule $module
   *   Module object.
   * @param \Drupal\opigno_module\Entity\OpignoActivity $activity
   *   Activity object.
   *
   * @return int
   *   Max score.
   */
  protected function get_activity_max_score($module, $activity) {
    $moduleHandler = \Drupal::service('module_handler');

    if ($moduleHandler->moduleExists('opigno_skills_system') && $module->getSkillsActive() && $module->getModuleSkillsGlobal()) {
      $query = $this->database->select('opigno_module_relationship', 'omr')
        ->fields('omr', ['max_score'])
        ->condition('omr.child_id', $activity->id())
        ->condition('omr.child_vid', $activity->getRevisionId())
        ->condition('omr.activity_status', 1);
      $results = $query->execute()->fetchAll();
    }
    else {
      $query = $this->database->select('opigno_module_relationship', 'omr')
        ->fields('omr', ['max_score'])
        ->condition('omr.parent_id', $module->id())
        ->condition('omr.parent_vid', $module->getRevisionId())
        ->condition('omr.child_id', $activity->id())
        ->condition('omr.child_vid', $activity->getRevisionId())
        ->condition('omr.activity_status', 1);
      $results = $query->execute()->fetchAll();
    }

    if (empty($results)) {
      return 0;
    }

    $result = reset($results);
    return $result->max_score;
  }

  /**
   * Returns step renderable array.
   *
   * @param array $step
   *   Step.
   *
   * @return array
   *   Step renderable array.
   */
  protected function build_step_name(array $step) {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['lp_step_name'],
      ],
      [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['lp_step_name_title'],
        ],
        '#value' => $step['name'],
      ],
      [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['lp_step_name_activities'],
        ],
        '#value' => ' &dash; ' . $step['activities'] . ' Activities',
      ],
    ];
  }

  /**
   * Returns step score renderable array.
   *
   * @param array $step
   *   Step.
   *
   * @return array
   *   Step score renderable array.
   */
  protected function build_step_score(array $step) {
    $uid = $this->currentUser()->id();

    if (opigno_learning_path_is_attempted($step, $uid)) {
      $score = $step['best score'];

      return [
        '#type' => 'container',
        [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $score . '%',
        ],
        [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['lp_step_result_bar'],
          ],
          [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => [
              'class' => ['lp_step_result_bar_value'],
              'style' => "width: $score%",
            ],
            '#value' => '',
          ],
        ],
      ];
    }

    return ['#markup' => '&nbsp;'];
  }

  /**
   * Returns step state renderable array.
   *
   * @param array $step
   *   Step.
   *
   * @return array
   *   Step state renderable array.
   */
  protected function build_step_state(array $step) {
    $uid = $this->currentUser()->id();
    $status = opigno_learning_path_get_step_status($step, $uid);
    $markups = [
      'pending' => '<span class="lp_step_state_pending"></span>'
      . t('Pending'),
      'failed' => '<span class="lp_step_state_failed"></span>'
      . t('Failed'),
      'passed' => '<span class="lp_step_state_passed"></span>'
      . t('Passed'),
    ];
    $markup = isset($markups[$status]) ? $markups[$status] : '&dash;';
    return ['#markup' => $markup];
  }

  /**
   * Returns module panel renderable array.
   *
   * @param \Drupal\group\Entity\GroupInterface $training
   *   Group.
   * @param null|\Drupal\group\Entity\GroupInterface $course
   *   Group.
   * @param \Drupal\opigno_module\Entity\OpignoModule $module
   *   Module.
   *
   * @return array
   *   Module panel renderable array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function build_module_panel(GroupInterface $training, GroupInterface $course = NULL, OpignoModule $module, AccountInterface $account = NULL) {
    /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
    $date_formatter = \Drupal::service('date.formatter');
    $user = $this->currentUser($account);
    $moduleHandler = \Drupal::service('module_handler');

    // Get training latest certification timestamp.
    $latest_cert_date = LPStatus::getTrainingStartDate($training, $user->id());

    $parent = isset($course) ? $course : $training;
    // It will keep the parent id (training) as the group context for the course page.
    $update_group_context = !isset($course);
    $step = opigno_learning_path_get_module_step($parent->id(), $user->id(), $module, $latest_cert_date, $update_group_context);
    $completed_on = $step['completed on'];
    $completed_on = $completed_on > 0
      ? $date_formatter->format($completed_on, 'custom', 'F d, Y')
      : '';

    /** @var \Drupal\opigno_module\Entity\OpignoModule $module */
    $module = OpignoModule::load($step['id']);
    /** @var \Drupal\opigno_module\Entity\UserModuleStatus[] $attempts */
    $attempts = $module->getModuleAttempts($user, NULL, $latest_cert_date);

    if ($moduleHandler->moduleExists('opigno_skills_system') && $module->getSkillsActive() && $module->getModuleSkillsGlobal() && !empty($attempts)) {
      $activities_from_module = $module->getModuleActivities();
      $activity_ids = array_keys($activities_from_module);
      $attempt = $this->getTargetAttempt($attempts, $module);

      $db_connection = \Drupal::service('database');
      $query = $db_connection->select('opigno_answer_field_data', 'o_a_f_d');
      $query->leftJoin('opigno_module_relationship', 'o_m_r', 'o_a_f_d.activity = o_m_r.child_id');
      $query->addExpression('MAX(o_a_f_d.activity)', 'id');
      $query->condition('o_a_f_d.user_id', $user->id())
        ->condition('o_a_f_d.module', $module->id());

      if (!$module->getModuleSkillsGlobal()) {
        $query->condition('o_a_f_d.activity', $activity_ids, 'IN');
      }

      $query->condition('o_a_f_d.user_module_status', $attempt->id())
        ->groupBy('o_a_f_d.activity');

      $activities = $query->execute()->fetchAllAssoc('id');
    }
    else {
      $activities = $module->getModuleActivities();
    }
    /** @var \Drupal\opigno_module\Entity\OpignoActivity[] $activities */
    $activities = array_map(function ($activity) {
      /** @var \Drupal\opigno_module\Entity\OpignoActivity $activity */
      return OpignoActivity::load($activity->id);
    }, $activities);

    if (!empty($attempts)) {
      // If "newest" score - get the last attempt,
      // else - get the best attempt.
      $attempt = $this->getTargetAttempt($attempts, $module);
      $max_score = $attempt->calculateMaxScore();
      $score_percent = $attempt->getAttemptScore();
      $score = round($score_percent * $max_score / 100);
    }
    else {
      $attempt = NULL;
      $max_score = !empty($activities)
        ? array_sum(array_map(function ($activity) use ($module) {
          return (int) $this->get_activity_max_score($module, $activity);
        }, $activities))
        : 0;
      $score_percent = 0;
      $score = 0;
    }
    $activities_done = 0;
    $activities = array_map(function ($activity) use ($user, $module, $attempt, &$activities_done) {
      /** @var \Drupal\opigno_module\Entity\OpignoActivity $activity */
      /** @var \Drupal\opigno_module\Entity\OpignoAnswer $answer */
      $answer = isset($attempt)
        ? $activity->getUserAnswer($module, $attempt, $user)
        : NULL;
      $score = isset($answer) ? $answer->getScore() : 0;
      $max_score = (int) $this->get_activity_max_score($module, $activity);

      if ($max_score == 0 && $activity->get('auto_skills')->getValue()[0]['value'] == 1) {
        $max_score = 10;
      }

      if ($answer && $activity->hasField('opigno_evaluation_method') && $activity->get('opigno_evaluation_method')->value && !$answer->isEvaluated()) {
        $state_class = 'pending';
      }
      else {
        $state_class = isset($answer) ? ($answer->isEvaluated() ? 'passed' : 'failed') : ('pending');
      }

      if ($state_class == 'passed') {
        $activities_done++;
      }
      return [
        [
          'class' => 'name',
          'data' => $activity->getName(),
        ],
        [
          'class' => 'progress',
          'data' => [
            '#markup' => $score . '/' . $max_score,
          ],
        ],
        [
          'class' => 'status',
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<div class="status-wrapper"><span class="led {{state_class}}">{{state_label}}</span></div>',
            '#context' => [
              'state_class' => $state_class,
              'state_label' => [
                'passed' => $this->t('Done'),
                'pending' => $this->t('Pending'),
                'failed' => $this->t('Failed'),
              ][$state_class],
            ],
          ],
        ],
      ];
    }, $activities);

    $activities = [
      '#type' => 'table',
      '#attributes' => [
        'class' => ['statistics-table'],
      ],
      '#header' => [
        $this->t('Activity'),
        $this->t('Score'),
        $this->t('Status'),
      ],
      '#rows' => $activities,
    ];

    $training_id = $training->id();
    $module_id = $module->id();
    if (isset($course)) {
      $course_id = $course->id();
      $id = "module_panel_${training_id}_${course_id}_${module_id}";
    }
    else {
      $id = "module_panel_${training_id}_${module_id}";
    }
    $info_items = [
      [
        '#type' => 'inline_template',
        '#template' => '<div class="activity-info__item"><div class="label">{{"Activities Done"|t}}</div><div class="value"><span>{{activities_done}}/{{activities}}</span></div></div>',
        '#context' => [
          'activities_done' => (int) $activities_done,
          'activities' => (int) $step["activities"],
        ],
      ],
      [
        '#type' => 'inline_template',
        '#template' => '<div class="activity-info__item"><div class="label">{{"Score"|t}}</div><div class="value"><span>{{score}}/{{max_score}}</span></div></div>',
        '#context' => [
          'score' => (int) $score,
          'max_score' => (int) $max_score,
        ],
      ],
    ];
    if ($module && $attempt) {
      $see_activity = Link::createFromRoute(
        $this->t('See activity results'),
        'opigno_module.module_result',
        [
          'opigno_module' => $module->id(),
          'user_module_status' => $attempt->id(),
        ],
        ['query' => ['skip-links' => TRUE]]
      )->toRenderable();
      $see_activity['#attributes'] = [
        'class' => 'btn btn-rounded btn-small',
      ];
    }
    return [
      '#type' => 'container',
      'activities' => $activities,
      'info_items' => $info_items,
      'link' => $see_activity ?? [],
    ];
  }

  /**
   * Returns module approved activities.
   *
   * @param int $parent
   *   Group ID.
   * @param int $module
   *   Module ID.
   *
   * @return int
   *   Approved activities.
   */
  protected function module_approved_activities($parent, $module, $latest_cert_date = NULL, AccountInterface $account = NULL) {
    $approved = 0;
    $user = $this->currentUser($account);
    $parent = Group::load($parent);
    $module = OpignoModule::load($module);
    $moduleHandler = \Drupal::service('module_handler');

    $step = opigno_learning_path_get_module_step($parent->id(), $user->id(), $module, $latest_cert_date);

    /** @var \Drupal\opigno_module\Entity\OpignoModule $module */
    $module = OpignoModule::load($step['id']);
    /** @var \Drupal\opigno_module\Entity\UserModuleStatus[] $attempts */
    $attempts = $module->getModuleAttempts($user, NULL, $latest_cert_date);

    if ($moduleHandler->moduleExists('opigno_skills_system') && $module->getSkillsActive() && $module->getModuleSkillsGlobal() && !empty($attempts)) {
      $activities_from_module = $module->getModuleActivities();
      $activity_ids = array_keys($activities_from_module);
      $attempt = $this->getTargetAttempt($attempts, $module);

      $db_connection = \Drupal::service('database');
      $query = $db_connection->select('opigno_answer_field_data', 'o_a_f_d');
      $query->leftJoin('opigno_module_relationship', 'o_m_r', 'o_a_f_d.activity = o_m_r.child_id');
      $query->addExpression('MAX(o_a_f_d.activity)', 'id');
      $query->condition('o_a_f_d.user_id', $user->id())
        ->condition('o_a_f_d.module', $module->id());

      if (!$module->getModuleSkillsGlobal()) {
        $query->condition('o_a_f_d.activity', $activity_ids, 'IN');
      }

      $query->condition('o_a_f_d.user_module_status', $attempt->id())
        ->condition('o_m_r.max_score', '', '<>')
        ->groupBy('o_a_f_d.activity');

      $activities = $query->execute()->fetchAllAssoc('id');
    }
    else {
      $activities = $module->getModuleActivities();
    }
    /** @var \Drupal\opigno_module\Entity\OpignoActivity[] $activities */
    $activities = array_map(function ($activity) {
      /** @var \Drupal\opigno_module\Entity\OpignoActivity $activity */
      return OpignoActivity::load($activity->id);
    }, $activities);

    if (!empty($attempts)) {
      // If "newest" score - get the last attempt,
      // else - get the best attempt.
      $attempt = $this->getTargetAttempt($attempts, $module);
      $max_score = $attempt->calculateMaxScore();
      $score_percent = $attempt->getAttemptScore();
      $score = round($score_percent * $max_score / 100);
    }
    else {
      $attempt = NULL;
      $max_score = !empty($activities)
        ? array_sum(array_map(function ($activity) use ($module) {
          return (int) $this->get_activity_max_score($module, $activity);
        }, $activities))
        : 0;
      $score_percent = 0;
      $score = 0;
    }

    $activities = array_map(function ($activity) use ($user, $module, $attempt) {
      /** @var \Drupal\opigno_module\Entity\OpignoActivity $activity */
      /** @var \Drupal\opigno_module\Entity\OpignoAnswer $answer */
      $answer = isset($attempt)
        ? $activity->getUserAnswer($module, $attempt, $user)
        : NULL;
      $score = isset($answer) ? $answer->getScore() : 0;
      $max_score = (int) $this->get_activity_max_score($module, $activity);

      return [
        isset($answer) ? 'lp_step_state_passed' : 'lp_step_state_failed',
      ];
    }, $activities);

    foreach ($activities as $activity) {

      if ($activity[0] == 'lp_step_state_passed') {
        $approved++;
      }
    }

    return $approved;
  }

  /**
   * Returns course steps renderable array.
   *
   * @param \Drupal\group\Entity\GroupInterface $training
   *   Parent training group entity.
   * @param \Drupal\group\Entity\GroupInterface $course
   *   Course group entity.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   User account entity.
   *
   * @return array
   *   Course steps renderable array.
   */
  protected function build_course_steps(GroupInterface $training, GroupInterface $course, AccountInterface $account = NULL) {
    $user = $this->currentUser($account);
    $steps = opigno_learning_path_get_steps($course->id(), $user->id());

    // Get training latest certification timestamp.
    $latest_cert_date = LPStatus::getTrainingStartDate($training, $user->id());

    $steps = array_map(static function ($step) use ($user, $latest_cert_date) {
      $step['status'] = opigno_learning_path_get_step_status($step, $user->id(), TRUE, $latest_cert_date);
      $step['attempted'] = opigno_learning_path_is_attempted($step, $user->id());
      $step['progress'] = opigno_learning_path_get_step_progress($step, $user->id(), FALSE, $latest_cert_date);
      return $step;
    }, $steps);

    $course_steps = array_map(function ($step) use ($training, $course, $user, $latest_cert_date) {
      $time_spent = $this->getTimeSpentByStep($step);
      $completed = $this->getComplitedByStep($step);
      $time_spent = $time_spent ? $this->dateFormatter->formatInterval($time_spent) : 0;
      $completed = $completed ? $this->dateFormatter->format($completed, 'custom', 'm/d/Y') : '';
      list($approved, $approved_percent) = $this->getApprovedModuleByStep($step, $user, $latest_cert_date, $training);
      $badges = $this->getModulesStatusBadges($step, $training, $user->id());

      /** @var \Drupal\opigno_module\Entity\OpignoModule $module */
      $module = OpignoModule::load($step['id']);
      return [
        '#theme' => 'opigno_learning_path_training_module',
        '#status' => $this->mapStatusToTemplateClasses($step['status']),
        '#group_id' => $course->id(),
        '#step' => $step,
        '#time_spent' => $time_spent,
        '#completed' => $completed,
        '#badges' => $badges,
        '#approved' => [
          'value' => $approved,
          'percent' => $approved_percent,
        ],
        '#activities' => $this->build_module_panel($training, $course, $module, $user),
      ];
    }, $steps);

    return [
      '#theme' => 'opigno_learning_path_training_course_content',
      '#course_id' => $course->id(),
      $course_steps,
    ];
  }

  /**
   * Returns course passed steps.
   *
   * @param \Drupal\group\Entity\GroupInterface $training
   *   Parent training group entity.
   * @param \Drupal\group\Entity\GroupInterface $course
   *   Course group entity.
   *
   * @return array
   *   Course passed steps.
   */
  protected function course_steps_passed(GroupInterface $training, GroupInterface $course, $latest_cert_date = NULL, $user = NULL) {
    $user = $user ?: $this->currentUser();
    $steps = opigno_learning_path_get_steps($course->id(), $user->id(), NULL, $latest_cert_date);

    $passed = 0;
    foreach ($steps as $step) {
      $status = opigno_learning_path_get_step_status($step, $user->id(), FALSE, $latest_cert_date);
      if ($status == 'passed') {
        $passed++;
      }
    }

    return [
      'passed' => $passed,
      'total' => count($steps),
    ];
  }

  /**
   * Matching a status to a class for template rendering.
   */
  public function mapStatusToTemplateClasses($status): array {
    $steps_status = [
      'pending' => [
        'class' => 'in progress',
        'title' => $this->t('Pending'),
      ],
      'failed' => [
        'class' => 'failed',
        'title' => $this->t('Failed'),
      ],
      'passed' => [
        'class' => 'passed',
        'title' => $this->t('Passed'),
      ],
    ];
    return $steps_status[$status] ?? $steps_status['pending'];
  }

  /**
   * Gets an approved state by the step.
   *
   * Copy of legacy code.
   */
  public function getApprovedModuleByStep(&$step, $user, $latest_cert_date, $group): array {
    $module = OpignoModule::load($step['id']);
    $moduleHandler = \Drupal::service('module_handler');
    if ($moduleHandler->moduleExists('opigno_skills_system') && $module->getSkillsActive()) {
      $attempts = $module->getModuleAttempts($user, NULL, $latest_cert_date);
      $attempt = $this->getTargetAttempt($attempts, $module);

      $account = User::load($attempt->getOwnerId());
      $answers = $module->userAnswers($account, $attempt);
      $count_answers_from_step = count($answers);

      $approved = $count_answers_from_step . '/' . $count_answers_from_step;
      $approved_percent = 100;

      $step['progress'] = 1;
    }
    else {
      $approved_activities = $this->module_approved_activities($group->id(), $step['id'], $latest_cert_date, $user);
      $approved = $approved_activities . '/' . $step['activities'];
      $approved_percent = $approved_activities / $step['activities'] * 100;
    }
    return [
      $approved ?? 0,
      $approved_percent ?? 0,
    ];
  }

  /**
   * Gets an passed/percent of course state by the step.
   *
   * Copy of legacy code.
   */
  public function getStatusPercentCourseByStep($step, $latest_cert_date, $group, $user): array {
    $course_steps = $this->course_steps_passed($group, Group::load($step['id']), $latest_cert_date, $user);
    $passed = $course_steps['passed'] . '/' . $course_steps['total'];
    $passed_percent = round(($course_steps['passed'] / $course_steps['total']) * 100);
    $score = $step['best score'];
    $score_percent = $score;
    return [
      $passed,
      $passed_percent,
      $score_percent,
    ];
  }

  /**
   * Gets the badges by the step.
   *
   * Copy of legacy code.
   */
  public function getModulesStatusBadges($step, $group, $uid) {
    // Get existing badge count.
    $badges = 0;
    if (
      in_array($step['typology'], ['Course', 'Module']) &&
      \Drupal::moduleHandler()->moduleExists('opigno_module')
    ) {
      $result = OpignoModuleBadges::opignoModuleGetBadges($uid, $group->id(), $step['typology'], $step['id']);
      if ($result) {
        $badges = $result;
      }
    }
    return $badges;
  }

  /**
   * Returns LP steps.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return array
   *   LP steps.
   */
  protected function build_lp_steps(GroupInterface $group, AccountInterface $account = NULL) {
    $user = $this->currentUser($account);
    $uid = $user->id();

    // Get training latest certification timestamp.
    $latest_cert_date = LPStatus::getTrainingStartDate($group, $uid);

    // Get training guided navigation option.
    $freeNavigation = !OpignoGroupManagerController::getGuidedNavigation($group);
    if ($freeNavigation) {
      // Get all steps for LP.
      $steps = opigno_learning_path_get_all_steps($group->id(), $uid, NULL, $latest_cert_date);
    }
    else {
      // Get guided steps.
      $steps = opigno_learning_path_get_steps($group->id(), $uid, NULL, $latest_cert_date);
    }

    $steps = array_filter($steps, function ($step) use ($user) {
      if ($step['typology'] === 'Meeting') {
        // If the user have not the collaborative features role.
        if (!$user->hasPermission('view meeting entities')) {
          return FALSE;
        }

        // If the user is not a member of the meeting.
        /** @var \Drupal\opigno_moxtra\MeetingInterface $meeting */
        $meeting = \Drupal::entityTypeManager()
          ->getStorage('opigno_moxtra_meeting')
          ->load($step['id']);
        if (!$meeting->isMember($user->id())) {
          return FALSE;
        }
      }
      elseif ($step['typology'] === 'ILT') {
        // If the user is not a member of the ILT.
        /** @var \Drupal\opigno_ilt\ILTInterface $ilt */
        $ilt = \Drupal::entityTypeManager()
          ->getStorage('opigno_ilt')
          ->load($step['id']);
        if (!$ilt->isMember($user->id())) {
          return FALSE;
        }
      }

      return TRUE;
    });

    $steps = array_map(static function ($step) use ($uid, $latest_cert_date) {
      $step['status'] = opigno_learning_path_get_step_status($step, $uid, TRUE, $latest_cert_date);
      $step['attempted'] = opigno_learning_path_is_attempted($step, $uid);
      $step['progress'] = opigno_learning_path_get_step_progress($step, $uid, FALSE, $latest_cert_date);
      return $step;
    }, $steps);

    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'training_steps_' . $group->id(),
      ],
      array_map(function ($step) use ($group, $user, $latest_cert_date) {
        return [
          '#theme' => 'opigno_learning_path_training_step',
          '#step' => $step,
          '#is_module' => $this->isModule($step),
          $this->trainingStepContentBuild($step, $group, $user, $latest_cert_date),
        ];
      }, $steps),
    ];
  }

  /**
   *
   */
  protected function currentUser(AccountInterface $account = NULL) {
    if ($account) {
      return $account;
    }
    return parent::currentUser();
  }

  /**
   * Returns training timeline.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return array
   *   Training timeline.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function build_training_timeline(GroupInterface $group) {
    /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
    $date_formatter = \Drupal::service('date.formatter');
    $user = $this->currentUser();

    $latest_cert_date = LPStatus::getTrainingStartDate($group, $user->id());

    $result = (int) $this->database
      ->select('opigno_learning_path_achievements', 'a')
      ->fields('a')
      ->condition('uid', $user->id())
      ->condition('gid', $group->id())
      ->condition('status', 'completed')
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($latest_cert_date || $result === 0) {
      // If training is not completed, generate steps.
      $steps = opigno_learning_path_get_steps($group->id(), $user->id(), NULL, $latest_cert_date);
      $steps = array_filter($steps, function ($step) {
        return $step['mandatory'];
      });
      $steps = array_filter($steps, function ($step) use ($user) {
        if ($step['typology'] === 'Meeting') {
          // If the user have not the collaborative features role.
          if (!$user->hasPermission('view meeting entities')) {
            return FALSE;
          }

          // If the user is not a member of the meeting.
          /** @var \Drupal\opigno_moxtra\MeetingInterface $meeting */
          $meeting = \Drupal::entityTypeManager()
            ->getStorage('opigno_moxtra_meeting')
            ->load($step['id']);
          if (!$meeting->isMember($user->id())) {
            return FALSE;
          }
        }
        elseif ($step['typology'] === 'ILT') {
          // If the user is not a member of the ILT.
          /** @var \Drupal\opigno_ilt\ILTInterface $ilt */
          $ilt = \Drupal::entityTypeManager()
            ->getStorage('opigno_ilt')
            ->load($step['id']);
          if (!$ilt->isMember($user->id())) {
            return FALSE;
          }
        }

        return TRUE;
      });
      $steps = array_map(function ($step) use ($user, $latest_cert_date, $group) {
        $status = opigno_learning_path_get_step_status($step, $user->id(), TRUE, $latest_cert_date, $group->id());
        if ($status == 'passed') {
          $step['passed'] = opigno_learning_path_is_passed($step, $user->id());
        }
        return $step;
      }, $steps);
    }
    else {
      // Load steps from cache table.
      $results = $this->database
        ->select('opigno_learning_path_step_achievements', 'a')
        ->fields('a', [
          'name', 'status', 'completed', 'typology', 'entity_id',
        ])
        ->condition('uid', $user->id())
        ->condition('gid', $group->id())
        ->condition('mandatory', 1)
        ->execute()
        ->fetchAll();

      $steps = array_map(function ($result) {
        // Convert datetime string to timestamp.
        if (isset($result->completed)) {
          $completed = DrupalDateTime::createFromFormat(DrupalDateTime::FORMAT, $result->completed);
          $completed_timestamp = $completed->getTimestamp();
        }
        else {
          $completed_timestamp = 0;
        }

        return [
          'name' => $result->name,
          'passed' => $result->status === 'passed',
          'completed on' => $completed_timestamp,
          'typology' => $result->typology,
          'id' => $result->entity_id,
        ];
      }, $results);
    }

    $items = [];
    foreach ($steps as $step) {
      $items[] = [
        'label' => $step['name'],
        'completed_on' => $step['completed on'] > 0 ?
          $date_formatter->format($step['completed on'], 'custom', 'F d, Y') : '',
        'status' => opigno_learning_path_get_step_status($step, $user->id(), TRUE, $latest_cert_date, $group->id()),
      ];
    }

    return [
      '#theme' => 'opigno_learning_path_training_timeline',
      '#steps' => $items,
    ];
  }

  /**
   * Returns training summary.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return array
   *   Training summary.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function build_training_summary(GroupInterface $group, AccountInterface $account = NULL) {
    $gid = $group->id();
    $uid = $this->currentUser($account)->id();
    return $this->progress->getProgressAjaxContainer($gid, $uid, '', 'achievements-page', TRUE);
  }

  /**
   * Returns training array.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return array
   *   Training array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function build_training(GroupInterface $group = NULL, AccountInterface $account = NULL) {
    return [
      '#theme' => 'opigno_learning_path_training',
      '#label' => $group->label(),
      'summary' => $this->build_training_summary($group, $account),
      'details' => $this->build_lp_steps($group, $account),
      'image' => $group->get('field_learning_path_media_image')->view([
        'label' => 'hidden',
        'type' => 'media_thumbnail',
        'settings' => [
          'image_style' => 'catalog_image',
        ],
      ]),
    ];
  }

  /**
   * Loads module panel with a AJAX.
   *
   * @param \Drupal\group\Entity\GroupInterface $training
   *   Training group.
   * @param null|\Drupal\group\Entity\GroupInterface $course
   *   Course group.
   * @param \Drupal\opigno_module\Entity\OpignoModule $opigno_module
   *   Opigno module.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Response.
   */
  public function course_module_panel_ajax(GroupInterface $training, GroupInterface $course, OpignoModule $opigno_module) {
    $training_id = $training->id();
    $course_id = $course->id();
    $module_id = $opigno_module->id();
    $selector = "#module_panel_${training_id}_${course_id}_${module_id}";
    $content = $this->build_module_panel($training, $course, $opigno_module);
    $content['#attributes']['data-ajax-loaded'] = TRUE;
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand($selector, $content));
    return $response;
  }

  /**
   * Loads module panel with a AJAX.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   * @param \Drupal\opigno_module\Entity\OpignoModule $opigno_module
   *   Opigno module.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Response.
   */
  public function training_module_panel_ajax(GroupInterface $group, OpignoModule $opigno_module) {
    $training_id = $group->id();
    $module_id = $opigno_module->id();
    $selector = "#module_panel_${training_id}_${module_id}";
    $content = $this->build_module_panel($group, NULL, $opigno_module);
    $content['#attributes']['data-ajax-loaded'] = TRUE;
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand($selector, $content));
    return $response;
  }

  /**
   * Loads steps for a training with a AJAX.
   *
   * @param \Drupal\group\Entity\Group $group
   *   Group.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Response.
   */
  public function training_steps_ajax(Group $group) {
    $selector = '#training_steps_' . $group->id();
    $content = $this->build_lp_steps($group);
    $content['#attributes']['data-ajax-loaded'] = TRUE;
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand($selector, $content));
    return $response;
  }

  /**
   * Checks training progress access.
   */
  public function buildTrainingProgressAccess(AccountInterface $account) {
    /** @var \Drupal\opigno_social\Services\UserAccessManager $user_access_manager */
    $user_access_manager = \Drupal::service('opigno_social.user_access_manager');
    return AccessResult::allowedIf($account->id() > 0 && $user_access_manager->canAccessUserStatistics($account));
  }

  /**
   * Get the training progress page title.
   *
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The group entity to get statistics for.
   * @param \Drupal\user\UserInterface|null $account
   *   The user account to get statistics for.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The training progress page title.
   */
  public function buildTrainingProgressTitle(?GroupInterface $group = NULL, ?UserInterface $account = NULL): TranslatableMarkup {
    return $account instanceof UserInterface && (int) $this->currentUser()->id() !== (int) $account->id()
      ? $this->t('My training progress - @user', ['@user' => $account->getDisplayName()])
      : $this->t('My training progress');
  }

  /**
   * Returns training progress page.
   */
  public function buildTrainingProgress($group = NULL, $account = NULL) {
    return array_map(function ($group) use ($account) {
      return $this->build_training($group, $account);
    }, [$group]);
  }

  /**
   * Returns training page array.
   *
   * @param int $page
   *   Page id.
   *
   * @return array
   *   Training page array.
   */
  protected function build_page($page = 0) {
    $per_page = 5;

    $user = $account = $this->currentUser();
    $uid = $user->id();

    $query = $this->database->select('group_content_field_data', 'gc');
    $query->innerJoin(
      'groups_field_data',
      'g',
      'g.id = gc.gid'
    );
    // Opigno Module group content.
    $query->leftJoin(
      'group_content_field_data',
      'gc2',
      'gc2.gid = gc.gid AND gc2.type = \'group_content_type_162f6c7e7c4fa\''
    );
    $query->leftJoin(
      'opigno_group_content',
      'ogc',
      'ogc.entity_id = gc2.entity_id AND ogc.is_mandatory = 1'
    );
    $query->leftJoin(
      'user_module_status',
      'ums',
      'ums.user_id = gc.uid AND ums.module = gc2.entity_id'
    );
    $query->addExpression('max(ums.started)', 'started');
    $query->addExpression('max(ums.finished)', 'finished');
    $gids = $query->fields('gc', ['gid'])
      ->condition('gc.type', 'learning_path-group_membership')
      ->condition('gc.entity_id', $uid)
      ->groupBy('gc.gid')
      ->orderBy('finished', 'DESC')
      ->orderBy('started', 'DESC')
      ->orderBy('gc.gid', 'DESC')
      ->range($page * $per_page, $per_page)
      ->execute()
      ->fetchCol();
    $groups = Group::loadMultiple($gids);

    return array_map(function ($group) use ($account) {
      return $this->build_training($group, $account);
    }, $groups);
  }

  /**
   * Get last or best user attempt for Module.
   *
   * @param array $attempts
   *   User module attempts.
   * @param \Drupal\opigno_module\Entity\OpignoModule $module
   *   Module.
   *
   * @return \Drupal\opigno_module\Entity\UserModuleStatus
   *   $attempt
   */
  protected function getTargetAttempt(array $attempts, OpignoModule $module) {
    if ($module->getKeepResultsOption() == 'newest') {
      $attempt = end($attempts);
    }
    else {
      $attempt = opigno_learning_path_best_attempt($attempts);
    }

    return $attempt;
  }

  /**
   * Loads next achievements page with a AJAX.
   *
   * @param int $page
   *   Page id.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Response.
   */
  public function page_ajax($page = 0) {
    $selector = '#achievements-wrapper';

    $content = $this->build_page($page);

    $response = new AjaxResponse();
    if (!empty($content)) {
      $response->addCommand(new AppendCommand($selector, $content));
    }
    return $response;
  }

  /**
   * Returns index array.
   *
   * @param int $page
   *   Page id.
   *
   * @return array
   *   Index array.
   */
  public function index($page = 0) {
    $content = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'achievements-wrapper',
      ],
      [
        '#theme' => 'opigno_learning_path_message',
        '#markup' => t('Consult your results and download the certificates for the trainings.'),
      ],
      '#attached' => [
        'library' => [
          'opigno_learning_path/achievements',
          'opigno_learning_path/achievements_slick',
        ],
      ],
    ];

    $content[] = $this->build_page($page);
    return $content;
  }

  /**
   * Prepares a render array of content.
   */
  protected function trainingStepContentBuild($step, $group, $user, $latest_cert_date = NULL): array {
    $build = [];
    switch ($step['typology']) {
      case 'Module':
        return $this->trainingStepModuleBuild($step, $group, $user, $latest_cert_date);

      case 'Course':
        return $this->trainingStepCourseBuild($step, $group, $user, $latest_cert_date);

      case 'ILT':
        return $this->trainingStepIltBuild($step, $group, $user, $latest_cert_date);

      case 'Meeting':
        return $this->trainingStepMeetingBuild($step, $group, $user, $latest_cert_date);
    }
    return $build;
  }

  /**
   * If step is module prepares a render array of content.
   */
  protected function trainingStepModuleBuild($step, $group, $user, $latest_cert_date = NULL): array {
    $time_spent = $this->getTimeSpentByStep($step);
    $completed = $this->getComplitedByStep($step);
    $time_spent = $time_spent ? $this->dateFormatter->formatInterval($time_spent) : 0;
    $completed = $completed ? $this->dateFormatter->format($completed, 'custom', 'm/d/Y') : '';
    $completed = $completed ?: '';
    list(
      $approved,
      $approved_percent,
      ) = $this->getApprovedModuleByStep($step, $user, $latest_cert_date, $group);
    $badges = $this->getModulesStatusBadges($step, $group, $user->id());

    /** @var \Drupal\opigno_module\Entity\OpignoModule $module */
    $module = OpignoModule::load($step['id']);
    return [
      '#theme' => 'opigno_learning_path_training_module',
      '#status' => $this->mapStatusToTemplateClasses($step['status']),
      '#group_id' => $group->id(),
      '#step' => $step,
      '#time_spent' => $time_spent,
      '#completed' => $completed,
      '#badges' => $badges,
      '#approved' => [
        'value' => $approved,
        'percent' => $approved_percent,
      ],
      '#activities' => $this->build_module_panel($group, NULL, $module, $user),
    ];
  }

  /**
   * If step is course prepares a render array of content.
   */
  protected function trainingStepCourseBuild($step, $group, $user, $latest_cert_date = NULL): array {
    $time_spent = $this->getTimeSpentByStep($step);
    $completed = $this->getComplitedByStep($step);
    $time_spent = $time_spent ? $this->dateFormatter->formatInterval($time_spent) : 0;
    $completed = $completed ? $this->dateFormatter->format($completed, 'custom', 'm/d/Y') : '';
    $badges = $this->getModulesStatusBadges($step, $group, $user->id());
    list($passed, $passed_percent, $score_percent) = $this->getStatusPercentCourseByStep($step, $latest_cert_date, $group, $user);
    return [
      '#type' => 'container',
      '#attributes' => [],
      [
        '#theme' => 'opigno_learning_path_training_course',
        '#passed' => [
          'value' => $passed,
          'percent' => $passed_percent,
        ],
        '#score' => $score_percent,
        '#step' => $step,
        '#completed' => $completed,
        '#badges' => $badges,
        '#time_spent' => $time_spent,
      ],
      $this->build_course_steps($group, Group::load($step['id']), $user),
    ];
  }

  /**
   * Prepare render array for ILT step.
   */
  private function trainingStepIltBuild($step, $group, $user, $latest_cert_date) {

    // If the user is not a member of the meeting.
    /** @var \Drupal\opigno_ilt\Entity\ILT $ilt */
    $ilt = \Drupal::entityTypeManager()
      ->getStorage('opigno_ilt')
      ->load($step['id']);

    if (!($ilt instanceof  ILT)) {
      return [];
    }
    if (!($valid_unix = strtotime($ilt->getStartDate()))) {
      return [];
    }
    $date = $valid_unix ? $this->dateFormatter->format($valid_unix, 'custom', 'm/d/Y') : '';
    return [
      '#theme' => 'opigno_learning_path_training_ilt',
      '#date' => $date,
      '#status' => $this->mapStatusToTemplateClasses($step['status']),
      '#attended' => $step["attempted"] ? $this->t('Yes') : $this->t('No'),
      '#step' => $step,
      '#place' => $ilt->getPlace(),
      '#approved' => [
        'value' => $step["presence"],
        'percent' => $step["progress"],
      ],
    ];
  }

  /**
   * Prepare render array for Meeting step.
   */
  private function trainingStepMeetingBuild($step, $group, $user, $latest_cert_date) {

    // If the user is not a member of the meeting.
    /** @var \Drupal\opigno_moxtra\Entity\Meeting $meeting */
    $meeting = \Drupal::entityTypeManager()
      ->getStorage('opigno_moxtra_meeting')
      ->load($step['id']);
    if (!($meeting instanceof  Meeting)) {
      return [];
    }
    if (!($valid_unix = strtotime($meeting->getStartDate()))) {
      return [];
    }
    $date = $valid_unix ? $this->dateFormatter->format($valid_unix, 'custom', 'm/d/Y') : '';
    return [
      '#theme' => 'opigno_learning_path_training_meeting',
      '#date' => $date,
      '#status' => $this->mapStatusToTemplateClasses($step['status']),
      '#attended' => $step["attempted"] ? $this->t('Yes') : $this->t('No'),
      '#step' => $step,
      '#place' => $meeting->toLink()->toRenderable(),
      '#approved' => [
        'value' => $step["presence"],
        'percent' => $step["progress"],
      ],
    ];
  }

  /**
   * Checks if step is module.
   */
  protected function isModule($step): bool {
    return $step['typology'] == 'Module';
  }

  /**
   * Time spent if module is attempted.
   */
  protected function getTimeSpentByStep($step) {
    return (isset($step['attempted']) && $step['time spent'] > 0) ? $step['time spent'] : FALSE;
  }

  /**
   * Completed if module is attempted.
   */
  protected function getComplitedByStep($step) {
    return (isset($step['attempted']) && $step['completed on'] > 0) ? $step['completed on'] : FALSE;
  }

}
