<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: *");
if (filter_input(INPUT_SERVER, "REQUEST_METHOD") === "OPTIONS")
    die();

$url = filter_input(INPUT_SERVER, "QUERY_STRING");
$infopos = strrpos($url, "info");
$urlbase = substr($url, 0, 6) . "/" . substr($url, 6, $infopos - 6);
$json = file_get_contents($urlbase . "info");
$info = json_decode($json, TRUE);
$type = $info["data_type"];
if ($type !== "float32")
    die($type . " ? float32");
$channels = $info["num_channels"];
$scale = $info["scales"][0];
$key = $scale["key"];
$tilebase = $urlbase . $key . "/";
$width = $scale["size"][0];
$height = $scale["size"][1];
$tile_width = $scale["chunk_sizes"][0][0];
$tile_height = $scale["chunk_sizes"][0][1];
$encoding = $scale["encoding"];
if ($encoding !== "raw")
    die($type . " ? float32");
$image = imagecreatetruecolor($width, $height);
$curlopt = array(CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_FOLLOWLOCATION => TRUE);
$mh = curl_multi_init();
curl_multi_setopt($mh, CURLMOPT_PIPELINING, 3);
for ($y = 0; $y < $height; $y += $tile_height) {
    $yheight = min($tile_height, $height - $y);
    for ($x = 0; $x < $width; $x += $tile_width) {
        $xwidth = min($tile_width, $width - $x);
        $ch = curl_init($tilebase . $x . "-" . ($x + $xwidth) . "_" . $y . "-" . ($y + $yheight) . "_0-1");
        curl_setopt_array($ch, $curlopt);
        curl_setopt($ch, CURLOPT_PRIVATE, $x . ":" . $y . ":" . $xwidth . ":" . $yheight);
        curl_multi_add_handle($mh, $ch);
    }
}
do {
    curl_multi_exec($mh, $still_running);
    curl_multi_select($mh);
    while ($info = curl_multi_info_read($mh)) {
        if ($info["msg"] !== CURLMSG_DONE)
            die($info["msg"] . "? CURLMSG_DONE");
        if ($info["result"] !== CURLE_OK)
            die($info["result"] . "? CURLE_OK");
        $ch = $info["handle"];
        $tile = curl_multi_getcontent($ch);
        $tiledata = unpack("N*", $tile);
        list($x, $y, $xwidth, $yheight) = explode(":", curl_getinfo($ch, CURLINFO_PRIVATE));
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $x = intval($x);
        $y = intval($y);
        $xwidth = intval($xwidth);
        $yheight = intval($yheight);
        if ($x === $tile_width || $y === $tile_height) {
            $color = random_int(0, 0xFFFFFF);
            for ($ty = 0; $ty < $yheight; $ty++)
                for ($tx = 0; $tx < $xwidth; $tx++)
                    imagesetpixel($image, $x + $tx, $y + $ty, $color);
        } elseif ($x < $width / 2 || $y < $height / 2)
            for ($ty = 0; $ty < $yheight; $ty++)
                for ($tx = 0; $tx < $xwidth; $tx++)
                    imagesetpixel($image, $x + $tx, $y + $ty, $tiledata[1 + $tx + $ty * $xwidth + $xwidth * $yheight]);
        else
            for ($ty = 0; $ty < $yheight; $ty++)
                for ($tx = 0; $tx < $xwidth; $tx++)
                    imagesetpixel($image, $x + $tx, $y + $ty, $tiledata[1 + $tx + $ty * $xwidth]);
    }
} while ($still_running);

curl_multi_close($mh);
header("Content-Type: image/png");
imagepng($image);
