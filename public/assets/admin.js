// language tabs inside admin forms
document.addEventListener('click', function (e) {
  var b = e.target.closest('.tab');
  if (!b) return;
  var box = b.closest('.ltabs');
  var code = b.getAttribute('data-t');
  box.querySelectorAll('.tab').forEach(function (x) { x.classList.toggle('on', x === b); });
  box.querySelectorAll('.pane').forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-p') === code); });
});
