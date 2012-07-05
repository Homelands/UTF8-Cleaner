<?php
// $Id: clean_utf8.php,v 1.5 2011-12-13 04:17:59 kennyk Exp $

// UTF-8 Format Validation
// Reference: http://www.rfc-editor.org/rfc/rfc3629.txt
// Reference: http://en.wikipedia.org/wiki/UTF-8
//
//	BSize	Code Point	Binary					Hexadecimal
//	1	U+0000		00000000				0x00
//	1	U+007F		01111111				0x7F
//		Illegal leading characters:	[0x80 - 0xBF]
//	2	Overlong prefix:		[0xC0 - 0xC1]
//	2	U+0080		11000010 10000000			0xC2 0x80
//	2	U+07FF		11011111 10111111			0xDF 0xBF
//	3	Overlong prefix:		[0xE0 0x80 - 0xE0 0x9F]
//	3	U+0800		11100000 10100000 10000000		0xE0 0xA0 0x80
//	3	U+D7FF		11101101 10011111 10111111		0xED 0x9F 0xBF
//	3	Surrogate pairs prefix:		[0xED 0xA0 - 0xED 0xBF]
//	3	U+E000		11101110 10000000 10000000		0xEE 0x80 0x80
//	3	U+FFFF		11101111 10111111 10111111		0xEF 0xBF 0xBF
//	4	Overlong prefix:		[0xF0 0x80 - 0xF0 0x8F]
//	4	U+010000	11110000 10010000 10000000 10000000	0xF0 0x90 0x80 0x80
//	4	U+10FFFF	11110100 10001111 10111111 10111111	0xF4 0x8F 0xBF 0xBF
//	4	Out of range:			[0xF4 0x90 - 0xF4 0xBF]
//	4	Out of range:			[0xF5 - 0xF7]
//	5	Out of range:			[0xF8 - 0xFB]
//	6	Out of range:			[0xFC - 0xFD]
//		Illegal characters:		[0xFE - 0xFF]



// $v: Current character in ASCII value
// $next: Next character in ASCII value
function check_overlong($v, $next)
{
	switch ($v)
	{
	case 0xC0: case 0xC1:				// Special case in block size = 2	(Overlong!)
		return false;
	case 0xE0:	// Special case in block size = 3, requires next byte >= 0xA0, or	(Overlong!)
		return ($next >= 0xA0);
	case 0xED:	// Special case in block size = 3, requires next bute < 0xA0, or	(Surrogate Pairs!)
		return ($next < 0xA0);
	case 0xF0:	// Special case in block size = 4, requires next byte >= 0x90, or	(Overlong!)
		return ($next >= 0x90);
	case 0xF4:	// Special case in block size = 4, requires next byte < 0x90, or	(Out of range!)
		return ($next < 0x90);
	case 0xF5: case 0xF6: case 0xF7:		// Special case in block size = 4	(Out of range!)
	case 0xF8: case 0xF9: case 0xFA: case 0xFB:	// Special case in block size = 5	(Out of range!)
	case 0xFC: case 0xFD:				// Special case in block size = 6	(Out of range!)
		return false;
	}
	return true;
}


// $s: String to be parsed
// $r: Replacing character
function clean_utf8($s, $r)
{
	static $c2n = array(), $ovl = array(), $is_initialized = false;

//	$is_modified = false;
	$r = chr(ord($r) & 0x7F);

	if (!$is_initialized)
	{
		$c2n = array_fill(0x00, 0x100, 0);
		for ($i = 0x00; $i < 0x80; $i++) { $c2n[$i] = 1; }	// Leading characters, for block size = 1 (Valid single character)
		//for ($i = 0x80; $i < 0xC0; $i++) { $c2n[$i] = 0; }	// Range of non-leading characters
		for ($i = 0xC0; $i < 0xE0; $i++) { $c2n[$i] = 2; }	// Leading characters, for block size = 2
		for (; $i < 0xF0; $i++) { $c2n[$i] = 3; }		// Leading characters, for block size = 3
		for (; $i < 0xF8; $i++) { $c2n[$i] = 4; }		// Leading characters, for block size = 4
		for (; $i < 0xFC; $i++) { $c2n[$i] = 5; }		// Leading characters, for block size = 5
		for (; $i < 0xFE; $i++) { $c2n[$i] = 6; }		// Leading characters, for block size = 6
		for (; $i < 0x100; $i++) { $c2n[$i] = 7; }		// This is invalid, and will be screened in overlong (ovl) checking!
	//	Marking of special leading character, that requires overlong (ovl) check
		{
			$ovl = array_fill(0x00, 0x100, false);
			$ovl[0xC0] = $ovl[0xC1] = 	// Block Size = 2, but immediately identified as overlong stream
			$ovl[0xE0] = 			// Block Size = 3, may consist overlong stream	(req. checking)
			$ovl[0xED] =			// Block Size = 3, may consist illegal "surrogate pairs"
			$ovl[0xF0] = 			// Block Size = 4, may consist overlong stream	(req. checking)
			$ovl[0xF4] = 			// Block Size = 4, may goes out of range	(req. checking)
			$ovl[0xF5] = $ovl[0xF6] =
			$ovl[0xF7] = 			// Block Size = 4, but immediately found beyond the range of UTF-8 page 
			$ovl[0xF8] = $ovl[0xF9] = $ovl[0xFA] =
			$ovl[0xFB] =			// Block Size = 5, obviously beyond the limit of UTF-8 page boundary of 0x10FFFF
			$ovl[0xFC] = $ovl[0xFD] =	// Block Size = 6, obviously beyond the limit of UTF-8 page boundary of 0x10FFFF
			$ovl[0xFE] = $ovl[0xFF] = true;	// Invalid characters in UTF-8 specification
		}
		$is_initialized = true;
	}

	for ($i = 0, $sz = strlen($s); $i < $sz; )
	{
		// Speed ride to affected zone (Critical Performance Boost, unless your $s is too large that breaks preg_match()[!])
		{
			if (preg_match('/[^\x00-\x7F]/', $s, $matches, PREG_OFFSET_CAPTURE, $i) == 0) break;
			$i = $matches[0][1];	// Jump to scene
		}
		$v = ord($s[$i]);
		$nb = $c2n[$v];
		if ($nb == 1)
		{	// Good character, short cut to get out...
			$i++;
		}
		else if (!$nb || ($ovl[$v] && !check_overlong($v, ord($s[$i+1]))))
		{	// Invalid UTF-8 character, or
			// Overlong / Outbound of Range check applied by failed
			$s[$i++] = $r;
//			$is_modified = true;
		}
		else
		{	// Standard multi-byte validation
			$j = $i;
			while (--$nb && !$c2n[ord($s[++$i])]);
			if (!$nb)
			{	// Good block inspected, next is next...
				$i++;
			}
			else
			{	// Invalid UTF-8 stream found at *c, replace previous things with "r"
				do
				{
					$s[$j++] = $r;
				} while ($j != $i);
//				$is_modified = true;
			}
		}
	}

	return $s;
}

?>
