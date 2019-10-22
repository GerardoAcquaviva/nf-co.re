<?php
$title = 'Pipeline health';
$subtitle = 'Check repository settings for all nf-core pipelines';
$mainpage_container = false;
include('../includes/header.php');

// Get auth secrets
$config = parse_ini_file("../config.ini");
$gh_auth = base64_encode($config['github_username'].':'.$config['github_access_token']);

// Load pipelines JSON
$pipelines_json = json_decode(file_get_contents('pipelines.json'))->remote_workflows;

// Placeholders
$pipelines = [];
$core_repos = [];

// HTTP header to use on GitHub API GET requests
define('GH_API_OPTS',
  stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => [
        'User-Agent: PHP',
        'Accept:application/vnd.github.mercy-preview+json', // Needed to get topics (keywords) for now
        'Accept:application/vnd.github.luke-cage-preview+json', // Needed to get protected branch required reviews
        "Authorization: Basic $gh_auth"
      ]
    ]
  ])
);

// Base repo health class
class RepoHealth {

  // Init vars
  public $name;
  public function __construct($name) {
    $this->name = $name;
  }
  public $required_status_check_contexts = [
    'continuous-integration/travis-ci',
    // TODO - after official switch to GitHub Actions, need new CI test names here:
    // Markdown
    // YAML
    // nf-core
    // test
    // NOTE - doesn't seem to be any way to get the "available" contexts through GitHub API
    // If we really want to do this, might have to query the repo contents..??
  ];
  public $required_topics = ['nf-core'];
  public $web_url = 'https://nf-co.re';

  // Data vars
  public $gh_repo;
  public $gh_teams = [];
  public $gh_branch_master;
  public $gh_branch_dev;

  // Test result variables
  public $repo_wikis;
  public $repo_issues;
  public $repo_merge_commits;
  public $repo_merge_rebase;
  public $repo_merge_squash;
  public $repo_default_branch;
  public $repo_keywords;
  public $repo_description;
  public $repo_url;
  public $team_all;
  public $team_core;

  // Branch test vars
  public $branch_master_strict_updates;
  public $branch_master_required_ci;
  public $branch_master_stale_reviews;
  public $branch_master_code_owner_reviews;
  public $branch_master_required_num_reviews;
  public $branch_master_enforce_admins;
  public $branch_dev_strict_updates;
  public $branch_dev_required_ci;
  public $branch_dev_stale_reviews;
  public $branch_dev_code_owner_reviews;
  public $branch_dev_required_num_reviews;
  public $branch_dev_enforce_admins;

  public function get_data(){
    $this->get_repo_data();
    $this->get_branch_data();
  }
  public function run_tests(){
    $this->test_repo();
    $this->test_teams();
    $this->test_branch();
  }

  private function get_repo_data(){
    // Super annoyingly, the teams call misses just one or two keys we need :(
    if(is_null($this->gh_repo) || !isset($this->gh_repo->allow_merge_commit)){
      $gh_repo_url = 'https://api.github.com/repos/nf-core/'.$this->name;
      $this->gh_repo = json_decode(file_get_contents($gh_repo_url, false, GH_API_OPTS));
    }
  }
  private function get_branch_data(){

    $gh_branch_master_url = 'https://api.github.com/repos/nf-core/'.$this->name.'/branches/master/protection';
    $gh_branch_master = json_decode(@file_get_contents($gh_branch_master_url, false, GH_API_OPTS));
    if(in_array("HTTP/1.1 200 OK", $http_response_header) && is_object($gh_branch_master)){
      $this->gh_branch_master = $gh_branch_master;
    }

    $gh_branch_dev_url = 'https://api.github.com/repos/nf-core/'.$this->name.'/branches/dev/protection';
    $gh_branch_dev = json_decode(@file_get_contents($gh_branch_dev_url, false, GH_API_OPTS));
    if(in_array("HTTP/1.1 200 OK", $http_response_header) && is_object($gh_branch_dev)){
      $this->gh_branch_dev = $gh_branch_dev;
    }

//    if($this->name == 'tools'){
//      echo ("Done with the api call to $gh_branch_dev_url<br>");
//      echo '<pre><code>'.print_r($this->gh_branch_dev, true).'</code></pre>';
//      echo '<pre><code>'.print_r($http_response_header, true).'</code></pre>';
//    }
  }

