<?php

namespace app\admin\controller;

use app\admin\model\SckClient;
use app\admin\model\SckWarehouseGoodLog;
use app\admin\model\SckWarehouseGoodLogPay as WarehouseGoodLogPayModel;
use think\Db;
use app\admin\model\SckWarehouseGoodLog as WarehouseGoodLogModel;
use app\admin\model\SckWarehouseGood as WarehouseGoodModel;
use think\Exception;
use think\exception\PDOException;
use think\Session;
use think\Validate;
use app\admin\model\Admin as adminModel;

//管理员模型

class WarehouseGoodLog extends Permissions
{
    public function GoodEnter()
    {

        $good_id = $this->request->has('good_id') ? $this->request->param('good_id', 0, 'intval') : 0;
        if ($good_id > 0) {
            $good = db('sck_warehouse_good')
                ->where(['good_id' => $good_id])->find();
//            if($good['good_number']>$good['good_warn']){
//                return  $this->error('该商品库存未到预警值，不可入库！');
//            }
            $good_old = db('sck_warehouse_good_log')
                ->where(['good_id' => $good_id,'good_status'=>1])->order('create_time','desc')->find();
//            if($good['good_number']>$good['good_warn']){
//                return  $this->error('该商品库存未到预警值，不可入库！');
//            }
            $GoodLogModel = new WarehouseGoodLogModel();
            if ($this->request->isPost()) {
                $post = $this->request->post();
                $validate = new Validate([
                    ['good_name', 'require', '商品名称不能为空'],
                    ['good_price', 'require', '商品单价不能为空'],
                    ['good_total', 'require', '商品总价不能为空'],
                    ['good_amount', 'require', '入库数量不能为空'],
                ]);
                $admin_id = Session::get('admin');
                if (!$admin_id) {
                    $json = ['code' => 0, 'msg' => '页面错误，刷新后重试！', 'url' => ''];
                    return $json;
                }
                $post['admin_id'] = $admin_id;
                $post['good_status'] = 1;
                $post['is_good_enter'] = 0;
                if (!$validate->check($post)) {
                    $this->error('提交失败：' . $validate->getError());
                }
                Db::startTrans();
                try {
                    $ok = $GoodLogModel->allowField(true)->save($post);
//                    $log_id = db('sck_warehouse_good_log')->insertGetId($post);
                    if ($ok) {
//                        $insert = db('sck_warehouse_good')
//                            ->where(['good_id' => $good_id])
//                            ->update(['good_number' => WarehouseGoodModel::raw('good_number+' . $post['good_amount'] . ''), 'good_amount' => WarehouseGoodModel::raw('good_amount+' . $post['good_amount'] . '')]);
//                        if ($insert) {
//                            $insert_pay_data = [
//                                'log_id'=>
//                            ];
                            addlog($good_id);
                            $json = ['code' => 1, 'msg' => '入库成功！', 'url' => ''];
//                        } else {
//                            throw new \Exception('入库失败，请重试!');
//                        }
                    } else {
                        throw new \Exception('入库失败，请重试!');
                    }
                    // 提交事务
                    Db::commit();
                } catch (\Exception $e) {
                    // 回滚事务
                    Db::rollback();
                    $json = ['code' => 0, 'msg' => $e->getMessage()];
                }
                return $json;

            } else {
                $supplier = db('sck_supplier')->select();
                $this->assign('supplier', $supplier);
                $this->assign('good_id', $good_id);
                $this->assign('good_old', @$good_old);
                $this->assign('good_name', $good);
                return $this->fetch();
            }
        } else {
            return $this->error('页面错误，请重试！');
        }
    }

