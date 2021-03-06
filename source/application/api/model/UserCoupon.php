<?php

namespace app\api\model;

use app\common\library\helper;
use app\common\model\UserCoupon as UserCouponModel;

/**
 * 用户优惠券模型
 * Class UserCoupon
 * @package app\api\model
 */
class UserCoupon extends UserCouponModel
{

    /**
     * 获取用户优惠券列表
     * @param $userId
     * @param bool $isUse 是否已使用
     * @param bool $isExpire 是否已过期
     * @param float $amount 订单消费金额
     * @return false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getList($userId, $isUse = false, $isExpire = false, $amount = null)
    {
        // 构建查询对象
        $query = $this->where('user_id', '=', $userId)
            ->where('is_use', '=', $isUse)
            ->where('is_expire', '=', $isExpire);
        // 最低消费金额
        if (!is_null($amount) && $amount > 0) {
            $query->where('min_price', '<=', $amount);
        }
        return $query->select();
    }

    /**
     * 获取用户优惠券总数量(可用)
     * @param $user_id
     * @return int|string
     * @throws \think\Exception
     */
    public function getCount($user_id)
    {
        return $this->where('user_id', '=', $user_id)
            ->where('is_use', '=', 0)
            ->where('is_expire', '=', 0)
            ->count();
    }

    /**
     * 获取用户优惠券ID集
     * @param $user_id
     * @return array
     */
    public function getUserCouponIds($user_id)
    {
        return $this->where('user_id', '=', $user_id)->column('coupon_id');
    }

    /**
     * 领取优惠券
     * @param $user
     * @param $coupon_id
     * @return bool|false|int
     * @throws \think\exception\DbException
     */
    public function receive($user, $coupon_id)
    {
        // 获取优惠券信息
        $coupon = Coupon::detail($coupon_id);
        // 验证优惠券是否可领取
        if (!$this->checkReceive($user, $coupon)) {
            return false;
        }
        // 添加领取记录
        return $this->add($user, $coupon);
    }

    /**
     * 添加领取记录
     * @param $user
     * @param Coupon $coupon
     * @return bool
     */
    private function add($user, $coupon)
    {
        // 计算有效期
        if ($coupon['expire_type'] == 10) {
            $start_time = time();
            $end_time = $start_time + ($coupon['expire_day'] * 86400);
        } else {
            $start_time = $coupon['start_time']['value'];
            $end_time = $coupon['end_time']['value'];
        }
        // 整理领取记录
        $data = [
            'coupon_id' => $coupon['coupon_id'],
            'name' => $coupon['name'],
            'color' => $coupon['color']['value'],
            'coupon_type' => $coupon['coupon_type']['value'],
            'reduce_price' => $coupon['reduce_price'],
            'discount' => $coupon->getData('discount'),
            'min_price' => $coupon['min_price'],
            'expire_type' => $coupon['expire_type'],
            'expire_day' => $coupon['expire_day'],
            'start_time' => $start_time,
            'end_time' => $end_time,
            'apply_range' => $coupon['apply_range'],
            'apply_range_config' => $coupon['apply_range_config'],
            'user_id' => $user['user_id'],
            'wxapp_id' => self::$wxapp_id
        ];
        return $this->transaction(function () use ($data, $coupon) {
            // 添加领取记录
            $status = $this->save($data);
            if ($status) {
                // 更新优惠券领取数量
                $coupon->setIncReceiveNum();
            }
            return $status;
        });
    }

    /**
     * 验证优惠券是否可领取
     * @param $user
     * @param Coupon $coupon
     * @return bool
     */
    private function checkReceive($user, $coupon)
    {
        if (!$coupon) {
            $this->error = '优惠券不存在';
            return false;
        }
        if (!$coupon->checkReceive()) {
            $this->error = $coupon->getError();
            return false;
        }
        // 验证是否已领取
        $userCouponIds = $this->getUserCouponIds($user['user_id']);
        if (in_array($coupon['coupon_id'], $userCouponIds)) {
            $this->error = '该优惠券已领取';
            return false;
        }
        return true;
    }

    /**
     * 订单结算优惠券列表
     * @param int $userId 用户id
     * @param double $orderPayPrice 订单商品总金额
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getUserCouponList($userId, $orderPayPrice)
    {
        // 获取用户可用的优惠券列表
        $list = (new static)->getList($userId, false, false, $orderPayPrice);
        $data = [];
        foreach ($list as $coupon) {
            // 最低消费金额
            // if ($orderPayPrice < $coupon['min_price']) continue;
            // 有效期范围内
            if ($coupon['start_time']['value'] > time()) continue;
            $key = $coupon['user_coupon_id'];
            $data[$key] = [
                'user_coupon_id' => $coupon['user_coupon_id'],
                'name' => $coupon['name'],
                'color' => $coupon['color'],
                'coupon_type' => $coupon['coupon_type'],
                'reduce_price' => $coupon['reduce_price'],
                'discount' => $coupon['discount'],
                'min_price' => $coupon['min_price'],
                'expire_type' => $coupon['expire_type'],
                'start_time' => $coupon['start_time'],
                'end_time' => $coupon['end_time'],
                'apply_range' => $coupon['apply_range'],
                'apply_range_config' => $coupon['apply_range_config']
            ];
            // 计算打折金额
            if ($coupon['coupon_type']['value'] == 20) {
                $reducePrice = helper::bcmul($orderPayPrice, $coupon['discount'] / 10);
                $data[$key]['reduced_price'] = bcsub($orderPayPrice, $reducePrice, 2);
            } else
                $data[$key]['reduced_price'] = $coupon['reduce_price'];
        }
        // 根据折扣金额排序并返回
        return array_sort($data, 'reduced_price', true);
    }

    /**
     * 判断当前优惠券是否满足订单使用条件
     * @param $couponList
     * @param $orderGoodsIds
     * @return mixed
     */
    public static function couponListApplyRange($couponList, $orderGoodsIds)
    {
        // 名词解释(is_apply)：允许用于抵扣当前订单
        foreach ($couponList as &$item) {
            if ($item['apply_range'] == 10) {
                // 1. 全部商品
                $item['is_apply'] = true;
            } elseif ($item['apply_range'] == 20) {
                // 2. 指定商品, 判断订单商品是否存在可用
                $applyGoodsIds = array_intersect($item['apply_range_config']['applyGoodsIds'], $orderGoodsIds);
                $item['is_apply'] = !empty($applyGoodsIds);
            } elseif ($item['apply_range'] == 30) {
                // 2. 排除商品, 判断订单商品是否全部都在排除行列
                $excludedGoodsIds = array_intersect($item['apply_range_config']['excludedGoodsIds'], $orderGoodsIds);
                $item['is_apply'] = count($excludedGoodsIds) != count($orderGoodsIds);
            }
            !$item['is_apply'] && $item['not_apply_info'] = '该优惠券不支持当前商品';

        }
        return $couponList;
    }

}
