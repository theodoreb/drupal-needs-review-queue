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
    dump($exception);
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
    $page = fetch($path, $query);
    $list = array_merge($list, $page->list);
    $query['page'] = $page->self + 1;
  } while ($page->self < $page->last);

  return $list;
}

function isRelevantIssue(object $issue): bool {
  return !in_array($issue->field_issue_version[0], ['8', '7']);
}

// Get all needs review nodes, this also takes 7.x issues but there are no good ways of avoiding that.
// d.o crashes when trying to fetch issues for 11.x-dev.
$all_do_issues = fetchAll('/node', [
  'field_project' => 3060,
  'type' => 'project_issue',
  // Needs review.
  'field_issue_status' => 8,
]);

// Make sure we only have issues for D9+ versions
$relevant_issues = array_filter($all_do_issues, 'isRelevantIssue');

// Initialize the result array.
$summary = array_count_values(array_column($relevant_issues, 'field_issue_component'));
arsort($summary);

foreach ($summary as $component => $count) {
  logg("$component  $count");
}

logg('');
logg('TOTAL ' . array_sum($summary));
