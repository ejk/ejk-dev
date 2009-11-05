#!/usr/bin/php5 -q
<?php

//putenv('GDFONTPATH=' . realpath('/usr/share/fonts/truetype/freefont'));
putenv('GDFONTPATH=' . realpath('/usr/local/php/fpdf/ttf/'));

$unique = time() . rand(2000000, 6000000);


$fd = fopen("php://stdin", "r");
$email = "";
while (!feof($fd)) {
	$email .= fread($fd, 1024);
}
fclose($fd);

//file_put_contents("/tmp/infile", $email);


//$email = file_get_contents('/home/users/blybergj/dev/john_wrk/PHP/scratch/infile');
file_put_contents('/tmp/tstemail', $email);
$item = hold_item_info($email);

$x = 350;
$y = 350;


// Create the image
$im = imagecreatetruecolor($x, $y);
$imBarcode = imagecreatefrompng("http://frodo.aadl.org/bcode.php?input=$item[barcode]");
$imLogo = imagecreatefromgif("http://www.aadl.org/staticimages/aadllogo.gif");

// Create some colors
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);

imagefill($im, 0, 0, $$white);

imagefilledrectangle($im, 0, 0, $x, $y, $white);

$spine_name = $item[plname];
if ($item[pfname]) { $spine_name .= ', ' . strtoupper($item[pfname]{1}) . '.'; }

$holdtil = (time() + (6 * 86400));
$holidays = array("0101", "0412", "0525", "0704", "0907", "1111", "1126", "1224", "1225"); // date("md") format
while (in_array(date("md", $holdtil), $holidays))
  $holdtil += 86400;
$holdtilfmt = date("n/j", $holdtil);
$canceldate = date("m-d-Y", $holdtil);

$details =
//	'Title: ' . $item[title] . "\n" .
//	'Author: ' . $item[author] . "\n" .
	'Callnum: ' . $item[callnum] . "\n" .
	'Barcode: ' . $item[barcode] . "\n" .
//	'Held for: ' . $item[plname] . "\n" .
	'Pickup location: ' . $item[pickuploc] . "\n" .
	'Hold until: ' . $canceldate;

$font = 'arialbd';

imagettftext($im, 28, 0, 20, 250, $black, $font, $spine_name);
imagefilledrectangle($im, 20, 210, 345, 212, $black);
imagettftext($im, 12, 90, 340, 250, $black, $font, $holdtilfmt);

$imBarcode = imagerotate($imBarcode, 180, 0);
$imLogo = imagerotate($imLogo, 180, 0);

$per = 0.6;
$imLogoSmall = imagecreatetruecolor(ImageSX($imLogo)*$per, ImageSY($imLogo)*$per);
imagecopyresampled($imLogoSmall, $imLogo, 0, 0, 0, 0, ImageSX($imLogo)*$per, ImageSY($imLogo)*$per, ImageSX($imLogo), ImageSY($imLogo));

imagecopymerge($im , $imBarcode, 170, 140, 0, 0, ImageSX($imBarcode), ImageSY($imBarcode), 100);
imagecopymerge($im , $imLogoSmall, 10, 10, 0, 0, ImageSX($imLogoSmall), ImageSY($imLogoSmall), 100);

imagettftext($im, 12, 180, 350, 120, $black, $font, $details);

imagepng($im,"/tmp/pic$unique.png");
imagedestroy($im);

if ($item[printer]) {
	$printer = $item[printer];
	if ($item[plname]) { exec("/usr/bin/lp -d $printer /tmp/pic$unique.png"); }
}

unlink("/tmp/pic$unique.png");

// Send Email notice?
if (!empty($item[pemail])) {
  $title = $item[title];
  $pickuploc = $item[pickuploc];
  $mailholdtil = date("l, F j", $holdtil);
  $mail_message = "Your request for \"$title\" is now ready for pickup at the $pickuploc and will be held until library closing on $mailholdtil.\n" .
                  "For questions visit our website at http://www.aadl.org/contactus or call 327-4219.\n" .
                  "You can log in to your account at http://aadl.org/myaccount .\n" . 
									"Thank you for using the Ann Arbor District Library.\n\n----------------------------------------\n\nRequest Details:\n";

  // Tweak item details
  $pname = $item[pfname] . " " . $item[plname];
  $details = array('Title' => $item[title], 'Author' => $item[author], 'Requested by' => trim($pname), 'Pickup at' => $item[pickuploc]);
  foreach ($details as $key => $data) {
    $mail_message .= str_pad($key, 15) . ": $data\n";
  }

  $mail_to = $item[pemail];
  $mail_subject = "Library Hold is ready for pickup";
  $mail_headers = 'From: <virtualcirc@aadl.org>' . "\r\n" .
                  'Reply-To: <virtualcirc@aadl.org>' . "\r\n" .
                  'X-Mailer: PHP/' . phpversion()  . "\r\n" .
                  'Return-Path: <virtualcirc@aadl.org>';


  if (mail($mail_to, $mail_subject, $mail_message, $mail_headers, '-fvirtualcirc@aadl.org'))
    $msg = date("Ymd H:i:s") . " SUCCESS: $mail_to for item " . $item[barcode] . " \($title\)";
  else
    $msg = date("Ymd H:i:s") . " **ERR**: $mail_to for item " . $item[barcode] . " \($title\)";
  exec("echo $msg >> /tmp/holdtix.log");
}


// Functions Below

function hold_item_info($email) {

	$email_array = array_slice(explode("\n", $email), 18, 50);
	foreach($email_array as $email_line) {

		if (preg_match('/TITLE/', $email_line) && empty($item[title])) {
			$line_arr = explode(':', $email_line);
			$title_arr = explode('/', $line_arr[1]);
			$item[title] = trim($title_arr[0]);
			if ($title_arr[1]) { $item[author] = trim($title_arr[1]); } else { $item[author] = 'N/A'; }
		}
		if (preg_match('/CALL NUMBER/', $email_line)) {
			$line_arr = explode(':', $email_line);
			$item[callnum] = trim($line_arr[1]);
		}
		if (preg_match('/BARCODE/', $email_line)) {
			$line_arr = explode(':', $email_line);
			$item[barcode] = trim($line_arr[1]);
		}
		if (preg_match('/PATRON NAME/', $email_line)) {
			$line_arr = explode(':', $email_line);
			$pname_arr = explode(',', $line_arr[1]);
			$item[plname] = trim($pname_arr[0]);
			if ($pname_arr[1]) { $item[pfname] = $pname_arr[1]; }
		}
		if (preg_match('/ADDRESS/', $email_line)) {
			$line_arr = explode(':', $email_line);
			$item[paddress] = trim($line_arr[1]);
		}
		if (preg_match('/EMAIL/', $email_line)) {
			$line_arr = explode(':', $email_line);
			$item[pemail] = trim($line_arr[1]);
		}
		if (preg_match('/PICKUP AT/', $email_line)) {
			$line_arr = explode(':', $email_line);
			$item[pickuploc] = trim($line_arr[1]);
		}
		if (preg_match('/TELEPHONE/', $email_line)) {
			$line_arr = explode(':', $email_line);
			$item[telephone] = trim($line_arr[1]);
		}

	}
	// Hard Code Printer for Downtown #2
  $item[printer] = 'zebra2';

	return $item;
}


?>
