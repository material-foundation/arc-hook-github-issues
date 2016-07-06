# arc-hook-github-issues

arc-hook-github-issues is a hook for use with [Phabricator](http://phabricator.org)'s `arc` command
line tool.

Requires use of the [arc-hook-conphig](https://github.com/material-foundation/arc-hook-conphig)
arcanist configuration.

## Notice of API instability

This hook is not presently intended for use outside of the material-motion team.

To generalize this hook we need to find an effective way to configure the labels defined in
GitHubIssuesPostDiffArcanistHook.

## Features

Update GitHub issues after running `arc diff`. If the diff has any
[issue-closing verbs](https://help.github.com/articles/closing-issues-via-commit-messages/) followed
by a fully-qualified http:// identifier, this hook will update the issue's labels and post a link
to the phabricator review.

## Installation

Add this repository as a git submodule to the .arc-hooks directory in your project.

    git submodule init
    git submodule add https://github.com/material-foundation/arc-hook-github-issues.git .arc-hooks/post-diff/arc-hook-github-issues

Your `.arcconfig` should list the hook in the `load` configuration:

    {
      "load": [
        ".arc-hooks/post-diff/arc-hook-github-issues"
      ]
    }

## License

Licensed under the Apache 2.0 license. See LICENSE for details.