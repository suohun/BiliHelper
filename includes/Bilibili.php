<?php
/**
 *  Website: https://i-meto.com/
 *  Author: METO
 *  Version: 0.6.2
 */
header("Content-Type:text/html; charset=utf-8");

require "Traits/customConfig.php";
require "Traits/smallTv.php";
require "Traits/socketHelper.php";
require "Traits/liveGlobal.php";
require "Traits/rhythmStorm.php";
require "Traits/userHelper.php";
require "Traits/otherGift.php";
require "Traits/activityLottery.php";
require "Traits/groupSign.php";
require "Traits/noticeManager.php";

class Bilibili
{
    use customConfig;
    use smallTv;
    use socketHelper;
    use liveGlobal;
    use rhythmStorm;
    use userHelper;
    use otherGift;
    use activityLottery;
    use groupSign;
    use noticeManager;

    // 主播房间 id
    public $roomid = '3746256';
    // UA
    public $useragent = 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36';
    // 调试信息开关
    public $debug = false;
    // 调试信息上色
    public $color = true;
    // 脚本最长运行时间
    public $break = 24 * 60 * 60;
    //自定义代理
    public $_issetProxy = false;
    public $_setProxy = ['ip' => '127.0.0.1', 'port' => '8888'];

    private $prefix = 'https://api.live.bilibili.com/';

    private $referer = 'http://live.bilibili.com/';
    private $temp = array();
    //个人全局信息
    private $_userDataInfo = [
        'name' => '',
        'level' => '',
        'silver' => '',
        'billCoin ' => '',
        'user_intimacy' => '',
        'user_next_intimacy' => '',
        'gold' => '',
    ];

    /**
     * 初始化，设定时间锁
     */
    public function __construct($cookie)
    {
        date_default_timezone_set('Asia/Shanghai');
        $this->cookie = $cookie;
        $this->start = time();

        $this->lock = array(
            // 每日签到
            'sign' => $this->start,
            // 免费宝箱
            'silver' => $this->start,
            // 在线心跳
            'heart' => $this->start,
            // 限时礼物
            'giftheart' => $this->start,
            // 礼物投喂
            'giftsend' => $this->start,
            // 心跳包
            'sheart' => $this->start,
            //中奖查询
            'wincheck' => $this->start,
            //瓜子换硬币
            'silver2coin' => $this->start,
            //客户端心跳
            'appHeart' => $this->start,
            //每日任務
            'dailyTask' => $this->start,
            //每日背包奖励
            'dailyBag' => $this->start,
            //小电视中奖查询
            'smallTvWin' => $this->start,
            //活动中奖查询
            'activeWin' => $this->start,
            //私人发送弹幕
            'privateSendMsg' => $this->start,
            //应援团签到
            'groupSign' => $this->start,
            //实物抽奖
            'drawLottery' => $this->start,
            //刷新cookie 周期20小时
            'refreshCookie' => $this->start + 240 * 60 * 60,
            //刷新token 周期100小时
            'refreshToken' => $this->start + 480 * 60 * 60,
        );
    }

    public function init()
    {
        $this->cookie = $this->trimAll($this->cookie);
        $api = $this->prefix . 'room/v1/Room/room_init?id=' . $this->roomid;
        $raw = $this->curl($api);
        $data = json_decode($raw, true);

        if ($data['code'] != 0) {
            $this->log($data['msg'], 'bg_red', '初始化');
            die;
        }

        $this->roomid = $data['data']['room_id'];
        $this->ruid = $data['data']['uid'];

        $api = $this->prefix . 'live_user/v1/UserInfo/get_info_in_room?roomid=' . $this->roomid;
        $raw = $this->curl($api);
        $data = json_decode($raw, true);

        if ($data['code'] != 0) {
            $this->log($data['msg'], 'bg_red', '初始化');
            die;
        }

        $this->uid = $data['data']['info']['uid'];
        $this->_userDataInfo['name'] = $data['data']['info']['uname'];

        preg_match('/bili_jct=(.{32})/', $this->cookie, $token);
        $this->token = isset($token[1]) ? $token[1] : '';
        if (empty($this->token)) {
            $this->log('cookie 不完整，部分功能已经禁用', 'red', '初始化');
        }
    }

