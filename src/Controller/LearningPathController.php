<?php

namespace Drupal\opigno_learning_path\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\opigno_group_manager\Controller\OpignoGroupManagerController;
use Drupal\opigno_group_manager\Entity\OpignoGroupManagedContent;
use Drupal\opigno_learning_path\Entity\LPStatus;
use Drupal\opigno_learning_path\LearningPathAccess;
use Drupal\opigno_module\Entity\OpignoModule;
use Drupal\taxonomy\Entity\Term;

/**
 * Class LearningPathController.
 */
class LearningPathController extends ControllerBase {

  /**
   * Returns step score cell.
   *
   * @opigno_deprecated
   */
  protected function build_step_score_cell($step) {
    if (in_array($step['typology'], ['Module', 'Course', 'Meeting', 'ILT'])) {
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
    else {
      return ['#markup' => '&dash;'];
    }
  }

  /**
   * Returns step score cell.
   */
  protected function build_step_score_label($step) {
    if (in_array($step['typology'], ['Module', 'Course', 'Meeting', 'ILT'])) {
      $score = $step['best score'];

      return $score . '%';
    }
    else {
      return '0%';
    }
  }

  /**
   * Returns step state cell.
   *
   * @opigno_deprecated
   */
  protected function build_step_state_cell($step) {
    $user = $this->currentUser();
    $uid = $user->id();

    $status = opigno_learning_path_get_step_status($step, $uid, TRUE);
    switch ($status) {
      case 'pending':
        $markup = '<span class="lp_step_state_pending"></span>' . $this->t('Pending');
        break;

      case 'failed':
        $markup = '<span class="lp_step_state_failed"></span>' . $this->t('Failed');
        break;

      case 'passed':
        $markup = '<span class="lp_step_state_passed"></span>' . $this->t('Passed');
        break;

      default:
        $markup = '&dash;';
        break;
    }

    return ['#markup' => $markup];
  }

  /**
   * Returns step state cell.
   */
  protected function build_step_state_label($step) {
    $user = $this->currentUser();
    $uid = $user->id();

    assert(\Drupal::routeMatch()->getParameter('group'));
    $status = opigno_learning_path_get_step_status($step, $uid, TRUE);
    return [
      'class' => $status,
    ];
  }

  /**
   * Returns course row.
   */
  protected function build_course_row($step) {
    $result = $this->build_step_score_cell($step);
    $state = $this->build_step_state_cell($step);

    return [
      $step['name'],
      [
        'class' => 'lp_step_details_result',
        'data' => $result,
      ],
      [
        'class' => 'lp_step_details_state',
        'data' => $state,
      ],
    ];
  }

  /**
   * Training content.
   */
  public function trainingContent() {
    $build = [
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
        ],
      ],
      '#theme' => 'opigno_learning_path_training_content',
    ];
    /** @var \Drupal\group\Entity\Group $group */
    $group = \Drupal::routeMatch()->getParameter('group');
    if(!($group instanceof GroupInterface)){
      // On of  case an anonymous user hasn't an access to the group.
      return $build;
    }
    $user = \Drupal::currentUser();

    // Get training certificate expiration flag.
    $latest_cert_date = LPStatus::getTrainingStartDate($group, $user->id());

    // If not a member.
    if (!$group->getMember($user)
      || (!$user->isAuthenticated() && $group->field_learning_path_visibility->value === 'semiprivate')) {
      return $build;
    }

    // Check if membership has status 'pending'.
    if (!LearningPathAccess::statusGroupValidation($group, $user)) {
      return $build;
    }

