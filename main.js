'use strict';

// Настройки клуба. Ссылку на профиль MAX владелица берёт в приложении:
// «Профиль» → значок QR → «Скопировать ссылку» (вид https://max.ru/u/...).
// Пока ссылки нет, после заявки показываем поиск по номеру.
const CLUB = {
  maxProfile: '',
  maxPhone: '8 933 051-25-00',
};

// ---------- Город: ночной на первом экране, вечер и утро в карточках смен ----------
// Генератор с зерном: силуэт один и тот же при каждой загрузке
function rng(seed) {
  return () => {
    seed = (seed + 0x6D2B79F5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

// Дома считаются в масштабе первого экрана; карточки уменьшают готовый город через scale.
// o: W, H, seed, clubAt (где дом с короной, доля ширины), lit (доля горящих окон),
// twinkle (доля мигающих), delay0 и delaySpan (когда окна загораются)
function cityParts(o) {
  const { W, H } = o, r = rng(o.seed), clubX = W * o.clubAt;
  const parts = [];
  let x = -8;
  while (x < W + 8) {
    const w = 58 + Math.round(r() * 66);
    // дом клуба выше всех и с короной, как на логотипе
    const isClub = x <= clubX && x + w >= clubX;
    const h = isClub ? 168 : 62 + Math.round(r() * 98);
    const y = H - h;
    parts.push(`<rect class="bld" x="${x}" y="${y}" width="${w}" height="${h + 2}"/>`);
    if (isClub) {
      const cx = x + w / 2;
      parts.push(`<path class="crown" d="M${cx - 30} ${y - 4}l-6 -30 18 14 18 -24 18 24 18 -14 -6 30z"/>`);
      parts.push(`<path class="gem" d="M${cx} ${y - 24}l5 7 -5 7 -5 -7z"/>`);
    } else if (r() < 0.3) {
      parts.push(`<rect class="bld" x="${x + w / 2 - 2}" y="${y - 16}" width="4" height="16"/>`);
    } else if (r() < 0.3) {
      parts.push(`<rect class="bld" x="${x + 10}" y="${y - 12}" width="${w - 20}" height="12"/>`);
    }
    // окна сеткой, часть загорается по очереди
    const cols = Math.max(2, Math.floor((w - 14) / 18));
    const gap = (w - cols * 9) / (cols + 1);
    for (let wy = y + 14; wy < H - 18; wy += 21) {
      for (let c = 0; c < cols; c++) {
        const wx = (x + gap + c * (9 + gap)).toFixed(1);
        parts.push(`<rect class="win" x="${wx}" y="${wy}" width="9" height="12" rx="1.5"/>`);
        if (r() < o.lit) {
          const tw = r() < o.twinkle ? ' tw' : '';
          parts.push(`<rect class="lit${tw}" x="${wx}" y="${wy}" width="9" height="12" rx="1.5" style="--d:${(o.delay0 + r() * o.delaySpan).toFixed(2)}s"/>`);
        }
      }
    }
    x += w + (r() < 0.25 ? 5 : 0);
  }
  return parts.join('');
}

const skyline = document.querySelector('.skyline');
if (skyline) {
  skyline.innerHTML = cityParts({ W: 1440, H: 200, seed: 11, clubAt: 0.5, lit: 0.42, twinkle: 0.08, delay0: 0.3, delaySpan: 2.8 });
}

// Карточки смен: вечером свет горит почти везде, утром почти нигде
const SCENE_CITY = {
  evening: { seed: 7, clubAt: 0.66, lit: 0.5 },
  morning: { seed: 3, clubAt: 0.34, lit: 0.1 },
};
document.querySelectorAll('.scene .city').forEach((g) => {
  const k = 0.55, cfg = SCENE_CITY[g.dataset.city];
  g.setAttribute('transform', `scale(${k})`);
  g.innerHTML = cityParts({ W: 400 / k, H: 150 / k, ...cfg, twinkle: 0, delay0: 0.3, delaySpan: 1.4 });
});

// ---------- Появление при скролле ----------
// IntersectionObserver плюс ручной расчёт по скроллу: страховка, если IO молчит
const rvEls = [...document.querySelectorAll('.rv')];
function revealVisible() {
  const edge = innerHeight * 0.94;
  for (const el of rvEls) {
    if (!el.classList.contains('in') && el.getBoundingClientRect().top < edge) el.classList.add('in');
  }
}
if (rvEls.length) {
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      for (const e of entries) {
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      }
    }, { rootMargin: '0px 0px -6% 0px' });
    rvEls.forEach((el) => io.observe(el));
  }
  addEventListener('scroll', revealVisible, { passive: true });
  addEventListener('resize', revealVisible);
  revealVisible();
}

// ---------- Форма записи ----------
const form = document.getElementById('bookForm');

// Кнопки «Записаться» в карточках выбирают нужный пункт в форме
document.querySelectorAll('a[data-want]').forEach((a) => {
  a.addEventListener('click', () => {
    if (!form) return;
    const radio = form.querySelector(`input[name="want"][value="${a.dataset.want}"]`);
    if (radio) radio.checked = true;
  });
});