    /**
     * 脚本入口
     */
    public function run()
    {
        $this->init();
        for ($idx = 0; $idx < 3; $idx++) {
            while (true) {
                if (!$this->sign()) break;
                if (!$this->heart()) break;
                if (!$this->silver()) break;
                if (!$this->giftheart()) break;
                if (!$this->smallTvWin()) break;
                if (!$this->activeWin()) break;
                if (!$this->dailyTask()) break;
                if (!$this->dailyBag()) break;
                foreach ($this->_biliTaskskip as $key => $value) {
                    if ($value)
                        if (!$this->$key()) break;
                }
                //刷新信息
                if (!$this->refreshInfo()) break;
                if (!$this->customerAction()) break;
                sleep(1);
            }
            sleep(10);
        }

        if (isset($this->callback)) {
            call_user_func($this->callback);
            exit(1);
        }
        exit(0);
    }

    private function sign()
    {
        if (time() < $this->lock['sign']) {
            return true;
        }

        $api = $this->prefix . 'sign/doSign';
        $raw = $this->curl($api);
        $data = json_decode($raw, true);

        // 已经签到
        if ($data['code'] == -500) {
            $this->log($data['msg'], 'green', 'SIGN');
            $this->lock['sign'] = time() + 24 * 60 * 60;
            return true;
        }
        // 签到失败
        if ($data['code'] != 0) {
            $this->log($data['msg'], 'bg_red', 'SIGN');
            return false;
        }
        // 签到成功
        $this->log($data['msg'], 'blue', 'SIGN');

        //推送签到结果信息
        $this->infoSendManager('todaySign', $data['msg']);

        $api = $this->prefix . 'giftBag/sendDaily?_=' . round(microtime(true) * 1000);
        $raw = $this->curl($api);
        $data = json_decode($raw, true);
        if ($data['code'] != 0) {
            $this->log($data['msg'], 'bg_red', 'SIGN');
            return false;
        }

        $api = $this->prefix . 'sign/GetSignInfo';
        $raw = $this->curl($api);
        $data = json_decode($raw, true);

        $this->log("获得 " . $data['data']['text'] . $data['data']['specialText'], 'green', 'SIGN');
        return true;
    }

    //PC心跳
    private function heart()
    {
        if (time() < $this->lock['heart']) {
            return true;
        }

        $api = $this->prefix . 'User/userOnlineHeart';
        $raw = $this->curl($api);
        $data = json_decode($raw, true);
        if ($data['code'] != 0) {
            $this->log($data['msg'], 'bg_red', 'HEART');
            return false;
        }
        $this->lock['heart'] = time() + 5 * 60;

        $api = $this->prefix . 'User/getUserInfo?ts=' . round(microtime(true) * 1000);
        $raw = $this->curl($api);
        $data = json_decode($raw, true);

        //全局个人信息
        $this->_userDataInfo = [
            'name' => $data['data']['uname'],
            'level' => $data['data']['user_level'],
            'silver' => $data['data']['silver'],
            'billCoin' => $data['data']['billCoin'],
            'user_intimacy' => $data['data']['user_intimacy'],
            'user_next_intimacy' => $data['data']['user_next_intimacy'],
            'gold' => $data['data']['gold'],
        ];

        $level = $data['data']['user_level'];
        $a = $data['data']['user_intimacy'];
        $b = $data['data']['user_next_intimacy'];
        $per = round($a / $b * 100, 3);
        $this->log('PCHeart: OK!', 'magenta', 'HEART');
        $this->log("level:$level exp:$a/$b ($per%)", 'magenta', 'HEART');
        return true;
    }

