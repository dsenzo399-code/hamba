const Hamba = {
  csrf: '',
  token: localStorage.getItem('hamba_token') || '',
  user: null,

  async api(route, { method = 'GET', body, form } = {}) {
    const headers = { Accept: 'application/json' };
    if (this.csrf) headers['X-CSRF-Token'] = this.csrf;
    if (this.token) headers.Authorization = 'Bearer ' + this.token;
    let payload;
    if (form) {
      payload = { method, headers, body: form };
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = { method, headers, body: JSON.stringify(body) };
    } else {
      payload = { method, headers };
    }
    const root = document.documentElement.dataset.root || './';
    const [path, qs] = route.split('?');
    const url = root + 'api/index.php?route=' + encodeURIComponent(path) + (qs ? '&' + qs : '');
    const res = await fetch(url, payload);
    const data = await res.json().catch(() => ({ ok: false, error: 'Bad response' }));
    if (data.csrf) this.csrf = data.csrf;
    if (data.token) {
      this.token = data.token;
      localStorage.setItem('hamba_token', data.token);
    }
    if (data.user) this.user = data.user;
    if (!data.ok && res.status === 401) {
      if (!route.startsWith('auth/')) location.href = (document.documentElement.dataset.root || './') + 'login.php';
    }
    return data;
  },

  async boot() {
    const c = await this.api('auth/csrf');
    this.csrf = c.csrf || this.csrf;
    const me = await this.api('auth/me');
    return me;
  },

  fileUrl(path) {
    const root = document.documentElement.dataset.root || './';
    return path ? root + 'file.php?p=' + encodeURIComponent(path) : '';
  }
};

function $(sel, root = document) { return root.querySelector(sel); }
function $$(sel, root = document) { return [...root.querySelectorAll(sel)]; }

function toast(msg) {
  let el = $('#toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'toast';
    el.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:#14241c;color:#fff;padding:10px 16px;border-radius:999px;z-index:9999;font-weight:600';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.style.display = 'block';
  setTimeout(() => { el.style.display = 'none'; }, 2800);
}

function watchGps(onPos, onErr) {
  if (!navigator.geolocation) {
    onErr && onErr(new Error('Geolocation is not available in this browser.'));
    return null;
  }
  return navigator.geolocation.watchPosition(
    (p) => onPos({ lat: p.coords.latitude, lng: p.coords.longitude, heading: p.coords.heading, speed: p.coords.speed }),
    (err) => onErr && onErr(err),
    { enableHighAccuracy: true, maximumAge: 2000, timeout: 15000 }
  );
}
