<?php

/*
	More information on constructing requests in php can be found at:
	http://php.net/manual/en/context.http.php
*/

$phpversion = phpversion();
if (version_compare(phpversion(), '5.3.0', '<'))
{
	trigger_error("RestJSONClient Insufficient PHP Version, might not work, requires > 5.3.0, currently using $phpversion ::", E_USER_WARNING);
}

/*
	This class can send REST requests. The following arguments have setters & getters:
		$method				- The HTTP method type
		$url				- The endpoint.
		$headers			- Any optonal HTTP headers to include.
		#bodyjson			- Any variables to include. (not included in DELETE request)
		$files				- A file to uplood. (Only used in PUT request)
		
		$timeout			- number of seconds to try for a connection
		$protocol_version	- HTTP/1.0 or HTTP/1.1
	
	Example usage:

	$myclient = new RestClient($method,$url,$headers,$bodyjson,files);
	
	$myclient->set_all($method,$url,$headers,$bodyjson,$file); // or set individually with setter functions: set_PARAMETER($PARAMETER)

	$response = $myclient->send();
*/
class RestJSONClient {

	const VERSION = '0.1';

	/*** private attributes ***/

	private $bodyjson = array();
	private $files = null;
	private $filecount = 0;
	private $headers = array();
	private $method = "";
	
	private $request = "";
	private $response = "";
	
	private $timeout = 5;
	private $protocol_version = "1.0";

	private $scheme = "";
	private $user = "";
	private $pass = "";
	private $host = "";
	private $port = "";
	private $path = "";
	private $query = "";
	private $frag = "";
	
	private $MULTIPART_BOUNDARY = "";
	
	private static $MIMEtypes = null;
	
	/*** public helper functions ***/
	
	/*
		$data		- (array) converted to URI formatted query string
	*/
	public function format_query($data)
	{
		if(empty($data))
		{
			return "";
		}
		
		$query = "?";
		foreach($data as $key => $value)
		{
			$query .= urlencode($key) . "=" . urlencode($value);
		}
		
		return $query;
	}
	
	public function get_request()
	{
		$this->validate_requests();
		
		$req = $this->method . " " . $this->get_url() . " HTTP/" . $this->protocol_version . "\r\n";
		$req .= $this->format_headers($this->headers,$this->bodyjson,$this->filecount) . "\r\n";
		$req .= $this->format_body($this->bodyjson,$this->files,$this->filecount) . "\r\n\r\n";

		return $req;
	}
	
	/*** private helper functions ***/

	/*
		$headers	- (array) converted to HTTP formatted string
	*/
	private function format_headers($headers,$bodyjson,$filecount)
	{
		if($filecount === 0 && !empty($bodyjson))
		{
			return "Content-Type: application/json\r\n";
		}
		
		if(empty($headers))
		{
			return "";
		}
		
		$formatted_headers = "";
		foreach($headers as $header_name => $header_value)
		{
			/* determine whether to add a space or not on the header value */
			if($header_value[0] !== ' ')
			{
				$formatted_headers .= $header_name . ': ' . $header_value . "\r\n";
			}
			else
			{
				$formatted_headers .= $header_name . ':' . $header_value . "\r\n";
			}
		}
		
		return $formatted_headers;
	}

	/*
		$bodyjson	- (array) converted to JSON string
		$files		- (array) used to read file contents into body
		$filecount	- (int)	used to traverse files to open
	*/
	private function format_body($bodyjson,$files,$filecount)
	{
		if($filecount)
		{
			$content = "";
			for($i = 0; $i < $filecount; $i++)
			{
				$filename = $files[$i];
				$file_contents = file_get_contents($filename);
				
				$ext = pathinfo($filename,PATHINFO_EXTENSION);
				if(array_key_exists($ext, self::$MIMEtypes) !== false)
				{
					$mime = self::$MIMEtypes[$ext];
				}
				else
				{
					$mime = mime_content_type($filename);
				}
		
				$content .=  "--" . $this->MULTIPART_BOUNDARY . "\r\n"
				. "Content-Disposition: form-data; name=\"file-{$i}\"; filename=\"" . basename($filename) . "\"\r\n"
				. "Content-Type: " . $mime . "\r\n\r\n"
				. $file_contents ."\r\n";
			}
			
			$content .= "--" . $this->MULTIPART_BOUNDARY . "\r\n" 
			. "Content-Disposition: form-data; name=\"json\"\r\n"
			. "Content-Type: application/json\r\n\r\n"
			. json_encode($bodyjson) . "\r\n"
			. "--" . $this->MULTIPART_BOUNDARY . "--\r\n";
			
			return $content;
		}
		else if(!empty($bodyjson))
		{
			return json_encode($bodyjson);
		}
		
		return "";
	}
	
