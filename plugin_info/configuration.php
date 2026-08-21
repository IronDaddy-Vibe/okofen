<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
    <fieldset>
        <legend>{{Valeurs par défaut}}</legend>
        <div class="form-group">
            <label class="col-sm-4 control-label">{{Port JSON par défaut}}</label>
            <div class="col-sm-2">
                <input class="configKey form-control" data-l1key="defaultPort" placeholder="4321">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-4 control-label">{{Capacité de silo par défaut}}</label>
            <div class="col-sm-2">
                <div class="input-group">
                    <input class="configKey form-control" data-l1key="defaultSiloCapacity" placeholder="6000">
                    <span class="input-group-addon">kg</span>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-4 control-label">{{Délai d'attente réseau}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Temps maximal d'attente d'une réponse de la chaudière.}}"></i></sup>
            </label>
            <div class="col-sm-2">
                <div class="input-group">
                    <input class="configKey form-control" data-l1key="httpTimeout" placeholder="10">
                    <span class="input-group-addon">s</span>
                </div>
            </div>
        </div>
    </fieldset>
</form>
