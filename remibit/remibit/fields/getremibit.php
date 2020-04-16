<?php
/*
RemiBit Payment Module
Modified April 16th 2020 by Blockchain Remittance Ltd.
Adapted to handle calls to RemiBit API.
*/
/**
 * RemiBit - RemiBit Payment Module for PrestaShop 1.7
 *
 * Order Validation Controller
 *
 */
/**
 * @version $Id: getsofort.php 8500 2014-10-21 16:03:28Z alatak $
 *
 * @author Valérie Isaksen
 * @package VirtueMart
 * @copyright Copyright (c) 2004 - 2012 VirtueMart Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */
defined('JPATH_BASE') or die();

jimport('joomla.form.formfield');

class JFormFieldGetRemiBit extends JFormField {

	/**
	 * Element name
	 *
	 * @access    protected
	 * @var        string
	 */
	var $type = 'getRemiBit';

	function getInput() {
        $html = '';
		return $html;
	}


}
