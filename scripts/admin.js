(() => {
  const root = document.querySelector('[data-admin-app]');
  if (!root) return;

  const csrf = root.dataset.csrf;
  const rows = root.querySelector('[data-author-rows]');
  const template = root.querySelector('[data-author-template]');
  const searchUrl = root.dataset.authorSearch;

  function addAuthorRow(id = '', name = '', margin = '') {
    const row = template.content.firstElementChild.cloneNode(true);
    row.querySelector('[data-author-id]').value = id;
    row.querySelector('[data-author-name]').value = name;
    row.querySelector('[data-margin]').value = margin;
    rows.appendChild(row);
    bindSearch(row);
  }

  function bindSearch(row) {
    const input = row.querySelector('[data-author-name]');
    const id = row.querySelector('[data-author-id]');
    const list = row.querySelector('[data-suggestions]');
    let timer;
    input.addEventListener('input', () => {
      id.value = '';
      clearTimeout(timer);
      const term = input.value.trim();
      if (term.length < 2) { list.replaceChildren(); return; }
      timer = setTimeout(async () => {
        const response = await fetch(`${searchUrl}?q=${encodeURIComponent(term)}`, {
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrf }
        });
        if (!response.ok) return;
        const authors = await response.json();
        list.replaceChildren(...authors.map(author => {
          const item = document.createElement('li');
          const button = document.createElement('button');
          button.type = 'button'; button.textContent = author.name;
          button.addEventListener('click', () => { input.value = author.name; id.value = author.id; list.replaceChildren(); });
          item.appendChild(button); return item;
        }));
      }, 180);
    });
  }

  root.querySelector('[data-add-author]').addEventListener('click', () => addAuthorRow());
  root.addEventListener('click', event => {
    if (event.target.matches('[data-remove-author]')) event.target.closest('[data-author-row]').remove();
  });
  document.addEventListener('submit', event => {
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) event.preventDefault();
  });
  root.querySelectorAll('[data-author-row]').forEach(bindSearch);
})();
