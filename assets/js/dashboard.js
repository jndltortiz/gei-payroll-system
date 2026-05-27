// Today's date — set directly (script is at bottom, DOM is already ready)
const _dateEl = document.getElementById('todayDate');
if (_dateEl) {
    _dateEl.textContent = new Date().toLocaleDateString('en-PH',
        { month: 'long', day: 'numeric', year: 'numeric' });
}

// Attendance Chart — initialize directly, no DOMContentLoaded needed
(function initChart() {
    const canvas = document.getElementById('attendanceChart');
    if (!canvas) return;   // guard: page might not have chart
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded — chart will not render.');
        return;
    }
    const ctx = canvas.getContext('2d');

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
})();

  function filterPresence(filter, btn) {
    document.querySelectorAll('.presence-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');

    document.querySelectorAll('#presenceGrid .presence-item').forEach(item => {
      const status = item.dataset.status;
      item.style.display = (filter === 'all' || status === filter) ? '' : 'none';
    });
  }