# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Fixed

- Reduce the database queries made to read the entity configuration
- Ship the plugin stylesheet as plain CSS instead of SCSS

## [1.0.1] - 2026-11-06

### Fixed

- Fix rights checks on escalations

### Added

- Add tags to the plugin XML

## [1.0.0] - 2026-11-05

### Fixed

- Fixed an issue where a group could be added to a ticket even though it did not have the necessary permissions
- Fix config tab visibility and restrict config update fields
- Fix tab redirect after save
- Show tasks in To Do status in the "Add a solution" warning when closure prevention is enabled
- Show the number of tasks to complete and of missing fields in the "Add a solution" warning

### Added

- GLPI 12 compatibility
- Add rector config
- Implementation of the basic concept of escalation
- Add the "Escalate to group" action to the ticket, change and problem business rules, and a page to switch the rules "Technician group" actions to it
- Add the escalation hierarchy between groups: graph editor in the "Escalation" tab of the groups, with basic links (limited to their entity) and inherited links (replicated in the child entities). Changing it requires the right to update the GLPI configuration
- Filter the groups of the "Escalate" form with the escalation tree
- Add the `plugins:moreoptions:migrate_escalade` command, migrating the group filter of the Escalade plugin (end of life) to the escalation tree

## [1.0.0-rc2]

### Fixed

- Fixed the issue where a ticket could be solved without a solution

## [1.0.0-rc1]
