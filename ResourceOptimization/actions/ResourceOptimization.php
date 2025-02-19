<?php
namespace Modules\ResourceOptimization\Actions;

use CController,
    CControllerResponseData;

class ResourceOptimization extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
        // Habilitar exibição de erros para debug (remova em produção)
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    protected function doAction(): void {

        // Se a ação for "getAllItems", retorna todos os items do host informado
        if (isset($_GET['action']) && $_GET['action'] === 'getAllItems') {
            $hostid = isset($_GET['hostid']) ? $_GET['hostid'] : null;
            if (!$hostid) {
                $data = ['error' => 'hostid não informado'];
                $this->setResponse(new CControllerResponseData($data));
                return;
            }
            // Busca todos os items do host
            $allItems = \API::Item()->get([
                'hostids' => $hostid,
                'output'  => 'extend'
            ]);
            $data = ['items' => $allItems];
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        // Lógica de Resource Optimization

        // Define o caminho do arquivo de cache e o tempo de expiração (em segundos)
        $cacheFile = '/tmp/resource_optimization_cache.json';
        $cacheTime = 28800; // (8 horas)

        // Se o cache existir e estiver válido, utiliza os dados do cache
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
            $data = json_decode(file_get_contents($cacheFile), true);
        } else {
            try {
                // Etapa 1: Buscar o grupo "SERVIDORES VIRTUAIS"
                $groupName = 'SERVIDORES VIRTUAIS';
                $groups = \API::HostGroup()->get([
                    'filter' => ['name' => $groupName]
                ]);
                if (empty($groups)) {
                    throw new \Exception("Grupo '$groupName' não encontrado.");
                }
                $groupId = $groups[0]['groupid'];

                // Etapa 2: Buscar os hosts do grupo
                $hosts = \API::Host()->get([
                    'groupids' => $groupId,
                    'output'   => ['hostid', 'host']
                ]);
                if (empty($hosts)) {
                    throw new \Exception("Nenhum host encontrado no grupo '$groupName'.");
                }
                $dataHosts = [];

                // Define o período desde o início do mês até agora
                $time_from = strtotime(date('Y-m-01 00:00:00'));
                $time_till = time();

                // Etapa 3: Para cada host, buscar os itens e coletar histórico, cálculos e recomendações
                foreach ($hosts as $host) {
                    $hostId = $host['hostid'];

                    // --- CPU ---
                    // Obter o item de CPU (uso atual - system.cpu.util)
                    $cpuItem = $this->getItemByKey($hostId, 'system.cpu.util');

                    // Obter o item que representa o número total de CPUs (system.cpu.num)
                    $cpuNumItem = $this->getItemByKey($hostId, 'system.cpu.num');
                    if (empty($cpuItem)) {
                        continue; // pula para o próximo $host
                    }
                    if (!empty($cpuNumItem)) {
                        $cpuAllocValue = floatval($cpuNumItem['lastvalue']);
                        $cpuAllocation = $cpuAllocValue . " vCPUs";
                    } else {
                        $cpuAllocation = 'N/A';
                        $cpuAllocValue = 0;
                    }

                    $cpuUsage = (!empty($cpuItem) && isset($cpuItem['lastvalue']))
                        ? number_format((float)$cpuItem['lastvalue'], 2, '.', '')
                        : 'N/A';

                    // Coleta do histórico para CPU (período desde o início do mês)
                    if (!empty($cpuItem) && isset($cpuItem['itemid'])) {
                        $cpuHistory = \API::history()->get([
                            'output'    => 'extend',
                            'history'   => 0,
                            'itemids'   => $cpuItem['itemid'],
                            'time_from' => $time_from,
                            'time_till' => $time_till,
                        ]);
                        $cpuTotal = 0;
                        $cpuCount = 0;
                        foreach ($cpuHistory as $h) {
                            $cpuTotal += floatval($h['value']);
                            $cpuCount++;
                        }
                        $cpuAvg = ($cpuCount > 0) ? $cpuTotal / $cpuCount : 'N/A';
                    } else {
                        $cpuAvg = 'N/A';
                    }

                    // --- Memory ---
                    // Tenta obter o item de memória percentual (vm.memory.size[pused])
                    $memItemPused = $this->getItemByKey($hostId, 'vm.memory.size[pused]');
                    if (!empty($memItemPused)) {
                        $memUsage = number_format((float)$memItemPused['lastvalue'], 2, '.', '');
                        $memItemId = $memItemPused['itemid'];
                    } else {
                        // Tenta obter o item de memory utilization (vm.memory.utilization)
                        $memItemUtil = $this->getItemByKey($hostId, 'vm.memory.utilization');
                        if (!empty($memItemUtil)) {
                            $memUsage = number_format((float)$memItemUtil['lastvalue'], 2, '.', '');
                            $memItemId = $memItemUtil['itemid'];
                        } else {
                            // Fallback: calcula a utilização com 'total' e 'available'
                            $memTotalForCalc = $this->getItemByKey($hostId, 'vm.memory.size[total]');
                            $memAvailableItem = $this->getItemByKey($hostId, 'vm.memory.size[available]');
                            if (!empty($memTotalForCalc) && !empty($memAvailableItem)) {
                                $totalMemCalc = floatval($memTotalForCalc['lastvalue']);
                                if ($totalMemCalc > 0) {
                                    $availableMemory = floatval($memAvailableItem['lastvalue']);
                                    $memUsage = number_format((1 - ($availableMemory / $totalMemCalc)) * 100, 2, '.', '');
                                    // Como fallback, usa o itemid do total (mas idealmente deseja o item de utilization)
                                    $memItemId = $memTotalForCalc['itemid'];
                                } else {
                                    $memUsage = 'N/A';
                                    $memItemId = null;
                                }
                            } else {
                                $memUsage = 'N/A';
                                $memItemId = null;
                            }
                        }
                    }

                    // Obter o item de memória total (vm.memory.size[total]) e converter para GB
                    $memTotalItem = $this->getItemByKey($hostId, 'vm.memory.size[total]');
                    if (!empty($memTotalItem)) {
                        $totalMemoryBytes = floatval($memTotalItem['lastvalue']);
                        $totalMemGB = ($totalMemoryBytes > 0)
                            ? $totalMemoryBytes / (1024 * 1024 * 1024)
                            : 0;
                        $memAllocation = ($totalMemGB > 0)
                            ? number_format($totalMemGB, 2, '.', '') . " GB"
                            : 'N/A';
                    } else {
                        $memAllocation = 'N/A';
                        $totalMemGB = 0;
                    }

                    // Coleta do histórico para Memory (usando o item de utilização ou fallback) desde o início do mês
                    if (!empty($memItemId)) {
                        $memHistory = \API::history()->get([
                            'output'    => 'extend',
                            'history'   => 0,
                            'itemids'   => $memItemId,
                            'time_from' => $time_from,
                            'time_till' => $time_till,
                        ]);
                        $memTotal = 0;
                        $memCount = 0;
                        foreach ($memHistory as $h) {
                            $memTotal += floatval($h['value']);
                            $memCount++;
                        }
                        $memAvg = ($memCount > 0) ? $memTotal / $memCount : $memUsage;
                    } else {
                        $memAvg = $memUsage;
                    }
                    
                    // Lógica de recomendação dinâmica para CPU (meta: ~50% de uso)
                    if ($cpuAvg !== 'N/A') {
                        $cpuAvgVal = floatval($cpuAvg);
                        if ($cpuAvgVal < 50) {
                            $newCpuAllocation = round($cpuAllocValue * ($cpuAvgVal / 50), 2);
                            $cpuArrow = "↓";
                            $changeAmount = round($cpuAllocValue - $newCpuAllocation, 2);
                        } elseif ($cpuAvgVal > 60) {
                            $newCpuAllocation = round($cpuAllocValue * ($cpuAvgVal / 50), 2);
                            $cpuArrow = "↑";
                            $changeAmount = round($newCpuAllocation - $cpuAllocValue, 2);
                        } else {
                            $newCpuAllocation = $cpuAllocValue;
                            $cpuArrow = "";
                            $changeAmount = 0;
                        }
                        $cpuRecommendation = "{$newCpuAllocation} vCPUs";
                        $cpuSavings = $cpuArrow . " " . $changeAmount . " vCPUs";
                    } else {
                        $cpuRecommendation = "No change";
                        $cpuSavings = "";
                    }
                    
                    // Lógica de recomendação dinâmica para Memory
                    if ($memAvg !== 'N/A') {
                        $memAvg = floatval($memAvg);
                        if ($memAvg > 60) {
                            $newMemAllocation = round(($totalMemGB * ($memAvg / 50)), 2);
                            $memArrow = "↑";
                            $changeMem = round($newMemAllocation - $totalMemGB, 2);
                        } elseif ($memAvg < 50) {
                            $newMemAllocation = round(($totalMemGB * ($memAvg / 50)), 2);
                            $memArrow = "↓";
                            $changeMem = round($totalMemGB - $newMemAllocation, 2);
                        } else {
                            $newMemAllocation = $totalMemGB;
                            $memArrow = "";
                            $changeMem = 0;
                        }
                        $memRecommendation = "{$newMemAllocation} GB";
                        $memSavings = $memArrow . " " . abs($changeMem) . " GB";
                    } else {
                        $memRecommendation = "No change";
                        $memSavings = "";
                    }

                    // Adiciona os dados de CPU ao array, utilizando o itemid obtido
                    $dataHosts[] = [
                        'host'               => $host['host'],
                        'resource'           => 'CPU',
                        'current_usage'      => ($cpuUsage !== 'N/A') ? $cpuUsage . '%' : 'N/A',
                        'current_allocation' => $cpuAllocation,
                        'recommendation'     => $cpuRecommendation,
                        'potential_savings'  => $cpuSavings,
                        'itemid'             => (!empty($cpuItem) && isset($cpuItem['itemid'])) ? $cpuItem['itemid'] : null,
                    ];

                    // Adiciona os dados de Memory ao array, utilizando o itemid (do item de utilização ou fallback)
                    $dataHosts[] = [
                        'host'               => $host['host'],
                        'resource'           => 'Memory',
                        'current_usage'      => ($memUsage !== 'N/A') ? $memUsage . '%' : 'N/A',
                        'current_allocation' => $memAllocation,
                        'recommendation'     => $memRecommendation,
                        'potential_savings'  => $memSavings,
                        'itemid'             => $memItemId,
                    ];
                }

                $data = ['hosts' => $dataHosts];

                // Atualiza o cache com os novos dados
                file_put_contents($cacheFile, json_encode($data));
            } catch (\Exception $e) {
                $data = ['error' => $e->getMessage()];
            }
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    /**
     * Função auxiliar para obter um item a partir da chave.
     *
     * @param int    $hostId
     * @param string $key
     * @return array|null Retorna o primeiro item encontrado ou null se não houver
     */
    private function getItemByKey($hostId, $key) {
        $items = \API::Item()->get([
            'hostids' => $hostId,
            'filter'  => ['key_' => $key],
            'output'  => ['itemid', 'lastvalue']
        ]);
        return !empty($items) ? $items[0] : null;
    }
}
?>
