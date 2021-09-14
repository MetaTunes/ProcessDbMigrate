$(document).ready(function () {

    $('#lock-page').click(function (event) {
        if (!confirm(ProcessWire.config.dbMigrateRuntimeControl.lock)) {
            event.preventDefault();
        }
    });

    $('#unlock-page').click(function (event) {
        if (!confirm(ProcessWire.config.dbMigrateRuntimeControl.unlock)) {
            event.preventDefault();
        }
    });

});