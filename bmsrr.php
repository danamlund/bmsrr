<?php
/**
 * BMSRR - Basic Minimalistic Simple RSS Reader
 *
 * Copyright (C) 2013 by Dan Amlund Thomsen <dan@danamlund.dk>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

/**
 * site: http://danamlund.dk/bmsrr.html
 */

/**
 * This is a self-hosted rss-reader. It reads a google reader
 * subscriptions.xml file, and generate a html file of the news
 * stories in the RSS or Atom feeds from subscriptions.xml. You can
 * click a news story to clear all stories older than the clicked
 * item. Updating fetches stories newer than the most recently clicked
 * clear-older-than story.
 *
 * This script generates 2 files: bmsrr_data.txt and bmsrr.html. The
 * script needs to have write permission to these two files.
 *
 * This is a simple reader, it cannot save stories. This means you
 * cannot "star" specific stories, and only stories avilable in the
 * feed at the time of generation are added (most feeds remove older
 * stories). 
 */

error_reporting(0);
ini_set("display_errors", "0");
set_time_limit(60 * 5);

date_default_timezone_set('Europe/Copenhagen');

$bmsrr_conf = 
  array("google reader subscriptions.xml" => "subscriptions.xml",
        "data file" => "./bmsrr_data.txt",
        "item sorter" => "itemSorterOldestFirst",
        "item changer" => "doNotChangeItem",
        "output file" => "bmsrr.html",
        "output header" => 
        '<html>
<head>
<meta http-equiv="Content-Type" content="text/html;charset=utf-8">
<title>BMSRR</title>
</head>
<body>
<h1>Showing {{itemsAmount}} items after {{showItemsAfter}}</h1>
<p><a href={{updateUrl}}>update</a></p>
<hr/>
',
        "output footer" =>
        '</body>
</html>
',
        "output item" =>
        '<h2><a href="{{itemLink}}">{{itemTitle}}</a></h2>
<p>From <i>{{itemFeedTitle}}</i> on {{itemPubDate}} 
(<a href="{{clearOlderThanUrl}}">clear older than this</a>)</p>
<p>{{itemDescription}}</p>
<hr/>
'
        );

function doNotChangeItem($item) {
  return $item;
}

makeRssReader($bmsrr_conf);

//// config that uses lazyload.js
// needs (in same dir as bmsrr.php)
//  1. jquery.js @ http://jquery.com/
//  2. jquery.lazyload.js @ https://github.com/jquery/plugins.jquery.com
//  3. grey.gif @ http://danamlund.dk/files/grey.gif
// To install comment out "makeRssReader($bmsrr_conf);" and uncomment
// "makeRssReader($bmsrr_lazyload_conf);"

$bmsrr_lazyload_conf = 
  array("google reader subscriptions.xml" => "subscriptions.xml",
        "data file" => "./bmsrr_data.txt",
        "item sorter" => "itemSorterOldestFirst",
        "item changer" => "changeItemLazyLoad",
        "output file" => "bmsrr.html",
        "output header" => 
        '<html>
<head>
<meta http-equiv="Content-Type" content="text/html;charset=utf-8">
<title>Basic RSS reader</title>
</head>
<body>
<h1>Showing {{itemsAmount}} items after {{showItemsAfter}}</h1>
<p><a href={{updateUrl}}>update</a></p>
<hr/>
',
        "output footer" =>
        '<script src="jquery.js" charset="utf-8"></script>
<script src="jquery.lazyload.js" charset="utf-8"></script>
<script type="text/javascript" charset="utf-8">
    $(function() {
        $("img").lazyload();
    });
</script>
</body>
</html>
',
        "output item" =>
        '<h2><a href="{{itemLink}}">{{itemTitle}}</a></h2>
<p>From <i>{{itemFeedTitle}}</i> on {{itemPubDate}} 
(<a href="{{clearOlderThanUrl}}">clear older than this</a>)</p>
<p>{{itemDescription}}</p>
<hr/>
'
        );

// makeRssReader($bmsrr_lazyload_conf);

function itemSorterOldestFirst($a, $b) {
  return $a["pubDate"] - $b["pubDate"];
}

function changeItemLazyLoad($item) {
  $dom = new DOMDocument("1.0", "UTF0-8");
  $dom->loadHTML('<?xml encoding="utf-8" ?>' . $item["description"]);
  foreach ($dom->getElementsByTagName("img") as $img) {
    $dataOriginal = $dom->createAttribute("data-original");
    $img->appendChild($dataOriginal);
    $img->setAttribute("data-original", $img->getAttribute("src"));
    $img->setAttribute("src", "grey.gif");
  }

  $item["description"] = $dom->saveHTML($dom);
  
  $item["description"] = 
    preg_replace('~<(?:!DOCTYPE|/?(?:html|head|body))[^>]*>\s*~i', '', 
                 $item["description"]);
  return $item;
}

