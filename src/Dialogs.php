<?php
/**
 * Created by Kirill Zorin <zarincheg@gmail.com>
 * Personal website: http://libdev.ru
 *
 * Date: 13.06.2016
 * Time: 13:55
 */

namespace BotDialogs;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

/**
 * Class Dialogs
 * @package BotDialogs
 */
class Dialogs
{


    /**
     * @param Dialog $dialog
     * @return Dialog
     */
    public function add(Dialog $dialog)
    {
        // save new dialog
        $chatId = $dialog->getChat()->getId();
        $this->setField($chatId, 'next', $dialog->getNext());
        $this->setField($chatId, 'dialog', get_class($dialog)); // @todo It's not safe. Need to define Dialogs registry with check of bindings

        return $dialog;
    }

    /**
     * @param Update $update
     * @return Dialog|false
     * @internal param $chatId
     */
    public function get(Update $update)
    {
        $chatId = $update->getMessage()->getChat()->getId();

        if (!Redis::exists($chatId)) {
            return false;
        }

        $next = Redis::hget($chatId, 'next');
        $name = Redis::hget($chatId, 'dialog');
        $memory = Redis::hget($chatId, 'memory');

        /** @var Dialog $dialog */
        $dialog = new $name($update); // @todo look at the todo above about code safety
        $dialog->setNext($next);
        $dialog->setMemory($memory);

        return $dialog;
    }

    /**
     * @param Update $update
     */
    public function proceed(Update $update)
    {
        $dialog = self::get($update);

        if (!$dialog) {
            return;
        }
        $chatId = $dialog->getChat()->getId();
        $dialog->proceed();

        $dialogAfterProceed = self::get($update);
        if (get_class($dialog) === get_class($dialogAfterProceed)) {
            if ($dialog->isEnd()) {
                Redis::del($chatId);
            } else {
                $this->setField($chatId, 'next', $dialog->getNext());
                $this->setField($chatId, 'memory', $dialog->getMemory());
            }
        }
    }

    /**
     * @param Update $update
     * @return bool
     */
    public function exists(Update $update)
    {
        if (!Redis::exists($update->getMessage()->getChat()->getId())) {
            return false;
        }

        return true;
    }

    /**
     * @param string $key
     * @param string $field
     * @param mixed $value
     */
    protected function setField($key, $field, $value)
    {
        Redis::multi();

        Redis::hset($key, $field, $value);
        Redis::expire($key, Config::get('dialogs.expires'));

        Redis::exec();
    }
}