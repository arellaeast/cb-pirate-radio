/* CB Pirate Radio tuner.
 * Polls the WP now-playing proxy (never AzuraCast directly) and drives one audio element.
 */
(function () {
	'use strict';

	var root = document.getElementById('cb-tuner');
	if (!root || typeof CB_RADIO === 'undefined') return;

	var audio = document.getElementById('cb-audio');
	var playBtn = document.getElementById('cb-play');
	var volume = document.getElementById('cb-volume');

	var channels = (CB_RADIO.channels || []).slice();
	var dial = {
		prev: document.getElementById('cb-seek-prev'),
		next: document.getElementById('cb-seek-next'),
		label: document.getElementById('cb-display-label'),
		digits: document.getElementById('cb-display-freq'),
		needle: document.getElementById('cb-scale-needle'),
		presets: root.querySelectorAll('.cb-tuner__preset[data-channel]'),
		more: root.querySelectorAll('.cb-tuner__more-list button[data-channel]')
	};

	var el = {
		art: document.getElementById('cb-np-art'),
		artFallback: document.getElementById('cb-np-art-fallback'),
		title: document.getElementById('cb-np-title'),
		artist: document.getElementById('cb-np-artist'),
		hood: document.getElementById('cb-np-hood'),
		listeners: document.getElementById('cb-np-listeners'),
		votes: document.getElementById('cb-votes'),
		voteUp: document.getElementById('cb-vote-up'),
		voteDown: document.getElementById('cb-vote-down')
	};

	var state = { channel: null, stream: '', timer: null, submissionId: 0, myVote: 0, hls: null, loadedStream: '', index: 0 };
	var triedFallback = false; // per-stream retry guard; reset on channel change

	/**
	 * Load a stream into the shared <audio> element regardless of format.
	 * - .m3u8 (HLS): Safari plays it natively; everywhere else needs hls.js.
	 * - mp3/aac/ogg/opus/webm and anything without a recognizable HLS signature:
	 *   handed straight to <audio src>, which is the native path for all of those.
	 * A source that looks like a direct file but is actually an HLS redirect
	 * (some Icecast/AzuraCast mounts do this) still gets one retry through hls.js
	 * if native playback errors out — see the audio 'error' listener below.
	 */
	function loadStream(url, thenPlay) {
		if (!url) return;

		if (state.hls) {
			state.hls.destroy();
			state.hls = null;
		}

		var isHls = /\.m3u8($|\?)/i.test(url);
		var nativeHlsSupport = audio.canPlayType('application/vnd.apple.mpegurl') !== '';

		if (isHls && !nativeHlsSupport && window.Hls && window.Hls.isSupported()) {
			state.hls = new window.Hls();
			state.hls.loadSource(url);
			state.hls.attachMedia(audio);
			if (thenPlay) {
				state.hls.once(window.Hls.Events.MANIFEST_PARSED, function () {
					audio.play().then(function () { setPlaying(true); }).catch(function () { setPlaying(false); });
				});
			}
		} else {
			// Native path: works for mp3/aac/ogg directly, and for HLS on Safari.
			audio.src = url;
			if (thenPlay) {
				audio.play().then(function () { setPlaying(true); }).catch(function () { setPlaying(false); });
			}
		}
		state.loadedStream = url;
	}

	function tuneIndex(i) {
		if (!channels.length) return;
		i = ( i % channels.length + channels.length ) % channels.length; // wrap both directions
		var c = channels[i];
		var wasPlaying = !audio.paused;

		state.index = i;
		state.channel = c.slug;
		state.stream = c.stream || '';

		if (dial.digits) dial.digits.textContent = c.freq;
		if (dial.label) dial.label.textContent = c.label;
		if (dial.needle) {
			var pct = channels.length > 1 ? ( i / ( channels.length - 1 ) ) * 100 : 0;
			dial.needle.style.left = pct + '%';
		}

		dial.presets.forEach(function (btn) {
			btn.classList.toggle('is-tuned', btn.dataset.channel === c.slug);
		});
		dial.more.forEach(function (btn) {
			btn.classList.toggle('is-tuned', btn.dataset.channel === c.slug);
		});

		if (wasPlaying && state.stream) {
			loadStream(state.stream, true);
		}
		triedFallback = false;

		fetchNowPlaying();
		restartPolling();
	}

	function indexForSlug(slug) {
		for (var i = 0; i < channels.length; i++) {
			if (channels[i].slug === slug) return i;
		}
		return -1;
	}

	function fetchNowPlaying() {
		if (!state.channel) return;
		fetch(CB_RADIO.apiBase + state.channel)
			.then(function (r) { return r.json(); })
			.then(render)
			.catch(function () { /* keep last known state */ });
	}

	function render(np) {
		if (!np || np.channel !== state.channel) return;

		el.title.textContent = (np.song && np.song.title) || '—';

		var artistName = (np.artist && np.artist.name) || (np.song && np.song.artist) || '—';
		el.artist.textContent = artistName;
		el.artist.href = (np.artist && np.artist.url) || '#';

		el.hood.textContent = (np.artist && np.artist.hood) ? 'Reps: ' + np.artist.hood : '';
		el.listeners.textContent = typeof np.listeners === 'number'
			? np.listeners + ' aboard' + (np.demo ? ' · DEMO SIGNAL' : '')
			: '';

		var art = (np.song && np.song.art) || (np.artist && np.artist.photo) || '';
		if (art) {
			el.art.src = art;
			el.art.hidden = false;
			el.artFallback.hidden = true;
		} else {
			el.art.hidden = true;
			el.artFallback.hidden = false;
		}

		// Voting — restore this browser's own vote for whatever's now
		// playing (never an aggregate count; see fetchMyVote).
		if (el.votes) {
			var newId = np.submission_id || 0;
			if (newId !== state.submissionId) {
				state.submissionId = newId;
				state.myVote = 0;
				paintMyVote(); // clear immediately; fetchMyVote may override once it resolves
				if (newId) fetchMyVote(newId);
			}
			var votable = state.submissionId > 0;
			el.voteUp.disabled = !votable;
			el.voteDown.disabled = !votable;
			el.votes.title = votable ? '' : 'Voting opens when a real track is on the air';
		}
	}

	function paintMyVote() {
		el.voteUp.classList.toggle('is-mine', state.myVote === 1);
		el.voteDown.classList.toggle('is-mine', state.myVote === -1);
		el.voteUp.setAttribute('aria-pressed', state.myVote === 1 ? 'true' : 'false');
		el.voteDown.setAttribute('aria-pressed', state.myVote === -1 ? 'true' : 'false');
	}

	// Per-visitor, never cached server-side — this is the only vote info
	// that ever reaches the browser. No one, including the voter themselves,
	// sees how anyone else voted.
	function fetchMyVote(submissionId) {
		if (!CB_RADIO.myVoteUrl) return;
		fetch(CB_RADIO.myVoteUrl + submissionId)
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (state.submissionId !== submissionId) return; // track changed again before this resolved
				state.myVote = (res && res.vote) || 0;
				paintMyVote();
			})
			.catch(function () {});
	}

	function castVote(v) {
		if (!state.submissionId) return;
		var submissionId = state.submissionId;
		fetch(CB_RADIO.voteUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CB_RADIO.nonce
			},
			body: JSON.stringify({ submission_id: submissionId, vote: v })
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.ok || state.submissionId !== submissionId) return;
				state.myVote = v;
				paintMyVote();
			})
			.catch(function () {});
	}

	function setPlaying(on) {
		playBtn.classList.toggle('is-playing', on);
		playBtn.textContent = on ? '■' : '▶';
	}

	function restartPolling() {
		clearInterval(state.timer);
		state.timer = setInterval(fetchNowPlaying, CB_RADIO.poll || 15000);
	}

	if (dial.prev) dial.prev.addEventListener('click', function () { tuneIndex(state.index - 1); });
	if (dial.next) dial.next.addEventListener('click', function () { tuneIndex(state.index + 1); });

	dial.presets.forEach(function (btn) {
		btn.addEventListener('click', function () {
			var i = indexForSlug(btn.dataset.channel);
			if (i > -1) tuneIndex(i);
		});
	});
	dial.more.forEach(function (btn) {
		btn.addEventListener('click', function () {
			var i = indexForSlug(btn.dataset.channel);
			if (i > -1) tuneIndex(i);
		});
	});

	playBtn.addEventListener('click', function () {
		if (audio.paused) {
			if (!state.stream) return; // no stream_url configured yet (placeholder mode)
			if (state.loadedStream !== state.stream || !audio.src) {
				loadStream(state.stream, true);
			} else {
				audio.play().then(function () { setPlaying(true); }).catch(function () { setPlaying(false); });
			}
		} else {
			audio.pause();
			if (state.hls) {
				state.hls.destroy();
				state.hls = null;
			}
			audio.removeAttribute('src'); // stop buffering a live stream
			audio.load();
			state.loadedStream = '';
			setPlaying(false);
		}
	});

	volume.addEventListener('input', function () {
		audio.volume = parseFloat(volume.value);
	});
	audio.volume = parseFloat(volume.value);

	// If a stream's extension lied (a "mp3" mount that's actually serving HLS,
	// or vice versa), one retry through the other path before giving up quietly.
	audio.addEventListener('error', function () {
		if (!triedFallback && state.stream && window.Hls && window.Hls.isSupported() && !state.hls) {
			triedFallback = true;
			state.hls = new window.Hls();
			state.hls.loadSource(state.stream);
			state.hls.attachMedia(audio);
			state.hls.once(window.Hls.Events.MANIFEST_PARSED, function () {
				audio.play().then(function () { setPlaying(true); }).catch(function () { setPlaying(false); });
			});
			state.hls.on(window.Hls.Events.ERROR, function (evt, data) {
				if (data.fatal) { setPlaying(false); }
			});
			return;
		}
		setPlaying(false);
	});

	if (el.voteUp) {
		el.voteUp.addEventListener('click', function () { castVote(1); });
		el.voteDown.addEventListener('click', function () { castVote(-1); });
	}

	// Boot: tune to the first channel.
	if (channels.length) tuneIndex(0);
})();

