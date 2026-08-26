<?php
// What's Overhead? Complete single-file PHP aircraft display.
// Edit these values for the display location.
const HOME_LAT = xxx;
const HOME_LON = xxx;
const RADIUS_KM = 40;
const STATE_API = 'https://opensky-network.org/api/states/all';
const ROUTE_API = 'https://api.adsbdb.com/v0/callsign/';
const STATE_CACHE = '/tmp/whats_overhead_states.json';
const ROUTE_CACHE = '/tmp/whats_overhead_routes.json';
const STATE_TTL = 30;
const ROUTE_TTL = 900;

function json_get($url) {
    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'header' => "User-Agent: WhatsOverhead/1.0\r\n"
    ]]);
    $text = @file_get_contents($url, false, $ctx);
    return $text ? json_decode($text, true) : null;
}

function cached($file, $ttl) {
    if (!file_exists($file) || time() - filemtime($file) >= $ttl) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function distance_km($lat, $lon) {
    $x = deg2rad($lon - HOME_LON) * cos(deg2rad(HOME_LAT));
    $y = deg2rad($lat - HOME_LAT);
    return 6371 * sqrt($x * $x + $y * $y);
}

function compass($degrees) {
    $names = ['north', 'north-east', 'east', 'south-east',
              'south', 'south-west', 'west', 'north-west'];
    if ($degrees === null) return 'unknown';
    return $names[(int)(($degrees + 22.5) / 45) % 8];
}

function arrow($degrees) {
    $arrows = ['↑', '↗', '→', '↘', '↓', '↙', '←', '↖'];
    if ($degrees === null) return '•';
    return $arrows[(int)(($degrees + 22.5) / 45) % 8];
}

function relative_position($lat, $lon) {
    $north_south = $lat - HOME_LAT;
    $east_west = $lon - HOME_LON;
    if (abs($north_south) < .02 && abs($east_west) < .03) return 'nearby';
    $vertical = $north_south >= 0 ? 'north' : 'south';
    $horizontal = $east_west >= 0 ? 'east' : 'west';
    if (abs($north_south) > abs($east_west) * 2) return $vertical;
    if (abs($east_west) > abs($north_south) * 2) return $horizontal;
    return "$vertical-$horizontal";
}

function state_data() {
    $old = cached(STATE_CACHE, STATE_TTL);
    if ($old) return $old;

    $span = RADIUS_KM / 111;
    $url = STATE_API . '?' . http_build_query([
        'lamin' => HOME_LAT - $span,
        'lomin' => HOME_LON - $span,
        'lamax' => HOME_LAT + $span,
        'lomax' => HOME_LON + $span,
        'extended' => 1
    ]);
    $data = json_get($url);

    if (!$data) {
        $fallback = file_exists(STATE_CACHE)
            ? json_decode(file_get_contents(STATE_CACHE), true) : null;
        return $fallback ?: ['time' => time(), 'states' => [], 'stale' => true];
    }

    file_put_contents(STATE_CACHE, json_encode($data), LOCK_EX);
    return $data;
}

function route_lookup($callsign) {
    $callsign = strtoupper(trim(preg_replace('/[^A-Z0-9]/', '', $callsign)));
    if (!$callsign) return null;

    $cache = file_exists(ROUTE_CACHE)
        ? json_decode(file_get_contents(ROUTE_CACHE), true) : [];
    if (!is_array($cache)) $cache = [];

    if (isset($cache[$callsign]) &&
        time() - ($cache[$callsign]['time'] ?? 0) < ROUTE_TTL) {
        return $cache[$callsign]['route'];
    }

    $data = json_get(ROUTE_API . rawurlencode($callsign));
    $flight = $data['response']['flightroute'] ?? null;
    $route = null;

    if ($flight) {
        $route = [
            'airline' => $flight['airline']['name'] ?? null,
            'from' => $flight['origin']['iata_code']
                ?? $flight['origin']['icao_code'] ?? null,
            'from_name' => $flight['origin']['name'] ?? null,
            'to' => $flight['destination']['iata_code']
                ?? $flight['destination']['icao_code'] ?? null,
            'to_name' => $flight['destination']['name'] ?? null
        ];
    }

    $cache[$callsign] = ['time' => time(), 'route' => $route];
    file_put_contents(ROUTE_CACHE, json_encode($cache), LOCK_EX);
    return $route;
}

function aircraft_list() {
    $data = state_data();
    $aircraft = [];

    foreach ($data['states'] ?? [] as $s) {
        if ($s[5] === null || $s[6] === null || $s[8]) continue;
        $distance = distance_km($s[6], $s[5]);
        if ($distance > RADIUS_KM) continue;

        $callsign = trim($s[1] ?? '') ?: 'UNKNOWN';
        $route = route_lookup($callsign);
        $aircraft[] = [
            'id' => strtolower($s[0]),
            'call' => $callsign,
            'country' => $s[2] ?: 'Unknown',
            'lat' => $s[6], 'lon' => $s[5],
            'alt' => $s[13] ?? $s[7],
            'speed' => $s[9], 'track' => $s[10],
            'vertical' => $s[11], 'squawk' => $s[14],
            'distance' => round($distance),
            'where' => relative_position($s[6], $s[5]),
            'move' => compass($s[10]), 'arrow' => arrow($s[10]),
            'from' => $route['from'] ?? null,
            'from_name' => $route['from_name'] ?? null,
            'to' => $route['to'] ?? null,
            'to_name' => $route['to_name'] ?? null,
            'airline' => $route['airline'] ?? null
        ];
    }

    usort($aircraft, fn($a, $b) => $a['distance'] <=> $b['distance']);
    return [
        'updated' => $data['time'] ?? time(),
        'stale' => $data['stale'] ?? false,
        'aircraft' => $aircraft
    ];
}

function selected_aircraft($id) {
    foreach (aircraft_list()['aircraft'] as $a) {
        if ($a['id'] === strtolower($id)) return $a;
    }
    return ['error' => 'Aircraft no longer visible'];
}

header('Cache-Control: no-store');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/api') {
    header('Content-Type: application/json');
    echo json_encode(aircraft_list());
    exit;
}

