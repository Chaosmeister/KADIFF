<?php

namespace Kanboard\Plugin\KADIFF;

require __DIR__ . '/src/SequenceMatcher.php';
require __DIR__ . '/src/MbString.php';

require __DIR__ . '/src/Factory/LineRendererFactory.php';
require __DIR__ . '/src/Factory/RendererFactory.php';

require __DIR__ . '/src/Renderer/RendererConstant.php';
require __DIR__ . '/src/Renderer/RendererInterface.php';
require __DIR__ . '/src/Renderer/AbstractRenderer.php';

require __DIR__ . '/src/Renderer/Html/AbstractHtml.php';
require __DIR__ . '/src/Renderer/Html/SideBySide.php';

require __DIR__ . '/src/Renderer/Html/LineRenderer/LineRendererInterface.php';
require __DIR__ . '/src/Renderer/Html/LineRenderer/AbstractLineRenderer.php';
require __DIR__ . '/src/Renderer/Html/LineRenderer/Line.php';

require __DIR__ . '/src/Utility/Arr.php';
require __DIR__ . '/src/Utility/Language.php';
require __DIR__ . '/src/Utility/ReverseIterator.php';
require __DIR__ . '/src/Utility/Str.php';

require __DIR__ . '/src/Differ.php';
require __DIR__ . '/src/DiffHelper.php';

use Jfcherng\Diff\DiffHelper;
use Kanboard\Core\Plugin\Base;
use Kanboard\Formatter\ProjectActivityEventFormatter;
use Jfcherng\Diff\Renderer\RendererConstant;

class MyFormatter extends ProjectActivityEventFormatter
{
    public function format()
    {
        $rendererName = 'SideBySide';

        $differOptions = [
            // show how many neighbor lines
            // Differ::CONTEXT_ALL can be used to show the whole file
            'context' => 3,
            // ignore case difference
            'ignoreCase' => false,
            // ignore whitespace difference
            'ignoreWhitespace' => false,
        ];

        // the renderer class options
        $rendererOptions = [
            // how detailed the rendered HTML in-line diff is? (none, line, word, char)
            'detailLevel' => 'line',
            // renderer language: eng, cht, chs, jpn, ...
            // or an array which has the same keys with a language file
            'language' => 'eng',
            // show line numbers in HTML renderers
            'lineNumbers' => true,
            // show a separator between different diff hunks in HTML renderers
            'separateBlock' => false,
            // show the (table) header
            'showHeader' => false,
            // the frontend HTML could use CSS "white-space: pre;" to visualize consecutive whitespaces
            // but if you want to visualize them in the backend with "&nbsp;", you can set this to true
            'spacesToNbsp' => false,
            // HTML renderer tab width (negative = do not convert into spaces)
            'tabSize' => 4,
            // this option is currently only for the Combined renderer.
            // it determines whether a replace-type block should be merged or not
            // depending on the content changed ratio, which values between 0 and 1.
            'mergeThreshold' => 0.8,
            // this option is currently only for the Unified and the Context renderers.
            // RendererConstant::CLI_COLOR_AUTO = colorize the output if possible (default)
            // RendererConstant::CLI_COLOR_ENABLE = force to colorize the output
            // RendererConstant::CLI_COLOR_DISABLE = force not to colorize the output
            'cliColorization' => RendererConstant::CLI_COLOR_AUTO,
            // this option is currently only for the Json renderer.
            // internally, ops (tags) are all int type but this is not good for human reading.
            // set this to "true" to convert them into string form before outputting.
            'outputTagAsString' => false,
            // this option is currently only for the Json renderer.
            // it controls how the output JSON is formatted.
            // see available options on https://www.php.net/manual/en/function.json-encode.php
            'jsonEncodeFlags' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            // this option is currently effective when the "detailLevel" is "word"
            // characters listed in this array can be used to make diff segments into a whole
            // for example, making "<del>good</del>-<del>looking</del>" into "<del>good-looking</del>"
            // this should bring better readability but set this to empty array if you do not want it
            'wordGlues' => [' ', '-'],
            // change this value to a string as the returned diff if the two input strings are identical
            'resultForIdenticals' => null,
            // extra HTML classes added to the DOM of the diff container
            'wrapperClasses' => ['diff-wrapper'],
        ];

        $events = array_reverse($this->query->findAll());

        $Diffs = array();

        foreach ($events as &$event) {
            $event += $this->unserializeEvent($event['data']);
            unset($event['data']);

            $event['author'] = $event['author_name'] ?: $event['author_username'];
            $event['event_title'] = $this->notificationModel->getTitleWithAuthor($event['author'], $event['event_name'], $event);

            $eventname = $event['event_name'];

            if ($eventname == 'task.create') {
                $Diffs['description'] = $event['task']['description'];
            } else if ($eventname == 'comment.create') {
                $Diffs['comment'][$event['comment']['id']] = $event['comment']['comment'];
            } else if ($eventname == 'task.update') {
                if (isset($event['changes']['description'])) {
                    if (isset($Diffs['description'])) {
                        $Diff = DiffHelper::calculate($Diffs['description'], $event['task']['description'], $rendererName, $differOptions, $rendererOptions);
                        $Diffs['description'] = $event['task']['description'];
                        $event['task']['description'] = $Diff;
                        $event['changes']['description'] = "x"; // force changerecognition
                    } else {
                        $Diffs['description'] = $event['task']['description'];
                    }
                }
            } else if ($eventname == 'comment.update') {
                if (isset($Diffs['comment'][$event['comment']['id']])) {
                    $Diff = DiffHelper::calculate($Diffs['comment'][$event['comment']['id']], $event['comment']['comment'], $rendererName, $differOptions, $rendererOptions);
                    $Diffs['comment'][$event['comment']['id']] = $event['comment']['comment'];
                    $event['comment']['comment'] = $Diff;
                } else {
                    $Diffs['comment'][$event['comment']['id']] = $event['comment']['comment'];
                }
            }

            $event['event_content'] = $this->renderEvent($event);
        }

        return array_reverse($events);
    }
}

class Plugin extends Base
{
    public function initialize()
    {
        $this->hook->on('template:layout:css', array('template' => 'plugins/KADIFF/diff-table.css'));

        $this->container['projectActivityEventFormatter'] = $this->container->factory(function ($c) {
            return new MyFormatter($c);
        });
    }

    public function getPluginName()
    {
        return "Activitystream diff";
    }

    public function getPluginAuthor()
    {
        return 'Tomas Dittmann';
    }

    public function getPluginVersion()
    {
        return '1.0.0';
    }

    public function getPluginDescription()
    {
        return 'Show a diff instead of only the newest version in the task activity stream';
    }

    public function getPluginHomepage()
    {
        return "https://github.com/Chaosmeister/KADIFF";
    }
}
