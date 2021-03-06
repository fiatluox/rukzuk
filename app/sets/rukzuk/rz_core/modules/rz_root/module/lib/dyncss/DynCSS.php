<?php
namespace Rukzuk\Modules\Lib;
require_once(dirname(__FILE__) . '/DynCSSEngine.php');

/**
 * Class DynCSS
 *
 * @link js/dyncssBackend.js
 * @package Rukzuk\Modules\Lib
 */
class DynCSS {
  /**
   * @var array
   */
  private $errors = array();
  /**
   * @var DynCSSEngine
   */
  private $cssEngine;

  const CACHE_VERSION = 3;

  /**
   * @param DynCSSEngine $cssEngine
   */
  public function __construct($cssEngine)
  {
      $this->cssEngine = $cssEngine;
  }

  /**
   * Generate 'dynamic' CSS (css which depends on formValues)
   * @param \Render\APIs\RootAPIv1\RootCssAPI|\Render\APIs\RootAPIv1\RootRenderAPI $rootCssApi
   * @param \Render\Unit $rootUnit
   * @param boolean $outputTreeData - weather data required for client side recompile is written or not
   */
  public function generateCSS($rootCssApi, $rootUnit, $outputTreeData)
  {
    $unitData = $rootCssApi->getAllUnitData($rootUnit);
    $globalDataHash = md5(json_encode($rootCssApi->getColorScheme()) . json_encode($rootCssApi->getResolutions()));

    // collect css.js code
    $dynCSSPlugins = array();
    $formValues = array();
    foreach ($unitData as $uid => $data) {
      if (isset($data['dyncss']['formValues'])) {
        $formValues[$uid] = $data['dyncss']['formValues'];
      }
      if ($data['dyncss']['plugin']) {
        $pluginName = $data['dyncss']['plugin']['name'];
        $dynCSSPlugins[$pluginName] = $data['dyncss']['plugin'];
      }
    }

    // create css for each non-extension unit
    foreach ($unitData as $unitId => $singleUnitData) {
      if ($singleUnitData['dyncss']['isExtension']) {
        continue;
      }

      // unit data subtree
      $unitDataTree = $this->buildUnitTree($rootCssApi, $unitData, $unitId);
      // convert to json tree (used by dyncss)
      $cssJsonTree = array();
      $this->buildJsonTree($cssJsonTree, $unitDataTree);

      $cssCode = '';
      // try to get cached value
      $cacheKey = md5(json_encode($unitDataTree) . $globalDataHash . self::CACHE_VERSION);

      $cachedValue = $rootCssApi->getUnitCache($rootCssApi->getUnitById($unitId), 'css');
      if (isset($cachedValue['hash']) && $cachedValue['hash'] === $cacheKey) {
        $cssCode = $cachedValue['cssCode'];
      } else {
        // regenerate css
        try {
          $cssCode = $this->cssEngine->compile($dynCSSPlugins, $cssJsonTree, $formValues, $rootCssApi);
          $rootCssApi->setUnitCache($rootCssApi->getUnitById($unitId), 'css', array('hash' => $cacheKey, 'cssCode' => $cssCode));
        } catch (\Exception $e) {
          $this->addError($e->getMessage());
        }
      }

      if ($outputTreeData) {
        echo '<style data-tree="' . htmlentities(json_encode($cssJsonTree)) . '" class="generatedStyles" id="generatedStyle_' . $unitId . '" data-unit-id="' . $unitId . '">';
        echo $cssCode;
        echo '</style>';
      } else {
        echo $cssCode;
      }
    }
    // load dyncss js module definition for client
    if ($outputTreeData) {
      $pluginUrls = array();
      foreach ($dynCSSPlugins as $data) {
        $pluginUrls[] = $data['url'];
      }
      echo '<script type="application/json" id="dyncss-plugins">'.json_encode($pluginUrls).'</script>';
    }
  }

  /**
   * Converts flat unitData into a tree (which only includes the extension children, but at any depth)
   * @param \Render\APIs\APIv1\CSSAPI $api
   * @param $unitData
   * @param string $unitId
   *
   * @return array
   */
  private function buildUnitTree($api, $unitData, $unitId)
  {
    // map data in newly create tree
    $tree = array();
    $data = $unitData[$unitId]['dyncss'];
    $tree[$unitId]['data'] = $data;

    // children (only extension modules, but at any nesting level)
    foreach ($api->getChildren($api->getUnitById($unitId)) as $child) {
      $childId = $child->getId();
      if ($unitData[$childId]['dyncss']['isExtension']) {
        $tree[$unitId]['children'][] = $this->buildUnitTree($api, $unitData, $childId);
      }
    }

    return $tree;
  }

  /**
   * Convert unitTree to an absurd.js compatible array (json) structure
   * @param array $jsonTree
   * @param array $unitList - array generated by {@link #buildUnitTree}
   */
  private function buildJsonTree(&$jsonTree, $unitList)
  {
    $context = &$jsonTree;

    foreach ($unitList as $unitId => $unit) {
      $data = $unit['data'];
      $selector = isset($data['selector']) ? $data['selector'] : null;
      $hasSelector = !empty($selector);
      $pluginName = isset($data['plugin']['name']) ? $data['plugin']['name'] : null;
      $dynamicSelector = isset($data['dynamicSelector']) ? $data['dynamicSelector'] : null;

      $childrenContext = null;
      if ($hasSelector) {
        // this unit has a selector (usually the unit id of a 'default' module)
        $sel = implode(' ', $selector);
        $context[$sel] = array();
        $context = &$context[$sel];
        // use this context for the children
        $childrenContext = &$context;
      } else if (!is_null($dynamicSelector)) {
        // this module has a dynamic selector (a selector which is defined by a formValue)
        $dynSelObj = array('dynamicSelector' => array('unitId' => $unitId, 'formValue' => $dynamicSelector));
        $elemIdx = $this->array_push_or_create($context, '&', $dynSelObj);
        // use the dynamicSelector child as context for the cildren
        $childrenContext = &$context['&'][$elemIdx]['dynamicSelector'];
      }

      // we have a plugin (so this module want to set some styles)
      // add a call to this plugin in the json tree
      if (!is_null($pluginName)) {
        $pluginCall = array($pluginName => $unitId);
        $this->array_push_or_create($context, '&', $pluginCall);
      }

      // add children of this unit via recursive call
      if (isset($unit['children'])) {
        foreach($unit['children'] as $childList) {
          $this->buildJsonTree($childrenContext, $childList);
        }
      }
    }
  }

  private function addError($e)
  {
    $this->errors[] = $e;
  }


  public function getErrors()
  {
    return $this->errors;
  }

  /**
   * Append val to a multidimensional array which might not exist at this key (then its created)
   * @param $arr - the array
   * @param $key - key inside the multidimensional array $arr
   * @param $val - value which gets pushed
   * @return int - position of the element inside the array at key
   */
  private function array_push_or_create(&$arr, $key, $val)
  {

    if(isset($arr[$key]) && is_array($arr[$key])) {
      array_push($arr[$key], $val);
    } else {
      $arr[$key] = array($val);
    }

    $elemIdx = count($arr[$key])-1;
    return $elemIdx;
  }

}
