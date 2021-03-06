<?php
/*
 Copyright 2016-present The Material Motion Authors. All Rights Reserved.

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 */

# If the revision's message contains any of the GitHub issues closing verbs:
#
# - Updates the labels for the issue to reflect the current state
# - If the labels changed, also posts a comment to the thread linking to the diff.
class GitHubIssuesPostDiffArcanistHook {
  private $workflow;
  private $console;
  private $githubToken;
  private $revisionID;

  # Revision state
  private $uri;
  private $changesPlanned;

  private $changesPlannedLabelsToRemove = array(
    'flow: In review',
    'flow: Ready for action',
  );
  private $changesPlannedLabelsToAdd = array('flow: In progress');

  private $readyLabelsToRemove = array(
    'flow: In progress',
    'flow: Ready for action',
  );
  private $readyLabelsToAdd = array('flow: In review');

  private $changesPlannedColumnName = 'In Progress';
  private $readyColumnName = 'In Review';

  public static function hookType() {
    return 'post-diff';
  }

  public function doHook(ArcanistDiffWorkflow $workflow) {
    $this->workflow = $workflow;
    $this->console = PhutilConsole::getConsole();
    
    $this->githubToken = $this->loadGitHubToken();
    if (!$this->githubToken) {
      $this->console->writeOut(
        "gh not configured; will not attempt to update issue labels.\n"
        . "Run gh user --whoami to authenticate."
      );
      return;
    }

    $summary = $this->loadRevisionSummary();
    if (!$summary) {
      return;
    }

    $re = "/(?:close|closes|closed|fix|fixes|fixed|resolve|resolves|resolved).+github.com\\/(.+?)\\/(.+?)\\/issues\\/(\\d+)/i";
    if (preg_match_all($re, $summary, $matches, PREG_SET_ORDER)) {
      $issues = array();
      foreach ($matches as $match) {
        $issues[$match[1].'/'.$match[2].'/'.$match[3]] = $match;
      }

      foreach ($issues as $match) {
        $this->updateIssue($match[1], $match[2], $match[3]);
      }
    }
  }

