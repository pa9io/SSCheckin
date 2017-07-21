<?php
namespace Home\Controller;

use Think\Controller;

class TaskController extends Controller
{
    public function index()
    {
        $num_once = 5;
        $_log = D('log');
        $_website = D('website');

        //读上一次的位置
        $result = $_log->where('sid=0')->order('id DESC')->find();
        $last_id = $result['result'];
        $last_time = $result['time'];
        if($last_time){
            if(strtotime($last_time.' +1 minute') > strtotime('now')){            
                echo '午时未到ヽ(●-`Д´-)ノ';
                return;
            }
        }

        if(empty($last_id)){
            $last_id = 0;
        }

        $result = $_website->where('sid>'.$last_id)->field('sid')->limit($num_once)->select();

        if(count($result) < $num_once){
            $result_ = $_website->field('sid')->limit($num_once - count($result))->select();
            $checkin_query = array_merge($result,$result_);
        }else{
            $checkin_query = $result;
        }

        $_log->add(array(
            'time' => date('Y-m-d H:i:s'),
            'sid' => 0,
            'result' => $checkin_query[count($checkin_query) - 1]['sid']
        ));

        foreach ($checkin_query as $key => $value) {
            $this->checkin($value['sid']);
        }
    }

        public function userRequire()
        {
            $_log = D('log');
            $_website = D('website');
            $_user = D('user');

            //读上一次的时间
            $result = $_log->where('sid=0 and result="userRequire"')->order('id DESC')->find();
            $last_time = $result['time'];
            if($last_time){
                if(strtotime($last_time.' +2 minute') > strtotime('now')){            
                    echo '午时未到ヽ(●-`Д´-)ノ';
                    return;
                }
            }

            $_log->add(array(
                'time' => date('Y-m-d H:i:s'),
                'sid' => 0,
                'result' => 'userRequire'
            ));

            $result = $_user->where('`require`=1')->field('uid')->find();
            $uid = $result['uid'];
            if($uid){
                $result = $_website->where('uid='.$uid)->field('sid')->select();
                foreach ($result as $value) {
                    $this->checkin($value['sid']);
                }
                $_user->where('uid='.$uid)->save(array('require'=>0));
            }
        }

