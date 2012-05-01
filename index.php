<?php
/*
Modified by Adam Paquette
Script d'origine : <https://github.com/joshdick/miniProxy>

TODO:
    Gérer les cookies
    Serveur HTTPS
    Activer/Désactiver la gestion javascript/CSS/images/flash
    Gérer tout les types de header javascript
*/

session_start();

define("URL_PARAM", "url");
define("PROXY_PREFIX", "http://".$_SERVER['SERVER_NAME'].":".$_SERVER['SERVER_PORT'].$_SERVER['PHP_SELF']."?".URL_PARAM."=");

$url = empty($_GET[URL_PARAM]) ? null : $_GET[URL_PARAM];

//Doit se connecter et avoir entré une URL pour utiliser le proxy
if((!empty($_SESSION['MODE']) || (!empty($_GET['MODE']) && $_GET['MODE'] == 'UNLOCKED')) && $url!=null)
    $_SESSION['MODE'] = 'UNLOCKED';
else
    exit;

//Retrieves contents and HTTP headers of a URL with cURL.
function getFile($fileLoc)
{
    if(!function_exists("curl_init")) die ("Install PHP's cURL extension.");

    //Sends user-agent of actual browser being used--unless there isn't one.
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    if(empty($user_agent)) 
        $user_agent = "Mozilla/5.0";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    //If data was POSTed to the proxy, re-POST the data to the requested URL
    $postData = '';
    foreach($_POST as $key => $value) 
        $postData .= $key."=".$value.'&';
    $postData = substr($postData, 0, strlen($postData)-1);
    
    if(strlen($postData) > 0) 
    {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    
    //If data was GETed to the proxy, re-GET the data to the requested URL
    $getData = '';
    foreach($_GET as $key => $value) 
    {
        if ($key == URL_PARAM) continue; //Strip out any data that was added when proxifying GET forms
        $getData .= urlencode($key)."=".urlencode($value)."&";
    }
    $getData = substr($getData, 0, strlen($getData)-1);
    
    if(strlen($getData) > 0) 
        $fileLoc .= "?".$getData;
    
    curl_setopt($ch, CURLOPT_URL, $fileLoc);
    $data = curl_exec($ch);
    
    $responseInfo = curl_getinfo($ch);
    if($responseInfo['http_code'] != 200)
        $data = "Error: Server at " . $fileLoc . " sent HTTP response code " . $responseInfo['http_code'] . ".";
    
    curl_close($ch);
    return array("data" => $data, "contentType" => $responseInfo['content_type']);
}

//Converts relative URLs to absolute ones, given a base URL.
//Modified version of code Found at http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
function rel2abs($rel, $base)
{
    if(empty($rel)) 
        $rel = ".";
    
    //Return if already an absolute URL
    if(parse_url($rel, PHP_URL_SCHEME) != "" || strpos($rel, "//") === 0) 
        return $rel; 
    
    //Queries and anchors
    if($rel[0] == "#" || $rel[0] == "?") 
        return $base.$rel; 
        
    extract(parse_url($base)); //Parse base URL and convert to local variables: $scheme, $host, $path
    $path = isset($path) ? preg_replace('#/[^/]*$#', "", $path) : "/"; //Remove non-directory element from path
    
    //Destroy path if relative url points to root
    if($rel[0] == '/') 
        $path = ""; 
    
    $abs = "$host$path/$rel"; //Dirty absolute URL
    for($n = 1; $n > 0; $abs = preg_replace(array("#(/\.?/)#", "#/(?!\.\.)[^/]+/\.\./#"), "/", $abs, -1, $n)) {} //Replace '//' or '/./' or '/foo/../' with '/'
    return $scheme . "://" . $abs; //Absolute URL is ready.
}

//Proxify contents of url() references in blocks of CSS text.
function proxifyCSS($css, $baseURL) 
{
    return preg_replace_callback
    (
        '/url\((.*?)\)/i',
        function($matches) use ($baseURL) 
        {
            $url = $matches[1];
            //Remove any surrounding single or double quotes from the URL so it can be passed to rel2abs - the quotes are optional in CSS
            //Assume that if there is a leading quote then there should be a trailing quote, so just use trim() to remove them
            if(strpos($url, "'") === 0) 
              $url = trim($url, "'");
            
            if(strpos($url, "\"") === 0) 
              $url = trim($url, "\"");
            
            //The URL isn't an HTTP URL but is actual binary data. Don't proxify it.
            if(stripos($url, "data:") === 0) 
                return "url(".$url.")"; 
            
            return "url(".PROXY_PREFIX.rel2abs($url, $baseURL).")";
        },
        $css
    );
}

//Proxify path to files in the javascript
function proxifyJS($js, $baseURL) 
{
    return preg_replace_callback
    (
        '#([\'"])([^\'"]*[\.](php|txt|xml))[\'"]#i',  // 'ex.txt' or "a.php" or 'a.php"
        function($matches) use ($baseURL) 
        {
            $path = $matches[2];
            return "'".PROXY_PREFIX.rel2abs($path, $baseURL)."'";
        },
        $js
    );
}

//Assume that any supplied URLs starting with // are HTTP URLs.
if(strpos($url, "//") === 0) 
    $url = "http:".$url; 

//Assume that any supplied URLs without a scheme are HTTP URLs.
if(!preg_match("@^.*://@", $url)) 
    $url = "http://".$url; 

$file = getFile($url);
header("Content-Type: ".$file["contentType"]);

//This is a web page, so proxify the DOM.
if(stripos($file["contentType"], "text/html") !== false) 
{ 
    $doc = new DomDocument();
    @$doc->loadHTML(utf8_decode($file["data"]));
    $xpath = new DOMXPath($doc);

    //Rewrite forms so that their actions point back to the proxy.
    foreach($xpath->query('//form') as $form) 
    {
        $method = $form->getAttribute("method");
        $action = $form->getAttribute("action");
        
        //If the form doesn't have an action, the action is the page itself. 
        //Otherwise, change an existing action to an absolute version.
        $action = empty($action) ? $url : rel2abs($action, $url); 
        
        //This is a GET form
        if(empty($method) || strtolower($method) != "post") 
        { 
            //Wipe out the form action in the DOM, forcing the form to submit back to the proxy
            $form->setAttribute("action", "");
            
            //Add a hidden form field containing the original form action, 
            //so the proxy knows where to make the request
            $proxyField = $doc->createElement("input");
            $proxyField->setAttribute("type", "hidden");
            $proxyField->setAttribute("name", URL_PARAM);
            $proxyField->setAttribute("value", $action);
            $form->appendChild($proxyField);
        } 
        else 
        {   //This is a POST form, so change its action to a proxified version.
            $action = preg_replace("/\?/", "&", $action, 1); //Replace any leftmost question mark with an ampersand to blend an existing query string into the proxified URL
            $form->setAttribute("action", PROXY_PREFIX.$action);
        }
    }
    
    //Profixy <scritp> tags
    foreach($xpath->query('//script') as $script) 
        $script->nodeValue = proxifyJs($script->nodeValue, $url);
    
    //Profixy <style> tags
    foreach($xpath->query('//style') as $style) 
        $style->nodeValue = proxifyCSS($style->nodeValue, $url);
  
    //Proxify tags with a style attribute
    foreach ($xpath->query('//*[@style]') as $element) 
        $element->setAttribute("style", proxifyCSS($element->getAttribute("style"), $url));

    //Proxify any of these attributes appearing in any tag.
    $proxifyAttributes = array("href", "src");
    foreach($proxifyAttributes as $attrName) 
    {
        //For every element with the given attribute...
        foreach($xpath->query('//*[@'.$attrName.']') as $element) 
        {
            $attrContent = $element->getAttribute($attrName);
            if($attrName == "href" && (stripos($attrContent, "javascript:") === 0 || stripos($attrContent, "mailto:") === 0)) continue;
            $attrContent = rel2abs($attrContent, $url);
            //Replace any leftmost question mark with an ampersand to blend an existing query string into the proxified URL
            $attrContent = preg_replace("/\?/", "&", $attrContent, 1);
            $attrContent = PROXY_PREFIX.$attrContent;
            $element->setAttribute($attrName, $attrContent);
        }
    }
    echo $doc->saveHTML();
}
//This is CSS, so proxify url() references.
else if(stripos($file["contentType"], "text/css") !== false) 
{ 
    echo proxifyCSS($file["data"], $url);
} 
//Proxify javascript
else if(stripos($file["contentType"], "javascript") !== false) 
{
    echo proxifyJS($file["data"], $url);
    
    /*$fp = fopen('datajs.txt', 'a');
    fwrite($fp, "TYPE DATA : ".$file["data"]."\r\n\r\n\r\n\r\n\r\n\r\n");
    fclose($fp);*/
}
else //Serve unmodified through the proxy with the correct headers (images...)
{ 
    header("Content-Length: ".strlen($file["data"]));
    echo $file["data"];
    
    /*$fp = fopen('data.txt', 'a');
    fwrite($fp, "TYPE DATA : ".$file["contentType"]."\r\n");
    fclose($fp);*/
}



?>