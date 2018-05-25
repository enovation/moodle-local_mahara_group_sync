<?php

class RestWebServiceClient {

    public $textArr = array();
    public $valueArr = array();
    private $domainName = '';
    private $pathToServer = '';
    private $pathToServerNotRest = '';
    private $tokenNameNotFromMoodle = '';
    private $token = '';

    public function getDomain() {
        return $this->domainName;
    }

    public function addArg($value){
        array_push( $this->textArr , $value);
    }

    public function addValue($value){
        array_push( $this->valueArr , $value);
    }

    public function setDomainName($name){
        $this->domainName = $name;
    }

    public function setPathToServerNotRest($path){
        $this->pathToServer = $path;
    }

    public function setToken($token){
        $this->token = $token;
    }

    public function setTokenNameNotFromMoodle($token){
        $this->tokenNameNotFromMoodle= $token;
    }

    public function getUrl(){
        $serverurl = $this->domainName;
        $serverurl.= !empty($this->pathToServerNotRest)
            ? $this->pathToServerNotRest
            : '/webservice/rest/server.php';
        $serverurl.= !empty($this->tokenNameNotFromMoodle)
            ? $this->tokenNameNotFromMoodle
            : '?wstoken=';
        $serverurl.= $this->token;

        $i=0;
        foreach($this->valueArr as $value){
            $serverurl .= '&' . $this->textArr[$i] . '=' . $value;
            $i++;
        }

        return $serverurl;
    }
};