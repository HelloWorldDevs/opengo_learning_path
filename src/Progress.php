<?php

namespace Drupal\opigno_learning_path;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\opigno_learning_path\Entity\LPResult;
use Drupal\opigno_learning_path\Entity\LPStatus;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class JoinService.
 */
class Progress {

  use StringTranslationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The database layer.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The RequestStack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new Progress object.
   */
  public function __construct(AccountInterface $current_user, $database, RequestStack $request_stack) {
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->requestStack = $request_stack;
  }

  /**
   * Calculates progress in a group for a user.
   *
   * @param int $group_id
   *   Group ID.
   * @param int $account_id
   *   User ID.
   * @param int|string $latest_cert_date
   *   Latest certification date.
   *
   * @return float
   *   Attempted activities count / total activities count.
   */
  public function getProgress(int $group_id, int $account_id, $latest_cert_date): float {
    $activities = opigno_learning_path_get_activities($group_id, $account_id, $latest_cert_date);

    $total = count($activities);
    $attempted = count(array_filter($activities, function ($activity) {
      return $activity['answers'] > 0;
    }));

    return $total > 0 ? $attempted / $total : 0;
  }

  /**
   * Check achievements data.
   *
   * It can be reused, but leave as it is for backward compatibility.
   *
   * @see \Drupal\opigno_learning_path\Progress::getProgressRound
   */
  public function getProgressAchievementsData($group_id, $account_id) {
    // Firstly check achievements data.
    $query = $this->database
      ->select('opigno_learning_path_achievements', 'a')
      ->fields('a')
      ->condition('a.gid', $group_id)
      ->condition('a.uid', $account_id);
    return $query->execute()->fetchAssoc();
  }

  /**
   * Get round integer of progress.
   *
   * @param int $group_id
   *   Group ID.
   * @param int $account_id
   *   User ID.
   * @param int|string $latest_cert_date
   *   Latest certification date.
   *
   * @return int
   *   Attempted activities count / total activities count.
   */
  public function getProgressRound(int $group_id, int $account_id, $latest_cert_date = 0): int {
    // Firstly check achievements' data.
    $query = $this->database
      ->select('opigno_learning_path_achievements', 'a')
      ->fields('a', ['progress'])
      ->condition('a.gid', $group_id)
      ->condition('a.uid', $account_id)
      ->condition('a.status', 'completed');

    $achievements_data = $query->execute()->fetchAssoc();

    if ($achievements_data) {
      return $achievements_data['progress'];
    }
    if (!$latest_cert_date) {
      $group = Group::load($group_id);
      $latest_cert_date = LPStatus::getTrainingStartDate($group, $account_id);
    }

    return round(100 * $this->getProgress($group_id, $account_id, $latest_cert_date));
  }

  /**
   * Get html container where progress will be loaded via ajax.
   *
   * @param int $group_id
   *   Group ID.
   * @param int $account_id
   *   User ID.
   * @param int|string $latest_cert_date
   *   Latest certification date.
   * @param string $class
   *   Identifier for progress bar.
   * @param bool $build_html
   *   If the HTML should be returned or not.
   *
   * @return array
   *   Renderable array.
   */
  public function getProgressAjaxContainer(int $group_id, int $account_id, $latest_cert_date = 0, string $class = 'basic', bool $build_html = FALSE): array {

    if (!$latest_cert_date) {
      $group = Group::load($group_id);
      $latest_cert_date = LPStatus::getTrainingStartDate($group, $account_id);
    }

    // If latest_cert_date is empty we just set 0 to avoid any errors for empty
    // args.
    if (!$latest_cert_date) {
      $latest_cert_date = 0;
    }

    // Maybe in some cases we need to have pre-loaded progress bar without ajax.
    // An example unit tests or so.
    $preload = $this->requestStack->getCurrentRequest()->query->get('preload-progress');
    if ($preload || $build_html) {
      return $this->getProgressBuild($group_id, $account_id, $latest_cert_date, $class);
    }

    // HTML structure for ajax container.
    return [
      '#theme' => 'opigno_learning_path_progress_ajax_container',
      '#group_id' => $group_id,
      '#account_id' => $account_id,
      '#latest_cert_date' => $latest_cert_date,
      '#class' => $class,
      '#attached' => ['library' => ['opigno_learning_path/progress']],
    ];
  }

