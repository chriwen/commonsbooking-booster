/**
 * Occupancy plan (admin page and shortcode [cb_occupancy_plan]): search field with multiple selection, detail
 * panel, day explanation and tooltip. No dependencies. Configuration: #cb-plan-data (JSON).
 *
 * @see design/belegungsplan.md
 */
(function(){
	'use strict';

	function el(tag, cls, text){
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}
	// requestSubmit() fires the submit listeners (scroll position, expanded locations), submit() would skip them
	function submitForm(form){
		if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
	}

	// Search field with multiple selection
	document.querySelectorAll('.cb-plan-picker').forEach(function(picker){
		var options = JSON.parse(picker.querySelector('.cb-plan-picker-options').textContent);
		var pt = JSON.parse(picker.getAttribute('data-i18n'));
		var chips = picker.querySelector('.cb-plan-chips');
		var input = picker.querySelector('.cb-plan-picker-input');
		var list = picker.querySelector('.cb-plan-picker-list');
		var form = picker.closest('form');
		var name = picker.getAttribute('data-name');
		var matches = [];
		var current = -1;

		function fold(s){ return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
		function selected(){
			var ids = {};
			chips.querySelectorAll('.cb-plan-chip').forEach(function(c){ ids[c.getAttribute('data-id')] = true; });
			return ids;
		}
		function changed(){ form.classList.add('is-dirty'); }
		function addChip(option){
			var li = el('li', 'cb-plan-chip');
			li.setAttribute('data-id', option[0]);
			li.appendChild(el('span', null, option[1]));
			var b = el('button', 'cb-plan-chip-remove', '×');
			b.type = 'button';
			b.setAttribute('aria-label', pt.remove.replace('%s', option[1]));
			li.appendChild(b);
			var h = el('input');
			h.type = 'hidden';
			h.name = name;
			h.value = option[0];
			li.appendChild(h);
			chips.appendChild(li);
			changed();
		}
		function closeList(){
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			current = -1;
		}
		function highlight(index){
			var items = list.querySelectorAll('[role=option]');
			if (!items.length) { return; }
			current = (index + items.length) % items.length;
			items.forEach(function(li, i){ li.classList.toggle('is-current', i === current); });
			input.setAttribute('aria-activedescendant', items[current].id);
			items[current].scrollIntoView({block: 'nearest'});
		}
		function openList(){
			var q = fold(input.value.trim());
			var ids = selected();
			matches = options.filter(function(o){ return !ids[o[0]] && (!q || fold(o[1]).indexOf(q) !== -1); });
			list.textContent = '';
			if (!matches.length) {
				list.appendChild(el('li', 'cb-plan-picker-none', pt.noMatches));
			}
			matches.forEach(function(o, i){
				var li = el('li', null, o[1]);
				li.id = 'cb-plan-option-' + o[0];
				li.setAttribute('role', 'option');
				li.setAttribute('data-index', i);
				list.appendChild(li);
			});
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			current = -1;
			if (q && matches.length) { highlight(0); }
		}
		function choose(index){
			if (!matches[index]) { return; }
			addChip(matches[index]);
			input.value = '';
			openList();
			input.focus();
		}

		input.addEventListener('focus', openList);
		input.addEventListener('input', openList);
		input.addEventListener('keydown', function(e){
			if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { openList(); } highlight(current + 1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(current - 1); }
			else if (e.key === 'Enter') {
				e.preventDefault();
				if (!list.hidden && current >= 0) { choose(current); }
				else if (!input.value.trim()) { submitForm(form); }
			}
			else if (e.key === 'Escape') { if (!list.hidden) { e.stopPropagation(); closeList(); } }
			else if (e.key === 'Backspace' && !input.value) {
				var last = chips.lastElementChild;
				if (last) { last.remove(); changed(); if (!list.hidden) { openList(); } }
			}
		});
		input.addEventListener('blur', function(){ setTimeout(function(){ if (document.activeElement !== input) { closeList(); } }, 150); });
		// mousedown keeps the focus in the input
		list.addEventListener('mousedown', function(e){
			e.preventDefault();
			var li = e.target.closest('[role=option]');
			if (li) { choose(parseInt(li.getAttribute('data-index'), 10)); }
		});
		chips.addEventListener('click', function(e){
			var b = e.target.closest('.cb-plan-chip-remove');
			if (!b) { return; }
			b.closest('.cb-plan-chip').remove();
			changed();
			input.focus();
		});
		picker.querySelector('.cb-plan-picker-box').addEventListener('click', function(e){ if (e.target === this || e.target === chips) { input.focus(); } });
	});

	// Filters that apply on change (category, cancelled bookings, period, zoom)
	document.querySelectorAll('#cb-plan-root [data-autosubmit]').forEach(function(field){
		field.addEventListener('change', function(){ submitForm(field.form); });
	});

	// Plan: detail panel, day explanation, tooltip, name column, collapsing
	(function(){
		var data = document.getElementById('cb-plan-data');
		var root = document.querySelector('.cb-plan');
		if (!data || !root) { return; }
		var cfg = JSON.parse(data.textContent);
		var t = cfg.i18n;
		var panel = document.getElementById('cb-plan-panel');
		var body = panel.querySelector('.cb-plan-panel-body');
		var tip = document.getElementById('cb-plan-tip');
		var scroller = document.querySelector('.cb-plan-scroll');
		// position: fixed must not depend on the theme (an ancestor with transform or overflow would clip it)
		document.body.appendChild(panel);
		document.body.appendChild(tip);
		var active = null;
		var request = 0;

		function setActive(node){
			if (active) { active.classList.remove('is-active'); }
			active = node;
			if (node) { node.classList.add('is-active'); }
		}
		function close(){
			panel.hidden = true;
			var last = active;
			setActive(null);
			if (last && last.focus) { last.focus(); }
		}
		function details(list){
			var dl = el('dl', 'cb-plan-details');
			(list || []).forEach(function(d){
				if (d[1] === '') { return; }
				dl.appendChild(el('dt', null, d[0]));
				dl.appendChild(el('dd', null, d[1]));
			});
			return dl;
		}
		function conflicts(list){
			var box = el('div', 'cb-plan-conflicts');
			box.appendChild(el('strong', null, '⚠ ' + t.conflicts));
			var ul = el('ul');
			list.forEach(function(c){ ul.appendChild(el('li', null, c)); });
			box.appendChild(ul);
			return box;
		}
		function editLink(url){
			var p = el('p');
			var a = el('a', cfg.buttonClass || 'button', t.edit);
			a.href = url;
			p.appendChild(a);
			return p;
		}
		function show(nodes){
			body.textContent = '';
			nodes.forEach(function(n){ body.appendChild(n); });
			panel.hidden = false;
		}
		function openPost(button){
			var post = cfg.posts[button.getAttribute('data-post')];
			if (!post) { return; }
			setActive(button);
			var nodes = [el('p', 'cb-plan-panel-type', post.type_label), el('h2', 'cb-plan-panel-title', post.title)];
			if (post.conflicts && post.conflicts.length) { nodes.push(conflicts(post.conflicts)); }
			nodes.push(details(post.details));
			if (post.edit_url) { nodes.push(editLink(post.edit_url)); }
			show(nodes);
		}
		// Day explanation: location (item view: item) -> one card per row with the calendar result -> reasons grouped
		// into timeframes, restrictions and bookings
		var KIND_GROUP = {bookable: 'timeframe', holiday: 'timeframe', repair: 'timeframe', restriction: 'restriction', booking: 'booking'};
		function icon(type){
			var i = el('span', 'cb-plan-icon is-' + type);
			i.setAttribute('aria-hidden', 'true');
			return i;
		}
		function reasonNode(r){
			var d = el('details', 'cb-plan-reason cb-plan-reason-' + r.kind);
			var s = el('summary');
			s.appendChild(el('span', 'cb-plan-swatch cb-plan-' + r.kind));
			s.appendChild(el('span', 'cb-plan-reason-title', r.title));
			if (r.conflicts && r.conflicts.length) { s.appendChild(el('span', 'cb-plan-badge', '⚠ ' + r.conflicts.length)); }
			s.appendChild(el('span', 'cb-plan-reason-type', r.type_label));
			d.appendChild(s);
			if (r.conflicts && r.conflicts.length) { d.appendChild(conflicts(r.conflicts)); d.open = true; }
			d.appendChild(details(r.details));
			if (r.edit_url) { d.appendChild(editLink(r.edit_url)); }
			return d;
		}
		function dayCard(row){
			var card = el('section', 'cb-plan-day is-' + row.result.tone);
			var head = el('h3', 'cb-plan-day-head');
			head.appendChild(icon(cfg.view === 'item' ? 'location' : 'item'));
			head.appendChild(el('span', 'cb-plan-day-title', row.title));
			var res = el('div', 'cb-plan-result is-' + row.result.tone);
			res.appendChild(el('span', 'cb-plan-result-label', t.calendar));
			res.appendChild(el('strong', null, row.result.label));
			row.result.notes.forEach(function(n){ res.appendChild(el('p', null, n)); });
			card.appendChild(head);
			card.appendChild(res);
			if (!row.reasons.length) {
				card.appendChild(el('p', 'cb-plan-muted', t.noReasons));
			}
			['timeframe', 'restriction', 'booking'].forEach(function(key){
				var list = row.reasons.filter(function(r){ return (KIND_GROUP[r.kind] || 'timeframe') === key; });
				if (!list.length) { return; }
				var box = el('div', 'cb-plan-reasons is-' + key);
				box.appendChild(el('p', 'cb-plan-reasons-title', t.groups[key] + ' · ' + list.length));
				list.forEach(function(r){ box.appendChild(reasonNode(r)); });
				card.appendChild(box);
			});
			return card;
		}
		function summary(rows){
			var counts = [], seen = {};
			rows.forEach(function(row){
				var key = row.result.tone + '|' + row.result.label;
				if (!seen[key]) { seen[key] = {tone: row.result.tone, label: row.result.label, n: 0}; counts.push(seen[key]); }
				seen[key].n++;
			});
			var sum = el('ul', 'cb-plan-day-summary');
			counts.forEach(function(c){ sum.appendChild(el('li', 'cb-plan-pill is-' + c.tone, c.n + ' × ' + c.label)); });
			return sum;
		}
		function renderDay(data){
			var nodes = [el('p', 'cb-plan-panel-type', t.day), el('h2', 'cb-plan-panel-title', data.date_label)];
			// Summary: how many rows show which result in the booking calendar
			if (data.rows.length > 1) { nodes.push(summary(data.rows)); }
			var groups = [], byId = {};
			data.rows.forEach(function(row){
				var id = String(row.group_id || '');
				if (!byId[id]) { byId[id] = {id: id, title: row.group_title || '', rows: []}; groups.push(byId[id]); }
				byId[id].rows.push(row);
			});
			groups.forEach(function(g){
				// Several groups: collapsible, collapsed like in the plan (only here in the panel when toggled)
				var foldable = groups.length > 1 && g.title;
				var sec = el(foldable ? 'details' : 'div', 'cb-plan-daygroup');
				if (foldable) { sec.open = !isCollapsed(g.id); }
				if (g.title) {
					var h = el(foldable ? 'summary' : 'p', 'cb-plan-daygroup-title');
					h.appendChild(icon(cfg.view === 'item' ? 'item' : 'location'));
					h.appendChild(el('span', 'cb-plan-daygroup-name', g.title));
					if (foldable) { h.appendChild(summary(g.rows)); }
					sec.appendChild(h);
				}
				var list = el('div', 'cb-plan-daygroup-rows');
				g.rows.forEach(function(row){ list.appendChild(dayCard(row)); });
				sec.appendChild(list);
				nodes.push(sec);
			});
			show(nodes);
		}
		function openDay(node, date, row, ids){
			setActive(node);
			show([el('p', 'cb-plan-muted', t.loading)]);
			var id = ++request;
			var params = new URLSearchParams({action: cfg.action, _ajax_nonce: cfg.nonce, view: cfg.view, ids: ids.join(','), date: date, row: row || 0});
			fetch(cfg.ajax, {method: 'POST', credentials: 'same-origin', body: params})
				.then(function(r){ return r.json(); })
				.then(function(json){
					if (id !== request) { return; }
					if (!json || !json.success) { throw new Error(); }
					renderDay(json.data);
				})
				.catch(function(){ if (id === request) { show([el('p', 'cb-plan-muted', t.error)]); } });
		}

		root.addEventListener('click', function(e){
			var seg = e.target.closest('.cb-plan-seg[data-post]');
			if (seg) { openPost(seg); return; }
			var head = e.target.closest('.cb-plan-dayhead');
			if (head) { openDay(head, head.getAttribute('data-date'), 0, cfg.ids); return; }
			var cell = e.target.closest('.cb-plan-cell');
			if (cell) {
				var row = cell.closest('.cb-plan-row');
				openDay(cell, cell.getAttribute('data-date'), parseInt(row.getAttribute('data-row'), 10) || 0, [row.getAttribute('data-group')]);
			}
		});
		panel.querySelector('.cb-plan-close').addEventListener('click', close);
		document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !panel.hidden) { close(); } });

		// Tooltip on top of everything (native titles get clipped by the scroll area)
		function hideTip(){ tip.hidden = true; }
		function showTip(target){
			var text = target.getAttribute('data-tip');
			if (!text) { return; }
			// names in the label column: only when shortened
			if (target.classList.contains('cb-plan-name') && target.scrollWidth <= target.clientWidth) { hideTip(); return; }
			tip.textContent = text;
			tip.hidden = false;
			var r = target.getBoundingClientRect();
			var w = tip.offsetWidth, h = tip.offsetHeight;
			var x = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
			var y = r.top - h - 6;
			if (y < 40) { y = r.bottom + 6; }
			tip.style.left = x + 'px';
			tip.style.top = y + 'px';
		}
		root.addEventListener('mouseover', function(e){
			var target = e.target.closest('[data-tip]');
			if (target) { showTip(target); } else { hideTip(); }
		});
		root.addEventListener('mouseleave', hideTip);
		root.addEventListener('focusin', function(e){ var target = e.target.closest('[data-tip]'); if (target) { showTip(target); } });
		root.addEventListener('focusout', hideTip);
		scroller.addEventListener('scroll', hideTip, {passive: true});

		// Name column: width adjustable by dragging the handle in the header (or arrow keys), remembered in this browser
		var headLabel = root.querySelector('.cb-plan-days > .cb-plan-label');
		var storeKey = 'cbPlanLabelWidth';
		function defaultWidth(){
			var saved = root.style.getPropertyValue('--cb-plan-label');
			root.style.removeProperty('--cb-plan-label');
			var w = headLabel.getBoundingClientRect().width;
			if (saved) { root.style.setProperty('--cb-plan-label', saved); }
			return w;
		}
		function clampWidth(w){
			var max = Math.max(160, Math.min(640, scroller.clientWidth * 0.75));
			return Math.round(Math.min(Math.max(w, 100), max));
		}
		function setWidth(w, save){
			w = clampWidth(w);
			root.style.setProperty('--cb-plan-label', w + 'px');
			handle.setAttribute('aria-valuenow', String(w));
			if (save) { try { localStorage.setItem(storeKey, String(w)); } catch (e) {} }
		}
		function resetWidth(){
			root.style.removeProperty('--cb-plan-label');
			handle.setAttribute('aria-valuenow', String(Math.round(headLabel.getBoundingClientRect().width)));
			try { localStorage.removeItem(storeKey); } catch (e) {}
		}
		var handle = null;
		if (headLabel) {
			handle = el('div', 'cb-plan-resize');
			handle.setAttribute('role', 'separator');
			handle.setAttribute('aria-orientation', 'vertical');
			handle.setAttribute('aria-label', t.resize);
			handle.setAttribute('aria-valuemin', '100');
			handle.setAttribute('tabindex', '0');
			handle.setAttribute('data-tip', t.resizeTip);
			headLabel.appendChild(handle);
			var stored = null;
			try { stored = parseInt(localStorage.getItem(storeKey), 10); } catch (e) {}
			if (stored > 0) { setWidth(stored, false); } else { handle.setAttribute('aria-valuenow', String(Math.round(defaultWidth()))); }

			var startX = 0, startW = 0;
			handle.addEventListener('pointerdown', function(e){
				if (e.button !== 0) { return; }
				e.preventDefault();
				startX = e.clientX;
				startW = headLabel.getBoundingClientRect().width;
				handle.setPointerCapture(e.pointerId);
				root.classList.add('is-resizing');
				hideTip();
			});
			handle.addEventListener('pointermove', function(e){
				if (!root.classList.contains('is-resizing')) { return; }
				setWidth(startW + e.clientX - startX, false);
			});
			function endDrag(e){
				if (!root.classList.contains('is-resizing')) { return; }
				root.classList.remove('is-resizing');
				setWidth(startW + e.clientX - startX, true);
			}
			handle.addEventListener('pointerup', endDrag);
			handle.addEventListener('pointercancel', endDrag);
			handle.addEventListener('dblclick', resetWidth);
			handle.addEventListener('keydown', function(e){
				var w = headLabel.getBoundingClientRect().width;
				if (e.key === 'ArrowRight') { setWidth(w + 20, true); }
				else if (e.key === 'ArrowLeft') { setWidth(w - 20, true); }
				else if (e.key === 'Home' || e.key === 'Escape') { resetWidth(); }
				else { return; }
				e.preventDefault();
			});
		}

		// Collapse locations (item view: items): view only, remembered in this browser per view, no reload
		var foldKey = 'cbPlanCollapsed';
		var folded = {};
		try { folded = JSON.parse(localStorage.getItem(foldKey) || '{}') || {}; } catch (e) { folded = {}; }
		var foldList = (folded[cfg.view] || []).map(String);
		var headings = Array.prototype.slice.call(root.querySelectorAll('.cb-plan-group[data-group]'));
		function isCollapsed(id){ return headings.length > 1 && foldList.indexOf(String(id)) !== -1; }
		function saveFolds(){
			folded[cfg.view] = foldList;
			try { localStorage.setItem(foldKey, JSON.stringify(folded)); } catch (e) {}
		}
		var expandAll = null;
		function applyFolds(){
			headings.forEach(function(head){
				var id = head.getAttribute('data-group');
				var closed = isCollapsed(id);
				head.classList.toggle('is-collapsed', closed);
				root.querySelectorAll('[data-group="' + id + '"]:not(.cb-plan-group)').forEach(function(line){ line.hidden = closed; });
				var toggle = head.querySelector('.cb-plan-toggle');
				var nameNode = head.querySelector('.cb-plan-name');
				var name = nameNode ? nameNode.getAttribute('data-tip') : '';
				var label = (closed ? t.expand : t.collapse).replace('%s', name);
				toggle.setAttribute('aria-expanded', closed ? 'false' : 'true');
				toggle.setAttribute('aria-label', label);
				toggle.setAttribute('data-tip', label);
			});
			if (expandAll) { expandAll.hidden = !headings.some(function(h){ return isCollapsed(h.getAttribute('data-group')); }); }
		}
		function setFold(id, closed){
			id = String(id);
			foldList = foldList.filter(function(x){ return x !== id; });
			if (closed) { foldList.push(id); }
		}
		if (headings.length > 1) {
			var corner = root.querySelector('.cb-plan-months > .cb-plan-label');
			if (corner) {
				expandAll = el('button', 'cb-plan-expand-all', t.expandAll);
				expandAll.type = 'button';
				expandAll.hidden = true;
				corner.appendChild(expandAll);
				expandAll.addEventListener('click', function(){ foldList = []; saveFolds(); applyFolds(); });
			}
			root.addEventListener('click', function(e){
				var head = e.target.closest('.cb-plan-group');
				if (!head || e.target.closest('a')) { return; }
				var id = head.getAttribute('data-group');
				if (e.target.closest('.cb-plan-solo')) {
					foldList = headings.map(function(h){ return h.getAttribute('data-group'); }).filter(function(x){ return x !== id; });
				} else if (e.target.closest('.cb-plan-label')) {
					setFold(id, !isCollapsed(id));
				} else {
					return;
				}
				hideTip();
				saveFolds();
				applyFolds();
			});
			// Selecting a location again in the search field shows it expanded
			var form = document.querySelector('.cb-plan-filters');
			if (form) {
				form.addEventListener('submit', function(){
					var current = cfg.ids.map(String);
					new FormData(form).forEach(function(value, key){
						if (/(location|item)\[\]$/.test(key) && current.indexOf(String(value)) === -1) { setFold(value, false); }
					});
					saveFolds();
				});
			}
			applyFolds();
		}
	})();

	/*
	 * Keep the scroll position when the plan reloads itself (earlier/today/later, view tabs, filters): every change is
	 * a page load, which would otherwise start at the top. Stored per tab for a short time and only for the same page.
	 */
	(function(){
		var root = document.getElementById('cb-plan-root');
		if (!root) { return; }
		var key = 'cbPlanScroll';
		function remember(){
			try { sessionStorage.setItem(key, JSON.stringify({ path: location.pathname, y: window.pageYOffset, t: Date.now() })); } catch (e) {}
		}
		root.addEventListener('click', function(e){
			var a = e.target.closest('a[href]');
			if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.target) { return; }
			if (!a.closest('.cb-plan-nav, .cb-plan-tabs')) { return; }
			remember();
		});
		var form = root.querySelector('.cb-plan-filters');
		if (form) { form.addEventListener('submit', remember); }
		var saved = null;
		try { saved = JSON.parse(sessionStorage.getItem(key) || 'null'); sessionStorage.removeItem(key); } catch (e) {}
		if (!saved || saved.path !== location.pathname || Date.now() - saved.t > 30000 || location.hash) { return; }
		if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
		var moved = false;
		function restore(){ if (!moved) { window.scrollTo(0, saved.y); } }
		restore();
		// Fonts and images can still change the height above the plan; stop correcting once the user scrolls
		window.addEventListener('wheel', function(){ moved = true; }, { passive: true, once: true });
		window.addEventListener('touchstart', function(){ moved = true; }, { passive: true, once: true });
		window.addEventListener('keydown', function(){ moved = true; }, { once: true });
		window.addEventListener('load', restore);
	})();

	// How-to card: closes with Esc or a click outside
	(function(){
		var help = document.querySelector('#cb-plan-root .cb-plan-help');
		if (!help) { return; }
		document.addEventListener('click', function(e){ if (help.open && !help.contains(e.target)) { help.open = false; } });
		document.addEventListener('keydown', function(e){
			if (e.key === 'Escape' && help.open) { help.open = false; help.querySelector('summary').focus(); }
		});
	})();
})();
