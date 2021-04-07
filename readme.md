# ProcessDbMigrate
## Introduction
This module is designed to ease the problem of migrating database changes from one PW environment to another.
I wanted something to achieve the following:

- To allow development to take place (in a separate environment on a copy of the live database, or on a test database with the same structure) using the Admin UI. When finished, to permit a declarative approach to defining the migration and implementing it (again in the UI).
- To allow testing of the migration in a test environment on a copy of the live database.
- To allow roll-back of a migration if installation causes problems (ideally while testing rather than after implementation!).
- To provide a record of changes applied.
- Although not originally intended, the module I developed also allows the selective reversion of parts of the database by exporting migration data from a backup copy.  Also, if changes are made directly on the live system (presumably simple, low-risk mods – although not best practice), it allows reverse migration to the development system in a similar fashion.

I should emphasise that what I have built is a 'proof of concept'. The code is pretty hacky. Lots of validation is missing and some spurious error messages occur. However, I have used it successfully in a number of small tests on 3 separate sites and a medium-sized live migration. It would still benefit from further testing and code enhancements from more skilled coders than me.

## Installation
Place the ProcessDbMigrate folder in your site/modules directory. Make sure your environment meets the requirements - you need FieldtypeRuntimeOnly to be installed first. The earliest PW version I have tested it with is 3.0.148, but it might work on earlier 3.0.xxx versions. Please let me know if it works with earlier versions.

Having satisfied the dependencies, install the module.

## Usage
I think the usage is quite logical, but it is fairly complex, so please read the [help file](help.md) first.