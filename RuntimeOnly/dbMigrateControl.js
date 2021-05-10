$(document).ready(function () {

    $('#lock-page').click(function (event) {
        if (!confirm(ProcessWire.config.dbMigrateControl.lock)) {
            event.preventDefault();
        }
    });

    $('#unlock-page').click(function (event) {
        if (!confirm(ProcessWire.config.dbMigrateControl.unlock)) {
            event.preventDefault();
        }
    });

});