function phoneDigits(v) {
  let d = v.replace(/\D/g, '');
  if (d.length === 11 && (d[0] === '8' || d[0] === '7')) d = d.slice(1);
  return d;
}
function formatPhone(d) {
  return `+7 ${d.slice(0, 3)} ${d.slice(3, 6)}-${d.slice(6, 8)}-${d.slice(8, 10)}`;
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (_) { /* нет https или запрет браузера: пробуем старый способ */ }
  try {
    const t = document.createElement('textarea');
    t.value = text;
    t.setAttribute('readonly', '');
    t.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.append(t);
    t.select();
    const ok = document.execCommand('copy');
    t.remove();
    return ok;
  } catch (_) {
    return false;
  }
}

if (form) {
  const f = form.elements;
  const sentText = document.getElementById('sentText');
  const stepCopy = document.getElementById('stepCopy');
  const stepOpen = document.getElementById('stepOpen');
  const maxLink = document.getElementById('maxLink');
  const sent = document.getElementById('sent');

  if (CLUB.maxProfile) {
    maxLink.href = CLUB.maxProfile;
    maxLink.hidden = false;
    stepOpen.textContent = 'Нажмите «Открыть чат в MAX».';
  }

  function setError(input, id, msg) {
    document.getElementById(id).textContent = msg;
    if (input.type !== 'checkbox') input.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }

  function validate() {
    let first = null;
    const name = f.name.value.trim();
    setError(f.name, 'e-name', name ? '' : 'Напишите, как к вам обращаться');
    if (!name) first = first || f.name;

    const digits = phoneDigits(f.phone.value);
    const phoneOk = digits.length === 10;
    setError(f.phone, 'e-phone', phoneOk ? '' : 'Нужен номер из 10 цифр после +7 или 8');
    if (!phoneOk) first = first || f.phone;

    setError(f.agree, 'e-agree', f.agree.checked ? '' : 'Без согласия заявку отправить нельзя');
    if (!f.agree.checked) first = first || f.agree;

    if (first) first.focus();
    return !first;
  }

  // ошибку снимаем, как только поле исправили
  form.addEventListener('input', (e) => {
    const map = { name: 'e-name', phone: 'e-phone', agree: 'e-agree' };
    const id = map[e.target.name];
    if (id && document.getElementById(id).textContent) setError(e.target, id, '');
  });
  form.addEventListener('change', (e) => {
    if (e.target.name === 'agree' && e.target.checked) setError(e.target, 'e-agree', '');
  });
  f.phone.addEventListener('blur', () => {
    const d = phoneDigits(f.phone.value);
    if (d.length === 10) f.phone.value = formatPhone(d);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!validate()) return;

    const lines = [
      'Здравствуйте! Заявка с сайта «Мафия.Дети».',
      `Интересует: ${f.want.value}`,
      `Адрес: ${f.place.value}`,
      `Родитель: ${f.name.value.trim()}`,
      `Телефон: ${formatPhone(phoneDigits(f.phone.value))}`,
    ];
    const child = f.child.value.trim();
    const note = f.note.value.trim();
    if (child) lines.push(`Ребёнок: ${child}`);
    if (note) lines.push(`Комментарий: ${note}`);
    const text = lines.join('\n');

    sentText.value = text;
    const ok = await copyText(text);
    stepCopy.textContent = ok
      ? 'Текст заявки уже скопирован.'
      : 'Скопируйте текст заявки из поля ниже.';
    form.classList.add('is-sent');
    sent.focus({ preventScroll: true });
    form.scrollIntoView({ block: 'start' });
  });

  document.getElementById('copyAgain').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const ok = await copyText(sentText.value);
    if (!ok) { sentText.focus(); sentText.select(); return; }
    const label = btn.lastChild;
    const prev = label.textContent;
    label.textContent = ' Скопировано';
    setTimeout(() => { label.textContent = prev; }, 1800);
  });

  document.getElementById('editAgain').addEventListener('click', () => {
    form.classList.remove('is-sent');
    f.name.focus();
  });
}

// ---------- Cookie ----------
const COOKIE_KEY = 'md-cookie';
const banner = document.getElementById('cookie');

function readConsent() {
  try { return localStorage.getItem(COOKIE_KEY); } catch (_) { return null; }
}
function saveConsent(v) {
  try { localStorage.setItem(COOKIE_KEY, v); } catch (_) { /* приватный режим: просто скрываем баннер */ }
}
// Аналитики пока нет. Если подключат Метрику, грузить её здесь и только при «all»
function loadAnalytics() {}

if (banner) {
  const saved = readConsent();
  if (!saved) banner.classList.add('show');
  if (saved === 'all') loadAnalytics();

  banner.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cookie]');
    if (!b) return;
    saveConsent(b.dataset.cookie);
    banner.classList.remove('show');
    if (b.dataset.cookie === 'all') loadAnalytics();
  });
  document.querySelectorAll('[data-cookie-open]').forEach((b) => {
    b.addEventListener('click', () => banner.classList.add('show'));
  });
}

// ---------- Мелочи ----------
document.querySelectorAll('[data-year]').forEach((el) => { el.textContent = new Date().getFullYear(); });
