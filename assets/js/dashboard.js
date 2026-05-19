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
          beginAtZero: true,
          grid: { color: '#f0f0f0' },
          border: { display: false },
          ticks: { precision: 0 }
        }
      }
    }
  });
});

  function filterPresence(filter, btn) {
    document.querySelectorAll('.presence-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');

    document.querySelectorAll('#presenceGrid .presence-item').forEach(item => {
      const status = item.dataset.status;
      item.style.display = (filter === 'all' || status === filter) ? '' : 'none';
    });
  }