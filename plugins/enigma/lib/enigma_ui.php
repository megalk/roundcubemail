<?php

/**
 +-------------------------------------------------------------------------+
 | User Interface for the Enigma Plugin                                    |
 |                                                                         |
 | Copyright (C) 2010-2015 The Roundcube Dev Team                          |
 |                                                                         |
 | Licensed under the GNU General Public License version 3 or              |
 | any later version with exceptions for skins & plugins.                  |
 | See the README file for a full license statement.                       |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                              |
 +-------------------------------------------------------------------------+
*/

class enigma_ui
{
    private $rc;
    private $enigma;
    private $home;
    private $css_loaded;
    private $js_loaded;
    private $data;
    private $keys_parts  = array();
    private $keys_bodies = array();


    function __construct($enigma_plugin, $home='')
    {
        $this->enigma = $enigma_plugin;
        $this->rc     = $enigma_plugin->rc;
        $this->home   = $home; // we cannot use $enigma_plugin->home here
    }

    /**
     * UI initialization and requests handlers.
     *
     * @param string Preferences section
     */
    function init()
    {
        $this->add_js();

        $action = rcube_utils::get_input_value('_a', rcube_utils::INPUT_GPC);

        if ($this->rc->action == 'plugin.enigmakeys') {
            switch ($action) {
                case 'delete':
                    $this->key_delete();
                    break;
/*
                case 'edit':
                    $this->key_edit();
                    break;
*/
                case 'import':
                    $this->key_import();
                    break;

                case 'export':
                    $this->key_export();
                    break;

                case 'generate':
                    $this->key_generate();
                    break;

                case 'create':
                    $this->key_create();
                    break;

                case 'search':
                case 'list':
                    $this->key_list();
                    break;

                case 'info':
                    $this->key_info();
                    break;
            }

            $this->rc->output->add_handlers(array(
                    'keyslist'     => array($this, 'tpl_keys_list'),
                    'keyframe'     => array($this, 'tpl_key_frame'),
                    'countdisplay' => array($this, 'tpl_keys_rowcount'),
                    'searchform'   => array($this->rc->output, 'search_form'),
            ));

            $this->rc->output->set_pagetitle($this->enigma->gettext('enigmakeys'));
            $this->rc->output->send('enigma.keys');
        }
/*
        // Preferences UI
        else if ($this->rc->action == 'plugin.enigmacerts') {
            $this->rc->output->add_handlers(array(
                    'keyslist'     => array($this, 'tpl_certs_list'),
                    'keyframe'     => array($this, 'tpl_cert_frame'),
                    'countdisplay' => array($this, 'tpl_certs_rowcount'),
                    'searchform'   => array($this->rc->output, 'search_form'),
            ));

            $this->rc->output->set_pagetitle($this->enigma->gettext('enigmacerts'));
            $this->rc->output->send('enigma.certs'); 
        }
*/
        // Message composing UI
        else if ($this->rc->action == 'compose') {
            $this->compose_ui();
        }
    }

    /**
     * Adds CSS style file to the page header.
     */
    function add_css()
    {
        if ($this->css_loaded)
            return;

        $skin_path = $this->enigma->local_skin_path();
        if (is_file($this->home . "/$skin_path/enigma.css")) {
            $this->enigma->include_stylesheet("$skin_path/enigma.css");
        }

        $this->css_loaded = true;
    }

    /**
     * Adds javascript file to the page header.
     */
    function add_js()
    {
        if ($this->js_loaded) {
            return;
        }

        $this->enigma->include_script('enigma.js');

        $this->js_loaded = true;
    }

