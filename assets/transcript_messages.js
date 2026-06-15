const timestampFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

for (const element of document.querySelectorAll('[data-local-datetime]')) {
    const isoValue = element.getAttribute('datetime') ?? element.dataset.localDatetime;
    if (!isoValue) {
        continue;
    }

    const parsed = new Date(isoValue);
    if (Number.isNaN(parsed.getTime())) {
        continue;
    }

    element.textContent = timestampFormatter.format(parsed);
    element.title = `${isoValue} UTC`;
}
