<?php

trait activityLottery
{
    //roomid
    public $_checkActiveApi = 'http://api.live.bilibili.com/activity/v1/Raffle/check?roomid=';
    //roomid raffleId
    public $_joinActiveApi = 'http://api.live.bilibili.com/activity/v1/Raffle/join?';
    //app活动抽奖
    public $_appJoinActiveApi = 'http://api.live.bilibili.com/YunYing/roomEvent?';
    //roomid raffleId
    public $_noticeActiveApi = 'http://api.live.bilibili.com/activity/v1/Raffle/notice?';
    //保存活动抽奖信息
    public $_activeLotteryList = [];
    //保存活动信息
    public $_activeList = [];

    //start
    public function activeStart($data)
    {
        $this->log("PCActive: " . $data['msg'], 'blue', 'SOCKET');
        $checkdata = $this->activeCheck($data['real_roomid']);
        $this->log("PCActive: 检查状态", 'blue', 'SOCKET');
        switch ($checkdata['code']) {
            case '-1':
                $this->log("PCActive: " . $checkdata['msg'], 'red', 'SOCKET');
                break;
            case '2':
                $this->log("PCActive: " . $checkdata['msg'], 'red', 'SOCKET');
                break;
            case '444':
                $this->log("PCActive: " . $checkdata['msg'], 'red', 'SOCKET');
                break;
            case '0':
                if (is_array($checkdata['msg'])) {
                    foreach ($checkdata['msg'] as $value) {
                        if (!$value) {
                            continue;
                        }
                        $this->log("PCActive: 编号-" . $value, 'cyan', 'SOCKET');
                        $filename = $this->_userDataInfo['name'] . '-activeLotteryRecord.txt';
                        $temp_data = date("Y-m-d H:i:s") . '|' . 'RoomId:' . $data["real_roomid"] . '|RaffleId:' . $value;
                        $this->writeFileTo('./record/', $filename, $temp_data);
                        //加入查询数组
                        $raffleid = explode("|", $value);
                        $this->_activeLotteryList[] = [
                            'roomid' => $data["real_roomid"],
                            'raffleId' => $raffleid[0],
                        ];
                        //TODO 详细写入信息没做
                    }
                } else {
                    $this->log("PCActive: " . $checkdata['msg'], 'red', 'SOCKET');
                }
                break;
            default:
                var_dump($checkdata['raw']);
                $this->log('PCActive: 关于活动的未知状态', 'red', 'SOCKET');
                break;
        }
    }

    //中奖查询
    public function activeWin()
    {
        if (time() < $this->lock['activeWin']) {
            return true;
        }
        if (!empty($this->_activeLotteryList)) {
            for ($i = 0; $i < 5; $i++) {
                if (!isset($this->_activeLotteryList[$i])) {
                    break;
                }
                $url = $this->_noticeActiveApi . 'roomid=' . $this->_activeLotteryList[0]['roomid'] . '&raffleId=' . $this->_activeLotteryList[0]['raffleId'];
                $raw = $this->curl($url);
                $de_raw = json_decode($raw, true);
                switch ($de_raw['code']) {
                    case  -400:
                        break;
                    case  0:
                        $info = '[PC] RoomId: ' . $this->_activeLotteryList[$i]['roomid'] . '|RaffleId: ';
                        $info .= $this->_activeLotteryList[$i]['raffleId'] . '|获得' . $de_raw['data']['gift_name'] . 'X' . $de_raw['data']['gift_num'];

                        if ($de_raw['data']['gift_name'] != '辣条' && $de_raw['data']['gift_name'] != '') {
                            //推送活动抽奖信息
                            $this->infoSendManager('active', $info);
                        }

                        $this->log("PCActive: " . $info, 'yellow', 'SOCKET');

                        $filename = $this->_userDataInfo['name'] . '-activeLotteryFb.txt';
                        $this->writeFileTo('./record/', $filename, $info);

                        unset($this->_activeLotteryList[$i]);
                        $this->_activeLotteryList = array_values($this->_activeLotteryList);

                        break;
                    default:
                        break;
                }
            }
            $this->lock['activeWin'] = time() + 30;
            return true;
        }
        return true;
    }

