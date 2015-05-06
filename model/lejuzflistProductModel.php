<?php
/**
 * Created by PhpStorm.
 * User: Dream 
 * Date: 14-5-4
 * Time: 下午5:39
 */

//namespace model;


/**
 * product model for jin dong website.
 *
 * @package model
 */
class lejuzflistProductModel extends productXModel {

    public  function  getProductID()
    {
        $str = parent::getUrl();
        $p = '/(\d+)/';
        preg_match($p,$str,$out);
        $this->_productID = isset($out[1])?$out[1]:"";
        return $this->_productID;
    }

    public function getItemCommon()
    {
        $data = parent::getItemCommon();
        $this->_itemcommon = array();
        $sp = "/data='(.*)'><\/a>/";
        preg_match($sp,$this->_content,$out);
        $d = json_decode($out[1],true);
        $data['Size'] = $d['area'];
        $this->_itemcommon = $data;
        return $this->_itemcommon;
    }

    public function getPromotion()
    {
        $str = parent::getPromotion();
        $p = '/基本情况：<\/span>(.*)<\/div>/';
        preg_match($p,$this->_content,$out);
        $this->_promotion = isset($out[1])?$out[1]:"";
        $this->_promotion = $this->_promotion." ".$str;
        return $this->_promotion;
    }

    public  function getCharacters()
    {
        $p = '/(付：(.*)押：(.*))<\/div>/';
        preg_match($p,$this->_content,$out);
        if(isset($out[1]) && $out[1])
        {
            $str = str_replace(array(")","押","："),array(""," 押",":"),$out[1]);
            $this->_characters = $str;
            return $this->_characters;
        }
        return "";
    }
}