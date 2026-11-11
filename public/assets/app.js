const TEXT_MAX = 1000;
const MAX_KEPT = 1000;
const BACKOFF = [1000, 2000, 4000, 8000, 15000];
const TITLE = 'Комната — онлайн-чат';

new Vue({
  el: '#app',
  data: {
    csrf: '', ready: false, me: '', conn: '', joined: false, busy: false, sending: false, error: '',
    nameInput: '', text: '', messages: [], users: [], online: true, loaded: false,
    unread: 0, hiddenUnread: 0, atBottom: true, showUsers: false, max: TEXT_MAX,
  },
  computed: {
    len() { return [...this.text].length; },
    tooLong() { return this.len > TEXT_MAX; },
    canSend() { return !this.sending && !this.tooLong && this.text.trim().length > 0; },
    rows() {
      const out = [];
      let day = '';
      for (const m of this.messages) {
        const d = new Date(m.ts);
        const key = d.toDateString();
        if (key !== day) {
          day = key;
          out.push({ type: 'day', id: 'day-' + key, label: this.dayLabel(d) });
        }
        out.push({ ...m, iso: d.toISOString(), time: d.toLocaleTimeString('ru-RU') });
      }
      return out;
    },
  },
  watch: {
    text() { this.error = ''; },
    nameInput() { this.error = ''; },
  },
  created() {
    this.ids = new Set();
    this.hues = new Map();
    this.attempts = 0;
    this.es = null;
    addEventListener('pagehide', this.leaveBeacon);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) { this.hiddenUnread = 0; this.updateTitle(); }
    });
    this.boot();
  },
  methods: {
    // ---------- сеть ----------
    async api(method, action, body) {
      try {
        const r = await fetch('api.php?action=' + action, {
          method,
          headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf } : {},
          body: body ? JSON.stringify(body) : undefined,
          credentials: 'same-origin',
        });
        let data = {};
        try { data = await r.json(); } catch (e) { /* не JSON */ }
        return { ok: r.ok, status: r.status, data };
      } catch (e) {
        return { ok: false, status: 0, data: {} };
      }
    },
    sleep(ms) { return new Promise(res => setTimeout(res, ms)); },

    async boot() {
      for (let i = 0; ; i++) {
        const r = await this.api('GET', 'state');
        if (r.ok) {
          this.csrf = r.data.csrf;
          this.ready = true;
          this.error = '';
          if (r.data.name) { this.me = r.data.name; this.joined = true; this.connect(); }
          return;
        }
        this.error = 'Нет связи с сервером. Повторяем…';
        await this.sleep(BACKOFF[Math.min(i, BACKOFF.length - 1)]);
      }
    },
    async login() {
      this.error = ''; this.busy = true;
      const r = await this.api('POST', 'login', { name: this.nameInput });
      this.busy = false;
      if (!r.ok) { this.error = r.data.error || 'Нет связи с сервером'; return; }
      this.csrf = r.data.csrf; this.me = r.data.name; this.joined = true;
      this.attempts = 0;
      this.connect();
    },
    async logout() {
      if (this.es) this.es.close();
      await this.api('POST', 'logout', { conn: this.conn });
      this.reset();
      this.nameInput = this.me;
    },
    reset() {
      this.es = null; this.conn = ''; this.joined = false; this.online = true; this.loaded = false;
      this.messages = []; this.users = []; this.ids.clear(); this.unread = 0; this.hiddenUnread = 0;
      this.showUsers = false; this.updateTitle();
    },
    leaveBeacon() {
      if (this.es) this.es.close();
      if (this.conn) {
        navigator.sendBeacon('api.php?action=leave', new Blob([JSON.stringify({ csrf: this.csrf, conn: this.conn })], { type: 'application/json' }));
      }
    },

    // ---------- поток ----------
    connect() {
      const es = this.es = new EventSource('api.php?action=stream');
      es.onopen = () => { this.online = true; this.attempts = 0; };
      es.addEventListener('ready', e => { this.conn = JSON.parse(e.data).conn; });
      es.addEventListener('history', e => {
        this.addMany(JSON.parse(e.data));
        this.loaded = true;
        this.toBottom(false);
      });
      es.addEventListener('message', e => this.addMany([JSON.parse(e.data)], true));
      es.addEventListener('users', e => { this.users = JSON.parse(e.data); });
      es.onerror = () => {
        this.online = false;
        if (es.readyState === EventSource.CLOSED) this.recover(es); // иначе браузер переподключится сам
      };
    },
    async recover(es) {
      if (this.es !== es) return;
      // Различаем «нет связи» и «сервер отказал»: смотрим, отвечает ли сервер.
      for (let i = 0; ; i++) {
        const r = await this.api('GET', 'state');
        if (this.es !== es) return;
        if (r.ok) {
          this.csrf = r.data.csrf;
          if (!r.data.name) return this.kicked('Сессия закончилась. Войдите заново.');
          if (++this.attempts > 3) return this.kicked('Не удалось войти. Возможно, имя уже занято.');
          this.connect();
          return;
        }
        await this.sleep(BACKOFF[Math.min(i, BACKOFF.length - 1)]);
      }
    },
    kicked(msg) {
      const keep = this.me;
      this.reset();
      this.nameInput = keep;
      this.error = msg;
    },

    // ---------- лента ----------
    addMany(list, live = false) {
      const fresh = list.filter(m => !this.ids.has(m.id));
      if (!fresh.length) return;
      fresh.forEach(m => this.ids.add(m.id));
      this.messages.push(...fresh);
      if (this.messages.length > MAX_KEPT) {
        this.messages.splice(0, this.messages.length - MAX_KEPT).forEach(m => this.ids.delete(m.id));
      }
      if (!live) return;
      if (document.hidden) { this.hiddenUnread += fresh.length; this.updateTitle(); }
      if (this.atBottom) this.toBottom(false); else this.unread += fresh.length;
    },
    onScroll() {
      const f = this.$refs.feed;
      this.atBottom = f.scrollHeight - f.scrollTop - f.clientHeight < 40;
      if (this.atBottom) this.unread = 0;
    },
    toBottom(smooth) {
      this.$nextTick(() => {
        const f = this.$refs.feed;
        if (!f) return;
        f.scrollTo({ top: f.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        this.atBottom = true; this.unread = 0;
      });
    },
    updateTitle() {
      document.title = this.hiddenUnread ? `(${this.hiddenUnread}) ${TITLE}` : TITLE;
    },

    // ---------- ввод ----------
    onEnter(e) {
      if (e.isComposing) return; // подтверждение IME-композиции
      e.preventDefault();
      this.send();
    },
    async send() {
      if (!this.canSend) return;
      this.sending = true; this.error = '';
      const r = await this.api('POST', 'send', { text: this.text.trim() });
      this.sending = false;
      if (r.ok) this.text = ''; else this.error = r.data.error || 'Нет связи с сервером';
      this.$nextTick(() => this.$refs.text && this.$refs.text.focus());
      if (r.ok) this.toBottom(false);
    },

    // ---------- оформление ----------
    hue(name) {
      let h = this.hues.get(name);
      if (h === undefined) {
        h = 0;
        for (const c of name.toLowerCase()) h = (h * 31 + c.codePointAt(0)) % 360;
        this.hues.set(name, h);
      }
      return h;
    },
    initial(name) { return [...name][0].toUpperCase(); },
    dayLabel(d) {
      const today = new Date().toDateString();
      const yest = new Date(Date.now() - 864e5).toDateString();
      if (d.toDateString() === today) return 'Сегодня';
      if (d.toDateString() === yest) return 'Вчера';
      return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
    },
  },
});
