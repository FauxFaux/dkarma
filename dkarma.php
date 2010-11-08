<?
ini_set('memory_limit', '9001M');
$cache_table = '_dkarma_cache';

function gencache()
{
	global $cache_table;
	mysql_query('DROP TABLE IF EXISTS ' . $cache_table);
	mysql_query('CREATE TABLE ' . $cache_table . ' AS SELECT `Nick`,`Text`,`Time` from `History` where `Text` LIKE "%++%" OR `Text` LIKE "%--%"');
	echo mysql_error();
}

// Karma.java

	// ++ or --:
	$plusplus_or_minusminus = "(\\+\\+|\\-\\-)";

	// Quoted string:
	$c_style_quoted_string = "(?:"
			. "\""
			. "("
				. "(?:\\\\.|[^\"\\\\]){1,15}" // C-style quoting
			. ")"
			. "\""
			. ")";

	// Plain string of >=2 chars.
	$plain_karma = "("
				. "[a-zA-Z0-9_]{2,15}"
			. ")";

	// Either a quoted or a valid plain karmaitem.
	$karma_item = "(?x:" . $c_style_quoted_string . "|" . $plain_karma . ")";

	// If you change this, change reasonPattern too.
	$karmaPattern =
		  "(?x:"
		. "(?: ^ | (?<=[\\s\\(]) )" // Anchor at start of string, whitespace or open bracket.
		. $karma_item
		. $plusplus_or_minusminus
		. "((?:\\s+(?:for|because) .{5,})?)"
		. "[\\)\\.,]?" // Allowed to terminate with full stop/close bracket etc.
		. "(?: (?=\\s) | $ )" // Need either whitespace or end-of-string now.
		. ")"
	;

// </Karma.java>

// Crushed from http://www.entropy.ch/software/macosx/php/button-image.phps
function hsv2rgb($h, $s, $v)
{
	$h %= 360;

	$h /= 60;
	$i = floor($h);
	$f = $h - $i;
	$p = $v * (1 - $s);
	$q = $v * (1 - $s * $f);
	$t = $v * (1 - $s * (1 - $f));

	switch($i)
	{
		case 0: $r = $v; $g = $t; $b = $p; break;
		case 1: $r = $q; $g = $v; $b = $p; break;
		case 2: $r = $p; $g = $v; $b = $t; break;
		case 3: $r = $p; $g = $q; $b = $v; break;
		case 4: $r = $t; $g = $p; $b = $v; break;
		default: $r = $v; $g = $p; $b = $q; break;
	}

	return array($r * 255, $g * 255, $b * 255);
}

// Lalalal, log sucks.
function numlen($num)
{
	$a = abs($num);
	$sign = $num < 0 ? 1 : 0;
	if ($a<10)
		return 1 + $sign;
	if ($a<100)
		return 2 + $sign;

	return 3 + $sign;
}

function nicklink($nick)
{
	if (preg_match("/^([a-zA-Z0-9_-]+?)(?:\\||\\`).*$/", $nick, $matches))
		return $matches[1];
	return $nick;
}

require('db_connection.php');

if (@$_GET{'flush'} == 1)
	gencache();

// Get the status of the cache.
$result = mysql_query('SHOW TABLE STATUS FROM choob LIKE "' . $cache_table . '"');
if (mysql_num_rows($result) != 0)
{
	// If it exists.
	$arr = mysql_fetch_assoc($result);
	$str = $arr['Create_time'];
	$timestamp = strtotime($str);
	if (date('d F Y', $timestamp) != date('d F Y'))
		gencache();
}
else
	gencache();

$types = array('items', 'allitems', 'include', 'invert', 'ignore', 'chans', 'total', 'goup', 'nodown', 'flood', 'reasons');

$cnt = 0; // number of graphs
foreach ($types as $ty)
	if (is_array($_GET[$ty])) {
		$prop=max(array_keys($_GET[$ty]));
		if ($prop > $cnt)
			$cnt = $prop;
	}

++$cnt;

