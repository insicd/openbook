(function () {
  'use strict';

  var editor = document.querySelector('[data-profile-fields]');
  if (!editor) return;

  var max = Number(editor.dataset.maxFields);
  var nextIndex = editor.querySelectorAll('[data-profile-field]').length;
  var counter = editor.querySelector('[data-profile-field-count]');

  function update() {
    var count = editor.querySelectorAll('[data-profile-field]').length;
    editor.querySelectorAll('[data-add-profile-field]').forEach(function (button) {
      button.disabled = count >= max;
    });
    counter.textContent = counter.dataset.countLabel.replace(':count', String(count));
  }

  editor.addEventListener('click', function (event) {
    var add = event.target.closest('[data-add-profile-field]');
    var remove = event.target.closest('[data-remove-profile-field]');

    if (add && editor.contains(add)) {
      if (editor.querySelectorAll('[data-profile-field]').length >= max) return;
      var kind = add.dataset.addProfileField;
      var template = editor.querySelector('[data-profile-field-template="' + kind + '"]');
      var row = template.content.firstElementChild.cloneNode(true);
      row.querySelectorAll('[name]').forEach(function (input) {
        input.name = input.name.replace('__INDEX__', String(nextIndex));
      });
      nextIndex += 1;
      editor.querySelector('[data-profile-field-list="' + kind + '"]').appendChild(row);
      row.querySelector('[data-profile-field-label]').focus();
    } else if (remove && editor.contains(remove)) {
      var section = remove.closest('section');
      remove.closest('[data-profile-field]').remove();
      update();
      section.querySelector('[data-add-profile-field]').focus();
    }

    update();
  });

  update();
})();