  private function test_topics(){
    $topics_pass = true;
    foreach($this->required_topics as $top){
      if(!in_array($top, $this->gh_repo->topics)){
        $topics_pass = false;
        break;
      }
    }
    return $topics_pass;
  }
  private function test_repo(){
    if(isset($this->gh_repo->has_wiki)) $this->repo_wikis = !$this->gh_repo->has_wiki;
    if(isset($this->gh_repo->has_issues)) $this->repo_issues = $this->gh_repo->has_issues;
    if(isset($this->gh_repo->allow_merge_commit)) $this->repo_merge_commits = $this->gh_repo->allow_merge_commit;
    if(isset($this->gh_repo->allow_rebase_merge)) $this->repo_merge_rebase = $this->gh_repo->allow_rebase_merge;
    if(isset($this->gh_repo->allow_squash_merge)) $this->repo_merge_squash = !$this->gh_repo->allow_squash_merge;
    if(isset($this->gh_repo->default_branch)) $this->repo_default_branch = $this->gh_repo->default_branch == 'master';
    if(isset($this->gh_repo->topics)) $this->repo_keywords = $this->test_topics();
    if(isset($this->gh_repo->description)) $this->repo_description = $this->gh_repo->description;
    if(isset($this->gh_repo->homepage)) $this->repo_url = $this->gh_repo->homepage == $this->web_url;
  }
  private function test_teams(){
    $this->team_all = isset($this->gh_teams['all']) ? $this->gh_teams['all']->push : false;
    $this->team_core = isset($this->gh_teams['core']) ? $this->gh_teams['core']->admin : false;
  }
  private function test_branch(){
    foreach (['dev', 'master'] as $branch) {
      $prs_required = $branch == 'master' ? 2 : 1;
      if(!isset($this->{'gh_branch_'.$branch}) || !is_object($this->{'gh_branch_'.$branch})){
        $this->{'branch_'.$branch.'_strict_updates'} = false;
        $this->{'branch_'.$branch.'_required_ci'} = false;
        $this->{'branch_'.$branch.'_stale_reviews'} = false;
        $this->{'branch_'.$branch.'_code_owner_reviews'} = false;
        $this->{'branch_'.$branch.'_required_num_reviews'} = false;
        $this->{'branch_'.$branch.'_enforce_admins'} = false;
        continue;
      }
      $data = $this->{'gh_branch_'.$branch};

      if(!isset($data->required_status_checks)){
        $this->{'branch_'.$branch.'_strict_updates'} = false;
        $this->{'branch_'.$branch.'_required_ci'} = false;
      } else {
        // Don't require branches to be up to date before merging.
        $this->{'branch_'.$branch.'_strict_updates'} = $data->required_status_checks->strict == false;
        // At least the minimum set of required CI tests
        $this->{'branch_'.$branch.'_required_ci'} = !array_diff($this->required_status_check_contexts, $data->required_status_checks->contexts);
      }
      if(!isset($data->required_pull_request_reviews)){
        $this->{'branch_'.$branch.'_stale_reviews'} = false;
        $this->{'branch_'.$branch.'_code_owner_reviews'} = false;
        $this->{'branch_'.$branch.'_required_num_reviews'} = false;
      } else {
        // Don't mark reviews as stale on new commits
        $this->{'branch_'.$branch.'_stale_reviews'} = $data->required_pull_request_reviews->dismiss_stale_reviews == false;
        // Don't require reviews from code owners
        $this->{'branch_'.$branch.'_code_owner_reviews'} = $data->required_pull_request_reviews->require_code_owner_reviews == false;
        // Require 1 or 2 reviews
        $this->{'branch_'.$branch.'_required_num_reviews'} = $data->required_pull_request_reviews->required_approving_review_count == $prs_required;
      }
      // Don't include administrators
      if(!isset($data->enforce_admins)) $this->{'branch_'.$branch.'_enforce_admins'} = false;
      else $this->{'branch_'.$branch.'_enforce_admins'} = $data->enforce_admins->enabled == false;

    }
  }

