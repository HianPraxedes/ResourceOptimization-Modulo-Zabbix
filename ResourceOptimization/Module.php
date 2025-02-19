<?php
       
       namespace Modules\ResourceOptimization;
       
       use Zabbix\Core\CModule,
           APP,
           CMenuItem;
       
       class Module extends CModule {
       
           public function init(): void {
               APP::Component()->get('menu.main')
                   ->findOrAdd(_('Monitoring'))
                   ->getSubmenu()
                   ->insertAfter(_('Discovery'),
                       (new CMenuItem(_('Resource Optimization')))->setAction('resource.optimization')
                   );
           }
       }