/* ── The Captain's Top 10 ─────────────────────────────────────────── */
(function () {
	'use strict';

	var root = document.getElementById('cb-charts');
	if (!root || typeof CB_RADIO === 'undefined' || !CB_RADIO.chartBase) return;

	var list = document.getElementById('cb-charts-list');
	var demoFlag = document.getElementById('cb-charts-demo');
	var tabs = root.querySelectorAll('.cb-charts__toggle [data-period]');

	function esc(s) {
		var d = document.createElement('span');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	function load(period) {
		tabs.forEach(function (t) {
			t.classList.toggle('is-active', t.dataset.period === period);
		});
		fetch(CB_RADIO.chartBase + period)
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.entries) return;
				demoFlag.hidden = !data.demo;
				list.innerHTML = data.entries.map(function (e) {
					var artistName = e.artist ? e.artist.name : '';
					var artistUrl = e.artist ? e.artist.url : '#';
					var art = e.art
						? '<img src="' + esc(e.art) + '" alt="" loading="lazy">'
						: '<span class="cb-charts__art-fallback">🏴‍☠️</span>';
					return (
						'<li class="cb-charts__row">' +
							'<span class="cb-charts__rank">' + e.rank + '</span>' +
							'<span class="cb-charts__art">' + art + '</span>' +
							'<span class="cb-charts__info">' +
								'<span class="cb-charts__track">' + esc(e.title) + '</span>' +
								'<a class="cb-charts__artist" href="' + esc(artistUrl) + '">' + esc(artistName) + '</a>' +
								'<span class="cb-charts__channel">' + esc(e.channel) + '</span>' +
							'</span>' +
							'<span class="cb-charts__score" title="' + e.up + ' up / ' + e.down + ' down">' +
								(e.score > 0 ? '+' : '') + e.score +
							'</span>' +
						'</li>'
					);
				}).join('');
			})
			.catch(function () {
				list.innerHTML = '<li class="cb-charts__loading">Signal lost — try again.</li>';
			});
	}

	tabs.forEach(function (t) {
		t.addEventListener('click', function () { load(t.dataset.period); });
	});

	load(root.dataset.period || 'week');
})();