  public function print_table_cell($test_name){
    if(is_null($this->$test_name)){
      echo '<td class="table-secondary text-center"><i class="fas fa-question text-secondary"></i></td>';
    } else if($this->$test_name){
      echo '<td class="table-success text-center"><i class="fas fa-check text-success"></i></td>';
    } else {
      echo '<td class="table-danger text-center"><i class="fas fa-times text-danger"></i></td>';
    }
  }
}

// Pipeline health class
class PipelineHealth extends RepoHealth {
  public function __construct($name) {
    $this->name = $name;
    $this->web_url = 'https://nf-co.re/'.$this->name;
  }
  public $required_topics = ['nf-core', 'nextflow', 'workflow', 'pipeline'];
}

// Core repo health class
class CoreRepoHealth extends RepoHealth {

}

// Get nf-core GitHub teams info & repos
function get_gh_team_repos($team){
  // Globals
  global $pipelines_json;
  global $pipelines;
  global $core_repos;

  // Get team ID
  $gh_team_url = 'https://api.github.com/orgs/nf-core/teams/'.$team;
  $gh_team = json_decode(file_get_contents($gh_team_url, false, GH_API_OPTS));

  $gh_team_repos_url = 'https://api.github.com/teams/'.$gh_team->id.'/repos';
  $first_page = true;
  $next_page = false;
  while($first_page || $next_page){

    // reset loop vars
    $first_page = false;
    // Get GitHub API results
    if($next_page){
      $gh_team_repos_url = $next_page;
    }
    $gh_team_repos = json_decode(file_get_contents($gh_team_repos_url, false, GH_API_OPTS));

    // Make repo health objects
    foreach($gh_team_repos as $repo){
      // Make a pipeline object
      $is_pipeline = false;
      foreach($pipelines_json as $wf){
        if($wf->name == $repo->name){
          if(!array_key_exists($repo->name, $pipelines)){
            $pipelines[$repo->name] = new PipelineHealth($repo->name);
            $pipelines[$repo->name]->gh_repo = $repo;
          }
          $pipelines[$repo->name]->gh_teams[$team] = $repo->permissions;
          $is_pipeline = true;
        }
      }
      // Make a core repo object
      if(!$is_pipeline){
        if(!array_key_exists($repo->name, $core_repos)){
          $core_repos[$repo->name] = new CoreRepoHealth($repo->name);
          $core_repos[$repo->name]->gh_repo = $repo;
        }
        $core_repos[$repo->name]->gh_teams[$team] = $repo->permissions;
      }
    }

    // Look for URL to next page of API results
    $next_page = false;
    $m_array = preg_grep('/rel="next"/', $http_response_header);
    if(count($m_array) > 0){
      preg_match('/<([^>]+)>; rel="next"/', array_values($m_array)[0], $matches);
      if(isset($matches[1])){
        $next_page = $matches[1];
      }
    }

  }
}
get_gh_team_repos('all');
get_gh_team_repos('core');

// Loop through pipelines, in case there are any without team access
foreach($pipelines_json as $wf){
  // Remove archived pipelines
  if($wf->archived){
    if(array_key_exists($wf->name, $pipelines)){
      unset($pipelines[$wf->name]);
    }
  } else {
    if(!array_key_exists($wf->name, $pipelines)){
      $pipelines[$wf->name] = new PipelineHealth($wf->name);
    }
  }
}

// Get any missing data and run tests
foreach($pipelines as $pipeline){
  $pipeline->get_data();
  $pipeline->run_tests();
}
foreach($core_repos as $core_repo){
  $core_repo->get_data();
  $core_repo->run_tests();
}

