<?php
if (!isConnect()) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
sendVarToJS('eqType', 'okofen');
$eqLogics = eqLogic::byType('okofen');
?>

<div class="row row-overflow">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter une chaudière}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>

        <legend><i class="fas fa-fire"></i> {{Mes chaudières ÖkoFEN}}</legend>
        <?php if (count($eqLogics) === 0): ?>
            <div class="alert alert-info">
                {{Aucune chaudière configurée. Cliquez sur « Ajouter une chaudière » pour commencer.}}
            </div>
        <?php else: ?>
            <input class="form-control input-sm" placeholder="{{Rechercher}}" id="in_searchEqlogic">
            <div class="eqLogicThumbnailContainer">
                <?php foreach ($eqLogics as $eqLogic) {
                    $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                    echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                    echo '<img src="plugins/okofen/plugin_info/okofen_icon.png" onerror="this.style.display=\'none\'"/>';
                    echo '<br>';
                    echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                    echo '</div>';
                } ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fa fa-cogs"></i> {{Configuration avancée}}</a>
                <a class="btn btn-sm btn-default eqLogicAction" id="bt_syncCommands"><i class="fas fa-sync"></i> {{Synchroniser les commandes}}</a>
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
                <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fa fa-tachometer-alt"></i> {{Équipement}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fa fa-list"></i> {{Commandes}}</a></li>
            <li role="presentation"><a href="#maintenancetab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fa fa-tools"></i> {{Maintenance}}</a></li>
        </ul>

        <div class="tab-content">
            <!-- Onglet Équipement -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br>
                <div class="col-lg-6">
                    <form class="form-horizontal">
                        <fieldset>
                            <legend>{{Général}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Chaudière ÖkoFEN}}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                <div class="col-sm-6">
                                    <select class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php foreach (jeeObject::all() as $object) { ?>
                                            <option value="<?php echo $object->getId(); ?>"><?php echo $object->getName(); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Catégorie}}</label>
                                <div class="col-sm-8">
                                    <?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) { ?>
                                        <label class="checkbox-inline">
                                            <input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="<?php echo $key; ?>"><?php echo $value['name']; ?>
                                        </label>
                                    <?php } ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Mode d'affichage}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{« Basique » n'affiche que l'essentiel : températures, état, modes de chauffage et d'ECS, stock de pellets et alertes. « Expert » affiche l'intégralité des variables remontées par la chaudière. Rien n'est supprimé dans un cas comme dans l'autre : toutes les commandes restent créées, alimentées et utilisables en scénario, seul leur affichage change. Le réglage se prend en compte à la synchronisation.}}"></i></sup>
                                </label>
                                <div class="col-sm-6">
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="displayMode">
                                        <option value="basic">{{Basique — l'essentiel}}</option>
                                        <option value="expert">{{Expert — toutes les variables}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label"></label>
                                <div class="col-sm-8">
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend>{{Connexion à la chaudière}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Adresse IP}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{Adresse IP locale de la chaudière, par exemple 192.168.0.50}}"></i></sup>
                                </label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.0.50">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Port JSON}}</label>
                                <div class="col-sm-3">
                                    <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="4321">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Mot de passe JSON}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{À relever sur l'écran tactile : Menu > Réglages généraux > Touch/JSON. Ce n'est PAS le mot de passe de l'interface web.}}"></i></sup>
                                </label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="password">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Intervalle d'interrogation}}</label>
                                <div class="col-sm-3">
                                    <div class="input-group">
                                        <input type="number" min="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="pollInterval" placeholder="5">
                                        <span class="input-group-addon">{{minutes}}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label"></label>
                                <div class="col-sm-6">
                                    <a class="btn btn-info btn-sm" id="bt_testConnection"><i class="fas fa-plug"></i> {{Tester la connexion}}</a>
                                </div>
                            </div>
                        </fieldset>
                    </form>
                </div>

                <div class="col-lg-6">
                    <form class="form-horizontal">
                        <fieldset>
                            <legend>{{Silo et consommation}}</legend>
                            <div class="form-group">
                                <label class="col-sm-5 control-label">{{Capacité du silo}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{Laisser vide pour reprendre la capacité déclarée par la chaudière.}}"></i></sup>
                                </label>
                                <div class="col-sm-4">
                                    <div class="input-group">
                                        <input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="siloCapacity" placeholder="6000">
                                        <span class="input-group-addon">kg</span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-5 control-label">{{Source du niveau de stock}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{« Plugin » tient une comptabilité autonome : vous déclarez les livraisons dans l'onglet Maintenance. « Chaudière » lit le compteur interne, à ne choisir que si votre installation permet de le recharger après une livraison — sinon il reste bloqué à zéro.}}"></i></sup>
                                </label>
                                <div class="col-sm-5">
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="stockSource">
                                        <option value="plugin">{{Comptabilité du plugin}}</option>
                                        <option value="boiler">{{Compteur de la chaudière}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-5 control-label">{{Puissance nominale}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{Puissance maximale de la chaudière. La modulation réelle est lue à chaque relève : cette valeur n'est que le plafond.}}"></i></sup>
                                </label>
                                <div class="col-sm-4">
                                    <div class="input-group">
                                        <input type="number" min="1" step="0.5" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="nominalPower" placeholder="12">
                                        <span class="input-group-addon">kW</span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-5 control-label">{{Facteur de correction}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{Recalage de l'estimation. Si le plugin annonce 10 % de moins que la consommation réelle constatée entre deux livraisons, mettre 1.1.}}"></i></sup>
                                </label>
                                <div class="col-sm-4">
                                    <input type="number" min="0.1" step="0.05" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="consumptionCorrection" placeholder="1">
                                </div>
                            </div>
                        </fieldset>
                    </form>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        {{La consommation est estimée en intégrant la modulation réelle de la chaudière à chaque relève, et non à puissance nominale constante. Elle sert au suivi quotidien et au calcul d'autonomie. Avec la source « Compteur de la chaudière », le niveau de stock reste celui déclaré sur l'écran tactile, qui fait autorité dès qu'il est renseigné.}}
                    </div>
                </div>
            </div>

            <!-- Onglet Commandes -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br>
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th style="width:60px;">#</th>
                                <th style="width:250px;">{{Nom}}</th>
                                <th style="width:180px;">{{Type}}</th>
                                <th style="width:120px;">{{Unité}}</th>
                                <th>{{Paramètres}}</th>
                                <th style="width:120px;">{{Actions}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <!-- Onglet Maintenance -->
            <div role="tabpanel" class="tab-pane" id="maintenancetab">
                <br>
                <div class="col-lg-6">
                    <legend><i class="fas fa-truck"></i> {{Remplissages de silo}}</legend>
                    <div class="input-group" style="max-width:420px;">
                        <input type="number" min="1" class="form-control" id="in_deliveryKg" placeholder="{{Quantité livrée en kg}}">
                        <span class="input-group-btn">
                            <a class="btn btn-success" id="bt_addDelivery"><i class="fas fa-plus"></i> {{Déclarer}}</a>
                        </span>
                    </div>
                    <br>
                    <table class="table table-condensed table-bordered" id="table_deliveries">
                        <thead><tr><th>{{Date}}</th><th>{{Quantité}}</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="col-lg-6">
                    <legend><i class="fas fa-trash-alt"></i> {{Vidanges du cendrier}}</legend>
                    <a class="btn btn-success" id="bt_addAsh"><i class="fas fa-check"></i> {{Déclarer une vidange effectuée}}</a>
                    <br><br>
                    <table class="table table-condensed table-bordered" id="table_ash">
                        <thead><tr><th>{{Date}}</th><th>{{Compteur brûleur}}</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'okofen', 'js', 'okofen'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
