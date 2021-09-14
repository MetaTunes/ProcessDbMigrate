/**
 * Abbreviate notes and provide popup
 *
 * @param event
 */
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

    $('#mct-tabs-container').WireTabs({
        items: $('.WireTab')
    });

    $('#migrations').parent().addClass('uk-switcher');
    var arr=window.location.href.split('/');
    var seg = arr[arr.length-1];
    if (seg.startsWith("#")) $(seg).click();

    $('.ProcessPageEdit-template-DbMigration #delete_page').click(function (event) {
        if ($(this).prop('checked')) {
            if (!confirm(ProcessWire.config.ProcessDbMigrate.confirmDelete)) {
                event.preventDefault();
            }
        }
    });
    $('.ProcessPageEdit-template-DbComparison #delete_page').click(function (event) {
        if ($(this).prop('checked')) {
            if (!confirm(ProcessWire.config.ProcessDbMigrate.confirmDelete)) {
                event.preventDefault();
            }
        }
    });

});