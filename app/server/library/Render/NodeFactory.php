<?php


namespace Render;

use Render\Exceptions\ModuleAPITypeNotFound;
use Render\Exceptions\ModuleNotFoundException;
use Render\Exceptions\NoContentException;
use Render\InfoStorage\ModuleInfoStorage\IModuleInfoStorage;
use Render\Nodes\DynamicHTMLNode;
use \Render\Nodes\INode;
use \Render\Nodes\FullDynamicNode;
use \Render\Nodes\LegacyNode;

/**
 * Class NodeFactory creates the node tree structure for the given content
 *
 * @package Render
 */
class NodeFactory
{

  /**
   * @var IModuleInfoStorage
   */
  private $moduleInfoStorage;

  /**
   * @var UnitFactory
   */
  private $unitFactory;

  private $dynamicModuleObjectCache = array();

  /**
   * Creates a new NodeFactory object
   *
   * @param IModuleInfoStorage $moduleInfoStorage InfoStorage used to load
   *                                              the modules
   */
  public function __construct(IModuleInfoStorage $moduleInfoStorage)
  {
    $this->moduleInfoStorage = $moduleInfoStorage;
    $this->unitFactory = new UnitFactory();
  }

  /**
   * @param array    $content
   * @param array    $unitMap -- Reference to an flat array map with all unit nodes
   * @param array    $usedModuleIds -- Reference to an array with all used module ids
   * @param NodeTree $tree
   * @param string   $parentId
   *
   * @throws NoContentException
   * @return INode
   */
  public function createNodeWithSubNodes(
      array &$content,
      array &$unitMap,
      array &$usedModuleIds,
      NodeTree &$tree,
      $parentId = null
  ) {
    if (empty($content)) {
      throw new NoContentException('Empty unit (content) detected');
    }

    $node = $this->createNodeObject($content, $tree, $usedModuleIds, $parentId);

    // Create Children
    $unitId = $content['id'];
    if ($this->hasChildren($content)) {
      foreach ($content['children'] as $childContent) {
        $node->addChild($this->createNodeWithSubNodes(
            $childContent,
            $unitMap,
            $usedModuleIds,
            $tree,
            $unitId
        ));
      }
    }

    $unitMap[$unitId] = $node;
    return $node;
  }

  /**
   * @return IModuleInfoStorage
   */
  protected function getModuleInfoStorage()
  {
    return $this->moduleInfoStorage;
  }

  /**
   * @param array $content
   *
   * @return bool
   */
  protected function hasChildren(array &$content)
  {
    return isset($content['children']) && is_array($content['children']);
  }

  /**
   *
   * Creates a new node, and adds it to the unitMap
   *
   * @param array       $content
   * @param NodeTree    $tree
   * @param array       $usedModuleIds
   * @param string|null $parentId
   *
   * @return INode
   * @throws ModuleAPITypeNotFound
   */
  protected function createNodeObject(
      array &$content,
      NodeTree $tree,
      array &$usedModuleIds,
      $parentId = null
  ) {
    $moduleId = $content['moduleId'];
    $usedModuleIds[] = $moduleId;
    $moduleInfo = $this->getModuleInfo($moduleId);
    $moduleApiType = $this->getModuleApiType($moduleId);
    $defaultFormValues = $this->getModuleInfoStorage()->getModuleDefaultFromValues($moduleId);
    $unit = $this->getUnit($content, $defaultFormValues);

    if (is_null($moduleApiType)) {
      return $this->createLegacyNode($tree, $parentId, $unit, $moduleInfo);
    }
    if ($moduleApiType === 'APIv1' || $moduleApiType === 'RootAPIv1') {
      return $this->createDynamicHTMLNode(
          $tree,
          $parentId,
          $unit,
          $moduleInfo,
          $moduleId,
          $moduleApiType
      );
    }

    throw new ModuleAPITypeNotFound('Unknown module api type: ' . $moduleApiType);
  }

  /**
   * Returns theAPI type of the module as a string.
   *
   * @param string $moduleId
   *
   * @return string
   */
  protected function getModuleApiType($moduleId)
  {
    return $this->getModuleInfoStorage()->getModuleApiType($moduleId);
  }

  /**
   * @param $moduleId
   *
   * @throws ModuleNotFoundException
   * @return ModuleInterface
   */
  protected function loadModule($moduleId)
  {
    if (! isset($this->dynamicModuleObjectCache[$moduleId])) {
        $this->dynamicModuleObjectCache[$moduleId] =  $this->_loadModule($moduleId);
    }
      return $this->dynamicModuleObjectCache[$moduleId];
  }

  /**
   * @param NodeTree   $tree
   * @param string     $parentId
   * @param Unit       $unit
   * @param ModuleInfo $moduleInfo
   *
   * @return LegacyNode
   */
  protected function createLegacyNode(
      NodeTree $tree,
      $parentId,
      Unit $unit,
      ModuleInfo $moduleInfo
  ) {
    $module = new LegacyModule();
    return new LegacyNode($unit, $moduleInfo, $module, $parentId, $tree);
  }

  /**
   * @param NodeTree   $tree
   * @param string     $parentId
   * @param Unit       $unit
   * @param ModuleInfo $moduleInfo
   * @param string     $moduleId
   * @param string     $moduleApiType
   *
   * @return DynamicHTMLNode
   */
  protected function createDynamicHTMLNode(
      NodeTree &$tree,
      $parentId,
      Unit $unit,
      ModuleInfo $moduleInfo,
      $moduleId,
      $moduleApiType
  ) {
    $module = $this->loadModule($moduleId);
    return new DynamicHTMLNode(
        $unit,
        $moduleInfo,
        $parentId,
        $tree,
        $module,
        $moduleApiType
    );
  }

  /**
   * @param array $content
   * @param array $defaultFromValues
   *
   * @return Unit
   */
  protected function getUnit(array &$content, array $defaultFromValues = array())
  {
    return $this->getUnitFactory()->contentToUnit($content, $defaultFromValues);
  }

  /**
   * @return UnitFactory
   */
  protected function getUnitFactory()
  {
    return $this->unitFactory;
  }

  /**
   * @param string $moduleId
   *
   * @return ModuleInfo
   */
  protected function getModuleInfo($moduleId)
  {
    return new ModuleInfo($this->getModuleInfoStorage(), $moduleId);
  }

    /**
     * @param $moduleId
     * @throws Exceptions\ModuleNotFoundException
     */
    protected function _loadModule($moduleId)
    {
        $moduleMainClassFilePath = $this->getModuleInfoStorage()->getModuleMainClassFilePath($moduleId);
    if (!file_exists($moduleMainClassFilePath)) {
        throw new ModuleNotFoundException('No main class file for module ' . $moduleId . ' found');
    }
        /** @noinspection PhpIncludeInspection */
        require_once( $moduleMainClassFilePath );
        $moduleMainClassName = $this->getModuleInfoStorage()->getModuleClassName($moduleId);
        return new $moduleMainClassName();
    }
}
