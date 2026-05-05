<?php

/**
 * AP Detail — widget edit/config panel template.
 *
 * Variables injected by Zabbix core before rendering this view:
 *
 * @var CView           $this
 * @var array           $data  Form field values from WidgetForm::getFieldsValues()
 *
 * Field order mirrors WidgetForm::addFields() declaration order.
 * Each field is rendered via its own CWidgetField::getView() method — Zabbix
 * handles the actual <input> HTML for type-specific fields (host picker, etc.).
 */

declare(strict_types=1);

?>
<div class="fields-group">
    <label class="field-label"><?= _('AP host') ?></label>
    <?= $fields['hostid']->getView() ?>
    <div class="fields-group__hint">
        <?= _('Select manually or leave empty to receive from Host Navigator broadcast.') ?>
    </div>
</div>

<div class="fields-group fields-group--separator">
    <h4 class="fields-group__heading"><?= _('ExtremeCloud IQ') ?></h4>
    <label class="field-label"><?= _('API base URL') ?></label>
    <?= $fields['xiq_host']->getView() ?>
    <div class="fields-group__hint">
        <?= _('OAuth2 credentials are read from Zabbix global macros {$XIQ_CLIENT_ID} and {$XIQ_CLIENT_SECRET}.') ?>
    </div>
</div>

<div class="fields-group fields-group--separator">
    <h4 class="fields-group__heading"><?= _('PacketFence NAC') ?></h4>
    <label class="field-label"><?= _('API URL') ?></label>
    <?= $fields['pf_url']->getView() ?>
    <label class="field-label"><?= _('Username') ?></label>
    <?= $fields['pf_user']->getView() ?>
    <label class="field-label"><?= _('Password') ?></label>
    <?= $fields['pf_pass']->getView() ?>
    <div class="fields-group__hint">
        <?= _('Use a dedicated read-only PacketFence webservices account — never the admin credentials.') ?>
    </div>
</div>

<div class="fields-group fields-group--separator">
    <label class="field-label"><?= _('Refresh interval (seconds)') ?></label>
    <?= $fields['refresh_rate']->getView() ?>
    <div class="fields-group__hint">
        <?= _('0 = use dashboard default.') ?>
    </div>
</div>
