<?php
/**
 * Server酱微信推送
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2018/7/25
 * @time 13:40
 */

use Curl\Curl;

class ServerChan
{
    /**
     * 微信推送
     * @param string $sendKey 不同的$sendKey对应不同的人，不同的人对应不同的通道
     * @param string $title
     * @param string $content
     * @return object
     * @throws \ErrorException
     * @throws \Exception
     */
    public static function send($sendKey, $title, $content)
    {
        $pushInfo = [
            'sendkey' => $sendKey,
            'text' => $title,
            'desp' => str_replace("\n", "\n\n", $content) // Server酱接口限定，两个\n等于一个换行
        ];

        $curl = new Curl();
        $curl->get(SC_URL, $pushInfo);

        if ($curl->error) {
            throw new \Exception('Curl 微信推送错误 #' . $curl->errorCode . ' - ' . $curl->errorMessage . "\n");
        }

        $curl->close();

        if ($curl->response->code !== 0) {
            system_log(array_merge(['error' => 'Server酱微信推送接口没有正确响应，本次推送可能不成功', 'errorReason' => $curl->response->message], $pushInfo));
        }

        return $curl->response;
    }
}