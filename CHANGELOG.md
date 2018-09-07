# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Added
- Introduced `Qless\Jobs\JobHandlerInterface` so that `Worker::registerJobPerformHandler` will require
  that its argument is implements the JobHandlerInterface interface
- Increased test coverage
- Added all the required PHP extensions to the Composer's `require` section so that
  now Composer will check dependencies on library installation time

### Changed
- Move `Qless\Lua` to the `Qless\LuaScript`
- Move `Qless\Listener` to the `Qless\Subscriber`
- More code quality improvements to follow the SRP. Thus the code base for almost all classes has been changed
  
### Removed
- Fully refactor the `Qless\Client` class and removed no longer used code
- Removed no longer required `Qless\Qless::fork` and `pcntl_fork` check

### Fixed
- Fixed demo

## 1.0.0 - 2018-08-30
### Added
 - Initial stable release

[Unreleased]: https://github.com/pdffiller/qless-php/compare/v1.0.0...HEAD