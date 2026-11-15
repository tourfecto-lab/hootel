// lightbox for galleries + live price/availability quote in the booking form (no dependencies)
(function () {
  var links = [].slice.call(document.querySelectorAll('.gal a'));
  if (links.length) {
    var i = 0, lb = document.createElement('div');
    lb.className = 'lb';
    lb.innerHTML = '<button class="x" aria-label="Close">×</button><button class="p" aria-label="Previous">‹</button><img alt=""><button class="n" aria-label="Next">›</button>';
    document.body.appendChild(lb);
    var img = lb.querySelector('img');
    var show = function (n) { i = (n + links.length) % links.length; img.src = links[i].href; lb.classList.add('on'); };
    links.forEach(function (a, n) { a.addEventListener('click', function (e) { e.preventDefault(); show(n); }); });
    lb.addEventListener('click', function (e) {
      if (e.target.classList.contains('p')) show(i - 1);
      else if (e.target.classList.contains('n')) show(i + 1);
      else if (e.target !== img) lb.classList.remove('on');
    });
    document.addEventListener('keydown', function (e) {
      if (!lb.classList.contains('on')) return;
      if (e.key === 'Escape') lb.classList.remove('on');
      if (e.key === 'ArrowRight') show(i + 1);
      if (e.key === 'ArrowLeft') show(i - 1);
    });
  }

  var form = document.getElementById('bookform');
  if (!form) return;
  var box = document.getElementById('quote'), timer;
  var get = function (n) { return form.elements[n] ? form.elements[n].value : ''; };
  function refresh() {
    var r = get('room'), a = get('in'), b = get('out'), g = get('guests') || 1;
    if (!r || !a || !b || b <= a) { box.hidden = true; return; }
    clearTimeout(timer);
    timer = setTimeout(function () {
      fetch(form.dataset.quote + '?room=' + encodeURIComponent(r) + '&in=' + a + '&out=' + b + '&guests=' + g, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (d) {
          if (!d.ok) { box.hidden = true; return; }
          box.hidden = false;
          box.className = 'quote full ' + (d.available ? 'ok' : 'na');
          box.textContent = d.available
            ? form.dataset.total + ': ' + Number(d.total).toLocaleString() + ' ' + form.dataset.cur + ' (' + d.nights + ' ' + form.dataset.nights + ')'
            : form.dataset.na;
        }).catch(function () { box.hidden = true; });
    }, 250);
  }
  ['room', 'in', 'out', 'guests'].forEach(function (n) { if (form.elements[n]) form.elements[n].addEventListener('change', refresh); });
  // keep check-out after check-in
  form.elements['in'].addEventListener('change', function () {
    var o = form.elements['out']; o.min = this.value;
    if (o.value && o.value <= this.value) o.value = '';
  });
})();
