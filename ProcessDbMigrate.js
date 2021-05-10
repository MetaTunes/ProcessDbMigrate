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


    $('#remove_files').click(function (event) {
        if (!confirm('Are you sure you want to remove the files for this migration? All files (in "old" and "new" directories) will be removed. This page will remain.')) {
            event.preventDefault();
        }
    });

});