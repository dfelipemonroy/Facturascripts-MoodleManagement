/*!
 * EnrolmentAutofill.js - Auto-fills moodle_userid and moodle_courseid
 * when contact or course map is selected in EditMoodleEnrolment.
 */
$(document).ready(function () {
    // Find the visible autocomplete inputs by their data-field attribute
    var contactAutocomplete = $(".widget-autocomplete[data-field='idcontacto']");
    var courseMapAutocomplete = $(".widget-autocomplete[data-field='idcourse_map']");

    // When contact is selected via autocomplete, fetch moodle_userid
    contactAutocomplete.on("autocompleteselect", function (event, ui) {
        if (ui.item && ui.item.key) {
            var form = $(this).closest("form");
            var idinstance = form.find("[name='idinstance']").val();
            if (idinstance) {
                fetchMoodleUserId(ui.item.key, idinstance, form);
            }
        }
    });

    // When course map is selected via autocomplete, fetch moodle_courseid
    courseMapAutocomplete.on("autocompleteselect", function (event, ui) {
        if (ui.item && ui.item.key) {
            var form = $(this).closest("form");
            fetchMoodleCourseId(ui.item.key, form);
        }
    });

    function fetchMoodleUserId(idcontacto, idinstance, form) {
        $.ajax({
            method: "POST",
            url: window.location.href,
            data: {
                action: "get-moodle-userid",
                idcontacto: idcontacto,
                idinstance: idinstance
            },
            dataType: "json",
            success: function (data) {
                if (data && data.moodle_userid) {
                    form.find("input[name='moodle_userid']").val(data.moodle_userid);
                }
            }
        });
    }

    function fetchMoodleCourseId(idcourse_map, form) {
        $.ajax({
            method: "POST",
            url: window.location.href,
            data: {
                action: "get-moodle-courseid",
                idcourse_map: idcourse_map
            },
            dataType: "json",
            success: function (data) {
                if (data && data.moodle_courseid) {
                    form.find("input[name='moodle_courseid']").val(data.moodle_courseid);
                    if (data.idinstance) {
                        form.find("[name='idinstance']").val(data.idinstance);
                    }
                }
            }
        });
    }
});
