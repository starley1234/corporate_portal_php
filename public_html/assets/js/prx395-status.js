/* Загружается только на странице ожидающей или исполняющейся задачи. */
if (document.querySelector('[data-prx395-pending]')) {
    window.setTimeout(() => window.location.reload(), 3000);
}
