<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Paris');
if(!isset($_GET['lat']) || !isset($_GET['lon'])){
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Chargement...</title>
<script>
if(navigator.geolocation){
  navigator.geolocation.getCurrentPosition(function(position){
    var lat = position.coords.latitude;
    var lon = position.coords.longitude;
    window.location.href = window.location.pathname + "?lat=" + lat + "&lon=" + lon;
  }, function(){
    window.location.href = window.location.pathname + "?lat=43.8812842&lon=4.834552";
  });
} else {
  window.location.href = window.location.pathname + "?lat=43.8812842&lon=4.834552";
}
</script>
</head>
<body>
<p>Chargement, veuillez patienter...</p>
</body>
</html>
<?php
exit;
}
$latitude = floatval($_GET['lat']);
$longitude = floatval($_GET['lon']);
$ch = curl_init("http://ip-api.com/json/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
$geo = json_decode($response, true);
if($geo && isset($geo['status']) && $geo['status']=='success' && isset($geo['city'])){
  $city = $geo['city'];
} else {
  $city = 'Inconnue';
}
$localNow = new DateTime('now', new DateTimeZone('Europe/Paris'));
$observer = "007";
$wsdl_url = 'https://vo.imcce.fr/webservices/miriade/miriade.php?wsdl';
try {
    $soapClient = new SoapClient($wsdl_url, ['trace' => 1, 'exceptions' => true]);
} catch(Exception $e) {
    die("SOAP Error: " . $e->getMessage());
}
function localToUTC($localDT) {
    $utcDT = clone $localDT;
    $utcDT->setTimezone(new DateTimeZone('UTC'));
    return $utcDT->format('Y-m-d H:i:s');
}
function julianDate($dt) {
    $year = (int)$dt->format("Y");
    $month = (int)$dt->format("m");
    $day = (float)$dt->format("d");
    $hour = (float)$dt->format("H");
    $minute = (float)$dt->format("i");
    $second = (float)$dt->format("s");
    $day += ($hour + ($minute + $second/60)/60)/24;
    if ($month <= 2) {
        $year -= 1;
        $month += 12;
    }
    $A = floor($year/100);
    $B = 2 - $A + floor($A/4);
    $JD = floor(365.25*($year + 4716)) + floor(30.6001*($month+1)) + $day + $B - 1524.5;
    return $JD;
}
function equatorialToHorizontal($ra, $dec, $localDT, $longitude, $latitude) {
    $utcNow = clone $localDT;
    $utcNow->setTimezone(new DateTimeZone('UTC'));
    $JD = julianDate($utcNow);
    $GMST = 280.46061837 + 360.98564736629 * ($JD - 2451545.0);
    $GMST = fmod($GMST, 360);
    if ($GMST < 0) { $GMST += 360; }
    $LST = $GMST + $longitude;
    $LST = fmod($LST, 360);
    if ($LST < 0) { $LST += 360; }
    $LST_rad = deg2rad($LST);
    $lat_rad = deg2rad($latitude);
    $HA = $LST_rad - $ra;
    $HA = fmod($HA + pi(), 2*pi()) - pi();
    $alt = asin(sin($dec)*sin($lat_rad) + cos($dec)*cos($lat_rad)*cos($HA));
    $az = acos((sin($dec) - sin($alt)*sin($lat_rad)) / (cos($alt)*cos($lat_rad)));
    if(sin($HA) > 0) {
        $az = 2*pi() - $az;
    }
    return ['az' => rad2deg($az), 'alt' => rad2deg($alt)];
}
function parseSexagesimal($str, $isRA = false) {
    $parts = preg_split('/\s+/', trim($str));
    if(count($parts) < 3){
        return 0;
    }
    if($isRA){
        $hours = floatval($parts[0]);
        $minutes = floatval($parts[1]);
        $seconds = floatval($parts[2]);
        $deg = ($hours + $minutes/60 + $seconds/3600) * 15;
    } else {
        $deg = floatval($parts[0]);
        $minutes = floatval($parts[1]);
        $seconds = floatval($parts[2]);
        $sign = ($deg < 0) ? -1 : 1;
        $deg = abs($deg) + $minutes/60 + $seconds/3600;
        $deg *= $sign;
    }
    return deg2rad($deg);
}
function getPrefixedName($body) {
    $bodyUpper = strtoupper($body);
    if($bodyUpper === 'MOON') return 's:' . $bodyUpper;
    if($bodyUpper === 'PLUTO') return 'dp:' . $bodyUpper;
    return 'p:' . $bodyUpper;
}
function getTrajectory($client, $body, $startDT, $nbSteps, $stepMinutes, $observer, $longitude, $latitude) {
    $utcEpoch = localToUTC($startDT);
    $stepDayFraction = $stepMinutes / 1440;
    $request_data = [
        'name' => getPrefixedName($body),
        'type' => '',
        'epoch' => $utcEpoch,
        'nbd' => $nbSteps,
        'step' => (string)$stepDayFraction,
        'tscale' => 'UTC',
        'observer' => $observer,
        'theory' => 'INPOP',
        'teph' => 0,
        'tcoor' => 1,
        'rplane' => 0,
        'mime' => 'text/csv',
        'output' => '',
        'extrap' => 0
    ];
    try {
        $result = $client->ephemcc($request_data);
    } catch(Exception $e) {
        return [];
    }
    $lines = explode(";", $result->result);
    $trajectory = [];
    $dt = clone $startDT;
    $index = 0;
    foreach($lines as $line){
        $line = trim($line);
        if($line === '' || strpos($line, '#') === 0) continue;
        $cols = array_map('trim', explode(",", $line));
        if(count($cols) >= 3){
            $ra = parseSexagesimal($cols[1], true);
            $dec = parseSexagesimal($cols[2], false);
            $altaz = equatorialToHorizontal($ra, $dec, $dt, $longitude, $latitude);
            $trajectory[] = ['x' => (float)$altaz['az'], 'y' => (float)$altaz['alt'], 'time' => $dt->format('H:i')];
            $index++;
            if($index >= $nbSteps) break;
            $dt->modify("+{$stepMinutes} minutes");
        }
    }
    return $trajectory;
}
function getAltAzMiriade($client, $body, $localDT, $observer, $longitude, $latitude) {
    $utcEpoch = localToUTC($localDT);
    $request_data = [
        'name' => getPrefixedName($body),
        'type' => '',
        'epoch' => $utcEpoch,
        'nbd' => 1,
        'step' => '1d',
        'tscale' => 'UTC',
        'observer' => $observer,
        'theory' => 'INPOP',
        'teph' => 0,
        'tcoor' => 1,
        'rplane' => 0,
        'mime' => 'text/csv',
        'output' => '',
        'extrap' => 0
    ];
    try {
        $result = $client->ephemcc($request_data);
        if(!isset($result->result)) return ['az' => null, 'alt' => null];
        $lines = explode(";", $result->result);
        foreach($lines as $line){
            $line = trim($line);
            if($line === '' || strpos($line, '#') === 0) continue;
            $cols = array_map('trim', explode(",", $line));
            if(count($cols) >= 3){
                $ra = parseSexagesimal($cols[1], true);
                $dec = parseSexagesimal($cols[2], false);
                return equatorialToHorizontal($ra, $dec, $localDT, $longitude, $latitude);
            }
        }
    } catch(Exception $e) {
        return ['az' => null, 'alt' => null];
    }
    return ['az' => null, 'alt' => null];
}
function callMiriadeRaw($p, $name, $date, $client, $observer) {
    $body = $p . $name;
    $request_data = [
        'name' => $body,
        'type' => '',
        'epoch' => $date,
        'nbd' => 5,
        'step' => '1h',
        'tscale' => 'UTC',
        'observer' => $observer,
        'theory' => 'INPOP',
        'teph' => 0,
        'tcoor' => 1,
        'rplane' => 0,
        'mime' => 'text/csv',
        'output' => '',
        'extrap' => 0
    ];
    try {
        $result = $client->ephemcc($request_data);
        return $result->result;
    } catch(Exception $e) {
        return "Erreur: " . $e->getMessage();
    }
}
$localYear = (int)$localNow->format('Y');
$localMonth = (int)$localNow->format('m');
$localDay = (int)$localNow->format('d');
$startOfDay = new DateTime($localNow->format('Y-m-d 00:00:00'), new DateTimeZone('Europe/Paris'));
$sunTrajectory = getTrajectory($soapClient, 'sun', $startOfDay, 1440, 1, $observer, $longitude, $latitude);
$moonTrajectory = getTrajectory($soapClient, 'moon', $startOfDay, 1440, 1, $observer, $longitude, $latitude);
$planetBodies = ['mercury','venus','mars','jupiter','saturn','uranus','neptune','pluto','sun','moon'];
$planetPositions = [];
foreach($planetBodies as $body){
    $instDT = new DateTime($localNow->format('Y-m-d H:i:00'), new DateTimeZone('Europe/Paris'));
    $pos = getAltAzMiriade($soapClient, $body, $instDT, $observer, $longitude, $latitude);
    $planetPositions[$body] = ['az' => (float)$pos['az'], 'alt' => (float)$pos['alt']];
}
$objects = [
    ['prefix' => 'p:', 'name' => 'mercury', 'label' => 'Mercury'],
    ['prefix' => 'p:', 'name' => 'venus', 'label' => 'Venus'],
    ['prefix' => 'p:', 'name' => 'earth', 'label' => 'Earth'],
    ['prefix' => 'p:', 'name' => 'mars', 'label' => 'Mars'],
    ['prefix' => 'p:', 'name' => 'jupiter', 'label' => 'Jupiter'],
    ['prefix' => 'p:', 'name' => 'saturn', 'label' => 'Saturn'],
    ['prefix' => 'p:', 'name' => 'uranus', 'label' => 'Uranus'],
    ['prefix' => 'p:', 'name' => 'neptune', 'label' => 'Neptune'],
    ['prefix' => 'dp:', 'name' => 'pluto', 'label' => 'Pluto'],
    ['prefix' => 's:', 'name' => 'moon', 'label' => 'Moon'],
    ['prefix' => 'p:', 'name' => 'sun', 'label' => 'Sun']
];
$rawData = [];
foreach($objects as $obj){
    $raw = callMiriadeRaw($obj['prefix'], $obj['name'], localToUTC(new DateTime($localNow->format('Y-m-d H:i:00'), new DateTimeZone('Europe/Paris'))), $soapClient, $observer);
    $rawData[$obj['label']] = $raw;
}
$allPositions = [];
foreach($objects as $obj){
    $instDT = new DateTime($localNow->format('Y-m-d H:i:00'), new DateTimeZone('Europe/Paris'));
    $pos = getAltAzMiriade($soapClient, $obj['name'], $instDT, $observer, $longitude, $latitude);
    $allPositions[$obj['label']] = ['az' => (float)$pos['az'], 'alt' => (float)$pos['alt']];
}
$sunrise = null;
$sunset = null;
foreach($sunTrajectory as $point){
    if($point['y'] >= 0 && $sunrise === null){
        $sunrise = $point['time'];
    }
    if($point['y'] >= 0){
        $sunset = $point['time'];
    }
}
$moonrise = null;
$moonset = null;
foreach($moonTrajectory as $point){
    if($point['y'] >= 0 && $moonrise === null){
        $moonrise = $point['time'];
    }
    if($point['y'] >= 0){
        $moonset = $point['time'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Sky view - <?php echo $localNow->format('Y-m-d H:i:s'); ?></title>
<link rel="shortcut icon" href="https://webftts.com/Fond%20ecran/planete.png" type="image/x-icon">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js" crossorigin=""></script>

<style>
body{background-image:url('/Fond ecran/comp4.gif');background-repeat:no-repeat;background-attachment:scroll;background-size:100% 100%;background-position-x:0px;font-family:'Dancing Script', cursive;font-weight:normal;color:blue;width:70%}
h2,h3,h4{margin-left:10pt;color:blue}
a{text-decoration:none;color:blue}
a:hover{color:red}
table{margin-left:10pt;font-family:monospace;border-collapse:collapse;color:blue;text-align:center;width:auto;max-width:100%}
th,td{border:1px solid blue;padding:2px;color:blue}
pre {  padding: 10px; overflow-x: auto; }
canvas { max-width: auto; max-height: 600px; }
#map { width: auto; height: 500px; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<canvas id="skyChart"></canvas>
<script>
const horizon = [];
const zenith = [];
for(let a = 0; a <= 360; a++){
  horizon.push({x: a, y: 0});
  zenith.push({x: a, y: 90});
}
const sunTrajectory = <?php echo json_encode($sunTrajectory, JSON_NUMERIC_CHECK); ?>;
const moonTrajectory = <?php echo json_encode($moonTrajectory, JSON_NUMERIC_CHECK); ?>;
const planetPositions = <?php echo json_encode($planetPositions, JSON_NUMERIC_CHECK); ?>;
const planetDatasets = [];
const planetColors = { mercury: 'gray', venus: 'yellow', mars: 'red', jupiter: 'orange', saturn: 'goldenrod', uranus: 'lightblue', neptune: 'darkblue', pluto: 'purple', sun: 'gold', moon: 'red' };
for(const body in planetPositions){
  if(planetPositions[body].az !== null && planetPositions[body].alt !== null){
    planetDatasets.push({
      label: body.charAt(0).toUpperCase() + body.slice(1),
      data: [{x: planetPositions[body].az, y: planetPositions[body].alt}],
      backgroundColor: planetColors[body] || 'black',
      borderColor: planetColors[body] || 'black',
      pointRadius: 7,
      type: 'scatter',
      showLine: false
    });
  }
}
const horizonDataset = { label: 'Horizon', data: horizon, borderColor: 'rgba(135,206,235,0.1)', backgroundColor: 'rgba(135,206,235,0.1)', fill: false, pointRadius: 0, showLine: true };
const zenithDataset = { label: 'Zenith', data: zenith, borderColor: 'rgba(135,206,235,0.1)', backgroundColor: 'rgba(135,206,235,0.1)', fill: '-1', pointRadius: 0, showLine: true };
const sunDataset = { label: 'Sun trajectory', data: sunTrajectory, borderColor: 'gold', backgroundColor: 'gold', pointRadius: 2, showLine: false, fill: false };
const moonDataset = { label: 'Moon trajectory', data: moonTrajectory, borderColor: 'red', backgroundColor: 'red', pointRadius: 2, showLine: false, fill: false };
const allDatasets = [horizonDataset, zenithDataset, sunDataset, moonDataset].concat(planetDatasets);
const ctx = document.getElementById('skyChart').getContext('2d');
const skyChart = new Chart(ctx, {
  type: 'line',
  data: { datasets: allDatasets },
  options: {
    responsive: true,
    scales: {
      x: {
        type: 'linear',
        title: { display: true, text: 'Azimuth (degrees)' },
        min: 0,
        max: 360,
        ticks: {
          stepSize: 90,
          callback: function(value){
            const labels = {0:'N',90:'E',180:'S',270:'W',360:'N'};
            return labels[value] !== undefined ? labels[value] : value;
          }
        }
      },
      y: { title: { display: true, text: 'Altitude (degrees)' }, min: -100, max: 100 }
    },
    plugins: {
      legend: { position: 'right' },
      title: {
        display: true,
        text: 'Sky view at ' + <?php echo json_encode($localNow->format('Y-m-d H:i:s')); ?> + ' (Local Time) Location: ' + "<?php echo $latitude; ?>" + ', ' + "<?php echo $longitude; ?>"
      },
      tooltip: {
        callbacks: {
          label: function(context){
            var label = context.dataset.label || '';
            if(context.raw.time){
              label += ' - ' + context.raw.time;
            }
            return label;
          }
        }
      }
    },
    elements: { line: { tension: 0 } }
  }
});
</script>
<p>☀️ <?php echo $sunrise!==null?$sunrise:'N/A'; ?> - <?php echo $sunset!==null?$sunset:'N/A'; ?>&nbsp;&nbsp;<?php date_default_timezone_set("UTC"); $now=time(); $known=strtotime("2000-01-06 18:14:00"); $synodic=29.530588853; $phase=(($now-$known)/86400)/$synodic; $phase=$phase-floor($phase); $index=round($phase*8)%8; $phases=array("🌑","🌒","🌓","🌔","🌕","🌖","🌗","🌘"); echo "$phases[$index]"; ?><?php echo $moonrise!==null?$moonrise:'N/A'; ?> - <?php echo $moonset!==null?$moonset:'N/A'; ?></p>

<!--
<h1>Votre position</h1>
  <p>Latitude: <?php echo $latitude; ?>, Longitude: <?php echo $longitude; ?></p>
-->
  
  <div id="map"></div>
    <script>
      document.addEventListener('DOMContentLoaded', function(){
        var latitude = <?php echo $latitude; ?>;
        var longitude = <?php echo $longitude; ?>;
        var map = L.map('map').setView([latitude, longitude], 12);
        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
          attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
        }).addTo(map);
        L.marker([latitude, longitude]).addTo(map)
          .bindPopup('<img border="0" width="80" src="https://webftts.com/index_fichiers/image004.gif">')
          .openPopup();
      });
    </script>
    
    
<h2>Données Myriade brutes</h2>
<?php foreach($rawData as $label => $csv): ?>
  <h3><?php echo htmlspecialchars($label); ?></h3>
  <pre><?php echo str_replace(';', ';<br>', htmlspecialchars($csv)); ?></pre>

<?php endforeach; ?>


<h2>Coordonnées des corps célestes</h2>
<table>
  <thead>
    <tr>
      <th>Corps</th>
      <th>Azimuth</th>
      <th>Altitude</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($allPositions as $label => $pos): ?>
      <tr>
        <td><?php echo htmlspecialchars($label); ?></td>
        <td><?php echo ($pos['az'] !== null) ? $pos['az'] : 'N/A'; ?></td>
        <td><?php echo ($pos['alt'] !== null) ? $pos['alt'] : 'N/A'; ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>


<h2>Trajectoire Sun et Moon</h2>
<table border="1" cellspacing="0" cellpadding="4">
  <thead>
    <tr>
      <th>Heure</th>
      <th>Azimuth (Sun)</th>
      <th>Altitude (Sun)</th>
      <th>Azimuth (Moon)</th>
      <th>Altitude (Moon)</th>
    </tr>
  </thead>
  <tbody>
    <?php 
    // On prend le nombre minimum d'éléments entre les deux trajectoires
    $n = min(count($sunTrajectory), count($moonTrajectory));
    for($i = 0; $i < $n; $i++): 
      // On suppose que l'heure est identique pour les deux trajectoires à cet index
      $time = $sunTrajectory[$i]['time']; 
    ?>
      <tr>
        <td><?php echo htmlspecialchars($time); ?></td>
        <td><?php echo htmlspecialchars($sunTrajectory[$i]['x']); ?></td>
        <td><?php echo htmlspecialchars($sunTrajectory[$i]['y']); ?></td>
        <td><?php echo htmlspecialchars($moonTrajectory[$i]['x']); ?></td>
        <td><?php echo htmlspecialchars($moonTrajectory[$i]['y']); ?></td>
      </tr>
    <?php endfor; ?>
  </tbody>
</table>





</body>
</html>