$base_test_names = [
  'repo_wikis' => "Wikis",
  'repo_issues' => "Issues",
  'repo_merge_commits' => "Merge commits",
  'repo_merge_rebase' => "Rebase merging",
  'repo_merge_squash' => "Squash merges",
  'repo_default_branch' => "Default branch",
  'repo_keywords' => "Keywords",
  'repo_description' => "Description",
  'repo_url' => "Repo URL",
  'team_all' => "Team all",
  'team_core' => "Team core",
  'branch_master_strict_updates' => 'master: strict updates',
  'branch_master_required_ci' => 'master: required CI',
  'branch_master_stale_reviews' => 'master: stale reviews',
  'branch_master_code_owner_reviews' => 'master: code owner reviews',
  'branch_master_required_num_reviews' => 'master: 2 reviews',
  'branch_master_enforce_admins' => 'master: enforce admins',
  'branch_dev_strict_updates' => 'dev: strict updates',
  'branch_dev_required_ci' => 'dev: required CI',
  'branch_dev_stale_reviews' => 'dev: stale reviews',
  'branch_dev_code_owner_reviews' => 'dev: code owner reviews',
  'branch_dev_required_num_reviews' => 'dev: 1 review',
  'branch_dev_enforce_admins' => 'dev: enforce admins',
];
$base_test_descriptions = [
  'repo_wikis' => "Disable wikis",
  'repo_issues' => "Enable issues",
  'repo_merge_commits' => "Allow merge commits",
  'repo_merge_rebase' => "Allow rebase merging",
  'repo_merge_squash' => "Do not allow squash merges",
  'repo_default_branch' => "master as default branch",
  'repo_keywords' => "Minimum keywords set",
  'repo_description' => "Description must be set",
  'repo_url' => "URL should be set to https://nf-co.re/",
  'team_all' => "Write access for nf-core/all",
  'team_core' => "Admin access for nf-core/core",
  'branch_master_strict_updates' => 'master branch: do not require branch to be up to date before merging',
  'branch_master_required_ci' => 'master branch: minimum set of CI tests must pass',
  'branch_master_stale_reviews' => 'master branch: reviews not marked stale after new commits',
  'branch_master_code_owner_reviews' => 'master branch: code owner reviews not required',
  'branch_master_required_num_reviews' => 'master branch: 2 reviews required',
  'branch_master_enforce_admins' => 'master branch: do not enforce rules for admins',
  'branch_dev_strict_updates' => 'dev branch: do not require branch to be up to date before merging',
  'branch_dev_required_ci' => 'dev branch: minimum set of CI tests must pass',
  'branch_dev_stale_reviews' => 'dev branch: reviews not marked stale after new commits',
  'branch_dev_code_owner_reviews' => 'dev branch: code owner reviews not required',
  'branch_dev_required_num_reviews' => 'dev branch: 1 review required',
  'branch_dev_enforce_admins' => 'dev branch: do not enforce rules for admins',
];

$pipeline_test_names = $base_test_names;
$pipeline_test_descriptions = $base_test_descriptions;
$pipeline_test_descriptions['repo_url'] = "URL should be set to https://nf-co.re/[PIPELINE-NAME]";

$core_repo_test_names = $base_test_names;
$core_repo_test_descriptions = $base_test_descriptions;

?>

<div class="container-fluid main-content">
  <h2>Pipelines</h2>
  <div class="table-responsive">
    <table class="table table-sm small">
      <thead>
        <tr>
          <th class="small text-nowrap">Pipeline Name</th>
          <?php foreach ($pipeline_test_names as $key => $name){
            echo '<th class="small text-nowrap" title="'.$pipeline_test_descriptions[$key].'" data-toggle="tooltip" data-placement="top">'.$name.'</th>';
          } ?>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach ($pipelines as $pipeline){
        echo '<tr>';
          echo '<td>'.$pipeline->name.'</td>';
          foreach ($pipeline_test_names as $key => $name){
            $pipeline->print_table_cell($key);
          }
        echo '</tr>';
      }
      ?>
      </tbody>
    </table>
  </div>
</div>


<div class="container-fluid main-content">
  <h2>Core repos</h2>
  <div class="table-responsive">
    <table class="table table-sm small">
      <thead>
        <tr>
          <th class="small text-nowrap">Pipeline Name</th>
          <?php foreach ($core_repo_test_names as $key => $name){
            echo '<th class="small text-nowrap" title="'.$core_repo_test_descriptions[$key].'" data-toggle="tooltip" data-placement="top">'.$name.'</th>';
          } ?>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach ($core_repos as $repo){
        echo '<tr>';
          echo '<td>'.$repo->name.'</td>';
          foreach ($core_repo_test_names as $key => $name){
            $repo->print_table_cell($key);
          }
        echo '</tr>';
      }
      ?>
      </tbody>
    </table>
  </div>
</div>


<?php
include('../includes/footer.php');
