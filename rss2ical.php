<?php

/* for debugging only!! * /
error_reporting(E_ALL);
ini_set('display_errors', TRUE);
/**/

ob_start();
header("Expires: " . gmdate("D, d M Y H:i:s", 0) . " GMT"); 

/* Default settings, you may change them at your whim.  See README. */
$rss_cache_path         = '';
$rss_default_cache_time = 180;
$rss_debug_mode         = true;

/* Private variables, do not change. */
$rss_contents           = array();
$rss_cache_age          = 0;
$rss_tag                = '';
$rss_isItem             = false;
$rss_isChannel          = false;
$rss_isImage            = false;
$rss_isTextInput        = false;
$rss_index              = 0;

function stream_last_modified($url) {
    if (function_exists('version_compare') && version_compare(phpversion(), '4.3.0') > 0) {
        if (!($fp = @fopen($url, 'r')))
            return NULL;

        $meta = stream_get_meta_data($fp);
        for ($j = 0; isset($meta['wrapper_data'][$j]); $j++) {
            if (strstr(strtolower($meta['wrapper_data'][$j]), 'last-modified')) {
                $modtime = substr($meta['wrapper_data'][$j], 15);
                break;
            }
        }
        fclose($fp);
    }
    else {
        $parts = parse_url($url);
        $host  = $parts['host'];
        $path  = $parts['path'];

        if (!($fp = @fsockopen($host, 80)))
            return NULL;

        $req = "HEAD $path HTTP/1.0\r\nUser-Agent: PHP/".phpversion()."\r\nHost: $host:80\r\nAccept: */*\r\n\r\n";
        fputs($fp, $req);

        while (!feof($fp)) {
            $str = fgets($fp, 4096);
            if (strstr(strtolower($str), 'last-modified')) {
                $modtime = substr($str, 15);
                break;
            }
        }
        fclose($fp);
    }
    return isset($modtime) ? strtotime($modtime) : time();
}

function parseRSS($url, $cache_file=NULL, $cache_time=NULL) {
    global   $rss_contents, $rss_default_cache_time, $rss_isTextInput,
         $rss_cache_path, $rss_cache_age, $rss_tag, $rss_isImage,
         $rss_isItem, $rss_isChannel, $rss_index, $rss_debug_mode;

    $rss_error = '<br /><strong>Error on line %s of '.__FILE__.'</strong>: %s<br />';

    if (!function_exists('xml_parser_create')) {
        if ($rss_debug_mode)
            printf($rss_error, (__LINE__-3), '<a href="http://www.php.net/manual/en/ref.xml.php">PHP\'s XML Extension</a> is not loaded or available.');

        return false;
    }

    $rss_contents = array();

    if (!is_null($cache_file)) {
        if (!isset($rss_cache_path) || !strlen($rss_cache_path))
            $rss_cache_path = dirname(__FILE__);

        $cache_file = str_replace('//', '/', $rss_cache_path.'/'.$cache_file);

        if (is_null($cache_time))
            $cache_time = $rss_default_cache_time;

        $rss_cache_age = file_exists($cache_file) ? ceil((time() - filemtime($cache_file)) / 60) : 0;
        $remotemodtime = stream_last_modified($url);
        if (is_null($remotemodtime)) {
            if ($rss_debug_mode)
                printf($rss_error, (__LINE__-4), 'Could not connect to remote RSS file ('.$url.').');

            return false;
        }
    }

    if (is_null($cache_file) ||
        (!is_null($cache_file) && !file_exists($cache_file)) ||
        (!is_null($cache_file) && file_exists($cache_file) && $rss_cache_age > $cache_time && $remotemodtime > ((time()) - ($rss_cache_age * 60)))
    ) {
        $rss_tag       = '';
        $rss_isItem    = false;
        $rss_isChannel = false;
        $rss_index     = 0;

        $saxparser = @xml_parser_create();
        if (!is_resource($saxparser)) {
            if ($rss_debug_mode)
                printf($rss_error, (__LINE__-4), 'Could not create an instance of <a href="http://www.php.net/manual/en/ref.xml.php">PHP\'s XML parser</a>.');

            return false;
        }

        xml_parser_set_option($saxparser, XML_OPTION_CASE_FOLDING, false);
        xml_set_element_handler($saxparser, 'sax_start', 'sax_end');
        xml_set_character_data_handler($saxparser, 'sax_data');

        if (!($fp = @fopen($url, 'r'))) {
            if ($rss_debug_mode)
                printf($rss_error, (__LINE__-3), 'Could not connect to remote RSS file ('.$url.').');

            return false;
        }

        while ($data = fread($fp, 4096)) {
            $parsedOkay = xml_parse($saxparser, $data, feof($fp));

            if (!$parsedOkay && xml_get_error_code($saxparser) != XML_ERROR_NONE) {
                if ($rss_debug_mode)
                    printf($rss_error, (__LINE__-3), 'File has an XML error (<em>'.xml_error_string(xml_get_error_code($saxparser)).'</em> at line <em>'.xml_get_current_line_number($saxparser).'</em>).');

                return false;
            }
        }

        xml_parser_free($saxparser);
        fclose($fp);

        if (!is_null($cache_file)) {
            if (!($cache = @fopen($cache_file, 'w'))) {
                if ($rss_debug_mode)
                    printf($rss_error, (__LINE__-3), 'Could not right to cache file (<em>'.$cache_file.'</em>). The path may be invalid or you may not have write permissions.');

                return false;
            }

            fwrite($cache, serialize($rss_contents));
            fclose($cache);
        }
    } else {
        if (!($fp = @fopen($cache_file, 'r'))) {
            if ($rss_debug_mode)
                printf($rss_error, (__LINE__-3), 'Could not read contents of cache file (<em>'.$cache_file.'</em>).');

            return false;
        }

        $rss_contents = unserialize(fread($fp, filesize($cache_file)));
        fclose($fp);
    }

    return $rss_contents;
}

