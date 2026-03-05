document.addEventListener('DOMContentLoaded', function () {
    var statusColors = {
        'table-success': '#198754',
        'table-secondary': '#6c757d',
        'table-warning': '#e6a100',
        'table-danger': '#dc3545'
    };

    // Find all rows in the Moodle instances list
    var form = document.getElementById('formListMoodleInstance');
    if (!form) return;

    form.querySelectorAll('table tbody tr').forEach(function (row) {
        var color = null;
        for (var cls in statusColors) {
            if (row.classList.contains(cls)) {
                color = statusColors[cls];
                break;
            }
        }
        if (!color) return;

        // Status is the 3rd data column (index 3 after checkbox column)
        var cells = row.querySelectorAll('td');
        if (cells.length < 4) return;

        var statusCell = cells[3];

        var dot = document.createElement('i');
        dot.className = 'fa-solid fa-circle';
        dot.style.cssText = 'color:' + color + ';font-size:0.55em;margin-right:0.4em;vertical-align:middle;';
        statusCell.style.fontWeight = 'bold';
        statusCell.style.color = color;
        statusCell.insertBefore(dot, statusCell.firstChild);
    });
});