    public function GoodOut()
    {
        $good_id = $this->request->has('good_id') ? $this->request->param('good_id', 0, 'intval') : 0;
        $log_id = $this->request->has('log_id') ? $this->request->param('log_id', 0, 'intval') : 0;
        if ($good_id > 0) {
            $good = db('sck_warehouse_good')
                ->where(['good_id' => $good_id])->find();
            if (empty($good) or $good['good_number'] <= 0) {
                return $this->error('该商品库存不足，不可出库，请联系管理员进行整理库存！');
            }
            $GoodLogModel = new WarehouseGoodLogModel();
            $GoodLogPayModel = new WarehouseGoodLogPayModel();
            if ($this->request->isPost()) {
                Db::startTrans();
                $post = $this->request->post();
                $validate = new Validate([
                    ['good_price', 'require', '最低出库价不能为空'],
                    ['good_amount', 'require', '出库数量不能为空'],
                    ['client_id', 'require', '请选择客户'],
                ]);

                $admin_id = Session::get('admin');
                if (!$admin_id) {
                    $json = ['code' => 0, 'msg' => '页面错误，刷新后重试！', 'url' => ''];
                    return $json;
                }
                $post['admin_id'] = $admin_id;
                $post['good_status'] = 2;
                if (!$validate->check($post)) {
                    $this->error('提交失败：' . $validate->getError());
                }
//                if($post['good_price']<$post['lowest_price']){
//                    return  $this->error('该商品最低出库价'.$post['lowest_price'].',不足以出库！');
//                }
                if ($good['good_number'] < $post['good_amount']) {
                    return $this->error('该商品库存剩余' . $good['good_number'] . ',不足以出库！');
                }
//                if ($post['pay'] > $post['good_total']) {
//                    return $this->error('该商品付款商品金额有误');
//                }
                $post['create_time'] = time();
                $post['update_time'] = time();
//                unset($post['lowest_price']);
                $low = $post['lowest_price'];
                $post['lowest_price'] = $post['good_total'] - $post['lowest_price'];
//                $pay_money = $post['pay'];
//                dump($post);die;
                if (isset($post['reason']) && !empty($post['reason'])) {
                    $client=db('sck_client')->where(['client_id'=>$post['client_id']])->find();
                    $arr = [
                        'admin_id' => $admin_id,
                        'reason' => $post['reason'],
                        'add_time' => time(),
                        'status' => 0,
                        'client'=>$client['client_name'],
                        'phone'=>empty($client['client_phone'])?0:$client['client_phone'],
                        'good_name'=>$post['good_name'],
                        'good_id'=>$post['good_id'],
                        'new_price'=>$post['good_total'],
                        'old_price'=>$low,
                        'company'=>$post['company'],
                    ];
                    db('reason')->insert($arr);
                }
//                unset($post['pay'], $post['reason']);
                unset($post['reason']);
                try {
                    $ok = $GoodLogModel->insertGetId($post);
//                    $arr = [
//                        'log_id' => $ok,
//                        'good_id' => $post['good_id'],
//                        'create_time' => time(),
//                        'update_time' => time(),
//                        'pay_price' => $pay_money,
//                        'pay_total' => $post['good_total'],
//                        'pay_status' => 2,
//                        'admin_id' => $admin_id,
//                        'client_id' => $post['client_id']
//                    ];
                    if ($ok) {
//                        $pay = $GoodLogPayModel->insert($arr);
                        $insert = db('sck_warehouse_good')
                            ->where(['good_id' => $good_id])
                            ->update(['good_number' => WarehouseGoodModel::raw('good_number-'.$post['good_amount'].'')]);
                        if ($insert) {

                            addlog($good_id);
                            $json = ['code' => 1, 'msg' => '出库成功！', 'url' => ''];
                        } else {
                            throw new \Exception('出库失败，请重试!');
                        }
                    } else {
                        throw new \Exception('出库失败，请重试!');
                    }
                    // 提交事务
                    Db::commit();
                } catch (\Exception $e) {
                    // 回滚事务
                    Db::rollback();
                    $json = ['code' => 0, 'msg' => $e->getMessage()];
                }
                return $json;

            } else {
                $advise_price = db('sck_warehouse_good_log')
                    ->where(['good_id' => $good_id, 'good_status' => 2, 'good_price' => ['neq', 0]])
                    ->order('create_time', 'desc')
                    ->limit(1)
                    ->column('good_price');
                $advise_price = @$advise_price[0];
                $good_log = db('sck_warehouse_good_log')
                    ->where(['good_id' => $good_id, 'good_status' => 1, 'good_amount' => ['neq', 0]])
                    ->order('create_time', 'desc')
                    ->limit(2)
                    ->select();
                if (empty($good_log)) {
                    return $this->error('该商品暂无可用库存，请联系管理员，确认库存！');
                } else {
                    $total_price = array_sum(array_column($good_log, 'good_total'));
                    $total_amount = array_sum(array_column($good_log, 'good_amount'));
                    $lowest_price = round($total_price / $total_amount, 2);
                }
                $tax = db('tax_invoice')->select();
                $this->assign('tax', $tax);

                if(!empty($log_id) and $log_id>0){
                    $good_log = db('sck_warehouse_good_log')
                        ->where(['good_id' => $good_id, 'good_status' => 2,'log_id'=>$log_id])
                        ->find();
                    $this->assign('good_log', $good_log);
                }

                $ClientModel = new SckClient();
                //=================================================================================
//                $id = Session::get('admin');
//                if(!empty($id)){
//                    $user_info = adminModel::get($id);
//                    if(!empty($user_info->address_ids)){
//                        $address_ids = json_decode($user_info->address_ids,true);
//                    }else{
//                        $address_ids =[];
//                    }
//                }else{
//                    $address_ids =[];
//                }
//                if(!empty($address_ids)){
//                    $street_ids = db('address')
//                        ->where(['id1'=>1,'status'=>1,'id4'=>['in',$address_ids]])
//                        ->column('id5');
//                    $street_ids = array_filter($street_ids);
//                }else{
//                    $street_ids =[];
//                }
                //=================================================================================
                $Client = $ClientModel
//                    ->where(['client_position_id' => ['in',$street_ids]])
                    ->order('create_time desc')
                    ->select();
                $this->assign('lowest_price', $lowest_price);
                $this->assign('good_id', $good_id);
                $this->assign('good', $good);
                $this->assign('advise_price', $advise_price);
                $this->assign('client', $Client);

                return $this->fetch();
            }
        } else {
            return $this->error('页面错误，请重试！');
        }
    }

