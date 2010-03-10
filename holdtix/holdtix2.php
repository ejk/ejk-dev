#!/usr/bin/php5 -q
<?php

$time_start = microtime(true);

// CONFIGURATION OPTIONS ///////////////////////////////////////////////////////

// library holidays in date("md") format
$holidays = array( 
  '0101',
  '0412',
  '0525',
  '0704',
  '0907',
  '1111',
  '1126',
  '1224',
  '1225',
);

// Branch printers
$printers = array(
  'd'  => 'zebra1',
  'd2' => 'zebra2',
  'm'  => 'zebra3',
  'm2' => 'zebra7',
  'p'  => 'zebra6',
  't'  => 'zebra8',
  't2' => 'zebra9',
  'w'  => 'zebra4',
);

// Parse the email /////////////////////////////////////////////////////////////

putenv('GDFONTPATH=' . realpath('/usr/local/php/fpdf/ttf/'));
$email = file_get_contents('/tmp/tstemail');
$item = hold_item_info($email);
$printer = lookup_printer($item);

if (stripos($item['CALL NUMBER'], "Large Type") !== FALSE) {
  // Check to see if we need a mailing label for the Large Type item
  $xmlurl="http://irma.aadl.org/xmlopac/b{$item['BARCODE']}?noexclude=WXROOT.Heading.Title.IIIRECORD&links=i1-100";
  $xmlraw = file_get_contents($xmlurl);
  $xml = simplexml_load_string($xmlraw);
  
  $bib_items = array();
  $linkfield = (array) $xml->Heading->Title->IIIRECORD->LINKFIELD;
  if ($linkfield) {
    foreach ($linkfield['Link'] as $bib_item) {
      $irecord = ($bib_item->IIIRECORD ? (array)$bib_item->IIIRECORD : (array)$bib_item);
      $inum = (string)$irecord['RECORDINFO']->RECORDKEY;
      if ($inum) {
        foreach ($irecord['VARFLDPRIMARYALTERNATEPAIR'] as $ifield) {
          if ($fieldname = (string) $ifield->VARFLDPRIMARY->VARFLD->HEADER->NAME) {
            $fieldvalue = (string) $ifield->VARFLDPRIMARY->VARFLD->DisplayForm;
            $bib_items[$fieldname][$inum] = $fieldvalue;
          }
        }
      }
    }
  }
  if ($itemnum = array_search($item['BARCODE'], $bib_items['BARCODE'])) {
    // Barcode Found
    if (preg_match('/P#=([0-9]{7})/', $bib_items['HOLD'][$itemnum], $matches)) {
      include('patronapi.php');
      $pnum = $matches[1];
      $pdata = get_patronapi_data($pnum);
      if ($pdata['PCODE3'] == 'WLBPD') {
        $address_array = explode('$', $pdata['ADDRESS']);
        foreach ($address_array as &$address_line) {
          $address_line = trim($address_line);
        }
        $mail_address = $item['pfname'] . ' ' . $item['plname'] . "\n" .
                        implode("\n", $address_array);
      }
    }
  }
}

// Create the image ////////////////////////////////////////////////////////////

$unique = time() . rand(2000000, 6000000);
$x = 350;
$y = 350;
$font = 'arialbd';

$im = imagecreatetruecolor($x, $y);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefill($im, 0, 0, $white);

if ($mail_address) { 
  // MAILING LABEL
  $to_box = imagettfbbox(18, 0, $font, $mail_address);
  imagettftext($im, 18, 0, $x-$to_box[2], $y-$to_box[3], $black, $font, $mail_address);
} else {
  // SPINE LABEL
  $spine_name = $item['PLNAME'];
  if ($item['PFNAME']) { $spine_name .= ", {$item['PFNAME'][0]}."; }

  $holdtil = (time() + (6 * 86400));
  while (in_array(date("md", $holdtil), $holidays))
    $holdtil += 86400;
  $holdtilfmt = date("n/j", $holdtil);
  $canceldate = date("m-d-Y", $holdtil);
  
  $details = 'Callnum: ' . $item['CALL NUMBER'] . "\n" .
             'Barcode: ' . $item['BARCODE'] . "\n" .
             'Pickup location: ' . $item['PICKUP AT'] . "\n" .
             'Hold until: ' . $canceldate;
  
  $imBarcode = imagecreatefrompng("http://laluba.aadl.org/bcode.php?input={$item['BARCODE']}");
  $imBarcode = imagerotate($imBarcode, 180, 0);
  $imLogo = imagecreatefromgif("http://www.aadl.org/staticimages/aadllogo.gif");
  $imLogo = imagerotate($imLogo, 180, 0);
  $per = 0.6;
  $imLogoSmall = imagecreatetruecolor(ImageSX($imLogo)*$per, ImageSY($imLogo)*$per);
  imagecopyresampled($imLogoSmall, $imLogo, 0, 0, 0, 0, ImageSX($imLogo)*$per, ImageSY($imLogo)*$per, ImageSX($imLogo), ImageSY($imLogo));
  
  imagettftext($im, 28, 0, 20, 250, $black, $font, $spine_name);
  imagefilledrectangle($im, 20, 210, 345, 212, $black);
  imagettftext($im, 12, 90, 340, 250, $black, $font, $holdtilfmt);

  imagecopymerge($im , $imBarcode, 170, 140, 0, 0, ImageSX($imBarcode), ImageSY($imBarcode), 100);
  imagecopymerge($im , $imLogoSmall, 10, 10, 0, 0, ImageSX($imLogoSmall), ImageSY($imLogoSmall), 100);

  imagettftext($im, 12, 180, 350, 120, $black, $font, $details);
}