    //检查
    public function activeCheck($roomid)
    {
        $url = $this->_checkActiveApi . $roomid;
        $raw = $this->curl($url);
        $de_raw = json_decode($raw, true);

        //钓鱼检测
        if (!$this->liveRoomStatus($roomid)) {
            return [
                'code' => '444',
                'msg' => '该房间存在钓鱼行为!',
            ];
        }

        if (array_key_exists('status', $de_raw['data'])) {
            switch ($de_raw['data']['status']) {
                case '-1':
                    $data = [
                        'code' => $de_raw['data']['status'],
                        'msg' => $de_raw['msg'],
                    ];
                    return $data;
                    break;
                case '2':
                    $data = [
                        'code' => '2',
                        'msg' => '该抽奖已经抽取过',
                    ];
                    return $data;
                    break;
                default:
                    $data = [
                        'code' => $de_raw['data']['status'],
                        'raw' => $de_raw,
                    ];
                    return $data;
                    break;
            }
        } else {
            $data = [
                //'code' => $raw['data']['status'],
                'code' => 0,
                'msg' => [],
            ];
            for ($i = 0; $i < count($de_raw['data']); $i++) {
                if ($i < 2) {
                    $this->randFloat();
                }
                if ($i < 1) {
                    $this->goToRoom($roomid);
                }
                $raffleId = $de_raw['data'][$i]['raffleId'];
                //app
                $this->appActiveJoin($roomid, $raffleId);
                //pc
                $data['msg'][$i] = $this->pcActiveJoin($roomid, $raffleId);
            }
            return $data;
        }
    }

    //加入APP活动抽奖
    public function appActiveJoin($roomid, $raffleId)
    {
        $data = [
            'access_key' => $this->_accessToken,
            'actionKey' => 'appkey',
            'appkey' => $this->_appKey,
            'build' => '414000',
            'device' => 'android',
            'event_type' => 'flower_rain-' . $raffleId,
            'mobi_app' => 'android',
            'platform' => 'android',
            'room_id' => $roomid,
            'ts' => time(),
        ];
        ksort($data);
        $data['sign'] = $this->createSign($data);
        $url = $this->_appJoinActiveApi . http_build_query($data);

        $raw = $this->curl($url, null, true, null, $this->referer);
        $de_raw = json_decode($raw, true);
        if ($de_raw['code'] == '0') {
            $info = '[APP] RoomId: ' . $roomid . '|RaffleId: ' . $raffleId . '|获得' . $de_raw['data']['gift_desc'];
            $this->log("APPActive: " . $info, 'yellow', 'SOCKET');

            $filename = $this->_userDataInfo['name'] . '-activeLotteryFb.txt';
            $this->writeFileTo('./record/', $filename, $info);
            return;
        }
        $this->log("APPActive: " . $de_raw['message'], 'green', 'SOCKET');
        return;
    }

    //加入PC活动抽奖
    public function pcActiveJoin($roomid, $raffleId)
    {
        if (in_array($raffleId, $this->_activeList)) {
            if (count($this->_activeList) > 2000) {
                $this->_activeList = null;
            }
            return false;
        } else {
            $this->_activeList[] = $raffleId;
            $this->_activeList = array_unique($this->_activeList);
        }

        $url = $this->_joinActiveApi . 'roomid=' . $roomid . '&raffleId=' . $raffleId;
        $headers = [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/62.0.3202.94 Safari/537.36',
            'referer: http://live.bilibili.com/' . $roomid,
        ];

        $raw = $this->curl($url, null, true, $headers);
        $de_raw = json_decode($raw, true);

        //打印加入信息
        print_r($de_raw);

        if ($de_raw['code'] == 0) {
            return $raffleId . '|成功，注意查看中奖信息';
        } elseif ($de_raw['message'] == '抽奖已失效！') {
            return $raffleId . '|失效';
        } else {
            return $raffleId . '|失败';
        }
    }


}