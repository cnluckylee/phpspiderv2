<?php
/**
 *
 * @copyright   Copyright(c) 2014
 * @author      cnlucky_lee <cnlucky_lee@gmail.com>
 * @version     1.0
 */
class spiderModel extends Model {
	protected $itemjob = false;
	
	/**
	 * 获取分类数据
	 */
	function getCategory() {
        header("Content-type: text/html; charset=utf-8");
		$Category = Application::$_spider ['Category'];
		$thistimerun = isset ( $Category ['Category_Run'] ) ? $Category ['Category_Run'] : 1;
		$collection_category_name = Application::$_spider [elements::COLLECTION_CATEGORY_NAME];
		$poolname = $this->spidername . 'Category';
        $sid = Application::$_spider ['stid'];
		// 清理Category现场
		$this->pools->del ( $poolname );
        $this->redis->delete ( $this->spidername . 'CategoryTotalCurrent' );
		$this->redis->delete ( $this->spidername . 'CategoryCurrent' );
		$this->redis->delete ( $this->spidername . 'ItemCurrent' );
		$this->redis->delete ( $this->spidername . 'Item' );
		$this->redis->delete ( $this->spidername . 'ItemJobRun' );
		// 判断本次是否重新抓取分类数据

		if ($thistimerun) {
			$Category_URL = $Category[elements::CATEGORY_URL];
//			$page = file_get_contents ( $Category_URL );
            $tmp = $this->curlmulit->remote( $Category_URL , null, false, Application::$_spider[elements::CHARSET],Application::$_spider [ elements::ITEMPAGECHARSET]);

            $page = $tmp[$Category_URL];
            $preg = $Category [elements::CATEGORY_MATCH_PREG];
            $matchnum = $Category [elements::CATEGORY_MATCH_MATCH];
            $Categorytmp = array();
//            if(strtolower($Category[elements::CATEGORY_MATCHING]) == 'xpath')
//            {
//                $dom = new DOMDocument();
//                @$dom->loadHTML($page);
//                $xpath = new DOMXPath($dom);
//                $result = $xpath->query($preg);
//                for($i = 0; $i < ($result->length); $i++) {
//                    $event = $result->item($i);
//                    if(in_array('text',$matchnum))
//                    {
//                        $cid = $event->nodeValue;
//                    }
//                    foreach($event->attributes as $k=>$v)
//                    {
//                        if(in_array($v->name,$matchnum) && $cid){
//                            $Categorytmp[$v->nodeValue] = $cid;
//                        }
//                    }
//
//                    if((isset($Categorytmp[$i]['cid']) && !$Categorytmp[$i]['cid']) || !isset($Categorytmp[$i]['cid']))
//                         unset($Categorytmp[$i]);
//                }
//                $Categorylist = $Categorytmp;
//            }else{
                preg_match_all ( $preg, $page, $match );
                if (is_array ( $matchnum )) {
                    $name = $matchnum ['name'];
                    $cid = $matchnum ['cid'];
                    $Categorytmp = array_combine ( $match [$name], $match [$cid] );
                } else {
                    $Categorytmp = $match [$matchnum];
                }
                $Categorylist = array_unique ( $Categorytmp );
//            }
            $mondata = array ();
            foreach ( $Categorylist as $name => $cid ) {
                $this->pools->set ( $poolname, $cid );
                $mondata [] = array (
                    'name' => $name,
                    'cid' => $cid,
                    'sid' => $sid
                );
            }
			/**
			 * 写入mongodb category集合
			 */
			$this->mongodb->remove ( $collection_category_name, array () ); // 删除原始数据，保存最新的数据
            if($mondata)
			    $this->mongodb->batchinsert ( $collection_category_name, $mondata );
			unset($mondata);
		} else {
			$Categorylist = $this->mongodb->find ( $collection_category_name, array () );
			foreach ( $Categorylist as $obj ) {
				$cid = $obj ['cid'];
				$this->pools->set ( $poolname, $cid );
			}
		}
		echo "共收集到" . count ( $Categorylist ) . "个分类\n";
		unset($Categorylist);
        exit;
	}
	/**
	 * 分类列表任务调度
	 *
	 * @return Ambigous <number, unknown>
	 */
	function master($jobname = 'Category') {
		$name = $this->spidername . $jobname;
		$totalvalue = 0;
		do {
			$totalvalue = $this->pools->size ( $name );
			$totaljobs = $this->redis->get ( $this->spidername . $jobname . 'TotalCurrent' ); // 当前运行总数
            $jobs = $this->redis->hget ( $this->spidername . $jobname . 'Current' ,HOSTNAME);//获取以jobname为key下面该hostname机子的当前运行数
			echo $this->spidername." Jobname:" . $jobname . "  TotalJobs:" . $totalvalue . " TotalRunJobs:".$totaljobs . " LocalRunJobs:" . $jobs . " LocalMaxjobs:" . $this->maxjobs ."\n";
			if ($totalvalue > 0) {
				$runs = $this->maxjobs;
				// 刚起步程序
				if (! $this->redis->hexists ( $this->spidername . $jobname . 'Current' ,HOSTNAME )) {
					if ($totalvalue < $this->maxjobs)
						$runs = $totalvalue;
					$cmd = "./startworker " . $this->spidername . '  ' . $jobname . "job " . $runs;
					$this->redis->incr ( $this->spidername . $jobname . 'TotalCurrent', $runs );//当前运行总数有增加中
                    $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,$runs);
					$out = popen ( $cmd, "r" );
					pclose ( $out );
				} else if ($jobs >= $this->maxjobs) 				// 当前运行数大于最大运行数 自动暂停3秒
				{
					sleep ( 1 );
				} else if ($jobs > 0 && $jobs < $this->maxjobs) 				// 当前运行数不足最大运行数 加入新的任务
				{
					if ($totalvalue < $this->maxjobs)
						$runs = $totalvalue;
					else
						$runs = $this->maxjobs - $jobs;
					$cmd = "./startworker " . $this->spidername . '  ' . $jobname . "job " . $runs; // $cmd = "./startworker " . $spidername . " categoryjob " . $runs
					echo "cmd:" . $cmd . "\n";
					$out = popen ( $cmd, "r" );
					pclose ( $out );
					$this->redis->incr ( $this->spidername . $jobname . 'TotalCurrent', $runs );//当前运行总数有增加中
                    $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,$runs);
				} else if ($jobs <= 0) {
					$runs = $this->maxjobs;
					if ($totalvalue < $this->maxjobs)
						$runs = $totalvalue;
					$cmd = "./startworker " . $this->spidername . '  ' . $jobname . "job " . $runs;
					$this->redis->incr ( $this->spidername . $jobname . 'TotalCurrent', $runs );//当前运行总数有增加中
                    $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,$runs);
					$out = popen ( $cmd, "r" );
					pclose ( $out );
				}

			} else {
                $this->redis->delete ( $this->spidername . 'CategoryCurrent' );
                $this->redis->delete ( $this->spidername . 'CategoryTotalCurrent' );
				$this->spiderrun = false;
				if ($jobname == 'Category') {
					$this->autostartitemmaster ();
					exit ( "Category stacks over\n" );
				} else {
					// 商品跑完了，等于全部跑完了,收拾战场
					$this->redis->delete ( $this->spidername . 'ItemJobRun' );
				}
			}
		} while ( $this->spiderrun );
        $this->redis->delete ( $this->spidername . 'CategoryTotalCurrent' );
        $this->redis->delete ( $this->spidername . 'CategoryCurrent' );
		$this->redis->delete ( $this->spidername . 'ItemCurrent' );
		$this->redis->delete ( $this->spidername . 'Item' );
		$this->redis->delete ( $this->spidername . 'ItemJobRun' );
		$this->mongodb->remove($this->spidername.'_err_log');
		$this->mongodb->remove($this->spidername.'_warning_log');
		$this->mongodb->remove($this->spidername.'_msg_log');
		exit ( "stack all over\n" );
	}
	function CategroyJob() {

        header("Content-type: text/html; charset=utf-8");
        $name = $this->spidername . 'Category';
        $jobname = 'Category';
		$spidername = str_replace ( 'Spider', "", $this->spidername );
        $errno = 0;//记录categoryjob抓取不到数据的次数，超过3次后放弃该任务

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

		// 首先获取下该分类下面的总页数
        if(isset(Application::$_spider [elements::CATEGORYPAGECHARSET]))
            $CatgoryPageCharset = Application::$_spider [elements::CATEGORYPAGECHARSET];
        else
            $CatgoryPageCharset = Application::$_spider [elements::ITEMPAGECHARSET];

		$pageHtml = $this->curlmulit->remote ( $Categoryurl,null,false,$CatgoryPageCharset,Application::$_spider [elements::HTML_ZIP]);
        if (! $pageHtml) {
//			$this->autostartitemmaster ();
			$this->redis->decr ( $this->spidername . 'CategoryTotalCurrent' );
            $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,-1);
			$this->log->errlog ( array (
					'job' => $job,
					'Categoryurl' => $Categoryurl,
					'error' => 2,
					'addtime' => date ( 'Y-m-d H:i:s' ),
                    'hostname' => HOSTNAME
			) );
			exit ();
		}
        //获取分类列表页总页数，如果获取不到则自动停止，并做好相应记录
        $totalpages = $this->getcategorylisttotalpages($Category,$xpath,$pageHtml,$Categoryurl,$job,$jobname);