  /**
   * Get get progress bar it self.
   *
   * @param int|string $group_id
   *   Group ID.
   * @param int|string $account_id
   *   User ID.
   * @param int|string $latest_cert_date
   *   Latest certification date.
   * @param string $class
   *   Identifier for progress bar.
   *
   * @return array|int
   *   Renderable array.
   */
  public function getProgressBuild($group_id, $account_id, $latest_cert_date, string $class) {
    // If $latest_cert_date argument is 0 than it means it empty.
    if ($latest_cert_date === 0) {
      $latest_cert_date = '';
    }

    // Progress should be shown only for member of group.
    $group = Group::load($group_id);
    $account = User::load($account_id);
    $existing = $group->getMember($account);
    if ($existing === FALSE) {
      $class = 'empty';
    }

    switch ($class) {
      case 'group-page':
        return $this->getProgressBuildGroupPage($group_id, $account_id, $latest_cert_date);

      case 'module-page':
        // @todo We can reuse a getProgressBuildGroupPage method.
        return $this->getProgressBuildModulePage($group_id, $account_id);

      case 'achievements-page':
        return $this->getProgressBuildAchievementsPage($group_id, $account_id, $latest_cert_date);

      case 'full':
      case 'mini':
        // Full: value, mini - with the progress bar.
        return [
          '#theme' => 'opigno_learning_path_progress',
          '#value' => $this->getProgressRound($group_id, $account_id, $latest_cert_date),
          '#show_bar' => $class === 'mini',
        ];

      case 'circle':
        return [
          '#theme' => 'lp_circle_progress',
          '#radius' => 31,
          '#progress' => $this->getProgressRound($group_id, $account_id, $latest_cert_date),
        ];

      case 'empty':
        // Empty progress.
        return ['#markup' => ''];

      default:
        // Only value.
        return $this->getProgressRound($group_id, $account_id, $latest_cert_date);
    }
  }

