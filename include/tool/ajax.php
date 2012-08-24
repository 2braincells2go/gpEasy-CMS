<?php
defined('is_running') or die('Not an entry point...');

//sleep(3); //for testing

class gpAjax{

	function ReplaceContent($id,$content){
		gpAjax::JavascriptCall('WBx.response','replace',$id,$content);
	}

	function JavascriptCall(){
		$args = func_get_args();
		if( !isset($args[0]) ){
			return;
		}

		echo array_shift($args);
		echo '(';
		$comma = '';
		foreach($args as $arg){
			echo $comma;
			echo gpAjax::quote($arg);
			$comma = ',';
		}
		echo ');';
	}

	function quote(&$content){
		static $search = array('\\','"',"\n","\r",'<script','</script>');
		static $repl = array('\\\\','\"','\n','\r','<"+"script','<"+"/script>');

		return '"'.str_replace($search,$repl,$content).'"';
	}

	function JsonEval($content){
		echo '{DO:"eval"';
		echo ',CONTENT:';
		echo gpAjax::quote($content);
		echo '},';
	}

	function JsonDo($do,$selector,&$content){
		static $comma = '';
		echo $comma;
		echo '{DO:';
		echo gpAjax::quote($do);
		echo ',SELECTOR:';
		echo gpAjax::quote($selector);
		echo ',CONTENT:';
		echo gpAjax::quote($content);
		echo '}';
		$comma = ',';
	}


	/**
	 * Handle HTTP responses made with $_REQUEST['req'] = json (when <a ... name="gpajax">)
	 * Sends JSON object to client
	 *
	 */
	function Response(){
		global $page;

		if( !is_array($page->ajaxReplace) ){
			die();
		}

		//gadgets may be using gpajax/json request/responses
		gpOutput::TemplateSettings();
		gpOutput::PrepGadgetContent();


		echo gpAjax::Callback($_REQUEST['jsoncallback']);
		echo '([';

		//output content
		if( !empty($_REQUEST['gpx_content']) ){
			switch($_REQUEST['gpx_content']){
				case 'gpabox':
					gpAjax::JsonDo('admin_box_data','',$page->contentBuffer);
				break;
			}
		}elseif( in_array('#gpx_content',$page->ajaxReplace) ){
			$replace_id = '#gpx_content';

			if( isset($_GET['gpreqarea']) ){
				$replace_id = '#'.$_GET['gpreqarea'];
			}

			ob_start();
			$page->GetGpxContent();
			$content = ob_get_clean();
			gpAjax::JsonDo('replace',$replace_id,$content);
		}

		//other areas
		foreach($page->ajaxReplace as $arguments){
			if( is_array($arguments) ){
				$arguments += array(0=>'',1=>'',2=>'');
				gpAjax::JsonDo($arguments[0],$arguments[1],$arguments[2]);
			}
		}

		//always send messages
		ob_start();
		GetMessages();
		$content = ob_get_clean();
		if( !empty($content) ){
			gpAjax::JsonDo('messages','',$content);
		}

		echo ']);';
		die();
	}



	/**
	 * Check the callback parameter, die with an alert if the test fails
	 *
	 */
	function Callback($callback){
		if( !preg_match('#^[a-zA-Z0-9_]+$#',$callback) ){
			die('alert("Invalid Callback");');
		}
		return $callback;
	}

	function InlineEdit($section_data){
		global $dataDir,$dirPrefix;

		$section_data += array('type'=>'','content'=>'');

		header('Content-type: application/x-javascript');

		$type = $section_data['type'];

		$scripts = array();
		$scripts[] = '/include/js/inline_edit/inline_editing.js';


		$type = 'text';
		if( !empty($section_data['type']) ){
			$type = $section_data['type'];
		}
		switch($section_data['type']){

			case 'gallery':
				$scripts = gpAjax::InlineEdit_Gallery($scripts);
			break;

			case 'include':
				$scripts = gpAjax::InlineEdit_Include($scripts);
			break;

			case 'text';
				$scripts = gpAjax::InlineEdit_Text($scripts);
			break;
		}

		$scripts = gpPlugin::Filter('InlineEdit_Scripts',array($scripts,$type));
		$scripts = array_unique($scripts);

		//send all scripts
		foreach($scripts as $script){

			//absolute paths don't need $dataDir
			$full_path = $script;
			if( strpos($script,$dataDir) !== 0 ){

				//fix addon paths that use $addonRelativeCode
				if( !empty($dirPrefix) && strpos($script,$dirPrefix) === 0 ){
					$script = substr($script,strlen($dirPrefix));
				}
				$full_path = $dataDir.$script;
			}

			if( !file_exists($full_path) ){
				echo 'if(isadmin){alert("Admin Notice: The following file could not be found: \n\n'.addslashes($full_path).'");}';
				continue;
			}

			echo ';';
			//echo "\n/**\n* $script\n*\n*/\n";
			readfile($full_path);
		}

		//replace resized images with their originals
		if( is_array($section_data['resized_imgs']) && count($section_data['resized_imgs']) ){
			includeFile('tool/editing.php');
			$section_data['content'] = gp_edit::RestoreImages($section_data['content'],$section_data['resized_imgs']);
		}

		//create the section object that will be passed to gp_init_inline_edit
		$section_object = common::JsonEncode($section_data);


		//send call to gp_init_inline_edit()
		echo ';if( typeof(gp_init_inline_edit) == "function" ){';
		echo 'gp_init_inline_edit(';
		echo gpAjax::quote($_GET['area_id']);
		echo ','.$section_object;
		echo ');';
		echo '}else{alert("gp_init_inline_edit() is not defined");}';
	}

	function InlineEdit_Text($scripts){
		includeFile('tool/editing.php');

		//autocomplete
		echo gp_edit::AutoCompleteValues(true);

		//ckeditor basepath and configuration
		$ckeditor_basepath = common::GetDir('/include/thirdparty/ckeditor_34/');
		echo 'CKEDITOR_BASEPATH = '.gpAjax::quote($ckeditor_basepath).';';
		echo 'var gp_ckconfig = {};';

		//gp_ckconfig options
		$options = array();
		echo gp_edit::CKConfig($options,'gp_ckconfig');

		$scripts[] = '/include/thirdparty/ckeditor_34/ckeditor.js';
		$scripts[] = '/include/js/inline_edit/inlineck.js';

		return $scripts;
	}

	function InlineEdit_Include($scripts){
		$scripts[] = '/include/js/inline_edit/include_edit.js';
		return $scripts;
	}

	function InlineEdit_Gallery($scripts){
		$scripts[] = '/include/js/inline_edit/gallery_edit_202.js';
		$scripts[] = '/include/js/jquery.auto_upload.js';
		return $scripts;
	}

}