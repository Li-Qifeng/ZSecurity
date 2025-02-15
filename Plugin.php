<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
if (!defined('__TYPECHO_CLASS_ALIASES__')){

require_once(__DIR__."/libs/ot.php");
}
else{
require_once(__DIR__."/libs/nt.php");
}
/**
 * 自用的文章版权添加以及防调试能力，并且添加了多种特效。
 *
 * @package ZSecurity
 * @author Zunmx
 * @version 1.1.7
 * @link https://www.zunmx.top
 *
 * @Source https://github.com/zunmx/ZSecurity
 */
class ZSecurity_Plugin implements Typecho_Plugin_Interface
{
    // 本插件的静态路径
    const STATIC_DIR = '/usr/plugins/ZSecurity/static';
    // 本插件的静态路径
    const PLUGIN_DIR = '/usr/plugins/ZSecurity/';
    // 本插件的方法路径
    const FUNC_DIR = '/usr/plugins/ZSecurity/func';

    // 抵抗开发者工具的默认脚本
    const defaultAntiDev = <<<EOF
<script>
setInterval(function () { 
$("html").append("<scr"+"ipt id='wng'>function devToolNotice() { try{antiDebug_Clear();}catch{}   $.message({        title: '检测到异常行为或指令',        message: '建站不易，趴站可耻。感谢配合。鞠躬',        type: 'error',        time: '3000'    });}</scr"+"ipt>")
$("script[id = wng]").remove()
var t1 = new Date().getTime(); debugger; var t2 = new Date().getTime(); if ((t2 - t1 > 1)||(window.outerWidth - window.innerWidth > 160 )||  (window.outerHeight - window.innerHeight > 160)) { devToolNotice() }console.clear(); }, 520); // 叼毛，不要调试我。  
 

let element = new Image();
Object.defineProperty(element, 'id', function () {
    devToolNotice();
}) // 开发者工具遍历元素提示
document.onkeydown = function () {
    if ((event.ctrlKey || event.metaKey) && event.keyCode === 73) {
        event.preventDefault();
        devToolNotice();
        return false;
    }
    if (window.event && window.event.keyCode == 123) {
        event.keyCode = 0;
        event.returnValue = false;
        devToolNotice();
        return false;
    }
};// 键盘事件，你要是改我也没办法。
window.oncontextmenu = function (e) {
try{
    emojiMouse(e)
}catch{
}
return false;
} // 右键特效，并且屏蔽右键
    if (window.console && window.console.log) {
    devToolNotice();
    }
    
</script>
EOF;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('admin/menu.php')->navBar = array('ZSecurity_Plugin', 'render');
        Typecho_Plugin::factory('Widget_Archive')->header = array(__CLASS__, 'header');
        Typecho_Plugin::factory('Widget_Archive')->footer = array(__CLASS__, 'footer');
        return '插件安装成功~';
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        self::writeConf(""); // 注销WAF配置
        return '插件卸载成功~';
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        if (isset($_GET['action']) && $_GET['action'] == 'activeWAF') {
            self::activeWAF();
        }
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Hidden("ZSecurityToken", null, md5("ZSecurity-%z^u&n#m@x-!" . strval($_SERVER["PATH"]))));

        /** 分类名称 */
        $form->addInput(new My_Title('btnTitle', NULL, NULL, _t('插件设置'), NULL));
        $name = new Typecho_Widget_Helper_Form_Element_Radio('tip_switch', array(0 => _t('不显示'), 1 => _t('显示')), 1, _t('管理页面是否显示顶部提示 <span style="color:blue;font-weight:bold;">点击跳转到设置页面</span>'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('word', NULL, '[√] ZSecurity', _t('顶部提示'));
        $form->addInput($name);


        $form->addInput(new My_Title('btnTitle', NULL, NULL, _t('轻量级防火墙 (WAF-WebApplicationFirewall)'), NULL));

        $name = new Typecho_Widget_Helper_Form_Element_Radio('waf_switch', array(0 => _t('禁用'), 1 => _t('启动')), 1, _t('🔺 轻量级站点防火墙[总开关] <span style="color:red;font-weight:bold;">使用前建议进行整站备份</span>'));
        $form->addInput($name);

        $name = new Typecho_Widget_Helper_Form_Element_Text('host_ip', NULL, gethostbyname($_SERVER["HTTP_HOST"]), _t('禁止通过IP访问'), _t('<span style="color:red;font-weight:bold;">通常设置为公网IP，如果您的公网IP为10.10.121.43，服务端口号为88，那么这里需要设置为10.10.121.43:88，注意英文半角的端口号，如果默认80端口，可以不写。不需要加协议名。留空为不设置</span>'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('domainLock', NULL, $_SERVER["HTTP_HOST"], _t('域名绑定'), _t("检查域名是否为设置的域名，相当于白名单，只能通过域名访问。<br/>" . '<span style="color:red;font-weight:bold;">如果设置的域名不正确，可能导致无法进入网站，留空为不设置，否则需要填写自己的域名！不需要加协议，如果有端口加上端口号，规则同上，多个域名通过半角逗号分割。</span>'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('redirect', NULL, "", _t('违规跳转页面'), _t('<span style="color:red;font-weight:bold;">当违反WAF规则时，跳转的页面，需要详细地址(带协议名例如http://127.0.0.1)，不填为插件默认的响应</span>'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Radio('anti_iframe', array(0 => _t('禁用'), 1 => _t('启动')), 1, _t('禁止iframe嵌套'), _t('阻止别人通过iframe标签显示在其他网站上'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Radio('anti_EvilSpiderUA', array(0 => _t('禁用'), 1 => _t('启动')), 0, _t('禁止UA为空和常见恶意爬虫的浏览器访问'), _t('禁止UA为空和常见恶意爬虫的浏览器访问，比如Python的Request、Go-http-client，经过长期观察UA字符长度小于10也不正常'));
        $form->addInput($name);


        $queryBtn = new Typecho_Widget_Helper_Layout("hr", array());
        $form->addItem($queryBtn);

        $name = new Typecho_Widget_Helper_Form_Element_Radio('anti_cc', array(0 => _t('禁用'), 1 => _t('启动')), 1, _t('🔺 抵御CC攻击总开关 <span style="color:red;font-weight:bold;">以下内容切勿多出额外字符，并且需要启动上面的WAF</span>'), _t('采用Redis数据库，封禁高频访问，也可以用来反爬'));
        $form->addInput($name);
        $queryBtn = new Typecho_Widget_Helper_Layout("a", array('id' => 'checkRedisButton', 'class' => 'btn primary', 'style' => 'padding-top:1em;float:right;', "onclick" => "RedisTest()"));
        $queryBtn->html("测试连接");
        $queryBtn->appendTo($name);
        $_SESSION["ZSecurity-RedisTest"] = 1; // 可以测试Redis


        $name = new Typecho_Widget_Helper_Form_Element_Text('anti_cc_redisIp', NULL, "127.0.0.1", _t('redis数据库地址'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('anti_cc_redisPort', NULL, "6379", _t('redis数据库端口号'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('anti_cc_redispasswd', NULL, "", _t('redis数据库密码'));
        $form->addInput($name);

        $name = new Typecho_Widget_Helper_Form_Element_Text('anti_cc_block_same_sec', NULL, "50", _t('同IP访问几 次/分钟 相同页面触发'), _t("当为-1时为禁用此项"));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('anti_cc_block_diff_sec', NULL, "100", _t('同IP访问几 次/分钟 不相同页面触发'), _t("当为-1时为禁用此项"));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('anti_cc_block_time', NULL, "60", _t('封禁IP时间，单位秒'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('anti_cc_block_clean', NULL, "3600", _t('Redis缓存清空时间 单位秒'), _t("由于是基于内存的缓存，内存资源占用过大可能导致应用不稳定，定期清空缓存有利于减缓内存压力。"));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('anti_cc_ip_allow', NULL, "123.56.220.252,127.0.0.1", _t('IP白名单，逗号分割多IP。通常用来搜索引擎收录IP以及自己的IP'));
        $form->addInput($name);


        $form->addInput(new My_Title('btnTitle', NULL, NULL, _t('反盗版'), NULL));
        $name = new Typecho_Widget_Helper_Form_Element_Radio('antiDebug_switch', array(0 => _t('禁用'), 1 => _t('启动')), 1);
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Radio('antiDebug_Clear', array(0 => _t('禁用'), 1 => _t('启动')), 0, _t('当发现开发者工具进行页面清空 <span style="color:red;font-weight:bold;">不建议开启，可能存在误判</span>[阻止开发者工具启动时有效]'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Textarea('antiDevtool', NULL, self::defaultAntiDev, _t('脚本内容'), _t('简单阻止开发者工具 <span style="color:red;font-weight:bold;"> 这里的js不进行转义</span>'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Radio('copyPlus', array(0 => _t('禁用'), 1 => _t('启动')), 1, _t('页面复制加版权信息'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('copyText', NULL, "尊暮萧", _t('版权作者'));
        $form->addInput($name);

        $form->addInput(new My_Title('btnTitle', NULL, NULL, _t('外观设置'), NULL));
        $name = new Typecho_Widget_Helper_Form_Element_Radio('JSCDN', array(0 => _t('本地'), 1 => _t('BootCDN'), 2 => '75CDN', 3 => '七牛云'), 1, _t('JS源'));
        $form->addInput($name);

        $name = new Typecho_Widget_Helper_Form_Element_Radio('clickStyle', array(0 => _t('禁用'), 1 => _t('emoji'), 2 => '爆炸气泡'), 1, _t('鼠标点击特效 <span style="color:red;font-weight:bold;">爆炸特效不建议开启</span>'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Radio('grayStyle', array(0 => _t('禁用'), 1 => _t('启动')), 1, _t('公祭日页面灰度'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Radio('commentStyle', array(0 => _t('禁用'), 1 => _t('启动')), 1, _t('评论框打字特效'));
        $form->addInput($name);
        // 鼠标样式
        $dir = self::STATIC_DIR . '/image';
        $options = [
            'none' => _t('默认'),
            'dew' => "<img src='{$dir}/dew/normal.cur'><img src='{$dir}/dew/link.cur'>",
            'sketch' => "<img src='{$dir}/sketch/normal.cur'><img src='{$dir}/sketch/link.cur'>",
            'black' => "<img src='{$dir}/black/normal.cur'><img src='{$dir}/black/link.cur'>",
            'star' => "<img src='{$dir}/star/normal.cur'><img src='{$dir}/star/link.cur'>",
            'win11cursor' => "<img src='{$dir}/win11cursor/normal.cur'><img src='{$dir}/win11cursor/link.cur'>",
        ];
        $bubbleType = new Typecho_Widget_Helper_Form_Element_Radio('mouseType', $options, 'dew', _t('鼠标样式'));
        $form->addInput($bubbleType);

        // 辅助功能
        $form->addInput(new My_Title('btnTitle', NULL, NULL, _t('<span style="color: #ff8d5a">辅助功能</span>'), NULL));
        $name = new Typecho_Widget_Helper_Form_Element_Radio('autoHttps', array(0 => _t('禁用'), 1 => _t('启动')), 0, _t('自动跳转到HTTPS页面'), _t("要配置好https的有关配置哦"));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Radio('admin_disabledWAF', array(0 => _t('禁用'), 1 => _t('启动')), 0, _t('针对管理员停用防火墙'), _t("只有管理员权限时绕过WAF和反盗版"));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Radio('title_focus_switch', array(0 => _t('禁用'), 1 => _t('启动')), 0, _t('启动页面获取/失去焦点标题改变'), _t(""));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('outOfFocus', NULL, '呜呜呜，我怕黑T_T', _t('获得焦点'));
        $form->addInput($name);
        $name = new Typecho_Widget_Helper_Form_Element_Text('inFocus', NULL, '我就知道你会回来的>_<', _t('失去焦点'));
        $form->addInput($name);
        self::printMyJS();
    }

    public static function isAdmin()
    {
        $isAdmin = Typecho_Widget::widget('Widget_User')->pass('administrator', true);

//        echo "<script>alert(" . ZSecurity_Plugin::isAdmin() . ")</script>";
        //todo 外部调用
        return $isAdmin;
    }

    public static function activeWAF()
    {
        $myself = Helper::options()->plugin('ZSecurity'); // 获取配置
        if ($myself->waf_switch == 1) {  // 防火墙状态：启动
            // func路径
            $funcPath = dirname(__FILE__) . "/func/";
            $funcPath = str_replace("\\", "/", $funcPath);
            // 获取配置信息中的内容
            $host_ip = $myself->host_ip;
            $domainLock = $myself->domainLock;
            $anti_iframe = $myself->anti_iframe;
            $redirect = $myself->redirect;
            $cc_switch = $myself->anti_cc;
            $redis_ip = $myself->anti_cc_redisIp;
            $redis_port = $myself->anti_cc_redisPort;
            $cc_same_sec = $myself->anti_cc_block_same_sec;
            $cc_diff_sec = $myself->anti_cc_block_diff_sec;
            $cc_block_time = $myself->anti_cc_block_time;
            $cc_ip_allow = $myself->anti_cc_ip_allow;
            $cc_ip_clean = $myself->anti_cc_block_clean;
            $cc_redispasswd = $myself->anti_cc_redispasswd;
            $anti_EvilSpiderUA = $myself->anti_EvilSpiderUA;

            // 写入文件
            $zkInfo = "<?php $" . <<<EOF
zkInfo = array(
    'ip' => "$host_ip",
    'domain' => "$domainLock",
    'redirect' => "$redirect",
    'cc' => "$cc_switch",
    'redis_ip' => "$redis_ip",
    'redis_port' => "$redis_port",
    'cc_same_sec' => "$cc_same_sec",
    'cc_diff_sec' => "$cc_diff_sec",
    'cc_block_time' => "$cc_block_time",
    'cc_ip_allow' => "$cc_ip_allow",
    'cc_ip_clean' => "$cc_ip_clean",
    'cc_redispasswd' => "$cc_redispasswd",
    'anti_EvilSpiderUA' => "$anti_EvilSpiderUA"
);
?>
EOF;

            if (file_exists($funcPath . "ZSConfig.php")) {  // 判断配置文件是否存在
                file_put_contents($funcPath . "ZSConfig.php", $zkInfo);  // 修改配置文件

                // 写主入口程序
                if ($anti_iframe == "1") {
                    $tmp = <<<EOF

header("X-Frame-Options: deny"); //ZSecurity 请勿修改
header("X-XSS-Protection: 0"); //ZSecurity 请勿修改

EOF;
                }
                $tmp .= 'include_once(' . '"' . $funcPath . 'check.php"' . ');  //ZSecurity 请勿修改';
                self::writeConf($tmp);


                //echo "<script>alert('ZSConfig--mod')</script>";
            } else { // ZSConfig.php 文件不存在
                throw new Typecho_Plugin_Exception(_t('WAF配置文件丢失' . $funcPath . "ZSConfig.php"));
            }

        } else {
            self::writeConf("");
        }

    }


    public static function writeConf($content)
    {
        try {
            $filePath = $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/index.php";
            $file = fopen($filePath, "r"); // 以只读的方式打开文件
            if (empty($file)) {
                throw new Typecho_Plugin_Exception(_t('index.php 不存在'));
            }
            //遍历文本中所有的行，直到文件结束为止。
            while (!feof($file)) {
                $itemStr = fgets($file); //fgets()函数从文件指针中读取一行
                $flag = strpos($itemStr, "//ZSecurity");
                if (!$flag) {
                    $result .= $itemStr;
                }
            }
            fclose($file);
            $result = trim($result);
            if ($content == "") {
                file_put_contents($filePath, $result);  // 修改配置文件
            } else {
                $result = "<?php //ZSecurity 请勿修改 " . PHP_EOL . $content . PHP_EOL . "/*//ZSecurity 请勿修改*/?>" . PHP_EOL . $result;
                file_put_contents($filePath, $result);  // 修改配置文件
            }


        } catch (Exception $exception) {
            throw new Typecho_Plugin_Exception(_t('操作失败！' . $exception));
        }
        return true;
    }


    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {

    }

    /**
     * 插件实现方法
     *
     * @access public
     * @return void
     */
    public static function render()
    {

        $myself = Helper::options()->plugin('ZSecurity');
        if ($myself->tip_switch == "1")  // 标识
            echo '<a href="';
        Helper::options()->adminUrl();
        echo 'options-plugin.php?config=ZSecurity'
            . '">'
            . htmlspecialchars(Typecho_Widget::widget('Widget_Options')->plugin('ZSecurity')->word)
            . '</a>';
    }

    public static function header()
    {
        $myself = Helper::options()->plugin('ZSecurity');

        if ($myself->autoHttps == "1") {//https
            if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != 'on') {
                header('Location: https://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']);
                exit();
            }
        }

        if ($myself->clickStyle != "0"){// 鼠标特效样式
            echo '<canvas id="fireworks" style="position:fixed;left:0;top:0;pointer-events:none;z-index: 999999"></canvas>';
            switch ($myself->JSCDN) {
                case "0":
                    if($myself->clickStyle == "2")echo '<script type="text/javascript" src= "'. self::STATIC_DIR . '/js/anime2.2.0.min.js"></script>';
                    echo '<script type="text/javascript" src= "'. self::STATIC_DIR . '/js/jquery.js"></script>';
                    break;
                case "1":
                    if($myself->clickStyle == "2")echo '<script src="https://cdn.bootcdn.net/ajax/libs/animejs/2.2.0/anime.js"></script>';
                    echo '<script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>';
                    break;
                case "2":
                    if($myself->clickStyle == "2")echo '<script type="text/javascript" src= "https://lib.baomitu.com/animejs/2.2.0/anime.min.js"></script>';
                    echo '<script type="text/javascript" src="https://lib.baomitu.com/jquery/3.6.0/jquery.js"></script>';
                    break;
                case "3":
                    if($myself->clickStyle == "2")echo '<script type="text/javascript" src= "https://cdn.staticfile.org/animejs/2.2.0/anime.min.js"></script>';
                    echo '<script type="text/javascript" src= "https://cdn.staticfile.org/jquery/3.6.0/jquery.min.js"></script>';
                    break;
                default :
                    if($myself->clickStyle == "2")echo '<script type="text/javascript" src= ".self::STATIC_DIR."/js/anime2.2.0.min.js"></script>';
                    echo '<script type="text/javascript" src= ".self::STATIC_DIR."/js/jquery.js"></script>';
            }
            echo "<script type='text/javascript' src='" . self::STATIC_DIR . "/js/fireworks.js'></script>";
        }
        if ($myself->clickStyle == "1") { // 鼠标特效样式
            echo <<<EOF
<script>
var a = new Array("🙂", "🙋‍", "😀", "😃", "😄", "😁", "😆", "😅", "🤣", "😂", "🙂", "🙃", "😉", "😊", "😇", "🥰", "😍", "🤩", "😘", "😗", "😚", "😙", "😋", "😛", "😜", "🤪", "😝", "🤑", "🤗", "🤭", "🤔", "🤐", "🤨", "😐", "😑", "😶", "😏", "😒", "🙄", "😬", "🤥", "😌", "😔", "😪", "🤤", "😴", "😷", "🤒", "🤕", "🤢", "🤮", "🤧", "🥵", "🥶", "🥴", "😵", "🤯", "🤠", "🥳", "😎", "🤓", "🧐", "😕", "😟", "🙁", "☹️", "😮", "😯", "😲", "😳", "🥺", "😦", "😧", "😨", "😰", "😥", "😢", "😭", "😱", "😖", "😣", "😞", "😓", "😩", "😫", "🥱", "😤", "😡", "😠", "🤬", "💋", "💍", "🌈", "👽", "💘", "💓", "💔", "💕", "💖", "💗", "💙", "💚", "💛", "💜", "💝", "💞", "💟");
    function emojiMouse(e) {
        var a_idx = parseInt((Math.random() * 100)) % a.length;
        var sp = $("<span/>").text(a[a_idx]);
        var x = e.pageX, y = e.pageY;
        sp.css({ "z-index": 9999999999999, "top": y - 20, "left": x, "position": "absolute", "font-weight": "bold", "color": "#ff6651" });
        $("body").append(sp);
        sp.animate({ "top": y - 100, "opacity": 0 }, 1000, function () {
            sp.remove();
        });
    } // 鼠标特效主入口

        document.onclick=(function (e) {
            emojiMouse(e)
        });

 //启动事件，鼠标特效
</script>
EOF;
        }
        // #############################################################WAF、Anti在后面#####################################################
        if ($myself->admin_disabledWAF == "1" && self::isAdmin()) { // 管理员判断，管理员不启动WAF和AntiDebug
            try {
                $conn = new Redis();  // TODO: 会拖慢效率，那位大佬会写PHP的单例，我试了试每次都会创建，有点懵。
                $conn->pconnect($myself->anti_cc_redisIp, $myself->anti_cc_redisPort);
                $conn->auth($myself->anti_cc_redispasswd);
                $conn->set("admin_ip", $_SERVER["REMOTE_ADDR"], 300); // 300s
            } catch (Exception $e) {

            }
            return;
        }
        if ($myself->antiDebug_switch == "1") {  // 禁止调试
            echo $myself->antiDevtool;
            if ($myself->antiDebug_Clear == "1") {
                echo '<script>' . <<<EOF
        function antiDebug_Clear(){
            $("html").html("");
        }
EOF;
                echo "</script>";
            }
        }

    }

    public static function footer()
    {
        $myself = Helper::options()->plugin('ZSecurity');
        if ($myself->grayStyle == "1") { // 公祭日
            echo <<<EOF
<script>
    $(function(){
        var dt = new Date();
        var dt2 = dt.getMonth() + 1 + "" + dt.getDate()
        if (dt2 == "918" || dt2 == "1213" ) {
            $("html").css({
                "filter": "gray !important",
                "filter": "progid:DXImageTransform.Microsoft.BasicImage(grayscale=1)",
                "filter": "grayscale(100%)",
                "-webkit-filter": "grayscale(100%)",
                "-moz-filter": "grayscale(100%)",
                "-ms-filter": "grayscale(100%)",
                "-o-filter": "grayscale(100%)"
            });
        }
    });
</script>
EOF;
        }
        if ($myself->title_focus_switch == "1") {
            echo <<<EOF
<script>
 $(document).ready(function(){
 var OriginTitile = document.title; 
 var titleTime;
 document.addEventListener('visibilitychange', function(){
                if (document.hidden){
                    document.title = '$myself->outOfFocus';
                    clearTimeout(titleTime);
                }else{
                    document.title = '$myself->inFocus';
                    titleTime = setTimeout(function() {
                        document.title = OriginTitile;
                    }, 2000); 
                }
            });
        });
</script>
EOF;


        }
        if ($myself->commentStyle == "1") {
            echo <<<EOF
<script>
(function webpackUniversalModuleDefinition(a,b){if(typeof exports==="object"&&typeof module==="object"){module.exports=b()}else{if(typeof define==="function"&&define.amd){define([],b)}else{if(typeof exports==="object"){exports["POWERMODE"]=b()}else{a["POWERMODE"]=b()}}}})(this,function(){return(function(a){var b={};function c(e){if(b[e]){return b[e].exports}var d=b[e]={exports:{},id:e,loaded:false};a[e].call(d.exports,d,d.exports,c);d.loaded=true;return d.exports}c.m=a;c.c=b;c.p="";return c(0)})([function(c,g,b){var d=document.createElement("canvas");d.width=window.innerWidth;d.height=window.innerHeight;d.style.cssText="position:fixed;top:0;left:0;pointer-events:none;z-index:999999";window.addEventListener("resize",function(){d.width=window.innerWidth;d.height=window.innerHeight});document.body.appendChild(d);var a=d.getContext("2d");var n=[];var j=0;var k=120;var f=k;var p=false;o.shake=true;function l(r,q){return Math.random()*(q-r)+r}function m(r){if(o.colorful){var q=l(0,360);return"hsla("+l(q-10,q+10)+", 100%, "+l(50,80)+"%, "+1+")"}else{return window.getComputedStyle(r).color}}function e(){var t=document.activeElement;var v;if(t.tagName==="TEXTAREA"||(t.tagName==="INPUT"&&t.getAttribute("type")==="text")){var u=b(1)(t,t.selectionStart);v=t.getBoundingClientRect();return{x:u.left+v.left,y:u.top+v.top,color:m(t)}}var s=window.getSelection();if(s.rangeCount){var q=s.getRangeAt(0);var r=q.startContainer;if(r.nodeType===document.TEXT_NODE){r=r.parentNode}v=q.getBoundingClientRect();return{x:v.left,y:v.top,color:m(r)}}return{x:0,y:0,color:"transparent"}}function h(q,s,r){return{x:q,y:s,alpha:1,color:r,velocity:{x:-1+Math.random()*2,y:-3.5+Math.random()*2}}}function o(){var t=e();var s=5+Math.round(Math.random()*10);while(s--){n[j]=h(t.x,t.y,t.color);j=(j+1)%500}f=k;if(!p){requestAnimationFrame(i)}if(o.shake){var r=1+2*Math.random();var q=r*(Math.random()>0.5?-1:1);var u=r*(Math.random()>0.5?-1:1);document.body.style.marginLeft=q+"px";document.body.style.marginTop=u+"px";setTimeout(function(){document.body.style.marginLeft="";document.body.style.marginTop=""},75)}}o.colorful=false;function i(){if(f>0){requestAnimationFrame(i);f--;p=true}else{p=false}a.clearRect(0,0,d.width,d.height);for(var q=0;q<n.length;++q){var r=n[q];if(r.alpha<=0.1){continue}r.velocity.y+=0.075;r.x+=r.velocity.x;r.y+=r.velocity.y;r.alpha*=0.96;a.globalAlpha=r.alpha;a.fillStyle=r.color;a.fillRect(Math.round(r.x-1.5),Math.round(r.y-1.5),3,3)}}requestAnimationFrame(i);c.exports=o},function(b,a){(function(){var d=["direction","boxSizing","width","height","overflowX","overflowY","borderTopWidth","borderRightWidth","borderBottomWidth","borderLeftWidth","borderStyle","paddingTop","paddingRight","paddingBottom","paddingLeft","fontStyle","fontVariant","fontWeight","fontStretch","fontSize","fontSizeAdjust","lineHeight","fontFamily","textAlign","textTransform","textIndent","textDecoration","letterSpacing","wordSpacing","tabSize","MozTabSize"];var e=window.mozInnerScreenX!=null;function c(k,l,o){var h=o&&o.debug||false;if(h){var i=document.querySelector("#input-textarea-caret-position-mirror-div");if(i){i.parentNode.removeChild(i)}}var f=document.createElement("div");f.id="input-textarea-caret-position-mirror-div";document.body.appendChild(f);var g=f.style;var j=window.getComputedStyle?getComputedStyle(k):k.currentStyle;g.whiteSpace="pre-wrap";if(k.nodeName!=="INPUT"){g.wordWrap="break-word"}g.position="absolute";if(!h){g.visibility="hidden"}d.forEach(function(p){g[p]=j[p]});if(e){if(k.scrollHeight>parseInt(j.height)){g.overflowY="scroll"}}else{g.overflow="hidden"}f.textContent=k.value.substring(0,l);if(k.nodeName==="INPUT"){f.textContent=f.textContent.replace(/\s/g,"\u00a0")}var n=document.createElement("span");n.textContent=k.value.substring(l)||".";f.appendChild(n);var m={top:n.offsetTop+parseInt(j["borderTopWidth"]),left:n.offsetLeft+parseInt(j["borderLeftWidth"])};if(h){n.style.backgroundColor="#aaa"}else{document.body.removeChild(f)}return m}if(typeof b!="undefined"&&typeof b.exports!="undefined"){b.exports=c}else{window.getCaretCoordinates=c}}())}])});
POWERMODE.colorful=true;POWERMODE.shake=false;document.body.addEventListener("input",POWERMODE);
</script>
EOF;
        }
        $mouseType = $myself->mouseType;
        $imageDir = self::STATIC_DIR . '/image';
        if ($mouseType != 'none') {
            echo <<<EOF
<script>
$("body").css("cursor", "url('{$imageDir}/{$mouseType}/normal.cur'), default");
$("a").css("cursor", "url('{$imageDir}/{$mouseType}/link.cur'), pointer");
</script>
EOF;
        }


        // #############################################################WAF、Anti在后面#####################################################
        if ($myself->admin_disabledWAF == "1") { // 管理员判断，管理员不启动WAF和AntiDebug
            if ($isAdmin = Typecho_Widget::widget('Widget_User')->pass('administrator', true)) {
                return;
            }
        }
        if ($myself->copyPlus == "1") { // 复制版权

            echo "<script>" . <<<EOF
$(function() {
  document.body.addEventListener('copy', function (e) {
    if (window.getSelection().toString() && window.getSelection().toString().length > 10) {
        setClipboardText(e);
    }
}); 
})
function setClipboardText(event) {
    var clipboardData = event.clipboardData || window.clipboardData;
    if (clipboardData) {
        event.preventDefault();
        var htmlData = '著作权归作者所有。<br/>商业转载请联系作者获得授权，非商业转载请注明出处。<br/>作者：$myself->copyText<br/>链接：' + window.location.href + '<br/>来源：'+window.location.host+'/<br/><br/>'+ window.getSelection().toString();
        clipboardData.setData('text/plain',htmlData.replaceAll("<br/>","\\r\\n"));
    }
}
EOF;
            echo "</script>";
        }


    }

// TODO: form.button当前为一，后期如果增加需要修改
    public static function printMyJS()
    {

        echo <<<EOF
<script>
window.onload=function(){
   
$("form").prop("onSubmit","return false"); // 拦截默认提交
$("button").click(function(){
$.ajax({
  url:$("form").attr("action"),
  type:"post",
  async:false,
  data:$("form").serialize(),
  success:function(){
    $.ajax({
        url: '
EOF;
        echo Helper::options()->adminUrl . "options-plugin.php?config=ZSecurity&action=activeWAF',";
        echo <<<EOF
        type: "GET",
        success:function(){
            $("form").prop("onSubmit","return true;"); // 取消拦截默认提交
            $("form").submit();
        }
    });// 提交waf修改
}})})
}
</script>
EOF;
        echo <<<EOF
<script>
function RedisTest (){
$.ajax({
        url: '
EOF;
        echo self::FUNC_DIR . "/Anti_CC.php?action=testRedis',";
        echo <<<EOF
        type: "POST",
        data: $("form").serialize(),
        success:function(e){
            $("#checkRedisButton").text(e);
        }
    }
   );// 提交waf修改
   }
   </script>
EOF;


    }
}
