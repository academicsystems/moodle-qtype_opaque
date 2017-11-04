<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Defines the qtype_opaque_connection class.
 *
 * @package   qtype_opaque
 * @copyright 2011 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/xmlize.php');
require_once($CFG->dirroot . '/question/type/opaque/RestJSONClient/RestJSONClient.php');

// In config.php, you can set
// $CFG->qtype_opaque_soap_class = 'qtype_opaque_soap_client_with_logging';
// To log every SOAP call in huge detail. Lots are writted to moodledata/temp.

/**
 * Wraps the SOAP connection to the question engine, exposing the methods used
 * when handling question and engine metatdata.
 *
 * @copyright 2011 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_opaque_connection_soap {

	protected $questionbanks = array();
	protected $passkeysalt = '';
	protected $soapclient;

	/**
	 * Constructor. Normally you should call
	 * {@link qtype_opaque_engine_manager::get_connection()} rather than calling
	 * this constructor directly.
	 * @param object $engine information about the engine being connected to.
	 */
	public function __construct($engine) {
		global $CFG;

		if (!empty($engine->urlused)) {
			$url = $engine->urlused;
		} else {
			$url = $engine->questionengines[array_rand($engine->questionengines)];
		}

		if (!empty($CFG->qtype_opaque_soap_class)) {
			$class = $CFG->qtype_opaque_soap_class;
		} else {
			$class = 'qtype_opaque_soap_client_with_timeout';
		}

		$this->soapclient = new $class($url . '?wsdl', array(
					'soap_version'	   => SOAP_1_1,
					'exceptions'		 => true,
					'cache_wsdl'		 => WSDL_CACHE_NONE,
					'connection_timeout' => $engine->timeout,
					'features'		   => SOAP_SINGLE_ELEMENT_ARRAYS,
				));
		$engine->urlused = $url;

		$this->questionbanks = $engine->questionbanks;
		$this->passkeysalt = $engine->passkey;
	}

	/**
	 * @return string random question bank url from the engine definition, if
	 *	  there is one, otherwise the empty string.
	 */
	protected function question_base_url() {
		if (!empty($this->questionbanks)) {
			return $this->questionbanks[array_rand($this->questionbanks)];
		} else {
			return '';
		}
	}

	/**
	 * @param string $secret the secret string for this question engine.
	 * @param int $userid the id of the user attempting this question.
	 * @return string the passkey that needs to be sent to the quetion engine to
	 *	  show that we are allowed to start a question session for this user.
	 */
	protected function generate_passkey($userid) {
		return md5($this->passkeysalt . $userid);
	}

	/**
	 * @return some XML, as parsed by xmlize giving the status of the engine.
	 */
	public function get_engine_info() {
		$getengineinforesult = $this->soapclient->getEngineInfo();
		return xmlize($getengineinforesult);
	}

	/**
	 * @param string $remoteid identifies the question.
	 * @param string $remoteversion identifies the specific version of the quetsion.
	 * @return The question metadata, as an xmlised array, so, for example,
	 *	  $metadata[questionmetadata][@][#][scoring][0][#][marks][0][#] is the
	 *	  maximum possible score for this question.
	 */
	public function get_question_metadata($remoteid, $remoteversion) {
		$getmetadataresult = $this->soapclient->getQuestionMetadata(
				$remoteid, $remoteversion, $this->question_base_url());
		return xmlize($getmetadataresult);
	}
}


/**
 * SoapClient subclass that implements time-outs correctly.
 *
 * Thanks to http://www.darqbyte.com/2009/10/21/timing-out-php-soap-calls/
 * for outlining this solution.
 *
 * @copyright  2011 The Open University
 * @license	http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_opaque_soap_client_with_timeout extends SoapClient {
	/** @var array configuration options for CURL. */
	protected $curloptions = array(
		CURLOPT_VERBOSE => false,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST => true,
		CURLOPT_HEADER => false,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => false,
	);

	/** @var array standard HTTP headers to send. */
	protected $headers = array(
		'Content-Type: text/xml',
	);

	/*
	 * (non-PHPdoc)
	 * @see SoapClient::__construct()
	 */
	public function __construct($wsdl, $options) {
		parent::__construct($wsdl, $options);
		if (!array_key_exists('connection_timeout', $options)) {
			throw new coding_exception('qtype_opaque_timeoutable_soap_client requires ' .
					'the connection timeout to be specificed in the constructor options.');
		}
		$this->curloptions[CURLOPT_TIMEOUT] = $options['connection_timeout'];
	}

	/*
	 * (non-PHPdoc)
	 * @see SoapClient::__doRequest()
	 */
	public function __doRequest($request, $location, $action, $version, $oneway = false) {

		$headers = $this->headers;
		if ($action) {
			$headers[] = 'SOAPAction: ' . $action;
		} else {
			$headers[] = 'SOAPAction: none'; // Seemingly, this is necessary.
		}

		$curl = curl_init($location);
		curl_setopt_array($curl, $this->curloptions);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		$response = curl_exec($curl);
		if (curl_errno($curl)) {
			throw new SoapFault('Receiver', curl_error($curl));
		}
		curl_close($curl);

		if (!$oneway) {
			return ($response);
		}
	}
}


