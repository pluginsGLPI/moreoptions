# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Fixed

- Fixed an issue where a group could be added to a ticket even though it did not have the necessary permissions

## Add

- Add rector config
- Implementation of the basic concept of escalation
- Add the escalation hierarchy between groups: graph editor in the "Escalation" tab of the groups, with basic links (limited to their entity) and inherited links (replicated in the child entities). Changing it requires the right to update the GLPI configuration

## [1.0.0-rc2]

### Fixed

- Fixed the issue where a ticket could be solved without a solution

## [1.0.0-rc1]

