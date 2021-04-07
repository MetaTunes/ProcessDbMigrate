$(document).ready(function () {

    $('#lock-page_copy').click(function (event) {
        if (!confirm('Lock the page? Locking marks the migration as complete and reduces the risk of subsequent conflicts. You will need to sync the lockfile to implement the lock in target environments.')) {
            event.preventDefault();
        }
    });

    $('#unlock-page_copy').click(function (event) {
        if (!confirm('Unlock the page? Unlocking allows changes and may conflict with other migrations. You will need to remove the lockfile from target environments if you wish the unlock to be implemented there.')) {
            event.preventDefault();
        }
    });

});