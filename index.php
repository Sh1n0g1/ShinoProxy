<?php
	$proxy=new ProxyHandler('http://shinobot.com/index.php');
	if($proxy->execute()){
	}else{
		echo $proxy->getCurlError();
	}
	$proxy->close();

//Copyright (c) 2010, 2011 Christian "chricke" Beckmann < mail@christian-beckmann.net >.
//This class is imported and modified from php5rp_ng
//https://github.com/chricke/php5rp_ng/

class ProxyHandler{
    const RN = "\r\n";
    private $_cacheControl = false;
    private $_chunked = false;
    private $_clientHeaders = array();
    private $_curlHandle;
    private $_pragma = false;
	function __construct($options){
        if (is_string($options)) {
            $options = array('proxyUri' => $options);
        }
        $translatedUri = rtrim($options['proxyUri'], '/');
        $baseUri = '';
        if (isset($options['baseUri'])) {
            $baseUri = $options['baseUri'];
        }
        elseif (!empty($_SERVER['REDIRECT_URL'])) {
            $baseUri = dirname($_SERVER['REDIRECT_URL']);
        }

        $requestUri = '';
        if (isset($options['requestUri'])) {
            $requestUri = $options['requestUri'];
        }else {
            if (!empty($_SERVER['REQUEST_URI'])) {
                $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            }
            if (!empty($_SERVER['QUERY_STRING'])) {
                $requestUri .= '?' . $_SERVER['QUERY_STRING'];
            }
        }

        if (!empty($requestUri)) {
            if (!empty($baseUri)) {
                $baseUriLength = strlen($baseUri);
                if (substr($requestUri, 0, $baseUriLength) === $baseUri) {
                    $requestUri = substr($requestUri, $baseUriLength);
                }
            }
            $translatedUri .= $requestUri;
        }else {
            $translatedUri .= '/';
        }
        $this->_curlHandle = curl_init($translatedUri);
        $this->setCurlOption(CURLOPT_FOLLOWLOCATION, true);
        $this->setCurlOption(CURLOPT_RETURNTRANSFER, true);
        $this->setCurlOption(CURLOPT_BINARYTRANSFER, true);
        $this->setCurlOption(CURLOPT_WRITEFUNCTION, array($this, 'readResponse'));
        $this->setCurlOption(CURLOPT_HEADERFUNCTION, array($this, 'readHeaders'));
        $requestMethod = '';
        if (isset($options['requestMethod'])) {
            $requestMethod = $options['requestMethod'];
        }elseif (!empty($_SERVER['REQUEST_METHOD'])) {
            $requestMethod = $_SERVER['REQUEST_METHOD'];
        }
        if ($requestMethod !== 'GET') {
            $this->setCurlOption(CURLOPT_CUSTOMREQUEST, $requestMethod);

            $inputStream = isset($options['inputStream']) ? $options['inputStream'] : 'php://input';

            switch($requestMethod) {
                case 'POST':
                    $data = '';
                    if (isset($options['data'])) {
                        $data = $options['data'];
                    }
                    else {
                        if (!isset($HTTP_RAW_POST_DATA)) {
                            $HTTP_RAW_POST_DATA = file_get_contents($inputStream);
                        }
                        $data = $HTTP_RAW_POST_DATA;
                    }
                    $this->setCurlOption(CURLOPT_POSTFIELDS, $data);
                    break;
            }
        }
        $this->handleClientHeaders();
    }
    private function _getRequestHeaders()
    {
        if (function_exists('apache_request_headers')) {
            if ($headers = apache_request_headers()) {
                return $headers;
            }
        }
        $headers = array();
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_' && !empty($value)) {
                $headerName = strtolower(substr($key, 5, strlen($key)));
                $headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', $headerName)));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    private function _removeHeader($headerName)
    {
        if (function_exists('header_remove')) {
            header_remove($headerName);
        } else {
            header($headerName . ': ');
        }
    }

    protected function handleClientHeaders()
    {
        $headers = $this->_getRequestHeaders();
        $xForwardedFor = array();

        foreach ($headers as $headerName => $value) {
            switch($headerName) {
                case 'Host':
                case 'X-Real-IP':
                    break;
                    
                case 'X-Forwarded-For':
                    $xForwardedFor[] = $value;
                    break;
                    
                default:
                    $this->setClientHeader($headerName, $value);
                    break;
            }
        }
        $xForwardedFor[] = $_SERVER['REMOTE_ADDR'];
        $this->setClientHeader('X-Forwarded-For', implode(',', $xForwardedFor));
		//This is the only modification of the original proxy handler.
		//Add the X-Shino-Proxy header.
		$this->setClientHeader('X-Shino-Proxy',$_SERVER['REMOTE_ADDR']);
    }

    protected function readHeaders(&$cu, $header){
        $length = strlen($header);

        if (preg_match(',^Cache-Control:,', $header)) {
            $this->_cacheControl = true;
        }
        elseif (preg_match(',^Pragma:,', $header)) {
            $this->_pragma = true;
        }
        elseif (preg_match(',^Transfer-Encoding:,', $header)) {
            $this->_chunked = strpos($header, 'chunked') !== false;
        }

        if ($header !== self::RN) {
            header(rtrim($header));
        }

        return $length;
    }

    protected function readResponse(&$cu, $body){
        static $headersParsed = false;
        if ($headersParsed === false) {
            if (!$this->_cacheControl) {
                $this->_removeHeader('Cache-Control');
            }
            if (!$this->_pragma) {
                $this->_removeHeader('Pragma');
            }
            $headersParsed = true;
        }

        $length = strlen($body);
        if ($this->_chunked) {
            echo dechex($length) . self::RN . $body . self::RN;
        } else {
            echo $body;
        }
        return $length;
    }

    public function close(){
        if ($this->_chunked) {
            echo '0' . self::RN . self::RN;
        }
        curl_close($this->_curlHandle);
    }
    public function execute()
    {
        $this->setCurlOption(CURLOPT_HTTPHEADER, $this->_clientHeaders);
        return curl_exec($this->_curlHandle) !== false;
    }

    public function getCurlError()
    {
        return curl_error($this->_curlHandle);
    }

    public function getCurlInfo()
    {
        return curl_getinfo($this->_curlHandle);
    }

    public function setClientHeader($headerName, $value)
    {
        $this->_clientHeaders[] = $headerName . ': ' . $value;
    }

    public function setCurlOption($option, $value)
    {
        curl_setopt($this->_curlHandle, $option, $value);
    }
}
