# ProcessDbMigrate
## Introduction
This module is designed to ease the problem of migrating database changes from one PW environment to another.
I wanted something to achieve the following:

- To allow development to take place (in a separate environment on a copy of the live database, or on a test database with the same structure) using the Admin UI. When finished, to permit a declarative approach to defining the migration and implementing it (again in the UI).
- To allow testing of the migration in a test environment on a copy of the live database.
- To allow roll-back of a migration if installation causes problems (ideally while testing rather than after implementation!).
- To provide a record of changes applied.
- Although not originally intended, the module I developed also allows the selective reversion of parts of the database by exporting migration data from a backup copy.  Also, if changes are made directly on the live system (presumably simple, low-risk mods – although not best practice), it allows reverse migration to the development system in a similar fashion.

While numerous improvements have been made since the early versions, I should emphasise that what I have built is still 'alpha'. Please test it thoroughly before using it on a live system and take frequent backups. 
I have used it on a number of sites, but I have not tested it on all possible configurations.

This version incorporates automated tracking of changes - your migration specification is built for you as you make changes in the development system! It also alerts you
to circular dependencies and allows you to resolve them. If you ignore them then the installation will probably still work the system will make up to 3 tries before giving up.

## Installation
Place the ProcessDbMigrate folder in your site/modules directory or install from the PW modules library. PW version 3.0.210 or later is recommended. Please let me know if it works with earlier versions.

Having satisfied the dependencies, install the module.

Note that if your admin path is something other than /processwire/, the module will change it's bootstrap json file to reflect this. 
If you attempt to migrate to a site with a different admin path then it may appear that the migration does not  fully install, although it might work.

## Updating
Use the PW modules library to update the module (check for updates in the module information).
Note that if you have hacked the bootstrap json, then updating will overwrite your hack.

## Usage
I think the usage is quite logical, but it is fairly complex, so please read the [help file](https://metatunes.github.io/DbMigrate/help.html) first.