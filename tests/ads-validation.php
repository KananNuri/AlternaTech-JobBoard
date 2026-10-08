<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/ads/validation.php';
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$valid=['title'=>'Développeur PHP','short_description'=>'Mentored role','description'=>'Build APIs','location'=>'Nantes','company_id'=>1];
[$data,$errors]=validateAd($valid); check(!$errors,'valid creation');
[, $errors]=validateAd([]); check(isset($errors['title'],$errors['body']),'required fields');
[, $errors]=validateAd(['title'=>' '],true); check(isset($errors['title']),'blank required field');
[, $errors]=validateAd(['title'=>str_repeat('é',201)],true); check(isset($errors['title']),'Unicode length');
[, $errors]=validateAd(['company_id'=>'1'],true); check(isset($errors['company_id']),'strict foreign key type');
[, $errors]=validateAd(['category_id'=>null],true); check(!$errors,'nullable category');
[, $errors]=validateAd(['source'=>'FRANCE_TRAVAIL','id'=>1],true); check(count($errors)===2,'read-only fields');
[, $errors]=validateAd(['description'=>['x']],true); check(isset($errors['description']),'nested input rejected');
[, $errors]=validateAd(['location'=>null],true); check(isset($errors['location']),'required null rejected');
[, $errors]=validateAd(['salary'=>null],true); check(!$errors,'optional null allowed');
