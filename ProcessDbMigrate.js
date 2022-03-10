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

/**
 * Pop-out for help
 *
 * @param event
 * @returns {boolean}
 */
function popOut(event) {
    var link = $(this).attr('href');
    window.open(link, 'popup', 'resizable= 1, height = 800, width=1200, scrollbars=1');
    return false;
}

$(document).ready(function () {

    $('.AdminDataTable  .abbreviate').each(shortNotes);

    //To facilitate tabs in module config page. Condition prevents running where WireTabs not available (to prevent js errors).
    if($('#mct-tabs-container').length) {
        $('#mct-tabs-container').WireTabs({
            items: $('.WireTab')
        });
    }

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

    $(document).on('click', 'a.popout-help', popOut);

});