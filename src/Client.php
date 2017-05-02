<?php declare(strict_types=1);

/*
 * This file is part of the Consatan\Weibo\ImageUploader package.
 *
 * (c) Chopin Ngo <consatan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Consatan\Weibo\ImageUploader;

use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Http\Message\StreamInterface;
use Consatan\Weibo\ImageUploader\Exception\IOException;
use Consatan\Weibo\ImageUploader\Exception\RequestException;
use Consatan\Weibo\ImageUploader\Exception\BadResponseException;
use Consatan\Weibo\ImageUploader\Exception\RuntimeException;
use Consatan\Weibo\ImageUploader\Exception\RequirePinException;
use Consatan\Weibo\ImageUploader\Exception\ImageUploaderException;

/**
 * Class Client
 *
 * @static string getImageUrl(
 *     string $pid,
 *     string $size = self::IMAGE_SIZE_ORIGNAL,
 *     bool $https = true
 * )
 * @method self __construct(
 *     \Psr\Cache\CacheItemPoolInterface $cache = null,
 *     \GuzzleHttp\ClientInterface $http = null
 * )
 * @method self setNickname(string $nickname = '')
 * @method string getNickname()
 * @method self setMark(bool $mark)
 * @method bool getMark()
 * @method self setMarkPos(int $pos)
 * @method int getMarkPos()
 * @method self setImageSizes(string|string[] $sizes)
 * @method string[] getImageSizes()
 * @method self useHttps(bool $https = true)
 * @method self setHttps(bool $https = true)
 * @method bool login(string $username, string $password, bool|string $cache = true)
 * @method string upload(
 *     string|resource|\Psr\Http\Message\StreamInterface $file,
 *     string $username = '',
 *     string $password = '',
 *     array $config = [],
 *     array $option = []
 * )
 */
class Client
{
    const IMAGE_SIZE_LARGE = 'large';

    const IMAGE_SIZE_SMALL = 'small';

    const IMAGE_SIZE_SQUARE = 'square';

    const IMAGE_SIZE_MIDDLE = 'bmiddle';

    const IMAGE_SIZE_ORIGNAL = 'large';

    const IMAGE_SIZE_BMIDDLE = 'bmiddle';

    const IMAGE_SIZE_THUMBNAIL = 'thumbnail';

    const IMAGE_SIZE_THUMB180 = 'thumb180';

    const IMAGE_SIZE_MW690 = 'mw690';

    const IMAGE_SIZE_MW1024 = 'mw1024';

    /**
     * 水印位置，图片右下角位置
     *
     * @var int
     */
    const MARKPOS_BOTTOM_RIGHT = 1;

    /**
     * 水印位置，图片底部中间位置
     *
     * @var int
     */
    const MARKPOS_BOTTOM_CENTER = 2;

    /**
     * 水印位置，图片中心位置
     *
     * @var int
     */
    const MARKPOS_CENTER = 3;

    /**
     * 允许的图片尺寸
     *
     * @var string[]
     */
    public static $imageSize = [
        'mw690' => 'mw690',
        'large' => 'large',
        'small' => 'small',
        'square' => 'square',
        'mw1024' => 'mw1024',
        'middle' => 'bmiddle',
        'orignal' => 'large',
        'bmiddle' => 'bmiddle',
        'thumb180' => 'thumb180',
        'thumbnail' => 'thumbnail',
    ];

    /**
     * http 实例
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $http;

    /**
     * cache 实例
     *
     * @var \Psr\Cache\CacheItemPoolInterface
     */
    protected $cache;

    /**
     * cookie 实例
     *
     * @var \GuzzleHttp\Cookie\CookieJarInterface
     */
    protected $cookie;

    /**
     * 返回的图片 URL 协议，https 或 http
     *
     * @var string
     */
    protected $protocol = 'https';

    /**
     * User-Agent
     *
     * @var string
     */
    protected $ua = '';

    /**
     * 微博帐号
     *
     * @var string
     */
    protected $username = '';

    /**
     * 微博密码
     *
     * @var string
     */
    protected $password = '';

    /**
     * 是否添加水印
     *
     * @var bool
     */
    protected $mark = false;

    /**
     * 水印位置
     *
     * @var int
     */
    protected $markpos = self::MARKPOS_BOTTOM_RIGHT;