/**
 * A subclass of qtype_opaque_connection that logs every SOAP call made.
 * @copyright  2011 The Open University
 * @license	http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class qtype_opaque_soap_client_with_logging extends qtype_opaque_soap_client_with_timeout {
	/*
	 * (non-PHPdoc)
	 * @see SoapClient::__soapCall()
	 */
	public function __call($function, $arguments) {
		$this->__log_arguments($function, $arguments);
		$timenow = microtime(true);

		try {
			$result = parent::__call($function, $arguments);
			$this->__log_result($function, $result, microtime(true) - $timenow);
			return $result;
		} catch (Exception $e) {
			$this->__log_exception($function, $e, microtime(true) - $timenow);
			throw $e;
		}
	}

	protected function __write_to_log($message) {
		global $CFG;
		file_put_contents($CFG->dataroot . '/temp/opaquelog.txt', $message . "\n",
				FILE_APPEND | LOCK_EX);
	}

	protected function __write_to_short_log($message) {
		global $CFG;
		file_put_contents($CFG->dataroot . '/temp/opaqueshortlog.txt', $message . "\n",
				FILE_APPEND | LOCK_EX);
	}

	protected function __log_arguments($function, $arguments) {
		$this->__log_rule();
		$this->__write_to_log("$function called with arguments:");
		foreach ($arguments as $arg) {
			$this->__log_thin_rule();
			$this->__log_object($arg);
		}
	}

	protected function __log_result($function, $result, $timetaken) {
		$this->__log_thin_rule();
		$this->__write_to_log("$function returned after {$this->__format_time($timetaken)}s. Value:");
		$this->__log_thin_rule();
		$this->__log_object($result);
		$this->__log_rule();

		$this->__write_to_short_log("Call to $function succeeded after {$this->__format_time($timetaken)}s.");
	}

	protected function __log_exception($function, $e, $timetaken) {
		$this->__log_thin_rule();
		$this->__write_to_log("$function failed after {$this->__format_time($timetaken)}s. Exception:");
		$this->__log_thin_rule();
		$this->__log_object($result);
		$this->__log_rule();

		$this->__write_to_short_log("Call to $function failed after {$this->__format_time($timetaken)}s.");
	}

	protected function __log_object($o) {
		$this->__write_to_log(print_r($o, true));
	}

	protected function __log_rule() {
		$this->__write_to_log(
				"================================================================================");
	}

	protected function __log_thin_rule() {
		$this->__write_to_log(
				"--------------------------------------------------------------------------------");
	}

	protected function __format_time($timetaken) {
		return format_float($timetaken, 4);
	}
}


class qtype_opaque_connection_rest {
	
	protected $questionbanks = array();
	protected $passkeysalt = '';
	protected $restclient;
	
	public function __construct($engine) {
		global $CFG;

		if (!empty($engine->urlused)) {
			$url = $engine->urlused;
		} else {
			$url = $engine->questionengines[array_rand($engine->questionengines)];
		}

		if (!empty($CFG->qtype_opaque_rest_class)) {
			$class = $CFG->qtype_opaque_rest_class;
		} else {
			$class = 'qtype_opaque_rest_client';
	   }

	$this->restclient = new $class($url);
	$this->restclient->set_url($url);
		$engine->urlused = $url;
	 
		$this->questionbanks = $engine->questionbanks;
		$this->passkeysalt = $engine->passkey;
	}
	
	protected function generate_passkey($userid) {
		return md5($this->passkeysalt . $userid);
	}