    public function GoodReturn()
    {
        $good_id = $this->request->has('good_id') ? $this->request->param('good_id', 0, 'intval') : 0;
        if ($good_id > 0) {

            $GoodLogModel = new WarehouseGoodLogModel();
            if ($this->request->isPost()) {
                $post = $this->request->post();

                $validate = new Validate([
                    ['good_name', 'require', '商品名称不能为空'],
//                    ['good_price', 'require', '商品单价不能为空'],
//                    ['good_total', 'require', '商品总价不能为空'],
                    ['good_amount', 'require', '退货数量不能为空'],
                ]);
                $num = $GoodLogModel->where(['log_id'=>$post['log_id']])->value('good_amount');
                $is_return = $GoodLogModel->where(['log_id'=>$post['log_id']])->value('is_delivery');
                if($is_return!=1){
                    return $this->error('该商品未确认出库，不可退货！');
                }
                if($post['good_amount']<=0){
                    return $this->error('退货数量不能为0');
                }
                $already_number = $GoodLogModel->where(['log_parent_id'=>$post['log_id'],'good_id'=>$good_id,'good_status'=>3])->sum('good_amount');
                $can_return = (int)$num-(int)$already_number-$post['good_amount'];
                if($can_return<0){
                    $can_return = (int)$num-(int)$already_number;
                    $can_return = $can_return>=0?$can_return:0;
                    return $this->error('该出库记录可退货'.$can_return.'件！');
                }
                if ($num < $post['good_amount']) {
                    return $this->error('退货数不能大于出库数！');
                }
                $admin_id = Session::get('admin');
                if (!$admin_id) {
                    $json = ['code' => 0, 'msg' => '页面错误，刷新后重试！', 'url' => ''];
                    return $json;
                }
                $post['admin_id'] = $admin_id;
                $post['good_status'] = 3;
                if (!$validate->check($post)) {
                    $this->error('提交失败：' . $validate->getError());
                }
                $log_id = $post['log_id'];
                $post['log_parent_id'] = $post['log_id'];
                unset($post['log_id']);
                Db::startTrans();
                try {
                    $post['good_total'] = round($post['good_price'] * $post['good_amount'], 2)*-1;
                    $post['lowest_price'] = round($post['good_total']*-1 - $post['cost_total'], 2)*-1;
                    $post['cost_price'] = round($post['cost_price'], 2)*-1;
                    $post['cost_total'] = round($post['cost_total'], 2)*-1;
                    $post['create_time'] = time();
                    $post['update_time'] = time();
//                    dump($post);die;

                    $ok = $GoodLogModel->allowField(true)->insert($post);
                    if ($ok) {
//                        $insert = db('sck_warehouse_good')
//                            ->where(['good_id' => $good_id])
//                            ->update(['good_number' => WarehouseGoodModel::raw('good_number+' . intval($post['good_amount'] . ''))]);

//                        if($num['good_amount'] != $post['good_amount']){
//                        $already_number = $GoodLogModel->where(['log_parent_id'=>$post['log_parent_id'],'good_id'=>$good_id,'good_status'=>3])->sum('good_amount');

//                        $insert =$GoodLogModel->where('log_id', $log_id)->update(['is_return' => 1, 'good_desc' => "退货产品，共计退回" . $already_number]);
                        $insert =$GoodLogModel->where('log_id', $log_id)->update(['is_return' => 1]);
//                        $pay = db('sck_warehouse_good_log_pay')->where(['log_id' => $log_id])->find();
//                        if ($pay) {
//                            db('sck_warehouse_good_log_pay')->where(['log_id' => $log_id])->update(['pay_status' => 3]);
//                        }
//                        }else{
//                            $GoodLogModel->where('log_id',$log_id)->delete();
//                        }
                        if ($insert) {

                            addlog($good_id);
                            $json = ['code' => 1, 'msg' => '退货入库成功！', 'url' => ''];
                        } else {
                            throw new \Exception('退货入库失败，请重试!');
                        }
                    } else {
                        throw new \Exception('退货入库失败，请重试!');
                    }
                    // 提交事务
                    Db::commit();
                } catch (\Exception $e) {
                    // 回滚事务
                    Db::rollback();
                    $json = ['code' => 0, 'msg' => $e->getMessage()];
                }
                return $json;

            } else {
                $ClientModel = new SckClient();
//                $id = Session::get('admin');
//                if(!empty($id)){
//                    $user_info = adminModel::get($id);
//                    if(!empty($user_info->address_ids)){
//                        $address_ids = json_decode($user_info->address_ids,true);
//                    }else{
//                        $address_ids =[];
//                    }
//                }else{
//                    $address_ids =[];
//                }
//                if(!empty($address_ids)){
//                    $street_ids = db('address')
//                        ->where(['id1'=>1,'status'=>1,'id4'=>['in',$address_ids]])
//                        ->column('id5');
//                    $street_ids = array_filter($street_ids);
//                }else{
//                    $street_ids =[];
//                }
                $client_id = request()->get();
                $this->assign('log_id', $client_id['log_id']);
                if (!empty($client_id['client_id']) && isset($client_id['client_id'])) {
                    $clients = $ClientModel->where(['client_id' => $client_id['client_id']])->find();
                    $this->assign('clients', $clients);
                    $this->assign("good_price", $client_id['good_price']);
//                    $this->assign("good_amount", $client_id['good_amount']);
                    $this->assign('goods_name', $client_id['good_name']);
                    $Log = $GoodLogModel->where(['log_id' => $client_id['log_id']])->find();
                    $this->assign('Log', $Log);
                }
//                dump($Log);die;
                $Client = $ClientModel
//                    ->where(['client_position_id' => ['in',$street_ids]])
                    ->order('create_time desc')
                    ->select();
                $this->assign('client', $Client);
                $this->assign('good_id', $good_id);
                return $this->fetch();
            }
        } else {
            return $this->error('页面错误，请重试！');
        }
    }

