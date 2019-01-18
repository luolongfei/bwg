<?php
/**
 * 配置
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2018/7/28
 * @time 17:40
 */

return [
    'sendKey' => [ // 微信推送通道
        'public_notice' => '5939-xxx', // 用于向公众推送补货提醒
        'report_errors' => '5666-xxx', // 用于向开发者报告程序错误
    ],
    'products' => [ // pid => 商品别名
        71 => 'CN2 GIA限量乞丐版',
        61 => '香港月付9.9刀',
        // 43 => '年付19.99刀可转CN2',
        // 56 => 'CN2 年付29.99',
    ],
    'aff' => 24499,
    'maxAttemptsNum' => 4, // 出错自动重试次数，建议最大不超过5
];