<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/10/26
 * Time: 5:08 PM
 */
namespace App\HttpController;
use App\Utility\Pool\Mysql2Object;
use App\Utility\Pool\Mysql2Pool;
use EasySwoole\Component\Pool\PoolManager;
use EasySwoole\EasySwoole\Config;
use EasySwoole\Http\AbstractInterface\Controller;
use EasySwoole\Validate\Validate;
/**
 * 每次进入控制都先获取一个数据库连接(不建议使用)
 * 每次执行完毕都回收数据库连接
 * Class BaseWithDb
 * @package App\HttpController
 */
abstract class BaseWithDb extends Controller
{
    private $db;
    function validate(Validate $validate)
    {
        return parent::validate($validate); // TODO: Change the autogenerated stub
    }
    function onRequest(?string $action): ?bool
    {
        $db = PoolManager::getInstance()->getPool(Mysql2Pool::class)->getObj(Config::getInstance()->getConf('MYSQL.POOL_TIME_OUT'));
        if($db){
            $this->db = $db;
        }else{
            //直接抛给异常处理，不往下
            throw new \Exception('url :'.$this->request()->getUri()->getPath().' error,Mysql Pool is Empty');
        }
        return true;
    }
    protected function gc()
    {
        PoolManager::getInstance()->getPool(Mysql2Pool::class)->recycleObj($this->db);
        parent::gc(); // TODO: Change the autogenerated stub
    }
    protected function getDbConnection():Mysql2Object
    {
        return $this->db;
    }
}