<?

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

function numlen($num)
{
	if ($num == 0)
		return 1;
	return ((int)log(abs($num))) + ($num < 0 ? 1 : 0);
}

function nicklink($nick)
{
	if (preg_match("/^([a-zA-Z0-9_-]+?)(?:\\||\\`).*$/", $nick, $matches))
		return $matches[1];
	return $nick;
}

mysql_connect('localhost', 'choob', 'ponies') or die('Could not connect: ' . mysql_error());

mysql_select_db('choob') or die('Could not select database');
$items = array_map('strtolower', array_map("mysql_real_escape_string", explode('|', $_GET['items'])));

$query = 'SELECT `Nick`,`Text`,`Time` from `History` where `Text` LIKE "%me++%" OR `Text` LIKE "%me--%"';

foreach ($items as $item)
	$query .= ' OR `Text` LIKE "%' . $item . '++%" OR `Text` LIKE "%' . $item . '--%"';

$result = mysql_query($query);

mysql_num_rows($result) or die ("teh no results!");

$imap = array();

while ($row = mysql_fetch_assoc($result))
{
	preg_match_all('/' . $karmaPattern . '/', $row['Text'], $regs);
	unset($regs[0]);
	for ($i = 0; $i < count($regs[1]); $i++)
	{
		$direction = $regs[3][$i] == "++";

		if ($regs[1][$i] != "")
			$item = $regs[1][$i];
		else
			$item = $regs[2][$i];

		if ($item == "me")
		{
			$item = nicklink($row['Nick']);
			$direction = false;
		}

		$item = strtolower($item);

		if (!in_array($item, $items))
			continue;

		//echo "$item is going " . ($direction ? "up" : "down") . "\n";
		$imap[$item][] = array($row['Time']/1000, $direction);
	}
}

//print_r($imap);

count($imap) or die("teh no info");

foreach($imap as $thing => $imp)
	if (count($imp) < 5)
		die("not enough stuff for $thing");

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

$karmarange = $maxkarma-$minkarma;
$timerange = $maxtime-$mintime;

header("Content-type: image/png");

/*
// Day in seconds.
$daylength = 24 * 60 * 60;

//foreach ($daystart as $day)
//	echo "$day\n";
*/

// Image dimensions.
$imw = 1000;
$imh = 500;

$bordertop = 20;
$borderbottom = 20;

$lenmin = numlen($minkarma);
$lenmax = numlen($minkarma);

if ($lenmin > $lenmax) $lenmax=$lenmin;
$borderleft = $lenmax*9 + 1;

$maxlen = 0;

foreach ($imap as $nam => $imp)
	if (strlen($nam) > $maxlen) $maxlen=strlen($nam);

$borderright = $maxlen * 9 + 10;

$subw = $imw - $borderleft - $borderright;
$subh = $imh - $bordertop - $borderbottom;

$im = imagecreate($imw, $imh);
$c = imagecolorallocate($im, 255,255,255);
$black = imagecolorallocate($im, 0,0,0);

$colour = array(
	imagecolorallocate($im, 255,127,127),
	imagecolorallocate($im, 127,127,255),
	imagecolorallocate($im, 255,127,255),
	imagecolorallocate($im, 0,255,0),
);

if (count($imap) > count($colour))
	die("programmer too lazy to make up enough colours");


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


imagepng($im);