	private function getMIMEtypes()
	{
		$url = 'http://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types';
		
	    $set = array();
	    foreach(explode("\n",file_get_contents($url)) as $mimetype)
	        if(isset($mimetype[0]) && $mimetype[0] !== '#' && preg_match_all('#([^\s]+)#',$mimetype,$out) && isset($out[1]) && ($count = count($out[1])) > 1)
	            for($i = 1; $i < $count; $i++)
	                $set[$out[1][$i]] = $out[1][0];

	    self::$MIMEtypes = $set;
	}
	
	private function validate_requests()
	{
		/*
			https://tools.ietf.org/html/rfc2616
			OPTIONS	body - true		...future extensions to HTTP might use the OPTIONS body...
			GET		body - false	...GET method means retrieve whatever information... ...is identified by the Request-URI...
			HEAD	body - false	...HEAD method is identical to GET...
			POST	body - true		...POST method is used to request that the origin server accept the entity enclosed in the request...
			PUT		body - true		...PUT method requests that the enclosed entity be stored under the supplied Request-URI...
			DELETE	body - false	...DELETE method requests that the origin server delete the resource identified by the Request-URI...
			TRACE 	body - true		...TRACE method is used to invoke a remote, application-layer loop-back of the request message...
		*/
		if(!empty($this->bodyjson))
		{
			switch($this->method)
			{
				case "DELETE":
				case "GET":
				case "HEADERS":
					trigger_error("RestJSONClient Indeterminate Request, {$this->method} method should not be used with body content ::", E_USER_WARNING);
					break;
				default:
					
			}
		}
		
		/*
			https://tools.ietf.org/html/rfc2616
			OPTIONS	query - true	...OPTIONS method represents a request... ...identified by the Request-URI...
			GET		query - true	...GET method means retrieve whatever information... ...is identified by the Request-URI...
			HEAD	query - true	...HEAD method is identical to GET...
			POST	query - false	...POST method is used to... ...accept the entity enclosed... ...identified by the Request-URI in the Request-Line...
			PUT		query - false	...PUT method requests that the enclosed entity be stored under the supplied Request-URI...
			DELETE	query - false	...DELETE method requests that the origin server delete the resource identified by the Request-URI...
			TRACE 	query - true	...TRACE method is used to invoke a remote, application-layer loop-back of the request message...
		*/
		if(!empty($this->query))
		{
			switch($this->method)
			{
				case "DELETE":
				case "POST":
				case "PUT":
					trigger_error("RestJSONClient Indeterminate Request, {$this->method} method should not be used with query string ::", E_USER_WARNING);
					break;
				default:
					
			}
			
		}
		
		if($this->scheme === "http://" && !empty($this->pass))
		{
			trigger_error("RestJSONClient Insecure Request, authorization credentials should not be sent over http ::", E_USER_WARNING);
		}
	}

	/*** constructor function ***/

	public function __construct($method = "GET",$url = "",$headers = array(),$bodyjson = "",$files = null)
	{	
		$this->set_method($method);
		$this->set_url($url);
		$this->set_headers($headers);
		$this->set_bodyjson($bodyjson);
		$this->set_files($files);
		
		if(self::$MIMEtypes === null)
		{
			$this->getMIMEtypes();
		}
	}
	
	/*** setter & getter functions ***/
	
