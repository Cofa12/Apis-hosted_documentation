<script>
    (function () {
        'use strict';

        /* ---------- theme ---------- */
        var root = document.documentElement;
        var STORAGE_KEY = 'api-docs-theme';

        function applyTheme(theme) {
            if (theme === 'light' || theme === 'dark') {
                root.setAttribute('data-theme', theme);
            } else {
                root.removeAttribute('data-theme');
            }
        }

        try {
            var stored = localStorage.getItem(STORAGE_KEY);
            if (stored) { applyTheme(stored); }
        } catch (e) { /* storage may be unavailable */ }

        var toggle = document.querySelector('[data-theme-toggle]');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var current = root.getAttribute('data-theme');
                if (!current) {
                    current = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                var next = current === 'dark' ? 'light' : 'dark';
                applyTheme(next);
                try { localStorage.setItem(STORAGE_KEY, next); } catch (e) {}
            });
        }

        /* ---------- copy buttons ---------- */
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-copy]');
            if (!button) { return; }

            var source = document.getElementById(button.getAttribute('data-copy'));
            if (!source) { return; }

            var text = source.innerText;
            var done = function () {
                var original = button.getAttribute('data-label') || button.textContent;
                button.setAttribute('data-label', original);
                button.textContent = 'Copied';
                setTimeout(function () { button.textContent = original; }, 1400);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {});
                return;
            }

            var area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            document.body.removeChild(area);
        });

        /* ---------- tabs ---------- */
        document.addEventListener('click', function (event) {
            var tab = event.target.closest('.tab');
            if (!tab) { return; }

            var group = tab.getAttribute('data-tab-group');
            var target = tab.getAttribute('data-tab');
            if (!group || !target) { return; }

            document.querySelectorAll('.tab[data-tab-group="' + group + '"]').forEach(function (item) {
                item.setAttribute('aria-selected', String(item === tab));
            });

            document.querySelectorAll('[data-panel-group="' + group + '"]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-panel') !== target;
            });
        });

        /* ---------- collapsing ---------- */
        document.addEventListener('click', function (event) {
            var head = event.target.closest('.op-head');
            if (head && !event.target.closest('a, button, input, textarea')) {
                head.parentElement.classList.toggle('collapsed');
                head.setAttribute('aria-expanded', String(!head.parentElement.classList.contains('collapsed')));
                return;
            }

            var groupToggle = event.target.closest('.nav-group > button');
            if (groupToggle) {
                var navGroup = groupToggle.parentElement;
                navGroup.classList.toggle('collapsed');
                groupToggle.setAttribute('aria-expanded', String(!navGroup.classList.contains('collapsed')));
            }
        });

        /* ---------- search ---------- */
        var search = document.getElementById('api-docs-search');
        var noResults = document.getElementById('api-docs-no-results');

        function filter(term) {
            term = term.trim().toLowerCase();
            var matches = 0;

            document.querySelectorAll('.group').forEach(function (group) {
                var visible = 0;

                group.querySelectorAll('.op').forEach(function (op) {
                    var haystack = (op.getAttribute('data-search') || '').toLowerCase();
                    var hit = term === '' || haystack.indexOf(term) !== -1;
                    op.hidden = !hit;
                    if (hit) { visible++; }
                });

                group.hidden = visible === 0;
                matches += visible;
            });

            document.querySelectorAll('.nav-items a').forEach(function (link) {
                var target = document.getElementById(link.getAttribute('href').slice(1));
                link.parentElement.hidden = !!(target && target.hidden);
            });

            document.querySelectorAll('.nav-group').forEach(function (navGroup) {
                var anyVisible = Array.prototype.slice.call(navGroup.querySelectorAll('.nav-items li'))
                    .some(function (item) { return !item.hidden; });
                navGroup.hidden = !anyVisible;
            });

            if (noResults) { noResults.hidden = matches !== 0; }
        }

        if (search) {
            search.addEventListener('input', function () { filter(search.value); });
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') { search.value = ''; filter(''); search.blur(); }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === '/' && search && document.activeElement !== search
                && !/^(INPUT|TEXTAREA)$/.test(document.activeElement.tagName)) {
                event.preventDefault();
                search.focus();
            }
        });

        /* ---------- active nav link ---------- */
        var links = {};
        document.querySelectorAll('.nav-items a').forEach(function (link) {
            links[link.getAttribute('href').slice(1)] = link;
        });

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var link = links[entry.target.id];
                    if (!link) { return; }
                    if (entry.isIntersecting) {
                        Object.keys(links).forEach(function (id) { links[id].classList.remove('active'); });
                        link.classList.add('active');
                    }
                });
            }, { rootMargin: '-80px 0px -70% 0px', threshold: 0 });

            document.querySelectorAll('.op').forEach(function (op) { observer.observe(op); });
        }

        /* ---------- try it ---------- */
        document.addEventListener('submit', function (event) {
            var form = event.target.closest('[data-tryit]');
            if (!form) { return; }
            event.preventDefault();

            var button = form.querySelector('button[type="submit"]');
            var output = form.querySelector('[data-tryit-output]');
            var status = form.querySelector('[data-tryit-status]');
            var pre = form.querySelector('[data-tryit-body]');

            var url = form.querySelector('[name="__url"]').value;
            var method = form.getAttribute('data-method') || 'GET';
            var headers = {};

            form.querySelectorAll('[data-header]').forEach(function (input) {
                if (input.value !== '') { headers[input.getAttribute('data-header')] = input.value; }
            });

            var options = { method: method, headers: headers };
            var bodyField = form.querySelector('[name="__body"]');

            if (bodyField && bodyField.value.trim() !== '' && method !== 'GET' && method !== 'HEAD') {
                options.body = bodyField.value;
            }

            button.disabled = true;
            output.hidden = false;
            status.innerHTML = '<span>Sending…</span>';
            pre.textContent = '';

            var started = Date.now();

            fetch(url, options).then(function (response) {
                return response.text().then(function (text) {
                    var elapsed = Date.now() - started;
                    var cls = response.ok ? 'ok' : 'err';
                    status.innerHTML = '<span class="' + cls + '">' + response.status + ' ' + response.statusText
                        + '</span> · ' + elapsed + ' ms';
                    try {
                        pre.textContent = JSON.stringify(JSON.parse(text), null, 2);
                    } catch (e) {
                        pre.textContent = text;
                    }
                });
            }).catch(function (error) {
                status.innerHTML = '<span class="err">Request failed</span>';
                pre.textContent = String(error && error.message ? error.message : error);
            }).finally(function () {
                button.disabled = false;
            });
        });

        document.addEventListener('click', function (event) {
            var reset = event.target.closest('[data-tryit-reset]');
            if (!reset) { return; }
            var form = reset.closest('[data-tryit]');
            if (form) {
                form.reset();
                var output = form.querySelector('[data-tryit-output]');
                if (output) { output.hidden = true; }
            }
        });

        /* ---------- deep link on load ---------- */
        if (window.location.hash) {
            var target = document.querySelector(window.location.hash);
            if (target && target.classList.contains('op')) {
                target.classList.remove('collapsed');
            }
        }
    })();
</script>
