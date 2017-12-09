<?php

namespace Drupal\dut_views\Plugin\views\display_extender;

use Drupal\Component\Utility\Html;
use Drupal\Core\Link;
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
    $options['theme_suggestions'] = array(
      'category' => 'other',
      'title' => $this->t('Theme'),
      'value' => $this->t('Information'),
      'desc' => $this->t('Get information on how to theme this display'),
    );
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $section = $form_state->get('section');

    // @todo: via DI.
    if (isset($_POST['ajax_page_state']['theme'])) {
      $this->theme = $_POST['ajax_page_state']['theme'];
    }
    elseif (empty($this->theme)) {
      // @todo: via DI.
      $this->theme = $config = \Drupal::config('system.theme')->get('default');
    }

    switch ($section) {
      case 'theme_suggestions':
        // Get a list of available themes
        $theme_handler = \Drupal::service('theme_handler');
        $themes = $theme_handler->listInfo();
        $form['#title'] .= $this->t('Theming information');

        /** @var \Drupal\Core\Theme\ActiveTheme $active_theme */
        $active_theme = \Drupal::theme()->getActiveTheme();
        if (isset($active_theme) && $active_theme->getName() == $this->theme) {
          $this->theme_registry = theme_get_registry();
          $theme_engine = $active_theme->getEngine();
        }
        // @todo: add 'else' condition.

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
          '#theme' => 'views_view_theme_suggestions_important',
        ];

        if (isset($this->displayHandler->display['new_id'])) {
          $form['important-new_id'] = [
            '#theme' => 'views_view_theme_suggestions_important_new_id',
          ];
        }

        $form['suggestions'] = [
          '#theme' => 'views_view_theme_suggestions',
          '#suggestions' => $suggestions_list,
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

        $form['analysis'] = array(
          '#theme' => 'views_view_theme_suggestion_theme_template',
          '#type' => $plugin_type,
          '#link' => $back_link,
          '#contents' => $contents,
        );
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
      '#theme' => 'views_view_theme_suggestion',
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

}