//        echo $totalpages."\n";
		$s = isset ( $Category [elements::CATEGORY_PAGE_START] ) ? $Category[elements::CATEGORY_PAGE_START] : 0;
		$pagesize = $Category [elements::CATEGORY_GROUP_SIZE];
        $urlarr=parse_url($Categoryurl);
        parse_str($urlarr['query'],$parr);
        $sourceCityName = $parr['SourceCityName'];
        $sourceProvince = $parr['SourceProvince'];
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
                    if(isset($Category [elements::TRANSFORM]) && $Category [elements::TRANSFORM] == false)
                    {
                        if(isset($Category [elements::CATEGORY_NO_ADD_PAGE]) && $Category [elements::CATEGORY_NO_ADD_PAGE])
                            $url =$job.$Category [elements::TRANSFORMADDSPECIL];
                        else
                            $url =$job.$i.$Category [elements::TRANSFORMADDSPECIL];
                    }else{
                        if($Category [elements::CATEGORY_LIST_URL]){
                            $url = $Category [elements::CATEGORY_LIST_PAGES_URL];
                            $url = str_replace ( '#job', $job, $url );
                            $url = str_replace ( '#i', $i, $url );
                        }else{
                            $url = str_replace ( '#i', $i, $job );
                        }
                    }
                    $tmpurls [$url] = $url;
				}
