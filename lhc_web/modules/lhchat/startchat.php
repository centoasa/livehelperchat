<?php

if ($Params['user_parameters_unordered']['sound'] !== null && is_numeric($Params['user_parameters_unordered']['sound'])) {
	erLhcoreClassModelUserSetting::setSetting('chat_message',(int)$Params['user_parameters_unordered']['sound'] == 1 ? 1 : 0);
}

// Perhaps it's direct argument
if ((string)$Params['user_parameters_unordered']['hash'] != '') {
	list($chatID,$hash) = explode('_',$Params['user_parameters_unordered']['hash']);

	// Redirect user
	erLhcoreClassModule::redirect('chat/chat/' . $chatID . '/' . $hash);
	exit;
}

$tpl = erLhcoreClassTemplate::getInstance( 'lhchat/startchat.tpl.php');
$tpl->set('referer','');
$tpl->set('referer_site','');

// Start chat field options
$startData = erLhcoreClassModelChatConfig::fetch('start_chat_data');
$startDataFields = (array)$startData->data;

// Input fields holder
$inputData = new stdClass();
$inputData->username = isset($_GET['prefill']['username']) ? (string)$_GET['prefill']['username'] : '';
$inputData->question = '';
$inputData->email = isset($_GET['prefill']['email']) ? (string)$_GET['prefill']['email'] : '';
$inputData->phone = isset($_GET['prefill']['phone']) ? (string)$_GET['prefill']['phone'] : '';
$inputData->departament_id = (int)$Params['user_parameters_unordered']['department'];
$inputData->priority = is_numeric($Params['user_parameters_unordered']['priority']) ? (int)$Params['user_parameters_unordered']['priority'] : false;
$inputData->validate_start_chat = true;
$inputData->name_items = array();
$inputData->value_items = array();
$inputData->value_types = array();
$inputData->value_sizes = array();
$inputData->hash_resume = false;
$inputData->vid = false;
$inputData->accept_tos = false;

// Perhaps it's direct argument
if ((string)$Params['user_parameters_unordered']['hash_resume'] != '') {
	CSCacheAPC::getMem()->setSession('chat_hash_widget_resume',(string)$Params['user_parameters_unordered']['hash_resume'],true,true);
	$inputData->hash_resume = (string)$Params['user_parameters_unordered']['hash_resume'];
}

if ((string)$Params['user_parameters_unordered']['vid'] != '') {
	$inputData->vid = (string)$Params['user_parameters_unordered']['vid'];
}



$chat = new erLhcoreClassModelChat();

// Assign department instantly
if ($inputData->departament_id > 0) {
	$chat->dep_id = $inputData->departament_id;
}

$leaveamessage = ((string)$Params['user_parameters_unordered']['leaveamessage'] == 'true' || (isset($startDataFields['force_leave_a_message']) && $startDataFields['force_leave_a_message'] == true)) ? true : false;

$additionalParams = array();
if ((string)$Params['user_parameters_unordered']['offline'] == 'true' && $leaveamessage == true) {
	$additionalParams['offline'] = true;
}

$tpl->set('leaveamessage',$leaveamessage);

// Department functionality
$department = (int)$Params['user_parameters_unordered']['department'] > 0 ? (int)$Params['user_parameters_unordered']['department'] : false;
$tpl->set('department',$department);

