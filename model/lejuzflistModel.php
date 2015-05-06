<?php

class lejuzflistModel extends spiderModel
{
    function getCategory() {
        header("Content-type: text/html; charset=utf-8");
        $Category = Application::$_spider ['Category'];
        $thistimerun = isset ( $Category ['Category_Run'] ) ? $Category ['Category_Run'] : 1;
        $collection_category_name = Application::$_spider [elements::COLLECTION_CATEGORY_NAME];
        $poolname = $this->spidername . 'Category';
        $sid = Application::$_spider ['stid'];
        $collection = 'lejubroker_Items';
        // 清理Category现场
        $this->pools->del ( $poolname );
        $this->redis->delete ( $this->spidername . 'CategoryCurrent' );
        $this->redis->delete ( $this->spidername . 'ItemCurrent' );
        $this->redis->delete ( $this->spidername . 'Item' );
        $this->redis->delete ( $this->spidername . 'ItemJobRun' );

        $collection_item_name = Application::$_spider [elements::COLLECTION_ITEM_NAME];
//创建索引
        $this->mongodb->ensureIndex($collection_item_name,array('skuid'=>1,'stid'=>1),array('unique'=>true));



        // 判断本次是否重新抓取分类数据
        if ($thistimerun) {
            $Category_URL = $Category[elements::CATEGORY_URL];
            $tmp = $this->curlmulit->remote( $Category_URL , null, false, Application::$_spider[elements::CHARSET],Application::$_spider [ elements::ITEMPAGECHARSET]);
            $page = $tmp[$Category_URL];
            $Categorytmp = array();
            $total = $this->mongodb->count($collection);

            //城市转换
            $dcity = $this->mongodbsec->find('leju_area',array());
            $citys = array();
            foreach($dcity as $k=>$v)
            {
                $urlarr=parse_url($v['cid']);
                parse_str($urlarr['query'],$parr);
                $p = '/com\/(\w+)\/ag/';
                preg_match($p,$v['cid'],$out);
                if($out[1])
                    $citys[$out[1]] = $parr['SourceCityName'];
            }
            $s = 0;
            $limit = 1000;
            do{
                $data = $this->mongodb->find($collection,array(), array (
                    "start" => $s,
                    "limit" => $limit
                ) );
                $Categorylist = array();
                foreach($data as $k=>$v)
                {
                    $tmp = $v['source_url'];
                    $p = '/com\/(\w+)\/shop\/(\d+)/';
                    preg_match($p,$tmp,$out);
                    $city = $out[1];
                    $agentid = $out[2];

                    if($city && $agentid)
                    {
                        $cityname = $citys[$city];
//                        $url = 'http://m.leju.com/?site=touch&ctl=js&act=agent_house&city='.$city.'&page=#i&type=2&agentid='.$agentid;
                        $msg = '乐居的兄弟你们辛苦了，领导不行不如赶紧撤了吧';
                        $url = 'http://m.leju.com/touch/esf/'.$city.'/shop/'.$agentid.'-3/n#i-aj1?'.urlencode($msg);
                        $url .='&SourceCityName='.urlencode($cityname).'&city='.$city.'&agentid='.$agentid;
                        $this->pools->set ( $poolname, $url );
//                        $Categorylist[$city.'-'.$agentid] = array('city'=>$city,'agentid'=>$agentid,'url'=>$url,'agentname'=>$v['UserName']);
                    }else{
                        $this->log->errlog ( array (
                            'job' => $tmp,
                            'Categoryurl' => $tmp,
                            'error' => 'no city or agentid',
                            'addtime' => date ( 'Y-m-d H:i:s' )
                        ) );
                    }
                }
//                if($Categorylist)
//                    $this->mongodb->batchinsert ( $collection_category_name, $Categorylist );
//                unset($Categorylist);
                $s +=$limit;
                echo "has load".$s."\n";
            }while($s<$total);
        } else {
            $Categorylist = $this->mongodb->find ( $collection_category_name, array () );
            foreach ( $Categorylist as $obj ) {
                $cid = $obj ['cid'];
                $this->pools->set ( $poolname, $cid );
            }
        }
        exit("do over");
    }