    public function giftsend()
    {
        if (time() < $this->lock['giftsend'] || empty($this->token)) {
            return true;
        }
        $this->lock['giftsend'] = time() + 3600;

        $this->log('开始翻动礼物', 'green', 'FEED');
        $api = $this->prefix . 'gift/v2/gift/bag_list';
        $raw = $this->curl($api);
        $data = json_decode($raw, true);

        if ($data['code'] != 0) {
            $this->log($data['msg'], 'bg_red', 'FEED');
            return false;
        }

        foreach ($data['data']['list'] as $vo) {
            if (abs($vo['expire_at'] - time()) > 3600) {
                continue;
            }
            $payload = array(
                'uid' => $this->uid,
                'gift_id' => $vo['gift_id'],
                'ruid' => $this->ruid,
                'gift_num' => $vo['gift_num'],
                'bag_id' => $vo['bag_id'],
                'platform' => 'pc',
                'biz_code' => 'live',
                'biz_id' => $this->roomid,
                'rnd' => mt_rand() % 10000000000,
                'storm_beat_id' => 0,
                'metadata' => '',
                'token' => '',
                'csrf_token' => $this->token
            );

            //TODO  有待优化
            $length = mb_strlen(http_build_query($payload));
            $headers = array(
                'Host: api.live.bilibili.com',
                'Connection: keep-alive',
                'Content-Length: ' . $length,
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Origin: http://live.bilibili.com',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36',
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Referer: http://live.bilibili.com/',
                'Accept-Encoding: gzip, deflate, br',
                'Accept-Language: zh-CN,zh;q=0.8',
                'Cookie: ' . $this->cookie,
            );

            $api = $this->prefix . 'gift/v2/live/bag_send';
            $res = $this->curl($api, $payload, true, $headers);
            $res = json_decode($res, true);

            if ($res['code']) {
                $this->log("{$res['msg']}", 'red', 'FEED');
            } else {
                $this->log("成功向 https://live.bilibili.com/{$this->roomid} 投喂了 {$vo['gift_num']} 个 {$vo['gift_name']}", 'green', 'FEED');
            }
        }
        return true;
    }

    private function giftheart()
    {
        if (time() < $this->lock['giftheart']) {
            return true;
        }
        $this->lock['giftheart'] = time() + 5 * 60;

        $api = $this->prefix . 'gift/v2/live/heart_gift_receive?roomid=' . $this->roomid;
        $raw = $this->curl($api);
        $data = json_decode($raw, true);

        if ($data['code'] == -403) {
            $this->log($data['msg'], 'magenta', 'GIFTS');
            $this->lock['giftheart'] = time() + 60 * 60;
            $this->curl($this->prefix . 'eventRoom/index?ruid=17561885');
            return true;
        }
        if ($data['code'] != 0) {
            $this->log($data['msg'], 'bg_red', 'GIFTS');
            return false;
        }

        if ($data['data']['heart_status'] == 0) {
            $this->log('没有礼物可以领了呢', 'magenta', 'GIFTS');
            $this->lock['giftheart'] = time() + 60 * 60;
        }

        if (isset($data['data']['gift_list'])) {
            foreach ($data['data']['gift_list'] as $vo) {
                $this->log("{$data['msg']}，礼物 {$vo['gift_name']} ({$vo['day_num']}/{$vo['day_limit']})", 'magenta', 'GIFTS');
            }
        }
        return true;
    }

