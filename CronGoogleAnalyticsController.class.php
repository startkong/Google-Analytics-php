<?php
/**
 * Created by PhpStorm.
 * User: xuheng
 * Date: 2018/5/24
 * Time: 下午4:33
 */

namespace Api\Controller;


use Think\Exception;

class CronGoogleAnalyticsController
{
    private $website = [
        2 => '112937380',   //站点的视图id
    ];

    //查看ga文档，找到对应title
    private $ga_name = [
        0 => [
            'see_num'       => 'ga:productDetailViews',    //产品详情视图
            'goods_car'     => 'ga:cartToDetailRate',      //查看详情后添加到购物车的比例
            'goods_cvr'     => 'ga:buyToDetailRate',       //查看详情后购买的比例
            'pay_num'       => 'ga:itemQuantity',          //购买的产品数量
            'goods_amount'  => 'ga:itemRevenue',           //产品收入
            'cart_num'      => 'ga:productAddsToCart',     //产品被添加到购物车的次数
            'goods_num'     => 'ga:productCheckouts',      //产品结帐次数
        ],
    ];

    public function getGaDateAgo()
    {
        $do_start_time = time();
        set_time_limit(0);
        require_once VENDOR_PATH.'/autoload.php';
        try{
        
            //获取授权
            $client = new \Google_Client();
            $client->setApplicationName("Hello Analytics Reporting");
            $client->setAuthConfig(json_decode(C('google_analytics'), 1));
            $client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
            $analytics = new \Google_Service_AnalyticsReporting($client);

            //获取各站点中的数据
            $insert_arr = $this->getReport($analytics, $format);

        
            echo '执行成功'.$format.' ga数据拉取成功, 耗时'.(time()-$do_start_time).'s';
        } catch (Exception $e) {
            echo '执行失败'.(!isset($format)?'':$format).' ga数据拉取失败, 原因:'.$e->getMessage();
        }
        exit();
    }

    public function getGaDate()
    {
        $do_start_time = time();
        set_time_limit(0);
        require_once VENDOR_PATH.'/autoload.php';
        try{
            $the_small_date = M('plan_goods_ga')->where(['delete_flag' => 0])->getField('max(stat_time)');
            if(!$the_small_date) {
                $format = date('Y-m-d', strtotime('-1 day'));
            } else {
                $format = date('Y-m-d', strtotime('1 day', $the_small_date));
            }

            if($format == date('Y-m-d')) {
                throw new Exception('不能获取当天数据');
            }

            $map_time = strtotime($format);
            if(M('plan_goods_ga')->where(['delete_flag' => 0, 'stat_time' => $map_time])->find()) {
                throw new Exception('数据已拉取');
            }

            //获取授权
            $client = new \Google_Client();
            $client->setApplicationName("Hello Analytics Reporting");
            $client->setAuthConfig(json_decode(C('google_analytics'), 1));
            $client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
            $analytics = new \Google_Service_AnalyticsReporting($client);

            //获取各站点中的数据
            $insert_arr = $this->getReport($analytics, $format);

            if(empty($insert_arr['goods_ga']) && empty($insert_arr['sku_ga'])) {
                throw new Exception('获取数据失败');
            }
            try{
                M()->startTrans();
                if(!empty($insert_arr['goods_ga'])) {
                    if(!M('plan_goods_ga')->addAll($insert_arr['goods_ga'])) {
                        throw new Exception('添加goods_sn数据失败');
                    }
                }
                if(!empty($insert_arr['sku_ga'])) {
                    if(!M('plan_sku_ga')->addAll($insert_arr['sku_ga'])) {
                        throw new Exception('添加goods_sku数据失败');
                    }
                }
                M()->commit();
            } catch (Exception $e) {
                M()->rollback();
                throw new Exception($e->getMessage());
            }
            echo '执行成功'.$format.' ga数据拉取成功, 耗时'.(time()-$do_start_time).'s';
        } catch (Exception $e) {
            echo '执行失败'.(!isset($format)?'':$format).' ga数据拉取失败, 原因:'.$e->getMessage();
        }
        exit();
    }

