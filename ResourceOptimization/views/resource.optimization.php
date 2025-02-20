<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Resource Usage</title>
  <style>
    /* Reset básico e definição de 100% para tela toda */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    html, body {
      width: 100%;
      height: 100%;
      background-color: #1c1c1c;
      font-family: Tahoma, Verdana, Arial, sans-serif;
      font-size: 13px;
      color: #ddd;
    }
    .container {
      width: 100%;
      background-color: #1c1c1c;
      height: 100%;
      padding: 20px;
    }
    #search-container, #filter-checkboxes {
      text-align: right;
      margin-bottom: 10px;
    }
    #search-input {
      padding: 4px 8px;
      font-size: 13px;
      border: 1px solid #555;
      border-radius: 3px;
      width: 220px;
      background-color: #333;
      color: #ddd;
    }
    #filter-checkboxes label {
      margin-left: 10px;
      cursor: pointer;
    }
    #filter-checkboxes input {
      margin-right: 4px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 15px;
    }
    thead {
      background-color: #444444;
      border-bottom: 1px solid #555;
    }
    thead th {
      padding: 6px 8px;
      text-align: left;
      font-weight: bold;
      border-right: 1px solid #555;
      color: #ddd;
    }
    thead th:last-child {
      border-right: none;
    }
    tbody td {
      padding: 6px 8px;
      border: 1px solid #333;
      border-top: none;
      border-right: 1px solid #333;
      color: #ddd;
    }
    tbody td:last-child {
      border-right: none;
    }
    tbody tr:nth-child(odd) {
      background-color: #2a2a2a;
    }
    tbody tr:nth-child(even) {
      background-color: #323232;
    }
    tbody tr:hover {
      background-color: #3a3a3a;
    }
    .pagination {
      text-align: center;
      margin-top: 10px;
    }
    .pagination button {
      padding: 1px 10px;
      font-size: 13px;
      color: #ddd;
      background-color: #444444;
      border: 1px solid #555;
      border-radius: 3px;
      cursor: pointer;
      margin: 0 5px;
    }
    .pagination button:hover {
      background-color: #555555;
    }
    .details-link {
      text-decoration: none;
      cursor: pointer;
    }
    .details-link:hover {
      text-decoration: underline;
      color: #ddd;
    }
  </style>
</head>
<body>
  <div class="container">
    <div id="search-container">
      <input type="text" id="search-input" placeholder="Search by hostname..." />
    </div>
    
    <!-- Checkboxes de filtro para CPU e Memória -->
    <div id="filter-checkboxes">
      <label><input type="checkbox" id="filter-cpu-checkbox" value="cpu" />CPU</label>
      <label><input type="checkbox" id="filter-memory-checkbox" value="memory" />Memory</label>
    </div>
    
    <table>
      <thead>
        <tr>
          <th>Host</th>
          <th>Resource</th>
          <th>Current Usage</th>
          <th>Current Allocation</th>
          <th>Recommendation</th>
          <th>Potential Savings</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody id="table-body">
        <!-- Conteúdo será inserido via JavaScript -->
      </tbody>
    </table>
    
    <div class="pagination">
      <button id="prev-button">Previous</button>
      Page <span id="current-page"></span> of <span id="total-pages"></span>
      <button id="next-button">Next</button>
    </div>
  </div>

  <script>
    // Dados dos hosts passados pelo PHP
    var hosts = <?php echo json_encode($data['hosts']); ?>;
    
    // Ordena os hosts alfabeticamente
    hosts.sort(function(a, b) {
      return a.host.localeCompare(b.host);
    });

    var filteredHosts = hosts.slice();
    var currentPage = 1;
    var rowsPerPage = 16;
    var totalPages = Math.ceil(filteredHosts.length / rowsPerPage);

    function renderTable(page) {
      var tbody = document.getElementById('table-body');
      tbody.innerHTML = '';
      var start = (page - 1) * rowsPerPage;
      var end = Math.min(start + rowsPerPage, filteredHosts.length);
      
      for (var i = start; i < end; i++) {
        var hostData = filteredHosts[i];
        var tr = document.createElement('tr');

        // Host
        var tdHost = document.createElement('td');
        tdHost.textContent = hostData.host;
        tr.appendChild(tdHost);

        // Resource
        var tdResource = document.createElement('td');
        tdResource.textContent = hostData.resource;
        tr.appendChild(tdResource);

        // Current Usage
        var tdUsage = document.createElement('td');
        tdUsage.textContent = hostData.current_usage;
        tr.appendChild(tdUsage);

        // Current Allocation
        var tdAllocation = document.createElement('td');
        tdAllocation.textContent = hostData.current_allocation;
        tr.appendChild(tdAllocation);

        // Recommendation
        var tdRec = document.createElement('td');
        tdRec.textContent = hostData.recommendation;
        tr.appendChild(tdRec);

        // Potential Savings
        var tdSavings = document.createElement('td');
        tdSavings.textContent = hostData.potential_savings;
        tr.appendChild(tdSavings);
        
        // Details
        var tdDetails = document.createElement('td');
        var detailsLink = document.createElement('a');
        detailsLink.textContent = "Details";
        detailsLink.target = "_blank";
        detailsLink.className = "details-link";

        var resourceItemid = hostData.itemid || "";
        var tabParam = "";
        if (hostData.resource.toLowerCase() === "cpu") {
          tabParam = "cpu";
        } else if (hostData.resource.toLowerCase() === "memory" || hostData.resource.toLowerCase() === "memória") {
          tabParam = "memory";
        }

        detailsLink.href = "/history.php?action=showgraph&tab=" + encodeURIComponent(tabParam) +
                            "&itemids%5B%5D=" + encodeURIComponent(resourceItemid);
        tdDetails.appendChild(detailsLink);
        tr.appendChild(tdDetails);

        tbody.appendChild(tr);
      }
      
      document.getElementById('current-page').textContent = page;
      document.getElementById('total-pages').textContent = totalPages;
    }

    function filterAll() {
      var searchValue = document.getElementById('search-input').value.toLowerCase();
      var cpuChecked = document.getElementById('filter-cpu-checkbox').checked;
      var memChecked = document.getElementById('filter-memory-checkbox').checked;

      filteredHosts = hosts.filter(function(host) {
        var hostNameMatch = host.host.toLowerCase().includes(searchValue);
        var resource = host.resource.toLowerCase();
        var resourceMatch = false;

        // Se nenhum checkbox estiver marcado, exibe todos
        if (!cpuChecked && !memChecked) {
          resourceMatch = true;
        } else {
          if (cpuChecked && resource === 'cpu') {
            resourceMatch = true;
          }
          if (memChecked && (resource === 'memory' || resource === 'memória')) {
            resourceMatch = true;
          }
        }
        return hostNameMatch && resourceMatch;
      });
      totalPages = Math.ceil(filteredHosts.length / rowsPerPage);
      currentPage = 1;
      renderTable(currentPage);
    }

    // Eventos para a busca e filtros
    document.getElementById('search-input').addEventListener('keyup', filterAll);
    document.getElementById('filter-cpu-checkbox').addEventListener('change', filterAll);
    document.getElementById('filter-memory-checkbox').addEventListener('change', filterAll);
    
    document.getElementById('prev-button').addEventListener('click', function() {
      if (currentPage > 1) {
        currentPage--;
        renderTable(currentPage);
      }
    });
    document.getElementById('next-button').addEventListener('click', function() {
      if (currentPage < totalPages) {
        currentPage++;
        renderTable(currentPage);
      }
    });

    // Renderiza a tabela inicialmente
    renderTable(currentPage);
  </script>
</body>
</html>
