<?
$cache_table = '_dkarma_cache';

$flood = @$_GET{'flood'};
if ($flood == 0) $flood = 900;

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
				. "(?:\\\\.|[^\"\\\\])+" // C-style quoting
			. ")"
			. "\""
			. ")";

	// Plain string of >=2 chars.
	$plain_karma = "("
				. "[a-zA-Z0-9_]{2,}"
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

mysql_connect('localhost', 'choob', 'ponies') or die('Could not connect: ' . mysql_error());

mysql_select_db('choob') or die('Could not select database');

if (@$_GET{'flush'} == 1)
	gencache();

// Get the status of the cache.
$result = mysql_query('SHOW TABLE STATUS FROM choob LIKE "' . $cache_table . '"');
if (mysql_num_rows($result) != 0)
{
	// If it exists.
	$arr = mysql_fetch_assoc($result);
	$str = $arr['Create_time'];
	// previous to PHP 5.1.0 you would compare with -1, instead of false
	if (!(($timestamp = strtotime($str)) === false /* use cache */ || date('d F Y', $timestamp) == date('d F Y') /* use cache */))
		gencache();
}
else
	gencache();


$ignore = array(); foreach (explode('|', @$_GET{'ignore'}) as $ig) @$ignore[strtolower(nicklink($ig))] = true;
$items = array_map('strtolower', array_map("mysql_real_escape_string", explode('|', $_GET['items'])));

$query = 'SELECT `Nick`,`Text`,`Time` from `' . $cache_table . '` where `Text` LIKE "%me++%" OR `Text` LIKE "%me--%"';

foreach ($items as $item)
	$query .= ' OR `Text` LIKE "%' . $item . '++%" OR `Text` LIKE "%' . $item . '--%"';

$result = mysql_query($query);

mysql_num_rows($result) or die ("teh no results!");

$imap = array();
$lasttim = array();

while ($row = mysql_fetch_assoc($result))
{
	$cleannick = strtolower(nicklink($row['Nick']));

	//echo $cleannick . "|";

	if (@$ignore[$cleannick])
		continue;

	preg_match_all('/' . $karmaPattern . '/', $row['Text'], $regs);
	unset($regs[0]);
	for ($i = 0; $i < count($regs[1]); $i++)
	{
		$direction = $regs[3][$i] == "++";

		if ($regs[1][$i] != "")
			$item = $regs[1][$i];
		else
			$item = $regs[2][$i];

		if ($item == "me" || strtolower($item) == $cleannick)
		{
			$item = nicklink($row['Nick']);
			$direction = false;
		}

		$item = strtolower($item);

		if (!in_array($item, $items))
			continue;



		//echo "$item is going " . ($direction ? "up" : "down") . "\n";
		$tim = $row['Time']/1000;

		if ($tim - @$lasttim[$item] > $flood)
			$imap[$item][] = array($tim, $direction);

		@$lasttim[$item] = $tim;
	}
}

//print_r($imap);

count($imap) or die("teh no info");

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
		if ($inst[1])
			$running++;
		else
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
$black = imagecolorallocate($im, 0,0,0);

$colour = array();
$numcols = count($imap);

for ($i=0; $i<$numcols; $i++)
{
	$rgb = hsv2rgb($i/$numcols*360, 1, 64);
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
		if ($inst[1])
			$running++;
		else
			$running--;

		$pos = array($borderleft + ($inst[0]-$mintime)/$timerange * $subw, $zeroline - $running/$karmarange * $subh);

		imageline($im, $lastpos[0], $lastpos[1], $pos[0], $pos[1], $colour[$idx]);
		$lastpos = $pos;
	}

	imagestring($im, 6, $lastpos[0] + 10, $lastpos[1] - 8, $nam, $colour[$idx]);

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