    private function silver()
    {
        if (time() < $this->lock['silver']) {
            return true;
        }

        if (!isset($this->temp['task'])) {
            // 查询宝箱数量
            $api = $this->prefix . 'lottery/v1/SilverBox/getCurrentTask';
            $raw = $this->curl($api);
            $data = json_decode($raw, true);
            // 今日已经领完
            if ($data['code'] == -10017) {
                $this->lock['silver'] = time() + 60 * 60;
                $this->log($data['msg'], 'blue', 'SBOX');
                return true;
            }
            // 领取失败
            if ($data['code'] != 0) {
                $this->log($data['msg'], 'bg_red', 'SBOX');
                return false;
            }
            $this->log("领取宝箱，内含 {$data['data']['silver']} 瓜子，需要 {$data['data']['minute']} 分钟开启", 'cyan', 'SBOX');

            $this->temp['task'] = array(
                'start' => $data['data']['time_start'],
                'end' => $data['data']['time_end'],
            );
            $this->lock['silver'] = $data['data']['time_end'] + 5;
            $this->log(sprintf(
                "等待 %s 领取",
                date('H:i:s', $this->lock['silver'])
            ), 'blue', 'SBOX');

            return true;
        }
        /*
                $captcha = $this->captcha();
                $api = $this->prefix . sprintf(
                        'lottery/v1/SilverBox/getAward?time_start=%s&end_time=%s&captcha=%s',
                        $this->temp['task']['start'],
                        $this->temp['task']['end'],
                        $captcha
                    );
        */
        $data = [
            'access_key' => $this->_accessToken,
            'actionKey' => 'appkey',
            'appkey' => $this->_appKey,
            'build' => '414000',
            'device' => 'android',
            'mobi_app' => 'android',
            'platform' => 'android',
            'time_end' => $this->temp['task']['end'],
            'time_start' => $this->temp['task']['start'],
            'ts' => time(),
        ];
        ksort($data);
        $data['sign'] = $this->createSign($data);

        $api = 'https://api.live.bilibili.com/mobile/freeSilverAward?' . http_build_query($data);
        $raw = $this->curl($api);
        $data = json_decode($raw, true);

        if ($data['code'] != 0) {
            $this->log($data['msg'], 'bg_red', 'SBOX');
        } else {
            $this->log("领取成功，silver: {$data['data']['silver']}(+{$data['data']['awardSilver']})", 'cyan', 'SBOX');
        }

        unset($this->temp['task']);
        $this->lock['silver'] = time() + 5;
        return true;
    }

    //自定义操作
    private function customerAction()
    {
        //接收socket
        $resp = $this->socketHelperStart();

        //解析参数
        $data = $this->liveGlobalStart($resp);

        //发送客户端心跳
        $this->appHeart();
        //礼物相关
        if (!is_array($data)) {
            $this->log($data, 'green', 'SOCKET');
        } else {
            switch ($data['type']) {
                case 'smalltv':
                    //小电视
                    $this->smallTvStart($data);
                    break;
                case 'storm':
                    //TODO 节奏风暴暂时搁置
                    $this->rhythmStormStart($data);
                    break;
                case 'active':
                    //TODO 活动抽奖
                    $this->activeStart($data);
                    break;
                case 'unkown':
                    //返回新未知类型
                    $this->log('CMD: 数据包不全或有新的类型' . $data['raw'], 'green', 'SOCKET');
                    break;
                default:
                    //不知道什么
                    $this->log('UNKOWN: 占位', 'magenta', 'SOCKET');
                    break;
            }
        }
        return true;
    }