        private function checkin($sid = 0)
    {
        ini_set('max_execution_time', 300);
        $_website = D('website');
        $_log = D('log');
        
        if($sid == 0){
            echo '<table class="table">';
            foreach ($_website->field('sid')->select() as $value) {
                echo '<tr><td>';
                $this->checkin($value['sid']);
                echo '</td></tr>';
            }
            echo '</table>';
            return;
        }

        $value = $_website->where('sid='.$sid)->find();

        $ss_website = $value['website'];
        $ss_checkin_type = $value['checkin_type'];
        $ss_username = $value['username'];
        $ss_password = $value['password'];
        $ss_cookies = $value['cookies'];
        $ss_website_name = $value['website_name'];
        if(empty($value['site_type'])){
            $ss_website_type = $this->getType($sid,$ss_website);
            $_website->where('sid='.$sid)->save(array('site_type'=>$ss_website_type));
        }else{
            $ss_website_type = $value['site_type'];
        }

        if(empty($ss_website)){
            return;
        }

        echo $sid.':';
        
        //获取网站访问状态
        $headers = get_headers($ss_website);
        $responce_code = $this->getResponceCode($headers);
        if($responce_code != 200){
            echo '网站不能正常访问，停止执行签到任务.';
            $this->_log($sid,'网站不能正常访问，停止执行签到任务;[Responce_Code]:' . $responce_code . ';[HTTP_header]:' . implode(' ',$headers));
            $_website->where('sid='.$sid)->save(array('last_result' => 0));
        }else{
            //网站可访问检查完成，开始执行签到任务
            echo '网站可访问->';
            if(empty($ss_cookies)){
                //cookies为空
                echo 'cookies为空->';
                if($ss_checkin_type == 1){
                    echo 'checkin_type为帐号密码,用帐号密码登录->';
                    $ss_cookies = $this->_login($sid,$ss_website,$ss_username,$ss_password,$ss_website_type);
                    if($ss_cookies){
                        //登录成功
                        echo '登录成功->';
                        $checkin_result = $this->_checkin($sid,$ss_website,$ss_cookies,$ss_website_type,$ss_checkin_type,$ss_website_name,$ss_username);
                        if($checkin_result){
                            //签到成功
                            echo '签到成功,cookies存起来下次用.';
                            $_website->where('sid='.$sid)->save(array(
                                'last_result' => 1,
                                'cookies'     => $ss_cookies //cookies存起来下次用
                                ));
                        }else{
                            //签到失败
                            echo '签到失败,详情请看日志.';
                            $_website->where('sid='.$sid)->save(array('last_result' => 0));
                        }
                    }else{
                        //登录失败
                        echo '登录失败,详情请看日志.';
                        $_website->where('sid='.$sid)->save(array('last_result' => 0));
                    }
                }else{
                    //没有cookies,没有帐号密码
                    echo '没有cookies,没有帐号密码,无法登录.';
                    $this->_log($sid,'没有cookies,没有帐号密码,你让我怎么签到???');
                    $_website->where('sid='.$sid)->save(array('last_result' => 0));
                }
            }else{
                //cookies不为空
                echo 'cookies不为空->';
                if($ss_checkin_type == 1){
                    //先尝试用cookies签到
                    echo 'checkin_type为帐号密码,先尝试使用cookies签到->';
                    $checkin_result = $this->_checkin($sid,$ss_website,$ss_cookies,$ss_website_type,$ss_checkin_type,$ss_website_name,$ss_username);
                    if($checkin_result){
                        //签到成功
                        echo '签到成功.';
                        $_website->where('sid='.$sid)->save(array('last_result' => 1));
                    }else{
                        //签到失败,尝试用帐号密码签到
                        echo 'cookies方式签到失败,尝试用帐号密码登录->';
                        $ss_cookies = $this->_login($sid,$ss_website,$ss_username,$ss_password,$ss_website_type);
                        if($ss_cookies){
                            //登录成功
                            echo '登录成功->';
                            $checkin_result = $this->_checkin($sid,$ss_website,$ss_cookies,$ss_website_type,$ss_checkin_type,$ss_website_name,$ss_username);
                            if($checkin_result){
                                //签到成功
                                echo '签到成功,更新cookies下次用.';
                                $_website->where('sid='.$sid)->save(array(
                                    'last_result' => 1,
                                    'cookies'     => $ss_cookies //更新cookies下次用
                                    ));
                            }else{
                                //签到失败
                                echo '签到失败,详情请看日志.';
                                $_website->where('sid='.$sid)->save(array(
                                    'last_result' => 0,
                                    'cookies' => '' //错误的cookie删除
                                    ));
                            }
                        }else{
                            //登录失败
                            echo '登录失败,详情请看日志.';
                            $_website->where('sid='.$sid)->save(array(
                                'last_result' => 0,
                                'cookies' => ''
                                ));
                        }
                    }

                }elseif($ss_checkin_type == 2){
                    echo 'checkin_type为cookies,尝试用cookies签到->';
                    $checkin_result = $this->_checkin($sid,$ss_website,$ss_cookies,$ss_website_type,$ss_checkin_type,$ss_website_name,$ss_username);
                    if($checkin_result){
                        //签到成功
                        echo '签到成功.';
                        $_website->where('sid='.$sid)->save(array('last_result' => 1));
                    }else{
                        //签到失败
                        echo '签到失败,可能是cookies失效啦.';
                        $_website->where('sid='.$sid)->save(array('last_result' => 0));
                    }

                }else{
                    $this->_log($sid,'未知的签到方式,黑人问号脸???;[ss_checkin_type]:'.$ss_checkin_type);
                    $_website->where('sid='.$sid)->save(array('last_result' => 0));
                }
            }

        }
    }

    //0:不支持;1:STAFF(ss-panel-mod)或ss-panel-3;2:ss-panel2;
    private function getType($sid,$website){
        if($this->getResponceCode(get_headers($website.'/staff')) == 200){
            return 1;
        }elseif($this->getResponceCode(get_headers($website.'/tos')) == 200){
            return 1;
        }elseif($this->getResponceCode(get_headers($website.'/user/tos.php')) == 200){
            return 2;
        }else{
            return 0;
        }
    }