    function CategroyJob() {

        header("Content-type: text/html; charset=utf-8");
        $name = $this->spidername . 'Category';
        $jobname = 'Category';
        $spidername = str_replace ( 'Spider', "", $this->spidername );
        $errno = 0;//记录categoryjob抓取不到数据的次数，超过3次后放弃该任务
        $collection_item_name = Application::$_spider [elements::COLLECTION_ITEM_NAME];
        if(isset($_GET['debug']) && $_GET['debug']=='categoryjob')
        {
            $gurl = isset($_GET['url'])?trim($_GET['url']):"";
            $job = $gurl;
        }else{
            $tmp = $this->pools->get ( $name );
            $jobs = array_values($tmp);
            $job = $jobs[0];
        }


        $poolname = $this->spidername . 'Item';
        $Category = Application::$_spider [elements::CATEGORY];
        $xpath = $Category [elements::CATEGORY_MATCHING];
        if(isset($Category [elements::TRANSFORM]) && $Category [elements::TRANSFORM] === false)
            $Categoryurl = $job.$Category [elements::TRANSFORMADDSPECIL];
        else{
            if($Category [elements::CATEGORY_LIST_URL])
                $Categoryurl = str_replace ( "#job", $job, $Category [elements::CATEGORY_LIST_URL] );
            else
                $Categoryurl = str_replace("#i",1,$job);
        }
        $urlarr=parse_url($Categoryurl);
        parse_str($urlarr['query'],$parr);
        $sourceCityName = $parr['SourceCityName'];
        $city = $parr['city'];
        $agentid = $parr['agentid'];
        // 首先获取下该分类下面的总页数
        $pageHtml = $this->curlmulit->remote ( $Categoryurl,null,false,Application::$_spider [ elements::ITEMPAGECHARSET],Application::$_spider [elements::HTML_ZIP]);
        if (! $pageHtml) {
//			$this->autostartitemmaster ();
            $this->pools->delerrjob($spidername,$jobname,$job,$Categoryurl,'no page');
        }
        $page = $pageHtml[$Categoryurl];
        $pageArray = json_decode($page,true);


        if($pageArray['message'] != 'success' || !$pageArray['data']['list'][0]['id'])
        {
            $this->pools->delerrjob($spidername,$jobname,$job,$Categoryurl,'has page no pagedata');
        }
        //获取分类列表页总页数，如果获取不到则自动停止，并做好相应记录
        $totalpages = $pageArray['data']['total_page'];
        $s = isset ( $Category [elements::CATEGORY_PAGE_START] ) ? $Category[elements::CATEGORY_PAGE_START] : 0;
        $pagesize = $Category [elements::CATEGORY_GROUP_SIZE];
        if ($totalpages > 0) {
            $totalpages +=1;
            // 循环获取商品的url地址
            do {
                if ($totalpages < $pagesize) {
                    $e = $totalpages;
                } else {
                    $e = $s + $pagesize;
                }
                $tmpurls = array ();
                for($i = $s; $i < $e; $i ++) {
                    $url = str_replace ( '#i', $i, $job );
                    $tmpurls [$url] = $url;
                }
                $pages = $this->curlmulit->remote ( $tmpurls, null, false ,Application::$_spider [ elements::ITEMPAGECHARSET],Application::$_spider [elements::HTML_ZIP]);
                /**
                 * 能否抓去到数据检测,此代码保留
                 */
                if ($s == 0 && count ( $pages ) == 0) {
                    delerrjob($spidername,$jobname,$job,$Categoryurl,'no list');
                }
                foreach ( $pages as $rurl => $page ) {
                    //加入错误日志
                    unset($tmpurls[$rurl]);
                    //加入列表页数据的获取并保存
                    $pdata = json_decode($page,true);
                    $categorydata = $pdata['data']['list'];

                    if($categorydata){
                        foreach($categorydata as $item)
                        {
                            $item['Category_Source_Url'] = $rurl;
                            $item_url = 'http://m.leju.com/touch/esf/'.$city.'/detail/'.$item['id'];
                            $item[\elements::CATEGORY_ITEM_URL] = $item_url;
                            $item['job'] = $job;
                            if($sourceCityName)
                                $item['SourceCityName'] = $sourceCityName;
                            $item['skuid'] = $item['id'];
                            $dpages = $this->curlmulit->remote ( $item_url, null, false ,Application::$_spider [ elements::ITEMPAGECHARSET],Application::$_spider [elements::HTML_ZIP]);
                            $dpage = $dpages[$item_url];
                            $fliter = '//div[@class="house_title"]/text()';
                            $titles = $this->curlmulit->getRegexpInfo2($fliter,$dpage);
                            $item['title'] = $titles[0];
                            $item['agentid'] = $agentid;
//                            print_r($item);
//                            if($item[\elements::CATEGORY_ITEM_URL])
//                                $this->pools->set ( $poolname, $item[\elements::CATEGORY_ITEM_URL] );//将category_item_url加入任务池中 2014.12.20 22:32
                            $this->mongodb->update($collection_item_name, array('skuid'=>$item['skuid']),$item,array("upsert"=>1));
                        }
                    }else{
                        $errno++;
                        if($e>$totalpages || $errno>3){
                            $s += $totalpages;//当错误次数超过3次就此终止
                            $this->log->errlog ( array (
                                'job' => $job,
                                'url' => $rurl,
                                'urltype' =>'CategoryList',
                                'yy' => 'job error',
                                'addtime' => date ( 'Y-m-d H:i:s' )
                            ) );
                        }
                    }

                }

                $s = $s + $pagesize;
                if($tmpurls)
                {
                    foreach($tmpurls as $url)
                        $this->log->errlog ( array (
                            'job' => $job,
                            'url' => $url,
                            'urltype' =>'CategoryList',
                            'error' => 1,
                            'addtime' => date ( 'Y-m-d H:i:s' )
                        ) );
                }
                $sleep = rand(3,5);
                sleep($sleep);
            } while ( $s <= $totalpages );
        }
        $this->pools->deljob($name,$job);//加入删除备份任务机制
        $this->redis->decr ( $this->spidername . 'CategoryTotalCurrent' );
        $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,-1);
//		$this->autostartitemmaster ();
        exit ();
    }

    /**
     * updatejob
     */
    function updatejob() {
        $spiderconfig = Application::$_spider;
        $poolname = $this->spidername . 'Update';
        $collectionname = $this->spidername.'_category_list';
        $jobname = 'Update';
        $Category = $spiderconfig ['Category'];
        $updateconfig = isset ( $spiderconfig ['updatedata'] ) ? $spiderconfig ['updatedata'] : "";
        if (! $updateconfig) {
            exit ( $this->spidername . "'s updateconfig not find" );
        }
        $urls = $this->pools->get ( $poolname, $Category ['Category_Group_Size'] );
        $priceurls = $sourceurls = array ();
        $priceurls = $sourceurls = array ();
        $Productmodel = $this->spidername . 'ProductModel';
//        $urls = 'http://m.leju.com/touch/esf/bj/detail/126484182';
        $pages = $this->curlmulit->remote ( $urls, null, false, Application::$_spider ['item_page_charset'] );
        if ($pages) {
            foreach ( $pages as $srouceurl => $page ) {

                $spidermodel = new $Productmodel ( $this->spidername, $srouceurl, $page, Application::$_spider );
                $spiderdata = $spidermodel->exportToArray ();
                $url = $spiderdata['AgentUrl'];
                if($url)
                {
                    $urlarr=parse_url($url);
                    parse_str($urlarr['query'],$parr);
                    $agentid = $parr['agentid'];
                    $houseid = $spiderdata['skuid'];
                    $agentname = $spiderdata['AgentName'];
                    $size = $spiderdata['Size'];
                    $company = $spiderdata['Company'];
                    $d = $this->mongodb->findOne('lejubroker_Items',array('skuid'=>'-'.$agentid));
                    if($d)
                    {
//                        print_r($d);
                    }else{
                        $d = $this->mongodb->findOne('lejubroker_Items',array('skuid'=>$parr['city'].'-'.$agentid));
                    }
                    if($company && $d){
                        $this->mongodb->update('lejubroker_Items',array('_id'=>$d['_id']),array('$set'=>array('title'=>$company,'skuid'=>$parr['city'].'-'.$agentid)));
                    }
                    $updatedata = array('$set'=>array('agentid'=>$agentid,'agentname'=>$agentname,'size'=>$size,'Company'=>$company));
                    if($houseid && $agentname && $updatedata){
                        $this->mongodb->update ( $collectionname, array ('houseid' => $houseid), $updatedata);
                        $this->pools->deljob($poolname,$srouceurl);//加入删除备份任务机制
                    }else{
                        $this->pools->delerrjob($this->spidername,$jobname,$poolname,$srouceurl,"data bu quan");
                    }
                }else{
                    $this->pools->delerrjob($this->spidername,$jobname,$poolname,$srouceurl,"no url");
                }
            }
        }
        $this->redis->decr ( $this->spidername . 'UpdateTotalCurrent' );
        $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,-1);
        $sleep = rand(3,5);
        sleep($sleep);
        exit ();
    }
    public function tojson()
    {
        $this->updateCity();
    }

    function updateCity()
    {
        $data = $this->mongodb->find('leju_area',array());
        $collection = $cname = 'lejuzf_Items';
        $citys = array();
        foreach($data as $k=>$v)
        {
            $url = $v['cid'];
            $p = '/http:\/\/(\w+).esf/';
            preg_match($p,$url,$out);
            $host = $out[1];
            $citys[$host] = $v['name'];
        }

        $i=0;
        $total = $this->mongodb->count($collection);
        $s = 0;
        $limit = 1000;
        $nocity = array();
        $_id = '';
        do {
            $find = array();
            if($_id)
                $find = array ("_id"=>array('$gt'=>$_id));
            $mondata = $this->mongodb->find ( $collection, $find , array (
                "limit" => $limit,
                "sort"=>array("_id"=>1)
            ) );
            foreach($mondata as $v)
            {
//                if(empty($v['agentid']) || !isset($v['agentid']))
//                {
                    $url = $v['Category_Source_Url'];
                    $p = '/shop\/(\d+)/';
                    preg_match($p,$url,$out);
                    $agentid = $out[1];
//                    echo $agentid."\n";
//                    $urlarr = parse_url($url);
//                    parse_str($urlarr['query'],$parr);
//                    $host = $parr['city'];
//                    $cityname = $citys[$host];
                    if(empty($agentid))
                    {
//                        if(!$nocity[$host])
//                        {
//                            $nocity[$host] = $v;
//                        }
                        print_r($v);
                    }
                    if($agentid)
                        $this->mongodb->update($collection,array("_id"=>$v['_id']),array('$set'=>array("agentid"=>$agentid)));
//                }
                $_id = $v['_id'];
            }
            $s +=$limit;
            echo "has load:".$s."\n";
        }while($s<$total);
        print_r($nocity);
    }
}