$ignore = array();
for ($ds = 0; $ds < $cnt; ++$ds) { 
	foreach (explode('|', get('ignore', $ds)) as $ig)
		@$ignore[$ds][strtolower(nicklink($ig))] = true;
	foreach (explode('|', get('chans', $ds)) as $ch)
		@$chans[$ds][strtolower($ch)] = true;
}

function array_flatten(array $array) {
	$ret_array = array();
	foreach(new RecursiveIteratorIterator(new RecursiveArrayIterator($array)) as $value) {
		$ret_array[] = $value;
	}
	return $ret_array;
}

function no_magic_quotes($s) { return str_replace("\\'", "'", str_replace('\\"','"', $s)); }
function spaces_to_underscores($s) { return str_replace(" ", "_", $s); }
function underscores_to_spaces($s) { return str_replace("_", " ", $s); }
function gettoitems($x) {
	$input = array_map("no_magic_quotes", explode('|', $x));
	return array_unique(array_map('spaces_to_underscores', array_map('strtolower', $input)));
}

function get($key, $ds) {
	$g = $_GET[$key];
	if (is_array($g))
		return $g[$ds];
	return $g;
}

function anyset($key) {
	global $cnt;
	for ($ds = 0; $ds < $cnt; ++$ds)
		if ('' != get($key, $ds))
			return true;
	return false;
}

$itemsget = $_GET['items'];
if (is_array($itemsget))
	$items = gettoitems(@implode('|', $itemsget));
else
	$items = gettoitems($itemsget);

$allitems = anyset('allitems');

if (!isset($_GET{'show'}) && ($items != array("") || $allitems))
{
	$query = 'SELECT `Nick`,`Text`,`Time`';
	$needchans = anyset('chans');
	if ($needchans)
		$query .= ',`Channel`';
	$query .= ' FROM `' . $cache_table . '`';
	if ($needchans)
		$query .= ' INNER JOIN `History` USING (`Nick`,`Text`,`Time`)';

	$query .= ' WHERE ';
	
	$since = 0;
	$sincestr = $_GET{'since'};
	$sincetotime = strtotime($sincestr);
	if (FALSE !== $sincetotime)
		$since = $sincetotime;
	else {
		$sinceint = (int)$sincestr;
		if ($sinceint)
			$since=time()-$sinceint;
	}

	if ($since)
		$query .= '`Time` > ' . $since*1000 . ' AND ';

	$query .= '(';
	$query .= '`Text` LIKE "%me++%" OR `Text` LIKE "%me--%"';

	foreach ($items as $item)
	{
		$escapedu = mysql_real_escape_string($item);
		$escapeds = mysql_real_escape_string(underscores_to_spaces($item));
		$query .= ' OR `Text` LIKE \'%' . $escapedu . '++%\' OR `Text` LIKE \'%' . $escapedu . '--%\'';
		$query .= ' OR `Text` LIKE \'%' . $escapedu . '"++%\' OR `Text` LIKE \'%' . $escapedu . '"--%\'';
		$query .= ' OR `Text` LIKE \'%' . $escapeds . '++%\' OR `Text` LIKE \'%' . $escapeds . '--%\'';
		$query .= ' OR `Text` LIKE \'%' . $escapeds . '"++%\' OR `Text` LIKE \'%' . $escapeds . '"--%\'';
	}

	$goup = $_GET{'goup'}; // not working
	$nodown = $_GET{'nodown'};

	if ($allitems)
		$query .= ' OR 1';

	$query .= ')';
	
	$result = mysql_query($query) or die(mysql_error());

	mysql_num_rows($result) or die ("teh no results!");

	$imap = array();
	$lasttim = array();
	for ($ds = 0; $ds < $cnt; ++$ds) {
		mysql_data_seek($result, 0);
		while ($row = mysql_fetch_assoc($result))
		{
			$cleannick = strtolower(nicklink($row['Nick']));

			if (@$ignore[$ds][$cleannick])
				continue;

			if ($needchans && @$chans[$ds][$row['Channel']])
				continue;

			preg_match_all('/' . $karmaPattern . '/', $row['Text'], $regs);
			unset($regs[0]);
			
			for ($i = 0; $i < count($regs[1]); $i++)
				if (strlen($regs[1][$i]) > 16)
					$regs[1][$i] = substr($regs[1][$i], 0, 15);

			for ($i = 0; $i < count($regs[1]); $i++)
			{
				if ('' != get('include',$ds) && !preg_match(get('include',$ds), $row['Nick']))
					continue;
				$direction = $regs[3][$i] == "++";
				if (get('reasons',$ds) && "" == $regs[4][$i])
					continue;
				if (isset($_GET['usage']))
					$direction = true;

				if ($regs[1][$i] != "")
					$item = $regs[1][$i];
				else
					$item = $regs[2][$i];

				if ($item == "me" || strtolower($item) == $cleannick)
				{
					$item = nicklink($row['Nick']);
					$direction = false;
				}

				if ('' != get('invert',$ds) && preg_match(get('invert',$ds), $row['Nick']))
					$direction =! $direction;

				$item = spaces_to_underscores(strtolower($item));

				if (!$allitems && !in_array($item, gettoitems(get('items',$ds))))
					continue;

				$tim = $row['Time']/1000;
				if ($tim - @$lasttim[$ds][$item] > get('flood',$ds))
					$imap[(get('total',$ds) ? 'total' : $item) . ':' . $ds][] = array($tim, $direction);

				@$lasttim[$ds][$item] = $tim;
			}
		}
	}
} else
	$imap = array();


