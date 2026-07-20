// Client interactivity for the Snapshot backend module (idle screen source selection).
// Loaded as a JavaScript module via the import map, so it complies with the backend CSP.

function initSnapshotModule() {
    const cards = document.querySelectorAll('#snapshot-cards .card');
    if (cards.length === 0) {
        return;
    }

    const summary = document.getElementById('snapshot-summary');
    const sub = document.getElementById('snapshot-sub');
    const prepare = document.getElementById('snapshot-prepare');

    const selectedSources = () => Array.from(cards)
        .filter((card) => card.classList.contains('selected'))
        .map((card) => card.dataset.source);

    const update = () => {
        const selected = selectedSources();
        const hasDb = selected.includes('db');
        const hasFiles = selected.includes('files');

        if (hasDb && hasFiles) {
            summary.textContent = 'Database + Fileadmin';
            sub.textContent = 'Prepare a secured, single-use download';
        } else if (hasDb) {
            summary.textContent = 'Database only';
            sub.textContent = 'Prepare a secured, single-use download';
        } else if (hasFiles) {
            summary.textContent = 'Fileadmin only';
            sub.textContent = 'Prepare a secured, single-use download';
        } else {
            summary.textContent = 'Nothing selected';
            sub.textContent = 'Pick at least one source';
        }

        prepare.disabled = selected.length === 0;
    };

    cards.forEach((card) => card.addEventListener('click', () => {
        card.classList.toggle('selected');
        update();
    }));

    update();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSnapshotModule);
} else {
    initSnapshotModule();
}
