$(document).ready(function () {

    $('#remove_files').click(function (event) {
        if (!confirm(ProcessWire.config.dbMigrateActions.confirmRemoveFiles)) {
            event.preventDefault();
        }
    });

});