    private function getResponceCode($header){
        if(is_array($header)){
            $header = implode(' ',$header);
        }
            
        if(preg_match('/(?<=HTTP\/1\.\d )\d{3}/',$header,$preg_results)){
            return $preg_results[0];
        }else{
            return FALSE;
        }
    }

    private function _log($sid,$log)
    {
        $_log = D("log");
        $result = $_log->where('sid='.$sid)->order('id DESC')->find();
        if($result['result'] == $log){
            $_log->where('id='.$result['id'])->delete();
        }
        //删除旧的，加入新的
        $_log->add(array(
            'sid' => $sid,
            'result' => $log
        ));
    }

    private function _login($sid,$website,$username,$password,$website_type){
        $ch = curl_init();
        $_log = D('log');

        switch ($website_type){
            case 1:
            curl_setopt_array($ch,array(
                CURLOPT_URL => $website.'/auth/login',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1',
                CURLOPT_TIMEOUT  => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_HEADER => true,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => array(
                    'email'       => $username,
                    'passwd'      => $password,
                    'code'        => '',
                    'remember_me' => 'week'
                )
            ));
            $curl_result = curl_exec($ch);

            if(preg_match('/{.*}/',$curl_result,$preg_results)){
                //JSON匹配成功
                $web_response = json_decode($preg_results[0],true);

                if($web_response['ret']){
                    if(preg_match_all('/(?<=Set-Cookie: ).*=.*;(?= e)/',$curl_result,$preg_results)){
                        $cookies_str = implode(' ',$preg_results[0]);
                        return $cookies_str;
                    }
                }else{
                    //登录失败
                    if(is_array($web_response)){
                        $this->_log($sid,'登录失败;[web_response]:'.$web_response['msg']);
                        return 0;
                    }else{
                        $this->_log($sid,'登录失败;[preg_results[0]]:'.$preg_results[0]);
                        return 0;
                    }
                }
            }else{
                //JSON匹配失败
                $this->_log($sid,'JSON匹配失败;[curl_result]:'.strip_tags($curl_result));
                return 0;
            }
            break;

            case 2:
            curl_setopt_array($ch,array(
                CURLOPT_URL => $website.'/user/_login.php',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1',
                CURLOPT_TIMEOUT  => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_HEADER => true,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => array(
                    'email'       => $username,
                    'passwd'      => $password,
                    'remember_me' => 'week'
                )
            ));

            $curl_result = curl_exec($ch);

            if(preg_match('/{.*}/',$curl_result,$preg_results)){
                //JSON匹配成功
                $web_response = json_decode($preg_results[0],true);

                if($web_response['code']){
                    if(preg_match_all('/(?<=Set-Cookie: ).*=.*;(?= e)/',$curl_result,$preg_results)){
                        $cookies_str = implode(' ',$preg_results[0]);
                        return $cookies_str;
                    }
                }else{
                    //登录失败
                    if(is_array($web_response)){
                        $this->_log($sid,'登录失败;[web_response]:'.$web_response['msg']);
                        return 0;
                    }else{
                        $this->_log($sid,'登录失败;[preg_results[0]]:'.$preg_results[0]);
                        return 0;
                    }
                }
            }else{
                //JSON匹配失败
                $this->_log($sid,'JSON匹配失败;[curl_result]:'.strip_tags($curl_result));
                return 0;
            }
            break;

            default:
            $this->_log($sid,'这个网站好像不支持自动签到呐');
            return 0;
        }

        curl_close($ch);
    }
    
