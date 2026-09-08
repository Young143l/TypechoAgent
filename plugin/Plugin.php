<?php

namespace TypechoPlugin\TypechoCli;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * TypechoCli — JSON API for AI blog management
 *
 * @package TypechoCli
 * @author  Young143
 * @link    https://www.young143.top
 * @version 1.0.0
 * @since   1.2.0
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        \Utils\Helper::addAction('tc', '\\' . __NAMESPACE__ . '\\Action');
        return _t('TypechoCli 已激活，端点: /action/tc');
    }

    public static function deactivate()
    {
        $db = Db::get();
        \Utils\Helper::removeAction('tc');
        $db->query($db->delete('table.options')
            ->where('name = ?', 'plugin:TypechoCli'));
    }

    public static function config(Form $form)
    {
    }

    public static function personalConfig(Form $form)
    {
    }
}
