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

        // Define o caminho do arquivo de cache e o tempo de expiração (em segundos)
        $cacheFile = '/tmp/resource_optimization_cache.json';
        $cacheTime = 28800; // 10 segundos para testes (substitua por 28800 para 8 horas)

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

                // Prepara um array de hostids para a busca em lote
                $hostids = array_map(function($host) {
                    return $host['hostid'];
                }, $hosts);

                // Define as chaves dos itens que queremos buscar
                $keys = [
                    'system.cpu.util',
                    'system.cpu.num',
                    'vm.memory.size[pused]',
                    'vm.memory.utilization',
                    'vm.memory.size[total]',
                    'vm.memory.size[available]'
                ];

                // Busca todos os itens relevantes para os hosts de uma só vez
                $items = \API::Item()->get([
                    'hostids' => $hostids,
                    'filter'  => ['key_' => $keys],
                    'output'  => ['itemid', 'hostid', 'key_', 'lastvalue']
                ]);

                // Agrupa os itens por hostid e chave para facilitar a busca
                $groupedItems = [];
                foreach ($items as $item) {
                    $hostid = $item['hostid'];
                    $key = $item['key_'];
                    if (!isset($groupedItems[$hostid])) {
                        $groupedItems[$hostid] = [];
                    }
                    $groupedItems[$hostid][$key] = $item;
                }

                // Define o período dos últimos 30 dias
                $time_from = time() - (30 * 24 * 60 * 60);
                $time_till = time();

                // Arrays para coletar os itemids que serão usados para buscar dados de Trends
                $cpuTrendIds = [];
                $memTrendIds = [];
                $hostHistoryMapping = [];

                // Prepara a estrutura de dados para cada host (dados atuais e alocações)
                $dataHosts = [];
                foreach ($hosts as $host) {
                    $hostid = $host['hostid'];
                    $hostname = $host['host'];

                    // --- CPU ---
                    $cpuItem = isset($groupedItems[$hostid]['system.cpu.util']) ? $groupedItems[$hostid]['system.cpu.util'] : null;
                    $cpuNumItem = isset($groupedItems[$hostid]['system.cpu.num']) ? $groupedItems[$hostid]['system.cpu.num'] : null;
                    if (!$cpuItem) {
                        continue; // pula o host se não houver item de CPU
                    }
                    $cpuAllocValue = $cpuNumItem ? floatval($cpuNumItem['lastvalue']) : 0;
                    $cpuAllocation = $cpuNumItem ? $cpuAllocValue . " vCPUs" : 'N/A';
                    $cpuUsage = (isset($cpuItem['lastvalue']) && $cpuItem['lastvalue'] !== '') ? number_format((float)$cpuItem['lastvalue'], 2, '.', '') : 'N/A';

                    $cpuTrendId = isset($cpuItem['itemid']) ? $cpuItem['itemid'] : null;
                    if ($cpuTrendId) {
                        $cpuTrendIds[] = $cpuTrendId;
                        $hostHistoryMapping[$hostid]['cpu'] = $cpuTrendId;
                    }

                    // --- Memory ---
                    // Tenta obter o item de memória percentual (pused)
                    $memItemPused = isset($groupedItems[$hostid]['vm.memory.size[pused]']) ? $groupedItems[$hostid]['vm.memory.size[pused]'] : null;
                    if ($memItemPused) {
                        $memUsage = number_format((float)$memItemPused['lastvalue'], 2, '.', '');
                        $memItemId = $memItemPused['itemid'];
                    } else {
                        // Tenta obter o item de memory utilization
                        $memItemUtil = isset($groupedItems[$hostid]['vm.memory.utilization']) ? $groupedItems[$hostid]['vm.memory.utilization'] : null;
                        if ($memItemUtil) {
                            $memUsage = number_format((float)$memItemUtil['lastvalue'], 2, '.', '');
                            $memItemId = $memItemUtil['itemid'];
                        } else {
                            // Fallback: utiliza 'total' e 'available'
                            $memTotalForCalc = isset($groupedItems[$hostid]['vm.memory.size[total]']) ? $groupedItems[$hostid]['vm.memory.size[total]'] : null;
                            $memAvailableItem = isset($groupedItems[$hostid]['vm.memory.size[available]']) ? $groupedItems[$hostid]['vm.memory.size[available]'] : null;
                            if ($memTotalForCalc && $memAvailableItem) {
                                $totalMemCalc = floatval($memTotalForCalc['lastvalue']);
                                if ($totalMemCalc > 0) {
                                    $availableMemory = floatval($memAvailableItem['lastvalue']);
                                    $memUsageCalc = (1 - ($availableMemory / $totalMemCalc)) * 100;
                                    $memUsage = number_format($memUsageCalc, 2, '.', '');
                                    $memItemId = $memTotalForCalc['itemid']; // fallback para trends
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
                    // Obter a alocação total de memória (convertendo de bytes para GB)
                    $memTotalItem = isset($groupedItems[$hostid]['vm.memory.size[total]']) ? $groupedItems[$hostid]['vm.memory.size[total]'] : null;
                    if ($memTotalItem) {
                        $totalMemoryBytes = floatval($memTotalItem['lastvalue']);
                        $totalMemGB = $totalMemoryBytes > 0 ? $totalMemoryBytes / (1024 * 1024 * 1024) : 0;
                        $memAllocation = $totalMemGB > 0 ? number_format($totalMemGB, 2, '.', '') . " GB" : 'N/A';
                    } else {
                        $memAllocation = 'N/A';
                        $totalMemGB = 0;
                    }
                    if ($memItemId) {
                        $memTrendIds[] = $memItemId;
                        $hostHistoryMapping[$hostid]['mem'] = $memItemId;
                    }

                    $dataHosts[$hostid] = [
                        'host' => $hostname,
                        'resource_cpu' => [
                            'current_usage' => ($cpuUsage !== 'N/A') ? $cpuUsage : 'N/A',
                            'current_allocation' => $cpuAllocation,
                            'avg' => null, // a ser calculado
                            'recommendation' => null,
                            'potential_savings' => null,
                            'itemid' => $cpuTrendId,
                        ],
                        'resource_mem' => [
                            'current_usage' => ($memUsage !== 'N/A') ? $memUsage : 'N/A',
                            'current_allocation' => $memAllocation,
                            'avg' => null, // a ser calculado
                            'recommendation' => null,
                            'potential_savings' => null,
                            'itemid' => isset($hostHistoryMapping[$hostid]['mem']) ? $hostHistoryMapping[$hostid]['mem'] : null,
                        ],
                        'total_mem_gb' => $totalMemGB,
                        'cpu_alloc_value' => $cpuAllocValue,
                    ];
                }

                // --- Busca dos dados de Trends em lote ---

                // Trends de CPU
                $cpuTrendsRaw = [];
                if (!empty($cpuTrendIds)) {
                    $cpuTrendsRaw = \API::trend()->get([
                        'output'    => 'extend',
                        'itemids'   => array_unique($cpuTrendIds),
                        'time_from' => $time_from,
                        'time_till' => $time_till,
                    ]);
                }
                $cpuTrends = [];
                foreach ($cpuTrendsRaw as $entry) {
                    $itemid = $entry['itemid'];
                    if (!isset($cpuTrends[$itemid])) {
                        $cpuTrends[$itemid] = [];
                    }
                    $cpuTrends[$itemid][] = $entry;
                }

                // Trends de Memory
                $memTrendsRaw = [];
                if (!empty($memTrendIds)) {
                    $memTrendsRaw = \API::trend()->get([
                        'output'    => 'extend',
                        'itemids'   => array_unique($memTrendIds),
                        'time_from' => $time_from,
                        'time_till' => $time_till,
                    ]);
                }
                $memTrends = [];
                foreach ($memTrendsRaw as $entry) {
                    $itemid = $entry['itemid'];
                    if (!isset($memTrends[$itemid])) {
                        $memTrends[$itemid] = [];
                    }
                    $memTrends[$itemid][] = $entry;
                }

                // --- Cálculo de médias e recomendações ---
                $finalDataHosts = [];
                foreach ($dataHosts as $hostid => $hostData) {
                    // Média de CPU usando trends (valor médio: value_avg)
                    $cpuItemid = $hostData['resource_cpu']['itemid'];
                    $cpuAvg = 'N/A';
                    if ($cpuItemid && isset($cpuTrends[$cpuItemid])) {
                        $entries = $cpuTrends[$cpuItemid];
                        $cpuTotal = 0;
                        $cpuCount = 0;
                        foreach ($entries as $entry) {
                            $cpuTotal += floatval($entry['value_avg']);
                            $cpuCount++;
                        }
                        if ($cpuCount > 0) {
                            $cpuAvg = $cpuTotal / $cpuCount;
                        }
                    }
                    $hostData['resource_cpu']['avg'] = $cpuAvg;

                    // Média de Memory usando trends (valor médio: value_avg)
                    $memItemid = $hostData['resource_mem']['itemid'];
                    $memAvg = 'N/A';
                    if ($memItemid && isset($memTrends[$memItemid])) {
                        $entries = $memTrends[$memItemid];
                        $memTotal = 0;
                        $memCount = 0;
                        foreach ($entries as $entry) {
                            $memTotal += floatval($entry['value_avg']);
                            $memCount++;
                        }
                        if ($memCount > 0) {
                            $memAvg = $memTotal / $memCount;
                        } else {
                            $memAvg = $hostData['resource_mem']['current_usage'];
                        }
                    } else {
                        $memAvg = $hostData['resource_mem']['current_usage'];
                    }
                    $hostData['resource_mem']['avg'] = $memAvg;

                    // Recomendações para CPU com novos thresholds:
                    // Se uso < 70% → recomendação de redução
                    // Se uso > 95% → recomendação de aumento
                    // Caso contrário, nenhuma alteração (mantém o valor atual)
                    if ($cpuAvg !== 'N/A') {
                        $cpuAvgVal = floatval($cpuAvg);
                        if ($cpuAvgVal < 70) { // Uso baixo: recomendar diminuição
                            $newCpuAllocation = round($hostData['cpu_alloc_value'] * ($cpuAvgVal / 70), 2);
                            $cpuArrow = "↓";
                            $changeAmount = round($hostData['cpu_alloc_value'] - $newCpuAllocation, 2);
                        } elseif ($cpuAvgVal > 95) { // Uso alto: recomendar aumento
                            $newCpuAllocation = round($hostData['cpu_alloc_value'] * ($cpuAvgVal / 95), 2);
                            $cpuArrow = "↑";
                            $changeAmount = round($newCpuAllocation - $hostData['cpu_alloc_value'], 2);
                        } else {
                            $newCpuAllocation = $hostData['cpu_alloc_value'];
                            $cpuArrow = "";
                            $changeAmount = 0;
                        }
                        $cpuRecommendation = number_format($newCpuAllocation, 2, ',', '') . " vCPUs";
                        $cpuSavings = ($changeAmount != 0) ? $cpuArrow . " " . number_format(abs($changeAmount), 2, ',', '') . " vCPUs" : "No change";
                    } else {
                        $cpuRecommendation = "No change";
                        $cpuSavings = "";
                    }
                    $hostData['resource_cpu']['recommendation'] = $cpuRecommendation;
                    $hostData['resource_cpu']['potential_savings'] = $cpuSavings;

                    // Recomendações para Memory com novos thresholds:
                    // Se uso < 70% → recomendar diminuição
                    // Se uso > 95% → recomendar aumento
                    // Caso contrário, manter o valor atual
                    if ($memAvg !== 'N/A') {
                        $memAvgVal = floatval($memAvg);
                        $totalMemGB = $hostData['total_mem_gb'];
                        if ($memAvgVal < 70) { // Uso baixo: recomendar diminuição
                            $newMemAllocation = round($totalMemGB * ($memAvgVal / 70), 2);
                            $memArrow = "↓";
                            $changeMem = round($totalMemGB - $newMemAllocation, 2);
                        } elseif ($memAvgVal > 95) { // Uso alto: recomendar aumento
                            $newMemAllocation = round($totalMemGB * ($memAvgVal / 95), 2);
                            $memArrow = "↑";
                            $changeMem = round($newMemAllocation - $totalMemGB, 2);
                        } else {
                            $newMemAllocation = $totalMemGB;
                            $memArrow = "";
                            $changeMem = 0;
                        }
                        $memRecommendation = number_format($newMemAllocation, 2, ',', '') . " GB";
                        $memSavings = ($changeMem != 0) ? $memArrow . " " . number_format(abs($changeMem), 2, ',', '') . " GB" : "No change";
                    } else {
                        $memRecommendation = "No change";
                        $memSavings = "";
                    }
                    $hostData['resource_mem']['recommendation'] = $memRecommendation;
                    $hostData['resource_mem']['potential_savings'] = $memSavings;

                    $finalDataHosts[] = [
                        'host'               => $hostData['host'],
                        'resource'           => 'CPU',
                        'current_usage'      => ($hostData['resource_cpu']['current_usage'] !== 'N/A') ? $hostData['resource_cpu']['current_usage'] . '%' : 'N/A',
                        'current_allocation' => $hostData['resource_cpu']['current_allocation'],
                        'recommendation'     => $hostData['resource_cpu']['recommendation'],
                        'potential_savings'  => $hostData['resource_cpu']['potential_savings'],
                        'itemid'             => $hostData['resource_cpu']['itemid'],
                    ];
                    $finalDataHosts[] = [
                        'host'               => $hostData['host'],
                        'resource'           => 'Memory',
                        'current_usage'      => ($hostData['resource_mem']['current_usage'] !== 'N/A') ? $hostData['resource_mem']['current_usage'] . '%' : 'N/A',
                        'current_allocation' => $hostData['resource_mem']['current_allocation'],
                        'recommendation'     => $hostData['resource_mem']['recommendation'],
                        'potential_savings'  => $hostData['resource_mem']['potential_savings'],
                        'itemid'             => $hostData['resource_mem']['itemid'],
                    ];
                }

                $data = ['hosts' => $finalDataHosts];

                // Atualiza o cache com os novos dados
                file_put_contents($cacheFile, json_encode($data));
            } catch (\Exception $e) {
                $data = ['error' => $e->getMessage()];
            }
        }

        $this->setResponse(new CControllerResponseData($data));
    }
}
?>
