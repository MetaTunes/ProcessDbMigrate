# HannaMigrate
## Migrate PW hanna codes

Provides 2 methods exportAll() and importAll()

### Simple usage:

1. Install the module. 

2. Use TracyDebugger console in the source database to: 
````
$hannaMig = $modules->get('HannaMigrate');
$hannaMig->exportAll('optional_migration_name');
````  
   where optional_migration_name is a name of a related migration, 
   if you are using ProcessDbMigrate. 
   Otherwise leave blank and the data file will be in assets/migrations/hanna-codes/data.json.

If you are using ProcessDbMigrate, the data file will be in the directory holding the migration (or its parent, if no migration name is specified).

3. Copy the data file to the target environment.
4. Then use TracyDebugger console in the target database to:
````
$hannaMig = $modules->get('HannaMigrate');
$hannaMig->importAll('optional_migration_name');
````
   (Do this while on the Hanna Codes page, then refresh the page to see the results and any error messages).

You could also use the methods in your own code. I may look to integrating it in ProcessDbMigrate.