imagepng($im, "/tmp/pic$unique.png");
imagedestroy($im);

if ($printer) {
  //exec("/usr/bin/lp -d $printer /tmp/pic$unique.png");
}

unlink("/tmp/pic$unique.png");

// Send Email notice?
if (!empty($item['EMAIL'])) {
  $title = $item['TITLE'];
  $pickuploc = $item['PICKUP AT'];
  $mailholdtil = date("l, F j", $holdtil);
  if (stripos($pickuploc, 'pitts') !== FALSE) {
    $locker_ad_percentage = 100; // integer between 1 and 100
    if (rand(0,99) < $locker_ad_percentage) {
      exec("echo " . date("Y-m-d H:i:s") . " :: " . $item['EMAIL'] . " >> /tmp/lockerinvite.log");
      $mail_message .= "We're testing out a new service! Would you like to request a locker for outdoor or after hours pickup of this item? Visit https://www.aadl.org/myaccount/locker for details.\n\n";
    }
  }
  $mail_message .= "Your request for \"$title\" is now ready for pickup at the $pickuploc and will be held until library closing on $mailholdtil.\n";
  $mail_message .= "For questions visit our website at http://www.aadl.org/contactus or call 327-4219.\n" .
                   "You can log in to your account at http://aadl.org/myaccount .\n" . 
                   "Thank you for using the Ann Arbor District Library.\n\n----------------------------------------\n\nRequest Details:\n";

  // Tweak item details
  $pname = $item['PFNAME'] . " " . $item['PLNAME'];
  $details = array('Title' => $title, 'Author' => $item['AUTHOR'], 'Requested by' => trim($pname), 'Pickup at' => $pickuploc);
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
    $msg = date("Ymd H:i:s") . " SUCCESS: $mail_to for item " . $item['BARCODE'] . " \($title\)";
  else
    $msg = date("Ymd H:i:s") . " **ERR**: $mail_to for item " . $item['BARCODE'] . " \($title\)";
  exec("echo $msg >> /tmp/holdtix.log");
}

$time = microtime(true) - $time_start;
echo "\nExecution time: $time seconds\n";

// Functions Below /////////////////////////////////////////////////////////////

function hold_item_info($email) {
  $item = array();
  foreach (explode("\n", $email) as $line) {
    if ($separator = strpos($line, ': ')) {
      $id = trim(substr($line, 0, $separator));
      $data = trim(substr($line, $separator+1));
      $item[$id] = $data;
    }
  }
  
  // Special field handling
  $title_arr = explode('/', $item['TITLE']);
  $item['TITLE'] = trim($title_arr[0]);
  $item['AUTHOR'] = ($title_arr[1] ? trim($title_arr[1]) : 'N/A');
  $pname_arr = explode(',', $item['PATRON NAME']);
  $item['PLNAME'] = trim($pname_arr[0]);
  if ($pname_arr[1]) {
    $item['PFNAME'] = trim($pname_arr[1]);
  }
print_r($item);
  return $item;
}

function lookup_printer($hold_info) {
  global $printers;

  if (stripos($hold_info['To'], 'holdtixd2') !== FALSE) {
    return $printers['d2'];
  } else if (stripos($hold_info['To'], 'holdtixm2') !== FALSE) {
    return $printers['m2'];
  } else if (stripos($hold_info['To'], 'holdtixt2') !== FALSE) {
    return $printers['t2'];
  } else if (stripos($hold_info['PICKUP AT'], 'downtown') !== FALSE) {
    return $printers['d'];
  } else if (stripos($hold_info['PICKUP AT'], 'mallett') !== FALSE) {
    return $printers['m'];
  } else if (stripos($hold_info['PICKUP AT'], 'pitts') !== FALSE) {
    return $printers['p'];
  } else if (stripos($hold_info['PICKUP AT'], 'traver') !== FALSE) {
    return $printers['t'];
  } else if (stripos($hold_info['PICKUP AT'], 'west') !== FALSE) {
    return $printers['w'];
  }
}
?>
