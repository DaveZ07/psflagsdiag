<?php
if (!defined('_PS_VERSION_')) { exit; }

class Psflagsdiag extends Module
{
    public function __construct()
    {
        $this->name = 'psflagsdiag';
        $this->tab = 'administration';
        $this->version = '1.3.9';
        $this->author = 'davez.ovh';
        $this->need_instance = 0;
        parent::__construct();
        $this->displayName = 'Product Flags Diagnostic';
        $this->description = 'Scans dynamic product flags from presenter (core+modules+hooks) and lists potential flags from theme/module CSS/TPL.';
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install() && $this->installTab();
    }

    public function uninstall()
    {
        return $this->uninstallTab() && parent::uninstall();
    }

    private function installTab()
    {
        $id_parent = (int)Tab::getIdFromClassName('AdminCatalog');
        if (!$id_parent) { $id_parent = 0; }

        $tab = new Tab();
        foreach (Language::getLanguages() as $lang) {
            $tab->name[$lang['id_lang']] = 'Flags Diagnostic';
        }
        $tab->class_name = 'AdminPsFlagsDiag';
        $tab->id_parent = $id_parent;
        $tab->module = $this->name;
        $tab->active = 1;
        return (bool)$tab->add();
    }

    private function uninstallTab()
    {
        $idTab = (int)Tab::getIdFromClassName('AdminPsFlagsDiag');
        if ($idTab) { $tab = new Tab($idTab); return (bool)$tab->delete(); }
        return true;
    }
}