    public function captcha()
    {
        $this->log("开始做幼儿算术", "blue", 'SBOX');

        $api = $this->prefix . 'lottery/v1/SilverBox/getCaptcha?ts=' . time();
        $raw = $this->curl($api);
        $data = json_decode($raw, true);
        $exploded = explode(',', $data['data']['img'], 2);
        $encoded = $exploded[1];
        $decoded = base64_decode($encoded);

        $image = imagecreatefromstring($decoded);
        $width = imagesx($image);
        $height = imagesy($image);
        for ($i = 0; $i < $height; $i++) {
            for ($j = 0; $j < $width; $j++) {
                $grey[$i][$j] = (imagecolorat($image, $j, $i) >> 16) & 0xFF;
            }
        }
        for ($i = 0; $i < $width; $i++) {
            $vis[$i] = 0;
        }
        for ($i = 0; $i < $height; $i++) {
            for ($j = 0; $j < $width; $j++) {
                $vis[$j] |= $grey[$i][$j] < 220;
            }
        }
        for ($i = 0; $i < $height; $i += 2) {
            for ($j = 0; $j < $width; $j += 2) {
                echo $grey[$i][$j] < 220 ? '■' : '□';
            }
            echo "\n";
        }

        $OCR = array(
            '0' => '0111111111111111111111111111110111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111110000000000000000000000011111111000000000000000000000001111111100000000000000000000000111111110000000000000000000000011111111000000000000000000000001111111100000000000000000000000111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111110111111111111111111111111111110',
            '1' => '0000001110000000000000000000000001111111000000000000000000000011111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111',
            '2' => '0111111111000000000000000001111111111111100000000000000011111111111111110000000000000111111111111111111000000000000111111111111111111100000000001111111111111110000000000000011111111111111111000000000000011111111101111111100000000000111111111100111111110000000001111111111000011111111000000001111111110000001111111100000011111111110000000111111111111111111111100000000011111111111111111111100000000001111111111111111111000000000000111111111111111111000000000000011110111111111110000000000000001111',
            '3' => '0111111111000000000001111111110111111111100000000000111111111111111111110000000000011111111111111111111000000000001111111111111111111100000000000111111111111110000000000000000000000011111111000000000111000000000001111111100000000111110000000000111111110000000011111000000000011111111000000011111110000000001111111100000011111111100000000111111111111111111111111111111111111111111111111111111111111111111111111111111110111111111111111111111111111110001111111111111111111111111110000011111111111111',
            '4' => '00000000000000000000111110000000000000000000000011111111000000000000000000001111111111100000000000000000111111111111110000000000000011111111111111111000000000001111111111111111111100000000111111111111111110011110000001111111111111111000001111000000111111111111100000000111100000011111111110000000000011110000001111111000001111111111111111111111100000000111111111111111111110000000000011111111111111111110000000000001111111111111111111000000000000111111111111111111100000000000000000000011110000000000000000000000000001111000000',
            '5' => '1111111111111110000001111111110111111111111111000000111111111111111111111111100000011111111111111111111111110000001111111111111111111111111000000111111111111110000000111100000000000011111111000000011110000000000001111111100000001111000000000000111111110000000111100000000000011111111000000011110000000000001111111100000001111000000000000111111110000000111111111111111111111111000000011111111111111111111111100000001111111111111111111111110000000111111111111111111111111000000001111111111111111110',
            '6' => '1111111111111111111111111111110111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111110000000001111000000000011111111000000000111100000000001111111100000000011110000000000111111110000000001111000000000011111111000000000111100000000001111111100000000011110000000000111111111111100001111111111111111111111111110000111111111111111111111111111000011111111111111111111111111100001111111111111111110111111110000011111111111111110',
            '7' => '1111111100000000000000000000000111111110000000000000000000000011111111000000000000000000000001111111100000000000000000000000111111110000000000000000000011111110000000000000000000011111111111000000000000000011111111111111100000000000011111111111111111110000000011111111111111111111111000011111111111111111111110111111111111111111111111110000011111111111111111111110000000001111111111111111110000000000000111111111111110000000000000000011111111110000000000000000000001111110000000000000000000000000',
            '8' => '0111111111110000011111111111110111111111111100011111111111111111111111111111011111111111111111111111111111111111111111111111111111111111111111111111111111111110000100111111100000000011111111000000001111110000000001111111100000000111110000000000111111110000000011111000000000011111111000000001111110000000001111111100000001111111000000000111111111111111111111111111111111111111111111111111111111111111111111111111111110111111111111111111111111111110001111111111111110111111111110000011111111111110',
            '9' => '0111111111111111100000111111110111111111111111111000011111111111111111111111111100001111111111111111111111111110000111111111111111111111111111000011111111111110000000000111100000000011111111000000000011110000000001111111100000000001111000000000111111110000000000111100000000011111111000000000011110000000001111111100000000001111000000000111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111110111111111111111111111111111110',
            '+' => '00000000000000001111000000000000000000000000000111100000000000000000000000000011110000000000000000000000000001111000000000000000000000000000111100000000000000000000000000011110000000000000000000001111111111111111000000000000000111111111111111100000000000000011111111111111110000000000000001111111111111111000000000000000111111111111111100000000000000000000011110000000000000000000000000001111000000000000000000000000000111100000000000000000000000000011110000000000000000000000000001111000000000000000000000000000111100000000000',
            '-' => '000000000000000001111000000000000000000000000000111100000000000000000000000000011110000000000000000000000000001111000000000000000000000000000111100000000000000000000000000011110000000000000000000000000001111000000000000000000000000000111100000000000000000000000000011110000000000',
        );

        $result = '';
        for ($k = 0; $k < $width; $k++) {
            if ($vis[$k]) {
                $L = $R = $k;
                while ($vis[$R] == 1) {
                    $R++;
                }
                $str = '';
                for ($j = $L; $j < $R; $j++) {
                    for ($i = 4; $i <= 34; $i++) {
                        $str .= $grey[$i][$j] < 220 ? '1' : '0';
                    }
                }
                $max = 0;
                foreach ($OCR as $key => $vo) {
                    similar_text($str, $vo, $per);
                    if ($per > $max) {
                        $max = $per;
                        $ch = $key;
                    }
                }
                $result .= $ch;
                $k = $R;
            }
        }

        $ans = eval("return $result;");
        $this->log("好耶！ $result = $ans", 'blue', 'SBOX');
        return $ans;
    }