    $steps = $this->trainingContentSteps($build, $group, $user, $latest_cert_date);
    $this->trainingContentMain($build, $steps);
    $this->trainingContentDocuments($build, $group);
    $this->trainingContentForum($build, $group, $user);
    return $build;
  }

  /**
   * Training content steps.
   */
  public function trainingContentSteps(&$content, $group, $user, $latest_cert_date) {

    // Get training guided navigation option.
    $freeNavigation = !OpignoGroupManagerController::getGuidedNavigation($group);

    if ($freeNavigation) {
      // Get all steps for LP.
      $steps = opigno_learning_path_get_all_steps($group->id(), $user->id(), NULL, $latest_cert_date);
    }
    else {
      // Get guided steps.
      $steps = opigno_learning_path_get_steps($group->id(), $user->id(), NULL, $latest_cert_date);
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

    $steps_array = [];
    $steps = array_values($steps);
    if ($steps) {
      foreach ($steps as $key => $step) {
        $sub_title = '';
        $link = NULL;
        $free_link = NULL;
        $score = $this->build_step_score_label($step);
        $state = $this->build_step_state_label($step);
        unset($start_date);
        unset($end_date);

        if ($step['typology'] === 'Course') {
          if ($freeNavigation) {
            // Get all steps for LP.
            $course_steps = opigno_learning_path_get_all_steps($step['id'], $user->id(), NULL, $latest_cert_date);
          }
          else {
            // Get guided steps.
            $course_steps = opigno_learning_path_get_steps($step['id'], $user->id(), NULL, $latest_cert_date);
          }

          foreach ($course_steps as $course_step_key => &$course_step) {
            if ($course_step_key == 0) {
              // Load first step entity.
              $first_step = OpignoGroupManagedContent::load($course_steps[$course_step_key]['cid']);
              /* @var \Drupal\opigno_group_manager\OpignoGroupContentTypesManager $content_types_manager */
              $content_types_manager = \Drupal::service('opigno_group_manager.content_types.manager');
              $content_type = $content_types_manager->createInstance($first_step->getGroupContentTypeId());
              $step_url = $content_type->getStartContentUrl($first_step->getEntityId(), $group->id());
              $link = Link::createFromRoute($course_step['name'], $step_url->getRouteName(), $step_url->getRouteParameters())
                ->toString();
            }
            else {
              // Get link to module.
              $course_parent_content_id = $course_steps[$course_step_key - 1]['cid'];
              $link = Link::createFromRoute($course_step['name'], 'opigno_learning_path.steps.next', [
                'group' => $group->id(),
                'parent_content' => $course_parent_content_id,
              ])
                ->toString();
            }

            // Add compiled parameters to step array.
            $course_step['title'] = !empty($link) ? $link : $course_step['name'];

            $course_step['summary_details_table'] = [
              '#theme' => 'opigno_learning_path_training_content_step_summary_details_table',
              '#mandatory' => $step["mandatory"],
              '#type' => $course_step["typology"],
              '#steps' => $course_step['title'],
              '#status' => $this->build_step_state_label($course_step),
              '#progress' => $this->build_step_score_label($course_step),
            ];
          }

          $course_steps_array = array_map(function ($value) use ($group) {
            return [
              '#theme' => 'opigno_learning_path_training_content_step',
              'step' => $value,
              '#group' => $group,
            ];
          }, $course_steps);
          $step['course_steps'] = $course_steps_array;
          $steps[$key]['course_steps'] = $course_steps_array;
        }
        elseif ($step['typology'] === 'Module') {
          $step['module'] = OpignoModule::load($step['id']);
        }

        $title = $step['name'];

        if ($step['typology'] === 'Meeting') {
          /** @var \Drupal\opigno_moxtra\MeetingInterface $meeting */
          $meeting = $this->entityTypeManager()
            ->getStorage('opigno_moxtra_meeting')
            ->load($step['id']);
          $start_date = $meeting->getStartDate();
          $end_date = $meeting->getEndDate();
          if ($freeNavigation) {
            $free_link = Link::createFromRoute($title, 'opigno_moxtra.meeting', [
              'opigno_moxtra_meeting' => $step['id'],
            ])
              ->toString();
          }
        }
        elseif ($step['typology'] === 'ILT') {
          /** @var \Drupal\opigno_ilt\ILTInterface $ilt */
          $ilt = $this->entityTypeManager()
            ->getStorage('opigno_ilt')
            ->load($step['id']);
          $start_date = $ilt->getStartDate();
          $end_date = $ilt->getEndDate();
          if ($freeNavigation) {
            $free_link = Link::createFromRoute($title, 'entity.opigno_ilt.canonical', [
              'opigno_ilt' => $step['id'],
            ])
              ->toString();
          }
        }

        if (isset($start_date) && isset($end_date)) {
          $start_date = DrupalDateTime::createFromFormat(
            DrupalDateTime::FORMAT,
            $start_date
          );
          $end_date = DrupalDateTime::createFromFormat(
            DrupalDateTime::FORMAT,
            $end_date
          );
          $end_date_format = $end_date->format('g:i A');
          if ($start_date->format('jS F Y') != $end_date->format('jS F Y')) {
            $end_date_format = $end_date->format('jS F Y - g:i A');
          }
          $title .= ' / ' . $this->t('@start to @end', [
            '@start' => $start_date->format('jS F Y - g:i A'),
            '@end' => $end_date_format,
          ]);
        }

        $keys = array_keys($steps);

        // Build link for first step.
        if ($key == $keys[0]) {
          if ($step['typology'] == 'Course') {
            $link = NULL;
          }
          else {
            // Load first step entity.
            $first_step = OpignoGroupManagedContent::load($steps[$key]['cid']);
            /* @var \Drupal\opigno_group_manager\OpignoGroupContentTypesManager $content_types_manager */
            $content_types_manager = \Drupal::service('opigno_group_manager.content_types.manager');
            $content_type = $content_types_manager->createInstance($first_step->getGroupContentTypeId());
            $step_url = $content_type->getStartContentUrl($first_step->getEntityId(), $group->id());
            $link = Link::createFromRoute($title, $step_url->getRouteName(), $step_url->getRouteParameters())
              ->toString();
          }
        }
        else {
          if ($step['typology'] == 'Course') {
            $link = NULL;
          }
          else {
            // Get link to module.
            if (!empty($free_link)) {
              $link = $free_link;
            }
            elseif (!empty($steps[$key - 1]['cid'])) {
              // Get previous step cid.
              if ($steps[$key - 1]['typology'] == 'Course') {
                // If previous step is course get it's last step.
                if (!empty($steps[$key - 1]['course_steps'])) {
                  $course_last_step = end($steps[$key - 1]['course_steps']);
                  if (!empty($course_last_step['cid'])) {
                    $parent_content_id = $course_last_step['cid'];
                  }
                }
              }
              else {
                // If previous step isn't a course.
                $parent_content_id = $steps[$key - 1]['cid'];
              }

              if (!empty($parent_content_id)) {
                $link = Link::createFromRoute($title, 'opigno_learning_path.steps.next', [
                  'group' => $group->id(),
                  'parent_content' => $parent_content_id,
                ])
                  ->toString();
              }
            }
          }
        }

        // Add compiled parameters to step array.
        $step['title'] = !empty($link) ? $link : $title;
        $step['sub_title'] = $sub_title;
        $step['score'] = $score;
        $step['state'] = $state;

        $step['summary_details_table'] = [
          '#theme' => 'opigno_learning_path_training_content_step_summary_details_table',
          '#mandatory' => $step["mandatory"],
          '#type' => $step["typology"],
          '#steps' => $step['title'],
          '#substeps' => (bool) ($step['course_steps'] ?? FALSE),
          '#status' => $state,
          '#progress' => $score,
        ];

        $steps_array[] = [
          '#theme' => 'opigno_learning_path_training_content_step',
          'step' => $step,
          '#group' => $group,
        ];
      }

      if ($steps_array) {
        $steps = $steps_array;
      }
    }
    return $steps;
  }

  /**
   * Training content main block.
   */
  public function trainingContentMain(&$content, $steps) {
    $content['tabs'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lp_tabs', 'nav', 'mb-4']],
    ];

    $content['tabs']['training'] = [
      '#markup' => '<a class="lp_tabs_link active" href="#training-content">' . $this->t('Training Content') . '</a>',
    ];

    $content['tab_content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tab-content']],
    ];

    $content['tab_content']['training'] = [
      '#theme' => 'opigno_learning_path_training_content_steps',
      'steps' => $steps,
    ];

  }

  /**
   * Training document block.
   */
  public function trainingContentDocuments(&$content, $group) {

    // $TFTController = new TFTController();
    // $listGroup = $TFTController->listGroup($group->id());
    $tft_url = Url::fromRoute('tft.group', ['group' => $group->id()])->toString();

    $content['tabs'][] = $tft_url = [
      '#markup' => '<div class="see-all"><a href="' . $tft_url . '">' . $this->t('See all') . '</a></div>',
    ];

    $block_render = $this->attachBlock('opigno_documents_last_group_block', ['group' => $group->id()]);
    $block_render["content"]['link'] = $tft_url;
    $content['tab_content']['documents'] = (isset($block_render["content"]["content"]) && !empty($block_render["content"]["content"])) ? [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'documents',
      ],
      'block' => [
        'content' => $block_render["content"],
      ],
    ] : [];

  }

  /**
   * Training workspaces block.
   *
   * @opigno_deprecated
   */
  public function trainingContentWorkspace(&$content, $group, $user) {
    // @todo Refactoring needed, the opigno_moxtra.workspace_controller is not available in the code,
    //       probably should be changed to a opigno_moxtra.meeting_controller service.
    $is_moxtra_enabled = \Drupal::hasService('opigno_moxtra.workspace_controller');
    if ($is_moxtra_enabled) {
      $has_workspace_field = $group->hasField('field_workspace');
      $has_workspace_access = $user->hasPermission('view workspace entities');
      if ($has_workspace_field && $has_workspace_access) {
        if ($group->get('field_workspace')->getValue() &&
          $workspace_id = $group->get('field_workspace')->getValue()[0]['target_id']
        ) {
          $workspace_url = Url::fromRoute('opigno_moxtra.workspace.iframe', ['opigno_moxtra_workspace' => $workspace_id])->toString();

          $content['tabs'][] = [
            '#markup' => '<a class="lp_tabs_link" data-toggle="tab" href="#collaborative-workspace">' . $this->t('Collaborative Workspace') . '</a>',
          ];
        }

        $workspace_tab = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'collaborative-workspace',
            'class' => ['tab-pane', 'fade'],
          ],
          'content' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['row'],
            ],
            (isset($workspace_url)) ? [
              '#type' => 'html_tag',
              '#tag' => 'iframe',
              '#attributes' => [
                'src' => $workspace_url,
                'frameborder' => 0,
                'width' => '100%',
                'height' => '600px',
              ],
            ] : [],
          ],
        ];

        $content['tab_content'][] = $workspace_tab;
      }
    }

  }

  /**
   * Training forum block.
   */
  public function trainingContentForum(&$content, $group, $user) {
    $has_enable_forum_field = $group->hasField('field_learning_path_enable_forum');
    $has_forum_field = $group->hasField('field_learning_path_forum');
    if ($has_enable_forum_field && $has_forum_field) {
      $enable_forum_field = $group->get('field_learning_path_enable_forum')->getValue();
      $forum_field = $group->get('field_learning_path_forum')->getValue();
      if (!empty($enable_forum_field) && !empty($forum_field)) {
        $enable_forum = $enable_forum_field[0]['value'];
        $forum_tid = $forum_field[0]['target_id'];
        if ($enable_forum && _opigno_forum_access($forum_tid, $user)) {
          $forum_url = Url::fromRoute('forum.page', ['taxonomy_term' => $forum_tid])->toString();
          $content['tabs'][] = $forum_url = [
            '#markup' => '<div class="see-all"><a href="' . $forum_url . '">' . $this->t('See all') . '</a></div>',
          ];
          $block_render = $this->attachBlock('opigno_forum_last_topics_block', ['taxonomy_term' => $forum_tid]);
          $block_render["content"]['link'] = $forum_url;
          $content['tab_content']['forum'] = $block_render["topics"] ? [
            '#type' => 'container',
            '#attributes' => [
              'id' => 'forum',
            ],
            'block' => [
              'content' => $block_render["content"],
            ],
          ] : [];
        }
      }
    }
    return $content;
  }

  /**
   * Attaches a block by block name.
   */
  public function attachBlock($name, $config = []) {
    $block_manager = \Drupal::service('plugin.manager.block');
    // You can hard code configuration or you load from settings.
    $plugin_block = $block_manager->createInstance($name, $config);
    // Some blocks might implement access check.
    $access_result = $plugin_block->access(\Drupal::currentUser());
    // Return empty render array if user doesn't have access.
    // $access_result can be boolean or an AccessResult class.
    if ((is_object($access_result) && $access_result->isForbidden()) || (is_bool($access_result) && !$access_result)) {
      // You might need to add some cache tags/contexts.
      return [];
    }
    $render = $plugin_block->build();
    // In some cases, you need to add the cache tags/context depending on
    // the block implemention. As it's possible to add the cache tags and
    // contexts in the render method and in ::getCacheTags and
    // ::getCacheContexts methods.
    return $render;
  }

  /**
   * Loads a group by LP forum term.
   */
  public static function loadGroupByForum(Term $term) {
    $query = \Drupal::entityQuery('group')
      ->condition('field_learning_path_forum.target_id', $term->id());
    $nids = $query->execute();
    return $nids ? Group::load(reset($nids)) : FALSE;
  }

}
