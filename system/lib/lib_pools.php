<?php
/**
 * 数据池
 */
class pools{
	/**
	 * 
	 */
	protected $redis;
    protected $mongodb;
	public function init($redis,$mongodb)
	{
		$this->redis = $redis;
        $this->mongodb = $mongodb;
	}
	public function set($k,$v)
	{
		$this->redis->sadd($k,$v);
        $this->addjob($k,$v);
	}
	public function del($k)
	{
		$this->redis->delete($k);
	}
	public function size($k)
	{
		return $this->redis->scard($k);
	}
	public function get($key,$num=null)
	{
		$lists = array();
		if($num>1)
		{
			for($i=0;$i<$num;$i++)
			{
				$value = $this->redis->spop($key);
				$lists[$value] = $value;
			}
			return $lists;
		}else{

            $value = $this->redis->spop($key);
            $lists[$value] = $value;
            return $lists;

		}
	}

    /**
     * 新增任务完成监控
     * 加入任务，重复的则增加单个任务重复的次数
     */
    public function addjob($spidername,$jobname,$jobnum=1)
    {
        $spidername .='Bak';
        //判断是否已经添加进去了
        $f = $this->redis->hexists($spidername,$jobname);
        if($f)
            $this->redis->hincrby($spidername,$jobname,$jobnum);
        else
            $this->redis->hset($spidername,$jobname,$jobnum);
    }

    /**
     * 新增任务完成监控
     * 加入任务，重复的则增加单个任务重复的次数
     */
    public function deljob($spidername,$jobname)
    {
        $spidername .='Bak';
        //判断是否已经添加进去了
        $delarr = array();
        $delarr[] = $jobname;
        $this->redis->hdel($spidername,$delarr);
    }

    /**
     * 获取未正常完成的任务，并记录次数,每获取一次加1
     */
    public function getUnfinished($spidername,$jobname)
    {
        $rkey = $spidername.$jobname.'Bak';
        $jobs = $this->redis->hkeys($rkey);
        foreach($jobs as $job)
        {
            $this->redis->hincrby($rkey,$job,1);
        }
        return $jobs;
    }

    /**
     * 错误中删除被占用的任务数
     */

    public function delerrjob($spidername,$jobname,$job,$url,$msg)
    {
        $this->redis->decr ( $spidername . 'CategoryTotalCurrent' );
        $this->redis->hincrby ( $spidername . $jobname . 'Current',HOSTNAME,-1);
        $logs = array (
            'job' => $job,
            'Categoryurl' => $url,
            'yy' => $msg,
            'addtime' => date ( 'Y-m-d H:i:s' ),
            'hostname' => HOSTNAME
        );
        $this->mongodb->insert($spidername.'_err_log',$logs);
        exit ();
    }
}