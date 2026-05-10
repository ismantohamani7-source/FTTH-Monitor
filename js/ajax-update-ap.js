// js/ajax-update-ap.js
(function(){
  const AUTO_AJAX_UPDATE = 5000; // 5 detik
  
  // ✅ TAMBAHAN: Global variable untuk tracking filter state
  window.currentFilterState = {
    status: localStorage.getItem('apTableFilterState') || 'all',
    lastApplied: new Date().getTime()
  };
  
  // Helper: format waktu "since" ke format "MMM/DD/YYYY HH:mm:ss"
  function formatSinceJS(s) {
    if (!s) return '';
    
    let ts = new Date(s).getTime();
    
    if (isNaN(ts)) {
      const match = s.match(/(\d{4})[-\/](\d{2})[-\/](\d{2})\s+(\d{2}):(\d{2}):(\d{2})/);
      if (match) {
        ts = new Date(match[1], match[2] - 1, match[3], match[4], match[5], match[6]).getTime();
      }
    }
    
    if (isNaN(ts)) return s;
    
    const date = new Date(ts);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = months[date.getMonth()];
    const day = String(date.getDate()).padStart(2, '0');
    const year = date.getFullYear();
    const hour = String(date.getHours()).padStart(2, '0');
    const minute = String(date.getMinutes()).padStart(2, '0');
    const second = String(date.getSeconds()).padStart(2, '0');
    
    return `${month}/${day}/${year} ${hour}:${minute}:${second}`;
  }
  
  // Helper: escape HTML
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[c]));
  }
  
  // Helper: simple hash untuk group ID
  function simpleHash(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
      hash = ((hash << 5) - hash) + str.charCodeAt(i);
      hash = hash & hash;
    }
    return Math.abs(hash).toString(16);
  }
  
  // ✅ TAMBAHAN: Restore dan apply filter setelah table update
  function reApplyCurrentFilter() {
    const filterStatus = window.currentFilterState.status || 'all';
    const table = document.getElementById('ap-table');
    if (!table) return;
    
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    rows.forEach(row => {
      const isParent = row.classList.contains('parent-row');
      const groupId = row.dataset.group;
      
      if (isParent) {
        const childRows = Array.from(document.querySelectorAll('.' + groupId));
        if (filterStatus === 'all') {
          row.style.display = '';
          childRows.forEach((cr) => { cr.style.display = 'none'; });
          const icon = row.querySelector('.toggle-icon');
          if (icon) icon.textContent = '⯈';
        } else {
          const anyChildMatch = childRows.some(cr => cr.dataset.status === filterStatus);
          if (anyChildMatch) {
            row.style.display = '';
            childRows.forEach(cr => {
              if (cr.dataset.status === filterStatus) cr.style.display = 'table-row';
              else cr.style.display = 'none';
            });
            const icon = row.querySelector('.toggle-icon');
            if (icon) icon.textContent = '⯆';
          } else {
            row.style.display = 'none';
            childRows.forEach(cr => { cr.style.display = 'none'; });
          }
        }
      } else {
        const s = row.dataset.status || 'unknown';
        if (filterStatus === 'all') row.style.display = '';
        else row.style.display = (s === filterStatus) ? '' : 'none';
      }
    });
    
    // ✅ Restore button active state
    const btnOffline = document.getElementById('filter-offline');
    const btnOnline = document.getElementById('filter-online');
    const btnAll = document.getElementById('filter-all');
    
    if (btnOffline) btnOffline.classList.toggle('active', filterStatus === 'down');
    if (btnOnline) btnOnline.classList.toggle('active', filterStatus === 'up');
    if (btnAll) btnAll.classList.toggle('active', filterStatus === 'all');
  }
  
  // Main function untuk update
  function updateAPTable() {
    fetch('api_update_ap_list.php')
      .then(r => r.json())
      .then(data => {
        if (!data.apList || !data.netwatch) return;
        
        // Update global NW_STATUS
        Object.assign(NW_STATUS, data.netwatch);
        
        // Update AP_LIST
        const newApIds = new Set(data.apList.map(a => a.id));
        
        data.apList.forEach(newAp => {
          const idx = AP_LIST.findIndex(a => a.id === newAp.id);
          if (idx >= 0) {
            AP_LIST[idx] = newAp;
          } else {
            AP_LIST.push(newAp);
          }
        });
        
        // Remove AP yang tidak ada lagi
        for (let i = AP_LIST.length - 1; i >= 0; i--) {
          if (!newApIds.has(AP_LIST[i].id)) {
            AP_LIST.splice(i, 1);
          }
        }
        
        // Update markers di map
        data.apList.forEach(ap => {
          const marker = markers[ap.id];
          if (marker) {
            const status = (NW_STATUS[ap.ip] && NW_STATUS[ap.ip].status) ? NW_STATUS[ap.ip].status : 'unknown';
            const iconType = (ap.type === 'odp') ? 'odp' : 'wifi';
            marker.setIcon(makeIconByType(status, iconType));
          }
        });
        
        // Update lines color
        data.apList.forEach(ap => {
          if (ap.line && lines[ap.id]) {
            const status = (NW_STATUS[ap.ip] && NW_STATUS[ap.ip].status) ? NW_STATUS[ap.ip].status : 'unknown';
            let colorWord;
            if (status === 'down') {
              colorWord = 'red';
            } else if (status === 'up') {
              colorWord = normalizeColor(ap.lineColor || 'lime');
            } else {
              colorWord = normalizeColor(ap.lineColor || 'gray');
            }
            lineColors[ap.id] = colorWord;
            lines[ap.id].setStyle({ color: colorWord, opacity: 1 });
            createDecoratorForLine(ap.id, lines[ap.id], colorWord);
          }
        });
        
        // Update hotspot counter
        HOTSPOT_ACTIVE.length = 0;
        data.hotspotActive.forEach(h => HOTSPOT_ACTIVE.push(h));
        const hotspotCountEl = document.querySelector('#hotspot-control-wrapper .hotspot-control:first-child');
        if (hotspotCountEl) {
          hotspotCountEl.textContent = (HOTSPOT_ACTIVE && HOTSPOT_ACTIVE.length) ? HOTSPOT_ACTIVE.length : 0;
        }
        
        // Update filter badges
        updateFilterBadges(data.counts);
        
        // Update tabel
        updateTableUI(data);
        
        // ✅ TAMBAHAN: Re-apply filter setelah table rebuild
        reApplyCurrentFilter();
      })
      .catch(err => {
        console.warn('AJAX update error:', err);
      });
  }
  
  // Update filter badges
  function updateFilterBadges(counts) {
    const filterOffline = document.getElementById('filter-offline');
    const filterOnline = document.getElementById('filter-online');
    const filterAll = document.getElementById('filter-all');
    
    if (filterOffline) {
      filterOffline.textContent = counts.down + ' Off';
      filterOffline.className = 'filter-badge offline';
      filterOffline.dataset.status = 'down';
    }
    
    if (filterOnline) {
      filterOnline.textContent = counts.up + ' On';
      filterOnline.className = 'filter-badge online';
      filterOnline.dataset.status = 'up';
    }
    
    if (filterAll) {
      filterAll.textContent = 'All (' + counts.total + ')';
      filterAll.className = 'filter-badge all';
      filterAll.dataset.status = 'all';
    }
  }
  
  // Update tabel UI dengan offline groups
  function updateTableUI(data) {
    const table = document.getElementById('ap-table');
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    
    // Sort AP berdasar status (down → unknown → up)
    const sorted = [...data.apList].sort((a, b) => {
      const order = ['down', 'unknown', 'up'];
      
      let statusA = 'unknown';
      if ((a.type || 'wifi') === 'odp' && !a.ip) {
        statusA = 'unknown';
      } else {
        statusA = (data.netwatch[a.ip] && data.netwatch[a.ip].status) ? data.netwatch[a.ip].status : 'unknown';
      }
      
      let statusB = 'unknown';
      if ((b.type || 'wifi') === 'odp' && !b.ip) {
        statusB = 'unknown';
      } else {
        statusB = (data.netwatch[b.ip] && data.netwatch[b.ip].status) ? data.netwatch[b.ip].status : 'unknown';
      }
      
      return order.indexOf(statusA) - order.indexOf(statusB);
    });
    
    // Build AP by ID map
    const apById = {};
    sorted.forEach(ap => { apById[ap.id] = ap; });
    
    // Get down children recursively
    function getDownChildren(id) {
      const result = [];
      sorted.forEach(ap => {
        if (!ap.line || ap.line !== id) return;
        const st = (data.netwatch[ap.ip] && data.netwatch[ap.ip].status) ? data.netwatch[ap.ip].status : 'unknown';
        if (st === 'down') {
          result.push(ap);
          result.push(...getDownChildren(ap.id));
        }
      });
      return result;
    }
    
    // Build offline groups
    const offlineGroups = {};
    sorted.forEach(ap => {
      const st = (data.netwatch[ap.ip] && data.netwatch[ap.ip].status) ? data.netwatch[ap.ip].status : 'unknown';
      if (st !== 'down') return;
      
      const parentLine = ap.line || null;
      if (parentLine && apById[parentLine]) {
        const parentAp = apById[parentLine];
        const parentStatus = (data.netwatch[parentAp.ip] && data.netwatch[parentAp.ip].status) ? data.netwatch[parentAp.ip].status : 'unknown';
        if (parentStatus === 'down') return;
      }
      
      const childrenDown = getDownChildren(ap.id);
      if (childrenDown.length > 0) {
        offlineGroups[ap.id] = childrenDown;
      }
    });
    
    // Build table HTML
    let html = '';
    const shownIds = [];
    
    // Tampilkan offline groups
    Object.entries(offlineGroups).forEach(([parentId, group]) => {
      const parent = apById[parentId];
      const groupId = 'group_' + simpleHash(parentId);
      
      html += `<tr class="parent-row" data-group="${groupId}" data-root-status="down" style="background:#2d2d2d;color:#ef4444;font-weight:bold;cursor:pointer;">
        <td colspan="3" style="padding:4px 6px;">
          🔴 ${escapeHtml(parent.name)} (+${group.length})
          <span class="toggle-icon" style="float:right;">⯈</span>
        </td>
      </tr>`;
      
      group.forEach(child => {
        shownIds.push(child.id);
        const sinceRaw = (data.netwatch[child.ip] && data.netwatch[child.ip].since) ? data.netwatch[child.ip].since : (child.lasttime || '');
        const since = formatSinceJS(sinceRaw);
        const childStatus = (data.netwatch[child.ip] && data.netwatch[child.ip].status) ? data.netwatch[child.ip].status : 'unknown';
        
        html += `<tr class="child-row ${groupId}" style="display:none;border-bottom:1px solid #444;cursor:pointer;" data-status="${escapeHtml(childStatus)}" onclick="focusToAp('${child.id}')">
          <td style="width:18px;padding:2px 4px;color:#ef4444;">↳</td>
          <td style="padding:2px 4px;">${escapeHtml(child.name)}</td>
          <td style="padding:2px 4px;text-align:right;color:#75ddff;">${escapeHtml(since)}</td>
        </tr>`;
      });
      
      shownIds.push(parentId);
    });
    
    // Tampilkan AP lain yang belum tampil
    sorted.forEach(ap => {
      if (shownIds.includes(ap.id)) return;
      
      const status = (data.netwatch[ap.ip] && data.netwatch[ap.ip].status) ? data.netwatch[ap.ip].status : 'unknown';
      const cls = status === 'up' ? 'color:#16a34a;' : (status === 'down' ? 'color:#ef4444;' : 'color:#6b7280;');
      const icon = status === 'up' ? '🟢' : (status === 'down' ? '🔴' : '⚪');
      const sinceRaw = (data.netwatch[ap.ip] && data.netwatch[ap.ip].since) ? data.netwatch[ap.ip].since : (ap.lasttime || '');
      const since = formatSinceJS(sinceRaw);
      
      html += `<tr style="border-bottom:1px solid #eee;cursor:pointer;" data-status="${escapeHtml(status)}" onclick="focusToAp('${ap.id}')">
        <td style="padding:2px 4px;${cls}width:18px;">${icon}</td>
        <td style="padding:2px 4px;white-space:nowrap;width:18px;">${escapeHtml(ap.name)}</td>
        <td style="padding:2px 4px;text-align:right;white-space:nowrap;color:#75ddff;width:10px;">
          ${(ap.type || 'wifi') === 'odp' && !ap.ip ? 'ODP' : escapeHtml(since)}
        </td>
      </tr>`;
    });
    
    tbody.innerHTML = html;
    
    // Re-attach event listeners untuk parent row toggle
    document.querySelectorAll('.parent-row').forEach(row => {
      row.addEventListener('click', function(e) {
        const groupId = this.dataset.group;
        const children = document.querySelectorAll('.' + groupId);
        const icon = this.querySelector('.toggle-icon');
        const isVisible = children[0] && children[0].style.display !== 'none';
        
        children.forEach(tr => {
          tr.style.display = isVisible ? 'none' : 'table-row';
        });
        
        icon.textContent = isVisible ? '⯈' : '⯆';
      });
    });
  }
  
  // Start AJAX update loop
  setInterval(updateAPTable, AUTO_AJAX_UPDATE);
  
  // Initial update
  updateAPTable();
})();