  /**
   * Get get progress for group page.
   *
   * @param int $group_id
   *   Group ID.
   * @param int $account_id
   *   User ID.
   * @param int|string $latest_cert_date
   *   Latest certification date.
   *
   * @return array
   *   Renderable array.
   */
  public function getProgressBuildGroupPage(int $group_id, int $account_id, $latest_cert_date): array {
    /** @var \Drupal\group\Entity\GroupInterface $group */
    $group = Group::load($group_id);
    $account = User::load($account_id);

    $date_formatter = \Drupal::service('date.formatter');

    $expiration_message = '';
    $expiration_set = LPStatus::isCertificateExpireSet($group);
    if ($expiration_set) {
      if ($expiration_message = LPStatus::getCertificateExpireTimestamp($group->id(), $account_id)) {
        $expiration_message = ' ' . $date_formatter->format($expiration_message, 'custom', 'F d, Y');
      }
    }

    // Get data from achievements.
    $query = $this->database
      ->select('opigno_learning_path_achievements', 'a')
      ->fields('a', ['score', 'progress', 'time', 'completed'])
      ->condition('a.gid', $group_id)
      ->condition('a.uid', $account_id)
      ->condition('a.status', 'completed');

    $achievements_data = $query->execute()->fetchAssoc();
    if ($achievements_data) {
      $score = $achievements_data['score'];
      $completed = $achievements_data['completed'];

      if ($achievements_data['completed']) {
        $format = 'Y-m-d H:i:s';
        $completed = DrupalDateTime::createFromFormat($format, $achievements_data['completed']);
        $completed = $completed->format('F d, Y');
      }

      $state = $this->t('Passed');
      $progress = $achievements_data['progress'];
      $is_passed = TRUE;
    }
    else {
      $score = opigno_learning_path_get_score($group_id, $account_id);
      $progress = $this->getProgressRound($group_id, $account_id, $latest_cert_date);
      $is_passed = opigno_learning_path_is_passed($group, $account_id);

      $completed = opigno_learning_path_completed_on($group_id, $account_id);
      $completed = $completed > 0
        ? $date_formatter->format($completed, 'custom', 'F d, Y')
        : '';

      $state = $is_passed ? $this->t('Passed') : $this->t('Failed');
    }

    if ($is_passed || $progress == 100) {
      // Expire message if necessary.
      if ($expiration_set) {
        // Expiration set, create expiration message.
        if ($expiration_message) {
          $expiration_message = ' - ' . $this->t('Valid until') . $expiration_message;
        }
      }

      $summary = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['lp_progress_summary'],
        ],
        // H2 Need for correct structure.
        [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Progress status'),
          '#attributes' => [
            'class' => ['sr-only'],
          ],
        ],
        [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => [
            'class' => $is_passed ? ['lp_progress_summary_passed'] : ['lp_progress_summary_failed'],
          ],
          '#value' => '',
        ],
        [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#attributes' => [
            'class' => ['lp_progress_summary_title'],
          ],
          '#value' => $state . $expiration_message,
        ],
        [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => [
            'class' => ['lp_progress_summary_score'],
          ],
          '#value' => $this->t('Average score : @score%', [
            '@score' => $score,
          ]),
        ],
        !empty($completed) ? [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => [
            'class' => ['lp_progress_summary_date'],
          ],
          '#value' => $this->t('Completed on @date', [
            '@date' => $completed,
          ]),
        ] : [],
      ];
    }
    elseif ($expiration_set && LPStatus::isCertificateExpired($group, $account_id)) {
      $summary = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['lp_progress_summary'],
        ],
        // H2 Need for correct structure.
        [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Progress status'),
          '#attributes' => [
            'class' => ['sr-only'],
          ],
        ],
        [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => [
            'class' => ['lp_progress_summary_expired'],
          ],
          '#value' => '',
        ],
        [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#attributes' => [
            'class' => ['lp_progress_summary_title'],
          ],
          '#value' => $this->t('Expired on') . ' ' . $expiration_message,
        ],
        [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => [
            'class' => ['lp_progress_summary_score'],
          ],
          '#value' => $this->t('Please start this training again to get new certification'),
        ],
      ];
    }

    $content_progress = [
      '#theme' => 'lp_progress',
      '#progress' => $progress,
      '#summary' => $this->buildSummary($group, $account),
    ];
    return $content_progress;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSummary($group, $account): array {
    $uid = $account->id();
    $gid = (int) $group->id();
    // Get user training expiration flag.
    $expired = LPStatus::isCertificateExpired($group, $uid);
    $status = $this->getProgressAchievementsData($gid, $uid);
    $result = LPResult::getCurrentLPAttempt($group, $account);
    $is_passed = opigno_learning_path_is_passed($group, $uid, $expired);

    return isset($result->started) ? [
      '#theme' => 'opigno_learning_path_step_block_progress',
      '#passed' => $is_passed,
      '#expired' => $expired,
      '#has_experation_date' => LPStatus::isCertificateExpireSet($group),
      '#expired_date' => LPStatus::getCertificateExpireTimestamp($gid, $uid),
      '#complite_date' => strtotime($status["registered"]) ?? 0,
      '#started_date' => $result->started->value ?? 0,
    ] : [];
  }

  /**
   * Get get progress for module page.
   *
   * @param int $group_id
   *   Group ID.
   * @param int $account_id
   *   User ID.
   *
   * @return array
   *   Renderable array.
   *
   * @opigno_deprecated
   */
  public function getProgressBuildModulePage(int $group_id, int $account_id): array {
    $home_link = Link::createFromRoute(Markup::create($this->t('home') . '<i class="icon-home-2"></i>'), 'entity.group.canonical', ['group' => $group_id], ['attributes' => ['class' => ['w-100']]])->toRenderable();
    $home_link = \Drupal::service('renderer')->render($home_link);

    $progress = $this->getProgressRound($group_id, $account_id);

    $build = [
      '#theme' => 'block__opigno_module_learning_path_progress_block',
      'content' => [
        // 'home_link' => $home_link,
        'progress' => $progress,
        'fullpage' => FALSE,
      ],
      '#configuration' => [
        'id' => 'opigno_module_learning_path_progress_block',
        'label' => 'Learning path progress',
        'provider' => 'opigno_module',
        'label_display' => '0',
      ],
      '#plugin_id' => 'opigno_module_learning_path_progress_block',
      '#base_plugin_id' => 'opigno_module_learning_path_progress_block',
      '#derivative_plugin_id' => NULL,
    ];

    return $build;
  }

  /**
   * Get get progress for achievements page.
   *
   * @param int $group_id
   *   Group ID.
   * @param int|string $account_id
   *   User ID.
   * @param int|string $latest_cert_date
   *   Latest certification date.
   *
   * @return array
   *   Renderable array.
   */
  public function getProgressBuildAchievementsPage(int $group_id, int $account_id, $latest_cert_date): array {
    $score = 0;
    $group = Group::load($group_id);
    $account = User::load($account_id);

    /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
    $date_formatter = \Drupal::service('date.formatter');

    /** @var \Drupal\group\Entity\GroupContent $member */
    $member = $group->getMember($account)->getGroupContent();
    $registration = $member->getCreatedTime();
    $registration = $date_formatter->format($registration, 'custom', 'm/d/Y');

    // Get data from achievements.
    $query = $this->database
      ->select('opigno_learning_path_achievements', 'a')
      ->fields('a', ['score', 'progress', 'time', 'completed'])
      ->condition('a.gid', $group_id)
      ->condition('a.uid', $account_id)
      ->condition('a.status', 'completed');

    $achievements_data = $query->execute()->fetchAssoc();

    if ($achievements_data) {
      if ($achievements_data['completed']) {
        $format = 'Y-m-d H:i:s';
        $completed = DrupalDateTime::createFromFormat($format, $achievements_data['completed']);
        $validation = $completed->format('F d, Y');
        $validation_date = $completed->format('m/d/Y');
      }

      if (isset($achievements_data['score'])) {
        $score = $achievements_data['score'];
      }

      if (isset($achievements_data['progress'])) {
        $progress = $achievements_data['progress'];
      }

      if ($achievements_data['time']) {
        $time_spent = $date_formatter->formatInterval($achievements_data['time']);
      }
    }
    else {
      $completed_on = opigno_learning_path_completed_on($group_id, $account_id, TRUE);
      $validation = $completed_on > 0
        ? $date_formatter->format($completed_on, 'custom', 'F d, Y')
        : '';
      $validation_date = $date_formatter->format($completed_on, 'custom', 'm/d/Y');

      $time_spent = opigno_learning_path_get_time_spent($group_id, $account_id);
      $time_spent = $date_formatter->formatInterval($time_spent);
      $score = round(opigno_learning_path_get_score($group_id, $account_id, FALSE, $latest_cert_date));
      $progress = $this->getProgressRound($group_id, $account_id, $latest_cert_date);
    }

    $expiration_message = '';
    $expiration_set = LPStatus::isCertificateExpireSet($group);
    $expired = FALSE;
    if ($expiration_set) {
      if ($expiration_timestamp = LPStatus::getCertificateExpireTimestamp($group->id(), $account_id)) {
        if (!LPStatus::isCertificateExpired($group, $account_id)) {
          $expiration_message = $this->t('Valid until');
        }
        else {
          $expired = TRUE;
          $expiration_message = $this->t('Expired on');
        }

        $expiration_message = $expiration_message . ' ' . $date_formatter->format($expiration_timestamp, 'custom', 'F d, Y');

        $valid_until = $date_formatter->format($expiration_timestamp, 'custom', 'm/d/Y');
      }
    }

    if ($achievements_data) {
      // Use cached result.
      $is_attempted = TRUE;
      $is_passed = TRUE;
    }
    else {
      // Check the actual data.
      $is_attempted = opigno_learning_path_is_attempted($group, $account_id);
      $is_passed = opigno_learning_path_is_passed($group, $account_id);
    }

    if ($is_passed) {
      $state_class = 'passed';
    }
    elseif ($progress == 100 && !opigno_learning_path_is_passed($group, $account_id)) {
      $state_class = 'failed';
    }
    elseif ($is_attempted) {
      $state_class = 'in progress';
    }
    elseif ($expired) {
      $state_class = 'expired';
    }
    else {
      $state_class = 'not started';
    }

    $validation_message = !empty($validation) ? $this->t('Validation date: @date<br />', ['@date' => $validation]) : '';

    $has_certificate = !$group->get('field_certificate')->isEmpty();

    return [
      '#theme' => 'opigno_learning_path_training_summary',
      '#progress' => $progress,
      '#score' => $score,
      '#group_id' => $group_id,
      '#has_certificate' => $has_certificate,
      '#is_passed' => $is_passed,
      '#state_class' => $state_class,
      '#registration_date' => $registration,
      '#validation_message' => $validation_message . $expiration_message,
      '#time_spend' => $time_spent,
      '#validation_date' => $validation_date,
      '#valid_until' => ($valid_until ?? ''),
      '#certificate_url' => $has_certificate && $is_passed ?
      Url::fromUri('internal:/certificate/group/' . $group_id . '/pdf') : FALSE,
    ];
  }

}
