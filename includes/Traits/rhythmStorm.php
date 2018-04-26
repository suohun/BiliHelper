<?php

trait rhythmStorm
{
    //{roomid}
    public $_checkStormApi = 'http://api.live.bilibili.com/lottery/v1/Storm/check?roomid=';
    //
    public $_joinStormApi = 'http://api.live.bilibili.com/lottery/v1/Storm/join';
    //验证码
    public $_stormCaptchaApi = 'https://api.live.bilibili.com/captcha/v1/Captcha/create?_=';

    public function rhythmStormStart($data)
    {
        //检测实名
        $this->realnameCheck();
        if (!$this->_stormFlag) {
            $this->log('Storm: 未实名，跳过节奏风暴功能!', 'green', 'SOCKET');
            return true;
        }
        //随机睡眠
        sleep(rand(5, 15));
        //如果有ID，直接加入
        if (array_key_exists('id', $data)) {
            $this->joinStorm($data['id']);
            return true;
        }
        $this->log('Storm: ' . $data['msg'], 'blue', 'SOCKET');
        //没有ID，则check
        $check = $this->checkStorm($data['roomid']);
        if (is_array($check)) {
            $this->joinStorm($check['id']);
            return true;
        }
        return true;
    }

    //检查
    public function checkStorm($roomid)
    {
        $url = $this->_checkStormApi . $roomid;
        $raw = $this->curl($url);
        $de_raw = json_decode($raw, true);
        if (empty($de_raw['data']) || $de_raw['data']['hasJoin'] != 0) {
            return false;
        }
        return [
            'id' => $de_raw['data']['id'],
            'roomid' => $de_raw['data']['roomid'],
            'num' => $de_raw['data']['num'],
            'time' => $de_raw['data']['time'],
            'content' => $de_raw['data']['content'],
        ];
    }

    //加入
    public function joinStorm($id)
    {
        $data = [
            'id' => $id,
            'color' => '16777215',
            'captcha_token' => '',
            'captcha_phrase' => '',
            'token' => '',
            'csrf_token' => $this->token,
            'data_source_id' => '',
        ];

        $raw = $this->curl($this->_joinStormApi, $data);
        $de_raw = json_decode($raw, true);
        if ($de_raw['code'] == '400') {
            $this->log('Storm: 节奏风暴结束了!s', 'red', 'SOCKET');
        } elseif ($de_raw['code'] == '0' && empty($de_raw['data'])) {
            $this->log('Storm: ' . $de_raw['data']['content'], 'cyan', 'SOCKET');
        } elseif ($de_raw['code'] == '429') {
            $this->log('Storm: 暂时不知道是什么', 'red', 'SOCKET');
        } else {
            print_r($de_raw);
        }

        $this->writeFileTo('./temp/', 'stormjoin.txt', $raw);

        //推送节奏风暴抽奖信息
        $this->infoSendManager('storm', $raw);

        return true;
    }

    //呼出的验证码
    public function ocrCaptcha()
    {
        //TODO 验证码识别
        $url = $this->_stormCaptchaApi . time() . '&width=112&height=32';

    }
}