    /**
     * Initializes key password prompt
     *
     * @param enigma_error $status Error object with key info
     * @param array        $params Optional prompt parameters
     */
    function password_prompt($status, $params = array())
    {
        $data = $status->getData('missing');

        if (empty($data)) {
            $data = $status->getData('bad');
        }

        $data = array('keyid' => key($data), 'user' => $data[key($data)]);

        if (!empty($params)) {
            $data = array_merge($params, $data);
        }

        if ($this->rc->action == 'send') {
            $this->rc->output->command('enigma_password_request', $data);
        }
        else {
            $this->rc->output->set_env('enigma_password_request', $data);
        }

        // add some labels to client
        $this->rc->output->add_label('enigma.enterkeypasstitle', 'enigma.enterkeypass',
            'save', 'cancel');

        $this->add_css();
        $this->add_js();
    }

    /**
     * Template object for key info/edit frame.
     *
     * @param array Object attributes
     *
     * @return string HTML output
     */
    function tpl_key_frame($attrib)
    {
        if (!$attrib['id']) {
            $attrib['id'] = 'rcmkeysframe';
        }

        $attrib['name'] = $attrib['id'];

        $this->rc->output->set_env('contentframe', $attrib['name']);
        $this->rc->output->set_env('blankpage', $attrib['src'] ?
            $this->rc->output->abs_url($attrib['src']) : 'program/resources/blank.gif');

        return $this->rc->output->frame($attrib);
    }

    /**
     * Template object for list of keys.
     *
     * @param array Object attributes
     *
     * @return string HTML content
     */
    function tpl_keys_list($attrib)
    {
        // add id to message list table if not specified
        if (!strlen($attrib['id'])) {
            $attrib['id'] = 'rcmenigmakeyslist';
        }

        // define list of cols to be displayed
        $a_show_cols = array('name');

        // create XHTML table
        $out = $this->rc->table_output($attrib, array(), $a_show_cols, 'id');

        // set client env
        $this->rc->output->add_gui_object('keyslist', $attrib['id']);
        $this->rc->output->include_script('list.js');

        // add some labels to client
        $this->rc->output->add_label('enigma.keyremoveconfirm', 'enigma.keyremoving');

        return $out;
    }

    /**
     * Key listing (and searching) request handler
     */
    private function key_list()
    {
        $this->enigma->load_engine();

        $pagesize = $this->rc->config->get('pagesize', 100);
        $page     = max(intval(rcube_utils::get_input_value('_p', rcube_utils::INPUT_GPC)), 1);
        $search   = rcube_utils::get_input_value('_q', rcube_utils::INPUT_GPC);

        // Get the list
        $list = $this->enigma->engine->list_keys($search);

        if ($list && ($list instanceof enigma_error))
            $this->rc->output->show_message('enigma.keylisterror', 'error');
        else if (empty($list))
            $this->rc->output->show_message('enigma.nokeysfound', 'notice');
        else if (is_array($list)) {
            // Save the size
            $listsize = count($list);

            // Sort the list by key (user) name
            usort($list, array('enigma_key', 'cmp'));

            // Slice current page
            $list = array_slice($list, ($page - 1) * $pagesize, $pagesize);
            $size = count($list);

            // Add rows
            foreach ($list as $key) {
                $this->rc->output->command('enigma_add_list_row',
                    array('name' => rcube::Q($key->name), 'id' => $key->id));
            }
        }

        $this->rc->output->set_env('rowcount', $size);
        $this->rc->output->set_env('search_request', $search);
        $this->rc->output->set_env('pagecount', ceil($listsize/$pagesize));
        $this->rc->output->set_env('current_page', $page);
        $this->rc->output->command('set_rowcount',
            $this->get_rowcount_text($listsize, $size, $page));

        $this->rc->output->send();
    }

    /**
     * Template object for list records counter.
     *
     * @param array Object attributes
     *
     * @return string HTML output
     */
    function tpl_keys_rowcount($attrib)
    {
        if (!$attrib['id'])
            $attrib['id'] = 'rcmcountdisplay';

        $this->rc->output->add_gui_object('countdisplay', $attrib['id']);

        return html::span($attrib, $this->get_rowcount_text());
    }

