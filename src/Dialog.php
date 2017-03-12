<?php
/**
 * Created by Kirill Zorin <zarincheg@gmail.com>
 * Personal website: http://libdev.ru
 *
 * Date: 12.06.2016
 * Time: 16:47
 */

namespace BotDialogs;

use BotDialogs\Exceptions\DialogException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Telegram\Bot\Actions;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\Update;

/**
 * Class Dialog
 * @package BotDialogs
 */
class Dialog
{
    protected $steps = [];
    /**
     * @var int Next step
     */
    protected $next = 0;
    protected $current = 0;
    protected $yes = null;
    protected $no = null;

    private $stay = false;

    /**
     * @var string
     */
    protected $lostDataResponseMessage = 'Ваши прошлые ответы бесследно утеряны.';

    /**
     * @param int $next
     */
    public function setNext($next)
    {
        $this->next = $next;
    }

    /**
     * @return array
     */
    public function getSteps()
    {
        return $this->steps;
    }

    /**
     * @return int
     */
    public function getNext()
    {
        return $this->next;
    }

    /**
     * @var Update
     */
    protected $update;
    //@todo make fixes with memory handling if more than one dialog will be present in the system
    // we should make this cause information is saved by chatId and
    // there can be interference between different dialogs
    protected $memory = '';

    /**
     * @param string $memory
     */
    public function setMemory($memory)
    {
        $this->memory = $memory;
    }

    /**
     * @return string
     */
    public function getMemory()
    {
        return $this->memory;
    }

    /**
     * @param Update $update
     */
    public function __construct(Update $update)
    {
        $this->update = $update;

        $this->importSteps();
    }


    public function start()
    {
        $this->next = 0;
        $this->proceed();
    }

    /**
     * @throws DialogException
     * @throws \Telegram\Bot\Exceptions\TelegramSDKException
     */
    public function proceed()
    {
        $this->current = $this->next;

        if (!$this->isEnd()) {
            Telegram::sendChatAction([
                'chat_id' => $this->update->getMessage()->getChat()->getId(),
                'action' => Actions::TYPING
            ]);

            $step = $this->steps[$this->current];

            $memory = json_decode($this->getMemory());
            if (is_null($memory)) {
                $memory = new \stdClass();
            }
            //check if there is information on previous required steps (labeled with "'save'=>true" step attribute)
            foreach($this->steps as $k=>$checkStep) {
                if ($k==$this->current)
                    break;
                if (is_array($checkStep)) {
                    if (!empty($checkStep['save']) && empty($memory->{$checkStep['name']})) {
                        $this->end();
                        $this->respondToCurrentRequest($this->lostDataResponseMessage);
                        return;
                    }
                }
            }

            if (is_array($step)) {
                if (!isset($step['name'])) {
                    throw new DialogException('Dialog step name must be defined.');
                }

                $name = $step['name'];
            } elseif (is_string($step)) {
                $name = $step;
            } else {
                throw new DialogException('Dialog step is not defined.');
            }

            // Flush yes/no state
            $this->yes = null;
            $this->no = null;

            if (is_array($step)) {
                if (!empty($step['save'])) { //not null and true
                    $memory->{$name} = $this->getMessageText();
                }
                if (isset($step['is_dich']) && $step['is_dich'] && $this->processYesNo($step)) {
                    return;
                } elseif (!empty($step['jump'])) {
                    $this->jump($step['jump']);
                }
            }
            $this->setMemory(json_encode($memory));
            $this->$name($step);
            // Step forward only if did not changes inside the step handler
            if ($this->next == $this->current && !$this->stay) {
                $this->next++;
            }
        }
    }

    /**
     * Process yes-no scenery
     *
     * @param array $step
     *
     * @return bool True if no further procession required (jumped to another step)
     */
    protected function processYesNo(array $step) {
        $message = $this->update->getMessage()->getText();
        $message = mb_strtolower(trim($message));
        $message = preg_replace('![%#,:&*@_\'\"\\\+\^\(\)\[\]\-\$\!\?\.]+!ui', '', $message);

        if (in_array($message, Config::get('dialogs.aliases.yes'))) {
            $this->yes = true;

            if (!empty($step['yes'])) {
                $this->jump($step['yes']);
                $this->proceed();

                return true;
            }
        } elseif (in_array($message, Config::get('dialogs.aliases.no'))) {
            $this->no = true;

            if (!empty($step['no'])) {
                $this->jump($step['no']);
                $this->proceed();

                return true;
            }
        } elseif (!empty($step['default'])) {
            $this->jump($step['default']);
            $this->proceed();

            return true;
        }

        return false;
    }