// Code from down here

function makeRssReader($conf) {
  printf("<html><body>\r\n");
  $fp = fopen($conf["output file"], "w");
  if ($fp == null) {
    pln("Couldn't write to '%s'", $conf["output file"]);
  } else {
    if (flock($fp, LOCK_EX)) {

      if (isset($_REQUEST["clearolder"])) {
        $clearOlderThan = intval($_REQUEST["clearolder"]);
        $out = file_put_contents($conf["data file"], 
                                 serialize($clearOlderThan));
        if ($out === false) {
          pln("Could not write to '%s'", $conf["data file"]);
        }
      }

      if (!file_exists($conf["data file"])) {
        $showItemsAfter = 0;
      } else {
        $showItemsAfter = unserialize(file_get_contents($conf["data file"]));
      }

      $feedUrls = getSubscriptions($conf["google reader subscriptions.xml"]);

      if ($feedUrls == null) {
        pln("Couldn't read '%s'", $conf["google reader subscriptions.xml"]);
      } else {
        $validItems = array();
        foreach ($feedUrls as $feedUrl) {
          $items = getRss($feedUrl);
          if($items === null) {
            pln("<font color=red>Problem fetching '%s'</font>", $feedUrl);
            continue;
          }
          $newItemsFromFeed = 0;
          foreach ($items as $item) {
            if ($item["pubDate"] > $showItemsAfter) {
              $validItems[] = call_user_func($conf["item changer"], $item);
              $newItemsFromFeed++;
            }
          }
          pln("Fetched %d new items from %s", $newItemsFromFeed, $feedUrl);
          flush();
        }

        usort($validItems, $conf["item sorter"]);

        fwrite($fp, templateText($conf["output header"],
                                 array("showItemsAfter" 
                                       => dateString($showItemsAfter),
                                       "itemsAmount" => count($validItems),
                                       "updateUrl" => $_SERVER["PHP_SELF"])));


        foreach ($validItems as $item) {
          $clearOlderThanUrl = 
            $_SERVER["PHP_SELF"]."?clearolder=".$item["pubDate"];
          fwrite($fp, 
                 templateText($conf["output item"],
                              array("itemLink" => $item["link"],
                                    "itemTitle" => $item["title"],
                                    "itemFeedTitle" => $item["feedTitle"],
                                    "itemPubDate" => dateString($item["pubDate"]),
                                    "itemDescription" => $item["description"],
                                    "clearOlderThanUrl" => $clearOlderThanUrl)));
        }

        fwrite($fp, $conf["output footer"]);
      }
      flock($fp, LOCK_UN);
    } else {
      pln("Could not get file lock");
    }
    fclose($fp);
    printf("<hr/>\r\n");
    pln('<a href="%s">rss reader</a>', $conf["output file"]);
  }
  printf("</body></html>\r\n");
}

function dateString($unixtime) {
  return date(DATE_RFC850, $unixtime);
}

function pln() {
  $args = func_get_args();
  $string = call_user_func_array('sprintf', $args);
  printf("%s<br/>\r\n", $string);
}

function templateText($html, $replaces) {
  $from = array();
  $to = array();
  foreach ($replaces as $key => $value) {
    $from[] = "{{" . $key . "}}";
    $to[] = $value;
  }

  return str_replace($from, $to, $html);
}

function getSubscriptions($filename) {
  $subscriptionsXml = simplexml_load_file($filename);
  if ($subscriptionsXml == null) {
    return null;
  }
  $rssUrls = array();

  foreach ($subscriptionsXml->body->outline as $subscription) {
    $rssUrls[] = (string) $subscription["xmlUrl"];
  }
  return $rssUrls;
}

function getRss($url) {
  $rssXml = simplexml_load_file($url);
  if (!$rssXml) {
    return null;
  }
  $items = array();
  if ($rssXml->entry) {
    // atom
    foreach ($rssXml->entry as $entry) {
      $description = trim((string) $entry->content 
                          ? $entry->content : $entry->summary);
      $items[] = array("feedTitle" => trim((string)$rssXml->title),
                       "title" => trim((string) $entry->title),
                       "description" => $description,
                       "link" => trim((string) $entry->link["href"]),
                       "guid" => trim((string) $entry->id),
                       "pubDate" => strtotime(trim((string) $entry->updated)));
    }
  } else if ($rssXml->channel->item) {
    // rss
    foreach ($rssXml->channel->item as $item) {
      $items[] = array("feedTitle" => trim((string) $rssXml->channel->title),
                       "title" => trim((string) $item->title),
                       "description" => trim((string) $item->description),
                       "link" => trim((string) $item->link),
                       "guid" => trim((string) $item->guid),
                       "pubDate" => strtotime(trim((string) $item->pubDate)));
    }
  } else {
    return null;
  }
  return $items;
}
