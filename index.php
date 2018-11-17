<?php
/**
 * 搬瓦工补货提醒
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2018/7/25
 * @time 13:40
 */

error_reporting(E_ERROR);
ini_set('display_errors', 1);
set_time_limit(0);

define('IS_CLI', PHP_SAPI === 'cli' ? true : false);
define('DS', DIRECTORY_SEPARATOR);
define('VENDOR_PATH', realpath('vendor') . DS);

date_default_timezone_set('Asia/Shanghai');

// Server酱微信推送url
define('SC_URL', 'https://pushbear.ftqq.com/sub');

/**
 * 定制错误处理
 */
register_shutdown_function('customize_error_handler');
function customize_error_handler()
{
    if (!is_null($error = error_get_last())) {
        system_log($error);

        $response = [
            'STATUS' => 9,
            'MESSAGE_ARRAY' => array(
                array(
                    'MESSAGE' => '程序执行出错，请稍后再试。'
                )
            ),
            'SYSTEM_DATE' => date('Y-m-d H:i:s')
        ];

        header('Content-Type: application/json');

        echo json_encode($response);
    }
}

/**
 * 记录程序日志
 * @param array|string $logContent 日志内容
 * @param string 文件名（不含后缀）
 * @param string $mark LOG | ERROR | WARNING 日志标志
 */
function system_log($logContent, $logFile = '', $mark = 'ERROR')
{
    try {
        $logPath = __DIR__ . '/logs/' . date('Y/m/') . ($logFile ? date('d/') : '');
        $logFile = $logPath . ($logFile ?: date('d')) . '.php';

        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
            chmod($logPath, 0777);
        }

        $handle = fopen($logFile, 'a'); // 文件不存在则自动创建

        if (!filesize($logFile)) {
            fwrite($handle, "<?php defined('VENDOR_PATH') or die('No direct script access allowed.'); ?>" . PHP_EOL . PHP_EOL);
            chmod($logFile, 0666);
        }

        fwrite($handle, $mark . ' - ' . date('Y-m-d H:i:s') . ' --> ' . (IS_CLI ? 'CLI' : 'URI: ' . $_SERVER['REQUEST_URI'] . PHP_EOL . 'REMOTE_ADDR: ' . $_SERVER['REMOTE_ADDR'] . PHP_EOL . 'SERVER_ADDR: ' . $_SERVER['SERVER_ADDR']) . PHP_EOL . (is_string($logContent) ? $logContent : var_export($logContent, true)) . PHP_EOL); // CLI模式下，$_SERVER中几乎无可用值

        fclose($handle);
    } catch (\Exception $e) {
        // do nothing
    }
}

require VENDOR_PATH . 'autoload.php';
require __DIR__ . DS . 'serverchan.php';

use Curl\Curl;

class BWG
{
    /**
     * @var BWG
     */
    protected static $instance;

    /**
     * @var int curl超时秒数
     */
    protected static $timeOut = 44;

    /**
     * 匹配VPS基础信息
     * @var string
     */
    protected static $baseInfoRegex = '/SSD:\s*(?P<ssd>[^<]+)[^:]+:\s*(?P<ram>[^<]+)[^:]+:\s*(?P<cpu>[^<]+)[^:]+:\s*(?P<transfer>[^<]+)[^:]+:\s*(?P<link_speed>[^<]+)[^:]+(?:(?<=Location):\s*[^:]+|):\s*(?P<vr_type>[^<]+)/i';

    /**
     * 匹配优惠码
     * @var string
     */
    protected static $promoCodeRegex = '/promo\s*code:\s*([^\n]+)/i';

    /**
     * 匹配价格
     * @var string
     */
    protected static $priceRegex = '/<option[^>]+>.*?(?=\$)([^<]+)<\/option>/i';

    /**
     * 匹配机房位置
     * @var string
     */
    protected static $locationRegex = '/<option\s+[^>]+>([^<\$]+)<\/option>/i';

    /**
     * 日志路径
     * @var string
     */
    protected static $logPath;

    public function __construct()
    {
        static::$logPath = __DIR__ . '/logs/' . date('Y/m/d/');
    }