/* ── Display options: visitor theme + text size (localStorage) ────── */
(function () {
	'use strict';

	if (typeof CB_RADIO_DISPLAY === 'undefined') return;

	var STORE_KEY = 'cbRadioDisplay';
	var containers = document.querySelectorAll('.cb-tuner, .cb-charts');
	if (!containers.length) return;

	// The server already picked a press effect (the site's active theme) and
	// printed it as data-press on each container. Capture that now, before any
	// override, so "Use site default" can restore it — removeAttribute would
	// erase it entirely and silently fall through to "no press effect".
	var defaultPress = [];
	containers.forEach(function (c) { defaultPress.push(c.getAttribute('data-press') || 'scale'); });

	var L = CB_RADIO_DISPLAY.labels;

	function prefs() {
		try {
			return JSON.parse(localStorage.getItem(STORE_KEY)) || {};
		} catch (e) { return {}; }
	}

	function savePrefs(p) {
		try {
			if (Object.keys(p).length) localStorage.setItem(STORE_KEY, JSON.stringify(p));
			else localStorage.removeItem(STORE_KEY);
		} catch (e) { /* private mode etc. — session-only is fine */ }
	}

	function themeById(id) {
		for (var i = 0; i < CB_RADIO_DISPLAY.themes.length; i++) {
			if (CB_RADIO_DISPLAY.themes[i].id === id) return CB_RADIO_DISPLAY.themes[i];
		}
		return null;
	}

	function apply() {
		var p = prefs();
		var theme = p.theme ? themeById(p.theme) : null;
		var fs = p.fs || null;

		containers.forEach(function (c, i) {
			// Clear inline overrides first so "site default" truly falls through
			// to the admin stylesheet.
			['hull', 'panel', 'brass', 'signal', 'ink', 'ink-dim', 'border'].forEach(function (v) {
				c.style.removeProperty('--cb-' + v);
			});
			['--cb-fs', '--cb-bw', '--cb-border-style', '--cb-radius', '--cb-radius-sm', '--cb-hover', '--cb-font'].forEach(function (v) {
				c.style.removeProperty(v);
			});
			c.setAttribute('data-press', defaultPress[i]); // reset target, not removed

			if (theme) {
				Object.keys(theme.colors).forEach(function (slot) {
					c.style.setProperty('--cb-' + slot.replace('_', '-'), theme.colors[slot]);
				});
				if (theme.border) {
					if (theme.border.width != null) c.style.setProperty('--cb-bw', theme.border.width + 'px');
					if (theme.border.style) c.style.setProperty('--cb-border-style', theme.border.style);
					if (theme.border.radius != null) c.style.setProperty('--cb-radius', theme.border.radius + 'px');
					if (theme.border.radius_sm != null) c.style.setProperty('--cb-radius-sm', theme.border.radius_sm + 'px');
				}
				if (theme.hover != null) c.style.setProperty('--cb-hover', theme.hover);
				if (theme.press) c.setAttribute('data-press', theme.press);
				if (theme.fontStack) c.style.setProperty('--cb-font', theme.fontStack);
			}
			if (fs) c.style.setProperty('--cb-fs', fs);
		});
	}

	function buildPanel(anchor) {
		var p = prefs();
		var panel = document.createElement('div');
		panel.className = 'cb-display-panel';
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', L.display);

		var themeOptions = CB_RADIO_DISPLAY.themes.map(function (t) {
			var sel = (p.theme || CB_RADIO_DISPLAY.defaultTheme) === t.id ? ' selected' : '';
			return '<option value="' + t.id + '"' + sel + '>' + t.name + '</option>';
		}).join('');

		var curFs = p.fs || CB_RADIO_DISPLAY.defaultTextSize;
		function fsBtn(val, label) {
			return '<button type="button" data-fs="' + val + '" class="' + (curFs === val ? 'is-active' : '') + '">' + label + '</button>';
		}

		panel.innerHTML =
			'<label>' + L.theme + '<br><select class="cb-display-theme">' + themeOptions + '</select></label>' +
			'<div class="cb-display-fs"><span>' + L.textSize + '</span><div>' +
				fsBtn('1', L.normal) + fsBtn('1.2', L.large) + fsBtn('1.4', L.xlarge) +
			'</div></div>' +
			'<button type="button" class="cb-display-reset">' + L.reset + '</button>';

		panel.querySelector('.cb-display-theme').addEventListener('change', function () {
			var pr = prefs();
			pr.theme = this.value;
			savePrefs(pr);
			apply();
		});

		panel.querySelectorAll('[data-fs]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var pr = prefs();
				pr.fs = btn.dataset.fs;
				savePrefs(pr);
				apply();
				panel.querySelectorAll('[data-fs]').forEach(function (b) {
					b.classList.toggle('is-active', b === btn);
				});
			});
		});

		panel.querySelector('.cb-display-reset').addEventListener('click', function () {
			savePrefs({});
			apply();
			close();
		});

		function close() {
			panel.remove();
			anchor.setAttribute('aria-expanded', 'false');
			document.removeEventListener('click', onDoc, true);
		}
		function onDoc(e) {
			if (!panel.contains(e.target) && e.target !== anchor) close();
		}
		document.addEventListener('click', onDoc, true);
		panel.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { close(); anchor.focus(); }
		});

		anchor.insertAdjacentElement('afterend', panel);
		anchor.setAttribute('aria-expanded', 'true');
		panel.querySelector('select').focus();
		return panel;
	}

	document.querySelectorAll('.cb-display-toggle').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var existing = btn.nextElementSibling;
			if (existing && existing.classList.contains('cb-display-panel')) {
				existing.remove();
				btn.setAttribute('aria-expanded', 'false');
				return;
			}
			buildPanel(btn);
		});
	});

	apply();
})();
