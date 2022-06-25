<?php

/*
 * Copyright (C) 2022 Giovanni Cascione <ing.cascione@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// Exit if accessed directly.
defined('ABSPATH') || exit;

$ajax_functions = ['ajax_build_report'];

foreach ($ajax_functions as $item) {
    add_action('wp_ajax_' . $item, $item);
    add_action('wp_ajax_nopriv_' . $item, $item);
}

function populate_selectors($filtered_query = [], $original_query = [], $date_range = [], $current_group = '') {

    if (!$date_range) {
        $date_range = get_filter_date_range();
    }

    $data = get_selectors_data($filtered_query, $date_range);

    $button_wrapper = '<div style="position:relative">'
            . '<div name="reset" class="w3-button w3-red w3-display-topleft"%1$s>&#9745;</div>'
            . '%2$s'
            . '</div>';

    //Populate elements inner except date range
    $html = [];
    foreach ($data as $column => $entries) {
        $inner = '';
        $is_filtered = array_key_exists($column, $original_query);
        $is_alone = count($entries) == 1;
        foreach ($entries as $entry) {
            $value = $entry->table == 'timetable' ? $entry->value : $entry->key; //TODO: improve last parameter logic (main tableitems)
            $checked = $is_filtered && in_array($value, $original_query[$column]);
            $disabled = $is_alone && !$checked;
            $inner .= sprintf('<input name="tsr_select_%2$s" type="checkbox" value="%1$s" data-key="%3$s"' . checked($checked, true, false) . disabled($disabled, true, false) . '> <label>%1$s</label><br>', get_label($data, $entry, $column), $column, $value);
        }
        $show_column = $current_group !== $column ? "display:none" : "";
        $html[$column] = '<div id="tsr_select_' . $column . '" class="w3-button w3-red w3-ripple w3-block" onclick="toggle(this)">'
                . ts_get_column_prop($column, 'description')
                . '</div><div name="block" style="' . $show_column . '" class="w3-padding w3-white">' . $inner . '</div>';
    }
    $html = array_map(function ($a) use ($button_wrapper) {
        $display = str_contains($a, checked(true, true, false)) ? '' : ' style="display:none"';
        return sprintf($button_wrapper, $display, $a);
    }, $html);

    //Populate date range
    $min = '';
    $max = '';
    $after = '';
    $before = '';

    if (isset($date_range['range'])) {
        $min = !isset($date_range['range']['min']) ? $min : $date_range['range']['min'];
        $max = !isset($date_range['range']['max']) ? $max : $date_range['range']['max'];
        $after = isset($date_range['range']['after']) ? $date_range['range']['after'] : $date_range['range']['min'];
        $before = isset($date_range['range']['before']) ? $date_range['range']['before'] : $date_range['range']['max'];
    }
    $show_column = $current_group !== 'range' ? "display:none" : "";
    $html['date_range'] = '<div id="tsr_select_range" class="w3-button w3-red w3-ripple w3-block" onclick="toggle(this)">' . __('Date range') . '</div>'
            . '<div name="block" style="' . $show_column . '" class="w3-padding w3-white">'
            . '<input name="tsr_select_range" type="date" id="after" value="' . $after . '" min="' . $min . '" max="' . $max . '"> <label>' . __('After') . '</label><br>'
            . '<input name="tsr_select_range" type="date" id="before" value="' . $before . '" min="' . $min . '" max="' . $max . '"> <label>' . __('Before') . '</label><br>'
            . '</div>';
    $display = ($before !== $max) || ($after !== $min) ? '' : ' style="display:none"';
    $html['date_range'] = sprintf($button_wrapper, $display, $html['date_range']);

    //Paginate elements in columns
    $output = '';
    $col_no = 3;
    $items_per_column = ceil(count($html) / $col_no);
    $col_html = '<div class="w3-cell w3-third">%s</div>';
    $col = '';
    $count = 0;
    foreach ($html as $chunk) {
        $col .= $chunk;
        $count++;
        if ($count == $items_per_column) {
            $output .= sprintf($col_html, $col);
            $col = '';
            $count = 0;
        }
    }

    //Add residual elements to last column if any
    if ($col !== '') {
        $output .= sprintf($col_html, $col);
    }

    return $output;
}

function get_report_vars() {
    return [
        'user_name',
        'user_group',
        'user_role',
        'job_no',
        'job_wbs',
        'job_tag',
        'activity_group',
        'activity_id'
    ];
}

function get_filter_date_range(&$filter = []) {
    //Check if date range is requested and remove from filters to treat separately
    //$start = 0;
    //$end = 0;
    $date_range = [];
    $date_query = [];
    if (isset($filter['after'])) {
        //$start = $filter['start'];
        $date_range['after'] = $filter['after']; //strtotime($start) - 1;
        unset($filter['after']);
    } else if (isset($filter['before'])) {
        //$end = $filter['end'];
        $date_range['before'] = $filter['before']; //strtotime($end) + 1;
        unset($filter['before']);
    }

    //Prepare date query if needed
    if ($date_range) {
        $date_query = ['date_query' => [
                'column' => ts_get_column_prop('date_rec'),
                $date_range
            ]
        ];
    }

    //Check max range excluding date filters
    $date_check = ts_query_items(
            array_merge($filter, [
        'min' => [
            'date_rec'
        ],
        'max' => [
            'date_rec'
        ],
            ])
    );

    $date_range['min'] = $date_check[0]['min_date_rec'];
    $date_range['max'] = $date_check[0]['max_date_rec'];

    return ['range' => $date_range, 'query' => $date_query];
}

function get_selectors_data($filter = [], $date_range = []) {

    $data_cols = get_report_vars();

    $query = array_merge($filter, isset($date_range['query']) ? $date_range['query'] : []);

    return filter_args_out($data_cols, $query, 'timetable');
}

function ajax_build_report() {
    $filters = [];

    if (isset($_POST['data_out'])) {
        $z = $_POST['data_out'];
        $y = json_decode(html_entity_decode(stripslashes($z)), true);
        if ($y) {
            $original_query = ts_get_column_prop(get_report_vars(), 'name', $y);
            $filtered_query = filter_args_in($original_query);
            $date_range = get_filter_date_range($y);
        }
    }

    $w['selectors'] = populate_selectors($filtered_query ?? [], $original_query ?? [], $date_range ?? [], $_POST['current_group']);
    $w['chartsdata'][] = chart1_get_data($filtered_query ?? [], $date_range ?? []);
    $w['chartsdata'][] = chart2_get_data($filtered_query ?? [], $date_range ?? []);
    $w['chartsdata'][] = chart3_get_data($filtered_query ?? [], $date_range ?? []);

    //TODO: generalize
    $tot = 0;
    foreach ($w['chartsdata'][0]['sum_time_units'] as $item) {
        $tot += $item;
    }

    $w['total'] = round($tot);

    $v = json_encode($w);

    echo $v;

    wp_die();
}

function chart1_get_data($args = [], $date_range = []) {

    if (!isset($date_range)) {
        $date_range = get_filter_date_range();
    }


    $default_args = ['sum' => [
            'time_units'
        ],
        'groupby' => [
            'date_rec'],
    ];

    $x = ts_query_items(
            array_merge($default_args, $args, isset($date_range['query']) ? $date_range['query'] : [])
    );

    $w = [];
    foreach ($x as $z) {
        foreach ($z as $key => $value) {
            $w[$key][] = $value; //ts_resolve_relation($key, $value);
        }
    }

    return $w;
}

function chart2_get_data($args = [], $date_range = []) {

    if (!isset($date_range)) {
        $date_range = get_filter_date_range();
    }


    $default_args = ['sum' => [
            'time_units'
        ],
        'groupby' => [
            'activity_id'],
    ];

    $x = ts_query_items(
            array_merge($default_args, $args, isset($date_range['query']) ? $date_range['query'] : [])
    );

    $w = [];
    foreach ($x as $z) {
        foreach ($z as $key => $value) {
            $w[$key][] = $value; //ts_resolve_relation($key, $value);
        }
    }

    $w['activity_id'] = array_map(function ($a) {
        $row = ts_get_column_value_by_id('activity_id', $a, true);
        return $row->activity_id . ' - ' . $row->activity_id_name;
    }, $w['activity_id']
    );

    return $w;
}

function chart3_get_data($args = [], $date_range = []) {

    if (!isset($date_range)) {
        $date_range = get_filter_date_range();
    }


    $default_args = ['sum' => [
            'time_units'
        ],
        'groupby' => [
            'activity_id'],
    ];

    $x = ts_query_items(
            array_merge($default_args, $args, isset($date_range['query']) ? $date_range['query'] : [])
    );

    $w = [];
    foreach ($x as $z) {
        foreach ($z as $key => $value) {
            $w[$key][] = $value; //ts_resolve_relation($key, $value);
        }
    }
//rename to activity_group
    $w['activity_group'] = array_map(function ($a) {
        $row = ts_get_column_value_by_id('activity_id', $a, true);
        $row2 = ts_get_column_value_by_id('activity_group', $row->activity_group, true);
        return $row2->activity_group . ' - ' . $row2->activity_group_name;
    }, $w['activity_id']
    );

    $y = [];
    $y['activity_group'] = array_unique($w['activity_group']);
    foreach ($w['sum_time_units'] as $key => $value) {
        $index = array_search($w['activity_group'][$key], $y['activity_group']);
        $y['sum_time_units'][$index] = ($y['sum_time_units'][$index] ?? 0) + $value;
    }
    $y['activity_group'] = array_values($y['activity_group']);
    $y['sum_time_units'] = array_values($y['sum_time_units']);

    return $y;
}
