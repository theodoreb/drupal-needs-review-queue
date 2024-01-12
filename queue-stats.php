<?php
/**
 * This file outputs the number of issues in needs review for each core component.
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Client;

function logg(string $message): void {
  print "$message\n";
}

const DO_API = 'https://www.drupal.org/api-d7';

function getPage(string $url): int {
  $query = Query::parse((new Uri($url))->getQuery());
  return !empty($query['page']) ? (int) $query['page'] : 0;
}

function fetch(string $path, array $query = []): object|bool {
  $url = Uri::withQueryValues(new Uri(DO_API . $path . '.json'), $query);
  $client = new Client();
  try {
    logg("Fetching $url");
    $response = $client->get($url);
  }
  catch (\Exception $exception) {
    logg("Exception " . $exception->getCode() . ":" . $exception->getMessage());
    print PHP_EOL;
    return FALSE;
  }

  $data = json_decode($response->getBody());

  return (object) [
    'query' => $query,
    'list' => $data->list,
    'self' => getPage($data->self),
    'last' => getPage($data->last),
  ];
}

function fetchAll(string $path, array $query): array {
  $query += ['page' => 0];
  $list = [];
  do {
    if ($page = fetch($path, $query)) {
      $list = array_merge($list, $page->list);
      $query['page'] = $page->self + 1;
    }
  } while ($page !== FALSE && $page->self < $page->last);

  return $list;
}

function isRelevantIssue(object $issue): bool {
  $is_9_or_above = !in_array($issue->field_issue_version[0], ['8', '7']);
  $is_not_plan = (int) $issue->field_issue_category !== 5;
  return $is_9_or_above && $is_not_plan;
}

function getSummary($list) {
  // Make sure we only have issues for D9+ versions
  $relevant_issues = array_filter($list, 'isRelevantIssue');
  $summary = array_count_values(array_column($relevant_issues, 'field_issue_component'));
  return $summary;
}

// Get all needs review nodes, this also takes 7.x issues but there are no good ways of avoiding that.
// d.o crashes when trying to fetch issues for 11.x-dev.
$all_nr_do_issues = fetchAll('/node', [
  'field_project' => 3060,
  'type' => 'project_issue',
  // Needs review.
  'field_issue_status' => 8,
  'limit' => 25,
]);

$all_rtbc_do_issues = fetchAll('/node', [
  'field_project' => 3060,
  'type' => 'project_issue',
  // RTBC.
  'field_issue_status' => 14,
  'limit' => 10,
]);


$summary_nr = getSummary($all_nr_do_issues);
$summary_rtbc = getSummary($all_rtbc_do_issues);

$components = array_unique(array_merge(array_keys($summary_nr), array_keys($summary_rtbc)));

$summary = array_fill_keys($components, ['NR' => 0, 'RTBC' => 0, 'Total' => 0]);
foreach ($summary as $component => &$count) {
  $count['NR'] = $summary_nr[$component] ?? 0;
  $count['RTBC'] = $summary_rtbc[$component] ?? 0;
  $count['Total'] = $count['NR'] + $count['RTBC'];
}

uasort($summary, function ($a, $b) {
  $tot = $b['Total'] <=> $a['Total'];
  if ($tot === 0) {
    return $b['RTBC'] <=> $a['RTBC'];
  }
  return $tot;
});


// Some cosmetic things.
$longestKey = array_reduce(array_keys($summary), function ($a, $b) { return strlen($a) > strlen($b) ? $a : $b; });
$pad_count = strlen($longestKey) + 1;

function logr($component, $count) {
  global $pad_count;
  logg(
    str_pad($component, $pad_count)
    . str_pad($count['RTBC'], 5, ' ', STR_PAD_LEFT)
    . str_pad($count['NR'], 5, ' ', STR_PAD_LEFT)
    . str_pad($count['Total'], 6, ' ', STR_PAD_LEFT)
  );
}

logr('', ['RTBC' => 'RTBC', 'NR' => 'NR', 'Total' => 'Total']);
foreach ($summary as $component => $infos) {
  logr($component, $infos);
}

logg('');
logr('TOTAL', [
  'RTBC' => array_sum(array_column($summary, 'RTBC')),
  'NR' => array_sum(array_column($summary, 'NR')),
  'Total' => array_sum(array_column($summary, 'Total')),
]);
