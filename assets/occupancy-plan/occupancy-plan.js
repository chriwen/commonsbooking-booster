/**
 * Occupancy plan (admin page and shortcode [cb_occupancy_plan]): search field with multiple selection, detail
 * panel, day explanation and tooltip. No dependencies. Configuration: #cb-plan-data (JSON).
 *
 * @see design/belegungsplan.md
 */
(function(){
	function el(tag, cls, text){
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}
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
				else if (!input.value.trim()) { form.submit(); }
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
})();

(function(){
	var data = document.getElementById('cb-plan-data');
	var root = document.querySelector('.cb-plan');
	if (!data || !root) { return; }
	var cfg = JSON.parse(data.textContent);
	var t = cfg.i18n;
	var panel = document.getElementById('cb-plan-panel');
	var body = panel.querySelector('.cb-plan-panel-body');
	var tip = document.getElementById('cb-plan-tip');
	// position: fixed must not depend on the theme (an ancestor with transform or overflow would clip it)
	document.body.appendChild(panel);
	document.body.appendChild(tip);
	var active = null;
	var request = 0;

	function el(tag, cls, text){
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}
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
	function renderDay(data){
		var nodes = [el('p', 'cb-plan-panel-type', t.day), el('h2', 'cb-plan-panel-title', data.date_label)];
		data.rows.forEach(function(row){
			var sec = el('section', 'cb-plan-day');
			if (data.rows.length > 1) { sec.appendChild(el('h3', null, row.title)); }
			var res = el('div', 'cb-plan-result is-' + row.result.tone);
			res.appendChild(el('span', 'cb-plan-result-label', t.calendar));
			res.appendChild(el('strong', null, row.result.label));
			row.result.notes.forEach(function(n){ res.appendChild(el('p', null, n)); });
			sec.appendChild(res);
			if (!row.reasons.length) {
				sec.appendChild(el('p', 'cb-plan-muted', t.noReasons));
			}
			row.reasons.forEach(function(r){
				var d = el('details', 'cb-plan-reason cb-plan-reason-' + r.kind);
				var s = el('summary');
				s.appendChild(el('span', 'cb-plan-swatch cb-plan-' + r.kind));
				s.appendChild(el('span', 'cb-plan-reason-type', r.type_label));
				s.appendChild(el('span', 'cb-plan-reason-title', r.title));
				d.appendChild(s);
				if (r.conflicts && r.conflicts.length) { d.appendChild(conflicts(r.conflicts)); d.open = true; }
				d.appendChild(details(r.details));
				if (r.edit_url) { d.appendChild(editLink(r.edit_url)); }
				sec.appendChild(d);
			});
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
	document.querySelector('.cb-plan-scroll').addEventListener('scroll', hideTip, {passive: true});
})();