    function getReport($analytics, $time) {
        //遍历站点，回去各站点数据
        $strtotime = time();
        $lovely_metri = array_values($this->ga_name[0]);
        $lovely_metri_key = array_keys($this->ga_name[0]);

        $shop_metri = array_values($this->ga_name[1]);
        $shop_metri_key = array_keys($this->ga_name[1]);

        $insert_arr = $sku_insert_arr = [];
        foreach ((array)$this->website as $key => $view_id) {
            // 实例化时间对象
            $dateRange = new \Google_Service_AnalyticsReporting_DateRange();
            $dateRange->setStartDate($time);
            $dateRange->setEndDate($time);

            // 实例化指标对象
            if($key == 4) {
                $metric_arr = array_map(function ($v) {
                    $obj = new \Google_Service_AnalyticsReporting_Metric();
                    $obj->setExpression($v);
                    return $obj;
                }, $lovely_metri);
            } else {
                $metric_arr = array_map(function ($v) {
                    $obj = new \Google_Service_AnalyticsReporting_Metric();
                    $obj->setExpression($v);
                    return $obj;
                }, $shop_metri);
            }
            //实例化维度对象
            $productSku = new \Google_Service_AnalyticsReporting_Dimension();
            $productSku->setName("ga:productSku");


            // 实例化请求对象.
            $request = new \Google_Service_AnalyticsReporting_ReportRequest();

            $request->setViewId($view_id); //设置获取对象的视图id

            $request->setDateRanges(array($dateRange)); //设置获取对象的时间

            $request->setDimensions($productSku); //设置获取对象的维度

            $request->setMetrics($metric_arr); //设置获取对象的指标

            $request->pageSize = 10000;

            //请求ga,获取数据对象
            $body = new \Google_Service_AnalyticsReporting_GetReportsRequest();
            $body->setReportRequests( array( $request) );
            $data = $analytics->reports->batchGet( $body );

            if($key == 4) { //lovely，如果是lovely,则直接根据goods_sku获取对应数据
                for ( $reportIndex = 0; $reportIndex < count( $data ); $reportIndex++ ) {
                    $report = $data[ $reportIndex ];
                    $header = $report->getColumnHeader();
                    $metricHeaders = $header->getMetricHeader()->getMetricHeaderEntries();
                    $rows = $report->getData()->getRows();

                    for ( $rowIndex = 0; $rowIndex < count($rows); $rowIndex++) {
                        $row = $rows[ $rowIndex ];
                        $dimensions = $row->getDimensions();
                        $metrics = $row->getMetrics();

                        for ($j = 0; $j < count( $metricHeaders ) && $j < count( $metrics ); $j++) {
                            if($dimensions[0] == '(not set)') {
                                continue;
                            }
                            $values = $metrics[$j];
                            foreach ((array)$values->getValues() as $mk => $metri_val) {
                                $arr_list[$lovely_metri_key[$mk]] = $metri_val;
                            }

                            //同下数组一起添加，必须键值顺序一致
                            $insert_arr[] = [
                                'goods_sn'      => $dimensions[0],
                                'web_id'        => $key,
                                'see_num'       => $arr_list['see_num'],
                                'pay_num'       => $arr_list['pay_num'],
                                'cart_num'      => $arr_list['cart_num'],
                                'goods_amount'  => $arr_list['goods_amount'],
                                'goods_num'     => $arr_list['goods_num'],
                                'goods_cvr'     => $arr_list['goods_cvr'],
                                'goods_car'     => $arr_list['goods_car'],
                                'add_time'      => $strtotime,
                                'stat_time'     => strtotime($time),
                                'operatorId'    => 1,
                                'delete_flag'   => 0,
                                'admin_id'      => 1,
                            ];
                        }
                    }
                }
            } else {//lovely以外的站点以商品sku为维度，需求和处理
                $back_arr = [];
                for ( $reportIndex = 0; $reportIndex < count( $data ); $reportIndex++ ) {
                    $report = $data[ $reportIndex ];
                    $header = $report->getColumnHeader();
                    $metricHeaders = $header->getMetricHeader()->getMetricHeaderEntries();
                    $rows = $report->getData()->getRows();

                    for ( $rowIndex = 0; $rowIndex < count($rows); $rowIndex++) {
                        $row = $rows[ $rowIndex ];
                        $dimensions = $row->getDimensions();
                        $metrics = $row->getMetrics();

                        for ($j = 0; $j < count( $metricHeaders ) && $j < count( $metrics ); $j++) {
                            $values = $metrics[$j];
                            foreach ((array)$values->getValues() as $mk => $metri_val) {
                                $sku = preg_replace('|[a-zA-Z/]+|','',$dimensions[0]);
                                $back_arr[$sku][$shop_metri_key[$mk]] = $metri_val;
                            }
                        }
                    }
                }
            }
        }

        return $back_arr;
    }

}