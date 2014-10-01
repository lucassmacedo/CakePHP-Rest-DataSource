<?php
App::uses('DboSource', 'Model/Datasource');

class RestSource extends DboSource {
	public $description = 'Rest Source';

	public $headers = array();

/**
 * __construct
 *
 * We are not a dbo source - we are secretly a datasource and just want the log functions, hence we extend
 * DboSource
 *
 * @param mixed $config
 * @param mixed $autoConnect
 * @return void
 */
	public function __construct($config = null, $autoConnect = true) {
		DataSource::__construct($config);
		$this->fullDebug = Configure::read('debug') > 1;
	}

/**
* Execute a custom query against the REST server
*
* $model->get('custom_method',)
* $model->post('custom_method', array())
*
* @param string $method The HTTP verb to execute (get, post, pull, delete)
* @param array $pass	The raw configuration
* @param Model $model	The model that triggered the call
* @return mixed
*/
	public function query($method, $pass, Model $model) {
		if (empty($pass)) {
			throw new Exception('Missing information about the HTTP request');
		}

		$t = microtime(true);

		$config = $pass[0];
		if (!is_array($config)) {
			$config = array('action' => $config);
		}

		if (empty($config['action'])) {
			throw new Exception('Missing action key');
		}

		$url = sprintf('/%s/%s', $model->remoteResource, $config['action']);

		$cu = new \Nodes\Curl($this->getBaseUrl() . $url);
		$this->applyConfiguration($cu);

		$data = null;
		if (in_array($method, array('put', 'post')) && isset($pass[1])) {
			$data = $pass[1];
		}

		try {
			$response = call_user_func(array($cu, $method), $data);

			if ($this->fullDebug) {
				$this->took = round((microtime(true) - $t) * 1000, 0);
				$this->numRows = $this->affected = $response['data'] ? count(current($response['data'])) : 0;
				$this->logQuery($url, $params);
			}

			return $response;

		} catch (Exception $e) {
			$this->logQuery($url, $params);

			CakeLog::error($e);
			return array();
		}
	}

/**
* Execute a HTTP POST request against a REST resource
*
* @param Model $model	The model that is executing the save()
* @param array $fields	A list of fields that needs to be saved
* @param array $values	A list of values that need to be saved
* @return mixed
*/
	public function create(Model $model, $fields = null, $values = null) {
		$t = microtime(true);

		$method = 'post';
		$url	= sprintf('/%s', $model->remoteResource);
		if(!empty($data['id'])) {
			$url = sprintf('/%s/%s', $model->remoteResource, $data['id']);
			$method = 'put';
		}

		$data = array();
		if (!empty($fields) && !empty($values)) {
			$data = array_combine($fields, $values);
		}

		$cu = new \Nodes\Curl($this->getBaseUrl() . $url);
		$this->applyConfiguration($cu);

		try {
			$response = call_user_func(array($cu, $method), $data);

			if ($this->fullDebug) {
				$this->took = round((microtime(true) - $t) * 1000, 0);
				$responseData = $response->getResponseBody('data');
				$this->numRows = $this->affected = isset($responseData['data']) ? count(current($responseData['data'])) : 0;
				$this->logQuery($url, $data);
			}

			return $response;
		} catch (Exception $e) {
			if ($this->fullDebug) {
				$this->logQuery($url);
			}
			CakeLog::error($e);
			return array();
		}
	}

/**
* Execute a GET request against a REST resource
*
* @param Model $model		The model that is executing find() / read()
* @param array $queryData	The conditions for the find - currently we only support "id" => $value
* @return mixed
*/
	public function read(Model $model, $queryData = array()) {
		$t = microtime(true);

		$url = $this->config['host'] . DS . $model->remoteResource;

		if (isset($queryData['action'])) {
			$url .= DS . $queryData['action'];
		}

		if (!empty($queryData['conditions']['id'])) {
			$url .= DS . $queryData['conditions']['id'];
			unset($queryData['conditions']['id']);
		}

		if (!empty($this->config['format'])) {
			$url = trim($url, DS) . '.' . $this->config['format'];
		}

		if (!empty($queryData['limit'])) {
			$queryData['conditions']['limit'] = $queryData['limit'];
		}

		if (!empty($queryData['offset'])) {
			$queryData['conditions']['offset'] = $queryData['offset'];
		}

		if (!empty($queryData['order'])) {
			$queryData['conditions']['order'] = $queryData['order'];
		}

		if (!empty($queryData['page'])) {
			$queryData['conditions']['page'] = $queryData['page'];
		}

		if (!empty($queryData['conditions'])) {
			$url .= '?' . http_build_query($queryData['conditions']);
		}

		try {
			$cu = new \Nodes\Curl($url);
			$this->applyConfiguration($cu);

			$response = $cu->get()->getResponseBody();

			if ($this->fullDebug) {
				$this->took = round((microtime(true) - $t) * 1000, 0);
				$current = is_array($response['data']) ? current($response['data']) : array();
				$this->numRows = $this->affected = $response['data'] ? count($current) : 0;
				$this->logQuery($url, $queryData);
			}

			if (empty($response['success'])) {
				return array();
			}
			return $response['data'];
		} catch (Exception $e) {
			if ($this->fullDebug) {
				$this->logQuery($url, $queryData);
			}

			CakeLog::error($e);
			return array();
		}
	}

/**
* Execute a PUT request against a REST resource
*
* @param Model $model		The model that is executing the save()
* @param array $fields		A list of fields that needs to be saved
* @param array $values		A list of values that need to be saved
* @param array $conditions	Update conditions - currently not used
* @return mixed
*/
	public function update(Model $model, $fields = array(), $values = null, $conditions = null) {
		$data	= array_combine($fields, $values);
		$url	= sprintf('/%s', $model->remoteResource);
		$cu		= new \Nodes\Curl($this->getBaseUrl() . $url);
		$this->applyConfiguration($cu);

		try {
			return $cu->put($data);
		} catch (Exception $e) {
			CakeLog::error($e);
			return array();
		}
	}

/**
* Execute a DELETE request against a REST resource
*
* @param Model $model	The model that is executing the delete()
* @param mixed $id		The resource ID to delete
* @return mixed
*/
	public function delete(Model $model, $id = null) {
		$url	= sprintf('/%s/%s', $model->remoteResource, $id);
		$cu		= new \Nodes\Curl($this->getBaseUrl() . $url);
		$this->applyConfiguration($cu);

		try {
			return $cu->delete();
		} catch (Exception $e) {
			CakeLog::error($e);
			return array();
		}
	}

/**
* Build the baseURL based on configuration options
*  - protocol	string	Can be HTTP or HTTPS (default)
*  - hostname	string	The hostname of the application server
*  - admin		boolean If the remote URL is within an admin routing
*
* @return string
*/
	public function getBaseUrl() {
		return $this->config['host'];
	}

/**
 * Caches/returns cached results for child instances
 *
 * @param mixed $data
 * @return array Array of sources available in this datasource.
 */
	public function listSources($data = null) {
		return true;
	}

/**
* Apply some custom confiuration to our cURL object
* - Set the Platform-Token HTTP header for remote authentication
* - Set the
*
* @param Curl $cu	The cURL object we want to apply configuration for
* @return void
*/
	public function applyConfiguration(\Nodes\Curl $cu) {
		$check = array_map(function($h) { return current(explode(':', $h)); }, $this->headers);
		if (!in_array('X-Authorization', $check)) {
			throw new InternalErrorException('No API token provided');
		}
		$cu->setOption(CURLOPT_HTTPHEADER, $this->headers);
	}
}