	public function set_authentication($user,$pass,$type = 'Basic')
	{
		$this->headers['Authorization'] = " $type " . base64_encode($user . ':' . $pass);
	}
	
	public function get_authentication()
	{	
		if(!isset($this->headers['Authorization']))
		{
			return array();
		}
		
		$header_parts = explode(' ',$this->headers['Authorization']);
		
		$type = $header_parts[1];
		$auth_parts = explode(':',base64_decode($header_parts[2]));
		
		return array('user' => $auth_parts[0], 'pass' => $auth_parts[1], 'type' => $type);
	}
	
	public function remove_authentication()
	{
		$this->user = "";
		$this->pass = "";
		
		if(isset($this->headers['Authorization']))
		{
			unset($this->headers['Authorization']);
		}
	}
	
	/*
		parameters:
			$bodyjson	- (string, array) strings should be in query format: key1=value1&key2=value2 etc.
		
		return:
			success		- (null)
			error		- (null) throws exception
	*/
	public function set_bodyjson($bodyjson)
	{
		if(is_string($bodyjson))
		{
			if(!empty($bodyjson))
			{
				// if json_decode fails, assume query string was passed
				$jsonarray = json_decode($bodyjson,true);
				if($jsonarray === null)
				{
					if($bodyjson[0] === '?')
					{
						$str = substr($bodyjson, 1);
					}
					else
					{
						$str = $bodyjson;
					}
					
						/* this part tries to verify we have a query string. it assumes we'll see at least one '=' and alternating '=' and '&' */
						$next = '=';
						$strArray = str_split($str);
						if(array_search('=', $strArray) !== false)
						{
							foreach($strArray as $key => $char)
							{
								if($char === '=')
								{
									if($char === $next)
									{
										$next = '&';
									}
									else
									{
										throw new Exception("Unable to parse string in set_bodyjson(), must be json string, array, or query string.");
									}
								}
								else if($char === '&')
								{
									if($char === $next)
									{
										$next = '=';
									}
									else
									{
										throw new Exception("Unable to parse string in set_bodyjson(), must be json string, array, or query string.");
									}
								}
							}
						}
						else
						{
							throw new Exception("Unable to parse string in set_bodyjson(), must be json string, array, or query string.");
						}

						parse_str($str, $output);
						$this->bodyjson = $output;
				}
				else
				{
					$this->bodyjson = $jsonarray;
				}
			}
			else
			{
				$this->bodyjson = "";
			}
		}
		else if(is_array($bodyjson))
		{
			$this->bodyjson = $bodyjson;
		}
		else
		{
			$badtype = gettype($bodyjson);
			throw new Exception("Invalid type in set_bodyjson(), must be array or string. $badtype was passed.");
		}
	}
	
	public function get_bodyjson()
	{
		return $this->bodyjson;
	}
	
	/*
		parameters:
			$filepaths	- (string, array) array of file paths or a string with a single file path
		
		return:
			success		- (null)
			error		- (null) throws exception
	*/
	public function set_files($filepaths)
	{
		if(is_null($filepaths))
		{
			return;
		}
		
		$this->MULTIPART_BOUNDARY = '--------------------------' . microtime(true);
		
		if(is_array($filepaths))
		{
			$this->filecount = count($filepaths);
			$this->files = $filepaths;
			
			$this->headers['Content-Type'] = 'multipart/form-data; boundary=' . $this->MULTIPART_BOUNDARY;	
		}
		else if(is_string($filepaths))
		{
			if(empty($filepaths))
			{
				$this->filecount = 0;
				$this->files = array();
			}
			else
			{
				$this->filecount = 1;
				$this->files = array($filepaths);
				
				$this->headers['Content-Type'] = 'multipart/form-data; boundary=' . $this->MULTIPART_BOUNDARY;		
			}
		}
		else
		{
			$badtype = gettype($filepaths);
			throw new Exception("Invalid type in set_files(), must be array or string. $badtype was passed.");
		}
	}
	
	public function get_files()
	{
		return $this->files;
	}
	