//print_r($imap);

if (empty($imap))
{
?>
<?='<?'?>xml version="1.0" encoding="utf-8" ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML Basic 1.1//EN"
    "http://www.w3.org/TR/xhtml-basic/xhtml-basic11.dtd">
<html><head><title>dkarma</title></head><body>
<form id="actual" action="" method="get">
<div></div>
</form>
<form id="fake" action="" method="get" onsubmit="sub(); return false;">
<p>
<input type="submit"/>
</p>
<p>
w and h: Width and height of the image: <input type="text" name="w" value="1000"/> x <input type="text" name="h" value="500"/>
</p>
<p>
since: empty for call, yyyy-mm-dd date or integer number of seconds old: <input type="text" name="since" value="<?=$_GET{'since'}?>"/>
</p>
</form>
<p>
<input type="button" onclick="addnew()" value="+"/> or <a href="?">destroy everything</a>.
</p>
<noscript><p>Javascript is required for now.  Free-software, etc.</p></noscript>
<script type="text/javascript"><!--
var get=<?=json_encode($_GET)?>;

function gut(type, ds) {
	var g = get[type];
	if (typeof g != 'object')
		return g;
	return get[type][ds];
}

function input(type, name) {
	var one = document.createElement('input');
	one.type=type;
	one.name=name + '[' + total + ']';
	var v = undefined;
	if (gut(name, total) != undefined) {
		v = gut(name, total);
	} else
		if (0 != total) {
			var last=document.getElementById(name+(total-1));
			if ('checkbox' == type)
				v = last.checked;
			else
				v = last.value;
		}
	if (v != undefined)
		if ('checkbox' == type)
			one.checked = (v != '' ? 'checked' : '');
		else 
			one.value = v;

	one.id=name+total;
	return one;
}

function ul() {
	return document.createElement('ul');
}

function li(a) {
	return appret(document.createElement('li'), a);
}

var total=0;

function label(of, contents) {
	var l = document.createElement('label');
	l['for'] = of + total;
	l.appendChild(document.createTextNode(contents));
	return l;
}

function appret(to, what) {
	to.appendChild(what);
	return to;
}

function og(u, id, desc, type) {
	var inp=input(type, id);
	u.appendChild(appret(li(label(id, id + ': ' + desc + ": ")), inp));
	return inp;
}

function newu(rootul, head) {
	return rootul.appendChild(li(document.createTextNode(head))).appendChild(ul());
}


function addnew() {
	var form = document.getElementById('fake');
	var rootul = ul();
	var u = newu(rootul, 'Item selection:');
	var itemsinp = input('text', 'items');
	itemsinp.style.minWidth='50%';
	u.appendChild(appret(li(label('items', 'items: Pipe (|) seperated list of items to show: ')), itemsinp));
	og(u, 'allitems', '...or show all items', 'checkbox');
	og(u, 'include', 'Only include karma from nicks matching this regex (e.g. /Faux/)', 'text');
	og(u, 'invert', 'Invert karma from nicks matching this regex (e.g. /Sraphim|bma/i)', 'text');
	og(u, 'ignore', 'Pipe (|) seperated list of people to ignore karma from', 'text');
	og(u, 'chans', 'Pipe (|) seperated list of channels to ignore karma from', 'text');
	u = newu(rootul, 'Controls:');
	og(u, 'total', 'Only show one line; the total of all the items selected', 'checkbox');
	og(u, 'goup', 'Pretend all karma is upwards (that\'ll be the day)', 'checkbox');
	og(u, 'nodown', 'Ignore haters, only include positive karma', 'checkbox');
	og(u, 'flood', 'Number of seconds of flood protection (>0, default: 900)', 'text');
	og(u, 'reasons', 'Reasons are mandatory', 'checkbox');
	form.appendChild(appret(document.createElement('p'), rootul));
	++total;
}

for (var i = 0; i < Math.max(1, <?=$cnt?>); ++i)
	addnew();

function valu(el) {
	if ('checkbox' == el.type) 
		return el.checked ? 'on' : '';
	return el.value;
}

function allsamefortype(orig, type) {
	var b = valu(orig[type + "[0]"]);
	for (var i = 1; i < total; ++i)
		if (valu(orig[type + "[" + i + "]"]) != b) {
			return false;
		}
	return true;
}

function sub() {
	var orig = document.forms['fake'].elements;
	var types = ['<?=implode("','", $types);?>'];
	var url="";
	var w=orig["w"].value;
	var h=orig["h"].value;
	if (w != 1000)
		url += "&w=" + encodeURIComponent(w);
	if (h != 500)
		url += "&h=" + encodeURIComponent(h);
	var since=orig["since"].value;
	if (since != '')
		url += "&since=" + encodeURIComponent(since);

	for (var t in types) {
		var type=types[t];
		if (allsamefortype(orig, type))
			if (valu(orig[type + "[0]"]) == '')
				continue;
			else
				url += "&" + type + "=" + encodeURIComponent(valu(orig[type + "[0]"]));
		else
			for (var i = 0; i < total; ++i) {
				var lab = type + "[" + i + "]";
				var val = valu(orig[type + "[" + i + "]"]);
				if (val != '')
					url += "&" + lab + "=" +  encodeURIComponent(val);
			}
	}
	document.location="?" + url.substring(1);
}

//--></script>

</body></html>
<?php
die();
}

