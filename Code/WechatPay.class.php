<?php
//WechatPay类
class WechatPayAction extends CommonAction{
    /*
     * PHP+移动端微信支付
     * param1:uid 用户id *
     * param2:money 支付金额 *
     */
    public function wechatPayApk(){
        //接收参数
        $money = $_REQUEST['money'];
        $uid = $_REQUEST['uid'];
        //服务器生成内部订单
        $postData = array();
        $postData['uid'] = $uid;
        $postData['money'] = $money;
        $postData['add_time'] = time();
        $payObj = M('pay.pay_order',null,'pay');
        $pay_re = $payObj->add($postData);
        if($pay_re){
            //内部订单生成成功，调用微信统一下单接口
            $wechatData=array();
            //组装参数
            $wechatData['appid'] = '12345678910';//公众帐号ID
            $wechatData['body'] = '微信充值';//商品描述
            $wechatData['detail'] = '充值'.$money.'元';
            $wechatData['mch_id'] = '12345678910';//商户号
            //生成随机字符串
            $str = array_merge(range(0,9),range('a','z'),range('A','Z'));
            shuffle($str);
            $wechatData['nonce_str'] = implode('',array_slice($str,0,16));//随机字符串
            $wechatData['notify_url'] = 'http://localhost/www/WechatPay/notifyUrl';//回调地址
            $wechatData['out_trade_no'] = $pay_re;//商户订单号（内部订单号，不超过32位）
            $wechatData['spbill_create_ip'] = $_SERVER['REMOTE_ADDR'];//终端ip
            $wechatData['total_fee'] = $money*100;//总金额，单位为分
            $wechatData['trade_type'] = 'APP';//交易类型

            //生成第一次签名(注意:签名的顺序是按照ASCII码排序的,也就是调用签名函数前传入的数组的键名要按a-z排好)
            $SignTemp = $this->sign($wechatData,'abcdefghijklmn');//调用签名函数，拼接上API密钥
            $wechatData['sign'] = strtoupper($SignTemp);//签名
            //转换xml
            $xml = $this->array_to_xml($wechatData);//调用数组转换xml函数
            //curl调用微信支付接口
            $url = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL,$url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $xml);
            $result = curl_exec($curl);
            curl_close($curl);
            //获取微信返回的xml数据，将xml转换成数组
            $result = $this->xml_to_array($result);//调用xml转换数组函数
            $result = json_encode($result['xml']);
            $result = str_replace('"',"\"",$result);

            /****下面是将微信返回的数据打包输出给移动端***/

            //由于移动端不能暴露appid、商户号、API密钥等信息，所以在后端进行再次签名
            $apk_re = array();
            $apk_re['appid'] = $result['appid'];
            $apk_re['noncestr'] = $result['nonce_str'];
            $apk_re['package'] = 'Sign=WXPay';
            $apk_re['partnerid'] = '12345678910';
            $apk_re['prepayid'] = $result['prepay_id'];
            $apk_re['timestamp'] = time();
            $sign = $this->sign($apk_re,'abcdefghijklmn');
            $result['timestamp'] = $apk_re['timestamp'];
            $result['outOrderId'] = $pay_re;
            $result['sign'] = strtoupper(md5($sign));
            $result = json_encode($result);
            $re['data'] = $result;
            //输出给移动端之后，在移动端那边进行调起支付界面，当支付完成后，微信会把数据返回到设置好的notify_url
            //notify_url这个方法要进行修改服务器内部订单状态，给用户到账金币
            echo json_encode($re);exit;
        }else{
            //失败则前端提示
            echo 0;
        }
    }

    /*
     *微信回调方法
     */
    public function notifyUrl(){
        //获取返回的xml
        $xml = file_get_contents('php://input');
        $result = $this->xml_to_array($xml);
        //组装参数更新订单状态，到账金额
        $postData = array();
        $postData['order_id'] = $result['xml']['out_trade_no'];//服务器内部订单ID
        $postData['trade_no'] = $result['xml']['transaction_id'];//微信订单ID
        $postData['order_status'] = $result['xml']['result_code'] == 'SUCCESS'?1:2;//订单状态
        $postData['reach_money'] = $result['xml']['cash_fee']/100;//到账金额
        $orderObj = M('pay.pay_order',null,'pay');
        $re = $orderObj->updateOrderStatus($postData);
        //无论更新成功，失败都把结果转换成xml输出返回给微信
        if($re && $re['status']){
            $result = array();
            $result['return_code'] = 'SUCCESS';
            $result['return_msg'] = 'OK';
            //转成xml
            $result = $this->array_to_xml($result);
            echo $result;
        }elseif($re['data'] == 'order_is_change'){
            $result = array();
            $result['return_code'] = 'SUCCESS';
            $result['return_msg'] = 'OK';
            //转成xml
            $result = $this->array_to_xml($result);
            echo $result;
        }else{
            $result = array();
            $result['return_code'] = 'FAIL';
            //转成xml
            $result = $this->array_to_xml($result);
            echo $result;
        }
    }

    /*
     * 签名方法
     */
    function sign ($params,$key){
        unset($params['key']);
        unset($params['sign']);
        unset($params['_URL_']);
        ksort($params);
        reset($params);
        $sign = '';
        foreach ($params as $index => $value){
            $sign.= $index.'='.$value.'&';
        }
        $sign = rtrim($sign, '&').'&key='.$key;
        if($key == 'bh31543iaj228vdn27vn2578vn20923j'){
            return $sign;
        }
        $sign_value = md5($sign);
        return $sign_value;
    }

    /**
     *  将数组转换为xml
     */
    function array_to_xml($data, $root = true){
        $str="";
        if($root)$str .= "<xml>";
        foreach($data as $key => $val){
            if(is_array($val)){
                $child = changeXml($val, false);
                $str .= "<$key>$child</$key>";
            }else{
                $str.= "<$key>".$val."</$key>";
            }
        }
        if($root)$str .= "</xml>";
        return $str;
    }

    /*
     * xml转换成数组
     */
    public function xml_to_array( $xml )
    {
        $reg = "/<(\\w+)[^>]*?>([\\x00-\\xFF]*?)<\\/\\1>/";
        if(preg_match_all($reg, $xml, $matches))
        {
            $count = count($matches[0]);
            $arr = array();
            for($i = 0; $i < $count; $i++)
            {
                $key = $matches[1][$i];
                $val = $this->xml_to_array( $matches[2][$i] );  // 递归
                if(array_key_exists($key, $arr))
                {
                    if(is_array($arr[$key]))
                    {
                        if(!array_key_exists(0,$arr[$key]))
                        {
                            $arr[$key] = array($arr[$key]);
                        }
                    }else{
                        $arr[$key] = array($arr[$key]);
                    }
                    $arr[$key][] = $val;
                }else{
                    $arr[$key] = $val;
                }
            }
            //去掉CDATA
            foreach($arr as $k=>$v){
                $arr[$k] = str_replace('<![CDATA[','',$v);
                $arr[$k] = str_replace(']]>','',$arr[$k]);
            }
            return $arr;
        }else{
            return $xml;
        }
    }
}