<?php
require_once(dirname(__FILE__) . '/db.php');
require_once(dirname(__FILE__) . '/common_functions.php');
//-----------------------------

function checkSession($redirection=true){	
	if(isSessionRefreshed() == false)
		terminateSession();
}
function validateLogin($username,$pwd){	
	global $conn;
	$pwdhash = hash("sha256", $pwd, false);
	$stmt = $conn->prepare("SELECT COUNT(*) FROM tb_main where username=? AND password=?");
	$stmt->bind_param('ss', $username,$pwdhash);
	$stmt->execute();
	$row = $stmt->get_result()->fetch_row();
	if($row[0] > 0){
		setServerVariables($conn);
		startProcess(getOSType());
		return true;
	}
	else
		return false;
}

function isSessionRefreshed(){
	if(isset($_SESSION['username']) && isset($_SESSION['lastaccess'])){
		if(time()-intval($_SESSION['lastaccess']) > 3600 )	//expire session if more than x seconds
			return false; //session expired
		else
			$_SESSION['lastaccess'] = time();
	}
	else
		return false; //session expired
	return true;
}

function terminateSession(){
	session_unset();
	session_destroy();
	ob_end_clean();   // clear output buffer
	header("Location: /spear/");
	die();
}

function setServerVariables($conn){
	$server_protocol = isset($_SERVER['HTTPS'])?'https':'http';
	$baseurl = $server_protocol.'://'.$_SERVER['HTTP_HOST'];
	$stmt = $conn->prepare("UPDATE tb_main_variables SET server_protocol=?, domain =?, baseurl=?");
	$stmt->bind_param('sss', $server_protocol, $_SERVER['HTTP_HOST'], $baseurl);
	
	$stmt->execute();
	$stmt->close();
}

function setInfoCookie(){
	global $conn;
	$result = mysqli_query($conn, "SELECT time_zone,time_format FROM tb_main_variables");
	if(mysqli_num_rows($result) > 0){
		$result = mysqli_fetch_assoc($result);
		$result['time_zone'] = json_decode($result['time_zone']);
		$result['time_format'] = json_decode($result['time_format']);
		setcookie("c_data",base64_encode(json_encode($result)), ["path" => "/", "SameSite" => "Strict", "HttpOnly" => false]);
	}
}

//-----------------------------------Start Public Access-------------------------------
function amIPublic($tk_id,$campaign_id,$tracker_id=""){
	global $conn;

	if(empty($tracker_id))
		$ctrl_ids = json_encode([$campaign_id]);
	else
		$ctrl_ids = json_encode([$campaign_id,$tracker_id]);

	$stmt = $conn->prepare("SELECT COUNT(*) FROM tb_access_ctrl WHERE tk_id=? AND ctrl_ids=?");
	$stmt->bind_param("ss", $tk_id,$ctrl_ids);
	$stmt->execute();
	$row = $stmt->get_result()->fetch_row();
	if($row[0] > 0){
		setInfoCookie();
		return true;
	}
	else
		return false;
}

//------

if (isset($_POST)) {
	$POSTJ = json_decode(file_get_contents('php://input'),true);

	if(isset($POSTJ['action_type']) && isset($POSTJ['tk_id']))
		if($POSTJ['action_type'] == "manage_dashboard_access"){
			if(isset($POSTJ['campaign_id']) && isset($POSTJ['tracker_id']))
				manageDashboardAccess($POSTJ['tk_id'],$POSTJ['ctrl_val'],$POSTJ['campaign_id'],$POSTJ['tracker_id']);
			else
				if(isset($POSTJ['campaign_id']))
					manageDashboardAccess($POSTJ['tk_id'],$POSTJ['ctrl_val'],$POSTJ['campaign_id']);
		}
		else
		if($POSTJ['action_type'] == "get_access_info"){
			if(isset($POSTJ['campaign_id']) && isset($POSTJ['tracker_id']))
				getAccessInfo($POSTJ['tk_id'],$POSTJ['campaign_id'],$POSTJ['tracker_id']);
			else
				if(isset($POSTJ['campaign_id']))
					getAccessInfo($POSTJ['tk_id'],$POSTJ['campaign_id']);
		}
}

function manageDashboardAccess($tk_id,$ctrl_val,$campaign_id,$tracker_id=""){	// For web-email camp
	header('Content-Type: application/json');
	global $conn;

	if(empty($tracker_id))
		$ctrl_ids = json_encode([$campaign_id]);
	else
		$ctrl_ids = json_encode([$campaign_id,$tracker_id]);

	//delete existing entry
	$stmt = $conn->prepare("DELETE FROM tb_access_ctrl WHERE ctrl_ids = ?");
	$stmt->bind_param("s", $ctrl_ids);
	$stmt->execute();
	$stmt->close();

	if($ctrl_val == true){
		$stmt = $conn->prepare("INSERT INTO tb_access_ctrl(tk_id,ctrl_ids) VALUES(?,?)");
		$stmt->bind_param('ss', $tk_id,$ctrl_ids);
	}
	else{
		$stmt = $conn->prepare("DELETE FROM tb_access_ctrl WHERE tk_id = ?");
		$stmt->bind_param('s', $tk_id);
	}

	if ($stmt->execute() === TRUE)
		echo json_encode(['result' => 'success', 'tk_id'=> $tk_id]);	
	else 
		echo json_encode(['result' => 'failed', 'error' => 'Error in enabling/disabling access']);	
	$stmt->close();
}

function getAccessInfo($tk_id, $campaign_id, $tracker_id=""){
	header('Content-Type: application/json');
	global $conn;
	
	if(empty($tracker_id))
		$ctrl_ids = json_encode([$campaign_id]);
	else
		$ctrl_ids = json_encode([$campaign_id,$tracker_id]);

	$stmt = $conn->prepare("SELECT tk_id FROM tb_access_ctrl WHERE ctrl_ids=?");
	$stmt->bind_param("s", $ctrl_ids);
	$stmt->execute();
	$result = $stmt->get_result();
	if($row = $result->fetch_assoc())
		echo json_encode(['pub_access' => true, 'tk_id'=>$row["tk_id"]]);
	else
		echo json_encode(['pub_access' => false]);
	$stmt->close();
}
//-----------------------------------End Public Access-------------------------------

?>