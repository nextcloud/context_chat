# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

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