    /**
     * Jump to the particular step of the dialog
     * @param $step
     */
    public function jump($step)
    {
        foreach ($this->steps as $index => $value) {
            if ((is_array($value) && $value['name'] === $step) || $value === $step) {
                $this->setNext($index);
                break;
            }
        }
    }

    /**
     * Stay on current step
     */
    public function stay() {
        $this->setNext($this->current);
        $this->stay = true;
    }

    /**
     * @todo Maybe the better way is that to return true/false from step-methods. And if it returns false - it means end of dialog
     */
    public function end()
    {
        $this->next = count($this->steps);
    }

    /**
     * Remember information for the next step usage. It works with Dialogs management class that store data to Redis.
     * @param $value
     * @return mixed
     */
    public function remember($value = '')
    {
        if (!$value && $this->memory !== '') {
            return json_decode($this->memory);
        }

        $this->memory = json_encode($value);
    }

    /**
     * Check if dialog ended
     * @return bool
     */
    public function isEnd()
    {
        if ($this->next >= count($this->steps)) {
            return true;
        }

        return false;
    }

    /**
     * Returns Telegram chat object
     * @return \Telegram\Bot\Objects\Chat
     */
    public function getChat()
    {
        return $this->update->getMessage()->getChat();
    }


    /**
     * Returns current user message text
     * @return string
     */
    public function getMessageText()
    {
        return $this->update->getMessage()->getText();
    }

    /**
     * @param string $name
     * @param array $args
     *
     * @return bool
     * @throws DialogException
     */
    public function __call($name, array $args)
    {
        if (count($args) === 0) {
            return false;
        }

        $step = $args[0];

        if (!is_array($step)) {
            throw new DialogException('For string steps method must be defined.');
        }

        // @todo Add logging
        if (isset($step['response'])) {
            $params = [
                'chat_id' => $this->getChat()->getId(),
                'text'    => $step['response']
            ];

            if (isset($step['markdown']) && $step['markdown']) {
                $params['parse_mode'] = 'Markdown';
            }

            Telegram::sendMessage($params);
        }

        if (!empty($step['jump'])) {
            $this->jump($step['jump']);
        }

        if (isset($step['end']) && $step['end']) {
            $this->end();
        }

        return true;
    }

    public function setSteps($steps)
    {
        $this->steps = $steps;
    }

    /**
     * Sends message with $text to currently handling request
     *
     * @param $text string message
     * @return \Telegram\Bot\Objects\Message
     */
    protected function respondToCurrentRequest($text) {
        return Telegram::sendMessage([
                'chat_id' => $this->getChat()->getId(),
                'text' => $text
        ]);
    }


    /**
     * Load steps from file (php or yaml formats)
     *
     * @param string $path
     *
     * @return bool True if steps loaded successfully
     */
    public function loadSteps($path)
    {
        if (!file_exists($path)) {
            return false;
        }

        $cacheKey = 'scenario:' . $path;
        if (!app()->environment('local', 'staging')) {
            $steps = Cache::get($cacheKey);
            if ($steps !== null) {
                $this->setSteps($steps);

                return true;
            }
        }

        $ext = substr($path, strrpos($path, '.') + 1);
        switch ($ext) {
            case 'php':
                $this->setSteps(require $path);
                break;
            case 'yml':
            case 'yaml':
                $parser = new Parser();
                try {
                    $yaml = $parser->parse(file_get_contents($path));
                    $this->setSteps($yaml);
                } catch (ParseException $e) {
                    Log::error('Unable to parse YAML config: ' . $e->getMessage());

                    return false;
                }

                break;
            default:
                return false;
        }

        if (!app()->environment('local', 'staging')) {
            Cache::forever($cacheKey, $this->getSteps());
        }

        return true;
    }

    protected function importSteps()
    {
        if ($scenario = Config::get('dialogs.scenarios.' . static::class)) {

            if (is_array($scenario)) {
                $this->steps = $scenario;
            } else {
                $this->loadSteps($scenario);
            }
        }
    }
}