if (preg_match('#^/detail/([a-f0-9]+)$#i', $path, $m)) {
    header('Content-Type: application/json');
    echo json_encode(selected_aircraft($m[1]));
    exit;
}
?>
<!doctype html>
<html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<title>What's Overhead?</title>
<style>
body{font:18px sans-serif;background:#10151c;color:#eee;margin:auto;max-width:650px;padding:18px}
h1{font-size:2em}.card{background:#202b38;border-radius:12px;padding:18px;margin:12px 0;cursor:pointer}
.arrow{font-size:2em;color:#6cf}.muted{color:#aab7c4}.route{font-size:1.2em}
button{font-size:1.1em;padding:12px;margin:8px;border-radius:8px}
#details{background:#263849;padding:20px;border-radius:12px;position:fixed;inset:10px;overflow:auto}
dt{color:#9fc8df;margin-top:10px}dd{margin:2px 0 0}
</style></head><body>
<h1>What's Overhead?</h1><p id="status" class="muted">Loading...</p>
<main id="list"></main>
<section id="details" hidden><button onclick="closeDetails()">← Back</button><div id="detail"></div></section>
<script>
const $=id=>document.getElementById(id);
const ft=m=>m==null?'Unknown':Math.round(m*3.28084).toLocaleString()+' ft';
const sp=m=>m==null?'Unknown':Math.round(m*1.94384)+' kt';
const route=a=>a.from||a.to?(a.from||'????')+' → '+(a.to||'????'):'likely route unknown';
const esc=s=>String(s??'Unknown').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function load(){try{let d=await fetch('/api').then(r=>r.json());
$('status').textContent=new Date(d.updated*1000).toLocaleTimeString()+(d.stale?' · cached data':' · live data')+' · '+d.aircraft.length+' aircraft';
$('list').innerHTML=d.aircraft.map(a=>`<article class="card" onclick="show('${esc(a.id)}')"><div class="route"><span class="arrow">${esc(a.arrow)}</span> <b>${esc(a.call)}</b> · <span class="muted">${esc(route(a))}</span></div><div class="muted">${a.distance} km ${esc(a.where)} of here at ${ft(a.alt)}</div><div>Travelling ${esc(a.move)} at ${sp(a.speed)}</div></article>`).join('')||'<p>No aircraft found.</p>';
}catch(e){$('status').textContent='Data unavailable';}}
async function show(id){let a=await fetch('/detail/'+encodeURIComponent(id)).then(r=>r.json());
$('detail').innerHTML=a.error?'<h2>'+esc(a.error)+'</h2>':`<h2>${esc(a.call)}</h2><p>${esc(route(a))} · ${esc(a.country)}</p><h2>Look ${esc(a.where)}<br>→ Travelling ${esc(a.move)}</h2><dl><dt>Likely route</dt><dd>${esc(a.from_name||a.from||'Unknown')} → ${esc(a.to_name||a.to||'Unknown')}</dd><dt>Airline</dt><dd>${esc(a.airline)}</dd><dt>Distance</dt><dd>${a.distance} km</dd><dt>Altitude</dt><dd>${ft(a.alt)}</dd><dt>Speed</dt><dd>${sp(a.speed)}</dd><dt>Track</dt><dd>${a.track==null?'Unknown':a.track.toFixed(0)+'° — '+esc(a.move)}</dd><dt>Vertical rate</dt><dd>${a.vertical==null?'Unknown':a.vertical+' m/s'}</dd><dt>Position</dt><dd>${a.lat.toFixed(4)}, ${a.lon.toFixed(4)}</dd><dt>Squawk</dt><dd>${esc(a.squawk||'Unknown')}</dd><dt>ICAO24</dt><dd>${esc(a.id)}</dd></dl>`;
$('details').hidden=false;}
function closeDetails(){$('details').hidden=true;}
load();setInterval(load,60000);
</script></body></html>
