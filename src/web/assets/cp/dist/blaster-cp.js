/**
 * Blaster's bar editor.
 *
 * Two jobs: showing only the fields that the current choices make meaningful, and keeping the
 * live preview in step with the form.
 *
 * The preview is rendered on the server by the same service that serves the front end, rather
 * than being rebuilt here from the form fields. Redrawing it in JavaScript would mean two
 * implementations of the same CSS generation, and the one that drifts is always the one the
 * author is looking at when they pick a colour.
 */
(function () {
  'use strict';

  // The editor's own wrapper is a div, not a form — the control panel already wraps the whole
  // page in one, and a nested `<form>` would be dropped by the parser while its children stayed,
  // leaving a second `action` input next to the page's own. So take the page form, and read the
  // editor's fields out of it.
  var editor = document.getElementById('blaster-bar-form');
  if (!editor) return;

  var form = editor.closest('form');
  if (!form) return;

  // ---------------------------------------------------------------- conditional fields

  function currentValue(name) {
    var field = form.querySelector('[name="' + name + '"]');
    if (!field) return null;

    if (field.type === 'checkbox') return field.checked ? '1' : '';

    // Craft's lightswitch keeps its value in a hidden input the switch writes to.
    return field.value;
  }

  function syncConditionals() {
    Array.prototype.forEach.call(form.querySelectorAll('[data-when]'), function (el) {
      var value = currentValue(el.getAttribute('data-when'));
      var wanted = (el.getAttribute('data-is') || '').split('|');
      var negate = el.hasAttribute('data-not');
      var matched = wanted.indexOf(value) !== -1;

      el.classList.toggle('hidden', negate ? matched : !matched);
    });
  }

  form.addEventListener('change', function () {
    syncConditionals();
    queuePreview();
  });

  form.addEventListener('input', queuePreview);

  // Craft's lightswitch writes to a hidden input from JavaScript, and setting `value` in script
  // fires no native `change` event — so the switches would silently never reach the handler above.
  form.addEventListener('click', function (event) {
    if (!event.target.closest('.lightswitch')) return;
    window.setTimeout(function () {
      syncConditionals();
      queuePreview();
    }, 0);
  });

  syncConditionals();

  // ---------------------------------------------------------------- live preview

  var target = document.getElementById('blaster-preview');
  if (!target) return;

  var timer = null;
  var inFlight = false;
  var again = false;

  function queuePreview() {
    if (!target) return;
    window.clearTimeout(timer);
    timer = window.setTimeout(refresh, 350);
  }

  function refresh() {
    // One request at a time, with at most one queued behind it. Typing in a colour field fires
    // an event per keystroke, and without this the preview ends up showing whichever response
    // happened to come back last rather than the most recent state of the form.
    if (inFlight) {
      again = true;
      return;
    }

    inFlight = true;

    var data = new FormData(form);

    // The page form carries its own `action` and `redirect` inputs. Craft resolves a request's
    // controller from the `action` body param before it looks at the URL, so leaving them in
    // would post the preview straight into the save action — and then follow the redirect.
    data.delete('action');
    data.delete('redirect');

    Craft.sendActionRequest('POST', 'blaster/bars/preview', { data: data })
      .then(function (response) {
        target.innerHTML = response.data.html || '';
      })
      .catch(function () {
        /* leave the last good preview on screen rather than blanking it */
      })
      .finally(function () {
        inFlight = false;
        if (again) {
          again = false;
          refresh();
        }
      });
  }

  refresh();

  // ---------------------------------------------------------------- secondary actions
  //
  // Posted with sendActionRequest rather than as forms. A `<form>` nested inside the control
  // panel's page form is invalid HTML: the parser drops the inner tag and keeps its children, so
  // a hidden delete action ends up sitting in the page form next to the save action — and with
  // two action inputs, Craft takes the last one. Clicking Save would run Delete.

  function post(action, confirmation) {
    return function (event) {
      event.preventDefault();

      if (confirmation && !window.confirm(confirmation)) return;

      var button = event.currentTarget;
      var barId = button.getAttribute('data-bar-id');

      Craft.sendActionRequest('POST', action, { data: { barId: barId } })
        .then(function (response) {
          window.location.href = response.data.redirect || Craft.getCpUrl('blaster/bars');
        })
        .catch(function (error) {
          Craft.cp.displayError((error.response && error.response.data && error.response.data.message) || Craft.t('blaster', 'Something went wrong.'));
        });
    };
  }

  var deleteButton = document.getElementById('blaster-delete');
  if (deleteButton) {
    deleteButton.addEventListener('click', post('blaster/bars/delete', deleteButton.getAttribute('data-confirm')));
  }

  var duplicateButton = document.getElementById('blaster-duplicate');
  if (duplicateButton) {
    duplicateButton.addEventListener('click', post('blaster/bars/duplicate'));
  }
})();