	public function remove_files()
	{
		if($this->headers['Content-Type'] === ('multipart/form-data; boundary=' . $this->MULTIPART_BOUNDARY))
		{
			unset($this->headers['Content-Type']);
		}
		
		$this->filecount = 0;
		$this->files = null;
	}
	
	/*
		parameters:
			$headers	- (string, array) if headers are passed as an array, they are converted to a string with "\r\n" appended to each header
		
		return:
			success		- (null)
			error		- (null) throws exception
	*/
	public function set_headers($headers)
	{
		/* empty the current headers */
		$this->headers = array();
		
		if(is_array($headers))
		{
			if(empty($headers))
			{
				return;
			}

			/* if a header array value has a semicolon, assume the values are full http headers */
			if(strpos($headers[array_keys($headers)[0]], ':') !== false)
			{
				foreach($headers as $key => $header)
				{
					$parts = explode(':', $header);
					$this->headers[$parts[0]] = $parts[1];
				}
			}
			else
			{
				/* this api doesn't store semicolons, so remove them if they've been included */
				if(strpos(array_keys($headers)[0], ':') !== false)
				{
					foreach($headers as $key => $header)
					{
						$parts = explode(':', $key);
						$this->headers[$parts[0]] = $header;
					}
				}
				else
				{
					$this->headers = $headers;
				}
			}
		}
		else if(is_string($headers))
		{
			if(empty($headers))
			{
				return;
			}

			$header = explode(':', $headers);
			$this->headers[$header[0]] = $header[1];
		}
		else
		{
			$badtype = gettype($headers);
			throw new Exception("Invalid type in set_headers(), must be array or string. $badtype was passed.");
		}
	}
	
	public function get_headers()
	{
		return $this->headers;
	}
	
	/*
		parameters:
			$method	- (string) if method is lower case, it will be automatically converted to uppercase
		
		return:
			success		- (null)
			error		- (null) throws exception
	*/
	public function set_method($method)
	{
		if(empty($method))
		{
			return;
		}
		
		$uppercase_method = strtoupper($method);
		
		switch($uppercase_method) {
			case "DELETE":
			case "GET":
			case "HEAD":
			case "OPTIONS":
			case "PATCH":
			case "POST":
			case "PUT":
			case "TRACE":
				$this->method = $uppercase_method;
				break;
			default:
				throw new Exception("Unsupported method passed to set_method(). $method was passed");
		}
	}
	
	public function get_method()
	{
		return $this->method;
	}
	
	public function set_protocol_version($version)
	{
		$lv = strtolower($version);
		switch($lv)
		{
			case "1";
			case "http/1":
			case "1.0":
			case "http/1.0":
				$this->protocol_version = "1.0";
				break;
			case "1.1":
			case "http/1.1":
				$this->protocol_version = "1.1";
				break;
			default:
				throw new Exception("Unsupported protocol version passed to set_protocol_version(). $method was passed");
		}
	}
	
	/*
		parameters:
			$timeout	- (int, float, double) the amount of time to try for a connection
		
		return:
			success		- (null)
			error		- (null) throws exception
	*/
	public function set_timeout($timeout)
	{
		if(!is_numeric($timeout))
		{
			$badtype = gettype($timeout);
			throw new Exception("Invalid type in set_timeout(), must be numeric. $badtype was passed.");
		}
		
		$this->timeout = $timeout;
	}
	
	public function get_timeout()
	{
		return $this->timeout;
	}
	
	/*
		parameters:
			$url	- (string) urls are always encoded, so pass in an unencoded url.
		
		return:
			success		- (null)
			error		- (null) throws exception
	*/
	public function set_url($url)
	{
		if(!is_string($url))
		{
			$badtype = gettype($url);
			throw new Exception("Invalid type in set_url(), must be string. $badtype was passed.");
		}
		
		if(empty($url))
		{
			return;
		}
		
		$urlc = parse_url($url);
		
		if(isset($urlc["scheme"])) { $this->set_url_scheme($urlc["scheme"]); }
		if(isset($urlc["user"])) { $this->set_url_user($urlc["user"]); }
		if(isset($urlc["pass"])) { $this->set_url_pass($urlc["pass"]); }
		if(isset($urlc["host"])) { $this->set_url_host($urlc["host"]); }
		if(isset($urlc["port"])) { $this->set_url_port($urlc["port"]); }
		if(isset($urlc["path"])) { $this->set_url_path($urlc["path"]); }
		if(isset($urlc["query"])) { $this->set_url_query($urlc["query"]); }
		if(isset($urlc["fragment"])) { $this->set_url_fragment($urlc["fragment"]); }
	}
	
