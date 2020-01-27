<?php
/**
 * DokuWiki Plugin elasticsearch (DocParser Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

require_once dirname(__FILE__) . '/../vendor/autoload.php';

/**
 * Convert a file to text and metainfos
 */
class helper_plugin_elasticsearch_docparser extends DokuWiki_Plugin
{
    /**
     * @var array maps extensions to parsers. A parser may be a local cli tool (file is passed as argument)
     * or an URL accepting input by PUT (like Apache Tika). They need to return plain text or a JSON response.
     */
    protected $parsers;

    /**
     * Maps fields returned by Tika or other JSON returning parsers to our own field names.
     * Order does matter. Last non-empty field wins.
     */
    const FILEDMAP = [
        'title' => 'title',
        'dc:title' => 'title',
        'content' => 'content',
        'body' => 'content',
        'dc:description' => 'content',
        'X-TIKA:content' => 'content',
    ];

    /**
     * Load the parser setup
     */
    public function __construct()
    {
        $parsers = [];
        $configs = explode("\n", $this->getConf('mediaparsers'));

        foreach ($configs as $c) {
            $p = explode(';', $c);
            $parsers[$p[0]] = $p[1];
        }
        array_walk($parsers, 'trim');

        $this->parsers = $parsers;
    }

    /**
     * Parse the given file
     *
     * Returns an array with the following keys
     *
     * title - will be filled with the basename if no title could be extracted
     * content - the content to index
     * mime - the mime type as determined by us
     * ext - the extension of the file
     * lang - the language code the file is written in
     *
     * Returns false if the file can not be parsed and thus should not be indexed
     *
     * @param string $file
     * @return false|array
     * @fixme we may want to throw exceptions here for better error handling
     */
    public function parse($file)
    {
        if (!file_exists($file)) return false;
        list($ext, $mime) = mimetype($file);
        if (!$ext) return false;
        if (!isset($this->parsers[$ext])) return false;

        $result = $this->runParser($file, $this->parsers[$ext]);
        if ($result === false) return false;

        // defaults
        $data = [
            'title' => basename($file),
            'content' => '',
            'mime' => $mime,
            'ext' => $ext,
            'language' => '',
        ];

        // add what we got from the parser
        $data = array_merge($data, $this->processParserResult($result));

        // add language info
        $data['language'] = $this->detectLanguage($data['content']);

        return $data;
    }

    /**
     * Execute the parser on the given file
     *
     * The parser can be an URL accepting a PUT request or a local command
     *
     * @param string $file
     * @param string $parser
     * @return bool|string
     */
    protected function runParser($file, $parser)
    {
        if (preg_match('/^https?:\/\/', $parser)) {
            $http = new \dokuwiki\HTTP\DokuHTTPClient();
            $http->timeout = 90;
            $ok = $http->sendRequest($parser, io_readFile($file, false), 'PUT');
            if ($ok) {
                return $http->resp_body;
            }
            return false;
        } elseif (is_executable($parser)) {
            $output = [];
            $ok = 0;
            exec($parser . ' ' . escapeshellarg($file), $output, $ok);
            if ($ok === 0) {
                return join('', $output);
            }
            return false;
        }

        return false;
    }

    /**
     * @param string $result The string returned by the parser, might be json
     * @return array
     */
    protected function processParserResult($result)
    {
        // decode json responses
        if (
            (
                $result[0] !== '[' && $result[0] !== '{'
            )
            ||
            (
                ($decoded = json_decode($result, true)) === null
            )
        ) {
            return [
                'content' => $result,
            ];
        };
        // we only want the first result from an Apache Tika response
        if (isset($decoded[0]) && is_array($decoded[0])) {
            $decoded = $decoded[0];
        }

        $data = [];
        foreach (self::FILEDMAP as $from => $to) {
            if (!blank($decoded[$from])) $data[$to] = trim($decoded[$from]);
        }
        return $data;
    }

    /**
     * Return the language the given body was written in
     *
     * Will always return the wiki default language unless the translation plugin is installed.
     *
     * @param string $body
     * @return string The detected language
     * @fixme handle languages like 'pt-br' correctly
     * @fixme maybe make this optional in favor of the namespace method
     */
    protected function detectLanguage($body)
    {
        global $conf;

        /** @var helper_plugin_translation $trans */
        $trans = plugin_load('helper', 'translation');
        if ($trans === null) return $conf['lang'];

        $ld = new \LanguageDetection\Language();

        $langs = array_keys($ld->detect($body)->whitelist(...$trans->translations)->close());
        return array_shift($langs);
    }
}
