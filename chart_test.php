<?php

function generateSvgChartData($dataPoints, $width = 500, $height = 180, $paddingX = 10, $paddingY = 25) {
    $count = count($dataPoints);
    if ($count < 2) return null;

    $maxVal = max(array_column($dataPoints, 'count'));
    $minVal = min(array_column($dataPoints, 'count'));
    $range = $maxVal - $minVal;
    if ($range == 0) $range = 1;

    $usableWidth = $width - ($paddingX * 2);
    $usableHeight = $height - ($paddingY * 2);

    $points = [];
    foreach ($dataPoints as $i => $dp) {
        $x = $paddingX + ($i / ($count - 1)) * $usableWidth;
        // Invert Y because SVG 0,0 is top-left
        $y = $height - $paddingY - ((($dp['count'] - $minVal) / $range) * $usableHeight);
        $points[] = ['x' => $x, 'y' => $y, 'label' => $dp['date'], 'val' => $dp['count']];
    }

    // Generate smooth path using Monotone cubic interpolation or simple Catmull-Rom
    $path = "M " . $points[0]['x'] . "," . $points[0]['y'] . " ";
    
    for ($i = 0; $i < $count - 1; $i++) {
        $p0 = $i > 0 ? $points[$i - 1] : $points[$i];
        $p1 = $points[$i];
        $p2 = $points[$i + 1];
        $p3 = $i < $count - 2 ? $points[$i + 2] : $points[$i + 1];

        // Catmull-Rom to Cubic Bezier
        // cp1 = p1 + (p2 - p0) / 6
        // cp2 = p2 - (p3 - p1) / 6
        $tension = 0.2; // Adjust tension for smoothness
        $cp1x = $p1['x'] + ($p2['x'] - $p0['x']) * $tension;
        $cp1y = $p1['y'] + ($p2['y'] - $p0['y']) * $tension;
        
        $cp2x = $p2['x'] - ($p3['x'] - $p1['x']) * $tension;
        $cp2y = $p2['y'] - ($p3['y'] - $p1['y']) * $tension;

        $path .= "C " . round($cp1x, 2) . "," . round($cp1y, 2) . " " . 
                        round($cp2x, 2) . "," . round($cp2y, 2) . " " . 
                        round($p2['x'], 2) . "," . round($p2['y'], 2) . " ";
    }

    return [
        'path' => $path,
        'points' => $points,
        'fillPath' => $path . " L " . end($points)['x'] . "," . $height . " L " . $points[0]['x'] . "," . $height . " Z"
    ];
}

$data = [];
for($i=0; $i<6; $i++) {
    $data[] = ['date' => 'Day '.$i, 'count' => rand(10, 100)];
}
print_r(generateSvgChartData($data));
