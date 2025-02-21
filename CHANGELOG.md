<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## [4.1.0] - 2025-02-21

### New

enh: Support nc 32
enh(Stats command): Fetch index counts from backend

### Fixed

fix(IndexerJob): Always keep at least one IndexerJob scheduled to wait for end of indexing
fix(IndexerJob): Do not run multiple jobs in parallel
fix(Statistics): Add missing parentheses
enh(IndexerJob): make it time insensitive
fix(IndexerJob): Count invalid sources and log paths
fix: Decrease /loadSources files count
fix: Avoid UndefinedArrayKey error
fix: Don't override last_indexed_time on updates after indexing is complete

## [4.0.6] - 2025-02-10

### Fixed

* enh: Retry failed sources individually (@marcelklehr)

## [4.0.5] - 2025-02-08

### Fixed
* fix(ActionJob): Only warn when sending actions to backend fails (@marcelklehr)
* fix(Statistics): Add days to indexing time display (@marcelklehr)
* fix(IndexerJob): Make default batch size much larger than CC_MAX_FILES (@marcelklehr)
* fix(IndexerJob#setInitialIndexCompletion): Correct conditions for whether indexing is complete (@marcelklehr)

### Added
* enh(Stats command): Show indexed files and total eligible files (@marcelklehr)
* enh: Count indexed files (@marcelklehr)



## [4.0.4] - 2025-01-28

### Fixed
- hard limit max file size that can be indexed (#99) @kyteinsky

### Added
- add job start times to diagnostics (#98) @marcelklehr


## [4.0.3] - 2025-01-17

### Fixed
- IndexerJob: Check enough running jobs probabilistically (#95) @marcelklehr


## [4.0.2] - 2025-01-11

### Changed
- Increase the max no. of concurrent jobs (#93) @kyteinsky

### Fixed
- Add files caught in RetryIndexException to the end of the queue (#93) @kyteinsky
- Prevent two concurrent requests processing the same source (#93) @kyteinsky


## [4.0.1] - 2024-12-19

### Fixed
- Allow underscores in appids for source id (#86) @kyteinsky
- Update content provider docs (#86) @kyteinsky
- Make sure userIDs is a list in updateAccessDeclarative calls (#87) @marcelklehr
- Reduce sleep time in indexer job (#88) @kyteinsky


## [4.0.0] - 2024-12-17

### Changed
- Database Schema Update (#79) @kyteinsky

### Fixed
- Fix content provider metadata population (#81) @kyteinsky

### Added
- Add user delete listener (#79) @kyteinsky
- Add more methods to content manager (#79) @kyteinsky
- Add context_chat:stats for indexing completion time (#80) @kyteinsky
- Add reuse compliance (#83) @AndyScherzinger
- Add node rename listener (#82) @kyteinsky
- Repeat db clear migration for beta to stable upgrade (#84) @kyteinsky


## [4.0.0-beta3] - 2024-11-21

### Added
- Enforce a max count of running indexer jobs (#77) @marcelklehr


## [4.0.0-beta2] - 2024-11-11

### Fixed
- Add migration step to reset indexing (#74) @marcelklehr


## [4.0.0-beta] - 2024-11-08

### Changed
- Increase max indexing time (#67) @marcelklehr
- Speed up indexing by indexing all the time (#72) @marcelklehr

### Fixed
- Better error handling and some fixes (#61) @kyteinsky
- Undefined key check in delete service (#64) @kyteinsky
- Stop inflation of Indexer jobs (#66) @marcelklehr
- Parse and pass on received error msg in an exception (#70) @kyteinsky

### Added
- Check in screenshots and add a logo (#62) @marcelklehr
- Make indexing batch size configurable (#65) @kyteinsky
- Add background job diagnostics (#69) @marcelklehr


## [3.1.0] - 2024-09-30

### Added
- task proc provider returns json string in sources @kyteinsky


## [3.0.2] – 2024-08-02

### Fixed
- StorageService fix: CacheQueryBuilder OC API changed @marcelklehr


## [3.0.1] – 2024-07-29

### Fixed
- Update context chat task type's description @kyteinsky
- Prompt command fix: Use TaskProcessing instead of TextProcessing @marcelklehr
- Prompt command fix: Don't try to write array to output @marcelklehr


## [3.0.0] – 2024-06-26

### Changed
- bump min NC version to 30 @kyteinsky

### Fixed
- fix content manager and update provider docs @kyteinsky @Rello

### Added
- task processing support @kyteinsky @julien-nc


## [2.2.1] – 2024-06-26

### Changed
- update node and npm versions for CI

## [2.2.0] – 2024-06-25

### Fixed
- get content provider with class string @kyteinsky

### Added
- use background jobs for delete requests @kyteinsky
- Add config flag to disable auto-indexing (#44) @marcelklehr
- show app name first in providers list @kyteinsky
- add backend init check to all requests @kyteinsky
- Add docs on how to implement a provider @marcelklehr
- add dsp timeout link to readme @kyteinsky


## [2.1.2] – 2024-05-02

### Fixed
- no exceptions for delete paths


## [2.1.1] – 2024-04-23

### Fixed
- 3 sec timeout for deletion requests
- update integration-test gh workflow


## [2.1.0] – 2024-04-15

### Fixed
- send source id instead of file path


## [2.0.2] – 2024-03-27

### Fixed
- Fix file listener


## 2.0.1 – 2024-03-23

### Fixed
- update integration-test.yml
- separate ProviderConfigService
- IndexerJob: Avoid sending the same resource multiple times


## 2.0.0 – 2024-03-21

### Changed
- refactor and fix scoped context chat
- install app_api from git & use setup_python action
- use full path of file instead of file name

### Fixed
- fix: app disable listener now deletes sources for all users
- replace usage of enum with a class and update gh workflows
- fix integration test workflow
- update app_api app installation commands

### Added
- add support for scoped context in query
- fix: metadata search for provider
- add no-context option to prompt command
- AppAPI min version check
- introduce a content provider interface


## 1.0.0 – 2023-09-21
### Added
* the app
