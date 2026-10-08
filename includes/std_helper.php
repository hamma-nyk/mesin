<?php
function getSnapshotStd($row, $kode_param, $key = 'nilai_min') {
    if (empty($row['std_snapshot'])) return null;
    static $cache = [];
    $id = spl_object_id((object)$row) ?? md5($row['std_snapshot']);
    if (!isset($cache[$id])) {
        $cache[$id] = json_decode($row['std_snapshot'], true) ?: [];
    }
    return isset($cache[$id][$kode_param][$key]) ? (float)$cache[$id][$kode_param][$key] : null;
}

function evalStdAAC($row, $tangki_index, $param_type, $value) {
    if ($value === null) return null;
    $kode = $param_type . '_t' . $tangki_index;
    $snap = !empty($row['std_snapshot']) ? json_decode($row['std_snapshot'], true) : null;

    if ($snap && isset($snap[$kode])) {
        $min = $snap[$kode]['nilai_min'] !== null ? (float)$snap[$kode]['nilai_min'] : null;
        $max = $snap[$kode]['nilai_max'] !== null ? (float)$snap[$kode]['nilai_max'] : null;
    } else {
        return null;
    }
    
    if ($min !== null && $value < $min) return true;
    if ($max !== null && $value > $max) return true;
    return false;
}

function getStdLabelAAC($row, $kode_param, $fallback_min = null, $fallback_max = null) {
    $snap = !empty($row['std_snapshot']) ? json_decode($row['std_snapshot'], true) : null;
    if ($snap && isset($snap[$kode_param])) {
        $min = $snap[$kode_param]['nilai_min'];
        $max = $snap[$kode_param]['nilai_max'];
        if ($min !== null && $max !== null) return $min . ' - ' . $max;
        if ($min === null && $max !== null) return 'Maks. ' . $max;
        if ($min !== null && $max === null) return 'Min. ' . $min;
    }
    if ($fallback_min !== null && $fallback_max !== null) return $fallback_min . ' - ' . $fallback_max;
    if ($fallback_min === null && $fallback_max !== null) return 'Maks. ' . $fallback_max;
    return '-';
}
