# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Fixed

- Fixed an issue where a group could be added to a ticket even though it did not have the necessary permissions
- Fix tab redirect after save

## Add

- Add rector config
- Implementation of the basic concept of escalation
- Add the "Escalate to group" action to the ticket, change and problem business rules, and a page to switch the rules "Technician group" actions to it
- Add the escalation hierarchy between groups: graph editor in the "Escalation" tab of the groups, with basic links (limited to their entity) and inherited links (replicated in the child entities). Changing it requires the right to update the GLPI configuration
- Filter the groups of the "Escalate" form with the escalation tree
- Add escalade plugin migration

## [1.0.0-rc2]

### Fixed

- Fixed the issue where a ticket could be solved without a solution

## [1.0.0-rc1]