function sax_start($parser, $name, $attribs) {
    global $rss_tag, $rss_isItem, $rss_isChannel, $rss_isImage, $rss_index, $rss_isTextInput;

    $rss_tag = $name = strtolower($name);

    if ($name == 'channel') {
        $rss_isChannel = true;
        $rss_isImage = false;
        $rss_isItem = false;
    } elseif ($name == 'image') {
        $rss_isChannel = false;
        $rss_isImage = true;
        $rss_isItem = false;
    } elseif ($name == 'item') {
        $rss_index++;
        $rss_isChannel = false;
        $rss_isImage = false;
        $rss_isItem = true;
    } elseif ($name == 'textinput') {
        $rss_isChannel = false;
        $rss_isImage = false;
        $rss_isItem = false;
        $rss_isTextInput = true;
    }
}

function sax_end($parser, $name){}

function sax_data($parser, $data) {
    global $rss_tag, $rss_isItem, $rss_isChannel, $rss_contents, $rss_isTextInput, $rss_isImage, $rss_index;
    if ($data != "\n") {
        if ($rss_isChannel && !$rss_isItem && strlen($data))
            (!isset($rss_contents['channel'][$rss_tag]) || !strlen($rss_contents['channel'][$rss_tag])) ?
                $rss_contents['channel'][$rss_tag] = $data :
                $rss_contents['channel'][$rss_tag].= $data ;
        elseif ($rss_isItem && strlen($data))
            (!isset($rss_contents[$rss_index-1][$rss_tag]) || !strlen($rss_contents[$rss_index-1][$rss_tag])) ? 
                $rss_contents[$rss_index-1][$rss_tag] = $data :
                $rss_contents[$rss_index-1][$rss_tag].= $data ;
        elseif ($rss_isImage && strlen($data))
            (!isset($rss_contents['image'][$rss_tag]) || !strlen($rss_contents['image'][$rss_tag])) ?
                $rss_contents['image'][$rss_tag] = $data :
                $rss_contents['image'][$rss_tag].= $data ;
        elseif ($rss_isTextInput && strlen($data))
            (!isset($rss_contents['textinput'][$rss_tag]) || !strlen($rss_contents['textinput'][$rss_tag])) ?
                $rss_contents['textinput'][$rss_tag] = $data :
                $rss_contents['textinput'][$rss_tag].= $data ;
    }
}

