$(document).ready(function () {

    $('#remove_files').click(function (event) {
        if (!confirm(ProcessWire.config.dbMigrateRuntimeAction.confirmRemoveFiles)) {
            event.preventDefault();
        }
    });

    $('#install_migration, #uninstall_migration').click(function (event) {
        if (!confirm(ProcessWire.config.dbMigrateRuntimeAction.confirmInstall)) {
            event.preventDefault();
        }
    });

    $('#remove_old').click(function (event) {
        if (!confirm(ProcessWire.config.dbMigrateRuntimeAction.confirmRemoveOld)) {
            event.preventDefault();
        }
    });

});