    /**
     * Returns text representation of list records counter
     */
    private function get_rowcount_text($all=0, $curr_count=0, $page=1)
    {
        if (!$curr_count) {
            $out = $this->enigma->gettext('nokeysfound');
        }
        else {
            $pagesize = $this->rc->config->get('pagesize', 100);
            $first    = ($page - 1) * $pagesize;

            $out = $this->enigma->gettext(array(
                'name' => 'keysfromto',
                'vars' => array(
                    'from'  => $first + 1,
                    'to'    => $first + $curr_count,
                    'count' => $all)
            ));
        }

        return $out;
    }

    /**
     * Key information page handler
     */
    private function key_info()
    {
        $this->enigma->load_engine();

        $id  = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GET);
        $res = $this->enigma->engine->get_key($id);

        if ($res instanceof enigma_key) {
            $this->data = $res;
        }
        else { // error
            $this->rc->output->show_message('enigma.keyopenerror', 'error');
            $this->rc->output->command('parent.enigma_loadframe');
            $this->rc->output->send('iframe');
        }

        $this->rc->output->add_handlers(array(
            'keyname' => array($this, 'tpl_key_name'),
            'keydata' => array($this, 'tpl_key_data'),
        ));

        $this->rc->output->set_pagetitle($this->enigma->gettext('keyinfo'));
        $this->rc->output->send('enigma.keyinfo');
    }

    /**
     * Template object for key name
     */
    function tpl_key_name($attrib)
    {
        return rcube::Q($this->data->name);
    }

    /**
     * Template object for key information page content
     */
    function tpl_key_data($attrib)
    {
        $out   = '';
        $table = new html_table(array('cols' => 2));

        // Key user ID
        $table->add('title', $this->enigma->gettext('keyuserid'));
        $table->add(null, rcube::Q($this->data->name));

        // Key ID
        $table->add('title', $this->enigma->gettext('keyid'));
        $table->add(null, $this->data->subkeys[0]->get_short_id());

        // Key type
        $keytype = $this->data->get_type();
        if ($keytype == enigma_key::TYPE_KEYPAIR) {
            $type = $this->enigma->gettext('typekeypair');
        }
        else if ($keytype == enigma_key::TYPE_PUBLIC) {
            $type = $this->enigma->gettext('typepublickey');
        }
        $table->add('title', $this->enigma->gettext('keytype'));
        $table->add(null, $type);

        // Key fingerprint
        $table->add('title', $this->enigma->gettext('fingerprint'));
        $table->add(null, $this->data->subkeys[0]->get_fingerprint());

        $out .= html::tag('fieldset', null,
            html::tag('legend', null,
                $this->enigma->gettext('basicinfo')) . $table->show($attrib));

        // Subkeys
        $table = new html_table(array('cols' => 5, 'id' => 'enigmasubkeytable', 'class' => 'records-table'));

        $table->add_header('id', $this->enigma->gettext('subkeyid'));
        $table->add_header('algo', $this->enigma->gettext('subkeyalgo'));
        $table->add_header('created', $this->enigma->gettext('subkeycreated'));
        $table->add_header('expires', $this->enigma->gettext('subkeyexpires'));
        $table->add_header('usage', $this->enigma->gettext('subkeyusage'));

        $now         = time();
        $date_format = $this->rc->config->get('date_format', 'Y-m-d');
        $usage_map   = array(
            enigma_key::CAN_ENCRYPT => $this->enigma->gettext('typeencrypt'),
            enigma_key::CAN_SIGN    => $this->enigma->gettext('typesign'),
            enigma_key::CAN_CERTIFY => $this->enigma->gettext('typecert'),
            enigma_key::CAN_AUTH    => $this->enigma->gettext('typeauth'),
        );

        foreach ($this->data->subkeys as $subkey) {
            $algo = $subkey->get_algorithm();
            if ($algo && $subkey->length) {
                $algo .= ' (' . $subkey->length . ')';
            }

            $usage = array();
            foreach ($usage_map as $key => $text) {
                if ($subkey->usage & $key) {
                    $usage[] = $text;
                }
            }

            $table->add('id', $subkey->get_short_id());
            $table->add('algo', $algo);
            $table->add('created', $subkey->created ? $this->rc->format_date($subkey->created, $date_format, false) : '');
            $table->add('expires', $subkey->expires ? $this->rc->format_date($subkey->expires, $date_format, false) : $this->enigma->gettext('expiresnever'));
            $table->add('usage', implode(',', $usage));
            $table->set_row_attribs($subkey->revoked || ($subkey->expires && $subkey->expires < $now) ? 'deleted' : '');
        }

        $out .= html::tag('fieldset', null,
            html::tag('legend', null,
                $this->enigma->gettext('subkeys')) . $table->show());

        // Additional user IDs
        $table = new html_table(array('cols' => 2, 'id' => 'enigmausertable', 'class' => 'records-table'));

        $table->add_header('id', $this->enigma->gettext('userid'));
        $table->add_header('valid', $this->enigma->gettext('uservalid'));

        foreach ($this->data->users as $user) {
            $username = $user->name;
            if ($user->comment) {
                $username .= ' (' . $user->comment . ')';
            }
            $username .= ' <' . $user->email . '>';

            $table->add('id', rcube::Q(trim($username)));
            $table->add('valid', $this->enigma->gettext($user->valid ? 'valid' : 'unknown'));
            $table->set_row_attribs($user->revoked || !$user->valid ? 'deleted' : '');
        }

        $out .= html::tag('fieldset', null,
            html::tag('legend', null,
                $this->enigma->gettext('userids')) . $table->show());

        return $out;
    }

    /**
     * Key(s) export handler
     */
    private function key_export()
    {
        $keys   = rcube_utils::get_input_value('_keys', rcube_utils::INPUT_GPC);
        $engine = $this->enigma->load_engine();
        $list   = $keys == '*' ? $engine->list_keys() : explode(',', $keys);

        if (is_array($list)) {
            $filename = 'export.pgp';
            if (count($list) == 1) {
                $filename = (is_object($list[0]) ? $list[0]->id : $list[0]) . '.pgp';
            }

            // send downlaod headers
            header('Content-Type: application/pgp-keys');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            if ($fp = fopen('php://output', 'w')) {
                foreach ($list as $key) {
                    $engine->export_key(is_object($key) ? $key->id : $key, $fp);
                }
            }
        }

        exit;
    }

    /**
     * Key import (page) handler
     */
    private function key_import()
    {
        // Import process
        if ($data = rcube_utils::get_input_value('_keys', rcube_utils::INPUT_POST)) {
            // Import from generation form (ajax request)
            $this->enigma->load_engine();
            $result = $this->enigma->engine->import_key($data);

            if (is_array($result)) {
                $this->rc->output->command('enigma_key_create_success');
                $this->rc->output->show_message('enigma.keygeneratesuccess', 'confirmation');
            }
            else {
                $this->rc->output->show_message('enigma.keysimportfailed', 'error');
            }

            $this->rc->output->send();
        }
        else if ($_FILES['_file']['tmp_name'] && is_uploaded_file($_FILES['_file']['tmp_name'])) {
            $this->enigma->load_engine();
            $result = $this->enigma->engine->import_key($_FILES['_file']['tmp_name'], true);

            if (is_array($result)) {
                // reload list if any keys has been added
                if ($result['imported']) {
                    $this->rc->output->command('parent.enigma_list', 1);
                }
                else {
                    $this->rc->output->command('parent.enigma_loadframe');
                }

                $this->rc->output->show_message('enigma.keysimportsuccess', 'confirmation',
                    array('new' => $result['imported'], 'old' => $result['unchanged']));

                $this->rc->output->send('iframe');
            }
            else {
                $this->rc->output->show_message('enigma.keysimportfailed', 'error');
            }
        }
        else if ($err = $_FILES['_file']['error']) {
            if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
                $this->rc->output->show_message('filesizeerror', 'error',
                    array('size' => $this->rc->show_bytes(parse_bytes(ini_get('upload_max_filesize')))));
            } else {
                $this->rc->output->show_message('fileuploaderror', 'error');
            }
        }

        $this->rc->output->add_handlers(array(
            'importform' => array($this, 'tpl_key_import_form'),
        ));

        $this->rc->output->set_pagetitle($this->enigma->gettext('keyimport'));
        $this->rc->output->send('enigma.keyimport');
    }

    /**
     * Template object for key import (upload) form
     */
    function tpl_key_import_form($attrib)
    {
        $attrib += array('id' => 'rcmKeyImportForm');

        $upload = new html_inputfield(array('type' => 'file', 'name' => '_file',
            'id' => 'rcmimportfile', 'size' => 30));

        $form = html::p(null,
            rcube::Q($this->enigma->gettext('keyimporttext'), 'show')
            . html::br() . html::br() . $upload->show()
        );

        $this->rc->output->add_label('selectimportfile', 'importwait');
        $this->rc->output->add_gui_object('importform', $attrib['id']);

        $out = $this->rc->output->form_tag(array(
            'action' => $this->rc->url(array('action' => $this->rc->action, 'a' => 'import')),
            'method' => 'post',
            'enctype' => 'multipart/form-data') + $attrib,
            $form);

        return $out;
    }

    /**
     * Server-side key pair generation handler
     */
    private function key_generate()
    {
        $user = rcube_utils::get_input_value('_user', rcube_utils::INPUT_POST, true);
        $pass = rcube_utils::get_input_value('_password', rcube_utils::INPUT_POST, true);
        $size = (int) rcube_utils::get_input_value('_size', rcube_utils::INPUT_POST);

        if ($size > 4096) {
            $size = 4096;
        }

        $ident = rcube_mime::decode_address_list($user, 1, false);

        if (empty($ident)) {
            $this->rc->output->show_message('enigma.keygenerateerror', 'error');
            $this->rc->output->send();
        }

        $this->enigma->load_engine();
        $result = $this->enigma->engine->generate_key(array(
            'user'     => $ident[1]['name'],
            'email'    => $ident[1]['mailto'],
            'password' => $pass,
            'size'     => $size,
        ));

        if ($result instanceof enigma_key) {
            $this->rc->output->command('enigma_key_create_success');
            $this->rc->output->show_message('enigma.keygeneratesuccess', 'confirmation');
        }
        else {
            $this->rc->output->show_message('enigma.keygenerateerror', 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Key generation page handler
     */
    private function key_create()
    {
        $this->enigma->include_script('openpgp.min.js');

        $this->rc->output->add_handlers(array(
            'keyform' => array($this, 'tpl_key_create_form'),
        ));

        $this->rc->output->set_env('enigma_keygen_server', $this->rc->config->get('enigma_keygen_server'));

        $this->rc->output->set_pagetitle($this->enigma->gettext('keygenerate'));
        $this->rc->output->send('enigma.keycreate');
    }

    /**
     * Template object for key generation form
     */
    function tpl_key_create_form($attrib)
    {
        $attrib += array('id' => 'rcmKeyCreateForm');
        $table  = new html_table(array('cols' => 2));

        // get user's identities
        $identities = $this->rc->user->list_identities(null, true);

        // Identity
        $select = new html_select(array('name' => 'identity', 'id' => 'key-ident'));
        foreach ((array) $identities as $idx => $ident) {
            $name = empty($ident['name']) ? ('<' . $ident['email'] . '>') : $ident['ident'];
            $select->add($name, $idx);
        }

        $table->add('title', html::label('key-name', rcube::Q($this->enigma->gettext('newkeyident'))));
        $table->add(null, $select->show(0));

        // Key size
        $select = new html_select(array('name' => 'size', 'id' => 'key-size'));
        $select->add($this->enigma->gettext('key2048'), '2048');
        $select->add($this->enigma->gettext('key4096'), '4096');

        $table->add('title', html::label('key-size', rcube::Q($this->enigma->gettext('newkeysize'))));
        $table->add(null, $select->show());

        // Password and confirm password
        $table->add('title', html::label('key-pass', rcube::Q($this->enigma->gettext('newkeypass'))));
        $table->add(null, rcube_output::get_edit_field('password', '',
            array('id' => 'key-pass', 'size' => $attrib['size'], 'required' => true), 'password'));

        $table->add('title', html::label('key-pass-confirm', rcube::Q($this->enigma->gettext('newkeypassconfirm'))));
        $table->add(null, rcube_output::get_edit_field('password-confirm', '',
            array('id' => 'key-pass-confirm', 'size' => $attrib['size'], 'required' => true), 'password'));

        $this->rc->output->add_gui_object('keyform', $attrib['id']);
        $this->rc->output->add_label('enigma.keygenerating', 'enigma.formerror',
            'enigma.passwordsdiffer', 'enigma.keygenerateerror', 'enigma.nonameident',
            'enigma.keygennosupport');

        return $this->rc->output->form_tag(array(), $table->show($attrib));
    }

    /**
     * Key deleting
     */
    private function key_delete()
    {
        $keys   = rcube_utils::get_input_value('_keys', rcube_utils::INPUT_POST);
        $engine = $this->enigma->load_engine();

        foreach ((array)$keys as $key) {
            $res = $engine->delete_key($key);

            if ($res !== true) {
                $this->rc->output->show_message('enigma.keyremoveerror', 'error');
                $this->rc->output->command('enigma_list');
                $this->rc->output->send();
            }
        }

        $this->rc->output->command('enigma_list');
        $this->rc->output->show_message('enigma.keyremovesuccess', 'confirmation');
        $this->rc->output->send();
    }

    /**
     * Init compose UI (add task button and the menu)
     */
    private function compose_ui()
    {
        $this->add_css();

        // Options menu button
        $this->enigma->add_button(array(
            'type'     => 'link',
            'command'  => 'plugin.enigma',
            'onclick'  => "rcmail.command('menu-open', 'enigmamenu', event.target, event)",
            'class'    => 'button enigma',
            'title'    => 'encryptionoptions',
            'label'    => 'encryption',
            'domain'   => $this->enigma->ID,
            'width'    => 32,
            'height'   => 32
            ), 'toolbar');

        $menu  = new html_table(array('cols' => 2));
        $chbox = new html_checkbox(array('value' => 1));

        $menu->add(null, html::label(array('for' => 'enigmasignopt'),
            rcube::Q($this->enigma->gettext('signmsg'))));
        $menu->add(null, $chbox->show($this->rc->config->get('enigma_sign_all') ? 1 : 0,
            array('name' => '_enigma_sign', 'id' => 'enigmasignopt')));

        $menu->add(null, html::label(array('for' => 'enigmaencryptopt'),
            rcube::Q($this->enigma->gettext('encryptmsg'))));
        $menu->add(null, $chbox->show($this->rc->config->get('enigma_encrypt_all') ? 1 : 0,
            array('name' => '_enigma_encrypt', 'id' => 'enigmaencryptopt')));

        $menu = html::div(array('id' => 'enigmamenu', 'class' => 'popupmenu'), $menu->show());

        // Options menu contents
        $this->rc->output->add_footer($menu);
    }

    /**
     * Handler for message_body_prefix hook.
     * Called for every displayed (content) part of the message.
     * Adds infobox about signature verification and/or decryption
     * status above the body.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function status_message($p)
    {
        // skip: not a message part
        if ($p['part'] instanceof rcube_message) {
            return $p;
        }

        // skip: message has no signed/encoded content
        if (!$this->enigma->engine) {
            return $p;
        }

        $engine  = $this->enigma->engine;
        $part_id = $p['part']->mime_id;

        // Decryption status
        if (isset($engine->decryptions[$part_id])) {
            $attach_scripts = true;

            // get decryption status
            $status = $engine->decryptions[$part_id];

            // display status info
            $attrib['id'] = 'enigma-message';

            if ($status instanceof enigma_error) {
                $attrib['class'] = 'enigmaerror';
                $code            = $status->getCode();

                if ($code == enigma_error::KEYNOTFOUND) {
                    $msg = rcube::Q(str_replace('$keyid', enigma_key::format_id($status->getData('id')),
                        $this->enigma->gettext('decryptnokey')));
                }
                else if ($code == enigma_error::BADPASS) {
                    $msg = rcube::Q($this->enigma->gettext('decryptbadpass'));
                    $this->password_prompt($status);
                }
                else {
                    $msg = rcube::Q($this->enigma->gettext('decrypterror'));
                }
            }
            else {
                $attrib['class'] = 'enigmanotice';
                $msg = rcube::Q($this->enigma->gettext('decryptok'));
            }

            $p['prefix'] .= html::div($attrib, $msg);
        }

        // Signature verification status
        if (isset($engine->signed_parts[$part_id])
            && ($sig = $engine->signatures[$engine->signed_parts[$part_id]])
        ) {
            $attach_scripts = true;

            // display status info
            $attrib['id'] = 'enigma-message';

            if ($sig instanceof enigma_signature) {
                $sender = ($sig->name ? $sig->name . ' ' : '') . '<' . $sig->email . '>';

                if ($sig->valid === enigma_error::UNVERIFIED) {
                    $attrib['class'] = 'enigmawarning';
                    $msg = str_replace('$sender', $sender, $this->enigma->gettext('sigunverified'));
                    $msg = str_replace('$keyid', $sig->id, $msg);
                    $msg = rcube::Q($msg);
                }
                else if ($sig->valid) {
                    $attrib['class'] = 'enigmanotice';
                    $msg = rcube::Q(str_replace('$sender', $sender, $this->enigma->gettext('sigvalid')));
                }
                else {
                    $attrib['class'] = 'enigmawarning';
                    $msg = rcube::Q(str_replace('$sender', $sender, $this->enigma->gettext('siginvalid')));
                }
            }
            else if ($sig && $sig->getCode() == enigma_error::KEYNOTFOUND) {
                $attrib['class'] = 'enigmawarning';
                $msg = rcube::Q(str_replace('$keyid', enigma_key::format_id($sig->getData('id')),
                    $this->enigma->gettext('signokey')));
            }
            else {
                $attrib['class'] = 'enigmaerror';
                $msg = rcube::Q($this->enigma->gettext('sigerror'));
            }
/*
            $msg .= '&nbsp;' . html::a(array('href' => "#sigdetails",
                'onclick' => rcmail_output::JS_OBJECT_NAME.".command('enigma-sig-details')"),
                rcube::Q($this->enigma->gettext('showdetails')));
*/
            // test
//            $msg .= '<br /><pre>'.$sig->body.'</pre>';

            $p['prefix'] .= html::div($attrib, $msg);

            // Display each signature message only once
            unset($engine->signatures[$engine->signed_parts[$part_id]]);
        }

        if ($attach_scripts) {
            // add css and js script
            $this->add_css();
            $this->add_js();
        }

        return $p;
    }

    /**
     * Handler for message_load hook.
     * Check message bodies and attachments for keys/certs.
     */
    function message_load($p)
    {
        $engine = $this->enigma->load_engine();

        // handle keys/certs in attachments
        foreach ((array) $p['object']->attachments as $attachment) {
            if ($engine->is_keys_part($attachment)) {
                $this->keys_parts[] = $attachment->mime_id;
            }
        }

        // the same with message bodies
        foreach ((array) $p['object']->parts as $part) {
            if ($engine->is_keys_part($part)) {
                $this->keys_parts[]  = $part->mime_id;
                $this->keys_bodies[] = $part->mime_id;
            }
        }

        // @TODO: inline PGP keys

        if ($this->keys_parts) {
            $this->enigma->add_texts('localization');
        }

        return $p;
    }

    /**
     * Handler for template_object_messagebody hook.
     * This callback function adds a box below the message content
     * if there is a key/cert attachment available
     */
    function message_output($p)
    {
        foreach ($this->keys_parts as $part) {
            // remove part's body
            if (in_array($part, $this->keys_bodies)) {
                $p['content'] = '';
            }

            // add box below message body
            $p['content'] .= html::p(array('class' => 'enigmaattachment'),
                html::a(array(
                    'href'    => "#",
                    'onclick' => "return ".rcmail_output::JS_OBJECT_NAME.".enigma_import_attachment('".rcube::JQ($part)."')",
                    'title'   => $this->enigma->gettext('keyattimport')),
                    html::span(null, $this->enigma->gettext('keyattfound'))));

            $attach_scripts = true;
        }

        if ($attach_scripts) {
            // add css and js script
            $this->add_css();
            $this->add_js();
        }

        return $p;
    }

    /**
     * Handle message_ready hook (encryption/signing)
     */
    function message_ready($p)
    {
        $savedraft = !empty($_POST['_draft']) && empty($_GET['_saveonly']);

        if (!$savedraft && rcube_utils::get_input_value('_enigma_sign', rcube_utils::INPUT_POST)) {
            $this->enigma->load_engine();
            $status = $this->enigma->engine->sign_message($p['message']);
            $mode   = 'sign';
        }

        if ((!$status instanceof enigma_error) && rcube_utils::get_input_value('_enigma_encrypt', rcube_utils::INPUT_POST)) {
            $this->enigma->load_engine();
            $status = $this->enigma->engine->encrypt_message($p['message'], null, $savedraft);
            $mode   = 'encrypt';
        }

        if ($mode && ($status instanceof enigma_error)) {
            $code = $status->getCode();

            if ($code == enigma_error::KEYNOTFOUND) {
                $vars = array('email' => $status->getData('missing'));
                $msg  = 'enigma.' . $mode . 'nokey';
            }
            else if ($code == enigma_error::BADPASS) {
                $msg  = 'enigma.' . $mode . 'badpass';
                $type = 'warning';

                $this->password_prompt($status);
            }
            else {
                $msg = 'enigma.' . $mode . 'error';
            }

            $this->rc->output->show_message($msg, $type ?: 'error', $vars);
            $this->rc->output->send('iframe');
        }

        return $p;
    }

    /**
     * Handler for message_compose_body hook
     * Display error when the message cannot be encrypted
     * and provide a way to try again with a password.
     */
    function message_compose($p)
    {
        $engine = $this->enigma->load_engine();

        // skip: message has no signed/encoded content
        if (!$this->enigma->engine) {
            return $p;
        }

        $engine = $this->enigma->engine;

        // Decryption status
        foreach ($engine->decryptions as $status) {
            if ($status instanceof enigma_error) {
                $code = $status->getCode();

                if ($code == enigma_error::KEYNOTFOUND) {
                    $msg = rcube::Q(str_replace('$keyid', enigma_key::format_id($status->getData('id')),
                        $this->enigma->gettext('decryptnokey')));
                }
                else if ($code == enigma_error::BADPASS) {
                    $this->password_prompt($status, array('compose-init' => true));
                    return $p;
                }
                else {
                    $msg = rcube::Q($this->enigma->gettext('decrypterror'));
                }
            }
        }

        if ($msg) {
            $this->rc->output->show_message($msg, 'error');
        }

        return $p;
    }
}
