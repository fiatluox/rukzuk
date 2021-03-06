<?php
namespace Cms\Request\TemplateSnippet;

use Cms\Request\Base;

/**
 * Request object for TemplateSnippet getById
 *
 * @package      Cms
 * @subpackage   Request\TemplateSnippet
 */
class GetById extends Base
{
  /**
   * @var string
   */
  private $id;

  /**
   * @var string
   */
  private $websiteId;

  protected function setValues()
  {
    $this->setId($this->getRequestParam('id'));
    $this->setWebsiteId($this->getRequestParam('websiteid'));
  }

  /**
   * @param string $id
   */
  public function setId($id)
  {
    $this->id = $id;
  }
  /**
   * @return string
   */
  public function getId()
  {
    return $this->id;
  }

  public function setWebsiteId($websiteId)
  {
    $this->websiteId = $websiteId;
  }

  public function getWebsiteId()
  {
    return $this->websiteId;
  }
}
