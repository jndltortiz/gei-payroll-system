  // Today's date
  const d = new Date();
  document.getElementById('todayDate').textContent = d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });

  // Sidebar collapse
  const sidebar = document.getElementById('sidebar');
  document.getElementById('collapseBtn').addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
  });

  // Attendance Chart
  document.addEventListener("DOMContentLoaded", function () {
    const ctx = document.getElementById('attendanceChart').getContext('2d');

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
            label: 'Present',
            data: presentData,
            backgroundColor: '#1db89a',
            borderRadius: 6
        },
        {
            label: 'Absent',
            data: absentData,
            backgroundColor: '#ef4444',
            borderRadius: 6
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { stacked: false, grid: { display: false }, border: { display: false } },
        y: {
          stacked: false,
          max: 160,
          grid: { color: '#f0f0f0' },
          border: { display: false },
          ticks: { stepSize: 40 }
        }
      }
    }
  });
});

  function renderPresence(filter) {
    const grid = document.getElementById('presenceGrid');
    const filtered = filter === 'all' ? employees : employees.filter(e => e.status === filter);
    grid.innerHTML = filtered.map(e => `
      <div class="presence-item">
        <span class="presence-name ${e.status}">${e.name}</span>
        <span class="pbadge ${e.status}">${e.status.charAt(0).toUpperCase() + e.status.slice(1)}</span>
      </div>
    `).join('');
  }

  function filterPresence(filter, btn) {
    document.querySelectorAll('.presence-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    renderPresence(filter);
  }

  renderPresence('all');
