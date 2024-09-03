<?php

namespace Drupal\opigno_learning_path\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\opigno_group_manager\Controller\OpignoGroupManagerController;
use Drupal\opigno_group_manager\Entity\OpignoGroupManagedContent;
use Drupal\opigno_group_manager\OpignoGroupContentTypesManager;
use Drupal\opigno_group_manager\OpignoGroupContext;
use Drupal\opigno_learning_path\Entity\LPResult;
use Drupal\opigno_learning_path\Form\DeleteAchievementsForm;
use Drupal\opigno_learning_path\LearningPathAccess;
use Drupal\opigno_learning_path\LearningPathValidator;
use Drupal\opigno_learning_path\LearningPathContent;
use Drupal\opigno_module\Entity\OpignoActivity;
use Drupal\opigno_module\Entity\OpignoModule;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class LearningPathStepsController.
 */
class LearningPathStepsController extends ControllerBase {

  protected $content_type_manager;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Type of source page.
   *
   * @var string
   */
  protected $source_type = 'group';

  /**
   * Group entity.
   *
   * @var Group
   */
  protected $group;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    OpignoGroupContentTypesManager $content_types_manager,
    Connection $database
  ) {
    $this->content_type_manager = $content_types_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('opigno_group_manager.content_types.manager'),
      $container->get('database')
    );
  }

  /**
   * Redirect to the group homepage.
   *
   * @param string $message
   * @param bool $is_ajax
   */
  protected function redirectToHome($message = '', $is_ajax = FALSE) {
    if (!empty($this->group) && !empty($this->source_type) && $this->source_type == 'catalog') {
      if ($is_ajax) {
        $url = Url::fromRoute('entity.group.canonical', ['group' => $this->group->id(), 'force' => 1]);
        return (new AjaxResponse())->addCommand(new RedirectCommand($url->toString()));
      }
      else {
        return $this->redirect('entity.group.canonical', ['group' => $this->group->id(), 'force' => 1]);
      }
    }
    return FALSE;
  }

  /**
   * Provide a default failed messages for the learning path.
   *
   * @param string $type
   * @param bool $modal
   * @param string $message
   *
   * @return mixed
   */
  protected function failedStep($type, $modal = FALSE, $message = '') {
    switch ($type) {
      case 'no_first':
        $message = $this->t('No first step assigned.');
        break;

      case 'no_url':
        $message = $this->t('No URL for the first step.');
        break;

      case 'no_score':
        $message = $this->t('No score provided');
        break;

      case 'passed':
        $message = $this->t('You passed!');
        break;

      case 'failed':
        $message = $this->t('You failed!');
        break;
    }

    if ($type !== 'none' && $redirect = $this->redirectToHome($message, $modal)) {
      return $redirect;
    }

    $content = [
      '#type' => 'html_tag',
      '#value' => $message,
      '#tag' => 'p',
    ];

    if ($modal) {
      return $this->showPopup($content);
    }
    else {
      return $content;
    }
  }

  /**
   * Show the AJAX popup.
   *
   * @param array $content
   *   The popup content.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response to show the popup.
   */
  public function showPopup(array $content): AjaxResponse {
    $build = [
      '#theme' => 'opigno_confirmation_popup',
      '#body' => $content,
    ];

    $response = new AjaxResponse();
    $response->addCommand(new RemoveCommand('.modal-ajax'));
    $response->addCommand(new AppendCommand('body', $build));
    $response->addCommand(new InvokeCommand('.modal-ajax', 'modal', ['show']));

    return $response;
  }

  /**
   * Start the learning path.
   *
   * This page will redirect the user to the first learning path content.
   */
  public function start(Group $group, $type = 'group') {
    // Create empty result attempt if current attempt doesn't exist.
    // Will be used to detect if user already started LP or not.
    $current_attempt = opigno_learning_path_started($group, \Drupal::currentUser());

    $visibility = $group->get('field_learning_path_visibility')->value;
    $this->source_type = $type;
    $this->group = $group;

    if (!$current_attempt) {
      $result = LPResult::createWithValues($group->id(),
        \Drupal::currentUser()
          ->id(), FALSE,
        0
      );
      $result->save();
    }

    $user = $this->currentUser();

    $uid = $user->id();
    $gid = $group->id();
    $is_owner = $uid === $group->getOwnerId();

    $is_ajax = \Drupal::request()->isXmlHttpRequest();

    // Load group steps.
    $steps = LearningPathContent::getAllStepsOnlyModules($gid, $uid);

    foreach ($steps as $step) {
      if (in_array($step['typology'], ['Meeting', 'ILT'])) {
        // If training starts with a mandatory live meeting
        // or instructor-led training, check requirements.
        $is_mandatory = $step['mandatory'] === 1;

        if ($step['typology'] === 'Meeting') {
          /** @var \Drupal\opigno_moxtra\MeetingInterface $entity */
          $entity = $this->entityTypeManager()
            ->getStorage('opigno_moxtra_meeting')
            ->load($step['id']);

          if (!$entity->isMember($uid)) {
            $is_mandatory = FALSE;
          }
        }
        elseif ($step['typology'] === 'ILT') {
          /** @var \Drupal\opigno_ilt\ILTInterface $entity */
          $entity = $this->entityTypeManager()
            ->getStorage('opigno_ilt')
            ->load($step['id']);

          if (!$entity->isMember($uid)) {
            $is_mandatory = FALSE;
          }
        }

        if ($is_mandatory) {
          $name = $step['name'];
          $required = $step['required score'];
          if ($required >= 0 || ($step['typology'] == 'Meeting' && !$is_owner)) {
            if ($step['best score'] < $required || OpignoGroupManagerController::mustBeVisitedMeeting($step, $group)) {
              $course_entity = OpignoGroupManagedContent::load($step['cid']);
              $course_content_type = $this->content_type_manager->createInstance(
                $course_entity->getGroupContentTypeId()
              );

              $current_step_url = $course_content_type->getStartContentUrl(
                $course_entity->getEntityId(),
                $gid
              );

              return $this->failedStep('none', $is_ajax, $this->requiredStepMessage($name, $required, $current_step_url->toString()));
            }
          }
          else {
            if ($step['attempts'] === 0) {
              return $this->failedStep('none', $is_ajax, $this->requiredStepMessage($name));
            }
          }
        }
      }
      else {
        break;
      }
    }

    // Check that training is completed.
    $is_completed = (int) $this->database
      ->select('opigno_learning_path_achievements', 'a')
      ->fields('a')
      ->condition('uid', $user->id())
      ->condition('gid', $group->id())
      ->condition('status', 'completed')
      ->countQuery()
      ->execute()
      ->fetchField() > 0;

    if ($is_completed) {
      // Load steps from cache table.
      $results = $this->database
        ->select('opigno_learning_path_step_achievements', 'a')
        ->fields('a', [
          'id',
          'typology',
          'entity_id',
          'parent_id',
          'position',
        ])
        ->condition('uid', $user->id())
        ->condition('gid', $group->id())
        ->execute()
        ->fetchAllAssoc('id');

      if (!empty($results)) {
        // Check training structure.
        $is_valid = TRUE;
        $steps_mandatory = array_filter($steps, function ($step) {
          return $step['mandatory'];
        });
        foreach ($steps_mandatory as $step) {
          $filtered = array_filter($results, function ($result) use ($results, $step) {
            if (isset($step['parent'])) {
              $step_parent = $step['parent'];
              $result_parent = isset($results[$result->parent_id])
                ? $results[$result->parent_id] : NULL;
              if (!isset($result_parent)
                || $result_parent->typology !== $step_parent['typology']
                || (int) $result_parent->entity_id !== (int) $step_parent['id']
                || (int) $result_parent->position !== (int) $step_parent['position']) {
                return FALSE;
              }
            }

            return $result->typology === $step['typology']
              && (int) $result->entity_id === (int) $step['id']
              && (int) $result->position === (int) $step['position'];
          });

          if (count($filtered) !== 1) {
            $is_valid = FALSE;
            break;
          }
        }

        // If training is changed.
        if (!$is_valid) {
          $form_state = new FormState();
          $form_state->addBuildInfo('args', [$group]);
          $form = $this->formBuilder()->buildForm(DeleteAchievementsForm::class, $form_state);
          if ($is_ajax) {
            $redirect = $this->redirectToHome('', TRUE);
            if ($redirect) {
              return $redirect;
            }
            else {
              return $this->showPopup($form);
            }
          }
          else {
            return $form;
          }
        }
      }
    }

    // Get training guided navigation option.
    $freeNavigation = !OpignoGroupManagerController::getGuidedNavigation($group);
    if ($freeNavigation) {
      $content = OpignoGroupManagedContent::getFirstStep($group->id());
      if ($content->getGroupContentTypeId() != 'ContentTypeCourse') {
        $content_type = $this->content_type_manager->createInstance($content->getGroupContentTypeId());
        $step_url = $content_type->getStartContentUrl($content->getEntityId(), $group->id());
        if ($is_ajax) {
          return (new AjaxResponse())->addCommand(new RedirectCommand($step_url->toString()));
        }
        else {
          return $this->redirect($step_url->getRouteName(), $step_url->getRouteParameters(), $step_url->getOptions());
        }
      }
    }

    // Check if there is resumed step. If is - redirect.
    // We disallow resuming for anonymous user if a training is public.
    $step_resumed_cid = ($visibility === 'public' && $uid == 0) ? FALSE : opigno_learning_path_resumed_step($steps);
    if ($step_resumed_cid) {
      $content = OpignoGroupManagedContent::load($step_resumed_cid);
      if (in_array($content->getGroupContentTypeId(), [
        'ContentTypeMeeting',
        'ContentTypeILT',
      ])) {
        for ($i = 0; $i < count($steps); ++$i) {
          if (intval($steps[$i]['cid']) == $step_resumed_cid) {
            // Set resumed step which is next to the meeting.
            $step_resumed_cid = $steps[$i + 1]['cid'];
            $content = OpignoGroupManagedContent::load($step_resumed_cid);
            break;
          };
        }
      }
      // Find and load the content type linked to this content.
      $content_type = $this->content_type_manager->createInstance($content->getGroupContentTypeId());
      $step_url = $content_type->getStartContentUrl($content->getEntityId(), $group->id());
      // Before redirecting, keep the content ID in context.
      OpignoGroupContext::setCurrentContentId($step_resumed_cid);
      OpignoGroupContext::setGroupId($group->id());
      // Finally, redirect the user to the first step.
      if ($is_ajax) {
        return (new AjaxResponse())->addCommand(new RedirectCommand($step_url->toString()));
      }
      else {
        return $this->redirect($step_url->getRouteName(), $step_url->getRouteParameters(), $step_url->getOptions());
      }
    };

    // Get the first step of the learning path. If no steps, show a message.
    $first_step = reset($steps);
    if ($first_step === FALSE) {
      return $this->failedStep('no_first', $is_ajax);
    }

    // Load first step entity.
    $first_step = OpignoGroupManagedContent::load($first_step['cid']);

    // Find and load the content type linked to this content.
    $content_type = $this->content_type_manager->createInstance($first_step->getGroupContentTypeId());

    // Finally, get the "start" URL
    // If no URL, show a message.
    $step_url = $content_type->getStartContentUrl($first_step->getEntityId(), $group->id());
    if (empty($step_url)) {
      return $this->failedStep('no_url', $is_ajax);
    }

    // Before redirecting, keep the content ID in context.
    OpignoGroupContext::setCurrentContentId($first_step->id());
    OpignoGroupContext::setGroupId($group->id());

    // Finally, redirect the user to the first step.
    if ($is_ajax) {
      return (new AjaxResponse())->addCommand(new RedirectCommand($step_url->toString()));
    }
    else {
      return $this->redirect($step_url->getRouteName(), $step_url->getRouteParameters(), $step_url->getOptions());
    }
  }

  /**
   * Redirect the user to the next step.
   */
  public function getNextStep(Group $group, OpignoGroupManagedContent $parent_content, $content_update = TRUE) {
    // Get the user score of the parent content.
    // First, get the content type object of the parent content.
    $content_type = $this->content_type_manager->createInstance($parent_content->getGroupContentTypeId());
    $user_score = $content_type->getUserScore(\Drupal::currentUser()
      ->id(), $parent_content->getEntityId());

    // If no no score and content is mandatory, show a message.
    if ($user_score === FALSE && $parent_content->isMandatory()) {
      return $this->failedStep('no_score');
    }

    $user = $this->currentUser();
    $uid = $user->id();
    $gid = $group->id();
    $is_owner = $uid === $group->getOwnerId();
    $cid = $parent_content->id();

    // Get training guided navigation option.
    $freeNavigation = !OpignoGroupManagerController::getGuidedNavigation($group);

    // Load group steps.
    if ($freeNavigation) {
      // Get all steps for LP.
      $steps = LearningPathContent::getAllStepsOnlyModules($gid, $uid, TRUE);
    }
    else {
      $steps = LearningPathContent::getAllStepsOnlyModules($gid, $uid);
    }


    // Find current & next step.
    $count = count($steps);
    $current_step = NULL;
    $current_step_index = 0;
    for ($i = 0; $i < $count - 1; ++$i) {
      if ($steps[$i]['cid'] === $cid || (!$freeNavigation && ($steps[$i]['required score'] > $steps[$i]['best score']))) {
        $current_step_index = $i;
        $current_step = $steps[$i];
        break;
      }
    }

    // Check mandatory step requirements.
    if (!$freeNavigation && isset($current_step) && $current_step['mandatory'] === 1) {
      $name = $current_step['name'];
      $required = $current_step['required score'];
      if ($required >= 0 || $current_step['typology'] == 'Meeting') {
        // Check if it's "skills module" with skills which user is already passed.
        if ($current_step['typology'] == 'Module') {
          $module = \Drupal::entityTypeManager()
            ->getStorage('opigno_module')
            ->load($current_step['id']);
          $moduleHandler = \Drupal::service('module_handler');

          if ($moduleHandler->moduleExists('opigno_skills_system') && $module->getSkillsActive()) {
            $current_step['current attempt score'] = $current_step['best score'];
          }
        }

        if (($current_step['mandatory'] && !$current_step['attempts']) ||
          ($current_step['mandatory'] && $current_step['attempts'] && empty($current_step['completed on'])) ||
          $current_step['best score'] < $required ||
          OpignoGroupManagerController::mustBeVisitedMeeting($current_step, $group) && !$is_owner) {

          $course_entity = OpignoGroupManagedContent::load($current_step['cid']);
          $course_content_type = $this->content_type_manager->createInstance(
            $course_entity->getGroupContentTypeId()
          );
          $current_step_url = $course_content_type->getStartContentUrl(
            $course_entity->getEntityId(),
            $gid
          );

          // Message if current step score less than required.
          $message = $this->requiredStepMessage($name, $required, $current_step_url->toString());
          $message = $this->failedStep('none', FALSE, $message);

          // Check if current step is module and has activities
          // with manual evaluation which haven't been evaluated yet.
          if ($current_step['typology'] == 'Module') {
            $module = OpignoModule::load($current_step['id']);
            if (!empty($module)) {
              $activities = $module->getModuleActivities();
              $activities = array_map(function ($activity) {
                return OpignoActivity::load($activity->id);
              }, $activities);

              $attempts = $module->getModuleAttempts($user);
              if (!empty($attempts)) {
                // If "newest" score - get the last attempt,
                // else - get the best attempt.
                $attempt = $this->getTargetAttempt($attempts, $module);
              }
              else {
                $attempt = NULL;
              }

              if ($activities) {
                foreach ($activities as $activity) {
                  $answer = isset($attempt) ? $activity->getUserAnswer($module, $attempt, $user) : NULL;
                  if ($answer && $activity->hasField('opigno_evaluation_method') && $activity->get('opigno_evaluation_method')->value && !$answer->isEvaluated()) {
                    // Message if current step is module and has activities
                    // with manual evaluation which haven't been evaluated yet.
                    $training_url = Url::fromRoute('entity.group.canonical', ['group' => $group->id()]);
                    $message = $this->t('One or several activities in module %step require a manual grading. You will be allowed to continue the training as soon as these activities have been graded and if you reach the minimum score %required.<br /><a href=":link">Back to training homepage.</a>', [
                      '%step' => $name,
                      '%required' => $required,
                      ':link' => $training_url->toString(),
                    ]);

                    $message = $this->failedStep('none', FALSE, $message);
                    break;
                  }
                }
              }
            }
          }
          if($content_update) {
            $this->setGroupAndContext($group->id(), $current_step['cid']);
          }
          return $message;
        }
      }
    }

    if (isset($current_step['is last child']) && $current_step['is last child']
      && isset($current_step['parent'])) {
      $course = $current_step['parent'];
      // Check mandatory course requirements.
      if ($course['mandatory'] === 1) {
        $name = $course['name'];
        $required = $course['required score'];
        if ($required >= 0) {
          if ($course['best score'] < $required) {
            $module_content = OpignoGroupManagedContent::getFirstStep($course['id']);
            $module_content_type = $this->content_type_manager->createInstance(
              $module_content->getGroupContentTypeId()
            );
            $module_url = $module_content_type->getStartContentUrl(
              $module_content->getEntityId(),
              $gid
            );

            if($content_update) {
              $this->setGroupAndContext($group->id(), $module_content->id());
            }
            return $this->failedStep('none', FALSE, $this->requiredStepMessage($name, $required, $module_url->toString()));

          }
        }
        else {
          if ($course['attempts'] === 0) {
            $module_content = OpignoGroupManagedContent::getFirstStep($course['id']);

            if($content_update) {
              $this->setGroupAndContext($group->id(), $module_content->id());
            }
            return $this->failedStep('none', FALSE, $this->requiredStepMessage($name));
          }
        }
      }
    }

    // Skip live meetings and instructor-led trainings.
    $skip_types = []; //['Meeting', 'ILT'];
    for ($next_step_index = $current_step_index + 1;
    $next_step_index < $count
    && in_array($steps[$next_step_index]['typology'], $skip_types);
    ++$next_step_index) {
      $next_step = $steps[$next_step_index];
      $is_mandatory = $next_step['mandatory'] === 1;

      if ($next_step['typology'] === 'Meeting') {
        /** @var \Drupal\opigno_moxtra\MeetingInterface $entity */
        $entity = $this->entityTypeManager()
          ->getStorage('opigno_moxtra_meeting')
          ->load($next_step['id']);

        if (!$entity->isMember($uid)) {
          $is_mandatory = FALSE;
        }
      }
      elseif ($next_step['typology'] === 'ILT') {
        /** @var \Drupal\opigno_ilt\ILTInterface $entity */
        $entity = $this->entityTypeManager()
          ->getStorage('opigno_ilt')
          ->load($next_step['id']);

        if (!$entity->isMember($uid)) {
          $is_mandatory = FALSE;
        }
      }

      if ($is_mandatory) {
        $name = $next_step['name'];
        $required = $next_step['required score'];
        // But if the live meeting or instructor-led training is
        // a mandatory and not passed,
        // block access to the next step.
        if ($required > 0) {
          if ($next_step['best score'] < $required) {
            return $this->failedStep('none', FALSE, $this->requiredStepMessage($name, $required));
          }
        }
        else {
          if ($next_step['attempts'] === 0) {
            return $this->failedStep('none', FALSE, $this->requiredStepMessage($name));
          }
        }
      }
    }

    return $steps[$next_step_index] ?? NULL;
  }

  /**
   * Sets a groupd and content.
   */
  public function setGroupAndContext($group_id, $content_id) {
    OpignoGroupContext::setGroupId($group_id);
    OpignoGroupContext::setCurrentContentId($content_id);
  }

  /**
   * Redirect the user to the next step.
   */
  public function nextStep(Group $group, OpignoGroupManagedContent $parent_content, $content_update = TRUE) {
    $next_step = $this->getNextStep($group, $parent_content, $content_update);
    // If there is no next step, show a message.
    if ($next_step === NULL) {
      // Redirect to training home page.
      $this->messenger()->addWarning($this->t('You reached the last content of that training.'));
      return $this->redirect('entity.group.canonical', ['group' => $group->id()]);
    }
    if(!isset($next_step['cid'])) {
      return $next_step;
    }
    // Load next step entity.
    $next_step = OpignoGroupManagedContent::load($next_step['cid']);

    // Before redirect, change the content context.
    if ($content_update) {
      $this->setGroupAndContext($group->id(), $next_step->id());
    }
    // Finally, redirect the user to the next step URL.
    $next_step_content_type = $this->content_type_manager->createInstance($next_step->getGroupContentTypeId());
    $next_step_url = $next_step_content_type->getStartContentUrl($next_step->getEntityId(), $group->id());
    return $this->redirect($next_step_url->getRouteName(), $next_step_url->getRouteParameters(), $next_step_url->getOptions());
  }

  /**
   * Show the finish page and save the score.
   */
  public function finish(Group $group) {
    // Get the "user passed" status.
    $current_uid = \Drupal::currentUser()->id();
    $user_passed = LearningPathValidator::userHasPassed($current_uid, $group);

    if ($user_passed) {

      // Store the result in database.
      $current_result_attempt = LPResult::getCurrentLPAttempt($group, \Drupal::currentUser());
      if ($current_result_attempt) {
        $current_result_attempt->setHasPassed($user_passed);
        $current_result_attempt->setFinished(\Drupal::time()->getRequestTime());
        $current_result_attempt->save();
      }
      else {
        // Store the result in database.
        $result = LPResult::createWithValues($group->id(), $current_uid, $user_passed, \Drupal::time()->getRequestTime());
        $result->save();
      }

      return $this->failedStep('passed');
    }
    else {
      return $this->failedStep('failed');
    }
  }

  /**
   * Steps.
   */
  public function contentSteps(Group $group, $current) {
    // Check if user has uncompleted steps.
    LearningPathValidator::stepsValidate($group);
    // Get group type.
    $type = opigno_learning_path_get_group_type();
    // Get all steps.
    $all_steps = opigno_learning_path_get_routes_steps();
    // Get unique steps numbers.
    $unique_steps = array_unique($all_steps);
    // Get next step.
    $next_step = ($current < count($unique_steps)) ? $current + 1 : NULL;
    // If last step.
    if (!$next_step) {
      if ($type == 'learning_path') {
        return $this->redirect('opigno_learning_path.manager.publish', ['group' => $group->id()]);
      }
      else {
        // For courses and classes.
        return $this->redirect('entity.group.canonical', ['group' => $group->id()]);
      }
    }
    // If not last step.
    else {
      if ($type == 'learning_path') {
        // Check for existing courses in the LP.
        // If there are no courses - skip courses step.
        $group_courses = $group->getContent('subgroup:opigno_course');
        if ($current == 2 && empty($group_courses)) {
          $next_step++;
        }
      }
      // For all group types.
      $route = array_search($next_step, opigno_learning_path_get_routes_steps());
      return $this->redirect($route, ['group' => $group->id()]);
    }
  }

  /**
   * Steps list.
   *
   * @param Group $group
   *
   * @return array
   */
  public function listSteps(Group $group) {
    /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
    $date_formatter = \Drupal::service('date.formatter');

    $group_id = $group->id();
    $uid = \Drupal::currentUser()->id();

    $steps = opigno_learning_path_get_steps($group_id, $uid);

    $rows = array_map(function ($step) use ($date_formatter) {
      return [
        $step['name'],
        $step['typology'],
        $date_formatter->formatInterval($step['time spent']),
        $step['best score'],
      ];
    }, $steps);

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Typology'),
        $this->t('Total time spent'),
        $this->t('Best score achieved'),
      ],
      '#rows' => $rows,
    ];
  }

  /**
   * Check if the user has access to any next content from the Learning Path.
   */
  public function nextStepAccess(Group $group, OpignoGroupManagedContent $parent_content) {
    // Check if there is a next step and if the user has access to it.
    // Get the user score of the parent content.
    // First, get the content type object of the parent content.
    $content_type = $this->content_type_manager->createInstance($parent_content->getGroupContentTypeId());
    $user_score = $content_type->getUserScore(\Drupal::currentUser()->id(), $parent_content->getEntityId());

    // If no no score and content is mandatory, return forbidden.
    if ($user_score === FALSE && $parent_content->isMandatory()) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowed();
  }

  /**
   * Check if the user has access to start the Learning Path.
   */
  public function startAccess(Group $group) {
    if ($group->bundle() !== 'learning_path') {
      return AccessResult::neutral();
    }

    if ($group->hasField('field_learning_path_visibility')) {
      $user = $this->currentUser();
      $group_visibility = $group->get('field_learning_path_visibility')->getValue()[0]['value'];

      if ($user->isAnonymous() && $group_visibility != 'public') {
        return AccessResult::forbidden();
      }

      $access = LearningPathAccess::statusGroupValidation($group, $user);
      if ($access === FALSE) {
        return AccessResult::forbidden();
      }
    }

    return AccessResult::allowed();
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
   * Provide the required step messages.
   *
   * @param string $name
   *   Step name.
   * @param int $required
   *   Minimum score.
   * @param string $link
   *   Link to try again.
   *
   * @return string
   *   Message.
   */
  protected function requiredStepMessage($name, $required = NULL, $link = '') {
    if (empty($required)) {
      // The simple message.
      return $this->t('A required step: %step should be done first.', [
        '%step' => $name,
      ]);
    }
    else {
      $text = 'You should first get a minimum score of %required to the step %step before going further.';
      if (!empty($link)) {
        return $this->t("{$text} <a href=':link'>Try again.</a>", [
          '%step' => $name,
          '%required' => $required,
          ':link' => $link,
        ]);
      } else {
        return $this->t("{$text}", [
          '%step' => $name,
          '%required' => $required,
        ]);
      }
    }
  }

}
