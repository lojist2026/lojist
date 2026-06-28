document.addEventListener('contextmenu', (event) => {
  if (event.target.closest('.protected-image')) {
    event.preventDefault();
  }
});

document.addEventListener('dragstart', (event) => {
  if (event.target.closest('.protected-image')) {
    event.preventDefault();
  }
});

document.querySelectorAll('[data-pro-locked]').forEach((field) => {
  field.addEventListener('focus', () => {
    showUpgradeModal();
    field.blur();
  });
});

const autoCityForm = document.querySelector('[data-auto-city-filter]');
if (autoCityForm && navigator.geolocation && !new URLSearchParams(location.search).has('cidade')) {
  const knownCities = [
    { city: 'Campo Grande', state: 'MS', lat: -20.4697, lon: -54.6201 },
    { city: 'São Paulo', state: 'SP', lat: -23.5505, lon: -46.6333 },
    { city: 'Rio de Janeiro', state: 'RJ', lat: -22.9068, lon: -43.1729 },
    { city: 'Belo Horizonte', state: 'MG', lat: -19.9167, lon: -43.9345 },
    { city: 'Curitiba', state: 'PR', lat: -25.4284, lon: -49.2733 },
    { city: 'Porto Alegre', state: 'RS', lat: -30.0346, lon: -51.2177 }
  ];
  const key = 'lojist_device_city_applied';
  if (!sessionStorage.getItem(key)) {
    navigator.geolocation.getCurrentPosition((position) => {
      const distance = (a, b) => {
        const toRad = (v) => v * Math.PI / 180;
        const dLat = toRad(b.lat - a.lat);
        const dLon = toRad(b.lon - a.lon);
        const lat1 = toRad(a.lat);
        const lat2 = toRad(b.lat);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
      };
      const current = { lat: position.coords.latitude, lon: position.coords.longitude };
      const nearest = knownCities
        .map((city) => ({ ...city, km: distance(current, city) }))
        .sort((a, b) => a.km - b.km)[0];
      if (nearest && nearest.km <= 120) {
        const cityInput = autoCityForm.querySelector('[name="cidade"]');
        const stateInput = autoCityForm.querySelector('[name="estado"]');
        if (cityInput && stateInput && cityInput.value !== nearest.city) {
          cityInput.value = nearest.city;
          stateInput.value = nearest.state;
          sessionStorage.setItem(key, '1');
          autoCityForm.requestSubmit();
        }
      }
    }, () => sessionStorage.setItem(key, 'denied'), { timeout: 5000, maximumAge: 600000 });
  }
}

function showUpgradeModal() {
  const old = document.querySelector('.upgrade-modal');
  if (old) old.remove();
  const modal = document.createElement('div');
  modal.className = 'upgrade-modal';
  modal.innerHTML = `
    <div>
      <strong>Filtro premium</strong>
      <p>Este filtro está disponível nos planos Pro e Elite.</p>
      <button class="button small" type="button">Entendi</button>
    </div>
  `;
  Object.assign(modal.style, {
    position: 'fixed',
    inset: '0',
    zIndex: '40',
    display: 'grid',
    placeItems: 'center',
    background: 'rgba(3, 9, 18, .74)',
    padding: '18px'
  });
  Object.assign(modal.firstElementChild.style, {
    width: 'min(420px, 100%)',
    border: '1px solid rgba(0,194,255,.28)',
    borderRadius: '8px',
    padding: '22px',
    background: '#0d1a2c',
    color: '#fff',
    boxShadow: '0 24px 70px rgba(0,30,90,.35)'
  });
  modal.querySelector('button').addEventListener('click', () => modal.remove());
  modal.addEventListener('click', (event) => {
    if (event.target === modal) modal.remove();
  });
  document.body.appendChild(modal);
}

document.querySelectorAll('.clickable-card[data-href]').forEach((card) => {
  const open = () => {
    window.location.href = card.dataset.href;
  };
  card.addEventListener('click', (event) => {
    if (event.target.closest('button, a, input, select, label')) return;
    open();
  });
  card.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      open();
    }
  });
});

document.querySelectorAll('time.local-time[datetime]').forEach((node) => {
  const date = new Date(node.getAttribute('datetime'));
  if (Number.isNaN(date.getTime())) return;
  node.textContent = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short'
  }).format(date);
  node.title = 'Salvo no banco em horário de Campo Grande/MS';
});

const tipoProduto = document.querySelector('#tipoProduto');
if (tipoProduto) {
  const syncType = () => {
    document.querySelectorAll('.seminovo-fields').forEach((el) => {
      el.style.display = tipoProduto.value === 'seminovo' ? 'contents' : 'none';
    });
  };
  tipoProduto.addEventListener('change', syncType);
  syncType();
}

document.querySelectorAll('[data-parts-select]').forEach((select) => {
  const form = select.closest('form') || document;
  const syncParts = () => {
    const show = select.value === 'Sim';
    form.querySelectorAll('.parts-only').forEach((node) => {
      node.style.display = show ? '' : 'none';
      node.querySelectorAll('input').forEach((input) => {
        if (!show && input.type === 'checkbox') input.checked = false;
      });
    });
  };
  select.addEventListener('change', syncParts);
  syncParts();
});