    public static function instance()
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * 补货提醒
     * @param array $sendKey 微信推送通道
     * @param integer $pid 商品id
     * @param string $pName 商品别名
     * @param integer $aff aff
     * @return null
     * @throws ErrorException
     */
    public function notice($sendKey, $pid, $pName, $aff = '')
    {
        if (file_exists(static::$logPath . 'today_notified_pid_' . $pid . '.php')) { // 防止同一天内重复提醒
            return false;
        }

        $curl = new Curl();
        $curl->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36');
        $curl->setReferrer('https://bwh1.net/cart.php');
        $curl->setHeaders([
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
        ]);
        $curl->setTimeout(static::$timeOut);
        $curl->setOpts([
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_AUTOREFERER => true
        ]);
        $curl->get('https://bwh1.net/cart.php', [
            'a' => 'add',
            'pid' => $pid,
        ]);

        if ($curl->error) {
            ServerChan::send($sendKey['report_errors'], '小主，Curl 请求页面出错', "详情：\n\n" . $curl->errorCode . ' - ' . $curl->errorMessage);

            return false;
        }

        $errors = '';
        if ($curl->response && stripos($curl->response, 'Out of Stock') === false) { // 有货了
            if (!preg_match(self::$baseInfoRegex, $curl->response, $vps_base_info)) {
                $errors .= "匹配基础信息出错\n";
            }
            if (!preg_match_all(self::$priceRegex, $curl->response, $price)) {
                $errors .= "匹配价格出错\n";
            }
            if (!preg_match(self::$promoCodeRegex, $curl->response, $promo_code)) {
                $errors .= "匹配优惠码出错\n";
            }
            if (!preg_match_all(self::$locationRegex, $curl->response, $location)) {
                $errors .= "匹配机房位置信息出错\n";
            }
            if ($errors) {
                ServerChan::send(
                    $sendKey['report_errors'],
                    sprintf('小主，「%s」疑似有货了，但正则匹配出了一点小状况', $pName),
                    sprintf("我取得的不寻常的情况如下：\n%s\n这可能是因为搬瓦工页面改版导致无法正确匹配，可能需要修改正则表达式，应该**不是真的补货**。请小主[亲自前往确定是否补货](https://bwh1.net/" . ($aff ? 'aff.php?aff=' . $aff : 'cart.php?a=add') . "&pid=" . $pid . ")。", $errors)
                );
            }

            $notice_content = '';
            if ($vps_base_info) {
                $notice_content = $pName . '的详情如下所述';
                $notice_content .= sprintf(
                    "\n\n#### 硬盘：%s\n#### 运存：%s\n#### CPU：%s\n#### 流量：%s\n#### 带宽：%s\n#### 虚拟技术：%s\n\n",
                    $vps_base_info['ssd'],
                    $vps_base_info['ram'],
                    $vps_base_info['cpu'],
                    $vps_base_info['transfer'],
                    $vps_base_info['link_speed'],
                    $vps_base_info['vr_type']
                );
            }
            if ($price[1]) {
                $notice_content .= sprintf("#### 可选价格为：\n%s\n\n", implode("\n", $price[1]));
            }
            if ($promo_code) {
                $notice_content .= sprintf("#### 优惠码：\n%s\n\n", $promo_code[1]);
            }
            if ($location[1]) {
                $notice_content .= sprintf("#### 可选机房为：\n%s\n\n", implode("\n", $location[1]));
            }
            $notice_content .= "\n[立即前往查看](https://bwh1.net/" . ($aff ? 'aff.php?aff=' . $aff : 'cart.php?a=add') . "&pid=" . $pid . ")\n\n![通讯酱](http://wx4.sinaimg.cn/mw690/0060lm7Tly1fvtvodr7ijj30ia0lkagm.jpg)\n笨笨的机器人敬上";

            $errors || ServerChan::send($sendKey['public_notice'], sprintf('小主，「%s」补货了，赶快去抢购吧~', $pName), $notice_content);
            system_log(sprintf('在%s这个时刻，「%s」' . ($errors ? '疑似' : '') . "补货了，我通知了所有人，写这条内容是为了防止在同一天内重复提醒。今次取得的页面信息为：\n%s", date('Y-m-d H:i:s'), $pName, $curl->response), 'today_notified_pid_' . $pid, 'NOTICE');
        }

        $curl->close();

        return $curl->response;
    }
}

try {
    $config = require __DIR__ . DS . 'config.php';
    foreach ($config['products'] as $pid => $pName) { // 多个VPS
        BWG::instance()->notice(
            $config['sendKey'],
            $pid,
            $pName,
            $config['aff']
        );

        usleep(600);
    }

    echo '执行成功。';
} catch (\Exception $e) {
    system_log($e->getMessage());
}