    public function log($message, $color = 'default', $type = '')
    {
        $colors = array(
            'none' => "",
            'black' => "\033[30m%s\033[0m",
            'red' => "\033[31m%s\033[0m",
            'green' => "\033[32m%s\033[0m",
            'yellow' => "\033[33m%s\033[0m",
            'blue' => "\033[34m%s\033[0m",
            'magenta' => "\033[35m%s\033[0m",
            'cyan' => "\033[36m%s\033[0m",
            'lightgray' => "\033[37m%s\033[0m",
            'darkgray' => "\033[38m%s\033[0m",
            'default' => "\033[39m%s\033[0m",
            'bg_red' => "\033[41m%s\033[0m",
        );
        $this->msg = $message;
        $date = date('[Y-m-d H:i:s] ');
        if (!empty($type)) {
            $type = "[$type] ";
        }
        if (!$this->color) {
            $color = 'none';
        }
        echo sprintf($colors[$color], $date . $type . $message) . PHP_EOL;
    }

    public function curl($url, $data = null, $log = true, $headers = null, $referer = null, $useragent = false, $header = false)
    {
        if ($this->debug) {
            $this->log('>>> ' . $url, 'lightgray');
        }
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HEADER, $header);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        curl_setopt($curl, CURLOPT_IPRESOLVE, 1);
        if (!empty($headers)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_URL, $url);
        if (!$referer) {
            curl_setopt($curl, CURLOPT_REFERER, $this->prefix . $this->roomid);
        } else {
            curl_setopt($curl, CURLOPT_REFERER, $this->referer . $referer);
        }

        curl_setopt($curl, CURLOPT_COOKIE, $this->cookie);
        if ($useragent) {
            curl_setopt($curl, CURLOPT_USERAGENT, $this->useragent);
        }
        if ($this->_issetProxy) {
            //代理服务器地址
            curl_setopt($curl, CURLOPT_PROXY, $this->_setProxy['ip']);
            curl_setopt($curl, CURLOPT_PROXYPORT, $this->_setProxy['port']); //代理服务器端口
        }
        if (!empty($data)) {
            if (is_array($data)) {
                $data = http_build_query($data);
            }
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        $result = curl_exec($curl);
        curl_close($curl);
        if ($this->debug && $log) {
            $this->log('<<< ' . $result, 'yellow');
        }
        return $result;
    }

    //appCurl
    public function appCurl($url, $data)
    {
        $newdata = http_build_query($data);
        $length = mb_strlen($newdata);

        $headers = [
            'Display-ID: 6580464-1522208142',
            'Buvid: 53CA3838-C28F-4E42-9B97-886D3CF7AD4B36100infoc',
            'User-Agent: Mozilla/5.0 BiliDroid/5.22.1 (bbcallen@gmail.com)',
            'Device-ID: Pw83Bj8LbgppUTJTL1Mv',
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . $length,
            'Host: api.live.bilibili.com',
            'Connection: Keep-Alive',
            'Accept-Encoding: gzip',
            'Cookie: ' . $this->cookie,
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        curl_setopt($curl, CURLOPT_IPRESOLVE, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);

        if ($this->_issetProxy) {
            //代理服务器地址
            curl_setopt($curl, CURLOPT_PROXY, $this->_setProxy['ip']);
            curl_setopt($curl, CURLOPT_PROXYPORT, $this->_setProxy['port']); //代理服务器端口
        }
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $newdata);

        $result = curl_exec($curl);
        curl_close($curl);
        return $result;
    }

    // 调试函数
    public function pr($var)
    {
        print_r($var);
        exit();
    }
}