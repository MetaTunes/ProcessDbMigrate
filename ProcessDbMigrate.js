function shortNotes(event) {
    // console.log($(this).html());
    if ($(this).html().length > 200) {
        var note = $(this).html();
        var shortNote = $(this).html().substring(0, 150) + '... <span style="font-style: italic">(click for more)</span>';
        $(this).html(shortNote);
        $(this).after('<div uk-dropdown="mode: click">' + note + '</div>');
        event.stopImmediatePropagation();
    }
}

$(document).ready(function () {

    $('.AdminDataTable  .abbreviate').each(shortNotes);


    $('.ProcessPageEdit-template-DbMigration #delete_page').click(function (event) {
        if ($(this).prop('checked')) {
            if (!confirm(ProcessWire.config.ProcessDbMigrate.confirmDelete)) {
                event.preventDefault();
            }
        }
    });

});