	public function get_url()
	{
		return $this->scheme . urlencode($this->user) . $this->pass . $this->host . $this->port . $this->path . urlencode($this->query) . urlencode($this->frag);
	}
	
	public function set_url_scheme($scheme)
	{
		if($scheme !== "http" && $scheme !== "https")
		{
			throw new Exception("Bad scheme passed to set_url(). Add http:// or https:// to url");
		}
		else
		{
			$this->scheme = $scheme . "://";
		}
	}
	
	public function get_url_scheme()
	{
		return substr($this->scheme, 0,	-3);
	}
	
	public function set_url_user($user)
	{
		if(!empty($user))
		{
			$this->user = urlencode($user) . ":";
		}
	}
	
	public function get_url_user()
	{
		return substr($this->user, 0, -1);
	}
	
	public function set_url_pass($pass)
	{
		if(!empty($pass))
		{
			$this->pass = urlencode($pass) . "@";
		}
	}
	
	public function get_url_pass()
	{
		return substr($this->pass, 0, -1);
	}
	
	public function set_url_host($host)
	{
		if(!empty($host))
		{
			$this->host = $host;
		}
	}
	
	public function get_url_host()
	{
		return $this->host;
	}
	
	/// throw new Exception("Bad host passed to set_url(). No host detected.");
	
	public function set_url_port($port)
	{
		if(!empty($port))
		{
			$this->port = ":" . $port;
		}
	}
	
	public function get_url_port()
	{
		return substr($this->port, 1);
	}
	
	public function set_url_path($path)
	{
		if($path[0] !== '/')
		{
			$this->path = '/' . $path;
		}
		else
		{
			$this->path = $path;
		}
	}
	
	public function get_url_path()
	{
		return $this->path;
	}
	
	public function set_url_query($query)
	{
		if(!empty($query))
		{
			$this->query = "?" . urlencode($query);
		}
	}
	
	public function get_url_query()
	{
		return substr($this->query, 1);
	}
	
	public function set_url_fragment($frag)
	{
		if(!empty($frag))
		{
			$this->frag = "#" . urlencode($frag);
		}
	}
	
	public function get_url_fragment()
	{
		return substr($this->frag, 1);
	}
	
	public function set_all($method,$url,$headers,$bodyjson,$files)
	{
		$this->set_method($method);
		$this->set_url($url);
		$this->set_headers($headers);
		$this->set_bodyjson($bodyjson);
		$this->set_files($files);
	}

	public function get_all()
	{
		return array($this->method,$this->get_url(),$this->headers,$this->bodyjson,$this->file);
	}
	
	/*** send request function ***/
	
	/*
		return:
			success		- (array) contains the response
			error		- (boolean) false
	*/
	public function send() {
		$url = $this->get_url();
		$body = $this->format_body($this->bodyjson,$this->files,$this->filecount);
		
		/* display warnings on indeterminate request formats */
		$this->validate_requests();
		
		/* create http context options */
		$options = array(
		    'http' => array(
		        'header'  => $this->format_headers($this->headers,$this->bodyjson,$this->filecount),
		        'method'  => $this->method,
		        'content' => $body,
		        'protocol_version' => $this->protocol_version,
		        'timeout' => $this->timeout,
		        'user_agent' => 'RestJSONClient ' . self::VERSION,
		        'ignore_errors' => true
		    )
		);
		
		/* create stream, send request, return response */
		$context  = stream_context_create($options);

		$this->response = file_get_contents($url, false, $context);
		
		return $this->response;
	}
}

