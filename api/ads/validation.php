<?php
declare(strict_types=1);
function validateAd(array $input, bool $partial = false): array {
    $limits = ['title'=>200, 'short_description'=>500, 'description'=>16000, 'location'=>150, 'salary'=>100, 'working_time'=>100, 'contract_type'=>100];
    $required = ['title','short_description','description','location','company_id'];
    $allowed = array_merge(array_keys($limits), ['company_id','category_id']);
    $errors = []; $data = [];
    foreach ($input as $key=>$value) if (!in_array($key, $allowed, true)) $errors[$key] = 'Unknown or read-only field';
    if (!$partial) foreach ($required as $key) if (!array_key_exists($key, $input)) $errors[$key] = 'Required';
    foreach ($limits as $key=>$limit) if (array_key_exists($key, $input)) {
        $value = $input[$key];
        if ($value === null && !in_array($key, $required, true)) { $data[$key] = null; continue; }
        if (!is_string($value) || !preg_match('//u', $value)) { $errors[$key] = 'Must be UTF-8 text'; continue; }
        $value = trim($value);
        if (($value === '' && in_array($key, $required, true)) || preg_match_all('/./us', $value) > $limit) $errors[$key] = "Required text, maximum $limit characters";
        else $data[$key] = $value;
    }
    foreach (['company_id','category_id'] as $key) if (array_key_exists($key, $input)) {
        $value = $input[$key];
        if ($key === 'category_id' && $value === null) $data[$key] = null;
        elseif (!is_int($value) || $value < 1 || $value > 4294967295) $errors[$key] = 'Must be a positive integer';
        else $data[$key] = $value;
    }
    if (!$input) $errors['body'] = 'Non-empty object required';
    return [$data,$errors];
}
