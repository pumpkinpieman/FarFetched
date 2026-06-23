/* FarFetched theme picker — shared across all pages.
 * Replaces the old binary light/dark toggle with a multi-theme popover.
 * Themes are CSS [data-theme="..."] blocks in styles.css; "dark" is the
 * default :root (no attribute). Choice persists in localStorage('theme'). */
(function () {
  'use strict';

  // id -> { label, swatch colors for the chip }
  var THEMES = [
    { id: 'dark',     label: 'Dark',     dot: ['#261d12', '#ff6b1a', '#f0e6d3'] },
    { id: 'light',    label: 'Light',    dot: ['#ffffff', '#d44d0d', '#261d12'] },
    { id: 'midnight', label: 'Midnight', dot: ['#152c44', '#fd7767', '#26e5d3'] },
    { id: 'forest',   label: 'Forest',   dot: ['#1a2c20', '#e8a13c', '#5fc88a'] },
    { id: 'nord',     label: 'Nord',     dot: ['#3b4252', '#88c0d0', '#a3be8c'] },
    { id: 'mono',     label: 'Mono',     dot: ['#262626', '#c8c8c8', '#8fb98f'] }
  ];

  function apply(id) {
    if (id === 'dark') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', id);
  }

  function current() {
    return localStorage.getItem('theme') || 'dark';
  }

  // Apply saved theme ASAP (also done inline per-page to avoid flash).
  apply(current());

  function buildPicker(btn) {
    // Popover element (one per page).
    var pop = document.createElement('div');
    pop.className = 'theme-pop';
    pop.setAttribute('role', 'menu');
    pop.hidden = true;

    THEMES.forEach(function (t) {
      var row = document.createElement('button');
      row.className = 'theme-pop-row';
      row.setAttribute('role', 'menuitemradio');
      row.dataset.theme = t.id;

      var dots = t.dot.map(function (c) {
        return '<span class="theme-dot" style="background:' + c + '"></span>';
      }).join('');
      row.innerHTML = '<span class="theme-swatch">' + dots + '</span>' +
                      '<span class="theme-name">' + t.label + '</span>' +
                      '<span class="theme-check">✓</span>';

      row.addEventListener('click', function () {
        apply(t.id);
        localStorage.setItem('theme', t.id);
        mark(pop);
        close();
      });
      pop.appendChild(row);
    });

    document.body.appendChild(pop);

    function mark(p) {
      var cur = current();
      p.querySelectorAll('.theme-pop-row').forEach(function (r) {
        r.classList.toggle('active', r.dataset.theme === cur);
      });
    }

    function place() {
      var r = btn.getBoundingClientRect();
      // Prefer opening above the button (it sits low in the sidebar).
      pop.style.left = r.left + 'px';
      pop.style.minWidth = Math.max(r.width, 190) + 'px';
      // Temporarily show to measure height.
      pop.hidden = false;
      var ph = pop.offsetHeight;
      var above = r.top - ph - 8;
      if (above > 8) {
        pop.style.top = above + 'px';
      } else {
        pop.style.top = (r.bottom + 8) + 'px';
      }
    }

    function open() { mark(pop); place(); pop.hidden = false; document.addEventListener('click', outside, true); }
    function close() { pop.hidden = true; document.removeEventListener('click', outside, true); }
    function outside(e) { if (!pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) close(); }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (pop.hidden) open(); else close();
    });

    window.addEventListener('resize', function () { if (!pop.hidden) place(); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    // Drop any old inline click handlers (legacy light/dark toggle) by cloning.
    var fresh = btn.cloneNode(true);
    btn.parentNode.replaceChild(fresh, btn);
    // Keep the icon consistent (legacy pages set ☀️/🌙); use a neutral glyph.
    var ic = fresh.querySelector('#theme-toggle-icon');
    if (ic) ic.textContent = '🎨';
    buildPicker(fresh);
  });
})();
