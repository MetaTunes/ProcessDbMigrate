$(document).ready(function () {

    $('#remove_files').click(function (event) {
        if (!confirm('Are you sure you want to remove this migration? All files (including those required for uninstallation in this environment) will be removed. This page will remain.')) {
            event.preventDefault();
        }
    });

});