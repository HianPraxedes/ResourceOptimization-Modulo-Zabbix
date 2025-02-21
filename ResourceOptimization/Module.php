<?php
namespace Modules\ResourceOptimization;

use Zabbix\Core\CModule,
    APP,
    CMenuItem,
    CWebUser;

class Module extends CModule {

    public function init(): void {
        // Adiciona o menu para usuários com nível de privilégio igual ou superior a admin.
        if (isset(CWebUser::$data) && CWebUser::$data['type'] >= USER_TYPE_ZABBIX_ADMIN) {
            APP::Component()->get('menu.main')
                ->findOrAdd(_('Monitoring'))
                ->getSubmenu()
                ->insertAfter(_('Discovery'),
                    (new CMenuItem(_('Resource Optimization')))->setAction('resource.optimization')
                );
        }
    }
}