//print_r($tmpurls);

                $pages = $this->curlmulit->remote ( $tmpurls, null, false ,$CatgoryPageCharset,Application::$_spider [elements::HTML_ZIP]);
                /**
				 * 能否抓去到数据检测,此代码保留
				 */
				if ($s == 0 && count ( $pages ) == 0) {
					$this->master ( 'Item' );
					$this->redis->decr ( $this->spidername . 'CategoryTotalCurrent' );
                    $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,-1);
					$this->log->errlog ( array (
							'job' => $job,
							'Categoryurl' => $Categoryurl,
                            'yy' => 'no list',
							'addtime' => date ( 'Y-m-d H:i:s' ),
                            'hostname' => HOSTNAME
					) );
					exit ();
				}
				$preg = $Category [elements::CATEGORY_LIST_GOODS_PREG];
				$match = $Category [elements::CATEGORY_LIST_GOODS_Match];
                $baseurl = '';

				foreach ( $pages as $rurl => $page ) {

                    if(strtolower($Category[elements::CATEGORY_ITEM_PREG][elements::CATEGORY_ITEM_MATCHING]) == 'xpath')
                    {
                        $item_urls = $this->curlmulit->getRegexpInfo2($preg,$page);
                    }else{
                        preg_match_all ( $preg, $page, $match_out );
                        $item_urls = isset ( $match_out [$match] ) ? $match_out [$match] : "";
                    }
                    $item_urls = array_unique ( $item_urls );
                    if(!$page)
                    print_r($tmpurls);
                    //加入错误日志
                    unset($tmpurls[$rurl]);
                    //加入列表页数据的获取并保存
                    if(isset($Category [elements::CATEGORY_ITEM_PREG]))
                    {
                        $Productmodel = $this->spidername . 'ProductModel';
                        $spidermodel = new $Productmodel ( $this->spidername, $rurl, $page, $Category [elements::CATEGORY_ITEM_PREG] );
                        $categorydata = $spidermodel->CategoryToArray ( );
                        if(isset($_GET['debug']) && $_GET['debug']=='categoryjob')
                        {
                            print_r($categorydata);
                            exit;
                        }
//print_r($rurl);
//print_r($page);
//print_r($categorydata);exit;
                        if($categorydata){
                            foreach($categorydata as $item)
                            {
                                $item['Category_Source_Url'] = $rurl;
                                $item['job'] = $job;
                                if($sourceCityName)
                                    $item['SourceCityName'] = $sourceCityName;
                                if($sourceProvince)
                                    $item['SourceProvince'] = $sourceProvince;
                                //排除掉不需要做itemjob
                                if(!isset(Application::$_spider[elements::NOITEMJOB]) && $item[\elements::CATEGORY_ITEM_URL])
                                     $this->pools->set ( $poolname, $item[\elements::CATEGORY_ITEM_URL] );//将category_item_url加入任务池中 2014.12.20 22:32
                                $this->mongodb->update($this->spidername.'_category_list',array(elements::CATEGORY_ITEM_URL=>$item[\elements::CATEGORY_ITEM_URL]),array('$set'=>$item),array("upsert"=>1));
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
                                    'addtime' => date ( 'Y-m-d H:i:s' ),
                                    'hostname' => HOSTNAME
                                ) );
                            }
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
                        'addtime' => date ( 'Y-m-d H:i:s' ),
                        'hostname' => HOSTNAME
                    ) );
                }
                $sleep = rand(1,3);
                sleep($sleep);
			} while ( $s <= $totalpages );
		}
        $this->pools->deljob($name,$job);//加入删除备份任务机制
		$this->redis->decr ( $this->spidername . 'CategoryTotalCurrent' );
        $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,-1);
		$this->autostartitemmaster ();
		exit ();
	}

    /**
     * 获取分类列表页页面总数
     * @param $Category
     * @param $xpath
     * @param $pageHtml
     * @param $Categoryurl
     * @return int|null|string
     */
    function getcategorylisttotalpages($Category,$xpath,$pageHtml,$Categoryurl,$job,$jobname)
    {
        if(isset(Application::$_spider [elements::TOTALPAGES])&&Application::$_spider [elements::TOTALPAGES]>0)
            $totalpages = Application::$_spider [elements::TOTALPAGES];
        else{
            $preg_pagetotals = $Category [elements::CATEGORY_LIST_PREG];
            if(strtolower($xpath) == 'xpath')
            {
                $totalpages = $this->curlmulit->getRegexpInfo($preg_pagetotals,$pageHtml [$Categoryurl],$Category [elements::CATEGORY_LIST_MATCH]);
            }else{
                preg_match ( $preg_pagetotals, $pageHtml [$Categoryurl], $match_pagetotals );
                foreach($match_pagetotals as $k=>$v)
                {
                    $match_pagetotals[$k] = trim($v);
                }
                $totalpages = $match_pagetotals ? $match_pagetotals [$Category [elements::CATEGORY_LIST_MATCH]] : 0;
            }
        }
        if(!$totalpages){
            $this->log->errlog ( array (
                'job' => $job,
                'Categoryurl' => $Categoryurl,
                'error' => 2,
                'yy' =>'no total and have page',
                'addtime' => date ( 'Y-m-d H:i:s' ),
                'hostname' => HOSTNAME
            ) );
            $sleep = rand(3,5);
            sleep($sleep);
            $this->redis->decr ( $this->spidername . 'CategoryTotalCurrent' );
            $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,-1);
            exit;
        }else{
            $collection_category_name = Application::$_spider [elements::COLLECTION_CATEGORY_NAME];
            $this->mongodb->update ( $collection_category_name,
                array ('cid' => $job),
                array('$set'=>array('totalcount'=>$totalpages)),
                array("upsert"=>1,"multiple"=>true));
        }

        return $totalpages;
    }

    //master 控制器
	function autostartitemmaster($jobname = 'Item') {
		if (! $this->redis->exists ( $this->spidername . $jobname . 'JobRun' )) {
			$this->redis->set ( $this->spidername . $jobname . 'JobRun', 1 );
			$ljobname = lcfirst ( $jobname );
			exec ( "php -f index.php " . $this->spidername . " " . $ljobname . "master " . $this->maxjobs );
		}
	}
	function itemjob() {
        header("Content-type: text/html; charset=utf-8");
		$poolname = $this->spidername . 'Item';
        $jobname = 'Item';
		$Category = Application::$_spider ['Category'];
		$collection_item_name = Application::$_spider [elements::COLLECTION_ITEM_NAME];
        $urls = array();
//$_GET['url'] = 'http://project.leju.com/house.php?city=sh&hid=104367&aid=192325';
		if(isset($_GET['debug']) && $_GET['debug']=='itemjob')
		{
            $gurl = isset($_GET['url'])?trim($_GET['url']):"";
            $urls[$gurl] = $gurl;
		}else
			$urls = $this->pools->get ( $poolname, $Category [elements::CATEGORY_GROUP_SIZE] );

        $site_conversion_rules = $this->getsite_conversion_rules();
        $url_rules = $site_conversion_rules[Application::$_spider[elements::STID]]?$site_conversion_rules[Application::$_spider[elements::STID]]:"";
        if($url_rules)
        {
            foreach($urls as $k=>$turl){
                $newurl = preg_replace ( '/#skuid/', $turl, $url_rules ['pcurl'] );
                $urls[$newurl] = $newurl;
            }
        }

		$pages = $this->curlmulit->remote ( $urls, null, false, Application::$_spider [ elements::ITEMPAGECHARSET],Application::$_spider [elements::HTML_ZIP]);
// 		$fetchitems = array ();
        $tmpurls = $urls;
		$Productmodel = $this->spidername . 'ProductModel';

		foreach ( $pages as $srouceurl => $page ) {
            if(empty($page))
            {
                $this->pools->delerrjob($this->spidername,$jobname,$poolname,$srouceurl,"item no page");
            }
			$spidermodel = new $Productmodel ( $this->spidername, $srouceurl, $page, Application::$_spider );
			$spiderdata = $spidermodel->exportToArray ();

//			if($spiderdata['title'])
//			{
// 				$fetchitems [] = $spiderdata;
                //评论数据单独放一个集合里面
                if($spiderdata[elements::ITEM_COMMENTS] && count($spiderdata[elements::ITEM_COMMENTS])>0){
                    $this->mongodb->batchinsert($this->spidername.'_comments',$spiderdata[elements::ITEM_COMMENTS]);
                    unset($spiderdata[elements::ITEM_COMMENTS]);
                }

				$this->mongodb->update($collection_item_name, array('skuid'=>$spiderdata['skuid'],'stid'=>$spiderdata['stid']),array('$set'=>$spiderdata),array("upsert"=>1));
//			}

			if(isset($_GET['debug']) && $_GET['debug']=='itemjob')
			{
				print_r($spiderdata);exit;
			}
            $this->pools->deljob($poolname,$srouceurl);//加入删除备份任务机制
            unset($tmpurls[$srouceurl]);
		}
        if($tmpurls)
        {
            foreach($tmpurls as $k=>$url)
                $this->log->errlog ( array (
                    'job' => $poolname,
                    'url' => $k,
                    'urltype' =>'Items',
                    'error' => 1,
                    'addtime' => date ( 'Y-m-d H:i:s' ),
                    'hostname' => HOSTNAME
                ) );
        }
        $this->redis->decr ( $this->spidername . 'ItemTotalCurrent' );
        $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,-1);
        sleep(1);
		exit ();
	}
	/**
	 * 全量更新
	 */
	function updatefull() {
		$spiderconfig = Application::$_spider;
		$updateconfig = isset ( $spiderconfig ['updatedata'] ) ? $spiderconfig ['updatedata'] : "";
		if (! $updateconfig) {
			exit ( $this->spidername . "'s updateconfig not find" );
		}
		// 清理现场
		$this->redis->delete ( $this->spidername . 'UpdateJobRun' );
		$this->redis->delete ( $this->spidername . 'Update' );
		$this->redis->delete ( $this->spidername . 'UpdateCurrent' );
		/*
		 * 数据入池
		 */
		$collectionname = $this->spidername.'_category_list';
		$limit = 1000;
//        $regex = new MongoRegex("/.esf.nb.fang./");
		$totalitems = $this->mongodb->count ( $collectionname );
		$s = 0;
		$poolname = $this->spidername . 'Update';
        $_id = '';
        do {
            $find = array();
            if($_id)
                $find = array ("_id"=>array('$gt'=>$_id));
            $mondata = $this->mongodb->find ( $collectionname, $find , array (
                "limit" => $limit,
                "sort"=>array("_id"=>1)
            ) );
			foreach ( $mondata as $item ) {
                if($item['agentid'] && (!isset($item['Company']) || empty($item['Company'])))
                {
                    $sourceurl = $item['url'];
                    $urlarr=parse_url($sourceurl);
                    parse_str($urlarr['query'],$parr);
                    $id = $parr['id'];
                    $city = $parr['city'];
                    $msg = '乐居的童鞋,我很同情你们的处境,坑B的领导不行就赶紧撤吧cnluckylee';
                    $url = 'http://m.leju.com/touch/esf/'.$city.'/detail/'.$id.'#'.urlencode($msg);
                    // $arr = array('price_url'=>$item['price_url'],'source_url'=>$item['source_url'],'id'=>$item['_id']);
                    $this->pools->set ( $poolname, $url );
                }
                $_id = $item['_id'];
			}
			$s += $limit;
			echo 'has load '.$s."\n";
		} while ( $s <= $totalitems );
		$this->autostartitemmaster ( 'Update' );
		exit ('Update Stack over');
	}

	/**
	 * updatejob
	 */
	function updatejob() {
        $spiderconfig = Application::$_spider;
		$poolname = $this->spidername . 'Update';
		$collectionname = $spiderconfig[elements::COLLECTION_ITEM_NAME];
        $jobname = 'Update';
		$Category = $spiderconfig ['Category'];
		$updateconfig = isset ( $spiderconfig ['updatedata'] ) ? $spiderconfig ['updatedata'] : "";
		if (! $updateconfig) {
			exit ( $this->spidername . "'s updateconfig not find" );
		}
		$strs = $this->pools->get ( $poolname, $Category ['Category_Group_Size'] );
		$priceurls = $sourceurls = array ();
		$Productmodel = $this->spidername . 'ProductModel';
		foreach ( $strs as $str ) {
			$item = unserialize ( $str );
			if (in_array ( 'dprice', $updateconfig )) {
				// 更新价格
				$urls = $item ['price_url'];
			} else {
				$urls = $item ['source_url'];
			}
			$pages = $this->curlmulit->remote ( $urls, null, false, Application::$_spider ['item_page_charset'] );
			if ($pages) {
				foreach ( $pages as $srouceurl => $page ) {
					$spidermodel = new $Productmodel ( $this->spidername, $srouceurl, $page, Application::$_spider );
					$spiderdata = $spidermodel->exportToArray ( $updateconfig, $item );
					$this->mongodb->update ( $collectionname, array ('_id' => $item ['_id']), $spiderdata,array("upsert"=>1));
				}
			}
		}
		$this->redis->decr ( $this->spidername . 'UpdateTotalCurrent' );
        $this->redis->hincrby ( $this->spidername . $jobname . 'Current',HOSTNAME,-1);
		exit;
	}

    //获取url规则
    public function getsite_conversion_rules()
    {
        $site_conversion_rules = array();
        $site_conversion_rules_arr = $this->mongodb->find('site_conversion_rules',array());
        foreach($site_conversion_rules_arr as $i)
        {
            $site_conversion_rules[$i['stid']] = array('pcurl'=>$i['pcurl'],'wapurl'=>$i['wapurl']);
        }
        return $site_conversion_rules;
    }

    /**
     * 记录操作日志
     * status 1:默认开始记录 2：记录完成
     */
    public function runlog($status = 1,$action = null)
    {
        $log = $this->spidername.'log'.$action;
        switch ($status){
            case 1:
                if (! $this->redis->exists ($log))
                {
                    $logstr = date('Y-m-d H:i:s').'_'.$action;
                    $this->redis->set($log,$logstr);
                }
                break;
            case 2:
                if ($this->redis->exists ($log))
                {
                    $logstr = $this->redis->get($log);
                    $this->redis->delete ( $log );
                    echo $logstr."\n";
                    $logarr = explode("_", $logstr);
                    $logtime = $logarr[0];
                    $logaction = $logarr[1];
                    $obj = $this->mongodb->findOne('source',array('domain'=>$this->spidername),array('sid'));
                    if(isset($obj['sid']) && $obj['sid'])
                    {
                        $min = strtotime($logtime);
                        $stid = $obj['sid'];
                        $find =  array('stid'=>$stid,'create_time'=>array('$gte'=>(int)$min));
                        $logdata = array('start_date'=>$logtime,'end_date'=>date('Y-m-d H:i:s'),'spidername'=>$this->spidername,'stid'=>$stid,'action'=>$logaction);
                        $this->mongodb->insert('wcc_online_message',$logdata);

                    }
                    exit("log over!");
                }
                break;
        }

    }
    /**
     * api更新数据
     */
    public function apifun()
    {
        exit('this api interface');
    }

    /**
     * 判断本次是否运行
     */
    function getDBStatus($domain='yhd.com')
    {
        $sql = "select table_name from wcc_data_message where domain='".$domain."' and status=0 order by id desc";
        $table_name = $this->db->getOne($sql);
        $this->tablename = $table_name;
        return $table_name;
    }
    /**
     * 处理完更新status字段,删除废弃数据
     */
    function updateDBStatus($domain='yhd.com')
    {
        $where = "domain='".$domain."' and status=0  order by id desc limit 1";
        $this->db->update('wcc_data_message',array('status'=>1,'process_time'=>date('Y-m-d H:i:s')),$where);
    }

    /**
     * 删除废弃数据
     */
    function delDiscard($table_name='yihaodian_product')
    {
        $where = 'status=9';
        $this->db->delete($table_name,$where);
        $uparr = array('status'=>1);
        $where2 = '(status=2 or status=3)';
        $this->db->update($table_name,$uparr,$where2);
    }

    /**
     * 转json
     */
    function tojson($cname)
    {
        $total = $this->mongodb->count($cname);
        $s = 0;
        $limit = 1000;
        $filename = $cname.'.log';
        do {
            $mondata = $this->mongodb->find ( $cname, array (), array (
                "start" => $s,
                "limit" => $limit
            ) );
            $str = '';

            foreach($mondata as $item)
            {
                $str .= json_encode($item)."\n";
            }
            $file = fopen($filename,"a+");
            fwrite($file,$str);
            fclose($file);
            $s +=$limit;
        }while($s<$total);
    }

    /**
     * @param $jobname
     */

    function retry($jobname)
    {
        $this->redis->delete ( $this->spidername . $jobname.'TotalCurrent' );
        $this->redis->delete ( $this->spidername . $jobname.'Current' );
        $poolname = $this->spidername.$jobname;
        $data = $this->pools->getUnfinished($this->spidername,$jobname);
        foreach($data as $job)
        {
            $this->pools->set($poolname,$job);
            echo "load: ".$job."\n";
        }
    }


    /**
     * 加载抓取错误的job
     */
    function retryerr($jobname='CategoryList')
    {

        $poolname = $this->spidername . 'CategoryErr';
        $collectionname = $this->spidername.'_err_log';
        $s=0;
        $run = true;
        $limit = 1000;
        $find = array("urltype"=>$jobname);
        do{
            $mondata = $this->mongodb->find ( $collectionname, $find, array (
                "start" => $s,
                "limit" => $limit
            ) );

            if(count($mondata)<1)
                $run=false;

            foreach ( $mondata as $item ) {
                $str = $item['job'];
                $this->pools->set ( $poolname, $str );
            }
            $s += $limit;
            echo 'has load '.$s."\n";
        }while($run);
        exit("all errs load over");
    }

    function CategroyErrJob() {
        header("Content-type: text/html; charset=utf-8");
        $poolname = $this->spidername . 'CategoryErr';
        $Category = Application::$_spider ['Category'];
        $jobname = 'CategoryErrJob';
        $tmpurls = $urls = $this->pools->get ( $poolname, $Category [elements::CATEGORY_GROUP_SIZE] );
        $setpoolname  = $this->spidername . 'Item';
        $pages = $this->curlmulit->remote ( $tmpurls, null, false ,Application::$_spider [ elements::ITEMPAGECHARSET],Application::$_spider [elements::HTML_ZIP]);
        $preg = $Category [elements::CATEGORY_LIST_GOODS_PREG];
        $match = $Category [elements::CATEGORY_LIST_GOODS_Match];

        foreach ( $pages as $rurl => $page ) {

            if(strtolower($Category[elements::CATEGORY_ITEM_PREG][elements::CATEGORY_ITEM_MATCHING]) == 'xpath')
            {
                $item_urls = $this->curlmulit->getRegexpInfo2($preg,$page);
            }else{
                preg_match_all ( $preg, $page, $match_out );
                $item_urls = isset ( $match_out [$match] ) ? $match_out [$match] : "";
            }
            $item_urls = array_unique ( $item_urls );
            if(!$page)
                print_r($tmpurls);
            //加入错误日志
            unset($tmpurls[$rurl]);
            //加入列表页数据的获取并保存
            if(isset($Category [elements::CATEGORY_ITEM_PREG]))
            {
                $Productmodel = $this->spidername . 'ProductModel';
                $spidermodel = new $Productmodel ( $this->spidername, $rurl, $page, $Category [elements::CATEGORY_ITEM_PREG] );
                $categorydata = $spidermodel->CategoryToArray ( );

                if($categorydata){
                    foreach($categorydata as $item)
                    {
                        $item['Category_Source_Url'] = $rurl;
                        $item['job'] = $rurl;
                        if($item[\elements::CATEGORY_ITEM_URL])
                            $this->pools->set ( $setpoolname, $item[\elements::CATEGORY_ITEM_URL] );//将category_item_url加入任务池中 2014.12.20 22:32
                        $this->mongodb->insert($this->spidername.'_category_list',$item);
                    }
                    $this->pools->deljob($poolname,$rurl);//加入删除备份任务机制
                }
            }
        }
        if($tmpurls)
        {
            foreach($tmpurls as $url)
                $this->log->errlog ( array (
                    'job' => $rurl,
                    'url' => $url,
                    'urltype' =>'CategoryList',
                    'error' => 1,
                    'addtime' => date ( 'Y-m-d H:i:s' ),
                    'hostname' => HOSTNAME

                ) );
        }
        $sleep = rand(1,3);
        sleep($sleep);
        $this->redis->decr ( $poolname . 'TotalCurrent' );
        $this->redis->hincrby ( $poolname . 'Current',HOSTNAME,-1);
		$this->autostartitemmaster ();
        exit ();
    }
}
