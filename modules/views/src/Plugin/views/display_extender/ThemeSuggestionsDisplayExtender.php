<?php

namespace Drupal\dut_views\Plugin\views\display_extender;

use Drupal\Component\Utility\Html;
use Drupal\Core\Link;
use Drupal\Core\Theme\Registry;
use Drupal\Core\Url;
use Drupal\views\Views;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\display_extender\DisplayExtenderPluginBase;

/**
 * Theme suggestions display extender plugin.
 *
 * @ingroup views_display_extender_plugins
 *
 * @ViewsDisplayExtender(
 *   id = "theme_suggestions",
 *   title = @Translation("Theme suggestions"),
 *   help = @Translation("Get information on how to theme this display."),
 *   no_ui = FALSE
 * )
 */
class ThemeSuggestionsDisplayExtender extends DisplayExtenderPluginBase {

  // @todo: add fields.
  protected $pluginTypes = [
    'display' => ['Display output', 'Alternative display output'],
    'style'  => ['Style output', 'Alternative style'],
    'row' => ['Row style output', 'Alternative row style'],
  ];

  protected $theme;

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(&$categories, &$options) {
    $options['theme_suggestions'] = [
      'category' => 'other',
      'title' => $this->t('Theme'),
      'value' => $this->t('Information'),
      'desc' => $this->t('Get information on how to theme this display'),
    ];
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $section = $form_state->get('section');
    $storage = $form_state->getStorage();

    // @todo: via DI.
    if (isset($_POST['theme'])) {
      $this->theme = $_POST['theme'];
    }
    elseif (empty($this->theme)) {
      // @todo: via DI.
      $this->theme = $config = \Drupal::config('system.theme')->get('default');
    }

    $form_state->set('ok_button', TRUE);
    switch ($section) {
      case 'theme_suggestions':
        $form['#title'] .= $this->t('Theming information');

        $theme_handler = \Drupal::service('theme_handler');
        $themes = $theme_handler->listInfo();

        /** @var \Drupal\Core\Theme\ActiveTheme $active_theme */
        $active_theme = \Drupal::theme()->getActiveTheme();
        if (isset($active_theme) && $active_theme->getName() == $this->theme) {
          $this->theme_registry = theme_get_registry();
          $theme_engine = $active_theme->getEngine();
        }
        else {
          // Get a list of available themes
          $theme = $themes[$this->theme];

          // Find all our ancestor themes and put them in an array.
          $base_theme = [];
          $ancestor = $this->theme;
          while ($ancestor && isset($themes[$ancestor]->base_theme)) {
            $ancestor = $themes[$ancestor]->base_theme;
            $base_theme[] = $themes[$ancestor];
          }

          // The base themes should be initialized in the right order.
          $base_theme = array_reverse($base_theme);

          // This code is copied directly from _drupal_theme_initialize()
          $theme_engine = NULL;

          // Initialize the theme.
          if (isset($theme->engine)) {
            // Include the engine.
            include_once DRUPAL_ROOT . '/' . $theme->owner;

            $theme_engine = $theme->engine;
            if (function_exists($theme_engine . '_init')) {
              foreach ($base_theme as $base) {
                call_user_func($theme_engine . '_init', $base);
              }
              call_user_func($theme_engine . '_init', $theme);
            }
          }
          else {
            // include non-engine theme files
            foreach ($base_theme as $base) {
              // Include the theme file or the engine.
              if (!empty($base->owner)) {
                include_once DRUPAL_ROOT . '/' . $base->owner;
              }
            }
            // and our theme gets one too.
            if (!empty($theme->owner)) {
              include_once DRUPAL_ROOT . '/' . $theme->owner;
            }
          }
          $registry = new Registry(\Drupal::root(), \Drupal::cache(), \Drupal::lock(), \Drupal::moduleHandler(), \Drupal::service('theme_handler'), \Drupal::service('theme.initialization'), $this->theme);
          $registry->setThemeManager(\Drupal::theme());

          $this->theme_registry = $registry->get();
        }

        // If there's a theme engine involved, we also need to know its extension
        // so we can give the proper filename.
        $this->theme_extension = '.html.twig';
        if (isset($theme_engine)) {
          $extension_function = $theme_engine . '_extension';
          if (function_exists($extension_function)) {
            $this->theme_extension = $extension_function();
          }
        }

        $suggestions_list = [];
        foreach (array_keys($this->pluginTypes) as $plugin_type) {
          list($definition, $display_theme) = $this->getPluginDefinitions($plugin_type);
          // Get theme functions for the display. Note that some displays may
          // not have themes. The 'feed' display, for example, completely
          // delegates to the style.
          if ($plugin_type === 'display' && empty($display_theme)) {
            continue;
          }

          if (empty($display_theme)) {
            continue;
          }

          $suggestions = $this->view->buildThemeFunctions($display_theme);
          $suggestions_list[] = $this->buildSuggestionGroup($plugin_type, $suggestions, 0);

          $additional_suggestions = !empty($definition['additional themes']) ? $definition['additional themes'] : [];
          foreach ($additional_suggestions as $theme => $type) {
            $suggestions = $this->view->buildThemeFunctions($theme);
            $suggestions_list[] = $this->buildSuggestionGroup($plugin_type, $suggestions, 1);
          }
        }

        $form['important'] = [
          '#theme' => 'dut_views_theme_suggestions_important',
        ];

        if (isset($this->displayHandler->display['new_id'])) {
          $form['important-new_id'] = [
            '#theme' => 'dut_views_theme_suggestions_important_new_id',
          ];
        }

        $options = [];
        foreach ($themes as $key => $theme) {
          if (!empty($theme->info['hidden'])) {
            continue;
          }
          $options[$key] = $theme->info['name'];
        }

        $form['box'] = [
          '#prefix' => '<div class="container-inline">',
          '#suffix' => '</div>',
        ];
        $form['box']['theme'] = [
          '#type' => 'select',
          '#options' => $options,
          '#default_value' => $this->theme,
        ];

        $form['box']['change'] = [
          '#type' => 'submit',
          '#value' => t('Change theme'),
          '#submit' => [
            [$this, 'changeTheme'],
          ],
        ];

        $form['suggestions'] = [
          '#theme' => 'dut_views_theme_suggestions',
          '#suggestions' => $suggestions_list,
        ];

        $form['rescan'] = [
          '#prefix' => '<div class="form-item">',
          '#suffix' => '</div>',
        ];
        $form['rescan']['button'] = [
          '#type' => 'submit',
          '#value' => $this->t('Rescan template files'),
          '#submit' => [
            [$this, 'rescanTemplateFiles'],
          ],
        ];
        $form['rescan']['description'] = [
          '#theme' => 'dut_views_theme_suggestions_rescan_description',
        ];
        break;

      case 'theme_suggestions__display':
      case 'theme_suggestions__style':
      case 'theme_suggestions__row':
        list($plugin_id, $plugin_type) = explode('__', $section);
        $form['#title'] .= t('Theming information (@plugin_type)', ['@plugin_type' => $plugin_type]);
        $back_link = Link::fromTextAndUrl(t('Theming information'), $this->createFormDisplayRouteLink($plugin_id));
        $theme_registry = theme_get_registry();
        list($definition, $display_theme) = $this->getPluginDefinitions($plugin_type);
        $contents = [];

        if (!empty($definition['theme'])) {
          $theme = $theme_registry[$definition['theme']];
          $content = file_get_contents('./' . $theme['path'] . '/' . strtr($theme['template'], '_', '-') . '.html.twig');
          $contents[] = [
            'additional' => FALSE,
            'content' => Html::escape($content),
          ];
        }

        if (!empty($definition['additional themes'])) {
          foreach ($definition['additional themes'] as $theme => $type) {
            $content = file_get_contents('./' . $theme['path'] . '/' . strtr($theme, '_', '-') . '.html.twig');
            $contents[] = [
              'additional' => TRUE,
              'content' => Html::escape($content),
            ];
          }
        }

        $form['analysis'] = [
          '#theme' => 'dut_views_theme_suggestion_theme_template',
          '#type' => $plugin_type,
          '#link' => $back_link,
          '#contents' => $contents,
        ];
        break;
    }
  }

