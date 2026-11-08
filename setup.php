<?php

/**
 * Plugin "VMO" (Value Management Office) para GLPI 11 e 12.
 *
 * Gere iniciativas da proposta à comprovação de valor: portfólio, tarefas e dependências,
 * baseline imutável com controle de mudanças, custos, benefícios (previsto, declarado e validado),
 * riscos, indicadores, dashboard executivo e relatórios reproduzíveis.
 * Especificação: docs/Requisitos_Plugin_GLPI_VMO_v1.docx (VMO SRS 1.0).
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Menu;

define('PLUGIN_VMO_VERSION', '0.1.0');
define('PLUGIN_VMO_MIN_GLPI', '11.0.0');
define('PLUGIN_VMO_MAX_GLPI', '12.0.99');

function plugin_init_vmo(): void
{
    global $PLUGIN_HOOKS;

    // aba do plugin em Administração → Perfis
    Plugin::registerClass(GlpiPlugin\Vmo\ProfileTab::class, ['addtabon' => [Profile::class]]);

    Plugin::registerClass(Access::class);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Access::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname]);
        $_SESSION['glpiactiveprofile'][Access::$rightname] = (int) ($rights[Access::$rightname] ?? 0);
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['vmo']     = [Menu::SECTOR => [Access::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['vmo'] = 'plugin_vmo_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['vmo']        = ['css/vmo.css'];
}

/** Seção "VMO" no menu lateral, logo abaixo de CRM/RH/Financeiro/PMO quando existirem. */
function plugin_vmo_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'VMO',
        'icon'    => 'ti ti-target-arrow',
        'types'   => [Access::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    foreach (['crm', 'rh', 'financeiro', 'pmo', 'dashboard'] as $after) {
        if (isset($menu[$after])) {
            $out = [];
            foreach ($menu as $key => $value) {
                $out[$key] = $value;
                if ($key === $after) {
                    $out += $section;
                }
            }
            return $out;
        }
    }
    return $section + $menu;
}

function plugin_version_vmo(): array
{
    return [
        'name'         => 'VMO',
        'version'      => PLUGIN_VMO_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/vmo-glpi',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_VMO_MIN_GLPI, 'max' => PLUGIN_VMO_MAX_GLPI],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_vmo_check_prerequisites(): bool
{
    return true;
}

function plugin_vmo_check_config($verbose = false): bool
{
    return true;
}