  # Updates labels and posts a comment with a link to the diff if the labels changed.
  private function updateIssue($owner, $repo, $issueID) {
    if ($this->changesPlanned) {
      $destination_column = $this->changesPlannedColumnName;
      $remove = $this->changesPlannedLabelsToRemove;
      $add = $this->changesPlannedLabelsToAdd;
      $comment = "💻 I'm working on a diff at ".$this->uri;

    } else {
      $destination_column = $this->readyColumnName;
      $remove = $this->readyLabelsToRemove;
      $add = $this->readyLabelsToAdd;
      $comment = "🎊 My diff is ready for review at ".$this->uri;
    }

    $ch = $this->createProjectCurlRequest("repos/$owner/$repo/projects");
    $server_output = curl_exec($ch);
    curl_close($ch);
    if (!$this->checkCurlResponse($server_output)) {
      return;
    }
    $response = json_decode($server_output, TRUE);
    $project_id = null;
    foreach ($response as $project) {
      if ($project['name'] == 'Current sprint') {
        $project_id = $project['id'];
        break;
      }
    }

    if ($project_id) {
      $this->console->writeOut("github: Enumerating columns in current sprint...\n");

      $ch = $this->createProjectCurlRequest("projects/$project_id/columns");
      $server_output = curl_exec($ch);
      curl_close($ch);
      if (!$this->checkCurlResponse($server_output)) {
        return;
      }
      $columns = json_decode($server_output, TRUE);

      $column_name_to_id = array();
      foreach ($columns as $column) {
        $column_name_to_id[$column['name']] = $column['id'];
      }
      if (!array_key_exists($destination_column, $column_name_to_id)) {
        $this->console->writeOut("github: Unable to find column named %s. Aborting!\n", $destination_column);
        return;
      }

      $this->console->writeOut("github: Searching for card with issue #%s...\n", $issueID);
      $issue_url = "https://api.github.com/repos/$owner/$repo/issues/$issueID";
      $issue_card_id = null;
      $issue_column_id = null;
      foreach ($columns as $column) {
        $ch = $this->createProjectCurlRequest("projects/columns/".$column['id'].'/cards');
        $server_output = curl_exec($ch);
        curl_close($ch);
        if (!$this->checkCurlResponse($server_output)) {
          return;
        }
        $cards = json_decode($server_output, TRUE);
        foreach ($cards as $card) {
          if ($card['content_url'] == $issue_url) {
            $issue_card_id = $card['id'];
            $issue_column_id = $column['id'];
            break;
          }
        }
        if ($issue_card_id) {
          break;
        }
      }
      
      $should_post_comment = false;

      if ($issue_card_id) {
        // Only move the card if it's in the wrong column.
        if ($issue_column_id != $column_name_to_id[$destination_column]) {
          $this->console->writeOut("github: Moving card to %s...\n", $destination_column);

          $ch = $this->createProjectCurlRequest("projects/columns/cards/$issue_card_id/moves");
          curl_setopt($ch, CURLOPT_POST, 1);
          curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            'position' => 'top',
            'column_id' => $column_name_to_id[$destination_column]
          )));
          $server_output = curl_exec($ch);
          curl_close($ch);
          if (!$this->checkCurlResponse($server_output)) {
            return;
          }
          $should_post_comment = true;
        }

      } else {
        $this->console->writeOut("github: Getting issue #%s id...\n", $issueID);

        $ch = $this->createCurlRequest("repos/$owner/$repo/issues/$issueID");
        $server_output = curl_exec($ch);
        curl_close($ch);
        if (!$this->checkCurlResponse($server_output)) {
          return;
        }
        $issue = json_decode($server_output, TRUE);

        $this->console->writeOut("github: Creating card on column %s...\n", $destination_column);

        $ch = $this->createProjectCurlRequest("projects/columns/".$column_name_to_id[$destination_column]."/cards");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
          'content_id' => $issue['id'],
          'content_type' => 'Issue'
        )));
        $server_output = curl_exec($ch);
        curl_close($ch);
        if (!$this->checkCurlResponse($server_output)) {
          return;
        }
        $should_post_comment = true;
      }

    } else {
      $this->console->writeOut("github: Getting labels for issue %s...\n", $issueID);

      $ch = $this->createCurlRequest("repos/$owner/$repo/issues/$issueID/labels");
      $server_output = curl_exec($ch);
      curl_close($ch);
      if (!$this->checkCurlResponse($server_output)) {
        return;
      }
      $response = json_decode($server_output, TRUE);
      $existingLabels = array();
      foreach ($response as $label) {
        $existingLabels []= $label['name'];
      }

      $labels = array_unique(array_merge($existingLabels, $add));
      $labels = array_values(array_diff($labels, $remove));

      $should_post_comment = false;

      if (count(array_diff($labels, $existingLabels))) {
        $this->console->writeOut("github: Setting labels %s to issue #%s\n", implode(', ', $labels), $issueID);

        $ch = $this->createCurlRequest("repos/$owner/$repo/issues/$issueID/labels");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($labels));
        $server_output = curl_exec($ch);
        curl_close($ch);
        if (!$this->checkCurlResponse($server_output)) {
          return;
        }
        $should_post_comment = true;
      }
    }

    if ($should_post_comment) {
      $this->console->writeOut("github: Posting to thread for issue #%s\n", $issueID);

      $ch = $this->createCurlRequest("repos/$owner/$repo/issues/$issueID/comments");
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('body' => $comment)));
      $server_output = curl_exec($ch);
      curl_close($ch);
      if (!$this->checkCurlResponse($server_output)) {
        return;
      }
    }

    $this->console->writeOut("github: Visit the issue https://github.com/%s/%s/issues/%s\n", $owner, $repo, $issueID);
  }
  
  private function checkCurlResponse($server_output) {
    if (!$server_output) {
      return false;
    }
    $result = json_decode($server_output, TRUE);
    switch(json_last_error()) {
        case JSON_ERROR_NONE:
        break;
        default:
        return false;
    }
    $message = idx($result, 'message', null);
    if ($message) {
      $this->console->writeOut("github: API request failed with message '%s'\n", $message);
      return false;
    }
    return true;
  }

  private function createCurlRequest($query) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.github.com/$query");
    curl_setopt($ch, CURLOPT_USERAGENT, 'arc-github-labeler');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      "Authorization: token ".$this->githubToken,
      "Accept: application/vnd.github.v3+json"
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    return $ch;
  }

  private function createProjectCurlRequest($query) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.github.com/$query");
    curl_setopt($ch, CURLOPT_USERAGENT, 'arc-github-labeler');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      "Authorization: token ".$this->githubToken,
      "Accept: application/vnd.github.inertia-preview+json"
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    return $ch;
  }

  # Returns the github authentication token for the `gh` cli tool.
  private function loadGitHubToken() {
    $ghjson = getenv("HOME")."/.gh.json";
    if (!file_exists($ghjson)) {
      return null;
    }

    $gh = json_decode(file_get_contents($ghjson), TRUE);
    return idx($gh, 'github_token', null);
  }

  # Returns the latest revision summary for the current diff.
  private function loadRevisionSummary() {
    $diffID = $this->workflow->getDiffID();
    if (!$diffID) {
      return null;
    }

    $current_diff = $this->workflow->getConduit()->callMethodSynchronous(
      'differential.querydiffs',
      array(
        'ids' => array($diffID),
      )
    );
    $current_diff = head($current_diff);
    if (!$current_diff) {
      $this->console->writeOut("Couldn't find diff with id %s\n", $diffID);
      return null;
    }
    $revisionID = idx($current_diff, 'revisionID', null);
    if (!$revisionID) {
      return null;
    }

    $this->revisionID = $revisionID;

    $current_revision = $this->workflow->getConduit()->callMethodSynchronous(
      'differential.query',
      array(
        'ids' => array($revisionID),
      )
    );
    $current_revision = head($current_revision);
    if (!$current_revision) {
      $this->console->writeOut("Couldn't find revision with id %s\n", $revisionID);
      return null;
    }
    $this->uri = idx($current_revision, 'uri', null);
    $this->changesPlanned = idx($current_revision, 'status', 0) == ArcanistDifferentialRevisionStatus::CHANGES_PLANNED;

    return idx($current_revision, 'summary', array());
  }
}
?>