document.querySelectorAll('[data-delivery-choice]').forEach((box) => {
  const toggle = box.querySelector('[data-alt-address-toggle]');
  const fields = box.querySelectorAll('[data-default-address-field]');
  const syncAddress = () => {
    const alt = Boolean(toggle?.checked);
    box.classList.toggle('using-alt-address', alt);
    fields.forEach((field) => {
      field.readOnly = !alt;
    });
  };
  toggle?.addEventListener('change', syncAddress);
  syncAddress();
});

setTimeout(() => {
  const flash = document.querySelector('.flash');
  if (flash) flash.style.display = 'none';
}, 6200);

document.querySelectorAll('[data-carousel]').forEach((carousel) => {
  const main = carousel.querySelector('[data-carousel-main]');
  const thumbs = Array.from(carousel.querySelectorAll('[data-carousel-thumb]'));
  if (!main || thumbs.length === 0) return;
  let current = 0;

  const show = (index) => {
    current = (index + thumbs.length) % thumbs.length;
    const src = thumbs[current].dataset.src;
    main.src = src;
    thumbs.forEach((thumb, i) => thumb.classList.toggle('active', i === current));
  };

  thumbs.forEach((thumb, index) => thumb.addEventListener('click', () => show(index)));
  carousel.querySelector('[data-carousel-prev]')?.addEventListener('click', () => show(current - 1));
  carousel.querySelector('[data-carousel-next]')?.addEventListener('click', () => show(current + 1));

  const openModal = () => {
    const modal = document.createElement('div');
    modal.className = 'photo-modal';
    modal.innerHTML = `<button type="button" aria-label="Fechar">×</button><img src="${main.src}" alt="Foto ampliada">`;
    modal.querySelector('button').addEventListener('click', () => modal.remove());
    modal.addEventListener('click', (event) => {
      if (event.target === modal) modal.remove();
    });
    document.body.appendChild(modal);
  };

  main.addEventListener('click', openModal);
  carousel.querySelector('[data-open-photo]')?.addEventListener('click', openModal);
});

const liveProductNodes = Array.from(document.querySelectorAll('[data-live-product-id]'));
if (liveProductNodes.length) {
  const ids = [...new Set(liveProductNodes.map((node) => node.dataset.liveProductId).filter(Boolean))];
  const refreshLiveState = async () => {
    try {
      const response = await fetch(`index.php?p=live-state&products=${encodeURIComponent(ids.join(','))}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      if (!response.ok) return;
      const data = await response.json();
      Object.entries(data.products || {}).forEach(([id, status]) => {
        document.querySelectorAll(`[data-live-product-id="${CSS.escape(id)}"]`).forEach((node) => {
          node.dataset.liveStatus = status;
          const unavailable = !['disponivel'].includes(status);
          node.classList.toggle('is-unavailable-live', unavailable);
          const buyBox = node.querySelector('.buy-box');
          if (buyBox && unavailable) {
            buyBox.innerHTML = '<div class="notice">Este aparelho acabou de ficar indisponível em outra compra ou reserva. A página foi atualizada ao vivo para proteger o estoque.</div>';
          }
        });
      });
    } catch (_) {
      // Mantem a tela atual se a conexao cair momentaneamente.
    }
  };
  refreshLiveState();
  setInterval(refreshLiveState, 12000);
}

document.addEventListener('DOMContentLoaded', () => {
    // Lojista Chart
    const lojistaCanvas = document.getElementById('lojistaChart');
    if (lojistaCanvas && typeof Chart !== 'undefined') {
        new Chart(lojistaCanvas, {
            type: 'line',
            data: {
                labels: ['1', '5', '10', '15', '20', '25', '30'],
                datasets: [{
                    label: 'Faturamento (R$)',
                    data: [1200, 3500, 2800, 5400, 4800, 7200, 8900],
                    borderColor: '#0066ff',
                    backgroundColor: 'rgba(0, 102, 255, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#2563eb',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#a1a1aa' } },
                    y: { grid: { color: 'rgba(255,255,255,0.05)' }, border: { dash: [4, 4] }, ticks: { color: '#a1a1aa' } }
                }
            }
        });
    }

    // Admin Chart
    const adminCanvas = document.getElementById('adminChart');
    if (adminCanvas && typeof Chart !== 'undefined') {
        new Chart(adminCanvas, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'],
                datasets: [
                    { label: 'GMV (R$)', data: [45000, 52000, 48000, 61000, 59000, 76000], backgroundColor: '#0066ff', borderRadius: 6 },
                    { label: 'Lucro (R$)', data: [450, 520, 480, 610, 590, 760], backgroundColor: '#10b981', borderRadius: 6 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#fafafa' } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#a1a1aa' } },
                    y: { grid: { color: 'rgba(255,255,255,0.05)' }, border: { dash: [4, 4] }, ticks: { color: '#a1a1aa' } }
                }
            }
        });
    }
});
