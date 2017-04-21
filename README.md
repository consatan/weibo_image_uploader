### PHP 实现的微博图床上传轮子

#### 安装

##### 要求

- PHP 7.0 以上版本
- json 扩展
- openssl 扩展

PHP **5.5, 5.6** 版本请使用 [0.5 版本](https://github.com/consatan/weibo_image_uploader/tree/0.5)

使用 composer (推荐)

```shell
composer require consatan/weibo_image_uploader
```

从 Github 上下载

```shell
git clone https://consatan.github.com/weibo_image_uploader.git
```

#### 使用示例

```php
<?php
// 引入 composer autoload
require './vendor/autoload.php';

$weibo = new Consatan\Weibo\ImageUploader\Client();

// 默认返回的是 https 协议的图床 URL，调用该方法返回的是 http 协议的图床 URL
// $weibo->useHttps(false);

// 上传示例图片
$url = $weibo->upload('./example.jpg', '微博帐号', '微博帐号密码');

// 输出新浪图床 URL
echo $url . PHP_EOL;
```

#### 使用说明

构造函数可传递 `\Psr\Cache\CacheItemPoolInterface` 和 `\GuzzleHttp\ClientInterface`，默认情况下使用文件缓存 cookie 信息，存储在项目根目录的 cache/weibo 文件夹下，缓存的 `key` 使用 `md5` 后的微博用户名，可根据需求将缓存保存到其他适配器中，具体参见 `\Symfony\Cache\Adapter`。

> 关于验证码问题([issue #3](https://github.com/consatan/weibo_image_uploader/issues/3))，可查看 [example/cli.php](https://github.com/consatan/weibo_image_uploader/tree/master/example/cli.php) 示例代码

`Client::upload` 方法的第四个参数允许传递 `Guzzle request` 的参数数组，具体见 [Request Options](http://docs.guzzlephp.org/en/latest/request-options.html)，通过该参数可实现切换代理等操作，如下例：

```php
<?php

// 文件路径
$url1 = $weibo->upload('./example.jpg', '微博帐号1', '密码');
// 同一用户名只有第一次上传需要登入，之后使用缓存的登入 cookie 进行上传
// 如果使用 cookie 上传失败，将尝试重新登入一次，还是失败的话抛出异常
// 除非使用的是无法持久化保存的缓存适配器(如 ArrayAdapter)
// 否则以后同一用户名都将使用缓存的 cookie 进行登入
// echo $weibo->upload('./example.jpg', '微博帐号1', '密码');

// resource
$url2 = $weibo->upload(fopen('./example.jpg', 'r'), '微博帐号2', '密码', [
    'proxy' => 'http://192.168.1.100:8080'
]);

// 字符串
$url3 = $weibo->upload(file_get_contents('./example.jpg'), '微博帐号3', '密码', [
    'proxy' => 'http://192.168.1.200:8090'
]);

// \Psr\Http\Message\StreamInterface
$url4 = $weibo->upload(\GuzzleHttp\Psr7\stream_for(file_get_contents('./example.jpg')), '微博帐号4', '密码', [
    'proxy' => 'http://192.168.1.250:9080'
]);
```

##### 水印选项
```php
// 开启水印
$url = $weibo->upload('./example.jpg', '微博帐号', '密码', ['mark' => true]);

// 水印位置
$url = $weibo->upload('./example.jpg', '微博帐号', '密码', [
    'mark' => true,
    'markpos' => Consatan\Weibo\ImageUploader\Client::MARKPOS_BOTTOM_CENTER,
]);

// 可使用任意的水印暱称（也许以后的版本就会和谐了）
$url = $weibo->upload('./example.jpg', '微博帐号', '密码', ['mark' => true, 'nickname' => '任意暱称']);
```

##### 获取其他尺寸的图片链接
```php
// 默认使用 large (原始)尺寸，此处使用 thumbnail (缩略图) 尺寸
$url = $weibo->upload('./example.jpg', '微博帐号', '密码', [
    'size' => Consatan\Weibo\ImageUploader\Client::IMAGE_SIZE_THUMBNAIL
]);

// 获取多个尺寸的图片链接
$urls = $weibo->upload('./example.jpg', '微博帐号', '密码', ['size' => [
    Consatan\Weibo\ImageUploader\Client::IMAGE_SIZE_SMALL,
    Consatan\Weibo\ImageUploader\Client::IMAGE_SIZE_LARGE,
    Consatan\Weibo\ImageUploader\Client::IMAGE_SIZE_THUMBNAIL,
]]);
// 返回
// array (
//   'small' => 'https://ws2.sinaimg.cn/small/0068M0xKgy1fetd4l7x6vj30bo0bximx.jpg',
//   'large' => 'https://ws2.sinaimg.cn/large/0068M0xKgy1fetd4l7x6vj30bo0bximx.jpg',
//   'thumbnail' => 'https://ws2.sinaimg.cn/thumbnail/0068M0xKgy1fetd4l7x6vj30bo0bximx.jpg',
// )
```

##### upload 方法中，$config 和 $option 参数位置可调换
```php
// 以下 2 种传参顺序最终效果是一致的
$url = $weibo->upload('./example.jpg', '微博帐号', '密码', [
    'size' => Consatan\Weibo\ImageUploader\Client::IMAGE_SIZE_THUMBNAIL
], [
    'proxy' => 'http://192.168.1.250:9080'
]);

$url = $weibo->upload('./example.jpg', '微博帐号', '密码', [
    'proxy' => 'http://192.168.1.250:9080'
], [
    'size' => Consatan\Weibo\ImageUploader\Client::IMAGE_SIZE_THUMBNAIL
]);
```

抛出的所有异常都可通过 `\Consatan\Weibo\ImageUploader\Exception\ImageUploaderException` 接口捕获， 实现该接口的异常都在 [src/Exception](https://github.com/consatan/weibo_image_uploader/tree/master/src/Exception) 目录下。

#### Todo

- [ ] 单元测试
- [x] 获取其他规格的图片 URL（如，small, thumbnail...）
- [x] 添加水印选项
- [x] 实现验证码输入(用户输入)

#### 参考

- [微博官方简易发布器](http://weibo.com/minipublish)
- [微博官方图片上传js](http://js.t.sinajs.cn/t5/home/js/page/content/simplePublish.js)
- [WeiboPicBed](https://github.com/Suxiaogang/WeiboPicBed/blob/master/js/popup.js)
- [超详细的Python实现新浪微博模拟登陆(小白都能懂)](http://www.jianshu.com/p/816594c83c74)
- [调用网页接口实现发微博（PHP实现）](http://andrewyang.cn/post.php?id=1034)
- [weibo-publisher](https://github.com/yangyuan/weibo-publisher)
