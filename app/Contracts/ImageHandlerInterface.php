<?php

/**
 * Image Handler Interface
 *
 * @package     Gofer
 * @subpackage  Contracts
 * @category    Image Handler
 * @author      Trioangle Product Team
 * @version     2.2.1
 * @link        http://trioangle.com
*/

namespace App\Contracts;

interface ImageHandlerInterface
{
	public function upload($image, $options);
	public function delete($image, $options);
	public function getImage($file_name, $options);
}