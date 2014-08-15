<?
class GoogleDocsPage extends Page {

	public static $db = array(
	  'GoogleDocID' => "Varchar(1000)",
	  'ImportURL' => "Varchar(1000)",
	);	

	static $has_many = array(
		'Imports' => 'GoogleDocsPage_Import'
	);
	
	static $gdoc_pub_urlbase = "http://docs.google.com/document/pub?id=";	

	function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->addFieldToTab('Root.Content.Main', new TextField("ImportURL", "Import URL (paste the gDocs Import url here)"),"Title");
		$fields->addFieldToTab('Root.Content.Main', new LiteralField("doImport", '<a href="' . $this->Link() . '?flush=1" target="import">IMPORT</a>'),"Title");
		$fields->addFieldToTab('Root.Content.Main', new TextField("GoogleDocID", "Google Doc ID"),"Title");
		
		return $fields;
	}	
	
	function LatestImport(){
		$import = DataObject::get_one("GoogleDocsPage_Import","PageID=" . $this->ID, NULL,"Created DESC");
		//var_dump($import);
		
		if (!$import || !$import->exists()) {
			$import = GoogleDocsPage_Import::import($this);
		}
		
		return $import;
	}
	
	static $import_msg = "";
	function ImportMsg(){
		if (Permission::check("ADMIN")) {
			return GoogleDocsPage::$import_msg;
		}
	}
	
	
}
 
class GoogleDocsPage_Controller extends Page_Controller {

	function init(){
		parent::init();
		if (isset($_GET["flush"])) {
			//echo "flushing";
			if (Permission::check("ADMIN")) {
				$msg = GoogleDocsPage_Import::import($this);
				GoogleDocsPage::$import_msg = $msg;
			}
		} else {
			//echo "not flushing";
		}
	}

	//for development
	/*
	function import(){
		GoogleDocsPage_Import::import($this);
	}
	*/


 
}
 

class GoogleDocsPage_Import extends DataObject {
	static $db = array(
	  //'Imported'    => 'SS_Datetime', //no need for it, the created field is sufficient
	  'Content' => 'HTMLText',
	  'Css' => 'Text',
	  'CssParsed' => 'Text',
	);

	static $has_one = array(
		'Page' => 'GoogleDocsPage'
	);
	
	static function import($page) {	
		include_once('../googledocspage/libs/simplehtmldom/simple_html_dom.php');	
		
		//if import url is set, use that, else fall back on google doc id
		if (strlen($page->ImportURL)  > 1) {
			$url = $page->ImportURL;
		} else {
			$url = GoogleDocsPage::$gdoc_pub_urlbase  . $page->GoogleDocID;
		}
		//echo $url;
		$html = file_get_html($url);
		//$contents = $html->find('div[id="contents"]', 0)->innertext;
		$contents = $html->find('div[id="contents"]', 0);

		// remove h1
		//var_dump($contents->find('h1'));
		if (isset($contents)) {
			foreach($contents->find('h1') as $e) {
				$e->outertext = '';
			}
		} else {
			return "Error retrieving document. <br /> Try visiting this URL: <br /><br /><a href=\"$url\">$url</a>";
		}

		// save style
		$style = "";
		foreach($contents->find('style') as $e)
			$style = $e->innertext;
			$e->outertext = '';

		


		//changing img path
		$i = 1;
		foreach($html->find('img') as $e) {
			if ($i < 99) {
				//echo $e->src . "<br />";
				//$e->outertext = '';
				$e->src = "http://docs.google.com/document/" . $e->src;

				//var_dump($page->PageID);
	
				$folderPath = 'import/' . $page->ID;
				
				//var_dump($folderPath);
				
				$folder = Folder::findOrMake($folderPath);
	
				//$tempFileName = $i . ".png";
				$tempFileName = $i;
				$filepath = "assets/" . $folderPath . "/" . $tempFileName;
				
				$src = str_replace("amp;","",$e->src);
				$img = file_get_contents($src); 
				//$size = getimagesize($img);
				//var_dump($img);
				
				$file = File::find($filepath);
				if (!$file) {
					$file = new File();
					$file->Filename = $filepath;
				}
				file_put_contents(Director::baseFolder() . "/" . $filepath, $img);
				//$file->Name = $a["FileName"];
				//$file->setName($tempFileName);
				$file->write();
				$file->setName($i);
				
				$file->setParentID($folder->ID);
				
				//$file->setName($filepath);
				$file->ClassName = "Image";
				$file->write();
				
				$e->src = "/" . $filepath;

			}
			$i = $i + 1;

		}
	
		
		//echo '<style>.c2 { font-weight:bold;}</style>';
		//echo $contents->innertext;
		//echo "importing";
	
		
		$import = new GoogleDocsPage_Import();
		//$import->Imported = date("Y-m-d H:i:s");
		$import->Content = $contents->innertext;
		$import->Css = $style;
		$import->CssParsed = GoogleDocsPage_Import::parsecss($style);;
		$import->PageID = $page->ID;
		$import->write();
		
		//this is not neccessary, as it is done already be referencing the PageID
		//$pageimports = $page->Imports();
		//$pageimports->add($import);
		
		
		//writing content to the page
		//making sure the "real" page object is being used		
		$page = SiteTree::get_by_id("Page",$page->ID);
		
		$page->Content = $import->Content; 
		$page->writeToStage('Stage');
		$page->Publish('Stage', 'Live');
		$page->Status = "Published";
		$page->flushCache();
		
		return "import successful";
		//return $import;	
	}

	static function parsecss($cssstr) {
		include_once('../googledocspage/libs/csstidy-1.3/class.csstidy.php');
		
		$cssObj = new csstidy();
		$cssObj->parse($cssstr);		
		
		$cssarrOuter = $cssObj->css;

		$str = "";

		/*
		ob_start();
		echo "<pre>";
		*/
		
		foreach ($cssarrOuter as $arr) {
			$cssarr = $arr;
		}
		//var_dump($cssarr);
		
		//checking if the .cX classes exist
		$i = 0;
		while ($i <= 10) {
			$class = ".c" . $i;
			//echo $class . "\n";
			if (isset($cssarr[$class])) {
				//echo "test";
				foreach ($cssarr[$class] as $item => $value) {
					$tmp = "";
					if ($item == "font-weight") {
						$tmp .= $item . ": " . $value . "; ";	
					}
				}
				$str .= $class . " {" . $tmp . "} \n";
			}  
			$i++;
		}		
		
		/*
		echo $str;
		echo "</pre>";
		
		$var = ob_get_contents();
		ob_clean();
		*/
		
		return $str;
	}
		
	
}