    /**
     * 微博暱称
     *
     * @var string
     */
    protected $nickname = '';

    /**
     * 要获取的图片尺寸
     *
     * @var string[]
     */
    protected $imageSizes = [self::IMAGE_SIZE_LARGE];

    /**
     * 微博暱称缓存，由于微博暱称允许更改，所以该缓存不持久化
     *
     * @var array
     */
    protected $nicknames = [];

    /**
     * @param \Psr\Cache\CacheItemPoolInterface $cache (null) Cache 实例
     *     未设置默认使用文件缓存，保存在项目根路径的 cache/weibo 目录。
     * @param \GuzzleHttp\ClientInterface $http (null) Guzzle client 实例
     *
     * @return self
     */
    public function __construct(CacheItemPoolInterface $cache = null, ClientInterface $http = null)
    {
        $this->cookie = new CookieJar();
        $this->cache = null !== $cache ? $cache : new FilesystemAdapter('weibo', 0, __DIR__ . '/../cache');

        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:45.0) Gecko/20100101 Firefox/45.0';
        if (null !== $http) {
            $this->http = $http;
            $header = $http->getConfig('headers');
            // 如果是默认 UA 替换成模拟的 UA
            if (0 === strpos($header['User-Agent'], 'GuzzleHttp')) {
                $this->ua = $ua;
            }

            if (($cookie = $http->getConfig('cookies')) instanceof CookieJarInterface) {
                $this->cookie = $cookie;
            }
        } else {
            $this->http = new HttpClient(['headers' => ['User-Agent' => $ua]]);
        }
    }

    /**
     * 获取图片链接
     *
     * @param string $pid 微博图床pid，或者微博图床链接。传递的是链接的话，
     *     仅是将链接的尺寸更改为目标尺寸而已。
     * @param string $size (self::IMAGE_SIZE_LARGE) 图片尺寸
     * @param bool $https (true) 是否使用 https 协议
     *
     * @return string 图片链接
     *
     * @throws Consatan\Weibo\ImageUploader\Exception\RuntimeException
     *     当 $pid 既不是 pid 也不是合法的微博图床链接时
     */
    public static function getImageUrl(string $pid, string $size = self::IMAGE_SIZE_ORIGNAL, bool $https = true)
    {
        $pid = trim($pid);
        $size = strtolower($size);
        $size = isset(self::$imageSize[$size]) ? $size : self::IMAGE_SIZE_ORIGNAL;

        // 传递 pid
        if (preg_match('/^[a-zA-Z0-9]{32}$/', $pid) === 1) {
            return ($https ? 'https' : 'http') . '://' . ($https ? 'ws' : 'ww')
                . ((crc32($pid) & 3) + 1) . ".sinaimg.cn/" . self::$imageSize[$size]
                . "/$pid." . ($pid[21] === 'g' ? 'gif' : 'jpg');
        }

        // 传递 url
        $url = $pid;
        $imgUrl = preg_replace_callback('/^(https?:\/\/[a-z]{2}\d\.sinaimg\.cn\/)'
            . '(large|bmiddle|mw1024|mw690|small|square|thumb180|thumbnail)'
            . '(\/[a-z0-9]{32}\.(jpg|gif))$/i', function ($match) use ($size) {
                return $match[1] . self::$imageSize[$size] . $match[3];
            }, $url, -1, $count);

        if ($count === 0) {
            throw new RuntimeException('Invalid URL: ' . $url);
        }
        return $imgUrl;
    }

    /**
     * 设置(水印中的)微博用户暱称，当前版本允许自定义水印微博用户暱称，
     * 以后版本该功能可能会被和谐。
     *
     * @param string $nickname ('') 微博用户暱称
     * @return self
     */
    public function setNickname(string $nickname = '')
    {
        $nickname = trim($nickname);
        if ($nickname === '') {
            if (isset($this->nicknames[$this->username])) {
                $nickname = $this->nicknames[$this->username];
            } else {
                $this->request('http://weibo.com/minipublish', function (string $content) use (&$nickname) {
                    if (preg_match('/\$CONFIG\[\'nick\'\]\s*=\s*\'(.*)\'\s*;/m', $content, $match) === 1) {
                        $nickname = trim($match[1]);
                        $this->nicknames[$this->username] = $nickname;
                    }
                });
            }
        }
        $this->nickname = $nickname;
        return $this;
    }

    /**
     * 获取微博用户暱称
     *
     * @return string 微博用户暱称
     */
    public function getNickname()
    {
        return $this->nickname;
    }

    /**
     * 设置水印开关
     *
     * @param bool $mark true 开启水印，false 关闭水印
     *
     * @return self
     */
    public function setMark(bool $mark)
    {
        $this->mark = $mark;
        return $this;
    }

    /**
     * 获取水印开关
     *
     * @return bool
     */
    public function getMark()
    {
        return $this->mark;
    }

    /**
     * 设置水印位置
     *
     * @param int $pos 水印位置
     *
     * @return self
     */
    public function setMarkPos(int $pos)
    {
        $this->markpos = $pos >= 1 && $pos <= 3 ? $pos : self::MARKPOS_BOTTOM_RIGHT;
        return $this;
    }

    /**
     * 获取水印位置
     *
     * @return int
     */
    public function getMarkPos()
    {
        return $this->markpos;
    }

    /**
     * 设置图片尺寸
     *
     * @param string[]|string 图片尺寸
     *
     * @return self
     */
    public function setImageSizes($sizes)
    {
        if (is_string($sizes)) {
            $sizes = [$sizes];
        }

        $this->imageSizes = [];
        if (is_array($sizes)) {
            foreach ($sizes as $size) {
                if (isset(self::imageSize[$size])) {
                    $this->imageSizes[] = $size;
                }
            }
        }

        if (sizeof($this->imageSizes) === 0) {
            $this->imageSizes = [self::IMAGE_SIZE_LARGE];
        }
        return $this;
    }

    /**
     * 获取图片尺寸
     *
     * @return array
     */
    public function getImageSizes()
    {
        return $this->imageSizes;
    }

    /**
     * 图床 URL 使用 https 协议
     *
     * @param bool $https (true) 默认使用 https，设置为 false 使用 http
     *
     * @return self
     */
    public function useHttps(bool $https = true): self
    {
        $this->protocol = $https ? 'https' : 'http';
        return $this;
    }

    /**
     * $this->useHttps() 的别名
     *
     * @see $this->useHttps()
     */
    public function setHttps(bool $https = true): self
    {
        return $this->useHttps($https);
    }

    /**
     * 上传图片
     *
     * @param mixed $file 要上传的文件，可以是文件路径、文件内容（字符串）、文件资源句柄
     *     或者实现了 \Psr\Http\Message\StreamInterface 接口的实体类。
     * @param string $username ('') 微博帐号
     * @param string $password ('') 微博密码
     * @param array $config ([]) 图片上传参数，该参数和 $option 参数位置可调换
     *     - mark: (bool, default=false) 图片水印开关
     *     - markpos: (int, default=1) 图片水印位置，仅开启图片水印时有效
     *         1: 图片右下角
     *         2: 图片底部居中位置
     *         3: 图片垂直和水平居中位置
     *     - nickname: (string) 水印上的暱称，默认使用上传的微博帐号暱称，当前该参数
     *         允许自定义暱称，但不保证以后版本中会被微博屏蔽掉。仅开启图片水印时有效
     *     - size: (string[], default=['large']) 获取不同尺寸的图片链接。当仅需要一个
     *         尺寸时，返回图片 URL；当需要多个尺寸时，返回索引数组，key 为尺寸，
     *         value 为对应尺寸的图片 URL
     * @param array $option ([]) 具体见 Guzzle request 的请求参数说明
     *
     * @return string|array 上传成功返回对应的图片 URL，上传失败返回空字符串。
     *     当 $config['size'] 需要多个尺寸的图片 URL 时，返回索引数组，key 为尺寸，
     *     value 为对应尺寸的图片 URL，当上传失败时返回空数组。
     *
     * @throws \Consatan\Weibo\ImageUploader\Exception\IOException 读取上传文件失败时
     * @throws \Consatan\Weibo\ImageUploader\Exception\RuntimeException 参数类型错误时
     * @throws \Consatan\Weibo\ImageUploader\Exception\BadResponseException 登入失败时
     * @throws \Consatan\Weibo\ImageUploader\Exception\RequestException 请求失败时
     *
     * @see http://docs.guzzlephp.org/en/latest/request-options.html
     */
    public function upload(
        $file,
        string $username = '',
        string $password = '',
        array $config = [],
        array $option = []
    ) {
        $img = $file;
        $imgUrl = '';

        if (is_string($file)) {
            // 如果是文件路径，根据文件路径获取文件句柄
            if (file_exists($file) && false === ($img = @fopen($file, 'r'))) {
                throw new IOException("无法读取文件 $file.");
            }
        } else {
            if (!is_resource($file) && !($file instanceof StreamInterface)) {
                throw new RuntimeException('Upload `$file` MUST a type of string or resource '
                    . 'or instance of \Psr\Http\Message\StreamInterface, '
                    . gettype($file) . ' given.');
            }
        }

        // 如果有提供用户名密码的话，从缓存中获取登入 cookie
        if ('' !== $username && '' !== $password && !$this->login($username, $password, true)) {
            // 登入失败
            throw new BadResponseException('登入失败，请检查用户名或密码是否正确');
        }

        $header = [
            'Referer' => 'http://weibo.com/minipublish',
            'Accept' => 'text/html, application/xhtml+xml, image/jxr, */*',
        ];

        // 允许 $config 和 $option 参数位置调换
        if (!empty($config) && !isset($config['mark']) && !isset($config['markpos'])
            && !isset($config['nickname']) && !isset($config['size'])) {
            $tmp = $config;
            $config = $option;
            $option = $tmp;
        }

        if (!empty($option) && (isset($option['mark']) || isset($option['markpos'])
            || isset($option['nickname']) || isset($option['size']))) {
            $tmp = $config;
            $config = $option;
            $option = $tmp;
        }

        if (!empty($option)) {
            if (isset($option['headers'])) {
                foreach ($option['headers'] as $key => $val) {
                    $name = strtolower($key);
                    // 删除 headers 中用户自定义的必须参数
                    if ('referer' === $name || 'accept' === $name) {
                        unset($option['headers'][$key]);
                    }
                    $header[$key] = $val;
                }
            }

            // 删除不允许修改的参数 或 不能和 multipart 一起使用的参数
            unset($option['json'], $option['body'], $option['form_params'], $option['handler']);
            unset($option['query'], $option['allow_redirects'], $option['multipart'], $option['headers']);
        }

        // 创建重试中间件
        $stack = HandlerStack::create(new CurlHandler());
        $stack->push(Middleware::retry(function ($retries, $req, $rsp, $error) use (&$imgUrl, &$config) {
            $imgUrl = '';
            if ($rsp !== null) {
                $statusCode = $rsp->getStatusCode();

                if (300 <= $statusCode && 303 >= $statusCode && !empty(($url = $rsp->getHeader('Location')))) {
                    $url = $url[0];
                    if (false !== ($query = parse_url($url, PHP_URL_QUERY))) {
                        parse_str($query, $pid);
                        if (isset($pid['pid'])) {
                            $pid = $pid['pid'];
                            /**
                             * pid 相关信息查看下面链接，可通过搜索 crc32 查看相关代码
                             * @link http://js.t.sinajs.cn/t5/home/js/page/content/simplePublish.js
                             *
                             * 根据上面 js 文件代码来看，cdn 的编号应该由以下代码来决定
                             * (($pid[9] === 'w' ? (crc32($pid) & 3) : (hexdec(substr($pid, 19, 2)) & 0xf)) + 1)
                             * 然而当前能访问的 cdn 编号只有 1 ~ 4，而且基本上任意的
                             * cdn 编号都能访问到同一资源，所以根据 pid 来判断 cdn 编号
                             * 当前实际上没啥意义了，有些实现甚至直接写死 cdn 编号
                             */
                            $imgUrl = self::getImageUrl($pid, $config['size'][0], $this->protocol === 'https');

                            // 停止重试
                            return false;
                        }
                    }
                }
            }

            // 上传失败，进行重试判断，$retries 参数由 0 开始
            if ($retries === 0) {
                // 进行非缓存登入
                if (!$this->login($this->username, $this->password, false)) {
                    // 如果非缓存登入失败，抛出异常
                    throw new BadResponseException('登入失败，请检查用户名或密码是否正确');
                }

                // 重试上传
                return true;
            } else {
                // 已是第二次上传失败，停止重试
                return false;
            }
        }));

        $config = array_merge([
            'mark' => $this->mark,
            'markpos' => $this->markpos,
            'nickname' => $this->nickname,
            'size' => $this->imageSizes,
        ], $config);
        if (is_string($config['size'])) {
            $config['size'] = [$config['size']];
        }

        $option = array_merge($option, [
            'handler' => $stack,
            'query' => [
                'ori' => '1',
                'marks' => '1',
                'app' => 'miniblog',
                's' => 'rdxt',
                'markpos' => $config['mark'] ? $config['markpos'] : '',
                'logo' => '',
                'nick' => '@' . $config['nickname'],
                'url' => '',
                'cb' => 'http://weibo.com/aj/static/upimgback.html?_wv=5&callback=STK_ijax_'
                    . substr(strval(microtime(true) * 1000), 0, 13) . '1',
            ],
            'multipart' => [[
                'name' => 'pic1',
                'contents' => $img,
            ]],
            'headers' => $header,
            // 使用常规上传，将重定向到 query 里的 cb URL
            // pid 已包含在 URL 里，故毋须进行重定向
            'allow_redirects' => false,
        ]);

        $this->applyOption($option);

        try {
            $this->http->request('POST', 'http://picupload.service.weibo.com/interface/pic_upload.php', $option);
        } catch (GuzzleException $e) {
            throw new RequestException('请求失败. ' . $e->getMessage(), $e->getCode(), $e);
        }

        if (sizeof($config['size']) > 1) {
            $imgs = [];

            foreach ($config['size'] as $size) {
                $imgs[$size] = self::getImageUrl($imgUrl, $size);
            }
            return $imgs;
        } else {
            return $imgUrl;
        }
    }

    /**
     * 模拟登入微博，以获取登入信息 cookie。
     *
     * @param string $username 微博帐号，微博帐号的 md5 值将作为缓存 key
     * @param string $password 微博密码
     * @param bool|string $cache (true) 是否使用缓存的cookie进行登入，如果缓存不存在则创建；
     *     当传入的是字符串时，该参数为验证码，不使用缓存登入。
     *
     * @return bool 登入成功与否
     *
     * @throws \Consatan\Weibo\ImageUploader\Exception\RequirePinException 需要输入验证码时，
     *     Exception message 为验证码图片的本地路径
     * @throws \Consatan\Weibo\ImageUploader\Exception\IOException 缓存持久化失败时
     */
    public function login(string $username, string $password, $cache = true): bool
    {
        $this->password = $password;
        $this->username = trim($username);
        $cacheKey = md5($this->username);

        if (is_string($cache)) {
            $pin = $cache;
            $cache = false;
        } else {
            $pin = '';
            $cache = (bool)$cache;
        }

        // 如果使用缓存登入且缓存里有对应用户名的缓存cookie的话，则不需要登入操作
        if ($cache && ($cookie = $this->cache->getItem($cacheKey)->get()) instanceof CookieJarInterface) {
            $this->cookie = $cookie;
            $this->setNickname();
            return true;
        }

        return $this->request($this->ssoLogin($pin), function (string $content) use ($cacheKey) {
            if (1 === preg_match('/"\s*result\s*["\']\s*:\s*true\s*/i', $content)) {
                $this->persistenceCache($cacheKey, $this->cookie);
                $this->setNickname();
                return true;
            }

            return false;
        }, [
            // 该请求会返回 302 重定向，所以开启 allow_redirects
            'allow_redirects' => true,
            'headers' => [
                'Referer' => 'http://login.sina.com.cn/sso/login.php?client=ssologin.js(v1.4.18)',
            ],
        ]);
    }

    /**
     * 获取 SSO 登入信息
     *
     * @param string $pin ('') 验证码
     *
     * @return string 返回登入结果的重定向的 URL
     *
     * @throws \Consatan\Weibo\ImageUploader\Exception\RequirePinException 需要输入验证码时，
     *     Exception message 为验证码图片的本地路径
     * @throws \Consatan\Weibo\ImageUploader\Exception\BadResponseException 响应非预期或未输入验证码时
     */
    protected function ssoLogin(string $pin = ''): string
    {
        $params = [];
        $pin = trim($pin);
        $cacheKey = md5($this->username) . '_preLogin';
        if ($this->cache->hasItem($cacheKey)) {
            // 从缓存中获取上次 preLogin 的数据
            $data = $this->cache->getItem($cacheKey)->get();
            if (is_array($data) && isset(
                $data['pcid'],
                $data['servertime'],
                $data['nonce'],
                $data['pubkey'],
                $data['rsakv'],
                $data['pinImgPath']
            )) {
                if ($pin !== '') {
                    $params['pcid'] = $data['pcid'];
                    $params['door'] = $pin;
                } else {
                    if (file_exists($data['pinImgPath'])) {
                        // 如果已经缓存过验证码图片，就不需要重复获取
                        throw new RequirePinException($data['pinImgPath']);
                    }
                }
                // 删除本地验证码图片
                @unlink($data['pinImgPath']);
            }
            // 删除 prelogin 缓存，如果提供了验证码，则验证码都是一次性的，
            // 不管验证成功与否，都没有必要继续缓存；如果没提供验证码，则会
            // 抛出 RequirePinException 异常，也就不会执行删除缓存的代码。
            $this->cache->deleteItem($cacheKey);
        }

        if (empty($params)) {
            $data = $this->preLogin();
            if (isset($data['showpin']) && (int)$data['showpin']) {
                // 要求输入验证码
                throw new RequirePinException($this->getPin($data));
            }
        }

        $msg = "{$data['servertime']}\t{$data['nonce']}\n{$this->password}";

        return $this->request(
            'http://login.sina.com.cn/sso/login.php?client=ssologin.js(v1.4.18)',
            function (string $content) {
                if (1 === preg_match('/location\.replace\s*\(\s*[\'"](.*?)[\'"]\s*\)\s*;/', $content, $match)) {
                    // 返回重定向URL
                    if (false !== stripos(($url = trim($match[1])), 'retcode=4049')) {
                        throw new BadResponseException('登入失败，要求输入验证码');
                    }
                    return $url;
                } else {
                    throw new BadResponseException("登入响应非预期结果: $content");
                }
            },
            [
                'headers' => ['Referer' => 'http://weibo.com/login.php'],
                'form_params' => $params + [
                    'entry' => 'weibo',
                    'gateway' => '1',
                    'from' => '',
                    'savestate' => '7',
                    'useticket' => '1',
                    'pagerefer' => '',
                    'vsnf' => '1',
                    'su' => base64_encode(urlencode($this->username)),
                    'service' => 'miniblog',
                    'servertime' => $data['servertime'],
                    'nonce' => $data['nonce'],
                    'pwencode' => 'rsa2',
                    'rsakv' => $data['rsakv'],
                    // 加密用户登入密码
                    'sp' => bin2hex(rsa_encrypt($msg, '010001', $data['pubkey'])),
                    'sr' => '1440*900',
                    'encoding' => 'UTF-8',
                    // 该参数为加载 preLogin 页面到提交登入表单的间隔时间
                    // 此处使用 float 是为了兼容 32 位系统
                    'prelt' => (int)round((microtime(true) - $data['preloginTime']) * 1000),
                    'url' => 'http://weibo.com/ajaxlogin.php?'
                        . 'framelogin=1&callback=parent.sinaSSOController.feedBackUrlCallBack',
                    'returntype' => 'META'
                ],
            ],
            'POST'
        );
    }

    /**
     * 登入前获取相关信息操作
     *
     * @return array 返回登入前信息数组
     *
     * @throws \Consatan\Weibo\ImageUploader\Exception\BadResponseException 响应非预期时
     */
    protected function preLogin(): array
    {
        $ts = microtime(true);
        return $this->request(
            'http://login.sina.com.cn/sso/prelogin.php?entry=weibo&callback=sinaSSOController.preloginCallBack&su='
                . urlencode(base64_encode(urlencode($this->username)))
                . '&rsakt=mod&checkpin=1&client=ssologin.js(v1.4.18)&_='
                . substr(strval($ts * 1000), 0, 13),
            function (string $content) use ($ts) {
                if (1 === preg_match('/^sinaSSOController.preloginCallBack\s*\((.*)\)\s*$/', $content, $match)) {
                    $json = json_decode($match[1], true);
                    if (isset($json['nonce'], $json['rsakv'], $json['servertime'], $json['pubkey'])) {
                        // 记录访问时间戳，登入时 prelt 参数需要用到
                        $json['preloginTime'] = $ts;
                        return $json;
                    }
                    throw new BadResponseException("PreLogin 响应非预期结果: $match[1]");
                } else {
                    throw new BadResponseException("PreLogin 响应非预期结果: $content");
                }
            },
            ['headers' => ['Referer' => 'http://weibo.com/login.php']]
        );
    }

    /**
     * 获取验证码图片
     *
     * @param string $pcid  preLogin 阶段获取到的 pcid
     *
     * @return string  验证码图片的本地路径
     *
     * @throws \Consatan\Weibo\ImageUploader\Exception\IOException 创建或保存验证码图片失败时，
     *     或持久化缓存失败时
     */
    protected function getPin(array $data): string
    {
        $url = 'http://login.sina.com.cn/cgi/pin.php?r=' . rand(100000000, 99999999) . '&s=0&p=' . $data['pcid'];
        $this->request($url, function ($content) use (&$data) {
            if (false === ($path = tempnam(sys_get_temp_dir(), 'WEIBO'))) {
                throw new IOException('创建验证码图片文件失败');
            }

            if (false === file_put_contents($path, $content)) {
                throw new IOException('保存验证码图片失败');
            }
            $data['pinImgPath'] = $path;
        }, ['headers' => [
            'Accept' => 'image/png, image/svg+xml, image/*;q=0.8, */*;q=0.5',
            'Referer' => 'http://www.weibo.com/login.php',
        ]]);

        $cacheKey = md5($this->username);
        // 持久化 preLogin 获取的数据
        $this->persistenceCache($cacheKey . '_preLogin', $data);
        // 持久化 cookie 保存当前状态
        $this->persistenceCache($cacheKey, $this->cookie);

        return $data['pinImgPath'];
    }

    /**
     * 封装的 HTTP 请求方法
     *
     * @param string $url 请求 URL
     * @param callable $fn 回调函数
     * @param array $option ([]) 请求参数，具体见 Guzzle request 的请求参数说明
     * @param string $method ('GET') 请求方法
     *
     * @return mixed 返回 `$fn` 回调函数的调用结果
     *
     * @throws \Consatan\Weibo\ImageUploader\Exception\RequestException 请求失败时
     * @throws \Consatan\Weibo\ImageUploader\Exception\RuntimeException 获取响应内容失败时
     *
     * @see http://docs.guzzlephp.org/en/latest/request-options.html
     */
    protected function request(string $url, callable $fn, array $option = [], string $method = 'GET')
    {
        $this->applyOption($option);

        try {
            $rsp = $this->http->request($method, $url, $option);
            if (200 === ($statusCode = $rsp->getStatusCode())) {
                try {
                    $content = $rsp->getBody()->getContents();
                } catch (\RuntimeException $e) {
                    throw new RuntimeException('获取响应内容失败 :' . $e->getMessage());
                }
                return $fn($content);
            } elseif (300 <= $statusCode && 303 >= $statusCode) {
                // 如果禁止重定向(只有禁止重定向才会捕获到300 ~ 303代码)
                // 则把重定向 URL 当参数传递
                return $fn(empty(($rsp = $rsp->getHeader('Location'))) ? '' : $rsp[0]);
            } else {
                throw new RequestException("请求失败. HTTP code: $statusCode " . $rsp->getReasonPhrase());
            }
        } catch (GuzzleException $e) {
            throw new RequestException('请求失败. ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 填充必须的 http header
     *
     * @param array &$option
     *
     * @return void
     */
    private function applyOption(array &$option)
    {
        if ('' !== $this->ua && !isset($option['headers']['User-Agent'])) {
            $option['headers']['User-Agent'] = $this->ua;
        }

        if (!isset($option['cookies'])) {
            $option['cookies'] = $this->cookie;
        }
    }

    /**
     * 持久化缓存
     *
     * @param string $key 缓存key
     * @param mixed $value 缓存数据
     *
     * @return void
     *
     * @throws Consatan\Weibo\ImageUploader\Exception\IOException 持久化失败时
     */
    private function persistenceCache(string $key, $value)
    {
        $this->cache->deleteItem($key);
        // 新建 或 获取 CacheItemInterface 实例
        $cache = $this->cache->getItem($key);
        // 设置 cookie 信息
        $cache->set($value);
        // 缓存持久化
        if (!$this->cache->save($cache)) {
            throw new IOException('持久化缓存失败');
        }
    }
}