$mintime = time();
$maxtime = 0;

foreach($imap as $imp)
	foreach ($imp as $inst)
		if ($inst[0] < $mintime)
			$mintime = $inst[0];
		else if ($inst[0] > $maxtime)
			$maxtime = $inst[0];

$minkarma = 999999999999;
$maxkarma = -999999999999;

function ds_from_nam($nam) {
	preg_match('/:(\d+)$/', $nam, $regs);
	return $regs[1];
}


foreach($imap as $nam => $imp)
{
	$ds = ds_from_nam($nam);
	$running = 0;
	foreach ($imp as $inst)
	{
		if (get('goup', $ds) || $inst[1])
			$running++;
		else if (!get('nodown', $ds))
			$running--;

		if ($running < $minkarma) $minkarma = $running;
		if ($running > $maxkarma) $maxkarma = $running;
	}
}

if ($maxkarma<0)
	$maxkarma = 0;

if ($minkarma>0)
	$minkarma = 0;

$karmarange = $maxkarma-$minkarma;
$timerange = $maxtime-$mintime;

// Image dimensions.
$imw = @$_GET{'w'};
$imh = @$_GET{'h'};
if ($imw == 0) $imw = 1000;
if ($imh == 0) $imh = 500;

$bordertop = 20;
$borderbottom = 20;

