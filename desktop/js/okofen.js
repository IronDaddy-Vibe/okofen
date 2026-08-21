/* Plugin ÖkoFEN pour Jeedom — interface de configuration */

/* Rendu d'une ligne de commande dans l'onglet « Commandes ». */
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td class="hidden-xs">';
    tr += '<span class="cmdAttr" data-l1key="id"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<div class="row">';
    tr += '<div class="col-sm-10">';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name">';
    tr += '</div>';
    tr += '</div>';
    tr += '<div class="row"><div class="col-sm-12">';
    tr += '<span class="cmdAttr label label-default" data-l1key="logicalId" style="font-size:0.85em;"></span>';
    tr += '</div></div>';
    tr += '</td>';
    tr += '<td>';
    tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
    tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="unite" style="width:90px;">';
    tr += '</td>';
    tr += '<td>';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized"/>{{Historiser}}</label> ';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label>';
    tr += '</td>';
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fa fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fa fa-rss"></i> {{Tester}}</a> ';
    }
    tr += '<i class="fa fa-minus-circle pull-right cmdAction cursor" data-action="remove"></i>';
    tr += '</td>';
    tr += '</tr>';
    $('#table_cmd tbody').append(tr);
    var row = $('#table_cmd tbody tr').last();
    row.setValues(_cmd, '.cmdAttr');
    jeedom.cmd.changeType(row, init(_cmd.subType));
}

/* Test de connexion à la chaudière. */
$('#bt_testConnection').on('click', function () {
    $.ajax({
        type: 'POST',
        url: 'plugins/okofen/core/ajax/okofen.ajax.php',
        data: {
            action: 'testConnection',
            ip: $('.eqLogicAttr[data-l1key=configuration][data-l2key=ip]').value(),
            port: $('.eqLogicAttr[data-l1key=configuration][data-l2key=port]').value(),
            password: $('.eqLogicAttr[data-l1key=configuration][data-l2key=password]').value()
        },
        dataType: 'json',
        global: false,
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            $('#div_alert').showAlert({message: data.result, level: 'success'});
        }
    });
});

/* Synchronisation des commandes depuis la chaudière. */
$('#bt_syncCommands').on('click', function () {
    var id = $('.eqLogicAttr[data-l1key=id]').value();
    if (id == '') {
        $('#div_alert').showAlert({message: '{{Enregistrez d\'abord l\'équipement.}}', level: 'warning'});
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/okofen/core/ajax/okofen.ajax.php',
        data: {action: 'syncCommands', id: id},
        dataType: 'json',
        global: false,
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            $('#div_alert').showAlert({message: '{{Commandes synchronisées.}}', level: 'success'});
            $('.eqLogicDisplayCard[data-eqLogic_id=' + id + ']').click();
        }
    });
});

/* Déclaration d'un remplissage de silo. */
$('#bt_addDelivery').on('click', function () {
    var id = $('.eqLogicAttr[data-l1key=id]').value();
    var kg = $('#in_deliveryKg').value();
    if (id == '' || kg == '') {
        $('#div_alert').showAlert({message: '{{Indiquez la quantité livrée.}}', level: 'warning'});
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/okofen/core/ajax/okofen.ajax.php',
        data: {action: 'addDelivery', id: id, kg: kg},
        dataType: 'json',
        global: false,
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            $('#in_deliveryKg').value('');
            $('#div_alert').showAlert({message: '{{Remplissage enregistré.}}', level: 'success'});
            loadHistories(id);
        }
    });
});

/* Déclaration d'une vidange du cendrier. */
$('#bt_addAsh').on('click', function () {
    var id = $('.eqLogicAttr[data-l1key=id]').value();
    if (id == '') {
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/okofen/core/ajax/okofen.ajax.php',
        data: {action: 'addAsh', id: id},
        dataType: 'json',
        global: false,
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            $('#div_alert').showAlert({message: '{{Vidange enregistrée.}}', level: 'success'});
            loadHistories(id);
        }
    });
});

/* Chargement des historiques de maintenance. */
function loadHistories(_id) {
    $.ajax({
        type: 'POST',
        url: 'plugins/okofen/core/ajax/okofen.ajax.php',
        data: {action: 'getHistories', id: _id},
        dataType: 'json',
        global: false,
        success: function (data) {
            if (data.state != 'ok') {
                return;
            }
            $('#table_deliveries tbody').empty();
            $.each(data.result.deliveries, function (i, entry) {
                $('#table_deliveries tbody').append('<tr><td>' + entry.date + '</td><td>' + entry.kg + ' kg</td></tr>');
            });
            $('#table_ash tbody').empty();
            $.each(data.result.ash, function (i, entry) {
                $('#table_ash tbody').append('<tr><td>' + entry.date + '</td><td>' + entry.runtime + ' h</td></tr>');
            });
        }
    });
}

/* Gestionnaire additionnel : on ne touche pas à celui du core, on s'ajoute à côté. */
$('body').on('click.okofen', '.eqLogicDisplayCard', function () {
    var id = $(this).attr('data-eqLogic_id');
    if (id != undefined && id != '') {
        // Laisse le core finir de peupler le formulaire avant de charger les historiques.
        setTimeout(function () { loadHistories(id); }, 500);
    }
});
