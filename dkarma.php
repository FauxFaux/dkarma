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


$ignore = array();
$cnt = count($_GET{'items'});
for ($ds = 0; $ds < $cnt; ++$ds) 
	foreach (explode('|', @$_GET{'ignore'}[$ds]) as $ig)
		@$ignore[$ds][strtolower(nicklink($ig))] = true;

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

$items = gettoitems(@implode('|', $_GET['items']));

$allitems = isset($_GET{'allitems'});

if (!isset($_GET{'show'}) && ($items != array("") || $allitems))
{
	$query = 'SELECT `Nick`,`Text`,`Time` from `' . $cache_table . '` where `Text` LIKE "%me++%" OR `Text` LIKE "%me--%"';

	foreach ($items as $item)
	{
		$escapedu = mysql_real_escape_string($item);
		$escapeds = mysql_real_escape_string(underscores_to_spaces($item));
		$query .= ' OR `Text` LIKE \'%' . $escapedu . '++%\' OR `Text` LIKE \'%' . $escapedu . '--%\'';
		$query .= ' OR `Text` LIKE \'%' . $escapedu . '"++%\' OR `Text` LIKE \'%' . $escapedu . '"--%\'';
		$query .= ' OR `Text` LIKE \'%' . $escapeds . '++%\' OR `Text` LIKE \'%' . $escapeds . '--%\'';
		$query .= ' OR `Text` LIKE \'%' . $escapeds . '"++%\' OR `Text` LIKE \'%' . $escapeds . '"--%\'';
	}

	$thisitems = $_GET{'items'};
	$flood = $_GET{'flood'};
	$invert = $_GET{'invert'};
	$include = $_GET{'include'};
	$total = isset($_GET{'total'});
	$goup = isset($_GET{'goup'});
	$nodown = isset($_GET{'nodown'});

	if ($allitems)
		$query .= ' OR 1';
	
	$result = mysql_query($query);

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

			preg_match_all('/' . $karmaPattern . '/', $row['Text'], $regs);
			unset($regs[0]);
			
			for ($i = 0; $i < count($regs[1]); $i++)
				if (strlen($regs[1][$i]) > 16)
					$regs[1][$i] = substr($regs[1][$i], 0, 15);

			for ($i = 0; $i < count($regs[1]); $i++)
			{
				if (!@empty($include[$ds]) && !preg_match($include[$ds], $row['Nick']))
					continue;
				$direction = $regs[3][$i] == "++";
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

				if (!@empty($invert[$ds]) && preg_match($invert[$ds], $row['Nick']))
					$direction =! $direction;

				$item = spaces_to_underscores(strtolower($item));

				if (!$allitems && !in_array($item, gettoitems($thisitems[$ds])))
					continue;

				$tim = $row['Time']/1000;

				if ($tim - @$lasttim[$ds][$item] > $flood[$ds])
					$imap[($total[$ds] ? 'total' : $item) . $ds][] = array($tim, $direction);

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
<html><head><title>dkarma</title></head><body>
<form id="theform" name="pony" action="" method="get">
<p>
<input type="submit"/>
</p>
<p>
w and h: Width and height of the image: <input type="text" name="w" value="1000"/> x <input type="text" name="h" value="500"/>
</p>
</form>
<input type="button" onclick="addnew()" value="+"/> or <a href="?">destroy everything</a>.
<script type="text/javascript">
var get=<?=json_encode($_GET)?>;

function input(type, name) {
	var one = document.createElement('input');
	one.type=type;
	one.name=name + '[' + total + ']';
	if (get[name] != undefined && get[name][total] != undefined)
		one.value=get[name][total];
	if (name == 'flood' && one.value == 0)
		one.value=900;
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
	var form = document.getElementById('theform');
	var rootul = ul();
	var u = newu(rootul, 'Item selection:');
	var itemsinp = input('text', 'items');
	itemsinp.style.minWidth='50%';
	u.appendChild(appret(li(label('items', 'items: Pipe (|) seperated list of items to show: ')), itemsinp));
	og(u, 'allitems', '...or show all items', 'checkbox');
	og(u, 'include', 'Only include karma from nicks matching this regex (e.g. /Faux/)', 'text');
	og(u, 'invert', 'Invert karma from nicks matching this regex (e.g. /Sraphim|bma/i)', 'text');
	og(u, 'ignore', 'Pipe (|) seperated list of people to ignore karma from', 'text');
	u = newu(rootul, 'Controls:');
	og(u, 'total', 'Only show one line; the total of all the items selected', 'checkbox');
	og(u, 'goup', 'Pretend all karma is upwards (that\'ll be the day)', 'checkbox');
	og(u, 'nodown', 'Ignore haters, only include positive karma', 'checkbox');
	og(u, 'flood', 'Number of seconds of flood protection (>0)', 'text');
	form.appendChild(appret(document.createElement('p'), rootul));
	++total;
}

for (var i = 0; i < Math.max(1, <?=$cnt?>); ++i)
	addnew();
</script>

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

foreach($imap as $imp)
{
	$running = 0;
	foreach ($imp as $inst)
	{
		if ($goup || $inst[1])
			$running++;
		else if (!$nodown)
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

/*
// Day in seconds.
$daylength = 24 * 60 * 60;

//foreach ($daystart as $day)
//	echo "$day\n";
*/

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
//imagesavealpha($im, true);
#@imagefill($im, 0, 0, $c);

$black = imagecolorallocate($im, 0,0,0);

$colour = array();
$numcols = count($imap);
$max_colours = 60;

for ($i=0; $i<min($numcols,$max_colours); $i++)
{
	$rgb = hsv2rgb((1+$i)/min($numcols,$max_colours)*360, 1, 64);
	$colour[] = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
}

//print_r($colour);

/*
if (count($imap) > count($colour))
	die("programmer too lazy to make up enough colours");
*/

// assumes zero is always included.
$zeroline = $bordertop + $subh * $maxkarma/$karmarange;

$idx = 0;

foreach($imap as $nam => $imp) // imp is an array(array(time, dir), , , );
{
	$running = 0;
	$lastpos = array($borderleft, $zeroline);
	foreach ($imp as $inst)
	{
		if ($goup || $inst[1])
			$running++;
		else if (!$nodown)
			$running--;

		$pos = array($borderleft + ($inst[0]-$mintime)/$timerange * $subw, $zeroline - $running/$karmarange * $subh);

		imageline($im, $lastpos[0], $lastpos[1], $pos[0], $pos[1], $colour[$idx%$max_colours]);
		$lastpos = $pos;
	}

	imagestring($im, 6, $lastpos[0] + 10, $lastpos[1] - 8, $nam, $colour[$idx%$max_colours]);

	$idx++;
}


imageline($im, $borderleft, $bordertop, $borderleft, $bordertop + $subh, $black);
imageline($im, $borderleft, $bordertop + $subh, $borderleft + $subw, $bordertop + $subh, $black);

imageline($im, $borderleft, $zeroline, $borderleft + $subw, $zeroline, $black);

imagestring($im, 6, 6, $zeroline-6, "0", $black);

imagestring($im, 6, 6, $bordertop-6, $maxkarma, $black);
imagestring($im, 6, 6, $bordertop+$subh-6, $minkarma, $black);


imagestring($im, 6, $borderleft, $bordertop+$subh+1, date('jS \of F Y', $mintime), $black);

$timeright = date('jS \of F Y', $maxtime);

imagestring($im, 6, $borderleft+$subw - strlen($timeright)*9, $bordertop+$subh+1, $timeright, $black);

header("Content-type: image/png");
imagepng($im);

