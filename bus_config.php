<?php
// Your private settings for bus.php. Keep this file; replace bus.php with newer versions freely.
// Do not share this file (it contains your key and secret).

const RT_KEY = '91c066bc5d26430a90b1d8901fa4b586';     // Trafiklab key for GTFS Regional Realtime
const SECRET = 'KfXhxS6L8DTgP1J';     // the page sends this as ?t=...

// Printed by check_trip.py (Södersjukhuset
const LAST_STOP_IDS = ['9022001010406003'];

// Printed by make_route_stops.py - paste your full list of stops here (the last one is Sköntorp)
/*const ROUTE_STOPS = [
    ['9022001013178001', 'Julitavägen', 59.28576, 18.03588],
    ['9022001013182001', 'Östbergahöjden västra', 59.28730, 18.03223],
    ['9022001013173002', 'Branten', 59.28803, 18.03064],
    ['9022001013171002', 'Terrassen', 59.29047, 18.02903],
    ['9022001013169002', 'Slättåkragränd', 59.29137, 18.03334],
    ['9022001013769003', 'Marknadsvägen', 59.29438, 18.03480],
    ['9022001013211001', 'Årstafältet', 59.29648, 18.04015],
    ['9022001013763002', 'Asplången', 59.29463, 18.04599],
    ['9022001013759002', 'Valla torg', 59.29336, 18.05016],
    ['9022001013755002', 'Idlången', 59.29210, 18.05570],
    ['9022001013217002', 'Bråviksvägen', 59.29497, 18.05634],
    ['9022001013215004', 'Årsta torg', 59.29628, 18.05305],
    ['9022001013117002', 'Årstaskolan', 59.29928, 18.05625],
    ['9022001013115001', 'Tämnarvägen', 59.30018, 18.05640],
    ['9022001013119001', 'Sköntorp', 59.30117, 18.05226],
];*/

const ROUTE_STOPS = [
    ['9022001013178001', 'Julitavägen', 59.28576, 18.03588],
    ['9022001013182001', 'Östbergahöjden västra', 59.28730, 18.03223],
    ['9022001013173002', 'Branten', 59.28803, 18.03064],
    ['9022001013171002', 'Terrassen', 59.29047, 18.02903],
    ['9022001013169002', 'Slättåkragränd', 59.29137, 18.03334],
    ['9022001013769003', 'Marknadsvägen', 59.29438, 18.03480],
    ['9022001013211001', 'Årstafältet', 59.29648, 18.04015],
    ['9022001013215004', 'Årsta torg', 59.29628, 18.05305],
    ['9022001013117002', 'Årstaskolan', 59.29928, 18.05625],
    ['9022001013115001', 'Tämnarvägen', 59.30018, 18.05640],
    ['9022001013119001', 'Sköntorp', 59.30117, 18.05226],
];