if (isset($_POST['StartChat'])) {
   // Validate post data
   $Errors = erLhcoreClassChatValidator::validateStartChat($inputData,$startDataFields,$chat,$additionalParams);

   if (count($Errors) == 0)
   {
   		$chat->setIP();
   		erLhcoreClassModelChat::detectLocation($chat);
   		
   		if (isset($additionalParams['offline']) && $additionalParams['offline'] == true) {
	   		erLhcoreClassChatMail::sendMailRequest($inputData,$chat);
	   		$tpl->set('request_send',true);
	   	} else {
	       $chat->time = time();
	       $chat->status = 0;
	       
	       $chat->hash = erLhcoreClassChat::generateHash();
	       $chat->referrer = isset($_POST['URLRefer']) ? $_POST['URLRefer'] : '';
	       $chat->session_referrer = isset($_POST['r']) ? $_POST['r'] : '';

	       if ( empty($chat->nick) ) {
	           $chat->nick = erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Visitor');
	       }

	       // Store chat
	       $chat->saveThis();

	       // Assign chat to user
	       if ( erLhcoreClassModelChatConfig::fetch('track_online_visitors')->current_value == 1 && (string)$Params['user_parameters_unordered']['vid'] != '') {
	            // To track online users
	            $userInstance = erLhcoreClassModelChatOnlineUser::handleRequest(array('vid' => (string)$Params['user_parameters_unordered']['vid']));

	            if ($userInstance !== false) {
	                $userInstance->chat_id = $chat->id;
	                $userInstance->dep_id = $chat->dep_id;
	                $userInstance->message_seen = 1;
	                $userInstance->message_seen_ts = time();
	                $userInstance->saveThis();

	                $chat->online_user_id = $userInstance->id;

	                if ( erLhcoreClassModelChatConfig::fetch('track_footprint')->current_value == 1) {
		            	erLhcoreClassModelChatOnlineUserFootprint::assignChatToPageviews($userInstance);
		            }
	            }
	       }

	       // Store message if required
	       if (isset($startDataFields['message_visible_in_popup']) && $startDataFields['message_visible_in_popup'] == true) {
	           if ( $inputData->question != '' ) {
	               // Store question as message
	               $msg = new erLhcoreClassModelmsg();
	               $msg->msg = trim($inputData->question);
	               $msg->chat_id = $chat->id;
	               $msg->user_id = 0;
	               $msg->time = time();
	               erLhcoreClassChat::getSession()->save($msg);

	               $chat->last_msg_id = $msg->id;
	               $chat->saveThis();
	           }
	       }

	       // Auto responder
	       $responder = erLhAbstractModelAutoResponder::processAutoResponder();

	       if ($responder instanceof erLhAbstractModelAutoResponder) {
		       	$chat->wait_timeout = $responder->wait_timeout;
		       	$chat->timeout_message = $responder->timeout_message;

		       	if ($responder->wait_message != '') {
		       		$msg = new erLhcoreClassModelmsg();
		       		$msg->msg = trim($responder->wait_message);
		       		$msg->chat_id = $chat->id;
		       		$msg->name_support = erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Live Support');
		       		$msg->user_id = 1;
		       		$msg->time = time()+5;
		       		erLhcoreClassChat::getSession()->save($msg);

		       		if ($chat->last_msg_id < $msg->id) {
		       			$chat->last_msg_id = $msg->id;
		       		}
		       	}

		       	$chat->saveThis();
	       }


	       // Redirect user
	       erLhcoreClassModule::redirect('chat/chat/' . $chat->id . '/' . $chat->hash);
	       exit;
	   	}
    } else {
        $tpl->set('errors',$Errors);
    }
}

$tpl->set('start_data_fields',$startDataFields);

$definition = array(
		'name'  => new ezcInputFormDefinitionElement(
				ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw',
				null,
				FILTER_REQUIRE_ARRAY
		),
		'value' => new ezcInputFormDefinitionElement(
				ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw',
				null,
				FILTER_REQUIRE_ARRAY
		),
		'type' => new ezcInputFormDefinitionElement(
				ezcInputFormDefinitionElement::OPTIONAL, 'string',
				null,
				FILTER_REQUIRE_ARRAY
		),
		'size' => new ezcInputFormDefinitionElement(
				ezcInputFormDefinitionElement::OPTIONAL, 'string',
				null,
				FILTER_REQUIRE_ARRAY
		),
		'req' => new ezcInputFormDefinitionElement(
				ezcInputFormDefinitionElement::OPTIONAL, 'string',
				null,
				FILTER_REQUIRE_ARRAY
		),
		'sh' => new ezcInputFormDefinitionElement(
				ezcInputFormDefinitionElement::OPTIONAL, 'string',
				null,
				FILTER_REQUIRE_ARRAY
		)
);

$form = new ezcInputForm( INPUT_GET, $definition );

if ( $form->hasValidData( 'name' ) && !empty($form->name))
{
	$inputData->name_items = $form->name;
}

if ( $form->hasValidData( 'sh' ) && !empty($form->sh))
{
	$inputData->value_show = $form->sh;
}

if ( $form->hasValidData( 'req' ) && !empty($form->req))
{
	$inputData->values_req = $form->req;
}

if ( $form->hasValidData( 'value' ) && !empty($form->value))
{
	$inputData->value_items = $form->value;
}

if ( $form->hasValidData( 'type' ) && !empty($form->type))
{
	$inputData->value_types = $form->type;
}

if ( $form->hasValidData( 'size' ) && !empty($form->size))
{
	$inputData->value_sizes = $form->size;
}

$tpl->set('input_data',$inputData);

if (isset($_GET['URLReferer']))
{
    $tpl->set('referer',$_GET['URLReferer']);
}

if (isset($_POST['URLRefer']))
{
    $tpl->set('referer',$_POST['URLRefer']);
}

if (isset($_GET['r']))
{
	$tpl->set('referer_site',$_GET['r']);
}

if (isset($_POST['r']))
{
	$tpl->set('referer_site',$_POST['r']);
}

$Result['content'] = $tpl->fetch();
$Result['pagelayout'] = 'userchat';

$Result['path'] = array(array('title' => erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Fill in the form to start a chat')))

?>