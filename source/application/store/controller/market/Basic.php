<?php

namespace app\store\controller\market;

use app\store\controller\Controller;
use app\store\model\Goods as GoodsModel;
use app\store\model\Region as RegionModel;
use app\store\model\Setting as SettingModel;

/**
 * 营销设置-基本功能
 * Class Basic
 * @package app\store\controller
 */
class Basic extends Controller
{
    /**
     * 满额包邮设置
     * @return array|bool|mixed
     * @throws \think\exception\DbException
     */
    public function full_free()
    {
        if (!$this->request->isAjax()) {
            // 满额包邮设置
            $values = SettingModel::getItem('full_free');
            return $this->fetch('full_free', [
                // 不参与包邮的商品列表
                'goodsList' => (new GoodsModel)->getListByIds($values['notin_goods']),
                // 获取所有地区(树状结构)
                'regionData' => RegionModel::getCacheTree(),
                // 地区总数
                'cityCount' => RegionModel::getCacheCounts()['city'],
                // 满额包邮设置
                'values' => $values
            ]);
        }
        $model = new SettingModel;
        if ($model->edit('full_free', $this->postData('model'))) {
            return $this->renderSuccess('操作成功');
        }
        return $this->renderError($model->getError() ?: '操作失败');
    }

}