	protected function getpasskeyquery() {
		$iv = substr(base64_encode(openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cfb8'))), 0, 16);
		$encrypted = openssl_encrypt('success', 'aes-256-cfb8', md5($this->passkeysalt), 0, $iv); // base64 encodes by default
		$pk = urlencode($encrypted) . ':' . urlencode($iv);
		return $pk;
	}

	protected function question_base_url() {
		if (!empty($this->questionbanks)) {
			return $this->questionbanks[array_rand($this->questionbanks)];
		} else {
			return '';
		}
	}

	public function get_engine_info() {
	$pk = null;
		if(!empty($this->passkeysalt)) {
			$pk = $this->getpasskeyquery();
		}

		$getengineinforesult = $this->restclient->getEngineInfo($pk);
		return json_decode($getengineinforesult['body'],true);
	}
	
	public function get_question_metadata($remoteid, $remoteversion) {
		$pk = null;
		if(!empty($this->passkeysalt)) {
			$pk = $this->getpasskeyquery();
		}

		$getmetadataresult = $this->restclient->getQuestionMetadata(
				$remoteid, $remoteversion, $this->question_base_url(), $pk);
		return json_decode($getmetadataresult['body'],true);
		
		$decoded = json_decode($getmetadataresult['body'],true);
		if($decoded == null) {
			return array('errors' => 'Status: ' . $getmetadataresult['status-line']['code'] . '<br>Body: ' . $getmetadataresult['body']);
		} else {
			return $decoded;
		}
	}
	
	public function post_question_file($questionfile, $remoteid, $remoteversion) {
		$pk = null;
		if(!empty($this->passkeysalt)) {
			$pk = $this->getpasskeyquery();
		}

		$postquestionfileresult = $this->restclient->postQuestionFile(
				$questionfile, $remoteid, $remoteversion, $this->question_base_url(), $pk);
		
		$decoded = json_decode($postquestionfileresult['body'],true);
		if($decoded == null) {
			// probably indicates the quiz engine does not support question posting
			return array('errors' => 'Status: ' . $postquestionfileresult['status-line']['code'] . '<br>Body: ' . $postquestionfileresult['body']);
		} else {
			return $decoded;
		}
	}
}

class qtype_opaque_rest_client extends RestJSONClient {

	protected $basepath = '';

	public function __construct($basepath = '',$passkey = '') {
		$this->basepath = $basepath;
	}

	public function getEngineInfo($pk = '') {
		$this->set_method('GET');
		$this->set_url($this->basepath . '/info');
		$this->set_bodyjson('');

		if (!empty($pk)) {
			$this->set_url_query('passKey=' . $pk);
		}
		
		return $this->send();
	}
	
	public function getQuestionMetadata($remoteid, $remoteversion, $questionbaseurl, $pk = '') {
		$this->set_method('GET');
		$this->set_url($this->basepath . '/question/' . $questionbaseurl . '/' . $remoteid . '/' . $remoteversion);
		$this->set_bodyjson('');

		if(!empty($pk)) {
			$this->set_url_query('passKey=' . $pk);
		}
		
		return $this->send();
	}
	
	public function postQuestionFile($questionfile, $remoteid, $remoteversion, $questionbaseurl, $pk = '') {
		$this->set_method('POST');
		$this->set_url($this->basepath . '/question/' . $questionbaseurl . '/' . $remoteid . '/' . $remoteversion);
		
		$data = array('questionFile' => $questionfile);

		if(!empty($pk)) {
			$data['passKey'] = $pk;
		}
		
		$this->set_bodyjson($data);
		
		return $this->send();
	}
	
	public function start($remoteid, $remoteversion, $questionbaseurl, $initialparamskeys, $initialparamsvalues, $cachedresources) {
		$this->set_method('POST');
		$this->set_url($this->basepath . '/session');
		
		$bodyjson = array(
			'questionID' => $remoteid,
			'questionVersion' => $remoteversion,
			'questionBaseURL' => $questionbaseurl,
			'initialParamNames' => $initialparamskeys,
			'initialParamValues' => $initialparamsvalues,
			'cachedResources' => $cachedresources
		);
		
		$this->set_bodyjson($bodyjson);
		
		return $this->send();
	}
	
	public function process($questionsessionid, $responsekeys, $responsevalues) {
		$this->set_method('POST');
		$this->set_url($this->basepath . '/session/' . $questionsessionid);
		
		$bodyjson = array(
			'names' => $responsekeys,
			'values' => $responsevalues
		);
		
		$this->set_bodyjson($bodyjson);
		
		return $this->send();
	}

	public function stop($questionsessionid, $pk) {
		$this->set_method('DELETE');
		$this->set_url($this->basepath . '/session/' . $questionsessionid);
		$this->set_bodyjson('');
		
		if (!empty($pk)) {
			$this->set_url_query('passKey=' . $pk);
		}
		return $this->send(); // response should be empty
	}
}

