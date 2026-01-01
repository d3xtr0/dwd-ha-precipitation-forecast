<?php
// Usage: rain_radar.php?lat=52.520008&lng=13.404954
// Returns JSON with 12 forecasted precipitation values (mm/h) for each 5-minute step

date_default_timezone_set('Europe/Berlin');

// Color -> representative mm/h mapping (midpoints or representative values)
function get_color_map()
{
	return [
		'255,255,255' => 0.0,
		'51,255,255'  => 0.1,
		'26,204,154'  => 0.2,
		'1,153,52'    => 0.4,
		'77,179,27'   => 1.0,
		'153,204,1'   => 2.0,
		'204,230,1'   => 3.0,
		'255,255,1'   => 5.0,
		'255,196,1'   => 7.5,
		'255,137,1'   => 10.0,
		'255,69,1'    => 15.0,
		'254,0,0'     => 30.0,
		'229,0,76'    => 45.0,
		'204,0,152'   => 75.5,
		'102,0,203'   => 100.0,
		'0,0,254'     => 150.0,
	];
}

function build_bbox($lat, $lng)
{
	// Use small box around given lat/lng;
	$d = 0.001;
	$tl_lat = $lat + $d;
	$tl_lng = $lng - $d;
	$br_lat = $lat - $d;
	$br_lng = $lng + $d;
	// BBOX ordering expected: TL(LNG),BR(LAT),BR(LNG),TL(LAT)
	return [$tl_lng, $br_lat, $br_lng, $tl_lat];
}

function build_wms_url($bbox, $timeUtcIso)
{
	$base = 'https://maps.dwd.de/geoserver/dwd/ows';
	$params = [
		'SERVICE' => 'WMS',
		'VERSION' => '1.3.0',
		'REQUEST' => 'GetMap',
		'FORMAT'  => 'image/png',
		'TRANSPARENT' => 'true',
		'LAYERS'  => 'Radar_rv_product_1x1km_ger',
		'TIME'    => $timeUtcIso,
		'WIDTH'   => 1,
		'HEIGHT'  => 1,
		'STYLES'  => '',
		'BBOX'    => implode(',', $bbox),
	];
	return $base . '?' . http_build_query($params);
}

function fetch_image_bytes($url, &$err = null)
{
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 8);
	curl_setopt($ch, CURLOPT_USERAGENT, 'rain_radar_php/1.0');
	$data = curl_exec($ch);
	if ($data === false) {
		$err = curl_error($ch);
		curl_close($ch);
		return false;
	}
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($code < 200 || $code >= 300) {
		$err = "HTTP $code";
		return false;
	}
	return $data;
}

function read_pixel_color_from_png_bytes($bytes)
{
	$im = @imagecreatefromstring($bytes);
	if ($im === false) return null;
	$c = imagecolorat($im, 0, 0);
	$r = ($c >> 16) & 0xFF;
	$g = ($c >> 8) & 0xFF;
	$b = $c & 0xFF;
	imagedestroy($im);
	return [$r, $g, $b];
}

// Parse inputs
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : 0;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : 0;

$colorMap = get_color_map();

// Build start time: floor to previous 5-minute mark in Europe/Berlin
$tz = new DateTimeZone('Europe/Berlin');
$now = new DateTime('now', $tz);
$minute = (int)$now->format('i');
$floored = floor($minute / 5) * 5;
$now->setTime((int)$now->format('H'), (int)$floored, 0);

$results = [];
$bbox = build_bbox($lat, $lng);

for ($i = 0; $i < 12; $i++) {
	$inst = clone $now;
	if ($i > 0) {
		$inst->modify('+' . (5 * $i) . ' minutes');
	}
	// local and utc formats
	$localIso = $inst->format('Y-m-d\TH:i:00P');
	$utc = clone $inst;
	$utc->setTimezone(new DateTimeZone('UTC'));
	// TIME param expects something like 2025-12-08T17:40:00.000Z
	$timeUtcIso = $utc->format('Y-m-d\TH:i:00') . '.000Z';

	$url = build_wms_url($bbox, $timeUtcIso);

	$entry = [
		'time_local' => $localIso,
		'time_utc'   => $utc->format('Y-m-d\TH:i:00\Z'),
		'value'      => null,
		'color'      => null,
		'url'        => $url,
		'status'     => 'error',
	];

	$err = null;
	$bytes = fetch_image_bytes($url, $err);
	if ($bytes === false) {
		$entry['status'] = 'error';
		$entry['error'] = 'fetch_failed: ' . $err;
		$results[] = $entry;
		continue;
	}

	$rgb = read_pixel_color_from_png_bytes($bytes);
	if ($rgb === null) {
		$entry['status'] = 'error';
		$entry['error'] = 'invalid_image';
		$results[] = $entry;
		continue;
	}

	$key = implode(',', $rgb);
	$entry['color'] = $key;
	if (array_key_exists($key, $colorMap)) {
		$entry['value'] = $colorMap[$key];
		$entry['status'] = 'ok';
	} else {
		// unknown color: return nearest by euclidean distance in RGB space
		$best = null;
		$bestDist = PHP_INT_MAX;
		$bestVal = null;
		foreach ($colorMap as $k => $val) {
			[$cr, $cg, $cb] = array_map('intval', explode(',', $k));
			$dist = ($cr - $rgb[0]) * ($cr - $rgb[0]) + ($cg - $rgb[1]) * ($cg - $rgb[1]) + ($cb - $rgb[2]) * ($cb - $rgb[2]);
			if ($dist < $bestDist) {
				$bestDist = $dist;
				$best = $k;
				$bestVal = $val;
			}
		}
		// If the nearest is close enough (tolerance), use it; else leave null but report nearest
		if ($bestDist <= 1000) {
			$entry['value'] = $bestVal;
			$entry['status'] = 'ok_nearest';
			$entry['nearest_color'] = $best;
			$entry['distance'] = $bestDist;
		} else {
			$entry['value'] = 0.0;
			$entry['status'] = 'unknown_color';
			$entry['distance'] = $bestDist;
			$entry['nearest_color'] = $best;
		}
	}

	$results[] = $entry;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
	'location' => ['lat' => $lat, 'lng' => $lng],
	'generated' => (new DateTime('now', $tz))->format('Y-m-d\TH:i:sP'),
	'entries' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
