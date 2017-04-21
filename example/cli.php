<?php
/**
 * 在 CLI 下当出现验证码时，要求用户输入验证码
 */

require '../vendor/autoload.php';

$client = new Consatan\Weibo\ImageUploader\Client();
$username = 'weibo_username';
$password = 'password';

while (true) {
    try {
        echo $client->upload('./example.png', $username, $password);
        break;
    } catch (Consatan\Weibo\ImageUploader\Exception\RequirePinException $e) {
        echo '验证码图片位置：' . $e->getMessage() . PHP_EOL .  '输入验证码以继续：';
        if (!$client->login($username, $password, stream_get_line(STDIN, 1024, PHP_EOL))) {
            echo '登入失败';
            break;
        }
    }
}
