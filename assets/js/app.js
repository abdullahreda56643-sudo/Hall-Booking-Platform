(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  function toast(message, type = 'error') {
    const root = $('#toast-root');
    if (!root) return;
    const notice = document.createElement('div');
    notice.className = `toast toast-${type}`;
    notice.setAttribute('role', 'status');
    notice.textContent = message;
    root.append(notice);
    window.setTimeout(() => notice.remove(), 6000);
  }

  function setupPlatformPreloader() {
    const preloader = $('[data-platform-preloader]');
    if (!preloader) return;

    const startedAt = performance.now();
    let dismissed = false;
    const dismiss = () => {
      if (dismissed) return;
      dismissed = true;
      preloader.classList.add('is-leaving');
      document.documentElement.classList.remove('preloader-enabled');
      const remove = () => preloader.remove();
      preloader.addEventListener('transitionend', remove, { once: true });
      window.setTimeout(remove, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 50 : 650);
    };
    const finishLoading = () => window.setTimeout(dismiss, Math.max(0, 3000 - (performance.now() - startedAt)));

    if (document.readyState === 'complete') finishLoading();
    else window.addEventListener('load', finishLoading, { once: true });
  }

  function setupNavigation() {
    const toggle = $('[data-menu-toggle]');
    const sidebar = $('[data-sidebar]');
    if (toggle && sidebar) {
      toggle.addEventListener('click', () => {
        const open = sidebar.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(open));
      });
      document.addEventListener('click', event => {
        if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
          sidebar.classList.remove('is-open');
          toggle.setAttribute('aria-expanded', 'false');
        }
      });
    }

    $$('form:not([data-report-form])').forEach(form => {
      form.addEventListener('submit', event => {
        const submitter = event.submitter;
        const message = submitter?.dataset.confirm || form.dataset.confirm;
        if (message && !window.confirm(message)) event.preventDefault();
      });
    });
  }

  function setupBookingSuccessDialog() {
    const backdrop = $('[data-booking-success-backdrop]');
    const dialog = $('[data-booking-success-dialog]');
    const closeButton = $('[data-booking-success-close]');
    if (!backdrop || !dialog) return;

    const close = () => {
      if (!backdrop.isConnected || backdrop.classList.contains('is-closing')) return;
      backdrop.classList.add('is-closing');
      window.setTimeout(() => backdrop.remove(), 190);
    };
    closeButton?.addEventListener('click', close);
    backdrop.addEventListener('click', event => {
      if (event.target === backdrop) close();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && backdrop.isConnected) close();
      if (event.key === 'Tab' && backdrop.isConnected) {
        event.preventDefault();
        closeButton?.focus();
      }
    });
    dialog.focus({ preventScroll: true });
  }

  function setupHomeDrawer() {
    const toggle = $('[data-home-drawer-toggle]');
    const drawer = $('[data-home-drawer]');
    const backdrop = $('[data-home-drawer-backdrop]');
    if (!toggle || !drawer || !backdrop) return;

    const close = () => {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'فتح القائمة');
      drawer.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      window.setTimeout(() => {
        if (!drawer.classList.contains('is-open')) drawer.inert = true;
      }, 230);
    };
    const open = () => {
      drawer.inert = false;
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-label', 'إغلاق القائمة');
      drawer.classList.add('is-open');
      backdrop.classList.add('is-open');
      const firstLink = $('a', drawer);
      firstLink?.focus({ preventScroll: true });
    };

    toggle.addEventListener('click', () => {
      toggle.getAttribute('aria-expanded') === 'true' ? close() : open();
    });
    backdrop.addEventListener('click', close);
    drawer.addEventListener('click', event => {
      if (event.target.closest('a')) close();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        close();
        toggle.focus({ preventScroll: true });
      }
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth > 700) close();
    });
  }

  function setupHomeAnimations() {
    const home = $('.home-public-body');
    if (!home) return;

    home.classList.add('motion-ready');
    const reveals = $$('.home-reveal, .home-hero-title');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion || !('IntersectionObserver' in window)) {
      reveals.forEach(element => element.classList.add('is-visible'));
      $('[data-home-steps]')?.classList.add('is-connected');
    } else {
      const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.15, rootMargin: '0px 0px -24px 0px' });
      reveals.forEach(element => revealObserver.observe(element));

      const steps = $('[data-home-steps]');
      if (steps) {
        const stepsObserver = new IntersectionObserver((entries, observer) => {
          if (!entries.some(entry => entry.isIntersecting)) return;
          steps.classList.add('is-connected');
          observer.disconnect();
        }, { threshold: 0.2 });
        stepsObserver.observe(steps);
      }
    }

    const counters = $$('[data-count]');
    const countUp = element => {
      const target = Number.parseInt(element.dataset.count || '0', 10);
      if (!Number.isFinite(target) || target <= 0) {
        element.textContent = '٠';
        return;
      }
      if (reduceMotion) {
        element.textContent = new Intl.NumberFormat('ar').format(target);
        return;
      }
      const started = performance.now();
      const duration = 900;
      const draw = now => {
        const progress = Math.min(1, (now - started) / duration);
        const eased = 1 - ((1 - progress) ** 3);
        element.textContent = new Intl.NumberFormat('ar').format(Math.round(target * eased));
        if (progress < 1) requestAnimationFrame(draw);
      };
      requestAnimationFrame(draw);
    };
    if (reduceMotion || !('IntersectionObserver' in window)) {
      counters.forEach(countUp);
      return;
    }
    const counterObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        countUp(entry.target);
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.55 });
    counters.forEach(element => {
      element.textContent = '٠';
      counterObserver.observe(element);
    });
  }

  function setupStaffDashboard() {
    const root = $('[data-staff-dashboard]');
    if (!root) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const reveals = $$('.dashboard-reveal', root);
    root.classList.add('dashboard-motion-ready');
    if (reduceMotion || !('IntersectionObserver' in window)) {
      reveals.forEach(element => element.classList.add('is-visible'));
    } else {
      const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -18px 0px' });
      reveals.forEach(element => revealObserver.observe(element));
    }

    const numberFormat = new Intl.NumberFormat('ar');
    const counters = $$('[data-dashboard-count]', root);
    const animateCounter = element => {
      const target = Number.parseInt(element.dataset.dashboardCount || '0', 10);
      if (!Number.isFinite(target) || target < 0) return;
      if (reduceMotion || target === 0) {
        element.textContent = numberFormat.format(target);
        return;
      }
      const started = performance.now();
      const duration = 850;
      const draw = now => {
        const progress = Math.min(1, (now - started) / duration);
        const eased = 1 - ((1 - progress) ** 3);
        element.textContent = numberFormat.format(Math.round(target * eased));
        if (progress < 1) requestAnimationFrame(draw);
      };
      requestAnimationFrame(draw);
    };
    if (reduceMotion || !('IntersectionObserver' in window)) {
      counters.forEach(animateCounter);
    } else {
      const counterObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          animateCounter(entry.target);
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.5 });
      counters.forEach(counter => {
        counter.textContent = '٠';
        counterObserver.observe(counter);
      });
    }

    if (!window.Chart) return;
    let chartData;
    try {
      chartData = JSON.parse(root.dataset.chartData || '{}');
    } catch (error) {
      console.error('تعذر قراءة بيانات رسوم لوحة التقارير.', error);
      return;
    }

    Chart.defaults.font.family = 'Tajawal, sans-serif';
    Chart.defaults.color = '#8792a4';
    const statusLabels = ['مسودات', 'مرسلة', 'معتمدة', 'مرفوضة'];
    const statusColors = ['#94a3b8', '#f59e0b', '#10b981', '#f43f5e'];
    const tooltip = {
      rtl: true,
      textDirection: 'rtl',
      titleFont: { family: 'Tajawal' },
      bodyFont: { family: 'Tajawal' },
      callbacks: { label: context => ` ${context.label}: ${numberFormat.format(context.parsed)}` },
    };
    const statuses = $('[data-staff-chart="statuses"]', root);
    if (statuses) {
      new Chart(statuses, {
        type: 'doughnut',
        data: {
          labels: statusLabels,
          datasets: [{ data: chartData.statuses || [0, 0, 0, 0], backgroundColor: statusColors, borderWidth: 0, hoverOffset: 5 }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '76%',
          animation: { duration: reduceMotion ? 0 : 950, easing: 'easeOutQuart' },
          plugins: { legend: { display: false }, tooltip },
        },
      });
    }

    const monthly = $('[data-staff-chart="monthly"]', root);
    if (monthly) {
      new Chart(monthly, {
        type: 'bar',
        data: {
          labels: chartData.months || [],
          datasets: [{
            label: 'التقارير',
            data: chartData.monthlyCounts || [],
            backgroundColor: context => {
              const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 220);
              gradient.addColorStop(0, '#8b7cf6');
              gradient.addColorStop(1, '#c4bafa');
              return gradient;
            },
            borderRadius: 8,
            borderSkipped: false,
            maxBarThickness: 34,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: reduceMotion ? 0 : 950, easing: 'easeOutQuart' },
          plugins: { legend: { display: false }, tooltip },
          scales: {
            x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 10 } } },
            y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 }, padding: 8 }, grid: { color: '#f1f3f7', drawTicks: false }, border: { display: false } },
          },
        },
      });
    }
  }

  function setupBookingDate() {
    const dateInput = $('[data-booking-date]');
    const daySelect = $('[data-booking-day]');
    if (!dateInput || !daySelect) return;
    const days = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
    dateInput.addEventListener('change', () => {
      if (!dateInput.value) return;
      const weekday = new Date(`${dateInput.value}T12:00:00`).getDay();
      daySelect.value = days[weekday] || '';
    });
  }

  function setupSchedule() {
    const schedule = $('[data-schedule-page]');
    if (!schedule) return;

    const search = $('[data-schedule-search]', schedule);
    const filter = $('[data-schedule-filter]', schedule);
    const slots = $$('[data-schedule-slot]', schedule);
    const tabs = $$('[data-schedule-tab]', schedule);
    const panels = $$('[data-schedule-panel]', schedule);
    const normalize = value => value.toLocaleLowerCase('ar')
      .normalize('NFD')
      .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
      .replace(/[أإآ]/g, 'ا')
      .replace(/ى/g, 'ي')
      .trim();

    const applySearch = () => {
      const query = normalize(search?.value || '');
      const field = filter?.value || 'all';
      slots.forEach(slot => {
        const searchable = field === 'teacher'
          ? slot.dataset.teacher || ''
          : field === 'class'
            ? slot.dataset.class || ''
            : `${slot.dataset.teacher || ''} ${slot.dataset.class || ''}`;
        const match = query !== '' && normalize(searchable).includes(query);
        slot.classList.toggle('is-search-dimmed', query !== '' && !match);
        slot.classList.toggle('is-search-match', match);
      });
      panels.forEach(panel => {
        const emptyMessage = $('[data-schedule-empty]', panel);
        const hasMatch = Boolean($('.schedule-cell.is-search-match', panel));
        if (emptyMessage) emptyMessage.hidden = query === '' || hasMatch;
      });
    };

    search?.addEventListener('input', applySearch);
    filter?.addEventListener('change', applySearch);

    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const selected = tab.dataset.scheduleTab;
        tabs.forEach(item => item.setAttribute('aria-selected', String(item === tab)));
        panels.forEach(panel => {
          panel.hidden = panel.dataset.schedulePanel !== selected;
        });
      });
    });

    $$('[data-week-direction]', schedule).forEach(link => {
      link.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        const target = new URL(link.href);
        target.searchParams.set('transition', link.dataset.weekDirection);
        schedule.classList.add('is-loading');
        window.setTimeout(() => window.location.assign(target.href), 140);
      });
    });
  }

  function setupUploads() {
    const input = $('[data-media-input]');
    const dropzone = $('[data-dropzone]');
    const previews = $('[data-preview-grid]');
    if (!input || !dropzone || !previews) return;
    const drawPreviews = () => {
      previews.replaceChildren();
      [...input.files].filter(file => file.type.startsWith('image/')).forEach(file => {
        const image = document.createElement('img');
        image.alt = file.name;
        image.src = URL.createObjectURL(file);
        image.onload = () => URL.revokeObjectURL(image.src);
        previews.append(image);
      });
      const count = input.files.length;
      const summary = document.createElement('span');
      summary.className = 'field-hint';
      summary.textContent = count ? `تم اختيار ${count} ملف` : '';
      previews.append(summary);
    };
    input.addEventListener('change', drawPreviews);
    ['dragenter', 'dragover'].forEach(name => dropzone.addEventListener(name, event => {
      event.preventDefault();
      dropzone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach(name => dropzone.addEventListener(name, event => {
      event.preventDefault();
      dropzone.classList.remove('dragover');
    }));
    dropzone.addEventListener('drop', event => {
      if (event.dataTransfer?.files.length) {
        input.files = event.dataTransfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  }

  function setupReportForm() {
    const form = $('[data-report-form]');
    if (!form || form.hasAttribute('data-report-editor')) return;
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const submitter = event.submitter;
      const confirmation = submitter?.dataset.confirm;
      if (confirmation && !window.confirm(confirmation)) return;

      const payload = new FormData(form);
      if (submitter?.name) payload.set(submitter.name, submitter.value);
      const progress = $('[data-progress]', form);
      const progressFill = $('span', progress);
      const submitButtons = $$('button[type="submit"]', form);
      submitButtons.forEach(button => { button.disabled = true; });
      if (progress) progress.classList.remove('hidden');
      const request = new XMLHttpRequest();
      request.open('POST', form.action);
      request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      request.upload.addEventListener('progress', event => {
        if (event.lengthComputable && progressFill) {
          progressFill.style.width = `${Math.round((event.loaded / event.total) * 100)}%`;
        }
      });
      request.addEventListener('load', () => {
        let result;
        try {
          result = JSON.parse(request.responseText);
        } catch {
          toast('تعذر حفظ التقرير. أعد تحميل الصفحة وحاول مرة أخرى.');
          submitButtons.forEach(button => { button.disabled = false; });
          return;
        }
        if (request.status >= 200 && request.status < 300 && result.ok && result.redirect) {
          window.location.assign(result.redirect);
          return;
        }
        toast(result.message || 'تعذر حفظ التقرير.');
        submitButtons.forEach(button => { button.disabled = false; });
        if (progress) progress.classList.add('hidden');
      });
      request.addEventListener('error', () => {
        toast('تعذر الاتصال بالخادم أثناء رفع الملفات.');
        submitButtons.forEach(button => { button.disabled = false; });
        if (progress) progress.classList.add('hidden');
      });
      request.send(payload);
    });
  }

  function setupCharts() {
    if (!window.Chart || !$('[data-chart]')) return;
    Chart.defaults.font.family = 'Tajawal, sans-serif';
    Chart.defaults.color = '#64748b';
    const chartInstances = new Map();
    const counterAnimations = new WeakMap();
    let chartRequest = 0;
    const purple = '#6366f1';
    const colors = ['#94a3b8', '#f59e0b', '#10b981', '#f43f5e'];
    const numberFormat = new Intl.NumberFormat('ar');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const labelsForDates = labels => labels.map(value => {
      const date = new Date(`${value}T12:00:00`);
      return new Intl.DateTimeFormat('ar', { day: 'numeric', month: 'short' }).format(date);
    });
    const tooltip = { rtl: true, textDirection: 'rtl', titleFont: { family: 'Tajawal' }, bodyFont: { family: 'Tajawal' } };

    function showNoData(canvas, chart) {
      if (chart.data.datasets.every(dataset => dataset.data.every(value => Number(value?.count ?? value) === 0))) {
        const context = canvas.getContext('2d');
        context.save();
        context.font = '13px Tajawal';
        context.fillStyle = '#94a3b8';
        context.textAlign = 'center';
        context.fillText('لا توجد بيانات في هذه الفترة', canvas.width / 2, canvas.height / 2);
        context.restore();
      }
    }

    function draw(name, config) {
      const canvas = $(`[data-chart="${name}"]`);
      if (!canvas) return;
      chartInstances.get(name)?.destroy();
      const chart = new Chart(canvas, {
        ...config,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: reduceMotion ? 0 : 1000, easing: 'easeOutQuart' },
          plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 17 } }, tooltip, ...(config.options?.plugins || {}) },
          scales: config.type === 'doughnut' ? undefined : {
            x: { grid: { display: false } },
            y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } },
            ...(config.options?.scales || {}),
          },
          ...config.options,
        },
        plugins: [...(config.plugins || []), { id: `empty-${name}`, afterDraw: instance => showNoData(canvas, instance) }],
      });
      chartInstances.set(name, chart);
    }

    function animateValue(element, value) {
      const target = Number(value);
      if (!Number.isFinite(target)) return;
      const animation = counterAnimations.get(element);
      if (animation) cancelAnimationFrame(animation);
      const start = Number(element.dataset.currentValue || 0);
      element.dataset.currentValue = String(target);
      if (reduceMotion) {
        element.textContent = numberFormat.format(target);
        return;
      }
      const started = performance.now();
      const drawFrame = now => {
        const progress = Math.min(1, (now - started) / 850);
        const eased = 1 - ((1 - progress) ** 3);
        element.textContent = numberFormat.format(Math.round(start + ((target - start) * eased)));
        if (progress < 1) counterAnimations.set(element, requestAnimationFrame(drawFrame));
        else counterAnimations.delete(element);
      };
      counterAnimations.set(element, requestAnimationFrame(drawFrame));
    }

    function renderSparkline(element, values, key) {
      const series = values.map(Number);
      const width = 82;
      const height = 27;
      const padding = 3;
      const minimum = Math.min(...series);
      const spread = Math.max(...series) - minimum || 1;
      const points = series.map((value, index) => {
        const x = padding + (index / Math.max(1, series.length - 1)) * (width - padding * 2);
        const y = height - padding - ((value - minimum) / spread) * (height - padding * 2);
        return `${x},${y}`;
      });
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
      svg.setAttribute('focusable', 'false');
      const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
      line.setAttribute('points', points.join(' '));
      line.setAttribute('fill', 'none');
      line.setAttribute('stroke', key === 'submitted' || key === 'rejected' ? '#f59e0b' : '#6366f1');
      line.setAttribute('stroke-width', '2');
      line.setAttribute('stroke-linecap', 'round');
      line.setAttribute('stroke-linejoin', 'round');
      svg.append(line);
      element.replaceChildren(svg);
    }

    function renderStatusLegend(distribution) {
      const root = $('[data-status-legend]');
      if (!root) return;
      root.replaceChildren();
      distribution.labels.forEach((label, index) => {
        const item = document.createElement('span');
        item.className = 'dashboard-legend-item';
        const marker = document.createElement('i');
        marker.style.backgroundColor = colors[index];
        const text = document.createElement('span');
        text.textContent = label;
        const value = document.createElement('strong');
        value.textContent = numberFormat.format(distribution.values[index]);
        item.append(marker, text, value);
        root.append(item);
      });
    }

    async function loadCharts() {
      const rangeControl = $('[data-chart-range]');
      const range = rangeControl?.tagName === 'SELECT'
        ? rangeControl.value
        : $('[data-chart-range][aria-pressed="true"]')?.dataset.chartRange || '30d';
      const parameters = new URLSearchParams({ range });
      if (range === 'custom') {
        const from = $('[data-custom-from]')?.value || '';
        const to = $('[data-custom-to]')?.value || '';
        if (!from || !to) {
          toast('حددي تاريخ البداية والنهاية للفترة المخصصة.');
          return;
        }
        parameters.set('from', from);
        parameters.set('to', to);
      }
      const requestId = ++chartRequest;
      const canvases = $$('[data-chart]');
      canvases.forEach(canvas => canvas.parentElement?.classList.add('chart-loading'));
      try {
        const response = await fetch(`${window.location.pathname.replace(/(?:admin\/)?(?:dashboard|statistics)\.php$/, '')}api/charts.php?${parameters}`, { credentials: 'same-origin' });
        const responseText = await response.text();
        let data = null;
        try {
          data = responseText ? JSON.parse(responseText) : null;
        } catch {
          data = null;
        }
        if (requestId !== chartRequest) return;
        if (!response.ok) {
          const diagnostic = typeof data?.diagnostic === 'string' ? ` — ${data.diagnostic}` : '';
          throw new Error(`${data?.error || `تعذر تحميل الإحصائيات (رمز الاستجابة ${response.status}).`}${diagnostic}`);
        }
        if (!data || typeof data !== 'object') throw new Error('وصلت استجابة غير صالحة من الخادم.');
        $$('[data-chart-value]').forEach(element => {
          const key = element.dataset.chartValue;
          if (Object.hasOwn(data.summary, key)) animateValue(element, data.summary[key]);
        });
        $$('[data-chart-change]').forEach(element => {
          const key = element.dataset.chartChange;
          if (!Object.hasOwn(data.changes, key)) return;
          const change = Number(data.changes[key]);
          const positive = key === 'submitted' || key === 'rejected' ? change < 0 : change > 0;
          const negative = key === 'submitted' || key === 'rejected' ? change > 0 : change < 0;
          element.classList.toggle('is-positive', positive);
          element.classList.toggle('is-negative', negative);
          element.classList.toggle('is-neutral', change === 0);
          const arrow = document.createElement('span');
          arrow.setAttribute('aria-hidden', 'true');
          arrow.textContent = change === 0 ? '−' : change > 0 ? '↑' : '↓';
          element.replaceChildren(arrow, document.createTextNode(` ${numberFormat.format(Math.abs(change))}٪`));
        });
        $$('[data-chart-sparkline]').forEach(element => {
          const key = element.dataset.chartSparkline;
          if (Array.isArray(data.sparklines[key]) && data.sparklines[key].length > 0) {
            renderSparkline(element, data.sparklines[key], key);
          }
        });
        const periodLabel = $('[data-period-label]');
        if (periodLabel) periodLabel.textContent = `${data.period.from} — ${data.period.to}`;
        draw('sent', {
          type: 'line',
          data: { labels: labelsForDates(data.sent_over_time.labels), datasets: [{ label: 'التقارير المرسلة', data: data.sent_over_time.values, borderColor: purple, backgroundColor: context => {
            const { chart } = context;
            if (!chart.chartArea) return 'rgba(99,102,241,.12)';
            const gradient = chart.ctx.createLinearGradient(0, chart.chartArea.top, 0, chart.chartArea.bottom);
            gradient.addColorStop(0, 'rgba(99,102,241,.27)');
            gradient.addColorStop(1, 'rgba(99,102,241,.015)');
            return gradient;
          }, fill: true, tension: .4, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2.5 }] },
          options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } } } },
        });
        draw('status', {
          type: 'doughnut',
          data: { labels: data.status_distribution.labels, datasets: [{ data: data.status_distribution.values, backgroundColor: colors, borderWidth: 3, borderColor: '#fff', hoverOffset: 6 }] },
          options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { ...tooltip, callbacks: { label: item => `${item.label}: ${numberFormat.format(item.raw)}` } } } },
          plugins: [{
            id: 'dashboard-doughnut-center',
            afterDraw(chart) {
              const { ctx, chartArea } = chart;
              if (!chartArea) return;
              const total = data.status_distribution.values.reduce((sum, value) => sum + Number(value), 0);
              const x = (chartArea.left + chartArea.right) / 2;
              const y = (chartArea.top + chartArea.bottom) / 2;
              ctx.save();
              ctx.textAlign = 'center';
              ctx.direction = 'rtl';
              ctx.fillStyle = '#94a3b8';
              ctx.font = '12px Tajawal';
              ctx.fillText('إجمالي التقارير', x, y - 5);
              ctx.fillStyle = '#172554';
              ctx.font = '800 21px Tajawal';
              ctx.fillText(numberFormat.format(total), x, y + 21);
              ctx.restore();
            },
          }],
        });
        renderStatusLegend(data.status_distribution);
        draw('users', {
          type: 'bar',
          data: { labels: data.reports_by_user.labels, datasets: [{ label: 'عدد التقارير', data: data.reports_by_user.values, backgroundColor: 'rgba(124,58,237,.78)', borderRadius: { topLeft: 7, topRight: 7 }, borderSkipped: 'bottom', maxBarThickness: 42 }] },
          options: { scales: { x: { grid: { display: false }, ticks: { maxRotation: 35, minRotation: 15 } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } } }, plugins: { tooltip: { ...tooltip, callbacks: { label: item => `عدد التقارير: ${numberFormat.format(item.raw)}` } } } },
        });
        draw('roles', {
          type: 'bar',
          data: { labels: data.role_comparison.labels, datasets: data.role_comparison.statuses.map((label, index) => ({
            label,
            data: data.role_comparison.values.map(row => row[Object.keys(row)[index]]),
            backgroundColor: colors[index],
            borderRadius: 4,
          })) },
          options: { scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } } } },
        });
        const heatValues = data.booking_heatmap.values;
        const heatMax = Math.max(1, ...heatValues.flat().map(Number));
        const heatPoints = heatValues.flatMap((row, dayIndex) => row.map((count, periodIndex) => ({
          x: periodIndex + 1,
          y: dayIndex,
          count: Number(count),
        })));
        draw('bookings', {
          type: 'scatter',
          data: { datasets: [{ label: 'الحجوزات', data: heatPoints, pointStyle: 'rectRounded', pointRadius: context => Math.max(7, Math.min(15, context.chart.width / 24, context.chart.height / 13)), pointHoverRadius: 16, borderWidth: 1, borderColor: '#fff', backgroundColor: context => {
            const intensity = context.raw?.count ? .12 + (context.raw.count / heatMax) * .78 : .045;
            return `rgba(99,102,241,${intensity})`;
          } }] },
          options: {
            layout: { padding: { top: 3, bottom: 3, left: 4, right: 4 } },
            scales: {
              x: { type: 'linear', min: .5, max: 8.5, grid: { display: false }, ticks: { stepSize: 1, callback: value => numberFormat.format(value) } },
              y: { type: 'linear', min: -.5, max: 4.5, reverse: true, grid: { display: false }, ticks: { stepSize: 1, callback: value => data.booking_heatmap.labels[value] || '' } },
            },
            plugins: { legend: { display: false }, tooltip: { ...tooltip, callbacks: {
              title: items => {
                const point = items[0]?.raw;
                return point ? `${data.booking_heatmap.labels[point.y]} · ${data.booking_heatmap.periods[point.x - 1]}` : '';
              },
              label: item => `الحجوزات: ${numberFormat.format(item.raw.count)}`,
            } } },
          },
        });
        draw('approval', {
          type: 'line',
          data: { labels: data.monthly_approval.labels, datasets: [{ label: 'نسبة الاعتماد', data: data.monthly_approval.values, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.09)', fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2.5 }] },
          options: { scales: { y: { min: 0, max: 100, ticks: { callback: value => `${numberFormat.format(value)}٪` }, grid: { color: '#f1f5f9' } } }, plugins: { tooltip: { ...tooltip, callbacks: { label: item => `نسبة الاعتماد: ${numberFormat.format(item.raw)}٪` } } } },
        });
      } catch (error) {
        if (requestId !== chartRequest) return;
        toast(error.message || 'تعذر تحميل الرسوم البيانية.');
      } finally {
        if (requestId === chartRequest) {
          canvases.forEach(canvas => canvas.parentElement?.classList.remove('chart-loading'));
          $$('.dashboard-kpi-value.is-loading').forEach(element => element.classList.remove('is-loading'));
        }
      }
    }

    const rangeSelect = $('[data-chart-range]');
    if (rangeSelect?.tagName === 'SELECT') {
      rangeSelect.addEventListener('change', () => {
        $$('.custom-date').forEach(field => field.classList.toggle('hidden', rangeSelect.value !== 'custom'));
        if (rangeSelect.value !== 'custom') loadCharts();
      });
    }
    $$('[data-chart-range][aria-pressed]').forEach(button => {
      button.addEventListener('click', () => {
        $$('[data-chart-range][aria-pressed]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        $$('.dashboard-custom-date, .dashboard-apply-filter').forEach(field => field.classList.toggle('hidden', button.dataset.chartRange !== 'custom'));
        if (button.dataset.chartRange !== 'custom') loadCharts();
      });
    });
    $('[data-chart-filter]')?.addEventListener('submit', event => {
      event.preventDefault();
      loadCharts();
    });
    loadCharts();
  }

  function setupDashboardRejectDialog() {
    const dialog = $('[data-dashboard-reject-dialog]');
    const idInput = $('[data-dashboard-reject-id]', dialog || document);
    const noteInput = $('#dashboard-reject-note', dialog || document);
    if (!dialog || !idInput || !noteInput) return;
    $$('[data-dashboard-reject-trigger]').forEach(button => {
      button.addEventListener('click', () => {
        idInput.value = button.dataset.dashboardRejectTrigger || '';
        noteInput.value = '';
        dialog.showModal();
        noteInput.focus();
      });
    });
    $('[data-dashboard-reject-close]', dialog)?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
      if (event.target === dialog) dialog.close();
    });
  }

  function setupReportsPage() {
    const root = $('[data-reports-page]');
    if (!root) return;

    const selectedCheckboxes = $$('[data-report-select]', root);
    const selectAll = $('[data-reports-select-all]', root);
    const bulkBar = $('[data-reports-bulk-bar]', root);
    const selectedCount = $('[data-reports-selected-count]', root);
    const exportMenu = $('.reports-export-menu', root);
    const exportLinks = $$('[data-report-export]', root);
    const bulkExport = $('[data-reports-bulk-export]', root);
    const selectedIds = () => selectedCheckboxes.filter(input => input.checked).map(input => input.value);
    const updateSelection = () => {
      const ids = selectedIds();
      if (selectedCount) selectedCount.textContent = new Intl.NumberFormat('ar').format(ids.length);
      bulkBar?.classList.toggle('is-visible', ids.length > 0);
      bulkBar?.setAttribute('aria-hidden', String(ids.length === 0));
      if (selectAll) {
        selectAll.checked = ids.length > 0 && ids.length === selectedCheckboxes.length;
        selectAll.indeterminate = ids.length > 0 && ids.length < selectedCheckboxes.length;
      }
      exportLinks.forEach(link => {
        const url = new URL(link.href);
        url.searchParams.delete('selected[]');
        ids.forEach(id => url.searchParams.append('selected[]', id));
        link.href = url.toString();
      });
    };
    selectedCheckboxes.forEach(input => input.addEventListener('change', updateSelection));
    selectAll?.addEventListener('change', () => {
      selectedCheckboxes.forEach(input => { input.checked = selectAll.checked; });
      updateSelection();
    });
    $('[data-reports-clear]', root)?.addEventListener('click', () => {
      selectedCheckboxes.forEach(input => { input.checked = false; });
      if (selectAll) selectAll.checked = false;
      updateSelection();
    });
    bulkExport?.addEventListener('click', () => {
      if (!selectedIds().length) return;
      if (exportMenu) exportMenu.open = true;
      $('.reports-export-menu summary', root)?.focus();
    });
    root.addEventListener('click', event => {
      if (!event.target.closest('.reports-export-menu')) exportMenu?.removeAttribute('open');
    });

    const rejectDialog = $('[data-reports-reject-dialog]', root);
    const rejectId = $('input[name="report_id"]', rejectDialog || root);
    const rejectNote = $('#reports-reject-note', rejectDialog || root);
    $$('[data-reports-reject]', root).forEach(button => {
      button.addEventListener('click', () => {
        if (!rejectDialog || !rejectId || !rejectNote) return;
        rejectId.value = button.dataset.reportsReject || '';
        rejectNote.value = '';
        rejectDialog.showModal();
        rejectNote.focus();
      });
    });
    $('[data-reports-reject-close]', rejectDialog || root)?.addEventListener('click', () => rejectDialog?.close());
    rejectDialog?.addEventListener('click', event => {
      if (event.target === rejectDialog) rejectDialog.close();
    });

    exportLinks.forEach(link => {
      link.addEventListener('click', async event => {
        event.preventDefault();
        if (link.dataset.loading === 'true') return;
        const label = $('span', link);
        const originalLabel = label?.textContent || '';
        link.dataset.loading = 'true';
        link.setAttribute('aria-disabled', 'true');
        if (label) label.textContent = 'جارٍ تجهيز التنزيل…';
        link.classList.add('is-loading');
        try {
          const response = await fetch(link.href, { credentials: 'same-origin' });
          if (!response.ok) {
            const message = (await response.text()).trim().slice(0, 180);
            throw new Error(message || 'تعذر تصدير التقارير.');
          }
          const file = await response.blob();
          if (file.size === 0) throw new Error('ملف التصدير فارغ.');
          const downloadUrl = URL.createObjectURL(file);
          const download = document.createElement('a');
          download.href = downloadUrl;
          download.download = link.dataset.filename || 'reports-export';
          document.body.append(download);
          download.click();
          download.remove();
          window.setTimeout(() => URL.revokeObjectURL(downloadUrl), 1000);
          toast('اكتمل تنزيل ملف التقارير.', 'success');
        } catch (error) {
          toast(error.message || 'تعذر تصدير التقارير.');
        } finally {
          link.dataset.loading = 'false';
          link.removeAttribute('aria-disabled');
          link.classList.remove('is-loading');
          if (label) label.textContent = originalLabel;
        }
      });
    });
    updateSelection();
  }

  function setupUserReportsPage() {
    const root = $('[data-user-reports]');
    if (!root) return;

    const tabs = $('.user-reports-tabs', root);
    const activeTabIndex = Number.parseInt(tabs?.style.getPropertyValue('--active-index') || '0', 10);
    if (tabs && activeTabIndex > 0 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      tabs.style.setProperty('--active-index', '0');
      window.requestAnimationFrame(() => tabs.style.setProperty('--active-index', String(activeTabIndex)));
    }

    const dialog = $('[data-report-delete-dialog]');
    const confirmButton = $('[data-report-delete-confirm]', dialog || root);
    const cancelButton = $('[data-report-delete-cancel]', dialog || root);
    let pendingForm = null;

    $$('[data-report-delete]', root).forEach(form => {
      form.addEventListener('submit', event => {
        event.preventDefault();
        pendingForm = form;
        if (dialog?.showModal) {
          dialog.showModal();
          confirmButton?.focus();
        } else if (window.confirm('سيتم حذف التقرير ومرفقاته نهائيًا، ولا يمكن التراجع عن هذا الإجراء.')) {
          submitAfterFade(form);
        } else {
          pendingForm = null;
        }
      });
    });

    function submitAfterFade(form) {
      const card = form.closest('[data-report-card]');
      card?.classList.add('is-removing');
      if (confirmButton) confirmButton.disabled = true;
      window.setTimeout(() => form.submit(), window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 260);
    }

    confirmButton?.addEventListener('click', () => {
      if (!pendingForm) return;
      const form = pendingForm;
      pendingForm = null;
      dialog.close();
      submitAfterFade(form);
    });
    cancelButton?.addEventListener('click', () => {
      pendingForm = null;
      dialog?.close();
    });
    dialog?.addEventListener('close', () => {
      pendingForm = null;
      if (confirmButton) confirmButton.disabled = false;
    });
    dialog?.addEventListener('click', event => {
      if (event.target === dialog) dialog.close();
    });
  }

  function setupUserManagement() {
    const root = $('[data-users-page]');
    if (!root) return;

    const rolePermissionForm = $('.role-permissions-form', root);
    $$('input[type="checkbox"][name^="permissions["]', rolePermissionForm || root).forEach(input => {
      input.addEventListener('change', () => {
        const state = input.parentElement.querySelector('small');
        if (state) state.textContent = input.checked ? 'مسموحة' : 'موقوفة';
      });
    });

    const filterForm = $('[data-users-filter]', root);
    const search = $('[data-user-search]', root);
    let searchTimer;
    search?.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => filterForm?.requestSubmit(), 280);
    });
    $$('[data-auto-submit]', filterForm || root).forEach(control => {
      control.addEventListener('change', () => filterForm?.requestSubmit());
    });

    const drawer = $('[data-user-drawer]', root);
    const backdrop = $('[data-user-drawer-backdrop]', root);
    const setDrawer = open => {
      drawer?.classList.toggle('is-open', open);
      backdrop?.classList.toggle('is-open', open);
      drawer?.setAttribute('aria-hidden', String(!open));
      if (drawer) drawer.inert = !open;
      if (open) $('#user-name', drawer)?.focus({ preventScroll: true });
    };
    $$('[data-user-drawer-open]', root).forEach(button => button.addEventListener('click', () => setDrawer(true)));
    $$('[data-user-drawer-close]', root).forEach(button => button.addEventListener('click', () => setDrawer(false)));
    backdrop?.addEventListener('click', () => setDrawer(false));

    const editor = $('[data-user-editor]', root);
    if (editor) {
      const name = $('#user-name', editor);
      const username = $('#user-username', editor);
      const role = $('#user-role', editor);
      const password = $('#user-password', editor);
      const setError = (input, key, message) => {
        const output = $(`[data-field-error="${key}"]`, editor);
        if (output) output.textContent = message;
        input?.setAttribute('aria-invalid', String(Boolean(message)));
      };
      const validateName = () => {
        const value = name.value.trim();
        setError(name, 'name', value.length === 0 ? 'الاسم مطلوب.' : [...value].length > 150 ? 'الاسم أطول من الحد المسموح.' : '');
      };
      const validateUsername = () => {
        const valid = /^[A-Za-z0-9_.-]{3,80}$/.test(username.value);
        setError(username, 'username', valid || username.value === '' ? '' : 'استخدمي 3 إلى 80 حرفًا إنجليزيًا أو رقمًا أو . _ -');
        if (!username.value) setError(username, 'username', 'اسم المستخدم مطلوب.');
      };
      const validateRole = () => setError(role, 'role', role.value ? '' : 'اختاري دور المستخدم.');
      const updatePasswordStrength = input => {
        const meter = input.parentElement.querySelector('[data-password-meter]');
        const hint = input.parentElement.querySelector('[data-password-hint]');
        if (!meter || !hint) return;
        const value = input.value;
        const present = value.length > 0;
        const score = Number(value.length >= 12)
          + Number(/[a-z]/.test(value) && /[A-Z]/.test(value))
          + Number(/\d/.test(value))
          + Number(/[^A-Za-z0-9]/.test(value));
        const strength = !present ? '' : value.length < 12 || score <= 1 ? 'weak' : score <= 2 ? 'medium' : 'strong';
        meter.dataset.strength = strength;
        meter.style.width = !present ? '0' : `${Math.max(20, score * 25)}%`;
        hint.textContent = !present
          ? editor.dataset.editing === 'true' ? 'اتركي الحقل فارغًا للاحتفاظ بكلمة المرور الحالية.' : 'يجب أن تتكون من 12 حرفًا على الأقل.'
          : strength === 'weak' ? 'ضعيفة — استخدمي 12 حرفًا على الأقل ومزيجًا من أنواع مختلفة.' : strength === 'medium' ? 'متوسطة — أضيفي أرقامًا أو رموزًا لزيادة القوة.' : 'قوية';
        setError(input, 'password', present && value.length < 12 ? 'كلمة المرور أقصر من 12 حرفًا.' : '');
      };
      editor.dataset.editing = editor.querySelector('input[name="action"]')?.value === 'update' ? 'true' : 'false';
      name?.addEventListener('input', validateName);
      username?.addEventListener('input', validateUsername);
      role?.addEventListener('change', validateRole);
      password?.addEventListener('input', () => updatePasswordStrength(password));
      editor.addEventListener('submit', event => {
        validateName();
        validateUsername();
        validateRole();
        updatePasswordStrength(password);
        const passwordInvalid = password.value !== '' && password.value.length < 12;
        if (!name.value.trim() || !/^[A-Za-z0-9_.-]{3,80}$/.test(username.value) || !role.value || passwordInvalid || (editor.dataset.editing !== 'true' && password.value.length < 12)) {
          event.preventDefault();
          editor.querySelector('[aria-invalid="true"]')?.focus();
        }
      });
      updatePasswordStrength(password);
    }

    const confirmDialog = $('[data-user-confirm-dialog]', root);
    const confirmText = $('[data-confirm-text]', confirmDialog || root);
    const confirmContinue = $('[data-confirm-continue]', confirmDialog || root);
    const confirmCancel = $('[data-confirm-cancel]', confirmDialog || root);
    let pendingForm = null;
    let bypassConfirm = false;
    $$('[data-user-confirm]', root).forEach(form => {
      form.addEventListener('submit', event => {
        if (bypassConfirm) {
          bypassConfirm = false;
          return;
        }
        event.preventDefault();
        pendingForm = form;
        if (confirmText) {
          const warning = form.dataset.confirmKind === 'disable'
            ? 'سيتم منع المستخدم من تسجيل الدخول.'
            : form.dataset.confirmKind === 'reset'
              ? 'تأكدي من اختيار كلمة مرور آمنة وإبلاغ صاحب الحساب بها.'
              : form.dataset.confirmKind === 'delete'
                ? 'سيتم حذف الحساب وجميع تقاريره ومرفقاتها نهائيًا، ولا يمكن التراجع عن هذا الإجراء.'
              : 'يرجى التأكد من صحة الإجراء قبل المتابعة.';
          confirmText.textContent = `${form.dataset.userConfirm || ''} ${warning}`;
        }
        if (confirmDialog?.showModal) confirmDialog.showModal();
        else if (window.confirm(form.dataset.userConfirm || 'تأكيد الإجراء؟')) {
          bypassConfirm = true;
          form.submit();
        }
      });
    });
    confirmContinue?.addEventListener('click', () => {
      if (!pendingForm) return;
      const form = pendingForm;
      pendingForm = null;
      confirmDialog.close();
      bypassConfirm = true;
      form.submit();
    });
    confirmCancel?.addEventListener('click', () => {
      pendingForm = null;
      confirmDialog.close();
    });

    const resetDialog = $('[data-reset-dialog]', root);
    if (resetDialog?.showModal) resetDialog.showModal();

    root.addEventListener('click', event => {
      if (!event.target.closest('.users-actions')) {
        $$('.users-actions[open]', root).forEach(menu => menu.removeAttribute('open'));
      }
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        if (drawer?.classList.contains('is-open')) setDrawer(false);
        $$('.users-actions[open]', root).forEach(menu => menu.removeAttribute('open'));
      }
    });
    if (drawer?.classList.contains('is-open')) {
      drawer.inert = false;
      $('#user-name', drawer)?.focus({ preventScroll: true });
    } else if (drawer) {
      drawer.inert = true;
    }
  }

  function setupReportReview() {
    const root = $('[data-report-review]');
    if (!root) return;

    const imageButtons = $$('[data-lightbox-open]', root);
    const lightbox = $('[data-report-lightbox]', root);
    const lightboxImage = $('[data-lightbox-image]', lightbox || root);
    const lightboxCaption = $('[data-lightbox-caption]', lightbox || root);
    const lightboxCounter = $('[data-lightbox-counter]', lightbox || root);
    const lightboxPrevious = $('[data-lightbox-prev]', lightbox || root);
    const lightboxNext = $('[data-lightbox-next]', lightbox || root);
    let activeImage = 0;
    let previousFocus = null;

    const showImage = index => {
      if (!imageButtons.length || !lightboxImage) return;
      activeImage = (index + imageButtons.length) % imageButtons.length;
      const trigger = imageButtons[activeImage];
      lightboxImage.src = trigger.dataset.imageSrc || '';
      lightboxImage.alt = trigger.dataset.imageAlt || '';
      if (lightboxCaption) lightboxCaption.textContent = trigger.dataset.imageAlt || '';
      if (lightboxCounter) {
        lightboxCounter.textContent = `${new Intl.NumberFormat('ar').format(activeImage + 1)} / ${new Intl.NumberFormat('ar').format(imageButtons.length)}`;
      }
      if (lightboxPrevious) lightboxPrevious.hidden = imageButtons.length < 2;
      if (lightboxNext) lightboxNext.hidden = imageButtons.length < 2;
      if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        lightboxImage.animate(
          [{ opacity: 0, transform: 'scale(.94)' }, { opacity: 1, transform: 'scale(1)' }],
          { duration: 240, easing: 'cubic-bezier(.2,.8,.2,1)' }
        );
      }
    };
    imageButtons.forEach((button, index) => {
      button.addEventListener('click', () => {
        if (!lightbox?.showModal) return;
        previousFocus = button;
        showImage(index);
        lightbox.showModal();
      });
    });
    $('[data-lightbox-close]', lightbox || root)?.addEventListener('click', () => lightbox?.close());
    lightboxPrevious?.addEventListener('click', () => showImage(activeImage - 1));
    lightboxNext?.addEventListener('click', () => showImage(activeImage + 1));
    lightbox?.addEventListener('click', event => {
      if (event.target === lightbox) lightbox.close();
    });
    lightbox?.addEventListener('close', () => previousFocus?.focus({ preventScroll: true }));
    document.addEventListener('keydown', event => {
      if (!lightbox?.open) return;
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        showImage(activeImage + 1);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        showImage(activeImage - 1);
      }
    });

    const rejectDialog = $('[data-report-reject-dialog]', root);
    const rejectNote = $('[data-reject-note]', rejectDialog || root);
    const rejectCount = $('[data-reject-count]', rejectDialog || root);
    $$('[data-reject-dialog-open]', root).forEach(button => {
      button.addEventListener('click', () => {
        if (!rejectDialog?.showModal) return;
        rejectDialog.showModal();
        window.setTimeout(() => rejectNote?.focus(), 30);
      });
    });
    $('[data-reject-dialog-close]', rejectDialog || root)?.addEventListener('click', () => rejectDialog?.close());
    rejectNote?.addEventListener('input', () => {
      if (rejectCount) rejectCount.textContent = new Intl.NumberFormat('ar').format([...rejectNote.value].length);
    });

    const statusLabels = { approved: 'معتمد', rejected: 'مرفوض' };
    const statusClasses = { approved: 'badge-green', rejected: 'badge-rose' };
    $$('[data-report-decision]', root).forEach(form => {
      form.addEventListener('submit', async event => {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (!form.reportValidity()) return;
        const action = $('[name="action"]', form)?.value;
        if (!['approve', 'reject'].includes(action)) return;

        const submitButtons = [
          ...$$('button[type="submit"]', form),
          ...$$('[form="review-approve-form"]', root),
        ];
        submitButtons.forEach(button => { button.disabled = true; });
        form.setAttribute('aria-busy', 'true');

        try {
          const response = await fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          });
          const contentType = response.headers.get('content-type') || '';
          const result = contentType.includes('application/json') ? await response.json() : null;
          if (!response.ok) {
            throw new Error(result?.message || `تعذر حفظ القرار (رمز الاستجابة ${response.status}).`);
          }
          if (response.redirected || !result || result.status !== (action === 'approve' ? 'approved' : 'rejected')) {
            throw new Error(result?.message || 'لم يصل تأكيد صالح بحفظ القرار. أعيدي المحاولة.');
          }

          const label = statusLabels[result.status];
          const badge = $('[data-report-status]', root);
          root.dataset.status = result.status;
          if (badge) {
            badge.textContent = label;
            badge.classList.remove('badge-amber', 'badge-green', 'badge-rose', 'badge-slate', 'badge-violet');
            badge.classList.add(statusClasses[result.status]);
            badge.classList.remove('is-changing');
            requestAnimationFrame(() => badge.classList.add('is-changing'));
          }

          const reviewedStep = $('[data-reviewed-step]', root);
          const reviewedTime = $('[data-reviewed-at]', root);
          if (reviewedStep) reviewedStep.classList.add('is-complete');
          if (reviewedTime && result.reviewed_at) {
            reviewedTime.textContent = result.reviewed_at;
            reviewedTime.dateTime = result.reviewed_at.replace(' ', 'T');
          }

          if (result.status === 'rejected') {
            const reportPaper = $('.report-paper', root);
            if (reportPaper) {
              let reviewNote = $('.report-review-note', reportPaper);
              if (!reviewNote) {
                reviewNote = document.createElement('div');
                reviewNote.className = 'report-review-note';
                const heading = document.createElement('strong');
                heading.textContent = 'سبب الرفض';
                const note = document.createElement('p');
                reviewNote.append(heading, note);
                reportPaper.append(reviewNote);
              }
              const note = $('p', reviewNote);
              if (note) note.textContent = rejectNote?.value.trim() || '';
            }
          }

          const resultMarkup = result.status === 'approved'
            ? '<span class="report-success-mark" aria-hidden="true"><svg viewBox="0 0 48 48"><path d="m13 25 7 7 15-16"/></svg></span><span><strong>تم اعتماد التقرير</strong><small>قرار نهائي</small></span>'
            : '<span class="report-result-symbol" aria-hidden="true">×</span><span><strong>تم رفض التقرير</strong><small>قرار نهائي</small></span>';
          const resultCard = document.createElement('div');
          resultCard.className = `report-decision-result ${result.status === 'approved' ? 'report-result-approved' : 'report-result-rejected'}`;
          resultCard.dataset.decisionResult = '';
          resultCard.innerHTML = resultMarkup;
          $('[data-decision-result]', root)?.remove();
          $('[data-decision-intro]', root)?.after(resultCard);
          const actions = $('[data-decision-actions]', root);
          if (actions) actions.hidden = true;
          $('[data-mobile-decision-bar]', root)?.remove();
          if (rejectDialog?.open) rejectDialog.close();
          toast(result.message || 'تم حفظ قرار المراجعة.', 'success');
        } catch (error) {
          toast(error instanceof Error ? error.message : 'تعذر حفظ القرار. تحققي من الاتصال وحاولي مجددًا.');
          submitButtons.forEach(button => { button.disabled = false; });
        } finally {
          form.removeAttribute('aria-busy');
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    setupPlatformPreloader();
    setupNavigation();
    setupBookingSuccessDialog();
    setupHomeDrawer();
    setupHomeAnimations();
    setupStaffDashboard();
    setupBookingDate();
    setupSchedule();
    setupUploads();
    setupReportForm();
    setupCharts();
    setupDashboardRejectDialog();
    setupReportsPage();
    setupUserReportsPage();
    setupUserManagement();
    setupReportReview();
  });
})();
