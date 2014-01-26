<?php

include('curl.php');

class Box {
	public $client_id		= '';
	public $client_secret	= '';
	public $redirect_uri	= '';
	public $access_token	= '';
	public $refresh_token	= '';
	public $authorize_url	= 'https://www.box.com/api/oauth2/authorize';
	public $token_url		= 'https://www.box.com/api/oauth2/token';
	public $api_url			= 'https://api.box.com/2.0';
	public $upload_url		= 'https://upload.box.com/api/2.0';
	public $curl;

	public function __construct($client_id = '', $client_secret = '') {
		if(empty($client_id) || empty($client_secret)) {
			throw ('Invalid CLIENT_ID or CLIENT_SECRET or REDIRECT_URL. Please provide CLIENT_ID, CLIENT_SECRET and REDIRECT_URL when creating an instance of the class.');
		} else {
			$this->client_id 		= $client_id;
			$this->client_secret 	= $client_secret;
		}
		$this->curl = new Curl();
	}

	public function auth() {
		if(array_key_exists('refresh_token', $_REQUEST)) {
        	$this->refresh_token = $_REQUEST['refresh_token'];
		} else {
			echo $url = $this->authorize_url . '?' . http_build_query(array('response_type' => 'code', 'client_id' => $this->client_id/*, 'redirect_uri' => $this->redirect_uri*/));
			header('location: ' . $url);
			exit();
		}
	}

	public function getToken($code) {
		$url = $this->token_url;
		if(!empty($this->refresh_token)){
			$params = array('grant_type' => 'refresh_token', 'refresh_token' => $this->refresh_token, 'client_id' => $this->client_id, 'client_secret' => $this->client_secret);
		} else {
			$params = array('grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $this->client_id, 'client_secret' => $this->client_secret);
		}
		return json_decode($this->curl->post($url, $params), true);
	}

	public function readToken($type = 'file') {
		if($type == 'file' && file_exists('token.box')){
			$fp = fopen('token.box', 'r');
			$content = fread($fp, filesize('token.box'));
			fclose($fp);
		} else {
			return false;
		}
		return json_decode($content, true);
	}

	public function writeToken($token, $type = 'file') {
		$array = $token;
		if(isset($array['error'])){
			$this->error = $array['error_description'];
			return false;
		} else {
			$array['timestamp'] = time();
			if($type == 'file'){
				$fp = fopen('token.box', 'w');
				fwrite($fp, json_encode($array));
				fclose($fp);
			}
			return true;
		}
	}

	public function loadToken() {
		$array = $this->readToken('file');
		if(!$array){
			return false;
		} else {
			if(isset($array['error'])){
				$this->error = $array['error_description'];
				return false;
			} elseif($this->expired($array['expires_in'], $array['timestamp'])){
				$this->refresh_token = $array['refresh_token'];
				$token = $this->getToken(NULL, true);
				if($this->writeToken($token, 'file')){
					$array = $token;
					$this->refresh_token = $array['refresh_token'];
					$this->access_token = $array['access_token'];
					return true;
				}
			} else {
				$this->refresh_token = $array['refresh_token'];
				$this->access_token = $array['access_token'];
				return true;
			}
		}
	}

	public function getUser() {
		$url = $this->build_url('/users/me');
		return json_decode($this->curl->get($url),true);
	}
	
	public function getFolderDetails($folder) {
		$url = $this->build_url("/folders/$folder");
		return json_decode($this->curl->get($url),true);
	}
	
	public function getFolderItems($folder) {
		$url = $this->build_url("/folders/$folder/items");
		return json_decode($this->curl->get($url),true);
	}
	
	public function getFolderCollaborators($folder) {
		$url = $this->build_url("/folders/$folder/collaborations");
		return json_decode($this->curl->get($url),true);
	}
	
	public function getFolders($folder = 0) {
		$data = $this->getFolderItems($folder);
		foreach($data['entries'] as $item){
			$array = '';
			if($item['type'] == 'folder'){
				$array = $item;
			}
			$return[] = $array;
		}
		if (isset($return))
			return array_filter($return);
		else
			return null;
	}
	
	public function getFiles($folder) {
		$data = $this->getFolderItems($folder);
		foreach($data['entries'] as $item){
			$array = '';
			if($item['type'] == 'file'){
				$array = $item;
			}
			$return[] = $array;
		}
		return array_filter($return);
	}
	
	public function getLinks($folder) {
		$data = $this->getFolderItems($folder);
		foreach($data['entries'] as $item){
			$array = '';
			if($item['type'] == 'web_link'){
				$array = $item;
			}
			$return[] = $array;
		}
		return array_filter($return);
	}
	
	public function createFolder($name, $parent_id) {
		$url = $this->build_url("/folders");
		$params = array('name' => $name, 'parent' => array('id' => $parent_id));
		return json_decode($this->curl->post($url, json_encode($params)), true);
	}
	
	public function updateFolder($folder, array $params) {
		$url = $this->build_url("/folders/$folder");
		return json_decode($this->curl->put($url, $params), true);
	}
	
	public function deleteFolder($folder, array $opts) {
		echo $url = $this->build_url("/folders/$folder", $opts);
		$return = json_decode($this->curl->delete($url), true);
		if(empty($return)){
			return 'The folder has been deleted.';
		} else {
			return $return;
		}
	}

	public function getFileDetails($file) {
		$url = $this->build_url("/files/$file");
		return json_decode($this->get($url),true);
	}
	
	public function putFile($filename, $parent_id) {
		$url = $this->upload_url . '/files/content';
		$params = array('filename' => "@" . realpath($filename), 'parent_id' => $parent_id, 'access_token' => $this->access_token);
		return json_decode($this->curl->post($url, $params), true);
	}
	
	public function updateFile($file, array $params) {
		$url = $this->build_url("/files/$file");
		return json_decode($this->curl->put($url, $params), true);
	}

	public function deleteFile($file) {
		$url = $this->build_url("/files/$file");
		$return = json_decode($this->curl->delete($url),true);
		if(empty($return)){
			return 'The file has been deleted.';
		} else {
			return $return;
		}
	}

	public function folderTree($folder = '0') {
		$arr = $this->getFolders($folder);
		$list = array();
		if ($arr) {
			foreach ($arr as $f) {
				if ($f['type'] == 'folder') {
					$list += array($f['name'] => array('id' => $f['id']));
					$return = $this->folderTree($f['id']);
					if ($return)
						$list[$f['name']] += array('child',$return);
				}
			}
		}
		return $list;
	}

	public function build_url($api_func, array $opts = array()) {
			$opts = $this->set_opts($opts);
			$base = $this->api_url . $api_func . '?';
			$query_string = http_build_query($opts);
			$base = $base . $query_string;
			return $base;
		}
		
	public function set_opts(array $opts) {
		if(!array_key_exists('access_token', $opts)) {
			$opts['access_token'] = $this->access_token;
		}
		return $opts;
	}
	
	public function parse_result($res) {
		$xml = simplexml_load_string($res);
		$json = json_encode($xml);
		$array = json_decode($json,TRUE);
		return $array;
	}
	
	public function expired($expires_in, $timestamp) {
		$ctimestamp = time();
		if(($ctimestamp - $timestamp) >= $expires_in){
			return true;
		} else {
			return false;
		}
	}	
}
?>