  protected function getPluginDefinitions($plugin_type) {
    $definitions = Views::pluginManager($plugin_type)->getDefinitions();
    $display_plugin_id = $plugin_type === 'display'
      ? $this->displayHandler->getPluginId()
      : $this->displayHandler->options[$plugin_type]['type'];
    $definition = !empty($definitions[$display_plugin_id])
      ? $definitions[$display_plugin_id]
      : [];
    $display_theme = !empty($definition['theme'])
      ? $definition['theme']
      : NULL;

    return [$definition, $display_theme];
  }

  protected function buildSuggestionGroup($plugin_type, $suggestions, $title_delta) {
    $group = $this->pluginTypes[$plugin_type][$title_delta];
    $type = implode('__', [$this->getBaseId(), $plugin_type]);
    $group_link = Link::fromTextAndUrl(t($group), $this->createFormDisplayRouteLink($type));

    return [
      '#theme' => 'dut_views_theme_suggestion',
      '#type' => $plugin_type,
      '#link' => $group_link,
      '#suggestions' => $this->formatThemes($suggestions),
    ];
  }

  protected function createFormDisplayRouteLink($type, $js = 'nojs') {
    $route = 'views_ui.form_display';
    $options = ['attributes' => ['class' => 'views-ajax-link']];

    return Url::fromRoute($route, [
      'js' => $js,
      'view' => $this->view->id(),
      'display_id' => $this->view->display_handler->display['id'],
      'type' => $type,
    ], $options);
  }