    private function _checkin($sid,$website,$cookies,$website_type,$checkin_type,$website_name,$username){
        $ch = curl_init();
        $_website = D('website');
        $_log = D('log');

        switch ($website_type){
            case 1:
            curl_setopt_array($ch,array(
                CURLOPT_URL => $website.'/user/checkin',
                CURLOPT_REFERER  => $website.'/user',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1',
                CURLOPT_TIMEOUT  => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_COOKIE => $cookies
            ));
            $curl_result = curl_exec($ch);

            if(preg_match('/{.*}/',$curl_result,$preg_results)){
                //JSON匹配成功
                $web_response = json_decode($preg_results[0],true);

                if($web_response['ret']){
                    //签到成功
                    $this->_log($sid,'签到成功;[web_response]:'.$web_response['msg']);

                    //更新网站信息
                    curl_setopt($ch,CURLOPT_URL,$website.'/user');
                    curl_setopt($ch,CURLOPT_POST,false);
                    $curl_result = curl_exec($ch);

                    if(preg_match_all('/\d*\.\d*GB/',$curl_result,$preg_results)){
                        $data_remain = array_pop($preg_results[0]);
                        $_website->where('sid='.$sid)->save(array('data_remain'=>$data_remain));
                    }
                    if(preg_match('/(?<=<code>)\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',$curl_result,$preg_results)){
                        $last_time = $preg_results[0];
                        $_website->where('sid='.$sid)->save(array('last_time'=>$last_time));
                    }
                    if(empty($website_name)){
                        if(preg_match('/(?<=<title>).*(?=<\/title>)/',$curl_result,$preg_results)){
                            $website_name = $preg_results[0];
                            $_website->where('sid='.$sid)->save(array('website_name'=>$website_name));
                        }
                    }
                    if(empty($username)){
                        curl_setopt($ch,CURLOPT_URL,$website.'/user/profile');
                        $curl_result = curl_exec($ch);
                        if(preg_match('/(?<=<dd>).*@.*(?=<\/dd>)/',$curl_result,$preg_results)){
                            $username = $preg_results[0];
                            $_website->where('sid='.$sid)->save(array('username'=>$username));
                        }
                    }
                    return 1;

                }else{
                    //签到失败
                    if(is_array($web_response)){
                        $this->_log($sid,'签到失败;[web_response]:'.$web_response['msg']);
                        return 0;
                    }else{
                        $this->_log($sid,'签到失败;[preg_results[0]]:'.$preg_results[0]);
                        return 0;
                    }
                }
            }else{
                //JSON匹配失败
                $this->_log($sid,'JSON匹配失败;[curl_result]:'.strip_tags($curl_result));
                return 0;
            }
            break;

            case 2:
            curl_setopt_array($ch,array(
                CURLOPT_URL => $website.'/user/_checkin.php',
                CURLOPT_REFERER  => $website.'/user',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1',
                CURLOPT_TIMEOUT  => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_COOKIE => $cookies
            ));
            $curl_result = curl_exec($ch);

            if(preg_match('/{.*}/',$curl_result,$preg_results)){
                //JSON匹配成功
                $web_response = json_decode($preg_results[0],true);
                //签到成功
                $this->_log($sid,'签到成功;[web_response]:'.$web_response['msg']);

                //更新网站信息
                    curl_setopt($ch,CURLOPT_URL,$website.'/user');
                    curl_setopt($ch,CURLOPT_POST,false);
                    $curl_result = curl_exec($ch);

                    if(preg_match_all('/\d*\.\d*GB/',$curl_result,$preg_results)){
                        $data_remain = array_pop($preg_results[0]);
                        $_website->where('sid='.$sid)->save(array('data_remain'=>$data_remain));
                    }
                    if(preg_match('/(?<=<code>)\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',$curl_result,$preg_results)){
                        $last_time = $preg_results[0];
                        $_website->where('sid='.$sid)->save(array('last_time'=>$last_time));
                    }
                    if(empty($website_name)){
                        if(preg_match('/(?<=<title>).*(?=<\/title>)/',$curl_result,$preg_results)){
                            $website_name = $preg_results[0];
                            $_website->where('sid='.$sid)->save(array('website_name'=>$website_name));
                        }
                    }
                    if(empty($username)){
                        curl_setopt($ch,CURLOPT_URL,$website.'/user/my.php');
                        $curl_result = curl_exec($ch);
                        if(preg_match('/(?<=：).*@.*\..*(?=<\/p>)/',$curl_result,$preg_results)){
                            $username = $preg_results[0];
                            $_website->where('sid='.$sid)->save(array('username'=>$username));
                        }
                    }

                return 1;

            }else{
                //JSON匹配失败
                $this->_log($sid,'JSON匹配失败;[curl_result]:'.strip_tags($curl_result));
                return 0;
            }
            break;

            default:
            return 0;
        }

        curl_close($ch);
    }
}