function getVar($varName, $varDefaultVal) {

    if (isset($_GET[$varName])) {
        $varVal = $_GET[$varName];
    }
    else
    {
        $varVal = $varDefaultVal;
    }

    return $varVal;
}

function pre_fix($strText) {
    $strResult = $strText;
    
    //$strResult = strip_tags($strResult);
    
    $trans_array = array();
    for ($i=127; $i<255; $i++) {
        $trans_array[chr($i)] = "&#" . $i . ";";
    }
    $strResult = strtr($strResult, $trans_array);
    
    $strResult = ltrim($strResult);
    $strResult = rtrim($strResult);
    
    return $strResult;
}

function fix($data) {

$data = pre_fix($data);

$patterns = array(
            '/\r/',
            '/\n/',

            '/<br \/>/',
            '/<\/p>/',
            '/<p.*?>/',
            
            '/(.*?)<a.*? href="(.*?http:\/\/.+?)".*?>(.*?)<\/a>(.*?)/',            
            
            '/<code.*?>(.*?)<\/code>/',
            '/<img.*?src=".*?".*?[\/]*>/',        
            
            '/<blockquote.*?cite="(http:\/\/.+?)".*?>(.*?)<\/blockquote>/',    
            '/<cite.*?>(.*?)<\/cite>/',
            
            '/<[ou]l*?>(.+?)<\/[ou]l>/',
            '/<li*?>(.+?)<\/li>/',

            '/<span.*?>(.*?)<\/span>/',
            '/<strong.*?>(.*?)<\/strong>/',
            '/<b.*?>(.*?)<\/b>/',
            '/<em.*?>(.*?)<\/em>/',
            '/<i.*?>(.*?)<\/i>/',
            '/,/',
            '/;/',

            '/&lt;/',
            '/&gt;/',
            '/&amp;/',
            '/&#821[67];/',
            '/&#822[01];/',
            '/Link/'
            );
            
$replace = array(
            '\\n',
            '\\n',
//br and p    
            "\\n",
            "\\n\\n",
            "",
//a element            
            //"\\1\\3 [link: \\2]\\4",
            "\\1\\3 [link]\\4",
//code
            "\\1",
//img
            "[img]\\n",
//cite=,cite    
            "\\n\\2[cite: \\1]\\n\\n",
            "[cite]\\1",
//ul/ol, li
            "\\n\\1",
            "* \\1\\n",

            "\\1",
            "\\1",
            "\\1",
            "\\1",
            "\\1",
            "\\,",
            "\\;",
            
            "<",
            ">",
            "&",
            "'",
            "\"",
            ""
            );

    $data = preg_replace($patterns, $replace, $data);

    $data = strip_tags($data);

    return $data;
}

$url = getVar("url", "");