  /**
   * Format a list of theme templates for output by the theme info helper.
   */
  protected function formatThemes($themes) {
    $fixed = [];
    $registry = $this->theme_registry;
    $extension = $this->theme_extension;

    $picked = FALSE;
    foreach ($themes as $theme) {
      $template_name = strtr($theme, '_', '-') . $extension;
      $template = ['template' => $template_name];
      if (!$picked && !empty($registry[$theme])) {
        $template['path'] = isset($registry[$theme]['path']) ? $registry[$theme]['path'] . '/' : './';
        $template['exists'] = file_exists($template['path'] . $template_name);
        $picked = TRUE;
      }
      $fixed[] = $template;
    }

    return array_reverse($fixed);
  }

  /**
   * Override handler for views_ui_edit_display_form
   */
  public static function changeTheme(array $form, FormStateInterface $form_state) {
    // This is just a temporary variable.
    $storage = $form_state->getStorage();
    if (!empty($storage['view'])) {
      $view = &$storage['view'];
      /** @var \Drupal\views_ui\ViewUI $view */
      $view->theme = $form_state->getValue('theme');
      $view->cacheSet();

      $form_state->setStorage($storage);
      $form_state->set('rerender', TRUE);
      $form_state->setRebuild();
    }
  }

  /**
   * Submit hook to clear Drupal's theme registry (thereby triggering
   * a templates rescan).
   */
  public static function rescanTemplateFiles(array $form, FormStateInterface $form_state) {
    $theme = $form_state->getValue('theme');
    drupal_theme_rebuild();

    // The 'Theme: Information' page is about to be shown again. That page
    // analyzes the output of theme_get_registry(). However, this latter
    // function uses an internal cache (which was initialized before we
    // called drupal_theme_rebuild()) so it won't reflect the
    // current state of our theme registry. The only way to clear that cache
    // is to re-initialize the theme system:
    unset($GLOBALS['theme']);
    $theme_init = \Drupal::service('theme.initialization');
    $theme_init->initTheme($theme);

    $form_state->set('rerender', TRUE);
    $form_state->setRebuild();
  }

}
