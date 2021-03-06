<?php
/**
 * Created by PhpStorm.
 * User: zzhpeng
 * Date: 2019/4/19
 * Time: 2:52 PM
 */

namespace App\HttpController;

use App\Model\Catalog\CatalogModel;
use App\Model\File\FileModel;
use App\Model\QiniuPlugin\QiniuPluginModel;
use App\Service\Env;
use App\Utility\Pool\MysqlObject;
use App\Utility\Pool\MysqlPool;
use App\Utils\Date;

class File extends Token
{

    /**
     * 列表
     * @author: zzhpeng
     * Date: 2019/4/28
     * @return bool|void
     * @throws \Throwable
     */
    public function index(){
        try{
            if($this->request()->getMethod() == 'OPTIONS'){
                return $this->successResponse();
            }
            $conditions = $this->getConditions();
            $param = $this->request()->getRequestParam();
            var_dump($param);
            //用账户查找用户,验证是否存在该用户
            $file = MysqlPool::invoke(function (MysqlObject $db) use($conditions,$param) {
                $fileModel = new FileModel($db);
                $qiniuPluginModel = new QiniuPluginModel($db);
                $catalogModel = new CatalogModel($db);
                $catalogs = $catalogModel->getAll($conditions)['list'];
                $catalogs = array_column($catalogs,'name','id');

                $result = $fileModel->getAll($conditions,$param);
                $qiniuPlugin = $qiniuPluginModel->getOne($conditions);

                //分类转换
                foreach($result['list'] as &$item){
                    $item['catalog'] = $catalogs[$item['catalog_id']] ?? '未分类';
                }

                $result['style_separator'] = $qiniuPlugin['style_separator'];
                return $result;
            });

            return $this->successResponse($file);
        }catch (\Exception $exception){
            return $this->failResponse($exception->getMessage(),$exception->getCode());
        }
    }

    /**
     * 条件
     * @author: zzhpeng
     * Date: 2019/4/28
     * @return array
     * @throws \Exception
     */
    function getConditions(){
        $where = [];
        $condition = [];
//        $condition = [
//            'where'=>[
//                [
//                    'account',$param['account']
//                ]
//            ]
//        ];
        $uid = $this->userId();
        array_push($where,['user_id',$uid]);

        $param = $this->request()->getRequestParam();

        if(isset($param['catalog_id'])){
            array_push($where,['catalog_id',$param['catalog_id']]);
        }

        if(!empty($where)){
            $condition = [
                'where'=>$where,
                'orderBy'=>[
                    [
                        'id','DESC'
                    ]
                ]
            ];
        }

        return $condition;
    }


    /**
     * @author: zzhpeng
     * Date: 2019/4/19
     * @return bool
     * @throws \Throwable
     */
    public function edit(){
        try{
            $userId = $this->userId();
            $param = $this->request()->getRequestParam();
            $validate = ($validate = new CatalogValidate())->edit($param);

            if($validate){
                throw new \Exception($validate);
            }

            MysqlPool::invoke(function (MysqlObject $db) use($param,$userId) {
                $catalogModel = new CatalogModel($db);
                $catalogBean = new CatalogBean();

                //验证
                $catalog = $catalogModel->getOne([
                    'where'=>[
                        [
                            'user_id',$userId,
                        ], [
                            'id',$param['id'],
                        ]
                    ]
                ]);

                if(empty($catalog)){
                    throw new \Exception('数据不存在');
                }
                unset($catalog);

                //查找数据是否有该目录名的数据
                $isExist = $catalogModel->getOne([
                    'where'=>[
                        [
                            'name',$param['name']
                        ],
                        [
                            'user_id',$userId
                        ],
                        [
                            'id',$param['id'],'<>'
                        ],
                        [
                            'parent_id',$param['parent_id'],
                        ],
                    ]
                ]);

                if(!empty($isExist)){
                    throw new \Exception('目录名已存在');
                }

                //更新数据
                $updateData['name'] = $param['name'];
                $updateData['parent_id'] = $param['parent_id'];
                $updateData['create_time'] = Date::defaultDate();

                $catalogBean->setId($param['id']);

                $result = $catalogModel->update($catalogBean, $updateData);
                if ($result === false) {
                    throw new \Exception('目录名更改失败');
                }
            });
            return $this->successResponse();
        }catch (\Exception $exception){
            return $this->failResponse($exception->getMessage(),$exception->getCode());
        }
    }

    /**
     * @author: zzhpeng
     * Date: 2019/4/19
     * @return bool
     * @throws \Throwable
     */
    public function delete(){
        try{
            $userId = $this->userId();
            $param = $this->request()->getRequestParam();
            $validate = ($validate = new CatalogValidate())->delete($param);

            if($validate){
                throw new \Exception($validate);
            }

            MysqlPool::invoke(function (MysqlObject $db) use($param,$userId) {
                $catalogModel = new CatalogModel($db);
                $catalogBean = new CatalogBean();

                //验证 todo 在使用的目录不给删除
                $catalog = $catalogModel->getOne([
                    'where'=>[
                        [
                            'user_id',$userId,
                        ], [
                            'id',$param['id'],
                        ]
                    ]
                ]);

                if(empty($catalog)){
                    throw new \Exception('数据不存在');
                }
                unset($catalog);

                $catalogBean->setId($param['id']);

                $result = $catalogModel->delete($catalogBean);
                if ($result === false) {
                    throw new \Exception('删除失败');
                }
            });
            return $this->successResponse();
        }catch (\Exception $exception){
            return $this->failResponse($exception->getMessage(),$exception->getCode());
        }
    }


    public function getUpToken(){
        try{
            if($this->request()->getMethod() == 'OPTIONS'){
                return $this->successResponse();
            }
            $userId = $this->userId();
            $qiniuPlugin = MysqlPool::invoke(function (MysqlObject $db) use($userId) {
                $qiniuPluginModel = new QiniuPluginModel($db);
                return $qiniuPluginModel->getOne([
                    'where'=>[
                        [
                            'user_id',$userId,
                        ]
                    ]
                ]);

            });
            if(!$qiniuPlugin){
                throw new \Exception('请先配置七牛');
            }

            $policy = array(
                'callbackUrl' => Env::getInstance()->get('QINIU.CALLBACK_URL') . '/service/fileCallBack',
                'callbackBody' => '{"fname":"$(fname)","fkey":"$(key)","desc":"$(x:name)","uid":' . $userId . '}'
            );
            $auth = new \Qiniu\Auth($qiniuPlugin['accessKey'], $qiniuPlugin['secretKey']);
            $upToken = $auth->uploadToken($qiniuPlugin['bucket'], null, 3600, $policy);
            return $this->successResponse([
                'uptoken'=>$upToken,
                'domain'=>$qiniuPlugin['domain'],
                'region'=>$qiniuPlugin['zone'],
                'fname'=>'picture',
                ]);
        }catch (\Exception $e){
            return $this->failResponse($e->getMessage(),$e->getCode());
        }

    }

}