$lenmin = numlen($minkarma);
$lenmax = numlen($maxkarma);

if ($lenmin > $lenmax) $lenmax=$lenmin;

$borderleft = $lenmax*9 + 8;



$maxlen = 0;

foreach ($imap as $nam => $imp)
	if (strlen($nam) > $maxlen) $maxlen=strlen($nam);

$borderright = $maxlen * 9 + 10;

$subw = $imw - $borderleft - $borderright;
$subh = $imh - $bordertop - $borderbottom;

$im = imagecreate($imw, $imh);
$c = imagecolorallocate($im, 255,255,255);

$black = imagecolorallocate($im, 0,0,0);
$grey = imagecolorallocate($im, 120,120,120);

$colour = array();
$numcols = count($imap);
$max_colours = 60;

for ($i=0; $i<min($numcols,$max_colours); $i++)
{
	$rgb = hsv2rgb((1+$i)/min($numcols,$max_colours)*360, 1, 64);
	$colour[] = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
}

// assumes zero is always included.
$zeroline = $bordertop + $subh * $maxkarma/$karmarange;

$idx = 0;

foreach($imap as $nam => $imp) // imp is an array(array(time, dir), , , );
{
	$ds = ds_from_nam($nam);
	$running = 0;
	$lastpos = array($borderleft, $zeroline);
	foreach ($imp as $inst)
	{
		if (get('goup',$ds) || $inst[1])
			$running++;
		else if (!get('nodown',$ds))
			$running--;

		$pos = array($borderleft + ($inst[0]-$mintime)/$timerange * $subw, $zeroline - $running/$karmarange * $subh);

		imageline($im, $lastpos[0], $lastpos[1], $pos[0], $pos[1], $colour[$idx%$max_colours]);
		$lastpos = $pos;
	}

	imagestring($im, 6, $lastpos[0] + 10, $lastpos[1] - 8, $nam, $colour[$idx%$max_colours]);

	$idx++;
}


imageline($im, $borderleft, $bordertop, $borderleft, $bordertop + $subh, $black); // y axis
imageline($im, $borderleft, $bordertop + $subh, $borderleft + $subw, $bordertop + $subh, $black); // x axis

imageline($im, $borderleft, $zeroline, $borderleft + $subw, $zeroline, $black); // zero line

imagestring($im, 6, 6, $zeroline-6, "0", $black);

imagestring($im, 6, 6, $bordertop-6, $maxkarma, $black);
imagestring($im, 6, 6, $bordertop+$subh-6, $minkarma, $black);


imagestring($im, 6, $borderleft, $bordertop+$subh+1, date('jS \of F Y', $mintime), $black);

$sy = date('Y', $mintime);
$ey = date('Y', $maxtime);
for ($i = $sy; $i <= $ey; ++$i) { # for each covered year
	$t = mktime(0,0,0,0,0,$i);
	$x = ($t-$mintime)/($maxtime-$mintime) * $subw;
#	echo "$i: ($t-$mintime)/($maxtime-$mintime) * $subw = $x;<br/>";
	if ($x > 0) {
		imageline($im, $x, $bordertop, $x, $bordertop + $subh, $grey); # year line
		if ($x > 250 && $x < $subw - 250) # close enough to the edge that the other labels would cover it
			imagestring($im, 6, $x - 17, $bordertop+$subh+1, $i, $grey); # 2008 / 2009 etc.
	}
}	

$timeright = date('jS \of F Y', $maxtime);

imagestring($im, 6, $borderleft+$subw - strlen($timeright)*9, $bordertop+$subh+1, $timeright, $black);

header("Content-type: image/png");
imagepng($im);