if (strlen($url) == 0) {
    echo "<!DOCTYPE HTML>\n<HTML>\n";
    echo "<HEAD>\n";
    echo "<TITLE>RSS2iCal</TITLE>\n";
    echo "</HEAD>\n";
    echo "<BODY>\n";
    echo "View and/or Subscribe to RSS/RDF News Feeds in iCalendar (vCal 2.0) format.<P>\n";
    echo "Subscriptions have only been tested with Apple's iCal application.<P>\n";
    echo "Once you have subscribed to a news feed in Apple's iCal, iSync can be used to synchronize it with your iPod, Palm, or cell phone.<P>\n";
    echo "<ul>\n";
    echo '<li>code: <a href="https://github.com/s72785/rss2ical/" target="_blank">RSS2iCal on github</a></li>' . "\n";
    echo '<li>demo: <a href="http://www2.htw-dresden.de/~s72785/rss2ical.php" target="_blank">webservice for any RSS feed to be converted</a></li>' . "\n";
    echo '<li>docu: <a href="http://www2.htw-dresden.de/~s72785/stura.ical.php" target="_blank">the starting point</a> was a calendar at university seemed available as RSS only, and <a href="www.stura.htw-dresden.de/events/ics_view" target="_blank">in fact a hidden ics with similar issues</a>, <a href="http://tools.ietf.org/html/rfc5545" target="_blank">s.a. RFCfor iCal</a></li>' . "\n";
    echo "</ul>\n";
    echo "<HR>\n";
    echo "<FORM NAME=\"Form_View\" METHOD=GET ACTION=\"http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "\">\n";
    echo "RSS/RDF URL: <INPUT TYPE=TEXT SIZE=50 NAME=\"url\" VALUE=\"http://www.stura.htw-dresden.de/events/RSS\">\n";
    echo "<INPUT TYPE=HIDDEN NAME=\"format\" VALUE=\".ics\">\n";
    echo "<INPUT TYPE=SUBMIT VALUE=\"View\">\n";
    echo "<INPUT TYPE=BUTTON VALUE=\"Subscribe\" onClick=\"document.Form_Subscribe.url.value = document.Form_View.url.value; document.Form_Subscribe.submit();\">\n";
    echo "</FORM>\n";
    echo "<FORM NAME=\"Form_Subscribe\" METHOD=GET ACTION=\"webcal://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "\">\n";
    echo "<INPUT TYPE=HIDDEN SIZE=50 NAME=\"url\" VALUE=\"http://www.stura.htw-dresden.de/events/RSS\">\n";
    echo "<INPUT TYPE=HIDDEN NAME=\"format\" VALUE=\".ics\">\n";
    echo "</FORM>\n";
//    echo "Links to Common RSS Feeds Converted to iCal format and subscriptions to them:\n";
//    echo "<UL>\n";
//    echo "<LI><A HREF=\"http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://www.bbc.co.uk/syndication/feeds/news/ukfs_news/front_page/rss091.xml&format=*.ics\">BBC News | Front Page</A> [<A HREF=\"webcal://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://www.bbc.co.uk/syndication/feeds/news/ukfs_news/front_page/rss091.xml&format=*.ics\">subscribe</A>]</LI>\n";
//    echo "<LI><A HREF=\"http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://rss.com.com/2547-12-0-20.xml&format=*.ics\">CNET News.com</A> [<A HREF=\"webcal://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://rss.com.com/2547-12-0-20.xml&format=*.ics\">subscribe</A>]</LI>\n";
//    echo "<LI><A HREF=\"http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://www.e-queue.com/index.rdf&format=*.ics\">E-Queue</A> [<A HREF=\"webcal://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://www.e-queue.com/index.rdf&format=*.ics\">subscribe</A>]</LI>\n";
//    echo "<LI><A HREF=\"http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://maccentral.macworld.com/mnn.cgi&format=*.ics\">MacCentral</A> [<A HREF=\"webcal://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://maccentral.macworld.com/mnn.cgi&format=*.ics\">subscribe</A>]</LI>\n";
//    echo "<LI><A HREF=\"http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://www.salon.com/feed/RDF/salon_use.rdf&format=*.ics\">Salon</A> [<A HREF=\"webcal://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://www.salon.com/feed/RDF/salon_use.rdf&format=*.ics\">subscribe</A>]</LI>\n";    
//    echo "<LI><A HREF=\"http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://slashdot.org/slashdot.rss&format=*.ics\">Slashdot</A> [<A HREF=\"webcal://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://slashdot.org/slashdot.rss&format=*.ics\">subscribe</A>]</LI>\n";
//    echo "<LI><A HREF=\"http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://www.wired.com/news_drop/netcenter/netcenter.rdf&format=*.ics\">Wired News</A> [<A HREF=\"webcal://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?url=http://www.wired.com/news_drop/netcenter/netcenter.rdf&format=*.ics\">subscribe</A>]</LI>\n";
//    echo "</UL>\n";
    echo "</BODY>\n";
    echo "</HTML>\n";

} else {

    $url = preg_replace('/http:\/\//', '', $url);

    if ($rssData = parseRSS ( "http://$url")) {

        if(!empty($rssData["channel"]["title"])) $channel_title = fix($rssData["channel"]["title"]);
        if(!empty($rssData["channel"]["description"])) $channel_description = fix($rssData["channel"]["description"]);
        if(!empty($channel_title) && strlen($channel_description) == 0) $channel_description = $channel_title;
//        if(!empty($rssData["channel"]["dc:date"])) $channel_date = pre_fix($rssData["channel"]["dc:date"]);
        if(!empty($rssData["channel"]["pubdate"])) $channel_pubdate = pre_fix($rssData["channel"]["pubdate"]);
        if(!empty($rssData["channel"]["lastbuilddate"])) $channel_lastbuilddate = pre_fix($rssData["channel"]["lastbuilddate"]);
//        if(!empty($channel_pubdate) && strlen($channel_pubdate) > 0 && (strlen($channel_date) == 0)) $channel_date = $channel_pubdate;
//        if(!empty($channel_lastbuilddate) && strlen($channel_lastbuilddate) > 0 && (strlen($channel_date) == 0)) $channel_date = $channel_lastbuilddate;

        if(getVar("contenttype","inline")=="attachment") {
            header('Content-Description: File Transfer');
            header('Content-Type: text/calendar');
//            header('Content-Disposition: attachment; filename="' . urlencode((!empty($channel_title))?str_replace(array(' ',"\t"),array('',''),$channel_title): .'calendar.ics') . '"');
            header('Content-Disposition: attachment; filename="calendar.ics"');
        } else {
            header('Content-type: text/plain');
//            header('Content-Disposition: inline; filename="' . urlencode((!empty($channel_title))?str_replace(array(' ',"\t"),array('',''),$channel_title): .'calendar.ics') . '"');
            header('Content-Disposition: inline; filename="calendar.ics"');
        }

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        //echo "X-WR-TIMEZONE;VALUE=TEXT:US/Eastern\r\n";
        echo "METHOD:PUBLISH\r\n";
        echo "PRODID:-//RSS2iCal// v0.0.1.2//DE\r\n";
        if(!empty($channel_title))
        echo "X-WR-CALNAME;VALUE=TEXT:" . $channel_title . "\r\n";
        if(!empty($channel_description))
        echo "X-WR-CALDESC;VALUE=TEXT:" . $channel_description . "\r\n";
        //echo "X-WR-RELCALID;VALUE=TEXT:123456789\r\n";
        $now_timestamp = (new DateTime())->getTimestamp();
        for ( $i = 0; isset ( $rssData[$i] ); $i++ ) {
            $item_title = '';
            $item_summary = '';
            $item_link = '';
            if(!empty($rssData[$i]["title"]))
                $item_title = fix($rssData[$i]["title"]);
            if(!empty($item_title))
                $item_summary = $item_title;
            if(!empty($rssData[$i]["link"]))
                $item_link = fix($rssData[$i]["link"]);
            if(!empty($rssData[$i]["guid"]) && strlen(fix($rssData[$i]["guid"])) > 0 && (strlen($item_link) == 0))
                $item_link = fix($rssData[$i]["guid"]);
            if(!empty($rssData[$i]["description"]))
                $item_description = fix($rssData[$i]["description"]);
            if (!empty($rssData[$i]["content:encoded"]) && strlen(fix($rssData[$i]["content:encoded"])) > 0)
                $item_description = fix($rssData[$i]["content:encoded"]);
            if (!empty($item_description) && (strlen($item_description) > 0) && (strlen($item_title) == 0)) 
                $item_title = substr($item_description, 0, 30) . "...";
            if(!empty($item_description) || !empty($item_link)){
                $item_description = "DESCRIPTION:" . ((!empty($item_link) && strlen($item_link) > 0)?$item_link . "\\n":'') . "\\n"
                    . ((!empty($item_description) && strlen($item_description) > 0)?$item_description:'') . "\n";
        }
            if(!empty($item_description) && strlen($item_description) > 0 && getVar("contentlinewrap","no")=='yes'){
                $item_description = implode("\r\n\t", str_split($item_description, 72));
            }
            if(!empty($rssData[$i]["dc:date"]))
                $item_date = pre_fix($rssData[$i]["dc:date"]);
            if(!empty($rssData[$i]["dc:pubdate"]))
                $item_pubdate = pre_fix($rssData[$i]["pubdate"]);
            if (!empty($item_pubdate) && strlen($item_pubdate) > 0 && (strlen($item_date) == 0))
                $item_date = $item_pubdate;
            //If we use the channel date, all entries have the same datetime
            //This datetime is erroneous because it only applies to the latest item
            //For now don't use it. This results in prettier iCal display
            //if (strlen($channel_date) > 0 && (strlen($item_date) == 0)) {
            //    $item_date = $channel_date;
            //}

            $start_date = '';
            $end_date = '';
            if(!empty($item_date)) {
                if (strlen($item_date) == 25) {
                    $strDT = substr($item_date, 5, 2) . "/";
                    $strDT = $strDT . substr($item_date, 8, 2) . "/";
                    $strDT = $strDT . substr($item_date, 0, 4) . " ";
                    $strDT = $strDT . substr($item_date, 11, 8) . " GMT";
                    //echo "strDT: " . $strDT;
                    $gmt_offset = substr($item_date, 19, 3);
                    $gmt_offset = 60 * 60 * $gmt_offset;
                    //echo "gmt_offset: " . $gmt_offset;
                    $unix_time = strtotime($strDT) - $gmt_offset;
                    //echo "unixtime: " . $unix_time;
                    $item_date = gmdate("Ymd\THis\Z", $unix_time);
                } elseif (strlen($item_date) == 29) {
                    $item_date = gmdate("Ymd\THis\Z", strtotime($item_date));
                } else {
                    $item_date = date("Ymd");
                }
                $start_date = ((strlen($item_date)>8)?'-TIME':'') . "$item_date";
                $end_date = ((strlen($item_date)>8)?'-TIME':'') . "$item_date";
            } else if ($rssData[$i]["description"]) {
                $desc = $rssData[$i]["description"];
                $dateRegex = '/When:\s.*?,\s+([a-zA-Z]+\s+[0-9]+,\s+[0-9]+)\s+([0-9]+:[0-9]+\s+[a-zA-Z]+)\s+to\s+([0-9]+:[0-9]+\s+[a-zA-Z]+)/'; 
                preg_match($dateRegex, $desc, $matches);
                if (!empty($matches[1]) && !empty($matches[2]) && !empty($matches[3])) {
                    $date = strtotime($matches[1]);
                    $startTime = $matches[2];
                    $endTime = $matches[3];
                    $timeZoneOffset = date('Z', strtotime($matches[1] . $matches[2]));
                    $start_date = date('Ymd\THis\Z', strtotime($matches[1] . $matches[2]) - $timeZoneOffset);
                    $end_date = date('Ymd\THis\Z', strtotime($matches[1] . $matches[3]) - $timeZoneOffset);
                }
                
            }
            echo "BEGIN:VEVENT\r\n";
            echo "UID:" . md5($item_link . $item_title) . "\r\n";
            echo "URL;VALUE=URI:$item_link\r\n";
            echo "SUMMARY:" . $item_summary . "\r\n";
            echo $item_description;
            echo 'DTSTART:' . $start_date . "\r\n";
            echo 'DTEND:' . $end_date . "\r\n";
            echo 'DTSTAMP:' . $now_timestamp . "\r\n";
            echo "TRANSP:OPAQUE\r\n";
            echo "STATUS:CONFIRMED\r\n";
            echo "SEQUENCE:0\r\n";
            echo "END:VEVENT\r\n";
        }
        echo "END:VCALENDAR\r\n";
    } else {
        echo "Unable to parse RSS feed.";
    }
}
?>