    public function GoodLog()
    {
        $post = $this->request->param();
        $good_status = $this->request->has('good_status') ? $this->request->param('good_status', 0, 'intval') : 0;
        $good_id = $this->request->has('good_id') ? $this->request->param('good_id', 0, 'intval') : 0;
        if (isset($post['keywords']) and !empty($post['keywords'])) {
            $where['good_name'] = ['like', '%' . $post['keywords'] . '%'];
            $this->assign('search_good_name', $post['keywords']);
        }
        if (isset($post['supplier_id']) and !empty($post['supplier_id'])) {
            $where['supplier_id'] = $post['supplier_id'];
            $this->assign('search_supplier_id', $post['supplier_id']);
        }
        if (isset($post['admin_id']) and !empty($post['admin_id'])) {
            $where['admin_id'] = $post['admin_id'];
            $this->assign('search_admin_id', $post['admin_id']);
        }
        if (isset($post['client_id']) and !empty($post['client_id'])) {
            $where['client_id'] = $post['client_id'];
            $this->assign('search_client_id', $post['client_id']);
        }
        if (isset($good_status) and !empty($good_status)) {
            $where['good_status'] = $good_status;
        }
        if (isset($post['create_time']) and !empty($post['create_time'])) {
            $min_time = strtotime($post['create_time']);
            $max_time = $min_time + 24 * 60 * 60;
            $where['create_time'] = [['>=', $min_time], ['<=', $max_time]];
        }
        if (isset($post['time']) and !empty($post['time'])) {
            $start_time = strtotime(substr($post['time'], 0, strripos($post['time'], ' - ')));
            $end_time = strtotime(substr($post['time'], strripos($post['time'], ' - ') + 3));
            $this->assign('search_time', $post['time']);
        }
        if (isset($post['client_id']) && !empty($post['client_id'])) {
            $where['client_id'] = $post['client_id'];
        }
        $admin_id = Session::get('admin');
        if (isset($admin_id)) {
            $user_info = adminModel::get($admin_id);

            if ((int)$user_info['admin_power'] == 0) {
                $where['admin_id'] = $admin_id;
            } elseif ((int)$user_info['admin_power'] == 1) {
//                dump((int)$user_info['admin_power']);die;
//                $start_time_is =
//                $where['create_time'] = ;
                if (!empty($start_time) and !empty($end_time)) {
                    $where['create_time'] = ['between', [$start_time, $end_time]];
                }
            } elseif ((int)$user_info['admin_power'] == 2) {
                $this_month_10 = strtotime(date('Y-m-' . '11'));
                $this_day = strtotime(date('Y-m-d'));
                if ($this_day <= $this_month_10) {
                    if (empty($start_time)) {
                        $start_time = mktime(0, 0, 0, date("m") - 1, 1, date("Y"));
                    } else {
                        if ($start_time < mktime(0, 0, 0, date("m") - 1, 1, date("Y"))) {
                            $start_time = mktime(0, 0, 0, date("m") - 1, 1, date("Y"));
                        } else {
                            $start_time;
                        }
                    }
                } elseif ($this_day > $this_month_10) {
                    if (empty($start_time)) {
                        $start_time = mktime(0, 0, 0, date('m'), 1, date('Y'));
                    } else {
                        if ($start_time < mktime(0, 0, 0, date("m"), 1, date("Y"))) {
                            $start_time = mktime(0, 0, 0, date("m"), 1, date("Y"));
                        } else {
                            $start_time;
                        }
                    }

                }
                if (!empty($end_time)) {
                    $end_time;
                } else {
                    $end_time = time();
                }
                $where['create_time'] = ['between', [$start_time, $end_time]];
            }
        } else {
            return $this->error('未检测到登录状态，请重新登陆!');
        }
        if (isset($good_id) and $good_id !== 0) {
            $where['good_id'] = $good_id;
            $this->assign('good_id', $good_id);
        }
        $model = new WarehouseGoodLogModel();
        $data = empty($where) ? $model
            ->order('create_time desc')
            ->paginate(20, false, ['query' => $this->request->param()])
            ->each(function ($k,$v){
                $admin_id = Session::get('admin');
                $user_info = adminModel::get($admin_id);
//                dump((int)$user_info['admin_power']);
//                dump((int)$user_info['client_power']);die;
                if ((int)$user_info['admin_power'] == 1) {
                    if((int)$user_info['client_power']!==1){
                        if($k['admin_id'] !== $admin_id){
                            $k['client_power'] = 1 ;
                        }
                    }

                }
            })
            : $model->where($where)
                ->order('create_time desc')
                ->paginate(20, false, ['query' => $this->request->param()])->each(function ($k,$v){
                    $admin_id = Session::get('admin');
                    $user_info = adminModel::get($admin_id);
//                    dump((int)$user_info['admin_power']);
//                    dump((int)$user_info['client_power']);die;
                    if ((int)$user_info['admin_power'] == 1) {
                        if((int)$user_info['client_power']!==1){
                            if($k['admin_id'] !== $admin_id){
                                $k['client_power'] = 1 ;
                            }
                        }
                    }
                });





        $supplier = db('sck_supplier')->select();
        $this->assign('supplier', $supplier);
        $ClientModel = new SckClient();
        $Client = $ClientModel
//                    ->where(['client_position_id' => ['in',$street_ids]])
            ->order('create_time desc')
            ->select();
        $this->assign('client', $Client);
        $this->assign('data', $data);
        $this->assign('good_status', $good_status);
        $info['admin'] = Db::name('admin')->select();
        $this->assign('admin',$info);

        return $this->fetch();
    }


}
