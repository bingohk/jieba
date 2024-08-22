<?php
ini_set('memory_limit', '1024M');

require_once dirname(dirname(__FILE__))."/src/vendor/multi-array/MultiArray.php";
require_once dirname(dirname(__FILE__))."/src/vendor/multi-array/Factory/MultiArrayFactory.php";
require_once dirname(dirname(__FILE__))."/src/class/Jieba.php";
require_once dirname(dirname(__FILE__))."/src/class/Finalseg.php";
require_once dirname(dirname(__FILE__))."/src/class/JiebaAnalyse.php";
require_once dirname(dirname(__FILE__))."/src/class/Posseg.php";
use Bingohk\Jieba\Jieba;
use Bingohk\Jieba\Finalseg;
use Bingohk\Jieba\JiebaAnalyse;
use Bingohk\Jieba\Posseg;
Jieba::init();
Finalseg::init();
JiebaAnalyse::init();
Posseg::init();

function loader($class) {
    $file = $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
}
spl_autoload_register('loader');
?>