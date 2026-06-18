<?php

namespace TypechoPlugin\TypechoAgent;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * TypechoAgent — JSON API for AI blog management
 *
 * @package TypechoAgent
 * @author  Young143
 * @link    https://www.young143.top
 * @version 1.0.0
 * @since   1.2.0
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        \Utils\Helper::addAction('ta', '\\' . __NAMESPACE__ . '\\Action');
        return _t('TypechoAgent 已激活，端点: /action/ta');
    }

    public static function deactivate()
    {
        $db = Db::get();
        \Utils\Helper::removeAction('ta');
        $db->query($db->delete('table.options')
            ->where('name = ?', 'plugin:TypechoAgent'));
    }

    public static function config(Form $form)
    {
    }

    public static function personalConfig(Form $form)
    {
    }
}
